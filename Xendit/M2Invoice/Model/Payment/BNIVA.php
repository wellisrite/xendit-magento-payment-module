<?php

namespace Xendit\M2Invoice\Model\Payment;

class BNIVA extends AbstractInvoice
{
    /**
     * Payment code
     *
     * @var string
     */
    protected $_code = 'bniva';
    protected $_minAmount = 10000;
    protected $_maxAmount = 1000000000;
    protected $methodCode = 'BNI';
}
