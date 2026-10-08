'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/company-email.js'), 'utf8');
const publicFields = [
    'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_from_email', 'smtp_from_name',
    'imap_host', 'imap_port', 'imap_encryption', 'imap_username'
];
const secrets = ['smtp_password', 'imap_password'];
const allFields = [...publicFields, ...secrets];

class MockElement {
    constructor(name = '', type = 'text', value = '') {
        Object.assign(this, {
            name, type, value, defaultValue: value, checked: false, defaultChecked: false,
            disabled: false, hidden: false, textContent: '', className: '', children: [],
            style: {}, dataset: {}, handlers: []
        });
    }
    appendChild(node) { this.children.push(node); return node; }
    replaceChildren(...nodes) { this.children = nodes; }
    setAttribute(name, value) { this[name] = value; }
    addEventListener(name, handler) { this.handlers.push({name, handler}); }
    querySelector(selector) {
        if (selector === '.alert') return this.children.find(child => String(child.className).includes('alert')) || null;
        if (selector === '[data-email-plaintext-consent]') return this.plaintextConsent || null;
        return null;
    }
    querySelectorAll(selector) {
        if (selector === 'input, select') return this.controls || [];
        if (selector === '[data-email-clear-secret]') return this.clearControls || [];
        return [];
    }
    reset() {
        (this.controls || []).forEach(control => {
            control.value = control.defaultValue;
            control.checked = control.defaultChecked;
        });
    }
}

function makeHarness() {
    const elements = {};
    const fields = {};
    const clears = {};
    const handlers = [];
    const form = new MockElement('emailConfigForm');
    const defaults = {
        smtp_port: '587', smtp_encryption: 'tls', imap_port: '993', imap_encryption: 'ssl',
        credential_storage: 'existing', configuration_id: ''
    };
    form.controls = allFields.map(name => {
        const control = new MockElement(name, secrets.includes(name) ? 'password' :
            name.includes('encryption') || name === 'credential_storage' ? 'select' : 'text', defaults[name] || '');
        control.defaultValue = defaults[name] || '';
        fields[name] = control;
        return control;
    });
    const storageControl = new MockElement('credential_storage', 'select', 'existing');
    storageControl.defaultValue = 'existing';
    fields.credential_storage = storageControl;
    form.controls.push(storageControl);
    const idControl = new MockElement('configuration_id', 'hidden', '');
    fields.configuration_id = idControl;
    form.controls.push(idControl);
    form.plaintextConsent = new MockElement('', 'checkbox');
    form.controls.push(form.plaintextConsent);
    form.clearControls = secrets.map(name => {
        const control = new MockElement('', 'checkbox');
        control.dataset.emailClearSecret = name;
        clears[name] = control;
        return control;
    });
    form.controls.push(...form.clearControls);
    form.elements = {namedItem: name => fields[name] || null};
    elements.emailConfigForm = form;
    [
        'emailConfigStatus', 'emailConnectionStatus', 'emailConfigNotice', 'emailCredentialWarning',
        'emailSupportedFieldsNotice', 'emailLockLabel', 'emailLockHelp', 'emailUnlockCountdown',
        'emailUnlockButton', 'emailCancelButton', 'emailAccountPicker', 'emailAccountHelp', 'testEmailBtn',
        'emailSaveButton', 'connectEmailBtn'
    ].forEach(id => { elements[id] = new MockElement(id); });
    const warning = new MockElement('plaintext warning');
    const consentWrap = new MockElement('consent wrap');
    const picker = elements.emailAccountPicker;
    const responseQueue = [];
    const calls = [];
    let postResponse = {success: true};
    let reloads = 0;
    let confirmCount = 0;
    let confirmAnswer = true;

    const document = {
        addEventListener(name, handler) { handlers.push({name, handler}); },
        createElement: tag => new MockElement(tag),
        getElementById: id => elements[id] || null,
        querySelector(selector) {
            if (selector === '[data-email-storage]') return fields.credential_storage;
            if (selector === '[data-email-plaintext-consent]') return form.plaintextConsent;
            if (selector === '[data-email-plaintext-warning]') return warning;
            if (selector === '[data-email-plaintext-consent-wrap]') return consentWrap;
            const clear = selector.match(/data-email-clear-secret="([\w_]+)"/);
            if (clear) return clears[clear[1]] || null;
            return null;
        }
    };
    const context = {
        companyId: 'company-a', portalCsrf: 'csrf-test', document, console,
        Date, Math, Number, String, Object, Array, Set, Map, encodeURIComponent,
        setInterval: () => 1, clearInterval() {},
        window: {
            confirm: () => { confirmCount += 1; return confirmAnswer; },
            location: {reload: () => { reloads += 1; }}
        },
        fetch: async (url, options = {}) => {
            const parsed = options.body ? JSON.parse(options.body) : null;
            calls.push({url, options, payload: parsed});
            if (options.method === 'POST') {
                const next = typeof postResponse === 'function' ? postResponse(url, parsed) : postResponse;
                return {ok: next.status !== 409, status: next.status || 200, json: async () => next};
            }
            const next = responseQueue.shift();
            if (!next) throw new Error(`No mock GET response for ${url}`);
            return {ok: true, status: 200, json: async () => next};
        }
    };
    vm.createContext(context);
    vm.runInContext(source, context);
    return {
        context, elements, form, fields, clears, warning, consentWrap, picker, calls, responseQueue,
        setPostResponse(value) { postResponse = value; },
        setConfirmAnswer(value) { confirmAnswer = value; },
        confirmCount: () => confirmCount,
        reloadCount: () => reloads,
        evaluate(code) { return vm.runInContext(code, context); }
    };
}

