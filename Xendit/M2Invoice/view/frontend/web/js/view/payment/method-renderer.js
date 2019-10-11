	
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'bcava',
                component: 'Xendit_M2Invoice/js/view/payment/method-renderer/bcava'
            },
            {
                type: 'alfamart',
                component: 'Xendit_M2Invoice/js/view/payment/method-renderer/alfamart'
            },
            {
                type: 'bniva',
                component: 'Xendit_M2Invoice/js/view/payment/method-renderer/bniva'
            },
            {
                type: 'briva',
                component: 'Xendit_M2Invoice/js/view/payment/method-renderer/briva'
            },
            {
                type: 'mandiriva',
                component: 'Xendit_M2Invoice/js/view/payment/method-renderer/mandiriva'
            },
            {
                type: 'permatava',
                component: 'Xendit_M2Invoice/js/view/payment/method-renderer/permatava'
            },
            {
                type: 'ovo',
                component: 'Xendit_M2Invoice/js/view/payment/method-renderer/ovo'
            },
            {
                type: 'cc',
                component: 'Xendit_M2Invoice/js/view/payment/method-renderer/cc'
            },
            {
                type: 'cchosted',
                component: 'Xendit_M2Invoice/js/view/payment/method-renderer/cchosted'
            }
        );
        return Component.extend({});
    }
);