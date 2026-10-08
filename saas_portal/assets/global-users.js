'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const status = document.getElementById('globalUsersStatus');
    const schemaNotice = document.getElementById('globalUsersSchemaNotice');
    const usersRoot = document.getElementById('globalUsersList');
    const candidatesRoot = document.getElementById('globalUsersCandidates');
    const previewRoot = document.getElementById('globalUserPreview');
    const refresh = document.getElementById('globalUsersRefresh');
    if (!status || !usersRoot || !candidatesRoot || !previewRoot) return;

    let accounts = [];
    let candidates = [];
    let schemaReady = false;
    let busy = false;
    let pendingPreview = null;
    let deactivationId = null;

    function text(parent, tag, value, className = '') {
        const node = document.createElement(tag);
        node.textContent = value == null ? '' : String(value);
        node.className = className;
        parent.appendChild(node);
        return node;
    }
    function button(parent, label, handler, className = 'btn btn-sm btn-outline-primary') {
        const node = text(parent, 'button', label, className);
        node.type = 'button';
        node.disabled = busy || !schemaReady;
        node.addEventListener('click', handler);
        return node;
    }
    function showMessage(message, kind = 'info') {
        status.replaceChildren();
        if (message) text(status, 'div', message, `alert alert-${kind} py-2 mb-2`);
    }
    async function post(payload) {
        return portalPost('/saas_portal/api/global_users.php', {
            ...payload,
            csrf_token: portalCsrf
        });
    }
    function renderAccounts() {
        usersRoot.replaceChildren();
        if (!accounts.length) {
            text(usersRoot, 'div', 'Ingen globale SaaS-kontoer registrert.', 'portal-empty');
            return;
        }
        accounts.forEach(account => {
            const row = text(usersRoot, 'article', '', 'global-user-row');
            const identity = text(row, 'div', '', 'global-user-identity');
            text(identity, 'strong', account.username || account.email || `Konto ${account.id}`);
            if (account.email && account.email !== account.username) text(identity, 'span', account.email);
            text(identity, 'span', account.is_active == 1 ? 'Aktiv global innlogging' : 'Deaktivert', 'global-user-meta');
            if (account.is_active == 1) {
                const actions = text(row, 'div', '', 'global-user-actions');
                if (String(deactivationId) === String(account.id)) {
                    text(actions, 'span', 'Deaktiver SaaS-innloggingen?', 'small text-danger');
                    button(actions, 'Bekreft deaktivering', () => deactivate(account.id), 'btn btn-sm btn-outline-danger');
                    button(actions, 'Avbryt', () => {
                        deactivationId = null;
                        renderAccounts();
                    }, 'btn btn-sm btn-outline-secondary');
                } else {
                    button(actions, 'Deaktiver global konto', () => {
                        if (busy) return;
                        deactivationId = account.id;
                        renderAccounts();
                    }, 'btn btn-sm btn-outline-danger');
                }
            }
        });
    }
    function renderCandidates() {
        candidatesRoot.replaceChildren();
        if (!candidates.length) {
            text(candidatesRoot, 'div', 'Ingen tilgjengelige bedriftskontoer å vurdere.', 'portal-empty');
            return;
        }
        candidates.forEach(candidate => {
            const row = text(candidatesRoot, 'article', '', 'global-user-row');
            const identity = text(row, 'div', '', 'global-user-identity');
            text(identity, 'strong', candidate.username || candidate.email || `Bruker ${candidate.id}`);
            if (candidate.email && candidate.email !== candidate.username) text(identity, 'span', candidate.email);
            text(identity, 'span', `${candidate.company_name || 'Bedrift'} · bedriftskonto ${candidate.company_id}`, 'global-user-meta');
            const actions = text(row, 'div', '', 'global-user-actions');
            button(actions, 'Forhåndsvis global tilgang', () => preview(candidate.id));
        });
    }
    function renderPreview(data) {
        const result = data.preview || {};
        previewRoot.replaceChildren();
        previewRoot.hidden = false;
        text(previewRoot, 'h4', 'Gjennomgå global tilgang');
        const details = text(previewRoot, 'dl', '', 'row small mb-2');
        text(details, 'dt', 'Bruker', 'col-sm-3');
        text(details, 'dd', result.username || result.email || `Konto ${result.admin_id}`, 'col-sm-9');
        text(details, 'dt', 'E-post', 'col-sm-3');
        text(details, 'dd', result.email || 'Ikke oppgitt', 'col-sm-9');
        text(details, 'dt', 'Kildebedrift', 'col-sm-3');
        text(details, 'dd', result.company_name || 'Ikke oppgitt', 'col-sm-9');
        text(previewRoot, 'p', 'Bekreftelsen gir denne identiteten global SaaS-tilgang til alle bedrifter og aktive moduler. Global innlogging bruker e-postadressen og samme passord. Bedriftsinnloggingen fra kildebedriften deaktiveres; passordet beholdes uendret og vises aldri.', 'small mb-2');
        const blockers = Array.isArray(result.blockers) ? result.blockers : ['Forhåndsvisningen mangler en blokkeringkontroll.'];
        if (blockers.length) {
            const list = text(previewRoot, 'ul', '', 'small text-danger');
            blockers.forEach(reason => text(list, 'li', reason));
            pendingPreview = null;
            text(previewRoot, 'div', 'Kan ikke konverteres før blokkeringene er løst.', 'alert alert-warning py-2 mb-0');
        } else if (!pendingPreview) {
            text(previewRoot, 'div', 'En gyldig forhåndsvisningsreferanse mangler. Oppdater listen og prøv igjen.', 'alert alert-warning py-2 mb-0');
        } else {
            const controls = text(previewRoot, 'div', '', 'global-user-confirmation');
            const confirm = button(controls, 'Bekreft og opprett global konto', convert, 'btn btn-primary-custom');
            confirm.dataset.requiresPreview = 'true';
            button(controls, 'Avbryt forhåndsvisning', () => {
                pendingPreview = null;
                previewRoot.replaceChildren();
                previewRoot.hidden = true;
            }, 'btn btn-outline-secondary');
        }
    }
    async function preview(adminId) {
        if (busy) return;
        busy = true;
        refresh.disabled = true;
        pendingPreview = null;
        previewRoot.hidden = false;
        previewRoot.replaceChildren();
        text(previewRoot, 'div', 'Kontrollerer konto og tilgang …', 'portal-skeleton');
        showMessage('');
        renderCandidates();
        renderAccounts();
        try {
            const response = await post({action: 'preview', admin_id: adminId});
            if (!response.success || !response.preview) throw new Error(response.message || 'Forhåndsvisningen kunne ikke opprettes.');
            const result = response.preview;
            if (String(result.admin_id) !== String(adminId)) throw new Error('Forhåndsvisningen gjelder en annen konto.');
            pendingPreview = blockersEmpty(result.blockers) && typeof result.quote_hash === 'string' && result.quote_hash
                ? {adminId, quoteHash: result.quote_hash} : null;
            renderPreview(response);
        } catch (error) {
            previewRoot.replaceChildren();
            text(previewRoot, 'div', error.message || 'Forhåndsvisningen kunne ikke lastes.', 'alert alert-danger mb-0');
        } finally {
            busy = false;
            refresh.disabled = false;
            renderCandidates();
            renderAccounts();
            previewRoot.querySelectorAll('button').forEach(control => {
                control.disabled = !schemaReady || (control.dataset.requiresPreview === 'true' && !pendingPreview);
            });
        }
    }
    function blockersEmpty(value) {
        return Array.isArray(value) && value.length === 0;
    }
    async function convert() {
        if (busy || !pendingPreview) return;
        const current = pendingPreview;
        busy = true;
        refresh.disabled = true;
        previewRoot.querySelectorAll('button').forEach(control => { control.disabled = true; });
        showMessage('Oppretter global SaaS-konto …');
        renderCandidates();
        try {
            const response = await post({
                action: 'convert',
                admin_id: current.adminId,
                quote_hash: current.quoteHash,
                confirmed: true
            });
            if (!response.success) throw new Error(response.message || 'Kontoen kunne ikke konverteres.');
            pendingPreview = null;
            previewRoot.replaceChildren();
            previewRoot.hidden = true;
            await load(true);
            showMessage(response.message || 'Global SaaS-konto opprettet.', 'success');
        } catch (error) {
            showMessage(error.message || 'Kontoen kunne ikke konverteres.', 'danger');
        } finally {
            busy = false;
            refresh.disabled = false;
            renderCandidates();
            renderAccounts();
            previewRoot.querySelectorAll('button').forEach(control => {
                control.disabled = !schemaReady || (control.dataset.requiresPreview === 'true' && !pendingPreview);
            });
        }
    }
    async function deactivate(globalId) {
        if (busy || String(deactivationId) !== String(globalId)) return;
        busy = true;
        refresh.disabled = true;
        showMessage('Deaktiverer global SaaS-innlogging …');
        renderAccounts();
        try {
            const response = await post({action: 'deactivate', global_id: globalId, confirmed: true});
            if (!response.success) throw new Error(response.message || 'Global konto kunne ikke deaktiveres.');
            deactivationId = null;
            await load(true);
            showMessage(response.message || 'Global innlogging deaktivert.', 'success');
        } catch (error) {
            showMessage(error.message || 'Global konto kunne ikke deaktiveres.', 'danger');
        } finally {
            busy = false;
            refresh.disabled = false;
            renderAccounts();
            renderCandidates();
        }
    }
    async function load(force = false) {
        if (busy && !force) return;
        busy = true;
        refresh.disabled = true;
        usersRoot.setAttribute('aria-busy', 'true');
        candidatesRoot.setAttribute('aria-busy', 'true');
        usersRoot.replaceChildren();
        candidatesRoot.replaceChildren();
        text(usersRoot, 'div', '', 'portal-skeleton');
        text(candidatesRoot, 'div', '', 'portal-skeleton');
        schemaNotice.replaceChildren();
        showMessage('');
        try {
            const response = await post({action: 'list'});
            if (!response.success) throw new Error(response.message || 'Globale brukere kunne ikke lastes.');
            schemaReady = response.schema_ready === true;
            accounts = Array.isArray(response.global_users) ? response.global_users : [];
            candidates = Array.isArray(response.candidates) ? response.candidates : [];
            if (!schemaReady) {
                text(schemaNotice, 'div', 'Global brukerstøtte er ikke tilgjengelig fordi databasemodell mangler. Ingen kontoendringer kan utføres.', 'alert alert-warning py-2');
            }
            renderAccounts();
            renderCandidates();
        } catch (error) {
            schemaReady = false;
            accounts = [];
            candidates = [];
            renderAccounts();
            renderCandidates();
            showMessage(error.message || 'Globale brukere kunne ikke lastes.', 'danger');
        } finally {
            busy = false;
            refresh.disabled = false;
            usersRoot.setAttribute('aria-busy', 'false');
            candidatesRoot.setAttribute('aria-busy', 'false');
            renderAccounts();
            renderCandidates();
        }
    }
    refresh.addEventListener('click', load);
    load();
});
