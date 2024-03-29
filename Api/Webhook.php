<?php
declare(strict_types=1);

namespace Storefront\BTCPay\Api;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;
use RuntimeException;
use Storefront\BTCPay\Helper\Data;
use Storefront\BTCPay\Model\BTCPay\BTCPayService;

class Webhook implements WebhookInterface
{
    /**
     * @var BTCPayService
     */
    private $btcPayService;
    /**
     * @var Request
     */
    private $request;
    /**
     * @var Data
     */
    private $helper;

    public function __construct(BTCPayService $btcPayService, Request $request, Data $helper)
    {
        $this->btcPayService = $btcPayService;
        $this->request = $request;
        $this->helper = $helper;
    }

    /**
     * @return bool
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function process(): bool
    {
        $logPayment = false;
        $postedString = $this->request->getContent();
        if (!$postedString) {
            throw new RuntimeException('No data posted. Cannot process BTCPay Server Webhook.');
        }
        $data = \json_decode($postedString, true);
        $signatureHeader = $this->request->getHeader('BTCPay-Sig');

        if ($signatureHeader) {
            $secret = $this->helper->getWebhookSecret();
            $expectedHeader = 'sha256=' . hash_hmac('sha256', $postedString, $secret);

            if ($expectedHeader === $signatureHeader) {
                // Event "Invoice created"
                // {
                //  "deliveryId": "string",
                //  "webhookId": "string",
                //  "originalDeliveryId": "string",
                //  "isRedelivery": true,
                //  "type": "InvoiceCreated",
                //  "timestamp": 1592312018,
                //  "storeId": "string",
                //  "invoiceId": "string"
                //}

                // Event "Invoice expired"
                // Same as "Invoice created", but contains an extra field: "partiallyPaid" true/false

                // Event "InvoiceReceivedPayment"
                // Same as "Invoice created", but contains an extra field: "afterExpiration" true/false

                // Event "Invoice processing"
                // Same as "Invoice created", but contains an extra field: "overPaid" true/false

                // Event "Invoice invalid"
                // Same as "Invoice created", but contains an extra field: "manuallyMarked" true/false

                // Event "Invoice settled"
                // Same as "Invoice created", but contains an extra field: "manuallyMarked" true/false

                $btcpayInvoiceId = $data['invoiceId'] ?? null;
                $btcpayStoreId = $data['storeId'] ?? null;
                $dataType = $data['type'];

                // Only use the "id" field from the POSTed data and discard the rest. We are not trusting the other posted data.
                unset($data);
                if ($btcpayInvoiceId) {

                    if ($dataType === 'InvoiceReceivedPayment') {
                        $logPayment = true;
                    }


                    $this->btcPayService->updateInvoice($btcpayStoreId, $btcpayInvoiceId, $logPayment);
                    return true;
                }

            } else {
                // TODO is there a better way to trigger a 403 Access Denied?
                throw new RuntimeException('Access Denied');
            }

        }
        // TODO is there a better way to trigger a 400 Bad Request?
        throw new RuntimeException('Invalid data POSTed');
    }
}
