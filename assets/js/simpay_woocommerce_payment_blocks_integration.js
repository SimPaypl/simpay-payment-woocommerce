const { registerPaymentMethod } = window.wc?.wcBlocksRegistry ?? {};
const { createElement } = window.wp?.element ?? {};
const { __ } = window.wp?.i18n ?? {};

if (!registerPaymentMethod) {
    console.error('registerPaymentMethod missing — blocks not loaded');
    return;
}

registerPaymentMethod({
    name: 'simpay_payment_gateway',
    label: __('SimPay', 'simpay_woocommerce_payment'),
    ariaLabel: __('SimPay.pl Payment Gateway', 'simpay_woocommerce_payment'),
    supports: {
        showSavedCards: false,
        tokenize: false,
    },
    content: createElement(
        'p',
        null,
        __('Zapłać przez SimPay.pl', 'simpay_woocommerce_payment')
    ),
    edit: null,
    canMakePayment: function () {
        return true;
    },
    placeOrder: function () {
        return new Promise(function (resolve) {
            console.log('Order via simpay');
            resolve();
        });
    }
});


