'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/company-billing.js'), 'utf8');

class MockElement {
    constructor(name = '', type = 'text', value = '') {
        Object.assign(this, {
            name, type, value, checked: false, disabled: false, hidden: false,
            textContent: '', className: '', dataset: {}, children: [],
            defaultValue: value, defaultChecked: false,
            options: {
                production: {disabled: false, textContent: 'Produksjon'},
                test: {disabled: false, textContent: 'Test'}
            }
        });
    }

    appendChild(node) { this.children.push(node); return node; }
    replaceChildren(...nodes) { this.children = nodes; }
    setAttribute(name, value) { this[name] = value; }
    addEventListener() {}
    querySelector(selector) {
        if (selector.startsWith('option[value=')) {
            return this.options[selector.includes('test') ? 'test' : 'production'];
        }
        return this.saveButton || null;
    }
    querySelectorAll(selector) {
        if (selector === 'input, select') return this.controls || [];
        if (selector.startsWith('button')) return this.saveButton ? [this.saveButton] : [];
        return [];
    }
    reset() {
        (this.controls || []).forEach(control => {
            control.value = control.defaultValue;
            control.checked = control.defaultChecked;
        });
    }
}

const gatewayFields = {
    vipps: ['display_name', 'is_active', 'test_mode', 'credential_environment',
        'vipps_client_id', 'vipps_client_secret', 'vipps_merchant_serial_number', 'vipps_subscription_key'],
    stripe: ['display_name', 'is_active', 'test_mode', 'credential_environment',
        'stripe_public_key', 'stripe_secret_key', 'stripe_webhook_secret'],
    paypal: ['display_name', 'is_active', 'test_mode', 'credential_environment',
        'paypal_client_id', 'paypal_client_secret', 'paypal_webhook_id']
};

function makeHarness() {
    const elements = {};
    const forms = {};
    const selectors = {};
    const environmentHelp = {};
    const clearSecrets = {};
    const basicIds = [
        'paymentConfigStatus', 'paymentCredentialWarning', 'paymentLockLabel', 'paymentLockHelp',
        'paymentUnlockButton', 'paymentCancelButton', 'paymentUnlockCountdown',
        'subscriptionStatus', 'subscriptionDetails', 'subscriptionDueUnlock',
        'subscriptionDueDate', 'subscriptionDueSave', 'subscriptionDueForm',
        'subscriptionDueCancel', 'subscriptionEditCountdown', 'subscriptionGross',
        'subscriptionDiscount', 'subscriptionDiscountAmount', 'subscriptionNet',
        'subscriptionCycle', 'subscriptionStart', 'subscriptionDueDisplay', 'subscriptionDueCountdown'
    ];
    basicIds.forEach(id => { elements[id] = new MockElement(id); });
    Object.keys(gatewayFields).forEach(gateway => {
        const form = new MockElement(`${gateway}ConfigForm`);
        form.controls = gatewayFields[gateway].map(name => {
            const type = ['is_active', 'test_mode'].includes(name) ? 'checkbox'
                : name.includes('secret') || name.includes('key') ? 'password' : 'text';
            const control = new MockElement(name, type, name === 'display_name' ? gateway : '');
            control.defaultChecked = name === 'test_mode';
            control.checked = control.defaultChecked;
            return control;
        });
        form.controls.find(control => control.name === 'credential_environment').type = 'select';
        form.saveButton = new MockElement('save', 'button');
        form.elements = {namedItem: name => form.controls.find(control => control.name === name) || null};
        forms[gateway] = form;
        elements[form.name] = form;
        selectors[gateway] = new MockElement(gateway, 'select', 'production');
        environmentHelp[gateway] = new MockElement(`${gateway} environment help`);
    });

    const document = {
        hidden: false,
        visibilityState: 'visible',
        addEventListener() {},
        getElementById: id => elements[id] || null,
        createElement: tag => new MockElement(tag),
        querySelector(selector) {
            let match = selector.match(/data-gateway-environment="(\w+)"/);
            if (match) return selectors[match[1]];
            match = selector.match(/data-environment-help="(\w+)"/);
            if (match) return environmentHelp[match[1]];
            match = selector.match(/\[name="([\w_]+)"\]/);
            if (match) {
                for (const form of Object.values(forms)) {
                    const control = form.elements.namedItem(match[1]);
                    if (control) return control;
                }
            }
            match = selector.match(/data-clear-secret="([\w_]+)"/);
            return match ? clearSecrets[match[1]] || null : null;
        },
        querySelectorAll: () => []
    };
    let reloadCount = 0;
    const context = {
        companyId: 'test-company',
        portalCsrf: 'test-csrf',
        document,
        Intl, Date, Number, String, Math, Object, Array, Set, URLSearchParams,
        setInterval: () => 1,
        clearInterval() {},
        window: {confirm: () => true, location: {reload: () => { reloadCount += 1; }}},
        portalRequest: async () => ({}),
        portalPost: async () => ({success: true}),
        portalFailure(id, message) {
            elements[id].replaceChildren();
            elements[id].textContent = message;
        },
        fetch: async () => ({
            ok: true,
            status: 200,
            json: async () => ({success: true, edit_token: 'edit', expires_at: Math.floor(Date.now() / 1000) + 300, version: 1})
        }),
        console
    };
    vm.createContext(context);
    vm.runInContext(source, context);
    return {
        context, elements, forms, selectors,
        reloadCount: () => reloadCount,
        evaluate(code) { return vm.runInContext(code, context); }
    };
}

