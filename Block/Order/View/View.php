<?php

namespace PayStand\PayStandMagento\Block\Order\View;

use \Magento\Backend\Block\Template\Context;
use \Magento\Framework\Registry;
use \Magento\Sales\Model\Order\Payment\Transaction;

class View extends \Magento\Backend\Block\Template
{

    const PAYSTAND_ORDER = "PAYSTAND_ORDER";
    
    /**
    * @var \Magento\Framework\Registry
    */
   private $_registry;

   /**
    * View constructor.
    * @param \Magento\Backend\Block\Template\Context $context
    * @param \Magento\Framework\Registry $registry
    * @param array $data
    */
   public function __construct(
        Context $context,
        Registry $registry,
        array $data = []
   ) {
       $this->_registry = $registry;
       parent::__construct($context, $data);
   }
   
    /**
    * Retrieve order model instance
    * 
    * @return \Magento\Sales\Model\Order
    */
   public function getOrder()
   {
       return $this->_registry->registry('current_order');
   }

    /**
    * Retrieve order model instance
    *
    * @return int
    *Get current id order
    */
   public function getOrderId()
   {
       return $this->getOrder()->getEntityId();
   }

   public function getAdditionalInfo(){
       $info = $this->getOrder()->getPayment()->getAdditionalInformation();
       return isset($info[Transaction::RAW_DETAILS]) ? $info[Transaction::RAW_DETAILS] : [];
   }

   public function getPaystandInfo() {
       $info = $this->getOrder()->getPayment()->getAdditionalInformation();
       return isset($info[self::PAYSTAND_ORDER]) ? $info[self::PAYSTAND_ORDER] : [];
   }

   public function getOrderInfoTable(){

       $paystand = $this->getPaystandInfo();

       if(!isset($paystand['source']))
        return [];

       return array_merge(
           $this->__filter($this->getAdditionalInfo(), []),
           $this->__filter($paystand['source'], ['payerId','created','lastUpdated','id', 'fingerprint'])
       );
     
   }

   private function __filter($data, $excluded = []){
    return array_filter($data, function($value, $key) use ($excluded) {
        return !is_array($value) && !in_array($key, $excluded);
    }, ARRAY_FILTER_USE_BOTH);
   }

}