function emailResponse(overrides = {}) {
    return {
        success: true, config: null, configs: [], selection_required: false, can_edit: true,
        version: 0, credentials_readonly: false, supported_fields: allFields,
        encrypted_credentials_ready: false, notice: '',
        ...overrides
    };
}

function fullConfig(configurationId = 'mail-1', overrides = {}) {
    return {
        configuration_id: configurationId, smtp_host: 'smtp.example.test', smtp_port: 587,
        smtp_encryption: 'tls', smtp_username: 'mailer', smtp_from_email: 'mail@example.test',
        smtp_from_name: 'Example mail', imap_host: 'imap.example.test', imap_port: 993,
        imap_encryption: 'ssl', imap_username: 'inbox',
        smtp_password: 'MUST_NEVER_HYDRATE', imap_password: 'MUST_NEVER_HYDRATE',
        ...overrides
    };
}

test('no-row path can unlock and create a complete configuration with confirmed save', async () => {
    const app = makeHarness();
    app.responseQueue.push(
        emailResponse(),
        emailResponse({config: fullConfig('mail-new'), configs: [{configuration_id: 'mail-new', smtp_from_email: 'new@example.test'}]})
    );
    app.setPostResponse(url => url.endsWith('/email_edit.php')
        ? {success: true, edit_token: 'email-edit', expires_at: Math.floor(Date.now() / 1000) + 240, version: 1}
        : url.endsWith('/save_email_config.php')
            ? {success: true, configuration_id: 'mail-new', message: 'Saved'}
            : {success: true});

    await app.evaluate('loadEmailConfig()');
    assert.equal(app.evaluate('emailTargetMode'), 'new');
    assert.equal(app.elements.emailUnlockButton.disabled, false);
    assert.equal(app.fields.smtp_password.value, '');
    assert.equal(app.fields.imap_password.value, '');
    assert.equal(app.elements.emailCredentialWarning.hidden, false, 'missing codec warns without blocking create');

    await app.evaluate('emailUnlock()');
    const unlock = app.calls.find(call => call.url.endsWith('/email_edit.php'));
    assert.equal(unlock.payload.action, 'unlock');
    assert.equal(unlock.payload.version, 0);
    assert.equal(unlock.payload.csrf_token, 'csrf-test');
    assert.equal(app.fields.smtp_host.disabled, false);

    app.fields.smtp_host.value = 'smtp.example.test';
    app.fields.smtp_username.value = 'mailer';
    app.fields.smtp_from_email.value = 'mail@example.test';
    app.fields.smtp_from_name.value = 'Example mail';
    app.fields.imap_host.value = 'imap.example.test';
    app.fields.imap_username.value = 'inbox';
    await app.evaluate('saveEmailConfig()');
    const save = app.calls.find(call => call.url.endsWith('/save_email_config.php'));
    assert.equal(save.payload.create_new, true);
    assert.equal(save.payload.confirmed, true);
    assert.equal(save.payload.edit_token, 'email-edit');
    assert.equal(save.payload.version, 1);
    assert.equal(save.payload.smtp_host, 'smtp.example.test');
    assert.equal(save.payload.imap_host, 'imap.example.test');
    publicFields.forEach(name => assert.ok(Object.hasOwn(save.payload, name), `${name} included in first configuration save`));
    assert.equal(save.payload.credential_storage, 'existing');
    assert.equal(save.payload.credential_storage_confirmed, false);
    assert.equal(Object.hasOwn(save.payload, 'smtp_password'), false, 'blank passwords do not overwrite existing credentials');
    assert.equal(app.confirmCount(), 1, 'save requires an explicit final confirmation');
    assert.ok(app.calls.some(call => call.url.endsWith('/email_edit.php') && call.payload.action === 'lock'));
});

