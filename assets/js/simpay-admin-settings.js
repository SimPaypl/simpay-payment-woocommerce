(function () {
    'use strict';

    // Tabs: "Connect via SimPay" / "Enter credentials manually"
    var input = document.getElementById('simpay-auth-method');
    var tabs = document.querySelectorAll('.simpay-auth-tabs [data-simpay-auth]');
    var panels = document.querySelectorAll('[data-simpay-auth-panel]');

    if (!input || !tabs.length) {
        return;
    }

    function show(method) {
        tabs.forEach(function (tab) {
            tab.classList.toggle('nav-tab-active', tab.getAttribute('data-simpay-auth') === method);
        });
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-simpay-auth-panel') !== method;
        });
        input.value = method;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            show(tab.getAttribute('data-simpay-auth'));
        });
    });

    show(input.value === 'manual' ? 'manual' : 'oauth');
})();
