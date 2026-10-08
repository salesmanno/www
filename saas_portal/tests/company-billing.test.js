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
        if (selector.startsWith('[data-plaintext-consent=')) return this.plaintextConsent || null;
        return this.saveButton || null;
    }
    querySelectorAll(selector) {
        if (selector === 'input, select') return this.controls || [];
        if (selector.startsWith('button')) return this.saveButton ? [this.saveButton] : [];
        if (selector === '[data-clear-secret]') return this.clearControls || [];
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
    vipps: ['display_name', 'is_active', 'test_mode', 'credential_environment', 'credential_storage',
        'vipps_client_id', 'vipps_client_secret', 'vipps_merchant_serial_number', 'vipps_subscription_key'],
    stripe: ['display_name', 'is_active', 'test_mode', 'credential_environment', 'credential_storage',
        'stripe_public_key', 'stripe_secret_key', 'stripe_webhook_secret'],
    paypal: ['display_name', 'is_active', 'test_mode', 'credential_environment', 'credential_storage',
        'paypal_client_id', 'paypal_client_secret', 'paypal_webhook_id']
};

function makeHarness() {
    const elements = {};
    const forms = {};
    const selectors = {};
    const storageChoices = {};
    const environmentHelp = {};
    const clearSecrets = {};
    const consentWraps = {};
    const plaintextWarnings = {};
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
        const storage = form.controls.find(control => control.name === 'credential_storage');
        storage.type = 'select';
        storage.value = 'existing';
        storage.defaultValue = 'existing';
        form.plaintextConsent = new MockElement('', 'checkbox');
        form.plaintextConsent.dataset.plaintextConsent = gateway;
        form.controls.push(form.plaintextConsent);
        form.clearControls = gatewayFields[gateway]
            .filter(name => name.includes('secret') || (gateway === 'vipps' && name === 'vipps_subscription_key'))
            .map(name => {
                const clear = new MockElement('', 'checkbox');
                clear.dataset.clearSecret = name;
                clearSecrets[name] = clear;
                return clear;
            });
        form.controls.push(...form.clearControls);
        form.saveButton = new MockElement('save', 'button');
        form.elements = {namedItem: name => form.controls.find(control => control.name === name) || null};
        forms[gateway] = form;
        elements[form.name] = form;
        selectors[gateway] = new MockElement(gateway, 'select', 'production');
        storageChoices[gateway] = form.elements.namedItem('credential_storage');
        environmentHelp[gateway] = new MockElement(`${gateway} environment help`);
        consentWraps[gateway] = new MockElement(`${gateway} consent wrapper`);
        plaintextWarnings[gateway] = new MockElement(`${gateway} plaintext warning`);
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
            match = selector.match(/data-credential-storage="(\w+)"/);
            if (match) return storageChoices[match[1]];
            match = selector.match(/data-plaintext-consent="(\w+)"/);
            if (match) return forms[match[1]].plaintextConsent;
            match = selector.match(/data-plaintext-consent-wrap="(\w+)"/);
            if (match) return consentWraps[match[1]];
            match = selector.match(/data-plaintext-warning="(\w+)"/);
            if (match) return plaintextWarnings[match[1]];
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
        postCalls: [],
        portalPost: async (url, payload) => {
            context.postCalls.push({url, payload});
            return {success: true, message: 'ok'};
        },
        portalFailure(id, message) {
            elements[id].replaceChildren();
            elements[id].textContent = message;
        },
        fetch: async () => ({
            ok: true,
            status: 200,
            json: async () => ({success: true, edit_token: 'edit', expires_at: Math.floor(Date.now() / 1000) + 300, version: 1})
        }),
        FormData: class MockFormData {
            constructor(form) {
                this.entries = (form.controls || [])
                    .filter(control => control.name && !control.disabled &&
                        (control.type !== 'checkbox' || control.checked))
                    .map(control => [control.name, control.type === 'checkbox' ? 'on' : control.value]);
            }
            forEach(callback) { this.entries.forEach(([key, value]) => callback(value, key)); }
        },
        console
    };
    vm.createContext(context);
    vm.runInContext(source, context);
    return {
        context, elements, forms, selectors, storageChoices, clearSecrets,
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
    assert.match(app.elements.paymentCredentialWarning.textContent, /ikke verifisert/i);
    assert.equal(app.elements.paymentCredentialWarning.hidden, false);

    app.evaluate("billingEditToken = 'edit'; billingSetGatewayControls(true)");
    assert.equal(app.forms.vipps.elements.namedItem('vipps_client_id').disabled, false);
    assert.equal(app.forms.vipps.elements.namedItem('vipps_client_secret').disabled, false);
    assert.equal(app.forms.vipps.elements.namedItem('vipps_subscription_key').disabled, false);
});

