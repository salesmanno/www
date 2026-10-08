'use strict';

const billingCompanyId = String(companyId);
const billingGatewayForms = {
    vipps: {form: 'vippsConfigForm', display: 'Vipps'},
    stripe: {form: 'stripeConfigForm', display: 'Stripe'},
    paypal: {form: 'paypalConfigForm', display: 'PayPal'}
};
const billingSecretFields = [
    'vipps_client_secret', 'vipps_subscription_key',
    'stripe_secret_key', 'stripe_webhook_secret', 'paypal_client_secret'
];
const billingGatewaySecretFields = {
    vipps: ['vipps_client_secret', 'vipps_subscription_key'],
    stripe: ['stripe_secret_key', 'stripe_webhook_secret'],
    paypal: ['paypal_client_secret']
};
const billingVisibleFields = {
    vipps: ['vipps_client_id', 'vipps_merchant_serial_number'],
    stripe: ['stripe_public_key'],
    paypal: ['paypal_client_id', 'paypal_webhook_id']
};
let billingGateways = [];
let billingGatewayData = {};
let billingGatewayEnvironments = {};
let billingGatewayVersion = null;
let billingGatewayCanEdit = false;
let billingEditToken = '';
let billingEditExpires = 0;
let billingEditTimer = null;
let billingGatewayRequest = 0;
let billingGatewaySaving = false;
let billingEncryptedCredentialsReady = false;
let billingSubscription = null;
let billingSubscriptionVersion = null;
let billingSubscriptionCanEdit = false;
let billingSubscriptionDatesReady = false;
let billingDueToken = '';
let billingDueExpires = 0;
let billingDueTimer = null;
let billingSubscriptionRequest = 0;
let billingOsloDate = '';

function billingStatus(message, type) {
    const root = document.getElementById('paymentConfigStatus');
    root.replaceChildren();
    if (message) {
        const alert = document.createElement('div');
        alert.className = 'alert alert-' + (type || 'info');
        alert.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        alert.textContent = message;
        root.appendChild(alert);
    }
}

function billingSetGatewayControls(enabled) {
    Object.entries(billingGatewayForms).forEach(([gateway, config]) => {
        const form = document.getElementById(config.form);
        form.querySelectorAll('input, select').forEach(control => {
            if (control.name === 'display_name') return;
            const blockedSecret = Boolean((billingSecretFields.includes(control.name) || control.dataset.clearSecret) &&
                !billingEncryptedCredentialsReady);
            control.disabled = !enabled || !billingGatewayCanEdit || !billingEditToken || billingGatewaySaving || blockedSecret;
        });
        form.querySelectorAll('button[onclick^="savePaymentGateway"]').forEach(button => {
            button.disabled = !enabled || !billingGatewayCanEdit || !billingEditToken || billingGatewaySaving;
        });
        form.querySelectorAll('[data-clear-secret]').forEach(control => {
            control.disabled = !enabled || !billingGatewayCanEdit || !billingEditToken || billingGatewaySaving || !billingEncryptedCredentialsReady;
        });
        const button = form.querySelector('button[onclick^="savePaymentGateway"]');
        if (button) button.disabled = !enabled || !billingGatewayCanEdit || !billingEditToken || billingGatewaySaving;
    });
    document.getElementById('paymentUnlockButton').disabled =
        !billingGatewayCanEdit || !!billingEditToken || billingGatewaySaving;
    document.getElementById('paymentCancelButton').hidden = !billingEditToken;
}

