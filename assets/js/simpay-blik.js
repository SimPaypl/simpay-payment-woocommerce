/**
 * SimPay BLIK Level 0 — WooCommerce checkout integration
 *
 * Uses the same approach as Stripe WooCommerce:
 * 1. Blocks default WooCommerce form submission via checkout_place_order_{gateway_id}
 * 2. Submits checkout data manually via fetch
 * 3. If BLIK code accepted → shows "confirm in app" + polls status
 * 4. If error → shows error message
 * 5. On paid → redirects to order-received
 */
(function () {
    'use strict';

    var config = window.simpayBlikConfig || {};
    if (!config.level0Enabled) return;

    function init() {
        if (!window.jQuery) return;

        // Block WooCommerce default checkout for simpay_blik gateway
        jQuery('form.checkout').on('checkout_place_order_simpay_blik', function () {
            handleBlikCheckout();
            return false; // Prevent WooCommerce default submission
        });

        // Fallback: if on order-received page with pending BLIK
        if (config.orderId && config.blikPending && window.simpayBlik) {
            window.simpayBlik.onCodeAccepted(config.orderId, window.location.href);
        }
    }

    function handleBlikCheckout() {
        var form = document.querySelector('form.checkout');
        if (!form) return;

        // Collect form data BEFORE disabling inputs
        var formData = new FormData(form);

        // Now show loading state (disables input)
        if (window.simpayBlik) {
            window.simpayBlik.setLoading(true);
            window.simpayBlik.setMessage(config.i18n.processing || 'Processing...', 'loading');
        }

        // Submit form data to WooCommerce checkout endpoint
        fetch(getCheckoutUrl(), {
            method: 'POST',
            body: new URLSearchParams(formData),
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
        })
        .then(function (res) { return res.json(); })
        .then(function (response) {
            if (response.result === 'failure') {
                // Show WooCommerce error messages
                if (window.simpayBlik) {
                    window.simpayBlik.setLoading(false);
                }
                showWcErrors(response.messages || '');
                jQuery('form.checkout').removeClass('processing').unblock();
                return;
            }

            if (response.result === 'success' && response.redirect) {
                // Check if this is a BLIK pending redirect
                var match = response.redirect.match(/simpay_blik_pending=(\d+)/);

                if (match) {
                    var orderId = parseInt(match[1], 10);
                    var redirectUrl = response.redirect.replace(/[?&]simpay_blik_pending=\d+/, '');

                    // BLIK code accepted! Show confirm message and poll
                    if (window.simpayBlik) {
                        window.simpayBlik.onCodeAccepted(orderId, redirectUrl);
                    }
                } else {
                    // Normal redirect (shouldn't happen for Level 0, but just in case)
                    window.location.href = response.redirect;
                }
            }
        })
        .catch(function () {
            if (window.simpayBlik) {
                window.simpayBlik.setLoading(false);
                window.simpayBlik.setMessage(config.i18n.genericError || 'Payment failed. Try again.', 'error');
            }
            jQuery('form.checkout').removeClass('processing').unblock();
        });
    }

    function getCheckoutUrl() {
        // WooCommerce checkout AJAX URL
        if (window.wc_checkout_params && wc_checkout_params.checkout_url) {
            return wc_checkout_params.checkout_url;
        }
        return '/?wc-ajax=checkout';
    }

    function showWcErrors(html) {
        if (!html) return;

        var notices = document.querySelector('.woocommerce-notices-wrapper');
        if (!notices) {
            notices = document.querySelector('.woocommerce');
        }
        if (notices) {
            // Remove existing errors first
            var existing = notices.querySelectorAll('.woocommerce-error');
            existing.forEach(function(el) { el.remove(); });

            notices.insertAdjacentHTML('afterbegin', html);
            var errorEl = notices.querySelector('.woocommerce-error');
            if (errorEl) {
                errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        // Also update inline BLIK message
        if (window.simpayBlik) {
            var text = html.replace(/<[^>]*>/g, '').trim();
            if (text) {
                window.simpayBlik.setMessage(text, 'error');
            }
        }
    }

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