test('storage defaults to existing; plaintext requires fresh explicit consent and clears on mode/lock/save', async () => {
    const app = makeHarness();
    const response = {
        can_edit: true, credentials_readonly: false, encrypted_credentials_ready: false, version: 14,
        gateways: [{
            gateway_name: 'vipps', credential_environment: 'production',
            credential_environments: ['production', 'test'], vipps_client_id: 'public',
            vipps_client_secret: 'DO-NOT-HYDRATE', vipps_subscription_key: 'DO-NOT-HYDRATE'
        }]
    };
    app.context.getResult = response;
    app.context.portalRequest = async () => response;
    app.evaluate('billingShowGatewayData(getResult)');
    assert.equal(app.storageChoices.vipps.value, 'existing');
    assert.equal(app.forms.vipps.plaintextConsent.checked, false);

    app.evaluate("billingEditToken = 'edit'; billingEditExpires = Math.floor(Date.now() / 1000) + 300; billingSetGatewayControls(true)");
    app.storageChoices.vipps.value = 'plaintext';
    app.evaluate("billingRefreshStorageChoice('vipps', true)");
    assert.equal(app.forms.vipps.plaintextConsent.checked, false);
    assert.equal(app.forms.vipps.elements.namedItem('vipps_client_secret').disabled, true);
    app.forms.vipps.elements.namedItem('vipps_client_secret').value = 'pending-secret';
    await app.evaluate("savePaymentGateway('vipps')");
    assert.equal(app.context.postCalls.some(call => call.url.includes('save_payment_gateway.php')), false,
        'nonempty plaintext secret must not submit without consent');
    app.forms.vipps.plaintextConsent.checked = true;
    app.evaluate('billingSetGatewayControls(true)');
    app.forms.vipps.elements.namedItem('vipps_client_secret').value = 'fresh-secret';
    await app.evaluate("savePaymentGateway('vipps')");
    const save = app.context.postCalls.find(call => call.url.includes('save_payment_gateway.php'));
    assert.equal(save.payload.credential_storage, 'plaintext');
    assert.equal(save.payload.credential_storage_confirmed, true);
    assert.equal(save.payload.vipps_client_secret, 'fresh-secret');
    assert.equal(app.storageChoices.vipps.value, 'existing');
    assert.equal(app.forms.vipps.plaintextConsent.checked, false);
});

test('explicit secret removal remains enabled without codec and is sent independently', async () => {
    const app = makeHarness();
    const response = {
        can_edit: true, credentials_readonly: false, encrypted_credentials_ready: false, version: 18,
        gateways: [{
            gateway_name: 'vipps', credential_environment: 'production',
            credential_environments: ['production'], vipps_client_id: 'public'
        }]
    };
    app.context.response = response;
    app.context.portalRequest = async () => response;
    app.evaluate('billingShowGatewayData(response)');
    app.evaluate("billingEditToken = 'edit'; billingEditExpires = Math.floor(Date.now() / 1000) + 300; billingSetGatewayControls(true)");
    const removal = app.clearSecrets.vipps_client_secret;
    assert.equal(removal.disabled, false);
    removal.checked = true;
    await app.evaluate("savePaymentGateway('vipps')");
    const save = app.context.postCalls.find(call => call.url.includes('save_payment_gateway.php'));
    assert.equal(JSON.stringify(save.payload.clear_secrets), JSON.stringify(['vipps_client_secret']));
    assert.equal(save.payload.credential_storage, 'existing');
    assert.equal(save.payload.credential_storage_confirmed, false);
});

test('environment changes and payment role denial reset storage mode and consent', () => {
    const app = makeHarness();
    app.context.getResult = {
        can_edit: false, credentials_readonly: false, encrypted_credentials_ready: true, version: 4,
        gateways: [{
            gateway_name: 'vipps', credential_environment: 'test',
            credential_environments: ['production', 'test'],
            vipps_client_id: 'prod', test_values: {vipps_client_id: 'test'}
        }]
    };
    app.evaluate('billingShowGatewayData(getResult)');
    app.evaluate("billingGatewayCanEdit = true; billingEditToken = 'edit'; billingSetGatewayControls(true)");
    app.storageChoices.vipps.value = 'plaintext';
    app.forms.vipps.plaintextConsent.checked = true;
    app.selectors.vipps.value = 'production';
    app.evaluate("billingRenderGatewayPublicFields('vipps', 'production')");
    assert.equal(app.storageChoices.vipps.value, 'existing');
    assert.equal(app.forms.vipps.plaintextConsent.checked, false);

    app.evaluate("billingGatewayCanEdit = false; billingEditToken = 'edit'; billingSetGatewayControls(true)");
    assert.equal(app.elements.paymentUnlockButton.disabled, true);
    assert.equal(app.forms.vipps.elements.namedItem('credential_storage').disabled, true);
    assert.equal(app.forms.vipps.plaintextConsent.disabled, true);
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