function billingRenderCredentialWarning(ready, notice = '') {
    const warning = document.getElementById('paymentCredentialWarning');
    if (ready === null) {
        warning.hidden = false;
        warning.className = 'alert alert-warning mb-4';
        warning.textContent = notice || 'Kunne ikke bekrefte at sikker lagring er tilgjengelig. Hemmelige felt forblir deaktivert.';
        return;
    }
    if (ready) {
        if (!notice) {
            warning.hidden = true;
            warning.textContent = '';
            return;
        }
        warning.hidden = false;
        warning.className = 'alert alert-info mb-4';
        warning.textContent = notice;
        return;
    }
    warning.hidden = false;
    warning.className = 'alert alert-warning mb-4';
    warning.textContent = [
        'Sikker lagring av nye eller endrede hemmelige betalingsnøkler er ikke tilgjengelig.',
        'Nøkkelfeltene er deaktivert; vanlige innstillinger kan fortsatt oppdateres.',
        'Koble til krypteringskoden før hemmelige verdier kan endres.',
        notice
    ].filter(Boolean).join(' ');
}

function billingSupportedEnvironments(gateway) {
    const reported = billingGatewayData[gateway]?.credential_environments;
    let values = [];
    if (Array.isArray(reported)) values = reported.map(value => String(value));
    else if (reported && typeof reported === 'object') {
        values = Object.entries(reported).filter(([, supported]) => supported).map(([name]) => name);
    }
    values = values.filter(value => value === 'production' || value === 'test');
    if (!values.length) values = ['production'];
    return Array.from(new Set(values));
}

function billingRenderGatewayPublicFields(gateway, environment) {
    const item = billingGatewayData[gateway];
    const config = billingGatewayForms[gateway];
    if (!item || !config) return;
    const form = document.getElementById(config.form);
    billingVisibleFields[gateway].forEach(field => {
        const input = form.elements.namedItem(field);
        if (!input) return;
        const source = environment === 'test' ? item.test_values : item;
        const value = source && Object.prototype.hasOwnProperty.call(source, field) ? source[field] : '';
        input.value = value == null ? '' : String(value);
    });
    // Secret inputs are write-only; changing environments always discards typed secrets.
    billingGatewaySecretFields[gateway].forEach(field => {
        const input = form.elements.namedItem(field);
        if (input) input.value = '';
        const clear = document.querySelector(`#payment [data-clear-secret="${field}"]`);
        if (clear) clear.checked = false;
    });
    billingGatewayEnvironments[gateway] = environment;
}

function billingConfigureGatewayEnvironment(gateway, item) {
    billingGatewayData[gateway] = item;
    const select = document.querySelector(`[data-gateway-environment="${gateway}"]`);
    const help = document.querySelector(`[data-environment-help="${gateway}"]`);
    const supported = billingSupportedEnvironments(gateway);
    const testOption = select.querySelector('option[value="test"]');
    testOption.disabled = !supported.includes('test');
    testOption.textContent = supported.includes('test') ? 'Test' : 'Test (ikke tilgjengelig)';
    select.querySelector('option[value="production"]').disabled = !supported.includes('production');
    const selected = supported.includes(item.credential_environment)
        ? item.credential_environment
        : supported.includes('production') ? 'production' : supported[0];
    select.value = selected;
    help.textContent = supported.includes('test')
        ? 'Velg hvilket nøkkelsett som skal redigeres. Hemmelige verdier vises aldri.'
        : 'Denne leverandøren støtter bare produksjonsnøkler i dette oppsettet.';
    billingRenderGatewayPublicFields(gateway, selected);
}

function billingInstallSecretRemovalControls() {
    billingSecretFields.forEach(name => {
        const input = document.querySelector(`#payment [name="${name}"]`);
        if (!input || input.dataset.clearControlReady) return;
        input.dataset.clearControlReady = 'true';
        const label = document.createElement('label');
        label.className = 'form-check small text-muted mt-2';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'form-check-input';
        checkbox.dataset.clearSecret = name;
        checkbox.disabled = true;
        const text = document.createElement('span');
        text.className = 'form-check-label';
        text.textContent = 'Fjern lagret hemmelig verdi ved lagring';
        label.append(checkbox, text);
        input.insertAdjacentElement('afterend', label);
    });
}

function billingClearSecrets() {
    billingSecretFields.forEach(name => {
        const input = document.querySelector(`#payment [name="${name}"]`);
        if (input) input.value = '';
        const checkbox = document.querySelector(`#payment [data-clear-secret="${name}"]`);
        if (checkbox) checkbox.checked = false;
    });
}

