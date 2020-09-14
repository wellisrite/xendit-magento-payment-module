define(
    [
        'Xendit_M2Invoice/js/view/payment/method-renderer/cchosted'
    ],
    function (
        Component
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Xendit_M2Invoice/payment/cc-hosted',
                redirectAfterPlaceOrder: false
            },

            getCode: function() {
                return 'cc_subscription';
            },

            getMethod: function() {
                return 'CC_SUBSCRIPTION';
            },

            getDescription: function() {
                return 'Bayar pesanan langganan dengan kartu kredit melalui Xendit';
            }
        });
    }
); 