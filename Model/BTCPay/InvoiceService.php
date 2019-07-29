<?php

namespace Storefront\BTCPay\Model\BTCPay;

use Bitpay\Token;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use stdClass;
use Storefront\BTCPay\Helper\Data;
use Storefront\BTCPay\Storage\EncryptedConfigStorage;

class InvoiceService {

    CONST KEY_PUBLIC = 'btcpay.pub';
    CONST KEY_PRIVATE = 'btcpay.priv';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var AdapterInterface
     */
    private $db;

    /**
     * @var \Magento\Framework\HTTP\ZendClientFactory
     */
    private $httpClientFactory;
    /**
     * @var Data
     */
    private $helper;
    /**
     * @var \Magento\Framework\App\Config\ConfigResource\ConfigInterface
     */
    private $configResource;
    /**
     * @var EncryptedConfigStorage
     */
    private $encryptedConfigStorage;

    public function __construct(ResourceConnection $resource, EncryptedConfigStorage $encryptedConfigStorage, \Magento\Framework\App\Config\ConfigResource\ConfigInterface $configResource, StoreManagerInterface $storeManager, UrlInterface $url, \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory, ScopeConfigInterface $scopeConfig) {
        $this->httpClientFactory = $httpClientFactory;
        $this->scopeConfig = $scopeConfig;
        $this->url = $url;
        $this->storeManager = $storeManager;
        $this->db = $resource->getConnection();
        $this->configResource = $configResource;
        $this->encryptedConfigStorage = $encryptedConfigStorage;
    }

    public function checkInvoiceStatus($invoiceId, $storeId) {
        // TODO replace with Zend HTTP Client
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getInvoicesEndpoint($storeId) . '/' . $invoiceId);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * @param $storeId
     * @return \BitPay\Client\Client
     */
    private function getClient($storeId, $loadToken = true) {
        $client = new \BitPay\Client\Client();

        $adapter = new \BitPay\Client\Adapter\CurlAdapter();

        $privateKey = $this->getPrivateKey();
        $publicKey = $this->getPublicKey();

        $client->setPrivateKey($privateKey);
        $client->setPublicKey($publicKey);

        $host = $this->getHost($storeId);
        $port = $this->getPort($storeId);
        $network = new \Bitpay\Network\Customnet($host, $port);

        $client->setNetwork($network);

        $client->setAdapter($adapter);

        if ($loadToken) {
            $token = $this->getTokenOrRegenerate($storeId);
            $client->setToken($token);
        }

        return $client;
    }

    /**
     * @param $storeId
     * @param null $pairingCode New pairing code to set, or if empty load the pairing code entered in Magento config
     * @return Token
     * @throws \BitPay\Client\BitpayException
     */
    public function pair($storeId, $pairingCode = null) {

        if ($pairingCode === null) {
            $pairingCode = $this->getPairingCode($storeId);
        } else {
            $this->setPairingCode($pairingCode);
        }

        /**
         * Start by creating a PrivateKey object
         */
        $privateKey = new \BitPay\PrivateKey(self::KEY_PRIVATE);

        // Generate a random number
        $privateKey->generate();

        // Once we have a private key, a public key is created from it.
        $publicKey = new \BitPay\PublicKey(self::KEY_PUBLIC);

        // Inject the private key into the public key
        $publicKey->setPrivateKey($privateKey);

        // Generate the public key
        $publicKey->generate();

        $this->encryptedConfigStorage->persist($privateKey);
        $this->encryptedConfigStorage->persist($publicKey);

        $client = $this->getClient($storeId, false);


        /**
         * Currently this part is required, however future versions of the PHP SDK will
         * be refactor and this part may become obsolete.
         */
        $sin = \BitPay\SinKey::create()->setPublicKey($publicKey)->generate();
        /**** end ****/

        $baseUrl = $this->getStoreConfig('web/unsecure/base_url', $storeId);
        $baseUrl = str_replace('http://', '', $baseUrl);
        $baseUrl = str_replace('https://', '', $baseUrl);
        $baseUrl = trim($baseUrl, ' /');

        $token = $client->createToken([
            'pairingCode' => $pairingCode,
            'label' => $baseUrl . ' (Magento 2 Storefront_BTCPay, ' . date('Y-m-d H:i:s') . ')',
            'id' => (string)$sin,
        ]);

        $this->configResource->saveConfig('payment/btcpay/pairing_code', $pairingCode);
        $this->configResource->saveConfig('payment/btcpay/token', $token->getToken());

        $client->setToken($token);
        // TODO test the new token somehow?

        //$x = $client->getPayouts();

        return $token;
    }