function billingShowGatewayData(data) {
    billingGateways = Array.isArray(data.gateways) ? data.gateways : [];
    billingGatewayData = {};
    billingGatewayEnvironments = {};
    billingGatewayVersion = data.version ?? billingGateways.find(item => item.version !== undefined)?.version ?? null;
    billingGatewayCanEdit = data.can_edit === true || data.can_edit === 1 || data.can_edit === '1';
    billingEncryptedCredentialsReady = data.encrypted_credentials_ready === true || data.encrypted_credentials_ready === 1;
    billingRenderCredentialWarning(billingEncryptedCredentialsReady, data.notice || '');
    Object.values(billingGatewayForms).forEach(config => document.getElementById(config.form).reset());

    billingGateways.forEach(gateway => {
        const key = String(gateway.gateway_name || '');
        if (!billingGatewayForms[key]) return;
        const form = document.getElementById(billingGatewayForms[key].form);
        const allowed = new Set(['is_active', 'test_mode']);
        Object.entries(gateway).forEach(([field, value]) => {
            if (!allowed.has(field)) return;
            const input = form.elements.namedItem(field);
            if (!input) return;
            if (input.type === 'checkbox') input.checked = value == 1 || value === true;
            else input.value = value == null ? '' : String(value);
        });
        billingConfigureGatewayEnvironment(key, gateway);
        const display = form.elements.namedItem('display_name');
        if (display) display.value = gateway.display_name || billingGatewayForms[key].display;
    });
    billingSetGatewayControls(true);
    document.getElementById('paymentLockLabel').textContent = 'Betalingsinnstillinger er låst';
    document.getElementById('paymentLockHelp').textContent =
        data.credentials_readonly === false
            ? 'Hemmelige verdier vises aldri. Tomme nøkkelfelt bevarer eksisterende verdi.'
            : 'Kunne ikke bekrefte at innstillingene kan redigeres sikkert.';
    document.getElementById('paymentUnlockButton').disabled =
        !billingGatewayCanEdit || data.credentials_readonly !== false || billingGatewayVersion === null;
    billingStatus(data.notice || (billingGateways.length
        ? ''
        : 'Ingen betalingsleverandør er konfigurert for bedriften.'), data.notice ? 'info' : '');
}

async function loadPaymentGateways() {
    const request = ++billingGatewayRequest;
    billingGatewayCanEdit = false;
    billingEncryptedCredentialsReady = false;
    billingRenderCredentialWarning(null, 'Kontrollerer tilgjengeligheten av sikker lagring av betalingsnøkler…');
    billingSetGatewayControls(false);
    document.getElementById('paymentUnlockButton').disabled = true;
    billingStatus('Henter betalingsinnstillinger…');
    try {
        const data = await portalRequest('/saas_portal/api/get_payment_gateways.php?company_id=' + encodeURIComponent(billingCompanyId));
        if (request !== billingGatewayRequest || String(companyId) !== billingCompanyId) return;
        if (!Array.isArray(data.gateways) || data.credentials_readonly !== false) {
            throw new Error('Betalingsinnstillingene kunne ikke bekreftes som trygge å vise.');
        }
        billingShowGatewayData(data);
    } catch (error) {
        if (request !== billingGatewayRequest) return;
        if (billingIsVersionConflict(error)) {
            billingReloadLockedAfterConflict('Betalingsinnstillingene ble endret et annet sted. Siden lastes på nytt i låst tilstand.');
            return;
        }
        billingStatus(error.message || 'Betalingsinnstillingene er ikke tilgjengelige.', 'danger');
        const root = document.getElementById('paymentConfigStatus');
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-sm btn-outline-danger ms-2';
        retry.textContent = 'Prøv igjen';
        retry.addEventListener('click', loadPaymentGateways);
        root.querySelector('.alert')?.appendChild(retry);
        billingGatewayCanEdit = false;
        billingEncryptedCredentialsReady = false;
        billingRenderCredentialWarning(null, 'Kunne ikke bekrefte sikker lagring. Hemmelige felt forblir deaktivert.');
        billingSetGatewayControls(false);
    }
}

