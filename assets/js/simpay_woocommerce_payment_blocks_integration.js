const { registerPaymentMethod } = window.wc?.wcBlocksRegistry ?? {};
const { createElement } = window.wp?.element ?? {};
const { __ } = window.wp?.i18n ?? {};
const { getSetting } = window.wc?.wcSettings ?? {};

const simpayData = getSetting ? getSetting('simpay_payment_gateway_data', {}) : {};

function simpayContent() {
    const children = [
        createElement('p', { key: 'desc' }, __('Zapłać przez SimPay.pl', 'simpay_woocommerce_payment')),
    ];

    if (simpayData.commissionNotice) {
        children.push(createElement('div', { key: 'fee', className: 'simpay-commission-notice' }, simpayData.commissionNotice));
    }

    return createElement('div', null, children);
}

if (!registerPaymentMethod) {
    console.error('registerPaymentMethod missing — blocks not loaded');
}

registerPaymentMethod({
    name: 'simpay_payment_gateway',
    label: __('SimPay', 'simpay_woocommerce_payment'),
    ariaLabel: __('SimPay.pl Payment Gateway', 'simpay_woocommerce_payment'),
    supports: {
        showSavedCards: false,
        tokenize: false,
    },
    content: simpayContent(),
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


