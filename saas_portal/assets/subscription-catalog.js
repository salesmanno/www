'use strict';

function billingCatalogMoney(value, currency) {
    if (typeof value !== 'string' || !/^(0|[1-9]\d{0,7})(?:\.\d{1,2})?$/.test(value) ||
        !/^[A-Z]{3}$/.test(currency || '')) return 'Ukjent pris';
    return billingMoney(value, currency);
}

function billingRenderCatalog(subscription) {
    const body = document.getElementById('subscriptionModules');
    const warnings = document.getElementById('subscriptionCatalogWarnings');
    body.replaceChildren();
    warnings.replaceChildren();
    const modules = Array.isArray(subscription.modules) ? subscription.modules : [];
    for (const module of modules) {
        const row = document.createElement('tr');
        const values = [
            String(module.name || module.module_key || 'Ukjent modul'),
            billingCatalogMoney(module.price_monthly, module.price_currency),
            billingCatalogMoney(module.price_setup, module.price_currency)
        ];
        values.forEach(value => {
            const cell = document.createElement('td');
            cell.textContent = value;
            row.appendChild(cell);
        });
        body.appendChild(row);
    }
    if (!modules.length) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 3;
        cell.textContent = 'Ingen moduler er valgt i bedriftens plan.';
        row.appendChild(cell);
        body.appendChild(row);
    }
    const catalog = subscription.catalog || {};
    const fields = {
        subscriptionCatalogGross: catalog.monthly_gross,
        subscriptionCatalogDiscount: catalog.monthly_discount_amount,
        subscriptionCatalogNet: catalog.monthly_net,
        subscriptionCatalogSetup: catalog.setup_gross,
        subscriptionCatalogSetupDiscount: catalog.setup_discount_amount,
        subscriptionCatalogSetupNet: catalog.setup_net
    };
    Object.entries(fields).forEach(([id, value]) => {
        document.getElementById(id).textContent = billingCatalogMoney(value, catalog.currency);
    });
    document.getElementById('subscriptionMaxUsers').textContent =
        subscription.max_users == null ? 'Ikke satt' : String(subscription.max_users);
    for (const message of (catalog.warnings || [])) {
        const alert = document.createElement('div');
        alert.className = 'alert alert-warning py-2';
        alert.textContent = message;
        warnings.appendChild(alert);
    }
}