test('declining final save confirmation performs no write and can relock the editor', async () => {
    const app = makeHarness();
    app.responseQueue.push(emailResponse());
    app.setPostResponse(url => url.endsWith('/email_edit.php')
        ? {success: true, edit_token: 'cancel-token', expires_at: Math.floor(Date.now() / 1000) + 240, version: 1}
        : {success: true});
    await app.evaluate('loadEmailConfig()');
    await app.evaluate('emailUnlock()');
    app.setConfirmAnswer(false);
    await app.evaluate('saveEmailConfig()');
    assert.equal(app.calls.some(call => call.url.endsWith('/save_email_config.php')), false);
    assert.equal(app.evaluate('emailEditToken'), 'cancel-token');
    await app.evaluate('emailRelock()');
    assert.equal(app.calls.some(call => call.url.endsWith('/email_edit.php') && call.payload.action === 'lock'), true);
    assert.equal(app.evaluate('emailEditToken'), '');
});

test('multiple account picker requires an explicit target before unlock', async () => {
    const app = makeHarness();
    const configs = [
        {configuration_id: 'one', smtp_from_email: 'one@example.test', smtp_from_name: 'One'},
        {configuration_id: 'two', smtp_from_email: 'two@example.test', smtp_from_name: 'Two'}
    ];
    app.responseQueue.push(
        emailResponse({configs, selection_required: true}),
        emailResponse({configs, config: fullConfig('two'), selection_required: false, version: 8})
    );
    await app.evaluate('loadEmailConfig()');
    assert.equal(app.evaluate('emailTargetMode'), 'none');
    assert.equal(app.elements.emailUnlockButton.disabled, true);
    assert.ok(app.picker.children.some(option => option.value === 'one'));
    assert.ok(app.picker.children.some(option => option.value === 'two'));

    await app.evaluate("emailSelectAccount({target: {value: 'two'}})");
    assert.equal(app.evaluate('emailTargetMode'), 'existing');
    assert.equal(app.evaluate('emailTargetId'), 'two');
    assert.equal(app.fields.smtp_host.value, 'smtp.example.test');
    assert.equal(app.fields.smtp_password.value, '');
});