function subscription(overrides = {}) {
    return {
        id: 21, status: 'active', billing_cycle: 'monthly', currency: 'NOK',
        monthly_gross: '100.00', monthly_discount_percent: '0',
        monthly_discount_amount: '0.00', monthly_net: '100.00',
        subscription_start: null, due_date: '2026-11-01',
        days_until_due: 2, today: '2026-10-30', version: 5,
        ...overrides
    };
}

test('due-date unlock requires both subscription permission and ready date schema', () => {
    const app = makeHarness();
    app.context.data = {subscription: subscription(), can_edit: false, dates_schema_ready: true};
    app.evaluate('billingRenderSubscription(data)');
    assert.equal(app.elements.subscriptionDueUnlock.disabled, true);

    app.context.data = {subscription: subscription(), can_edit: true, dates_schema_ready: false};
    app.evaluate('billingRenderSubscription(data)');
    assert.equal(app.elements.subscriptionDueUnlock.disabled, true);

    app.context.data = {subscription: subscription(), can_edit: true, dates_schema_ready: true};
    app.evaluate('billingRenderSubscription(data)');
    assert.equal(app.elements.subscriptionDueUnlock.disabled, false);

    app.evaluate('billingSubscriptionCanEdit = false; billingLockDueDate()');
    assert.equal(app.elements.subscriptionDueUnlock.disabled, true);
});

test('refresh failure and loading never leave due-date editing enabled', async () => {
    const app = makeHarness();
    app.context.portalRequest = async () => {
        throw new Error('temporary unavailable');
    };
    await app.evaluate('loadCompanySubscription()');
    assert.equal(app.elements.subscriptionDueUnlock.disabled, true);

    let resolveRequest;
    app.context.portalRequest = () => new Promise(resolve => { resolveRequest = resolve; });
    const pending = app.evaluate('loadCompanySubscription()');
    assert.equal(app.elements.subscriptionDueUnlock.disabled, true);
    resolveRequest({subscription: subscription(), can_edit: false, dates_schema_ready: true});
    await pending;
    assert.equal(app.elements.subscriptionDueUnlock.disabled, true);
});

test('encrypted credential readiness warning stays visible and blocks secret writes only', () => {
    const app = makeHarness();
    app.context.getResult = {
        can_edit: true,
        credentials_readonly: false,
        encrypted_credentials_ready: false,
        version: 12,
        gateways: [{
            gateway_name: 'vipps',
            credential_environment: 'production',
            credential_environments: ['production', 'test'],
            vipps_client_id: 'public-id',
            vipps_client_secret: 'MUST-NOT-APPEAR',
            vipps_subscription_key: 'MUST-NOT-APPEAR',
            test_values: {vipps_client_id: 'test-public-id', vipps_client_secret: 'ALSO-SECRET'}
        }]
    };
    app.evaluate('billingShowGatewayData(getResult)');
    assert.equal(app.forms.vipps.elements.namedItem('vipps_client_secret').value, '');
    assert.equal(app.forms.vipps.elements.namedItem('vipps_subscription_key').value, '');
    assert.match(app.elements.paymentCredentialWarning.textContent, /ikke tilgjengelig/i);
    assert.equal(app.elements.paymentCredentialWarning.hidden, false);

    app.evaluate("billingEditToken = 'edit'; billingSetGatewayControls(true)");
    assert.equal(app.forms.vipps.elements.namedItem('vipps_client_id').disabled, false);
    assert.equal(app.forms.vipps.elements.namedItem('vipps_client_secret').disabled, true);
    assert.equal(app.forms.vipps.elements.namedItem('vipps_subscription_key').disabled, true);
});

test('version conflict sends the current version and reloads without advancing it', async () => {
    const app = makeHarness();
    let submitted;
    app.context.fetch = async (_url, options) => {
        submitted = JSON.parse(options.body);
        return {ok: false, status: 409, json: async () => ({success: false, message: 'Version conflict'})};
    };
    app.evaluate('billingGatewayCanEdit = true; billingGatewayVersion = 33');
    await app.evaluate('billingUnlockGateways()');
    assert.equal(submitted.version, 33);
    assert.equal(submitted.action, 'unlock');
    assert.equal(app.reloadCount(), 1);
    assert.equal(app.evaluate('billingGatewayVersion'), 33);
    assert.equal(app.evaluate('billingEditToken'), '');
    assert.equal(app.elements.paymentUnlockButton.disabled, true);
});

