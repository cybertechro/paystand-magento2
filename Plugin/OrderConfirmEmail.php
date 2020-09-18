<?php
namespace PayStand\PayStandMagento\Plugin;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Escaper;
use Magento\Sales\Model\Order;

class OrderConfirmEmail extends \Magento\Framework\App\Helper\AbstractHelper
{

    const ORDER_CONFIRM_TEMPLATE = 'paystand_email_confirm';
    const ORDER_REJECT_TEMPLATE = 'paystand_email_reject';

    /**
     * 
     */
    public function __construct(
        TransportBuilder $transportBuilder,
        StoreManagerInterface $storeManager,
        Escaper $escaper,
        Context $context
    ) {

        $this->transportBuilder = $transportBuilder;
        $this->escaper = $escaper;
        $this->logger = $context->getLogger();
        $this->storeManager = $storeManager;

        parent::__construct($context);

    }

    public function sendEmail(Order $order, $template = self::ORDER_CONFIRM_TEMPLATE){

        try {
            
            $transport = $this->transportBuilder;
        
            // Build transport template
            $transport->setTemplateIdentifier($template)
                      ->setTemplateOptions([
                        'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                        'store' => $this->storeManager->getStore()->getId(),
                      ])->setTemplateVars([
                        'customerName'  =>$order->getCustomerName(),
                        'orderId' => $order->getIncrementId()
                      ]);

            // Set transport location
            $transport->setFrom('general')
                      ->addTo($order->getCustomerEmail())
                      ->addBcc($this->scopeConfig->getValue(
                            'trans_email/ident_sales/email',
                            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                       )); //send copy to Store sales
            
            // Instanciate & send
            $transport->getTransport()->sendMessage();

        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
        }
        
    }
}