test('unsupported optional columns are reported per field while SMTP remains editable', async () => {
    const supported = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
        'smtp_from_email', 'smtp_from_name', 'smtp_password'];
    const config = fullConfig('smtp-only');
    const app = makeHarness();
    app.responseQueue.push(
        emailResponse({
            config, configs: [{configuration_id: 'smtp-only', smtp_from_email: config.smtp_from_email}],
            supported_fields: supported, configuration_missing: true, version: 15
        }),
        emailResponse({
            config, configs: [{configuration_id: 'smtp-only', smtp_from_email: config.smtp_from_email}],
            supported_fields: supported, configuration_missing: true, version: 16
        })
    );
    app.setPostResponse(url => url.endsWith('/email_edit.php')
        ? {success: true, edit_token: 'smtp-token', expires_at: Math.floor(Date.now() / 1000) + 240, version: 16}
        : url.endsWith('/save_email_config.php')
            ? {success: true, configuration_id: 'smtp-only'}
            : {success: true});
    await app.evaluate('loadEmailConfig()');
    assert.match(app.elements.emailConfigNotice.textContent, /repairs|reparerer/i);
    assert.match(app.elements.emailSupportedFieldsNotice.textContent, /imap_host/);
    await app.evaluate('emailUnlock()');
    assert.equal(app.fields.smtp_host.disabled, false);
    assert.equal(app.fields.imap_host.disabled, true);
    app.fields.smtp_host.value = 'smtp-updated.example.test';
    await app.evaluate('saveEmailConfig()');
    const save = app.calls.find(call => call.url.endsWith('/save_email_config.php'));
    assert.equal(save.payload.configuration_id, 'smtp-only');
    assert.equal(save.payload.create_new, undefined);
    assert.equal(save.payload.smtp_host, 'smtp-updated.example.test');
    assert.equal(save.payload.imap_host, undefined);
});

test('plaintext replacement needs fresh consent and consent resets after save', async () => {
    const app = makeHarness();
    app.responseQueue.push(
        emailResponse({config: fullConfig('mail-1'), configs: [{configuration_id: 'mail-1'}], version: 9}),
        emailResponse({config: fullConfig('mail-1'), configs: [{configuration_id: 'mail-1'}], version: 10})
    );
    app.setPostResponse(url => url.endsWith('/email_edit.php')
        ? {success: true, edit_token: 'token', expires_at: Math.floor(Date.now() / 1000) + 240, version: 10}
        : url.endsWith('/save_email_config.php')
            ? {success: true, configuration_id: 'mail-1'}
            : {success: true});
    await app.evaluate('loadEmailConfig()');
    await app.evaluate('emailUnlock()');

    app.fields.credential_storage.value = 'plaintext';
    app.evaluate('emailPlaintextModeChanged()');
    assert.equal(app.form.plaintextConsent.checked, false);
    assert.equal(app.elements.emailCredentialWarning.hidden, false);
    assert.equal(app.fields.smtp_password.disabled, true);
    app.fields.smtp_password.value = 'new-plain-password';
    await app.evaluate('saveEmailConfig()');
    assert.equal(app.calls.some(call => call.url.endsWith('/save_email_config.php')), false);

    app.form.plaintextConsent.checked = true;
    app.evaluate('emailSetControls()');
    assert.equal(app.fields.smtp_password.disabled, false);
    await app.evaluate('saveEmailConfig()');
    const save = app.calls.find(call => call.url.endsWith('/save_email_config.php'));
    assert.equal(save.payload.credential_storage, 'plaintext');
    assert.equal(save.payload.credential_storage_confirmed, true);
    assert.equal(save.payload.smtp_password, 'new-plain-password');
    assert.equal(app.fields.credential_storage.value, 'existing');
    assert.equal(app.form.plaintextConsent.checked, false);
    assert.equal(app.confirmCount(), 1, 'the final save still requires confirmation after consent');
});

test('existing-mode password replacement offers explicit raw-storage assistance when codec is unavailable', async () => {
    const app = makeHarness();
    const existing = fullConfig('mail-raw');
    app.responseQueue.push(
        emailResponse({config: existing, configs: [{configuration_id: 'mail-raw'}], version: 30}),
        emailResponse({config: existing, configs: [{configuration_id: 'mail-raw'}], version: 31})
    );
    app.setPostResponse(url => url.endsWith('/email_edit.php')
        ? {success: true, edit_token: 'raw-token', expires_at: Math.floor(Date.now() / 1000) + 240, version: 31}
        : url.endsWith('/save_email_config.php')
            ? {success: true, configuration_id: 'mail-raw'}
            : {success: true});
    await app.evaluate('loadEmailConfig()');
    await app.evaluate('emailUnlock()');
    app.fields.smtp_password.value = 'new-raw-password';
    await app.evaluate('saveEmailConfig()');
    const save = app.calls.find(call => call.url.endsWith('/save_email_config.php'));
    assert.equal(save.payload.credential_storage, 'plaintext');
    assert.equal(save.payload.credential_storage_confirmed, true);
    assert.equal(save.payload.smtp_password, 'new-raw-password');
    assert.equal(app.fields.credential_storage.value, 'existing');
    assert.equal(app.form.plaintextConsent.checked, false);
    assert.equal(app.confirmCount(), 2, 'assisted raw-storage confirmation is followed by the final save confirmation');
});

