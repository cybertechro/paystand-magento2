<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace PayStand\PayStandMagento\Block;

use Magento\Backend\Model\Session\Quote;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Block\Form\Cc;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Config;
use Magento\Vault\Model\VaultPaymentInterface;
use PayStand\PayStandMagento\Model\PayStandConfigProvider;

/**
 * Class Form
 */
class Form extends Cc
{

    /**
     * @var PayStandConfigProvider
     */
    protected $config;

    /**
     * @var Quote
     */
    protected $quote;

    /**
     * @var Data
     */
    private $paymentDataHelper;

    /**
     * @param Context $context
     * @param Config $paymentConfig
     * @param Quote $quote
     * @param Data $paymentDataHelper
     * @param PayStandConfigProvider $config
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $paymentConfig,
        Quote $quote,
        Data $paymentDataHelper,
        PayStandConfigProvider $config,
        array $data = []
    ) {
        parent::__construct($context, $paymentConfig, $data);
        $this->config = $config;
        $this->quote = $quote;
    }

    public function getConfig(){ 

        $config = $this->config->getConfig();

        if($config)
            return $config['payment']['paystandmagento'];

        return [];

    }

    public function sandbox($true, $false){

        $config = $this->getConfig();

        if($config['use_sandbox'] == '1')
            return $true;

        return $false;
        
    }

    public function getPaystandRequire(){

        $config = $this->getConfig();

        if($config['use_sandbox'] == '1')
            return 'paystand-sandbox';

        return 'paystand';
        
    }

    
}
