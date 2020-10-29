<?php

namespace PayStand\PayStandMagento\Controller\Webhook;

use \Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use \Magento\Quote\Model\QuoteFactory as QuoteFactory;
use \Magento\Quote\Model\QuoteIdMaskFactory as QuoteIdMaskFactory;
use \stdClass;
use Magento\Framework\App\Action\HttpPostActionInterface as HttpPostActionInterface;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as BuilderInterface;
use Magento\Sales\Model\Order;


/**
 * Webhook Receiver Controller for Paystand
 */
class Paystand extends \Magento\Framework\App\Action\Action implements HttpPostActionInterface
{

    // Get configuration from Paystand's payment method settings & set constants
    const PUBLISHABLE_KEY = 'payment/paystandmagento/publishable_key';
    const CUSTOMER_ID = 'payment/paystandmagento/customer_id';
    const CLIENT_ID = 'payment/paystandmagento/client_id';
    const CLIENT_SECRET = 'payment/paystandmagento/client_secret';
    const USE_SANDBOX = 'payment/paystandmagento/use_sandbox';
    const SANDBOX_BASE_URL = 'https://api.paystand.co/v3';
    const BASE_URL = 'https://api.paystand.com/v3';
    
    const STORE_SCOPE = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

    /** @var \Psr\Log\LoggerInterface */
    protected $_logger;

    /** @var \Magento\Quote\Model\QuoteFactory */
    protected $_quoteFactory;

    /** @var \Magento\Quote\Model\QuoteIdMaskFactory */
    protected $_quoteIdMaskFactory;

    /** @var \Magento\Framework\App\Request\Http */
    protected $_request;

    /** @var \Magento\Framework\Controller\Result\JsonFactory */
    protected $_jsonResultFactory;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    protected $error;
    protected $errno;

    /**
     * @param \Magento\Framework\App\Action\Context $context ,
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        QuoteFactory $quoteFactory,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        ScopeConfig $scopeConfig,
        BuilderInterface $builderInterface,
        \Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory $invoiceCollectionFactory,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \PayStand\PayStandMagento\Plugin\OrderConfirmEmail $orderConfirmEmail
    ) {
        $this->_logger = $logger;
        $this->_request = $request;
        $this->_jsonResultFactory = $jsonResultFactory;
        $this->_objectManager = $objectManager;
        $this->_quoteFactory = $quoteFactory;
        $this->_quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->scopeConfig = $scopeConfig;
        $this->_builderInterface = $builderInterface;
        $this->_invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->_invoiceService = $invoiceService;
        $this->_transactionFactory = $transactionFactory;
        $this->_invoiceRepository = $invoiceRepository;
        $this->_orderRepository = $orderRepository;
        $this->_invoiceSender = $invoiceSender;
        $this->_orderConfirmEmail = $orderConfirmEmail;
        parent::__construct($context);
    }

    /**
     * Receives webhook events from Roadrunner
     */
    public function execute()
    {
        // Start and Initialize http response
        $result = $this->_jsonResultFactory->create();
        $this->_logger->debug('>>>>> PAYSTAND-START: paystandmagento/webhook/paystand endpoint was hit');

        // Get body content from request
        $body = (!empty($this->_request->getContent()))
            ? $this->_request->getContent() : $this->getRequest()->getContent();
        if ($body == null) {
            $this->_logger->error('>>>>> PAYSTAND-ERROR: error retrieving the body from webhook');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_INTERNAL_ERROR);
            $result->setData(['error_message' => __('error retrieving the body from webhook')]);
            return $result;
        }

        $json = json_decode($body);

        //$this->_logger->debug(">>>>> PAYSTAND-REQUEST-LIFECYCLE: " . $json->resource->status);
        $this->_logger->debug(">>>>> PAYSTAND-REQUEST-RECEIVED: " . json_encode($json));

        // Verify the received event is a Paystand-Magento request
        if (
            !isset($json->resource->meta->source) || 
            !in_array($json->resource->meta->source, ["magento 2", "magento 2 - admin"])
        ) {
            $this->_logger->debug('>>>>> PAYSTAND-FINISH: not a Paystand-Magento request');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
            $result->setData(['success_message' => __('Event finished: Not a Paystand-Magento request')]);
            return $result;
        }

