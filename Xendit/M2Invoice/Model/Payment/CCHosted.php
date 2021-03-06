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

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($quote === null) {
            return false;
        }

        if ($this->dataHelper->getIsActive() === '0') {
            return false;
        }

        $amount = ceil($quote->getSubtotal() + $quote->getShippingAddress()->getShippingAmount());

        if ($amount < $this->_minAmount || $amount > $this->_maxAmount) {
            return false;
        }

        if ($this->methodCode === 'CC_SUBSCRIPTION') {
            return true;
        }

        return false;
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

    public function requestHostedPayment($requestData)
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

    public function processFailedPayment($payment, $message)
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

    public function calculatePromo($order, $rawAmount)
    {
        $promo = [];
        $ruleIds = $order->getAppliedRuleIds();
        $enabledPromotions = $this->dataHelper->getEnabledPromo();

        if (empty($ruleIds) || empty($enabledPromotions)) {
            return $promo;
        }

        $ruleIds = explode(',', $ruleIds);

        foreach ($ruleIds as $ruleId) {
            foreach ($enabledPromotions as $promotion) {
                if ($promotion['rule_id'] === $ruleId) {
                    $rule = $this->ruleRepo->getById($ruleId);
                    $promo[] = $this->constructPromo($rule, $promotion, $rawAmount);
                }
            }
        }

        return $promo;
    }

    private function constructPromo($rule, $promotion, $rawAmount)
    {
        $constructedPromo = [
            'bin_list' => $promotion['bin_list'],
            'title' => $rule->getName(),
            'promo_reference' => $rule->getRuleId(),
            'type' => $this->dataHelper->mapSalesRuleType($rule->getSimpleAction()),
        ];
        $rate = $rule->getDiscountAmount();

        switch ($rule->getSimpleAction()) {
            case 'to_percent':
                $rate = 1 - ($rule->getDiscountAmount() / 100);
                break;
            case 'by_percent':
                $rate = ($rule->getDiscountAmount() / 100);
                break;
            case 'to_fixed':
                $rate = (int)$rawAmount - $rule->getDiscountAmount();
                break;
            case 'by_fixed':
                $rate = (int)$rule->getDiscountAmount();
                break;
        }

        $constructedPromo['rate'] = $rate;

        return $constructedPromo;
    }
}