function billingIsVersionConflict(error) {
    if (Number(error?.status || error?.statusCode) === 409 || error?.code === 'VERSION_CONFLICT') return true;
    return /\b409\b|\b(conflict|concurrent|outdated|stale)\b|version.{0,45}(chang|mismatch|outdat)|(?:stale|mismatch).{0,45}version/i
        .test(String(error?.message || ''));
}

async function billingVersionedUnlock(url, payload) {
    // Keep the HTTP status for 409 handling; portalPost currently discards it.
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify(payload)
    });
    let result;
    try {
        result = await response.json();
    } catch (_) {
        const error = new Error('Serveren returnerte ikke et gyldig svar.');
        error.status = response.status;
        throw error;
    }
    if (!response.ok || !result.success) {
        const error = new Error(result.message || 'Forespørselen kunne ikke fullføres.');
        error.status = response.status;
        error.code = result.code;
        throw error;
    }
    return result;
}

function billingReloadLockedAfterConflict(message) {
    billingGatewayCanEdit = false;
    billingEditToken = '';
    billingDueToken = '';
    billingSubscriptionCanEdit = false;
    billingSubscriptionDatesReady = false;
    billingGatewaySaving = false;
    clearInterval(billingEditTimer);
    clearInterval(billingDueTimer);
    billingClearSecrets();
    billingSetGatewayControls(false);
    document.getElementById('paymentUnlockButton').disabled = true;
    document.getElementById('subscriptionDueDate').disabled = true;
    document.getElementById('subscriptionDueSave').disabled = true;
    document.getElementById('subscriptionDueForm').hidden = true;
    document.getElementById('subscriptionDueCancel').hidden = true;
    document.getElementById('subscriptionDueUnlock').disabled = true;
    billingStatus(message, 'warning');
    window.location.reload();
}

