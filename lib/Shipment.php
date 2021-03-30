<?php


namespace Plugin\ws5_mollie\lib;


use Exception;
use JsonSerializable;
use JTL\Checkout\Bestellung;
use JTL\Checkout\Lieferschein;
use JTL\Checkout\Lieferscheinpos;
use JTL\Checkout\Versand;
use JTL\Model\DataModel;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\IncompatiblePlatform;
use Mollie\Api\Resources\OrderLine;
use Mollie\Api\Types\OrderStatus;
use Plugin\ws5_mollie\lib\Checkout\OrderCheckout;
use Plugin\ws5_mollie\lib\Model\OrderModel;
use Plugin\ws5_mollie\lib\Model\ShipmentsModel;
use Plugin\ws5_mollie\lib\Traits\Jsonable;
use Plugin\ws5_mollie\lib\Traits\Plugin;
use Shop;

class Shipment implements JsonSerializable
{

    /**
     * @var array
     */
    public array $lines = [];

    /**
     * @var array
     */
    public array $tracking;

    /**
     * @var bool
     */
    public $bTestmode;
    /**
     * @var \Mollie\Api\Resources\Shipment
     */
    public $result;
    /**
     * @var int|null
     */
    protected $kBestellung;
    /**
     * @var int|null
     *
     */
    protected $kLieferschein;
    /**
     * @var string|null
     */
    protected $cOrderId;

    use Jsonable;

    use Plugin;

    /**
     * @param int $kBestellung
     * @return array
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @throws \JTL\Exceptions\CircularReferenceException
     * @throws \JTL\Exceptions\ServiceNotFoundException
     */
    public static function syncBestellung(int $kBestellung): array
    {

        $shipments = [];

        $oBestellung = new Bestellung($kBestellung, true);

        if ($oBestellung->kBestellung) {

            $checkout = OrderCheckout::factory($oBestellung);

            $oKunde = new \Kunde($oBestellung->kKunde);

            /** @var Lieferschein $oLieferschein */
            foreach ($oBestellung->oLieferschein_arr as $oLieferschein) {

                try {

                    $shipmentModel = ShipmentsModel::loadByAttributes(
                        ['lieferschein' => $oLieferschein->getLieferschein()],
                        \JTL\Shop::Container()->getDB(),
                        DataModel::ON_NOTEXISTS_NEW);
                    if ($shipmentModel->getOrderId() && $shipmentModel->getShipmentId()) {
                        continue;
                    }

                    $shipment = self::factory($oLieferschein->getLieferschein(), $checkout->getModel()->getOrderId());

                    $mode = self::Plugin()->getConfig()->getValue('shippingMode');
                    switch ($mode) {
                        case 'A':
                            // ship directly
                            if (!$shipment->send() && !$shipment->result) {
                                throw new \Plugin\ws5_mollie\lib\Exception\APIException('Shipment konnte nicht gespeichert werden.');
                            }
                            $shipments[] = $shipment->result;
                            break;

                        case 'B':
                            // only ship if complete shipping
                            if ($oKunde->nRegistriert || (int)$oBestellung->cStatus === BESTELLUNG_STATUS_VERSANDT) {
                                if (!$shipment->send() && !$shipment->result) {
                                    throw new \Plugin\ws5_mollie\lib\Exception\APIException('Shipment konnte nicht gespeichert werden.');
                                }
                                $shipments[] = $shipment->result;
                                break;
                            }
                            throw new \Plugin\ws5_mollie\lib\Exception\APIException('Gastbestellung noch nicht komplett versendet!');
                    }

                } catch (Exception $e) {
                    Shop::Container()->getLogService()->addError("mollie: Shipment::syncBestellung - " . $e->getMessage());
                    throw $e;
                }
            }
        }
        return $shipments;
    }


