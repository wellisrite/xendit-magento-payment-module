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
                template: 'Xendit_M2Invoice/payment/multishipping/description',
                redirectAfterPlaceOrder: false
            }
        });
    }
);