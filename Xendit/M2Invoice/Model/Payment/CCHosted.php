<?php

namespace Xendit\M2Invoice\Model\Payment;

use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Method\Logger;
use Xendit\M2Invoice\Helper\ApiRequest;
use Xendit\M2Invoice\Helper\LogDNA;
use Xendit\M2Invoice\Enum\LogDNALevel;

class CCHosted extends AbstractInvoice
{
    const DEFAULT_EXTERNAL_ID_PREFIX = 'magento_xendit_';
    const PLATFORM_NAME = 'MAGENTO2';
    const CC_HOSTED_PAYMENT_TYPE = 'CREDIT_CARD';
    /**
     * Payment code
     *
     * @var string
     */
    protected $_code = 'cchosted';
    protected $_minAmount = 10000;
    protected $_maxAmount = 10000000;
    protected $_canRefund = true;
    protected $methodCode = 'CCHOSTED';

    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $orderId = $order->getRealOrderId();

        $payment->setIsTransactionPending(true);

        try {
            $args = [
                'order_number' => $orderId,
                'amount' => $amount,
                'payment_type' => self::CC_HOSTED_PAYMENT_TYPE,
                'store_name' => $this->storeManager->getStore()->getName(),
                'platform_name' => self::PLATFORM_NAME
            ];

            $hostedPayment = $this->requestHostedPayment($args);

            if (isset($hostedPayment['error_code'])) {
                $message = $hostedPayment['message'];
                $this->processFailedPayment($payment, $message);

                throw new \Magento\Framework\Exception\LocalizedException(
                    new Phrase($message)
                );
            } elseif (isset($hostedPayment['id'])) {
                $hostedPaymentId = $hostedPayment['id'];
                $hostedPaymentToken = $hostedPayment['hp_token'];

                $payment->setAdditionalInformation('xendit_hosted_payment_id', $hostedPaymentId);
                $payment->setAdditionalInformation('xendit_hosted_payment_token', $hostedPaymentToken);
            } else {
                $message = 'Error connecting to Xendit. Check your API key';
                $this->processFailedPayment($payment, $message);

                throw new \Magento\Framework\Exception\LocalizedException(
                    new Phrase($message)
                );
            }
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            throw new \Magento\Framework\Exception\LocalizedException(
                new Phrase($errorMsg)
            );
        }

        return $this;
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $chargeId = $payment->getParentTransactionId();

        if ($chargeId) {
            $order = $payment->getOrder();
            $orderId = $order->getRealOrderId();
            $canRefundMore = $payment->getCreditmemo()->getInvoice()->canRefund();
            $isFullRefund = !$canRefundMore &&
                0 == (double)$order->getBaseTotalOnlineRefunded() + (double)$order->getBaseTotalOfflineRefunded();

            
            $refundData = [
                'amount' => $amount,
                'external_id' => $this->dataHelper->getExternalId($orderId, true)
            ];
            $refund = $this->requestRefund($chargeId, $refundData);

            $this->handleRefundResult($payment, $refund, $canRefundMore);

            return $this;
        } else {
            throw new \Magento\Framework\Exception\LocalizedException(
                __("Refund not available because there is no capture")
            );
        }
    }

    private function requestRefund($chargeId, $requestData)
    {
        $refundUrl = $this->dataHelper->getCheckoutUrl() . "/payment/xendit/credit-card/charges/$chargeId/refund";
        $refundMethod = \Zend\Http\Request::METHOD_POST;

        try {
            $refund = $this->apiHelper->request($refundUrl, $refundMethod, $requestData);
        } catch (\Exception $e) {
            throw $e;
        }

        return $refund;
    }

    private function requestHostedPayment($requestData)
    {
        $hostedPaymentUrl = $this->dataHelper->getCheckoutUrl() . "/payment/xendit/hosted-payments";
        $hostedPaymentMethod = \Zend\Http\Request::METHOD_POST;

        try {
            $hostedPayment = $this->apiHelper->request(
                $hostedPaymentUrl,
                $hostedPaymentMethod,
                $requestData
            );
        } catch (\Exception $e) {
            throw $e;
        }

        return $hostedPayment;
    }

    private function processFailedPayment($payment, $message)
    {
        $payment->setAdditionalInformation('xendit_failure_reason', $message);
    }

    private function handleRefundResult($payment, $refund, $canRefundMore)
    {
        if (isset($refund['error_code'])) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __($refund['message'])
            );
        }

        if ($refund['status'] == 'FAILED') {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Refund failed, please check Xendit dashboard')
            );
        }

        $payment->setTransactionId(
            $refund['id']
        )->setIsTransactionClosed(
            1
        )->setShouldCloseParentTransaction(
            !$canRefundMore
        );
    }
}