<?php

namespace Xendit\M2Invoice\Controller\Checkout;

use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;
use Xendit\M2Invoice\Enum\LogDNALevel;

class InvoiceMultishipping extends AbstractAction
{
    public function execute()
    {
        try {
            $rawOrderIds        = $this->getRequest()->getParam('order_ids');
            $orderIds           = explode("-", $rawOrderIds);

            $transactionAmount  = 0;
            $orderProcessed     = false;
            $orders             = [];

            foreach ($orderIds as $key => $value) {
                $order = $this->getOrderFactory()->create();
                $order->load($value);

                $orderState = $order->getState();
                if ($orderState === Order::STATE_PROCESSING && !$order->canInvoice()) {
                    $orderProcessed = true;
                    continue;
                }

                $order->setState(Order::STATE_PENDING_PAYMENT)
                    ->setStatus(Order::STATE_PENDING_PAYMENT)
                    ->addStatusHistoryComment("Pending Xendit payment.");
                    
                array_push($orders, $order);
                
                $order->save();
    
                $transactionAmount  += (int)$order->getTotalDue();
                $billingEmail = $order->getCustomerEmail();
            }

            if ($orderProcessed) {
                return $this->_redirect('multishipping/checkout/success');
            }

            $preferredMethod = $this->getRequest()->getParam('preferred_method');
            $requestData = [
                'success_redirect_url' => $this->getDataHelper()->getSuccessUrl(true),
                'failure_redirect_url' => $this->getDataHelper()->getFailureUrl($rawOrderIds, true),
                'amount' => $transactionAmount,
                'external_id' => $this->getDataHelper()->getExternalId($rawOrderIds),
                'description' => $rawOrderIds,
                'payer_email' => $billingEmail,
                'preferred_method' => $preferredMethod,
                'should_send_email' => $this->getDataHelper()->getSendInvoiceEmail() ? "true" : "false",
                'platform_callback_url' => $this->getXenditCallbackUrl(),
                'client_type' => 'INTEGRATION',
                'payment_methods' => json_encode([strtoupper($preferredMethod)])
            ];

            $invoice = $this->createInvoice($requestData);

            if (isset($invoice['error_code'])) {
                $this->throwXenditAPIError($invoice);
            }

            $this->addInvoiceData($orders, $invoice);

            $redirectUrl = $this->getXenditRedirectUrl($invoice, $preferredMethod);
            
            $resultRedirect = $this->getRedirectFactory()->create();
            $resultRedirect->setUrl($redirectUrl);
            return $resultRedirect;
        } catch (\Exception $e) {
            $message = 'Exception caught on xendit/checkout/redirect: ' . $e->getMessage();
            $this->getLogger()->info($message);

            foreach ($orders as $order) {
                $this->cancelOrder($order, $e->getMessage());
            }
            return $this->redirectToCart($e->getMessage());
        }
    }

    private function createInvoice($requestData)
    {
        $invoiceUrl = $this->getDataHelper()->getCheckoutUrl() . "/payment/xendit/invoice";
        $invoiceMethod = \Zend\Http\Request::METHOD_POST;

        try {
            $invoice = $this->getApiHelper()->request(
                $invoiceUrl, $invoiceMethod, $requestData, false, $requestData['preferred_method']
            );
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            throw new \Magento\Framework\Exception\LocalizedException(
                new Phrase($e->getMessage())
            );
        }

        return $invoice;
    }

    private function getXenditRedirectUrl($invoice, $preferredMethod)
    {
        $url = $invoice['invoice_url'] . "#$preferredMethod";

        return $url;
    }

    private function addInvoiceData($orders, $invoice)
    {
        foreach ($orders as $key => $order) {
            $payment = $order->getPayment();
            $payment->setAdditionalInformation('payment_gateway', 'xendit');
            $payment->setAdditionalInformation('xendit_invoice_id', $invoice['id']);
            $payment->setAdditionalInformation('xendit_invoice_exp_date', $invoice['expiry_date']);
            
            $order->save();
        }
    }
}
