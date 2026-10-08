'use strict';

const emailCompanyId = String(companyId);
const emailPublicFields = [
    'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_from_email', 'smtp_from_name',
    'imap_host', 'imap_port', 'imap_encryption', 'imap_username'
];
const emailSecretFields = ['smtp_password', 'imap_password'];
const emailAllFields = [...emailPublicFields, ...emailSecretFields];
let emailLoaded = false;
let emailBusy = false;
let emailCanEdit = false;
let emailCredentialsReadonly = true;
let emailVersion = null;
let emailEditToken = '';
let emailEditExpires = 0;
let emailEditTimer = null;
let emailRequestId = 0;
let emailTargetMode = 'none';
let emailTargetId = '';
let emailConfig = null;
let emailConfigs = [];
let emailSupportedFields = new Set(emailAllFields);
let emailEncryptedCredentialsReady = false;
let emailLastResponse = null;

function emailForm() {
    return document.getElementById('emailConfigForm');
}

function emailStatus(message, type = 'info', targetId = 'emailConfigStatus') {
    const root = document.getElementById(targetId);
    if (!root) return;
    root.replaceChildren();
    if (targetId === 'emailConnectionStatus') root.style.display = message ? 'block' : 'none';
    if (!message) return;
    const note = document.createElement('div');
    note.className = `alert alert-${type} mb-0`;
    note.setAttribute('role', type === 'danger' ? 'alert' : 'status');
    note.textContent = message;
    root.appendChild(note);
}

function emailSetCredentialWarning(ready, notice = '') {
    const warning = document.getElementById('emailCredentialWarning');
    if (ready === true && !notice) {
        warning.hidden = true;
        warning.textContent = '';
        return;
    }
    warning.hidden = false;
    warning.className = `alert ${ready === true ? 'alert-info' : 'alert-warning'} mb-3`;
    warning.textContent = ready === true
        ? notice
        : [
            'Krypteringsstøtte for *_encrypted-felter er ikke verifisert.',
            'Eksisterende lagringsmåte og vanlige felt er fortsatt tilgjengelige; passordutskifting i krypterte kolonner kan bli avvist.',
            'Eksplisitt fjerning er tilgjengelig. Rå lagring krever bekreftelse og skal bare brukes hvis e-postmodulen leser rå passord.',
            notice
        ].filter(Boolean).join(' ');
}

function emailTargetReady() {
    return emailTargetMode === 'new' || (emailTargetMode === 'existing' && !!emailTargetId);
}

function emailPlaintextConsent() {
    return document.querySelector('[data-email-plaintext-consent]');
}

function emailSetControls() {
    const form = emailForm();
    const locked = !emailLoaded || !emailCanEdit || emailCredentialsReadonly ||
        !emailEditToken || !emailTargetReady() || emailBusy;
    const storage = document.querySelector('[data-email-storage]');
    const plaintext = storage?.value === 'plaintext';
    const consent = emailPlaintextConsent();
    form.querySelectorAll('input, select').forEach(control => {
        const name = control.name;
        if (control.type === 'hidden') {
            control.disabled = false;
            return;
        }
        const unsupported = name && emailAllFields.includes(name) && !emailSupportedFields.has(name);
        const needsConsent = emailSecretFields.includes(name) && plaintext && !consent?.checked;
        control.disabled = locked || !!unsupported || needsConsent;
    });
    form.querySelectorAll('[data-email-clear-secret]').forEach(control => {
        control.disabled = locked || !emailSupportedFields.has(control.dataset.emailClearSecret);
    });
    if (consent) consent.disabled = locked;
    if (storage) storage.disabled = locked;

    const picker = document.getElementById('emailAccountPicker');
    picker.disabled = !emailLoaded || !!emailEditToken || emailBusy;
    document.getElementById('emailUnlockButton').disabled =
        !emailLoaded || !emailCanEdit || emailCredentialsReadonly || !!emailEditToken ||
        !emailTargetReady() || emailVersion === null || emailBusy;
    document.getElementById('emailCancelButton').hidden = !emailEditToken;
    document.getElementById('emailSaveButton').disabled = locked;
    document.getElementById('connectEmailBtn').disabled =
        locked || emailTargetMode !== 'existing' || !emailTargetId;
    document.getElementById('testEmailBtn').disabled =
        !emailLoaded || !emailCanEdit || emailCredentialsReadonly || !emailTargetReady() ||
        emailBusy || (emailTargetMode === 'new' && !emailEditToken);
}

