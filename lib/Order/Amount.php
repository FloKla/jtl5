<?php


namespace Plugin\ws5_mollie\lib\Order;


use Plugin\ws5_mollie\lib\Traits\Jsonable;
use Shop;

class Amount implements \JsonSerializable
{
    use Jsonable;

    public $value;
    public $currency;

    /**
     * Amount constructor.
     * @param $value
     * @param \Currency $currency
     * @param bool $useFactor
     * @param bool $useRounding (is it total SUM => true [5 Rappen Rounding])
     * @todo: prüfe mit Shop4
     */
    public function __construct($value, \Currency $currency, $useFactor = true, $useRounding = false)
    {
        if ($useFactor) {
            $value *= $currency->getConversionFactor();
        }
        if ($useRounding) {
            $value = self::optionaleRundung($value);
        }
        $this->value = number_format($value, 2, '.', '');

        $this->currency = $currency->getCode();
    }

    public static function optionaleRundung($gesamtsumme)
    {
        $conf = Shop::getSettings([CONF_KAUFABWICKLUNG]);
        if (isset($conf['kaufabwicklung']['bestellabschluss_runden5']) && $conf['kaufabwicklung']['bestellabschluss_runden5'] == 1) {
            //$waehrung = isset($_SESSION['Waehrung']) ? $_SESSION['Waehrung'] : null;
            //if ($waehrung === null || !isset($waehrung->kWaehrung)) {
            //    $waehrung = Shop::Container()->getDB()->select('twaehrung', 'cStandard', 'Y');
            //}
            //$faktor = $waehrung->fFaktor;
            //$gesamtsumme *= $faktor;

            // simplification. see https://de.wikipedia.org/wiki/Rundung#Rappenrundung
            $gesamtsumme = round($gesamtsumme * 20) / 20;
            //$gesamtsumme /= $faktor;
        }

        return $gesamtsumme;
    }
}