        // Get quote id from request
        $quoteId = $json->resource->meta->quote;
        $this->_logger->debug('>>>>> PAYSTAND-QUOTE: magento 2 webhook identified with quote id = ' . $quoteId);
        $quoteIdMask = $this->_quoteIdMaskFactory->create()->load($quoteId, 'masked_id');
        // If the quoteId is not masked, it comes from a logged in user and should be used as is.
        $id = (empty($quoteIdMask->getQuoteId())) ? $json->resource->meta->quote : $quoteIdMask->getQuoteId();

        // Get Order Id from quote
        $quote = $this->_quoteFactory->create()->load($id);

        $order = $this->_objectManager->create( \Magento\Sales\Model\Order::class)
                                      ->loadByIncrementId($quote->getReservedOrderId());

        // alternate method in case the environment is not using increment_id on the order.
        if (empty($order)) {
            $order = $this->_objectManager->create(\Magento\Sales\Model\Order::class)->load($quote->getReservedOrderId());
        }

        // Verify we got an existing Magento order from received quote id
        if (empty($order->getIncrementId())) {
            $this->_logger->debug('>>>>> PAYSTAND-ERROR: Could not retrieve order from quoteId');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_NOT_FOUND);
            $result->setData(['error_message' => __('Could not retrieve order from quoteId')]);
            return $result;
        }

        // Get current order statuses
        $state = $order->getState();
        $status = $order->getStatus();
        $remote_status = $json->resource->status ? $json->resource->status : null;

        $this->_logger->debug(
            ">>>>> [LC: $remote_status] PAYSTAND-ORDER: current order id: {$order->getIncrementId()}, 
            current order state: $state,  current order status: $status"
        );

        // Get an access_token from Paystand using CLIENT_ID & CLIENT_SECRET
        $access_token = $this->getPaystandAccessToken();

        if ($access_token == null) {
            $this->_logger->error('>>>>> PAYSTAND-ERROR: access_token could not be retrieved, check your Paystand configuration');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_BAD_REQUEST);
            $result->setData(['error_message' => __('access_token could not be retrieved from Paystand')]);
            return $result;
        }

        // Verify received Event is valid with Paystand
        if (!$this->verifyPaystandEvent($access_token, $json)) {
            $this->_logger->error('>>>>> PAYSTAND-ERROR: Event verification failed');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Exception::HTTP_BAD_REQUEST);
            $result->setData(['error_message' => __('Event verification failed')]);
            return $result;
        }

        // Verify the event is payment related
        if (!$json->resource->object = "payment") {
            $this->_logger->debug('>>>>> PAYSTAND-EVENT-VERIFICATION-FINISH');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
            $result->setData(['success_message' => __('Event verified, not a payment, no further action')]);
            return $result;
        }

        // Skip closed or canceled orders. Those states are final
        if(in_array($status, [Order::STATE_CLOSED, Order::STATE_CANCELED])){
            $this->_logger->debug('>>>>> PAYSTAND-EVENT-SKIP: Order is closed or canceled');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
            $result->setData(['success_message' => __('Event verified, not a payment, no further action')]);
            return $result;
        }

        // Only create transaction and invoice when the payment is on paid status to prevent multiple objects
        if (in_array($json->resource->status, ['paid'])) {

            // Decode the body
            $request = json_decode($body, true);

            $order->addStatusHistoryComment('Paystand confirmed paid #' .  $order->getIncrementId())
                  ->setIsVisibleOnFront(true)
                  ->setIsCustomerNotified(true)
                  ->save();

            // Create Transaction & invoice for the Order
            $this->createTransaction($order,$request['resource']);
            $this->createInvoice($order);

            // Send order confirmation email
            // $this->_orderConfirmEmail->sendEmail($order, \PayStand\PayStandMagento\Plugin\OrderConfirmEmail::ORDER_CONFIRM_TEMPLATE);

        // If it's in created or processing or posted then discard
        } elseif (in_array($json->resource->status, ['created', 'processing', 'posted'])) {

            $this->_logger->debug('>>>>> PAYSTAND-FINISH: payment created, processing or paid, no need to update order');
            $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
            $result->setData(['success_message' => __('Event verified, payment created, processing or paid, no further action')]);

        // If it's in faild or canceled
        } elseif (in_array($json->resource->status, ['failed', 'canceled'])){

            $newStatus = $this->newOrderStatus($json->resource->status);

            $order->addStatusHistoryComment('Paystand payment ' . $json->resource->status, $newStatus);
            $order->setState($newStatus);
            $order->save();

        } else {

            $this->_logger->debug('>>>>> PAYSTAND-UNEXPECTED: ' . $json->resource->status);
            $order->addStatusHistoryComment('Order status changed to ' . $json->resource->status);
            $order->save();

        }

        // Finish and send back success response
        $this->_logger->debug(
            '>>>>> PAYSTAND-FINISH: Paystand payment status: "' . $json->resource->status
                . '", new order state: "' . $state
                . '", new order status: "' . $status . '"'
        );

        $result->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
        $result->setData(
            [
                'success_message' => __('Event verified, order status changed'),
                'order' => [
                    'newState' => __($state),
                    'newStatus' => __($status)
                ]
            ]
        );
        return $result;
    }

    private function buildCurl($curl, $verb, $body = "", $extheaders = null)
    {
        // Initialize default headers
        $headers = [
            "Content-Type" => "application/json",
            "Accept" => "application/json"
        ];
        // Add external headers for this particular request if any
        if (null != $extheaders) {
            $headers = array_merge($headers, $extheaders);
        }
        $curl->setHeaders($headers);

        // Initialize default options and set the body for this request
        $curlOptions = [
            CURLOPT_USERAGENT => "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:32.0) Gecko/20100101 Firefox/32.0",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $verb,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTFIELDS => $body
        ];
        $curl->setOptions($curlOptions);
        return $curl;
    }

    private function runCurl($curl, $url)
    {
        $curl->post($url, null);
        $raw_response = $curl->getBody();
        $response = json_decode($raw_response);
        return $response;
    }

    private function cleanObject($obj, $whitelist)
    {
        $ret = new stdClass;
        foreach ($whitelist as $prop) {
            $ret->$prop = $obj->$prop;
        }
        return $ret;
    }

    private function newOrderStatus($status)
    {
        $newStatus = '';
        switch ($status) {
            case 'posted':
                $newStatus = Order::STATE_PROCESSING;
                break;
            case 'failed':
                $newStatus = Order::STATE_CLOSED;
                break;
            case 'canceled':
                $newStatus = Order::STATE_CANCELED;
                break;
        }
        return $newStatus;
    }

    private function getPaystandAccessToken()
    {
        $oauthUrl = $this->getBaseUrl() . '/oauth/token';
        $oauth_credentials = [
            'grant_type' => "client_credentials",
            'scope' => "auth",
            'client_id' => $this->scopeConfig->getValue(self::CLIENT_ID, self::STORE_SCOPE),
            'client_secret' => $this->scopeConfig->getValue(self::CLIENT_SECRET, self::STORE_SCOPE)
        ];
        $authCurl = $this->_objectManager->create(\Magento\Framework\HTTP\Client\Curl::class);
        $authCurl = $this->buildCurl($authCurl, "POST", json_encode($oauth_credentials));
        $this->_logger->debug(
            '>>>>> PAYSTAND-FETCH-ACCESS-TOKEN-START'
        );
        $authResponse = $this->runCurl($authCurl, $oauthUrl);
        if ($authResponse == null) {
            return null;
        }
        $this->_logger->debug('>>>>> PAYSTAND-FETCH-ACCESS-TOKEN-SUCCESS');
        return $authResponse->access_token;
    }

    private function verifyPaystandEvent($access_token, $event)
    {
        $auth_header =
            [
                "Authorization" => "Bearer " . $access_token,
                "x-customer-id" => $this->scopeConfig->getValue(self::CUSTOMER_ID, self::STORE_SCOPE)
            ];
        $url = $this->getBaseUrl() . "/events/" . $event->id . "/verify";

        // Clean up json before sending for verification
        $attributeWhitelist = [
            "id", "object", "resource", "diff", "urls", "created", "lastUpdated", "status"
        ];
        $event = $this->cleanObject($event, $attributeWhitelist);

        $this->_logger->debug('>>>>> PAYSTAND-EVENT-VERIFICATION-START');
        $verificationCurl = $this->_objectManager->create(\Magento\Framework\HTTP\Client\Curl::class);
        $verificationCurl = $this->buildCurl($verificationCurl, "POST", json_encode($event), $auth_header);
        $response = $this->runCurl($verificationCurl, $url);
        if (null == $response || property_exists($response, "error")) {
            return false;
        } else {
            $this->_logger->debug('>>>>> PAYSTAND-EVENT-VERIFICATION-SUCCESS');
            return true;
        }
    }

    private function getBaseUrl()
    {
        if ($this->scopeConfig->getValue(self::USE_SANDBOX, self::STORE_SCOPE)) {
            $base_url = self::SANDBOX_BASE_URL;
        } else {
            $base_url = self::BASE_URL;
        }
        return $base_url;
    }

    private function createTransaction($order = null, $paymentData = [])
    {
        try {
            
            //get payment object from order object
            $this->_logger->debug('>>>>> PAYSTAND-CREATE-TRANSACTION-START');

            $payment = $order->getPayment();
            $payment->setLastTransId($paymentData['id']);
            $payment->setTransactionId($paymentData['id']);

            $payment->setAdditionalInformation([
                \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => $this->__paystandPaymentDataTransaction($paymentData),
                'PAYSTAND_ORDER' =>  $this->__paystandPaymentDataOrder($paymentData)
            ]);

            // Formated price
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = __('The captured amount is %1.', $formatedPrice);

            //get the object of builder class
            $trans = $this->_builderInterface;
            
            $transaction = $trans->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($paymentData['id'])
                ->setAdditionalInformation([
                    \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => $this->__paystandPaymentDataTransaction($paymentData),
                    'PAYSTAND_ORDER' =>  $this->__paystandPaymentDataOrder($paymentData)
                ])
                ->setFailSafe(true)
                //build method creates the transaction and returns the object
                ->build(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE);

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );

            $payment->importTransactionInfo($transaction);
            $payment->setParentTransactionId(null);

            $payment->save();
            $order->save();

            $transactionId = $transaction->save()->getTransactionId();

            $this->_logger->debug('>>>>> PAYSTAND-CREATE-TRANSACTION-FINISH: transactionId: ' . $transactionId);

            return  $transactionId;

        } catch (\Magento\Framework\Exception\AlreadyExistsException $e) {
            $this->_logger->debug('>>>>> PAYSTAND-EXCEPTION: ' . $e);
        }
    }

    private function __paystandPaymentDataOrder($data){
        return [
            'source' => $data['source'],
            'meta'   => $data['meta'],
            'payer'  => $data['payer'],
            'fees'   => $data['fees'],
            'holds'  => $data['holds']
        ];
    }

    private function __paystandPaymentDataTransaction($data)
    {
        return [
            'paystandTransactionId' => $data['id'],
            'amount' => $data['settlementAmount'],
            'currency' => $data['settlementCurrency'],
            'paymentStatus' => $data['status']
        ];
    }

    private function createInvoice($order)
    {
        try {

            $this->_logger->debug('>>>>> PAYSTAND-CREATE-INVOICE-START');

            if ($order) {
                $invoices = $this->_invoiceCollectionFactory->create()
                    ->addAttributeToFilter('order_id', ['eq' => $order->getId()]);

                $invoices->getSelect()->limit(1);

                if ((int)$invoices->count() !== 0) {
                    $invoices = $invoices->getFirstItem();
                    $invoice = $this->_invoiceRepository->get($invoices->getId());
                    return $invoice;
                }

                if (!$order->canInvoice()) {
                    return null;
                }

                $invoice = $this->_invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();

                $invoice->getOrder()->setCustomerNoteNotify(false);
                $invoice->getOrder()->setIsInProcess(true);

                // Send email for invoice
                $this->_invoiceSender->send($invoice);

                // To talkative
                //$order->addStatusHistoryComment(__('Invoice ' . $invoice->getId() . ' created'), false);

                $transactionSave = $this->_transactionFactory
                    ->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());

                $transactionSave->save();

                $this->_logger->debug('>>>>> PAYSTAND-CREATE-INVOICE-FINISH: invoiceId: ' . $invoice->getEntityId());
                
                return $invoice;
            }
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __($e->getMessage())
            );
        }
    }
}