function emailResetSecretInputs() {
    emailSecretFields.forEach(name => {
        const input = emailForm().elements.namedItem(name);
        if (input) input.value = '';
        const clear = document.querySelector(`[data-email-clear-secret="${name}"]`);
        if (clear) clear.checked = false;
    });
    const storage = document.querySelector('[data-email-storage]');
    const consent = emailPlaintextConsent();
    if (storage) storage.value = 'existing';
    if (consent) consent.checked = false;
    const warning = document.querySelector('[data-email-plaintext-warning]');
    const wrap = document.querySelector('[data-email-plaintext-consent-wrap]');
    if (warning) warning.hidden = true;
    if (wrap) wrap.hidden = true;
}

function emailResetForm(config = null) {
    const form = emailForm();
    form.reset();
    emailPublicFields.forEach(name => {
        const input = form.elements.namedItem(name);
        if (input) {
            if (!emailSupportedFields.has(name)) {
                input.value = '';
                return;
            }
            const value = config && Object.prototype.hasOwnProperty.call(config, name) ? config[name] : null;
            input.value = value == null ? '' : String(value);
        }
    });
    emailResetSecretInputs();
    const configurationId = config?.configuration_id || '';
    const idInput = form.elements.namedItem('configuration_id');
    if (idInput) idInput.value = String(configurationId);
}

function emailApplySupportedFields(fields) {
    emailSupportedFields = Array.isArray(fields) ? new Set(fields.map(String)) : new Set(emailAllFields);
    const missing = emailAllFields.filter(name => !emailSupportedFields.has(name));
    document.getElementById('emailSupportedFieldsNotice').textContent = missing.length
        ? `Ikke tilgjengelig i dette databaseskjemaet: ${missing.join(', ')}. Andre støttede innstillinger kan fortsatt brukes.`
        : '';
}

function emailRenderPicker(data, selectedValue = '') {
    const picker = document.getElementById('emailAccountPicker');
    picker.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = data.selection_required ? 'Velg en konto som skal redigeres' : 'Velg eller opprett en konto';
    picker.appendChild(placeholder);

    const choices = [...emailConfigs];
    if (data.config?.configuration_id != null &&
        !choices.some(config => String(config.configuration_id) === String(data.config.configuration_id))) {
        choices.push(data.config);
    }
    choices.forEach(config => {
        const option = document.createElement('option');
        option.value = String(config.configuration_id);
        const label = config.smtp_from_name || config.smtp_from_email || `E-postkonto ${config.configuration_id}`;
        option.textContent = `${label}${config.smtp_from_email && config.smtp_from_name ? ` · ${config.smtp_from_email}` : ''}`;
        picker.appendChild(option);
    });
    const create = document.createElement('option');
    create.value = '__create_new__';
    create.textContent = 'Opprett ny e-postkonto';
    picker.appendChild(create);
    picker.value = selectedValue;
}

function emailRenderAccountHelp() {
    const help = document.getElementById('emailAccountHelp');
    if (emailTargetMode === 'none') {
        help.textContent = 'Flere kontoer finnes. Velg eksplisitt hvilken konto som skal redigeres, eller opprett en ny.';
    } else if (emailTargetMode === 'new') {
        help.textContent = 'En ny konto opprettes først når du lagrer med tidsbegrenset redigering låst opp.';
    } else {
        help.textContent = `Valgt konto: ${emailTargetId}. Eksisterende passord vises aldri.`;
    }
}

function emailSetTarget(mode, id = '') {
    emailTargetMode = mode;
    emailTargetId = mode === 'existing' ? String(id) : '';
    emailConfig = mode === 'existing' ? emailConfig : null;
    emailRenderAccountHelp();
    emailSetControls();
}

function emailVersionConflict(error) {
    if (Number(error?.status || error?.statusCode) === 409 || error?.code === 'VERSION_CONFLICT') return true;
    return /\b409\b|\b(conflict|concurrent|outdated|stale)\b|version.{0,45}(chang|mismatch|outdat)/i
        .test(String(error?.message || ''));
}

async function emailPost(url, payload) {
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
    if (!response.ok || result.success === false) {
        const error = new Error(result.message || 'Forespørselen kunne ikke fullføres.');
        error.status = response.status;
        error.code = result.code;
        throw error;
    }
    return result;
}

