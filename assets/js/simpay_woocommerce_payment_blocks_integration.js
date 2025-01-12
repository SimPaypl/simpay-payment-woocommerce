if (typeof registerPaymentMethod == "undefined") {
    const {registerPaymentMethod} = wc.wcBlocksRegistry;
}
if (typeof createElement == "undefined") {
    const {createElement} = wp.element;
}
if (typeof __ == "undefined") {
    const {__} = wp.i18n;
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


