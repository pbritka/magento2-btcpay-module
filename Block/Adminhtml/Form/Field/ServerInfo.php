<?php
/*
 * @author Wouter Samaey <wouter.samaey@storefront.agency>
 * @license MIT
 */

declare(strict_types=1);

namespace Storefront\BTCPay\Block\Adminhtml\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Storefront\BTCPay\Helper\Data;

class ServerInfo extends \Magento\Config\Block\System\Config\Form\Field
{

    /**
     * @var \Storefront\BTCPay\Model\BTCPay\BTCPayService
     */
    private $btcPayService;
    /**
     * @var Data
     */
    private $helper;

    public function __construct(\Storefront\BTCPay\Model\BTCPay\BTCPayService $btcPayService, Data $helper, Context $context, array $data = [], ?SecureHtmlRenderer $secureRenderer = null)
    {
        parent::__construct($context, $data, $secureRenderer);
        $this->btcPayService = $btcPayService;
        $this->helper = $helper;
    }

    /**
     * Render form element as HTML
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     * @return string
     */
    public function render(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {

        $html = $this->getConnectionStatusPerStore();
        $r = '<tr><td class="label"></td><td class="value">' . $html . '</span></p></td><td class=""></td></tr>';

        return $r;
    }


    public function getStoreErrorHtml(int $magentoStoreId): string
    {
        $lines = $this->helper->getStoreInstallationErrors($magentoStoreId, false);

        if (count($lines) > 0) {
            $r = '<ul style="padding-left: 25px">';
            foreach ($lines as $line) {
                $r .= '<li style="margin-bottom: 10px; color: red">' . $line . '</li>';
            }
            $r .= '</ul>';
        } else {
            $r = '<span style="font-weight: bold; color: green;">OK</span>';
        }
        return $r;
    }

    public function getConnectionStatusPerStore()
    {
        $html = '';
        $globalErrors = $this->helper->getGlobalInstallationErrors(true);
        if (count($globalErrors) > 0) {
            $html .= '<ul style="padding-left: 25px">';
            foreach ($globalErrors as $line) {
                $html .= '<li style="margin-bottom: 10px; color: red">' . $line . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<table class="status">
  <tr>
    <th style="text-align: left;">' . __('Store View') . '</th>
    <th style="text-align: left">' . __('Status') . '</th>
  </tr>';

        $magentoStores = $this->helper->getAllMagentoStoreViews();

        foreach ($magentoStores as $magentoStore) {
            $storeId = (int)$magentoStore->getId();

            $html = $html . '  <tr>
    <td style="white-space: nowrap">' . $magentoStore->getName() . '</td>
    <td>' . $this->getStoreErrorHtml($storeId) . '</td>
  </tr>';

        }
        return $html . '</table>';

    }

}
