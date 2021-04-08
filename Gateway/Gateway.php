<?php

namespace PayStand\PayStandMagento\Gateway;

use Magento\Framework\DataObject;
use Magento\Framework\HTTP\ZendClient;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Math\Random;
use Magento\Payment\Model\Method\ConfigInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Payment\Model\Method\Online\GatewayInterface;

use PayStand\PayStandMagento\Gateway\GatewayConfigInterface;

class Gateway
{
    
    CONST AUTH_URL = 'oauth/token';
    CONST REFUND_URL = 'payments/%s/refunds';
    
    // 
    
    protected $httpClientFactory;
    protected $mathRandom;
    protected $logger;
    
    protected $token = null;
    
    
    public function __construct(
        ZendClientFactory $httpClientFactory,
        Random $mathRandom,
        Logger $logger,
        GatewayConfigInterface $config
        ) {
            
            $this->httpClientFactory = $httpClientFactory;
            $this->mathRandom = $mathRandom;
            $this->logger = $logger;
            $this->config = $config;
            
        }
        
        private function __validate($body){
            
            if(!$body)
            throw new \Exception('Paystand responded with empty ( could be a token issue )');
            
            if(isset($body['error'])){
                
                $error = $body['error'];
                $ref = $error['ref'];
                
                $error_details = isset($error['details']['explanation']) ? 
                $error['details']['explanation']:
                $error['description'];
                
                throw new \Exception($error_details);
                
            }
            
            return $body;
            
        }
        
        private function __buildURL($url){
            return $this->config->getValue('use_sandbox') == "1" ? 
            "https://api.paystand.co/v3/" . $url : 
            "https://api.paystand.com/v3/" . $url;    
        }
        
        /**
        * 
        */
        private function __getAuthToken(){
            
            $token = $this->config->getValue('access_token');
            $last_updated = $this->config->getValue('updated_at');
            
            // if($last_updated){
                //     $now = strtotime("now");
                //     $to_time = strtotime("+20 seconds", $last_updated);
                //     echo $to_time;
                //     exit();
                //    // echo round(abs($to_time - $from_time) / 60,2). " minute";
                // }
                
                if(!$token){
                    
                    $response = $this->post(self::AUTH_URL, [
                        'grant_type' => 'client_credentials',
                        'client_id' => $this->config->getValue('client_id'),
                        'client_secret' => $this->config->getValue('client_secret'),
                        'scope' => 'auth'
                        ]);
                        
                        if(isset($response['access_token']) && isset($response['refresh_token'])){
                            
                            $this->config->setValue('access_token', $response['access_token']);
                            $this->config->setValue('refresh_token', $response['refresh_token']);
                            $this->config->setValue('expires_in', $response['expires_in']);
                            $this->config->setValue('updated_at', time());
                            $this->config->setValue('scope', $response['scope']);   
                            
                            $token = $response['access_token'];
                            
                        }
                        
                    }
                    
                    return $token;
                    
                }
                
                /**
                *  Magento\Sales\Model\Order\Payment $payment
                *  Int $amount
                */
                public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount){
                    
                    $this->token = $this->__getAuthToken();
                    $result = null;
                    
                    if(!$this->token){
                        throw new \Exception('Could not get access token from paystand!');
                    }
                    
                    $payment_id = $payment->getOrder()->getExtOrderId();
                    $increment_id = $payment->getOrder()->getIncrementId();
                    $currency_code = $payment->getOrder()->getBaseCurrencyCode();
                    
                    $result = $this->post(
                        sprintf(self::REFUND_URL, $payment_id), 
                        [
                            'amount' => $amount,
                            'currency' => $currency_code,
                            'description' => 'Refund for transaction #' . $increment_id
                            ]
                        );
                        
                    }
                    
                    /**
                    * Post request to gateway and return response
                    *
                    * @param Object $request
                    * @param GatewayConfigInterface $config
                    *
                    * @return Object
                    *
                    * @throws \Exception
                    */
                    public function post($url, $request, $config = null){
                        
                        $result = [];
                        $config = $config ? $config : $this->config;
                        
                        $client = $this->httpClientFactory->create();
                        $client->setUri($this->__buildURL($url));
                        
                        $client->setConfig([
                            'maxredirects' => 5,
                            'timeout' => 30,
                            'verifypeer' => 1
                            ]);
                            
                            $client->setMethod(\Zend_Http_Client::POST);
                            $client->setRawData(json_encode($request), 'application/json');
                            
                            $client->setHeaders([
                                'X-CUSTOMER-ID' => $config->getValue('customer_id'),
                                'Authorization' => 'Bearer ' . $this->token,
                                ]);
                                
                                $client->setUrlEncodeBody(false);
                                
                                try {
                                    
                                    $response = $client->request();
                                    $body = json_decode($response->getBody(), true);
                                    $body = $this->__validate($body);
                                    $result = array_change_key_case($body, CASE_LOWER);
                                    
                                } catch (\Zend_Http_Client_Exception $e) {
                                    
                                    throw $e;
                                    
                                } finally {
                                    
                                    $this->logger->debug([
                                        'request' => $request,
                                        'result' => $result
                                        ]);
                                        
                                    }
                                    
                                    return $result;
                                    
                                }
                            }