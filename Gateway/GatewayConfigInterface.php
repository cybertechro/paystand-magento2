<?php

 namespace PayStand\PayStandMagento\Gateway;
 
 use Magento\Framework\App\Config\ScopeConfigInterface;
 use Magento\Payment\Gateway\ConfigInterface;
 use Magento\Store\Model\ScopeInterface;
 use Magento\Framework\App\Config\Storage\WriterInterface;
 
 class GatewayConfigInterface implements ConfigInterface
 {
     const DEFAULT_PATH_PATTERN = 'payment/%s/%s';
 
     private $scopeConfig;
     private $methodCode;
     private $pathPattern;
 
     public function __construct(
         ScopeConfigInterface $scopeConfig,
         WriterInterface $configWriter,
         $methodCode = "paystandmagento",
         $pathPattern = self::DEFAULT_PATH_PATTERN
     ) {
         $this->scopeConfig = $scopeConfig;
         $this->methodCode = $methodCode;
         $this->pathPattern = $pathPattern;
         $this->configWriter = $configWriter;
     }
 
     public function setMethodCode($methodCode)
     {
         $this->methodCode = $methodCode;
     }
 
     public function setPathPattern($pathPattern)
     {
         $this->pathPattern = $pathPattern;
     }
 
     public function getValue($field, $storeId = null)
     {
         
         if ($this->methodCode === null || $this->pathPattern === null) {
             return null;
         }
 
         return $this->scopeConfig->getValue(
             sprintf($this->pathPattern, $this->methodCode, $field),
             ScopeInterface::SCOPE_STORE,
             $storeId
         );
     }

     public function setValue($field, $value, $storeId = 0){

        if ($this->methodCode === null || $this->pathPattern === null) {
            return null;
        }

        $result = $this->configWriter->save(
            sprintf($this->pathPattern, $this->methodCode, $field),
            $value, 
            ScopeInterface::SCOPE_STORE, 
            $storeId
        );

     }

 }