    public function createInvoice(\Magento\Sales\Model\Order $order) {
        $storeId = $order->getStoreId();
        $orderId = $order->getId();

        $client = $this->getClient($storeId);


//            /**
//             * The code will throw an exception if anything goes wrong, if you did not
//             * change the $pairingCode value or if you are trying to use a pairing
//             * code that has already been used, you will get an exception. It was
//             * decided that it makes more sense to allow your application to handle
//             * this exception since each app is different and has different requirements.
//             */
//            echo "Exception occured: " . $e->getMessage().PHP_EOL;
//
//            echo "Pairing failed. Please check whether you're trying to pair a production pairing code on test.".PHP_EOL;
//            $request  = $client->getRequest();
//            $response = $client->getResponse();
//            /**
//             * You can use the entire request/response to help figure out what went
//             * wrong, but for right now, we will just var_dump them.
//             */
//            echo (string) $request.PHP_EOL.PHP_EOL.PHP_EOL;
//            echo (string) $response.PHP_EOL.PHP_EOL;
//            /**
//             * NOTE: The `(string)` is include so that the objects are converted to a
//             *       user friendly string.
//             */

        /**
         * This is where we will start to create an Invoice object, make sure to check
         * the InvoiceInterface for methods that you can use.
         */
        $invoice = new \BitPay\Invoice();

        $ba = $order->getBillingAddress();

        $buyer = new \BitPay\Buyer();
        $buyer->setFirstName($order->getCustomerFirstname());
        $buyer->setLastName($order->getCustomerLastname());
        $buyer->setCountry($ba->getCountryId());
        $buyer->setState($ba->getRegionCode());
        $buyer->setAddress($ba->getStreet());
        $buyer->setAgreedToTOSandPP(true);
        $buyer->setCity($ba->getCity());
        $buyer->setPhone($ba->getTelephone());
        $buyer->setZip($ba->getPostcode());
        $buyer->setEmail($order->getCustomerEmail());

        // TODO what does this notify field to exactly? Why is there a field for it on the Buyer and why is it also on the Invoice object?
        $buyer->setNotify(true);

        // Add the buyers info to invoice
        $invoice->setBuyer($buyer);

        $item = new \BitPay\Item();
        $item->setCode($order->getIncrementId());
        // TODO the descirption "Order #%1" is hard coded and not in the locale of the customer.
        $item->setDescription('Order #' . $order->getIncrementId());
        $item->setPrice($order->getGrandTotal());
        $item->setQuantity(1);
        $item->setPhysical(!$order->getIsVirtual());

        $invoice->setItem($item);

        /**
         * BTCPayServer supports multiple different currencies. Most shopping cart applications
         * and applications in general have defined set of currencies that can be used.
         * Setting this to one of the supported currencies will create an invoice using
         * the exchange rate for that currency.
         *
         * @see https://docs.btcpayserver.org/faq-and-common-issues/faq-general#which-cryptocurrencies-are-supported-in-btcpay for supported currencies
         */
        $invoice->setCurrency(new \BitPay\Currency($order->getOrderCurrencyCode()));

        // Configure the rest of the invoice
        $ipnUrl = $this->storeManager->getStore()->getBaseUrl() . 'rest/V1/btcpay/ipn';

        $invoice->setOrderId($order->getIncrementId());
        $invoice->setNotificationUrl($ipnUrl);

        // TODO what do the "notification email" and "extended notifications" fields to exactly?
        $invoice->setNotificationEmail($order->getCustomerEmail());
        $invoice->setExtendedNotifications(true);

        /**
         * Updates invoice with new information such as the invoice id and the URL where
         * a customer can view the invoice.
         */

        echo 'Creating invoice at BTCPayServer now.' . PHP_EOL;
        $client->createInvoice($invoice);

//            echo "Exception occured: " . $e->getMessage() . PHP_EOL;
//            $request = $client->getRequest();
//            $response = $client->getResponse();
//            echo (string)$request . PHP_EOL . PHP_EOL . PHP_EOL;
//            echo (string)$response . PHP_EOL . PHP_EOL;
//            exit(1); // We do not want to continue if something went wrong

        $table_name = $this->db->getTableName('btcpay_transactions');
        $this->db->insert($table_name, [
            'order_id' => $orderId,
            'transaction_id' => $invoice->getId(),
            'status' => 'new'
        ]);

        echo 'Invoice "' . $invoice->getId() . '" created, see ' . $invoice->getUrl() . PHP_EOL;
        echo 'Verbose details.' . PHP_EOL;
        print_r($invoice);

//        //create an item, should be passed as an object'
//        $params = [];
//        //$params->extension_version = $this->getExtensionVersion();
//        $params['price'] = $order->getGrandTotal();
//        $params['currency'] = $order->getOrderCurrencyCode();
//
//        $buyerInfo = [];
//
//        $nameParts = [];
//        $billingAddress = $order->getBillingAddress();
//
//        if ($billingAddress->getFirstname()) {
//            $nameParts[] = $billingAddress->getFirstname();
//        }
//        if ($billingAddress->getMiddlename()) {
//            $nameParts[] = $billingAddress->getMiddlename();
//        }
//        if ($billingAddress->getLastname()) {
//            $nameParts[] = $billingAddress->getLastname();
//        }
//
//        $buyerInfo['name'] = implode(' ', $nameParts);
//        $buyerInfo['email'] = $order->getCustomerEmail();
//
//        $params['buyer'] = $buyerInfo;
//        $params['orderId'] = $orderIncrementId;
//
//        if ($order->getCustomerId()) {
//            // Customer is logged in...
//            $params['redirectURL'] = $this->url->getUrl('sales/order/view/', ['order_id' => $orderId]);
//        } else {
//            // Send the guest back to the order/returns page to lookup
//            $params['redirectURL'] = $this->url->getUrl('sales/guest/form');
//        }
//
//        // TODO build the URL to the REST API in a more Magento way?
//        $params['notificationURL'] = $this->storeManager->getStore()->getBaseUrl() . 'rest/V1/btcpay/ipn';
//        $params['extendedNotifications'] = true;
//        $params['acceptanceWindow'] = 1200000;
//
//        $params['cartFix'] = $this->url->getUrl('btcpay/cart/restore', ['order_id' => $orderId]);
//        $params['token'] = $token;
//
//        $postData = json_encode($params);
//
////        $request_headers = [];
////        $request_headers[] = 'Content-Type: application/json';
//
//        $url = $this->getInvoicesEndpoint($storeId);
//
//        $client = $this->httpClientFactory->create();
//        $client->setUri($url);
//        $client->setMethod('POST');
//        $client->setHeaders('Content-Type', 'application/json');
//        $client->setHeaders('Accept', 'application/json');
//        $client->setRawData($postData);
//        $response = $client->request();
//
////        $ch = curl_init();
////        curl_setopt($ch, CURLOPT_URL, $url);
////        curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
////        curl_setopt($ch, CURLOPT_POST, 1);
////        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
////        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
////        $result = curl_exec($ch);
////        curl_close($ch);
//
//        $status = $response->getStatus();
//        $body = $response->getBody();
//
//        if ($status === 200) {
//            $data = json_decode($body, true);
//            $invoice = new Invoice($data);
//
//            $table_name = $this->db->getTableName('btcpay_transactions');
//            $this->db->insert($table_name, [
//                'order_id' => $orderId,
//                'transaction_id' => $invoice->getInvoiceId(),
//                'transaction_status' => 'new'
//            ]);
//
//            return $invoice;
//        } else {
//            // TODO improve error message
//            throw new \RuntimeException('Cannot create new invoice in BTCPay server for order ID ' . $order->getId());
//        }
    }

//    public function getInvoiceURL() {
//        $data = json_decode($this->invoiceData, true);
//        return $data['data']['url'] ?? false;
//    }