function billingStartGatewayCountdown() {
    clearInterval(billingEditTimer);
    const countdown = document.getElementById('paymentUnlockCountdown');
    const tick = () => {
        const seconds = Math.max(0, Math.ceil((billingEditExpires * 1000 - Date.now()) / 1000));
        if (!seconds) {
            countdown.textContent = '';
            billingRelockGateways('Redigeringstiden er utløpt. Innstillingene er låst.');
            return;
        }
        countdown.textContent = `Låses automatisk om ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    };
    tick();
    billingEditTimer = setInterval(tick, 1000);
}

async function billingRelockGateways(message = '') {
    const token = billingEditToken;
    clearInterval(billingEditTimer);
    billingEditTimer = null;
    billingEditToken = '';
    billingEditExpires = 0;
    billingGatewaySaving = false;
    billingClearSecrets();
    document.getElementById('paymentUnlockCountdown').textContent = '';
    document.getElementById('paymentLockLabel').textContent = 'Betalingsinnstillinger er låst';
    document.getElementById('paymentLockHelp').textContent = 'Hemmelige verdier vises aldri. Tomme nøkkelfelt bevarer eksisterende verdi.';
    billingSetGatewayControls(true);
    if (token) {
        try {
            await portalPost('/saas_portal/api/payment_edit.php', {
                company_id: companyId, csrf_token: portalCsrf, action: 'lock', edit_token: token
            });
        } catch (_) {
            // Local controls remain locked even if the revocation request cannot be confirmed.
        }
    }
    if (message) billingStatus(message, 'info');
}

async function billingUnlockGateways() {
    if (!billingGatewayCanEdit || billingEditToken || billingGatewayVersion === null) return;
    const button = document.getElementById('paymentUnlockButton');
    button.disabled = true;
    try {
        const result = await billingVersionedUnlock('/saas_portal/api/payment_edit.php', {
            company_id: billingCompanyId, csrf_token: portalCsrf, action: 'unlock',
            version: billingGatewayVersion
        });
        if (String(companyId) !== billingCompanyId) {
            if (result.edit_token) portalPost('/saas_portal/api/payment_edit.php', {
                company_id: billingCompanyId, csrf_token: portalCsrf, action: 'lock', edit_token: result.edit_token
            }).catch(() => {});
            return;
        }
        if (!result.edit_token || !Number.isFinite(Number(result.expires_at)) || result.version === undefined) {
            throw new Error('Serveren ga ikke en gyldig, tidsbegrenset redigeringstillatelse.');
        }
        billingEditToken = String(result.edit_token);
        billingEditExpires = Number(result.expires_at);
        billingGatewayVersion = result.version;
        document.getElementById('paymentLockLabel').textContent = 'Redigering er midlertidig låst opp';
        document.getElementById('paymentLockHelp').textContent = 'Kontroller endringene nøye. Tilgangen utløper automatisk.';
        billingSetGatewayControls(true);
        billingStartGatewayCountdown();
        billingStatus('Redigering er låst opp i en begrenset periode.', 'info');
    } catch (error) {
        if (billingIsVersionConflict(error)) {
            billingReloadLockedAfterConflict('Betalingsinnstillingene ble endret siden de ble lest. Siden lastes på nytt i låst tilstand.');
            return;
        }
        billingStatus(error.message, 'danger');
        button.disabled = !billingGatewayCanEdit;
    }
}

async function savePaymentGateway(gateway) {
    const config = billingGatewayForms[gateway];
    if (!config || !billingEditToken || billingGatewaySaving || Date.now() >= billingEditExpires * 1000) return;
    const form = document.getElementById(config.form);
    const clearSecrets = billingSecretFields.filter(name => {
        const checkbox = document.querySelector(`#payment [data-clear-secret="${name}"]`);
        return checkbox?.checked && form.elements.namedItem(name);
    });
    const message = clearSecrets.length
        ? `Lagre ${config.display}-innstillingene og fjern de valgte hemmelige verdiene?`
        : `Lagre endringene i ${config.display}-innstillingene?`;
    if (!window.confirm(message)) return;

    const payload = {
        company_id: billingCompanyId,
        csrf_token: portalCsrf,
        gateway_name: gateway,
        display_name: config.display,
        edit_token: billingEditToken,
        version: billingGatewayVersion,
        credential_environment: billingGatewayEnvironments[gateway] || 'production',
        confirmed: true,
        clear_secrets: clearSecrets,
        is_active: !!form.elements.namedItem('is_active')?.checked,
        test_mode: !!form.elements.namedItem('test_mode')?.checked
    };
    new FormData(form).forEach((value, key) => {
        if (key === 'is_active' || key === 'test_mode') payload[key] = value === 'on';
        else if (!billingSecretFields.includes(key)) payload[key] = value;
        else payload[key] = value;
    });
    billingGatewaySaving = true;
    billingSetGatewayControls(true);
    billingStatus(`Lagrer ${config.display}-innstillingene…`);
    let versionConflict = false;
    try {
        const result = await portalPost('/saas_portal/api/save_payment_gateway.php', payload);
        billingStatus(result.message || `${config.display}-innstillingene er lagret.`, 'success');
    } catch (error) {
        if (billingIsVersionConflict(error)) {
            versionConflict = true;
            billingReloadLockedAfterConflict('Betalingsinnstillingene ble endret et annet sted. Siden lastes på nytt i låst tilstand.');
            return;
        }
        billingStatus(error.message || `Kunne ikke lagre ${config.display}-innstillingene.`, 'danger');
    } finally {
        if (!versionConflict) {
            await billingRelockGateways();
            await loadPaymentGateways();
        }
    }
}

function billingOsloToday() {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Oslo', year: 'numeric', month: '2-digit', day: '2-digit'
    }).format(new Date());
}

function billingDisplayDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return 'Ikke oppgitt';
    const date = new Date(`${value}T00:00:00Z`);
    if (Number.isNaN(date.getTime())) return 'Ukjent dato';
    return new Intl.DateTimeFormat('no-NO', {
        timeZone: 'UTC', day: 'numeric', month: 'long', year: 'numeric'
    }).format(date);
}

function billingMoney(amount, currency) {
    if (amount === null || amount === undefined || amount === '') return 'Ikke oppgitt';
    const value = Number(amount);
    if (!Number.isFinite(value)) return 'Ukjent beløp';
    try {
        return new Intl.NumberFormat('nb-NO', {
            style: 'currency', currency: currency || 'NOK', maximumFractionDigits: 2
        }).format(value);
    } catch (_) {
        return `${String(amount)} ${currency || ''}`.trim();
    }
}

function billingRenderSubscription(data) {
    const status = document.getElementById('subscriptionStatus');
    const details = document.getElementById('subscriptionDetails');
    billingSubscription = data.subscription || null;
    billingSubscriptionVersion = data.version ?? billingSubscription?.version ?? null;
    billingSubscriptionCanEdit = data.can_edit === true || data.can_edit === 1;
    billingSubscriptionDatesReady = data.dates_schema_ready === true || data.dates_schema_ready === 1;
    status.replaceChildren();
    details.hidden = true;
    billingSetDueUnlockAvailability();
    if (!billingSubscription) {
        const note = document.createElement('div');
        note.className = 'portal-empty';
        note.textContent = 'Ingen abonnementsvilkår er registrert for denne bedriften.';
        status.appendChild(note);
        return;
    }

    const subscription = billingSubscription;
    const currency = subscription.currency;
    document.getElementById('subscriptionGross').textContent = billingMoney(subscription.monthly_gross, currency);
    document.getElementById('subscriptionDiscount').textContent =
        subscription.monthly_discount_percent === null || subscription.monthly_discount_percent === undefined
            ? 'Ikke oppgitt' : `${subscription.monthly_discount_percent} %`;
    document.getElementById('subscriptionDiscountAmount').textContent =
        subscription.monthly_discount_amount == null ? 'Beløp ikke oppgitt' : billingMoney(subscription.monthly_discount_amount, currency);
    document.getElementById('subscriptionNet').textContent = billingMoney(subscription.monthly_net, currency);
    document.getElementById('subscriptionCycle').textContent = subscription.billing_cycle || 'Ikke oppgitt';
    document.getElementById('subscriptionStart').textContent = billingDisplayDate(subscription.subscription_start);
    document.getElementById('subscriptionDueDisplay').textContent = billingDisplayDate(subscription.due_date);
    const dayLabel = document.getElementById('subscriptionDueCountdown');
    const serverToday = typeof subscription.today === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(subscription.today)
        ? subscription.today : '';
    let daysToDue = Number(subscription.days_until_due);
    if (subscription.due_date && serverToday) {
        const due = Date.parse(`${subscription.due_date}T00:00:00Z`);
        const baseline = Date.parse(`${serverToday}T00:00:00Z`);
        if (Number.isFinite(due) && Number.isFinite(baseline)) daysToDue = Math.round((due - baseline) / 86400000);
    }
    if (subscription.due_date && Number.isFinite(daysToDue)) {
        const days = daysToDue;
        dayLabel.textContent = days > 0 ? `${days} dager til forfall` : days === 0 ? 'Forfaller i dag' : `${Math.abs(days)} dager etter forfall`;
    } else {
        dayLabel.textContent = subscription.due_date ? 'Dager til forfall er ukjent' : '';
    }
    details.hidden = false;
    billingSetDueUnlockAvailability();
    if (!billingSubscriptionCanEdit || !billingSubscriptionDatesReady) {
        const note = document.createElement('div');
        note.className = 'alert alert-warning mt-3 mb-0';
        note.textContent = !billingSubscriptionCanEdit
            ? 'Denne tilgangen kan ikke endre abonnementsdatoer.'
            : 'Forfallsdato kan ikke endres før datolagringen er klargjort.';
        status.appendChild(note);
    }
}