test('role restriction keeps editor locked; clear-secret and connect use protected target inputs', async () => {
    const app = makeHarness();
    app.responseQueue.push(
        emailResponse({config: fullConfig('mail-3'), configs: [{configuration_id: 'mail-3'}], can_edit: false, version: 12}),
        emailResponse({config: fullConfig('mail-3'), configs: [{configuration_id: 'mail-3'}], can_edit: true, version: 12})
    );
    app.setPostResponse(url => url.endsWith('/email_edit.php')
        ? {success: true, edit_token: 'token', expires_at: Math.floor(Date.now() / 1000) + 240, version: 13}
        : {success: true, message: 'Activated'});
    await app.evaluate('loadEmailConfig()');
    assert.equal(app.elements.emailUnlockButton.disabled, true);
    assert.equal(app.fields.smtp_host.disabled, true);

    await app.evaluate("loadEmailConfig('mail-3', true)");
    await app.evaluate('emailUnlock()');
    app.clears.smtp_password.checked = true;
    assert.equal(app.clears.smtp_password.disabled, false, 'secret removal remains enabled without codec');
    app.clears.smtp_password.checked = false;
    await app.evaluate('connectEmail()');
    const connect = app.calls.find(call => call.url.endsWith('/connect_email.php'));
    assert.equal(connect.payload.configuration_id, 'mail-3');
    assert.equal(connect.payload.confirmed, true);
    assert.equal(connect.payload.edit_token, 'token');
    assert.equal(connect.payload.version, 13);
    assert.equal(connect.payload.csrf_token, 'csrf-test');
    assert.equal(Object.hasOwn(connect.payload, 'smtp_password'), false);
    assert.match(app.elements.emailConnectionStatus.children[0].textContent, /Activated/);
});

test('network check includes SaaS CSRF without claiming password authentication', async () => {
    const app = makeHarness();
    app.responseQueue.push(emailResponse({
        config: fullConfig('mail-test'), configs: [{configuration_id: 'mail-test'}], version: 14
    }));
    app.setPostResponse(url => url.endsWith('/test_email_connection.php')
        ? {success: true, smtp_test: true, imap_test: false}
        : {success: true});
    await app.evaluate('loadEmailConfig()');
    assert.equal(app.elements.testEmailBtn.disabled, false);
    await app.evaluate('testEmailConnection()');
    const testCall = app.calls.find(call => call.url.endsWith('/test_email_connection.php'));
    assert.equal(testCall.payload.csrf_token, 'csrf-test');
    assert.equal(testCall.payload.configuration_id, 'mail-test');
    assert.equal(Object.hasOwn(testCall.payload, 'edit_token'), false);
    assert.match(app.elements.emailConfigStatus.children[0].textContent, /does not verify|bekrefter ikke/i);
});

test('409 keeps the account locked and reloads instead of accepting a new version', async () => {
    const app = makeHarness();
    app.responseQueue.push(emailResponse({config: fullConfig('mail-4'), configs: [{configuration_id: 'mail-4'}], version: 21}));
    app.setPostResponse(url => url.endsWith('/email_edit.php')
        ? {success: false, status: 409, message: 'Version conflict'}
        : {success: true});
    await app.evaluate('loadEmailConfig()');
    await app.evaluate('emailUnlock()');
    assert.equal(app.reloadCount(), 1);
    assert.equal(app.evaluate('emailEditToken'), '');
    assert.equal(app.evaluate('emailVersion'), 21);
    assert.equal(app.elements.emailSaveButton.disabled, true);
});