    public function updateBuyersEmail($invoice_result, $buyers_email) {
        $invoice_result = json_decode($invoice_result, false);

        $token = $this->getPairingCode();

        $update_fields = new stdClass();
        $update_fields->token = $token;
        $update_fields->buyerProvidedEmail = $buyers_email;
        $update_fields->invoiceId = $invoice_result->data->id;
        $update_fields = json_encode($update_fields);
        // TODO replace with Zend HTTP Client
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://' . $this->item->getBuyerTransactionEndpoint());
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $update_fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    public function updateBuyerCurrency($invoice_result, $buyer_currency) {
        $invoice_result = json_decode($invoice_result);

        $update_fields = new stdClass();
        $update_fields->token = $this->item->item_params->token;
        $update_fields->buyerSelectedTransactionCurrency = $buyer_currency;
        $update_fields->invoiceId = $invoice_result->data->id;
        $update_fields = json_encode($update_fields);
        // TODO replace with Zend HTTP Client
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://' . $this->item->getBuyerTransactionEndpoint());
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $update_fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * @return string
     */
    public function getBuyerTransactionEndpoint(): string {
        return $this->host . '/invoiceData/setBuyerSelectedTransactionCurrency';
    }

    public function getStoreConfig($path, $storeId): ?string {
        $r = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        return $r;
    }

    private function getPairingCode(int $storeId): ?string {
        $r = $this->getStoreConfig('payment/btcpay/pairing_code', $storeId);
        return $r;
    }

    /**
     * @param int $storeId
     * @return \Storefront\BTCPay\Model\BTCPay\Token
     * @throws \BitPay\Client\BitpayException
     */
    private function getTokenOrRegenerate(int $storeId): Token {
        $tokenString = $this->getStoreConfig('payment/btcpay/token', $storeId);

        if (!$tokenString) {
            $tokenString = $this->pair($storeId);
        }
        $token = new Token();
        $token->setToken($tokenString);

        return $token;
    }

    private function getHost($storeId) {
        $r = $this->getStoreConfig('payment/btcpay/host', $storeId);
        return $r;
    }

    private function getInvoicesEndpoint(int $storeId) {
        $host = $this->getHost($storeId);
        $r = 'https://' . $host . '/invoices';
        return $r;
    }

    /**
     * @param $pairingCode
     * @return bool
     */
    public function setPairingCode($pairingCode) {
        // TODO if we want to make this module multi-BTCPay server, this would need to be store view scoped
        $this->configResource->saveConfig('payment/btcpay/pairing_code', $pairingCode);
        // TODO flush the cache after this
        return true;
    }

    /**
     * @return \Bitpay\KeyInterface
     */
    public function getPrivateKey() {
        return $this->encryptedConfigStorage->load(\Storefront\BTCPay\Model\BTCPay\InvoiceService::KEY_PRIVATE);
    }

    /**
     * @return \Bitpay\KeyInterface
     */
    public function getPublicKey() {
        return $this->encryptedConfigStorage->load(\Storefront\BTCPay\Model\BTCPay\InvoiceService::KEY_PUBLIC);
    }

    private function getPort($storeId) {
        // TODO port is hard coded for now
        return 443;
    }
}