function billingDueDateCanEdit() {
    return !!billingSubscription && billingSubscriptionCanEdit && billingSubscriptionDatesReady;
}

function billingSetDueUnlockAvailability() {
    document.getElementById('subscriptionDueUnlock').disabled = !billingDueDateCanEdit();
}

async function loadCompanySubscription() {
    const request = ++billingSubscriptionRequest;
    billingSubscriptionCanEdit = false;
    billingSubscriptionDatesReady = false;
    billingSetDueUnlockAvailability();
    if (billingDueToken) await billingLockDueDate();
    const status = document.getElementById('subscriptionStatus');
    status.replaceChildren();
    const skeleton = document.createElement('div');
    skeleton.className = 'portal-skeleton';
    skeleton.setAttribute('aria-label', 'Laster abonnement');
    status.appendChild(skeleton);
    billingSetDueUnlockAvailability();
    try {
        const data = await portalRequest('/saas_portal/api/company_subscription.php?company_id=' + encodeURIComponent(billingCompanyId));
        if (request !== billingSubscriptionRequest || String(companyId) !== billingCompanyId) return;
        if (!Object.prototype.hasOwnProperty.call(data, 'subscription')) throw new Error('Abonnementsdataene er ufullstendige.');
        billingRenderSubscription(data);
    } catch (error) {
        if (request !== billingSubscriptionRequest) return;
        billingSubscriptionCanEdit = false;
        billingSubscriptionDatesReady = false;
        billingSetDueUnlockAvailability();
        portalFailure('subscriptionStatus', error.message, loadCompanySubscription);
    }
}

