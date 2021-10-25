<?php
/*
 * @author Wouter Samaey <wouter.samaey@storefront.agency>
 * @license MIT
 */

declare(strict_types=1);

namespace Storefront\BTCPay\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Storefront\BTCPay\Helper\Data;


class CheckWebhooks implements ObserverInterface
{

    /**
     * @var Data $helper
     */
    private $helper;

    public function __construct(Data $helper)
    {
        $this->helper = $helper;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $changedPaths = $observer->getEvent()->getData('changed_paths');

        foreach ($changedPaths as $changedPath) {
            if ($changedPath === \Storefront\BTCPay\Helper\Data::CONFIG_ROOT . 'btcpay_store_id') {

                $storeId = $this->helper->getCurrentStoreId();
                $webhook = $this->helper->installWebhookIfNeeded($storeId, true);

                $magentoStoreViews = $this->helper->getAllMagentoStoreViews();
                $allBtcPayStores = $this->helper->getAllBtcPayStoresAssociative($storeId);

                foreach ($magentoStoreViews as $magentoStoreView) {
                    $storeId = (int)$magentoStoreView->getId();
                    $btcPayStoreId = $this->helper->getSelectedBtcPayStoreForMagentoStore($storeId);

                    $i = array_key_exists($btcPayStoreId, $allBtcPayStores);
                    if ($i) {
                        unset($allBtcPayStores[$btcPayStoreId]);
                    }
                }

                foreach ($allBtcPayStores as $btcPayStoreNotLinkedToMagentoStore) {
                    $apiKey = $this->helper->getApiKey('default', 0);
                    $btcPayStoreId = $btcPayStoreNotLinkedToMagentoStore['id'];
                    $magentoStoreViewIds = $this->helper->getAllMagentoStoreViewIds();
                    foreach ($magentoStoreViewIds as $storeId) {
                        try {
                            $this->helper->deleteWebhookIfNeeded($storeId, $apiKey, $btcPayStoreId);
                        } catch (\BTCPayServer\Exception\BTCPayException $e) {
                            // Do nothing
                        }
                    }

                }
            }
        }
    }

}