    /**
     * @param int $kLieferschein
     * @param $orderId
     * @return Shipment
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @throws Exception
     */
    public static function factory(int $kLieferschein, $orderId): Shipment
    {

        $checkout = OrderCheckout::fromID($orderId);

        $shipmentsModel = ShipmentsModel::loadByAttributes(
            ['lieferschein' => $kLieferschein],
            \JTL\Shop::Container()->getDB(),
            DataModel::ON_NOTEXISTS_NEW);
        if ($shipmentsModel->getOrderId()) {
            throw new \Plugin\ws5_mollie\lib\Exception\APIException('Lieferschien bereits an Mollie übertragen!');
        }

        if ($checkout->getMollie()->status === OrderStatus::STATUS_COMPLETED) {
            throw new \Plugin\ws5_mollie\lib\Exception\APIException('Bestellung bei Mollie bereits abgeschlossen!');
        }

        $oLieferschein = new Lieferschein($kLieferschein);

        if (!$oLieferschein->getLieferschein()) {
            throw new \Plugin\ws5_mollie\lib\Exception\APIException('Lieferschein konnte nicht geladen werden');
        }


        if (!count($oLieferschein->oVersand_arr)) {
            throw new \Plugin\ws5_mollie\lib\Exception\APIException('Kein Versand gefunden!');
        }


        $shipment = new self();

        $shipment->cOrderId = $orderId;
        $shipment->kBestellung = $checkout->getModel()->getBestellung();
        $shipment->kLieferschein = $kLieferschein;

        /** @var Versand $oVersand */
        $oVersand = $oLieferschein->oVersand_arr[0];
        if ($oVersand->getIdentCode() && $oVersand->getLogistik()) {
            $shipment->tracking = [
                'carrier' => $oVersand->getLogistik(),
                'code' => $oVersand->getIdentCode(),
            ];
            if ($oVersand->getLogistikVarUrl()) {
                $shipment->tracking['url'] = $oVersand->getLogistikURL();
            }
        }
        if ($checkout->getModel()->getTest()) {
            $shipment->bTestmode = true;
        }

        $oBestellung = new Bestellung($checkout->getModel()->getBestellung());
        if ((int)$oBestellung->cStatus === BESTELLUNG_STATUS_VERSANDT) {
            $shipment->lines = [];
        } else {
            $shipment->lines = self::getOrderLines($oLieferschein, $checkout->getMollie());
        }

        return $shipment;

    }

    protected static function getOrderLines(Lieferschein $oLieferschein, \Mollie\Api\Resources\Order $mOrder): array
    {
        $lines = [];

        if (!count($oLieferschein->oLieferscheinPos_arr)) {
            return $lines;
        }

        // Bei Stücklisten, sonst gibt es mehrere OrderLines für die selbe ID
        $shippedOrderLines = [];

        /** @var Lieferscheinpos $oLieferschienPos */
        foreach ($oLieferschein->oLieferscheinPos_arr as $oLieferschienPos) {

            $wkpos = Shop::Container()->getDB()->executeQueryPrepared('SELECT * FROM twarenkorbpos WHERE kBestellpos = :kBestellpos', [
                ':kBestellpos' => $oLieferschienPos->getBestellPos()
            ], 1);

            /** @var OrderLine $orderLine */
            foreach ($mOrder->lines as $orderLine) {

                if (!in_array($orderLine->id, $shippedOrderLines, true) && $wkpos->cArtNr === $orderLine->sku) {

                    $quantity = min($oLieferschienPos->getAnzahl(), $orderLine->shippableQuantity);
                    if ($quantity) {
                        $lines[] = [
                            'id' => $orderLine->id,
                            'quantity' => min($oLieferschienPos->getAnzahl(), $orderLine->shippableQuantity)
                        ];
                    }
                    $shippedOrderLines[] = $orderLine->id;
                    break;
                }

            }
        }


        return $lines;
    }

    /**
     * @return bool
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @throws Exception
     */
    public function send(): bool
    {

        $oShipmentModel = new ShipmentsModel(Shop::Container()->getDB());
        $oShipmentModel->setBestellung($this->kBestellung);
        $oShipmentModel->setLieferschein($this->kLieferschein);
        $oShipmentModel->setOrderId($this->cOrderId);
        $oShipmentModel->setCarrier($this->tracking['carrier'] ?? null);
        $oShipmentModel->setCode($this->tracking['code'] ?? null);
        $oShipmentModel->setUrl($this->tracking['url'] ?? null);

        $api = (new MollieAPI($this->bTestmode))->getClient();
        $this->bTestmode = null;
        $this->cOrderId = null;
        $this->kLieferschein = null;
        $this->kBestellung = null;

        //return true;

        $this->result = $api->shipments->createForId($oShipmentModel->orderId, $this->toArray());

        $oShipmentModel->setShipmentId($this->result->id);

        return $oShipmentModel->save();

    }

}