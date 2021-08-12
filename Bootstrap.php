<?php

/**
 * @copyright 2021 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace Plugin\ws5_mollie;

use JTL\Events\Dispatcher;
use JTL\Exceptions\CircularReferenceException;
use JTL\Exceptions\ServiceNotFoundException;
use Plugin\ws5_mollie\lib\Hook\ApplePay;
use Plugin\ws5_mollie\lib\Hook\Checkbox;
use Plugin\ws5_mollie\lib\Hook\Queue;
use Plugin\ws5_mollie\lib\Mapper\MollieUpgradeMapper;
use WS\JTL5\Mapper\UpgradeMapperInterface;

require_once __DIR__ . '/vendor/autoload.php';

class Bootstrap extends \WS\JTL5\Bootstrap
{
    /**
     * @param Dispatcher $dispatcher
     * @throws CircularReferenceException
     * @throws ServiceNotFoundException
     */
    public function boot(Dispatcher $dispatcher): void
    {
        parent::boot($dispatcher);

        $this->listen(HOOK_SMARTY_OUTPUTFILTER, [ApplePay::class, 'execute']);

        $this->listen(HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB, [Queue::class, 'bestellungInDB']);

        $this->listen(HOOK_INDEX_NAVI_HEAD_POSTGET, [Queue::class, 'headPostGet']);

        $this->listen(HOOK_BESTELLUNGEN_XML_BESTELLSTATUS, [Queue::class, 'xmlBestellStatus']);

        $this->listen(HOOK_BESTELLUNGEN_XML_BEARBEITESTORNO, [Queue::class, 'xmlBearbeiteStorno']);

        if ($this->getPlugin()->getConfig()->getValue('useCustomerAPI') === 'C') {
            $this->listen(HOOK_CHECKBOX_CLASS_GETCHECKBOXFRONTEND, [Checkbox::class, 'execute']);
        }
    }

    /**
     * @return null|UpgradeMapperInterface
     */
    public function getUpgradeMapper(): ?UpgradeMapperInterface
    {
        return new MollieUpgradeMapper('ws_mollie', 'ws5_mollie');
    }
}