function billingStartDueCountdown() {
    clearInterval(billingDueTimer);
    const output = document.getElementById('subscriptionEditCountdown');
    const tick = () => {
        const seconds = Math.max(0, Math.ceil((billingDueExpires * 1000 - Date.now()) / 1000));
        if (!seconds) {
            output.textContent = '';
            billingLockDueDate('Redigeringstiden er utløpt.');
            return;
        }
        output.textContent = `Låses om ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    };
    tick();
    billingDueTimer = setInterval(tick, 1000);
}

async function billingLockDueDate(message = '') {
    const token = billingDueToken;
    clearInterval(billingDueTimer);
    billingDueTimer = null;
    billingDueToken = '';
    billingDueExpires = 0;
    document.getElementById('subscriptionDueDate').disabled = true;
    document.getElementById('subscriptionDueSave').disabled = true;
    document.getElementById('subscriptionDueForm').hidden = true;
    document.getElementById('subscriptionDueCancel').hidden = true;
    document.getElementById('subscriptionDueUnlock').hidden = false;
    billingSetDueUnlockAvailability();
    document.getElementById('subscriptionEditCountdown').textContent = '';
    if (token) {
        try {
            await portalPost('/saas_portal/api/company_subscription.php', {
                company_id: billingCompanyId, csrf_token: portalCsrf, action: 'lock', edit_token: token
            });
        } catch (_) {
            // Keep the editor locked locally if revocation cannot be confirmed.
        }
    }
    if (message) {
        const root = document.getElementById('subscriptionStatus');
        const notice = document.createElement('div');
        notice.className = 'alert alert-info mt-3';
        notice.textContent = message;
        root.appendChild(notice);
    }
}

async function billingUnlockDueDate() {
    if (!billingDueDateCanEdit() || billingDueToken) return;
    const button = document.getElementById('subscriptionDueUnlock');
    button.disabled = true;
    try {
        const result = await billingVersionedUnlock('/saas_portal/api/company_subscription.php', {
            company_id: billingCompanyId, csrf_token: portalCsrf, action: 'unlock',
            version: billingSubscriptionVersion
        });
        if (String(companyId) !== billingCompanyId) return;
        if (!result.edit_token || !Number.isFinite(Number(result.expires_at)) || result.version === undefined) {
            throw new Error('Serveren ga ikke en gyldig redigeringstillatelse.');
        }
        billingDueToken = String(result.edit_token);
        billingDueExpires = Number(result.expires_at);
        billingSubscriptionVersion = result.version;
        const form = document.getElementById('subscriptionDueForm');
        const date = document.getElementById('subscriptionDueDate');
        date.value = billingSubscription.due_date || '';
        date.disabled = false;
        document.getElementById('subscriptionDueSave').disabled = false;
        form.hidden = false;
        button.hidden = true;
        document.getElementById('subscriptionDueCancel').hidden = false;
        billingStartDueCountdown();
    } catch (error) {
        if (billingIsVersionConflict(error)) {
            billingReloadLockedAfterConflict('Abonnementsvilkårene ble endret siden de ble lest. Siden lastes på nytt i låst tilstand.');
            return;
        }
        billingSetDueUnlockAvailability();
        portalFailure('subscriptionStatus', error.message);
    }
}

async function billingSaveDueDate(event) {
    event.preventDefault();
    if (!billingDueDateCanEdit() || !billingDueToken || Date.now() >= billingDueExpires * 1000) return;
    const dueDate = document.getElementById('subscriptionDueDate').value;
    if (!dueDate) {
        portalFailure('subscriptionStatus', 'Velg en forfallsdato før du lagrer.');
        return;
    }
    if (!window.confirm(`Bekreft at neste forfallsdato skal settes til ${billingDisplayDate(dueDate)}?`)) return;
    const button = document.getElementById('subscriptionDueSave');
    button.disabled = true;
    try {
        await portalPost('/saas_portal/api/company_subscription.php', {
            company_id: billingCompanyId, csrf_token: portalCsrf, action: 'save_due',
            edit_token: billingDueToken, version: billingSubscriptionVersion,
            due_date: dueDate, confirmed: true
        });
        await billingLockDueDate();
        await loadCompanySubscription();
    } catch (error) {
        if (billingIsVersionConflict(error)) {
            billingReloadLockedAfterConflict('Forfallsdatoen ble endret et annet sted. Siden lastes på nytt i låst tilstand.');
            return;
        }
        portalFailure('subscriptionStatus', error.message);
        button.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    billingInstallSecretRemovalControls();
    Object.keys(billingGatewayForms).forEach(gateway => {
        document.querySelector(`[data-gateway-environment="${gateway}"]`).addEventListener('change', event => {
            billingRenderGatewayPublicFields(gateway, event.target.value);
        });
    });
    document.getElementById('paymentUnlockButton').addEventListener('click', billingUnlockGateways);
    document.getElementById('paymentCancelButton').addEventListener('click', () => billingRelockGateways('Endringene ble avbrutt. Ingen betalingsinnstillinger ble lagret.'));
    document.getElementById('payment-tab').addEventListener('shown.bs.tab', loadPaymentGateways);
    Object.keys(billingGatewayForms).forEach(gateway => {
        const button = document.querySelector(`#${billingGatewayForms[gateway].form} button[onclick^="savePaymentGateway"]`);
        if (button) button.disabled = true;
    });
    document.getElementById('subscription-tab').addEventListener('shown.bs.tab', loadCompanySubscription);
    document.getElementById('subscriptionDueUnlock').addEventListener('click', billingUnlockDueDate);
    document.getElementById('subscriptionDueCancel').addEventListener('click', () => billingLockDueDate('Endring av forfallsdato ble avbrutt.'));
    document.getElementById('subscriptionDueForm').addEventListener('submit', billingSaveDueDate);
    loadPaymentGateways();
    loadCompanySubscription();

    document.addEventListener('visibilitychange', () => {
        const today = billingOsloToday();
        if (document.visibilityState === 'visible' && billingOsloDate && today !== billingOsloDate) {
            loadCompanySubscription();
        }
        billingOsloDate = today;
    });
    billingOsloDate = billingOsloToday();
    setInterval(() => {
        const today = billingOsloToday();
        if (today !== billingOsloDate) {
            billingOsloDate = today;
            if (!document.hidden) loadCompanySubscription();
        }
    }, 30000);
});