function emailReloadAfterConflict(message) {
    emailCanEdit = false;
    emailEditToken = '';
    emailBusy = false;
    clearInterval(emailEditTimer);
    emailEditTimer = null;
    emailResetSecretInputs();
    emailSetControls();
    emailStatus(message, 'warning');
    window.location.reload();
}

async function emailRelock() {
    const token = emailEditToken;
    emailBusy = true;
    clearInterval(emailEditTimer);
    emailEditTimer = null;
    emailEditToken = '';
    emailEditExpires = 0;
    emailBusy = false;
    emailResetSecretInputs();
    document.getElementById('emailUnlockCountdown').textContent = '';
    document.getElementById('emailLockLabel').textContent = 'E-postinnstillinger er låst';
    document.getElementById('emailLockHelp').textContent = 'Velg konto og lås opp for en tidsbegrenset endring.';
    emailSetControls();
    if (token) {
        try {
            await emailPost('/saas_portal/api/email_edit.php', {
                company_id: emailCompanyId, csrf_token: portalCsrf, action: 'lock',
                edit_token: token, version: emailVersion
            });
        } catch (_) {
            // Controls remain locked locally if token revocation cannot be confirmed.
        }
    }
    emailBusy = false;
    emailSetControls();
}

function emailStartCountdown() {
    clearInterval(emailEditTimer);
    const tick = () => {
        const seconds = Math.max(0, Math.ceil((emailEditExpires * 1000 - Date.now()) / 1000));
        if (!seconds) {
            emailRelock();
            emailStatus('Redigeringstiden er utløpt. E-postinnstillingene er låst.', 'info');
            return;
        }
        document.getElementById('emailUnlockCountdown').textContent =
            `Låses automatisk om ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    };
    tick();
    emailEditTimer = setInterval(tick, 1000);
}

async function emailUnlock() {
    if (!emailLoaded || !emailCanEdit || emailCredentialsReadonly || !emailTargetReady() ||
        emailEditToken || emailVersion === null || emailBusy) return;
    const button = document.getElementById('emailUnlockButton');
    button.disabled = true;
    try {
        const result = await emailPost('/saas_portal/api/email_edit.php', {
            company_id: emailCompanyId, csrf_token: portalCsrf, action: 'unlock', version: emailVersion
        });
        if (!result.edit_token || !Number.isFinite(Number(result.expires_at)) || result.version === undefined) {
            throw new Error('Serveren ga ikke en gyldig, tidsbegrenset redigeringstillatelse.');
        }
        emailEditToken = String(result.edit_token);
        emailEditExpires = Number(result.expires_at);
        emailVersion = result.version;
        document.getElementById('emailLockLabel').textContent = 'Redigering er midlertidig låst opp';
        document.getElementById('emailLockHelp').textContent = 'Passord vises aldri. Tomme passordfelt bevarer lagrede verdier.';
        emailSetControls();
        emailStartCountdown();
        emailStatus('E-postredigering er låst opp for en begrenset periode.', 'info');
    } catch (error) {
        if (emailVersionConflict(error)) {
            emailReloadAfterConflict('E-postinnstillingene ble endret siden de ble lest. Siden lastes på nytt i låst tilstand.');
            return;
        }
        emailStatus(error.message, 'danger');
        emailSetControls();
    }
}

async function loadEmailConfig(configurationId = '', specificRequest = false) {
    const request = ++emailRequestId;
    emailLoaded = false;
    emailCanEdit = false;
    emailResetForm();
    emailSetControls();
    if (emailEditToken) await emailRelock();
    emailCredentialsReadonly = true;
    emailConfig = null;
    emailResetForm();
    emailSetControls();
    emailStatus('Henter e-postkontoer…');
    let url = `/saas_portal/api/get_email_config.php?company_id=${encodeURIComponent(emailCompanyId)}`;
    if (configurationId) url += `&configuration_id=${encodeURIComponent(configurationId)}`;
    try {
        const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Kunne ikke hente e-postinnstillingene.');
        if (request !== emailRequestId || String(companyId) !== emailCompanyId) return;
        emailLastResponse = data;
        emailConfigs = Array.isArray(data.configs) ? data.configs : [];
        emailSupportedFields = Array.isArray(data.supported_fields)
            ? new Set(data.supported_fields.map(String)) : new Set(emailAllFields);
        emailApplySupportedFields(data.supported_fields);
        emailVersion = data.version ?? data.config?.version ?? null;
        emailCanEdit = data.can_edit === true || data.can_edit === 1 || data.can_edit === '1';
        emailCredentialsReadonly = data.credentials_readonly !== false;
        emailEncryptedCredentialsReady =
            data.encrypted_credentials_ready === true || data.encrypted_credentials_ready === 1;
        emailSetCredentialWarning(emailEncryptedCredentialsReady, data.notice || '');

        const configId = data.config?.configuration_id == null ? '' : String(data.config.configuration_id);
        let selected = '';
        if (configurationId) selected = String(configurationId);
        else if (configId) selected = configId;
        else if (data.selection_required) selected = '';
        else if (emailConfigs.length === 1 && !specificRequest) {
            const oneId = String(emailConfigs[0].configuration_id);
            if (oneId) {
                emailRenderPicker(data, oneId);
                return await loadEmailConfig(oneId, true);
            }
        } else if (emailConfigs.length === 0) selected = '__create_new__';

        emailRenderPicker(data, selected);
        if (selected === '__create_new__') {
            emailSetTarget('new');
            emailResetForm();
        } else if (selected) {
            if (!data.config || String(data.config.configuration_id) !== selected) {
                if (specificRequest) throw new Error('Den valgte e-postkontoen ble ikke returnert av serveren.');
                emailRenderPicker(data, '');
                emailSetTarget('none');
                emailResetForm();
            } else {
                emailConfig = data.config;
                emailSetTarget('existing', selected);
                emailResetForm(emailConfig);
            }
        } else {
            emailSetTarget('none');
            emailResetForm();
        }
        emailLoaded = true;
        emailSetControls();
        document.getElementById('emailLockHelp').textContent =
            emailCredentialsReadonly
                ? 'Serveren bekreftet ikke at innstillingene kan redigeres trygt.'
                : emailCanEdit
                    ? 'Velg konto og lås opp for en tidsbegrenset endring.'
                    : 'Denne SaaS-rollen kan ikke endre e-postinnstillinger.';
        document.getElementById('emailConfigNotice').textContent = [
            data.notice || '',
            data.configuration_missing
                ? 'E-postkoblingen mangler i firmakonfigurasjonen. En bevisst lagring av denne kontoen reparerer koblingen.'
                : ''
        ].filter(Boolean).join(' ');
        emailStatus(data.selection_required && !selected
            ? 'Velg en konto før du kan redigere.'
            : selected === '__create_new__'
                ? 'Ingen konto finnes ennå. Fyll inn støttede felt og lagre for å opprette den første kontoen.'
                : '');
    } catch (error) {
        if (request !== emailRequestId) return;
        emailLoaded = false;
        emailCanEdit = false;
        emailCredentialsReadonly = true;
        emailTargetMode = 'none';
        emailTargetId = '';
        emailSetControls();
        emailStatus(error.message || 'E-postinnstillingene er ikke tilgjengelige.', 'danger');
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-sm btn-outline-danger ms-2';
        retry.textContent = 'Prøv igjen';
        retry.addEventListener('click', () => loadEmailConfig(configurationId, specificRequest));
        document.getElementById('emailConfigStatus').querySelector('.alert')?.appendChild(retry);
    }
}

async function emailSelectAccount(event) {
    const selection = event.target.value;
    if (emailEditToken) await emailRelock();
    if (selection === '__create_new__') {
        emailConfig = null;
        emailSetTarget('new');
        emailResetForm();
        emailStatus('Ny konto valgt. Kontoen opprettes når du lagrer.');
        return;
    }
    if (!selection) {
        emailConfig = null;
        emailSetTarget('none');
        emailResetForm();
        emailStatus('Velg en konto eller opprett en ny.');
        return;
    }
    await loadEmailConfig(selection, true);
}

function emailPlaintextModeChanged() {
    const plaintext = document.querySelector('[data-email-storage]').value === 'plaintext';
    const consent = emailPlaintextConsent();
    if (consent) consent.checked = false;
    document.querySelector('[data-email-plaintext-warning]').hidden = !plaintext;
    document.querySelector('[data-email-plaintext-consent-wrap]').hidden = !plaintext;
    emailSetControls();
}

function emailHasSecretInput() {
    return emailSecretFields.some(name => String(emailForm().elements.namedItem(name)?.value || '').trim() !== '');
}

function emailClearNames() {
    return emailSecretFields.filter(name =>
        document.querySelector(`[data-email-clear-secret="${name}"]`)?.checked
    );
}

function emailBuildPayload() {
    const storageMode = document.querySelector('[data-email-storage]').value === 'plaintext' ? 'plaintext' : 'existing';
    const confirmed = storageMode === 'plaintext' && !!emailPlaintextConsent()?.checked;
    if (storageMode === 'plaintext' && emailHasSecretInput() && !confirmed) {
        throw new Error('Bekreft uttrykkelig rå, ukryptert lagring før du legger inn nye passord.');
    }
    const payload = {
        company_id: emailCompanyId,
        csrf_token: portalCsrf,
        edit_token: emailEditToken,
        version: emailVersion,
        confirmed: true,
        credential_storage: storageMode,
        credential_storage_confirmed: confirmed,
        clear_secrets: emailClearNames()
    };
    if (emailTargetMode === 'new') payload.create_new = true;
    else if (emailTargetMode === 'existing' && emailTargetId) payload.configuration_id = emailTargetId;
    else throw new Error('Velg en eksisterende konto eller opprett en ny før du lagrer.');

    emailPublicFields.forEach(name => {
        if (!emailSupportedFields.has(name)) return;
        payload[name] = emailForm().elements.namedItem(name).value;
    });
    emailSecretFields.forEach(name => {
        if (!emailSupportedFields.has(name)) return;
        const value = String(emailForm().elements.namedItem(name)?.value || '');
        if (value !== '') payload[name] = value;
    });
    return payload;
}

async function emailFinishMutation(configurationId, message, type, statusTarget = 'emailConfigStatus') {
    await emailRelock();
    await loadEmailConfig(configurationId || '');
    if (message) emailStatus(message, type, statusTarget);
}

async function saveEmailConfig() {
    if (!emailLoaded || !emailEditToken || emailBusy || Date.now() >= emailEditExpires * 1000) return;
    const storageControl = document.querySelector('[data-email-storage]');
    if (storageControl.value !== 'plaintext' && emailHasSecretInput() && !emailEncryptedCredentialsReady) {
        const approved = window.confirm(
            'Krypteringskoden for enkelte passordfelt er ikke verifisert. Vil du uttrykkelig bruke rå, ukryptert lagring? Velg bare dette hvis e-postmodulen leser rå passord, ikke hvis den forventer dekoding.'
        );
        if (!approved) return;
        storageControl.value = 'plaintext';
        emailPlaintextModeChanged();
        emailPlaintextConsent().checked = true;
        emailSetControls();
    }
    let payload;
    try {
        payload = emailBuildPayload();
    } catch (error) {
        emailStatus(error.message, 'warning');
        return;
    }
    const clearCount = payload.clear_secrets.length;
    const confirmText = payload.credential_storage === 'plaintext'
        ? 'Du valgte rå lagring uten kryptering. Bekreft lagring av e-postinnstillingene.'
        : 'Bekreft lagring av e-postinnstillingene.';
    if (!window.confirm(clearCount ? `${confirmText} ${clearCount} lagret passord blir fjernet.` : confirmText)) return;
    emailBusy = true;
    emailSetControls();
    emailStatus('Lagrer e-postinnstillingene…');
    let conflict = false;
    try {
        const result = await emailPost('/saas_portal/api/save_email_config.php', payload);
        if (result.version !== undefined) emailVersion = result.version;
        const id = result.configuration_id || result.config?.configuration_id ||
            (emailTargetMode === 'existing' ? emailTargetId : '');
        await emailFinishMutation(id, result.message || 'E-postinnstillingene ble lagret.', 'success');
    } catch (error) {
        if (emailVersionConflict(error)) {
            conflict = true;
            emailReloadAfterConflict('E-postinnstillingene ble endret et annet sted. Siden lastes på nytt i låst tilstand.');
            return;
        }
        emailStatus(error.message || 'Kunne ikke lagre e-postinnstillingene.', 'danger');
    } finally {
        if (!conflict && emailEditToken) {
            emailBusy = false;
            await emailRelock();
        }
    }
}

function emailHasUnsavedChanges() {
    if (!emailConfig) return emailPublicFields.some(name =>
        String(emailForm().elements.namedItem(name)?.value || '') !== ''
    ) || emailHasSecretInput();
    const changedPublic = emailPublicFields.some(name => {
        if (!emailSupportedFields.has(name)) return false;
        const current = String(emailForm().elements.namedItem(name)?.value || '');
        const saved = String(emailConfig[name] == null ? '' : emailConfig[name]);
        return current !== saved;
    });
    return changedPublic || emailHasSecretInput() || emailClearNames().length > 0;
}

async function connectEmail() {
    if (!emailLoaded || !emailEditToken || emailBusy || Date.now() >= emailEditExpires * 1000 ||
        emailTargetMode !== 'existing' || !emailTargetId) return;
    if (emailHasUnsavedChanges()) {
        emailStatus('Lagre eller avbryt skjemendringene før du aktiverer kontoen.', 'warning');
        return;
    }
    if (!window.confirm('Bekreft aktivering av den valgte e-postkontoen. Dette bekrefter ikke en SMTP- eller IMAP-test.')) return;
    emailBusy = true;
    emailSetControls();
    emailStatus('Aktiverer valgt e-postkonto…', 'info', 'emailConnectionStatus');
    try {
        const result = await emailPost('/saas_portal/api/connect_email.php', {
            company_id: emailCompanyId,
            csrf_token: portalCsrf,
            configuration_id: emailTargetId,
            edit_token: emailEditToken,
            version: emailVersion,
            confirmed: true
        });
        if (result.version !== undefined) emailVersion = result.version;
        await emailFinishMutation(emailTargetId, result.message || 'Aktivering fullført.', 'success', 'emailConnectionStatus');
    } catch (error) {
        if (emailVersionConflict(error)) {
            emailReloadAfterConflict('E-postkontoen ble endret et annet sted. Siden lastes på nytt i låst tilstand.');
            return;
        }
        emailStatus(error.message || 'Kunne ikke aktivere e-postkontoen.', 'danger', 'emailConnectionStatus');
    } finally {
        if (emailEditToken) {
            emailBusy = false;
            await emailRelock();
        }
    }
}

async function testEmailConnection() {
    if (!emailLoaded || !emailCanEdit || emailCredentialsReadonly || !emailTargetReady() || emailBusy) return;
    const payload = {company_id: emailCompanyId, csrf_token: portalCsrf};
    if (emailTargetMode === 'existing') payload.configuration_id = emailTargetId;
    else if (emailTargetMode === 'new') payload.create_new = true;
    emailPublicFields.forEach(name => {
        if (emailSupportedFields.has(name)) payload[name] = emailForm().elements.namedItem(name).value;
    });
    emailSecretFields.forEach(name => {
        const value = String(emailForm().elements.namedItem(name)?.value || '');
        if (emailSupportedFields.has(name) && value !== '') payload[name] = value;
    });
    emailBusy = true;
    emailSetControls();
    emailStatus('Kontrollerer tilgjengelig nettverkstilkobling. Resultatet bekrefter ikke passordautentisering.', 'info');
    try {
        const result = await emailPost('/saas_portal/api/test_email_connection.php', payload);
        const checks = [];
        if (result.smtp_test === true) checks.push('SMTP-tilkobling rapportert OK');
        if (result.imap_test === true) checks.push('IMAP-tilkobling rapportert OK');
        const detail = [result.smtp_error, result.imap_error].filter(Boolean).join(' ');
        const summary = checks.length ? checks.join(' · ') : detail || result.message || 'Ingen vellykket nettverkstilkobling rapportert.';
        emailStatus(`${summary}. Dette bekrefter ikke SMTP- eller IMAP-passordautentisering.`, result.success ? 'info' : 'warning');
    } catch (error) {
        emailStatus(`${error.message || 'Nettverkstilkoblingen kunne ikke kontrolleres.'} Dette er ikke en passordautentiseringstest.`, 'danger');
    } finally {
        emailBusy = false;
        emailSetControls();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('emailUnlockButton').addEventListener('click', emailUnlock);
    document.getElementById('emailCancelButton').addEventListener('click', async () => {
        const selectedId = emailTargetMode === 'existing' ? emailTargetId : '';
        await emailRelock();
        if (selectedId) await loadEmailConfig(selectedId, true);
        else {
            emailResetForm();
            emailSetControls();
            emailStatus('Endringene ble avbrutt. Ingen e-postinnstillinger ble lagret.', 'info');
        }
    });
    document.getElementById('emailAccountPicker').addEventListener('change', emailSelectAccount);
    document.querySelector('[data-email-storage]').addEventListener('change', emailPlaintextModeChanged);
    emailPlaintextConsent().addEventListener('change', emailSetControls);
    document.getElementById('email-tab').addEventListener('shown.bs.tab', () => loadEmailConfig());
    loadEmailConfig();
});
