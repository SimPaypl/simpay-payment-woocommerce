/**
 * SimPay BLIK Widget — input formatting, message/loader, polling
 *
 * Depends on: simpayBlikConfig (from wp_localize_script)
 */
(function () {
    'use strict';

    var config = window.simpayBlikConfig || {};
    var i18n = config.i18n || {};

    // ─── DOM helpers (always fresh lookup) ───
    function getInput() { return document.getElementById('simpay_blik_code'); }
    function getWrapper() { return document.getElementById('simpay-blik-fields'); }

    // ─── Input formatting (delegated) ───
    document.addEventListener('input', function (e) {
        if (!e.target || e.target.id !== 'simpay_blik_code') return;
        var clean = e.target.value.replace(/[^0-9]/g, '').substring(0, 6);
        e.target.value = clean.length > 3 ? clean.slice(0, 3) + ' ' + clean.slice(3) : clean;
    });

    document.addEventListener('paste', function (e) {
        if (!e.target || e.target.id !== 'simpay_blik_code') return;
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text');
        var clean = text.replace(/[^0-9]/g, '').substring(0, 6);
        e.target.value = clean.length > 3 ? clean.slice(0, 3) + ' ' + clean.slice(3) : clean;
    });

    document.addEventListener('keypress', function (e) {
        if (!e.target || e.target.id !== 'simpay_blik_code') return;
        if (!/[0-9]/.test(e.key) && e.key !== 'Backspace' && e.key !== 'Tab' && e.key !== 'Enter') {
            e.preventDefault();
        }
    });

    // ─── Message & Loader ───
    function setMessage(msg, status) {
        var w = getWrapper();
        if (!w) return;
        var hint = w.querySelector('[data-simpay-blik-hint]');
        var msgEl = w.querySelector('[data-simpay-blik-message]');
        if (msgEl) msgEl.textContent = msg || '';
        if (hint) hint.setAttribute('data-status', status || '');
    }

    function setLoading(isLoading) {
        var w = getWrapper();
        var input = getInput();
        if (w) {
            var loader = w.querySelector('[data-simpay-blik-loader]');
            if (loader) loader.style.display = isLoading ? 'inline-flex' : 'none';
        }
        if (input) input.disabled = !!isLoading;
        var btn = document.querySelector('#place_order');
        if (btn) btn.disabled = !!isLoading;
    }

    // ─── Polling ───
    function pollStatus(orderId, redirectUrl) {
        var maxPolls = 90;
        var count = 0;

        var timer = setInterval(function () {
            count++;
            if (count > maxPolls) {
                clearInterval(timer);
                setLoading(false);
                setMessage(i18n.timeout || 'Payment failed - not confirmed on time. Try again.', 'error');
                return;
            }

            var data = new URLSearchParams();
            data.append('action', 'simpay_blik_status');
            data.append('nonce', config.nonce || '');
            data.append('order_id', orderId);

            fetch(config.ajaxUrl, {
                method: 'POST',
                body: data,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success || !res.data) return;
                var st = res.data;

                if (st.status === 'paid') {
                    clearInterval(timer);
                    setMessage(i18n.success || 'Payment confirmed. Redirecting…', 'success');
                    setTimeout(function () {
                        window.location.href = st.redirect || redirectUrl;
                    }, 1000);
                }

                if (st.status === 'failed') {
                    clearInterval(timer);
                    setLoading(false);
                    setMessage(st.message || i18n.genericError || 'Payment failed. Try again.', 'error');
                    var input = getInput();
                    if (input) { input.value = ''; input.focus(); }
                }
            })
            .catch(function () {});
        }, 2000);
    }

    // ─── Global API ───
    window.simpayBlik = {
        setMessage: setMessage,
        setLoading: setLoading,
        onCodeAccepted: function (orderId, redirectUrl) {
            setLoading(true);
            setMessage(i18n.confirmInApp || 'Confirm the payment in your banking app.', 'loading');
            pollStatus(orderId, redirectUrl);
        }
    };

    // ─── Click on radio option to select it (div-based, not label) ───
    document.addEventListener('click', function (e) {
        var option = e.target.closest('.simpay-blik-radio-option');
        if (!option) return;
        var radio = option.querySelector('.simpay-blik-radio-input');
        if (radio && !radio.checked) {
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    // ─── Mode radio toggle (delegated) ───
    document.addEventListener('change', function (e) {
        if (!e.target || e.target.name !== 'simpay_blik_mode') return;

        var mode = e.target.value;
        var fallback = document.getElementById('simpay-blik-code-fallback');

        // Update active class on radio options
        var options = document.querySelectorAll('.simpay-blik-radio-option');
        options.forEach(function (opt) {
            opt.classList.remove('simpay-blik-radio-option--active');
        });
        var activeLabel = e.target.closest('.simpay-blik-radio-option');
        if (activeLabel) activeLabel.classList.add('simpay-blik-radio-option--active');

        if (mode === 'code') {
            if (fallback) fallback.style.display = '';
            var input = getInput();
            if (input) input.focus();
        } else {
            if (fallback) fallback.style.display = 'none';
            var input = getInput();
            if (input) input.value = '';
        }
    });
})();
