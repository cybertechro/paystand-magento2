<?php

namespace PayStand\PayStandMagento\Model;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentMethodInterface;
use Magento\Sales\Model\Order\Payment;
use PayStand\PayStandMagento\Gateway\Gateway;

class Directpost extends \Magento\Payment\Model\Method\AbstractMethod
{
    
    const METHOD_CODE = 'paystandmagento';

    /**
     * Payment code
     *
     * @var string
     */
    protected $_code = 'paystandmagento'; // Not worth it creating a constructor to just assign $_code to METHOD_CODE
    
    /**
     * @var string
     */
    protected $_formBlockType = 'PayStand\PayStandMagento\Block\Form';
    
    /**
     * Info instructions block path
     *
     * @var string
     */
    protected $_infoBlockType = 'Magento\Payment\Block\Info\Instructions';

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isOffline = false;

    /**
     * 
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Checkout\Model\Session $checkoutSession,
        \PayStand\PayStandMagento\Gateway\Gateway $gateway,
        \Magento\Framework\Exception\LocalizedExceptionFactory $exception,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {

        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->_storeManager = $storeManager;
        $this->_urlBuilder = $urlBuilder;
        $this->_checkoutSession = $checkoutSession;
        $this->_exception = $exception;
        $this->transactionRepository = $transactionRepository;
        $this->transactionBuilder = $transactionBuilder;
        $this->gateway = $gateway;
        
    }

    /**
     * Check whether there are CC types set in configuration
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        return parent::isAvailable($quote)
        && $this->getConfigData('publishable_key', $quote ? $quote->getStoreId() : null);
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {

        if (!$this->canRefund()) {
            throw new \Magento\Framework\Exception\LocalizedException(__('The refund action is not available.'));
        }
        
        $this->gateway->refund($payment, $amount);

        return $this;

    }
    
    /**
     * {@inheritdoc}
     */
    public function canRefund()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function canRefundPartialPerInvoice()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function canVoid()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function canEdit()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function canFetchTransactionInfo()
    {
        return false;
    }
  
    /**
     * {@inheritdoc}
     */
    public function isOffline()
    {
        return false;
    }

}
