(() => {
    'use strict';

    const API_URL = '/saas_portal/api/prospect_company.php';
    const dialog = document.getElementById('prospectCompanyDialog');
    if (!dialog) return;

    const state = {
        item: null,
        selectionDetail: null,
        prepared: null,
        matches: [],
        mode: '',
        loading: false,
        submitting: false,
        request: 0,
        retryCompanyId: null,
        resultCompanyId: null,
        confirmControlState: null
    };
    const el = (id) => document.getElementById(id);
    const text = (value) => value === null || value === undefined ? '' : String(value);
    const elements = {
        button: el('createProspectCompany'),
        alert: el('prospectCompanyAlert'),
        loading: el('prospectCompanyLoading'),
        prepareError: el('prospectCompanyPrepareError'),
        prepareErrorText: el('prospectCompanyPrepareErrorText'),
        matches: el('prospectCompanyMatches'),
        matchList: el('prospectCompanyMatchList'),
        details: el('prospectCompanyDetails'),
        intro: el('prospectCompanyIntro'),
        candidatesSection: el('prospectCompanyCandidatesSection'),
        candidateList: el('prospectCompanyCandidateList'),
        candidateCount: el('prospectCompanyCandidateCount'),
        warning: el('prospectCompanyWarning'),
        result: el('prospectCompanyResult'),
        submit: el('prospectCompanySubmit'),
        retryImport: el('prospectCompanyRetryImport'),
        subdomain: el('prospectCompanySubdomain'),
        subdomainError: el('prospectCompanySubdomainError')
    };

    function showAlert(message) {
        elements.alert.textContent = message || '';
        elements.alert.hidden = !message;
    }

    function canConvert(detail) {
        return !!detail && detail.saved && !detail.dirty && !detail.saving &&
            String(detail.status || '').trim().toLowerCase() === 'interessert';
    }

    function updateButton(detail) {
        elements.button.disabled = state.submitting || !canConvert(detail);
        elements.button.setAttribute('aria-disabled', elements.button.disabled ? 'true' : 'false');
        elements.button.title = elements.button.disabled
            ? 'Krever lagret prospekt, status «interessert» og ingen ulagrede endringer.'
            : 'Opprett bedrift fra lagret prospekt.';
    }

    function setBusy(value) {
        state.loading = value;
        elements.loading.hidden = !value;
        elements.submit.disabled = value || state.submitting || !state.prepared;
        elements.retryImport.disabled = value || state.submitting;
        dialog.setAttribute('aria-busy', value ? 'true' : 'false');
    }

    function clearView() {
        state.prepared = null;
        state.matches = [];
        state.mode = '';
        state.retryCompanyId = null;
        state.resultCompanyId = null;
        showAlert('');
        elements.prepareError.hidden = true;
        elements.matches.hidden = true;
        elements.matchList.replaceChildren();
        elements.details.hidden = true;
        elements.candidatesSection.hidden = true;
        elements.candidateList.replaceChildren();
        elements.warning.hidden = true;
        elements.result.hidden = true;
        elements.result.replaceChildren();
        elements.submit.hidden = false;
        elements.submit.textContent = 'Opprett bedrift';
        elements.submit.disabled = true;
        elements.retryImport.hidden = true;
        elements.subdomain.value = '';
        elements.subdomainError.hidden = true;
        elements.subdomain.removeAttribute('aria-invalid');
    }

    async function post(payload) {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), 45000);
        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                credentials: 'include',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ csrf_token: window.prospectsCsrf, ...payload }),
                signal: controller.signal
            });
            let body;
            try {
                body = await response.json();
            } catch (_) {
                const error = new Error('Uventet svar fra serveren. Prøv igjen.');
                error.uncertain = payload.action === 'confirm';
                throw error;
            }
            if (!response.ok || body.success !== true) {
                const error = new Error(body.message || 'Forespørselen kunne ikke fullføres.');
                error.status = response.status;
                error.errors = body.errors || {};
                throw error;
            }
            return body;
        } catch (error) {
            if (controller.signal.aborted) {
                const timeout = new Error(payload.action === 'confirm'
                    ? 'Tidsavbrudd: vi vet ikke om bedriften ble opprettet. Gjenta trygt med samme bekreftelse; serveren håndterer gjentatte bekreftelser sikkert.'
                    : 'Forespørselen tok for lang tid. Prøv igjen.');
                timeout.uncertain = payload.action === 'confirm';
                throw timeout;
            }
            if (payload.action === 'confirm' && !error.status) {
                error.uncertain = true;
            }
            throw error;
        } finally {
            window.clearTimeout(timer);
        }
    }

    function setOutput(id, value) {
        el(id).textContent = text(value).trim() || '—';
    }

    function renderCompany(company, mode) {
        const record = company || {};
        el('prospectCompanyTitle').textContent = mode === 'import' ? 'Importer ansatte' : 'Opprett bedrift';
        setOutput('prospectCompanyName', record.name || record.legal_name);
        setOutput('prospectCompanyOrg', record.org_number);
        setOutput('prospectCompanyEmail', record.contact_email);
        setOutput('prospectCompanyPhone', record.contact_phone);
        setOutput('prospectCompanyLegalName', record.legal_name);
        const address = [record.address_street, [record.address_zip, record.address_city].filter(Boolean).join(' ')].filter(Boolean).join(', ');
        setOutput('prospectCompanyAddress', address);
        setOutput('prospectCompanyIndustry', record.industry);
        elements.intro.textContent = mode === 'import'
            ? 'Eksisterende bedrift valgt. Velg eventuelle manglende ansatte som skal importeres.'
            : 'Kontroller registrerte opplysninger og velg nettadresse for den nye bedriften.';
        if (record.subdomain && !elements.subdomain.value) elements.subdomain.value = String(record.subdomain).toLowerCase();
        elements.details.hidden = false;
        elements.subdomain.readOnly = mode === 'import';
    }

    function previewWarning(body) {
        const setup = Array.isArray(body?.setup_warnings) ? body.setup_warnings : [];
        return [body?.warning, ...setup].filter(item => typeof item === 'string' && item).join(' ');
    }

    function renderCandidates(candidates) {
        const rows = Array.isArray(candidates) ? candidates : [];
        elements.candidateList.replaceChildren();
        elements.candidatesSection.hidden = false;
        elements.candidateCount.textContent = `${rows.length} kandidater`;
        elements.warning.textContent = previewWarning(state.prepared);
        elements.warning.hidden = !elements.warning.textContent;
        if (!rows.length) {
            const empty = document.createElement('p');
            empty.className = 'prospect-company-candidates-empty';
            empty.textContent = state.prepared?.warning
                ? 'Rolleinnehaverne kunne ikke hentes nå. Bedriften kan opprettes, og ansattimport kan forsøkes senere.'
                : 'Ingen rolleinnehavere funnet i Brønnøysundregistrene.';
            elements.candidateList.append(empty);
        }
        rows.forEach((candidate) => {
            const row = document.createElement('label');
            row.className = `prospect-company-candidate${candidate.status === 'missing' ? '' : ' is-disabled'}`;
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = text(candidate.id);
            checkbox.checked = candidate.status === 'missing';
            checkbox.disabled = candidate.status !== 'missing';
            checkbox.setAttribute('aria-label', `${text(candidate.first_name)} ${text(candidate.last_name)}${candidate.status === 'missing' ? ', velges for import' : `, ${text(candidate.message || candidate.status)}`}`);
            const content = document.createElement('span');
            const name = document.createElement('strong');
            name.textContent = `${text(candidate.first_name)} ${text(candidate.last_name)}`.trim() || 'Ukjent kandidat';
            const detail = document.createElement('small');
            const parts = [candidate.job_title, candidate.message || statusMessage(candidate.status)].filter(Boolean);
            detail.textContent = parts.join(' · ') || 'Kan velges for import';
            content.append(name, detail);
            row.append(checkbox, content);
            elements.candidateList.append(row);
        });
    }

    function statusMessage(status) {
        if (status === 'existing') return 'Finnes allerede';
        if (status === 'ambiguous') return 'Uklart treff – må avklares';
        if (status === 'blocked') return 'Import blokkert';
        return '';
    }

    function companyLink(companyId, name) {
        const anchor = document.createElement('a');
        anchor.href = `/saas_portal/pages/company_details.php?id=${encodeURIComponent(text(companyId))}`;
        anchor.textContent = `Åpne ${text(name) || 'bedriften'}`;
        anchor.rel = 'noopener';
        return anchor;
    }

    function renderMatches(matches) {
        state.matches = Array.isArray(matches) ? matches : [];
        elements.matchList.replaceChildren();
        state.matches.forEach((match) => {
            const row = document.createElement('div');
            row.className = 'prospect-company-match';
            const info = document.createElement('div');
            const name = document.createElement('strong');
            name.textContent = text(match.name) || 'Uten bedriftsnavn';
            const subdomain = document.createElement('small');
            subdomain.textContent = [text(match.subdomain), text(match.id)].filter(Boolean).join(' · ');
            info.append(name, subdomain, companyLink(match.id, match.name));
            const choose = document.createElement('button');
            choose.type = 'button';
            choose.className = 'btn btn-outline-secondary btn-sm';
            choose.textContent = 'Klargjør import';
            choose.addEventListener('click', () => prepare(match.id));
            row.append(info, choose);
            elements.matchList.append(row);
        });
        elements.matches.hidden = false;
        elements.details.hidden = true;
        elements.submit.hidden = true;
        elements.retryImport.hidden = true;
    }

    function renderPrepared(body, preserveSubdomain = '') {
        state.prepared = body;
        state.mode = body.mode;
        elements.matches.hidden = true;
        elements.result.hidden = true;
        elements.result.replaceChildren();
        elements.retryImport.hidden = true;
        if (body.mode === 'existing' && Array.isArray(body.matches) && body.matches.length && !body.token) {
            el('prospectCompanyTitle').textContent = 'Bedriften finnes allerede';
            state.prepared = body;
            renderMatches(body.matches);
            return;
        }
        renderCompany(body.company, body.mode);
        if (preserveSubdomain) elements.subdomain.value = preserveSubdomain;
        if (Array.isArray(body.candidates)) {
            renderCandidates(body.candidates);
        } else {
            elements.candidatesSection.hidden = !previewWarning(body);
            elements.candidateList.replaceChildren();
            elements.warning.textContent = previewWarning(body);
            elements.warning.hidden = !elements.warning.textContent;
        }
        if (body.mode === 'import') {
            elements.submit.textContent = 'Importer valgte ansatte';
        } else {
            elements.submit.textContent = 'Opprett bedrift';
        }
        elements.submit.hidden = false;
        elements.submit.disabled = false;
    }

    function showPrepareFailure(error, preserveSubdomain = '') {
        elements.prepareErrorText.textContent = error.message || 'Kontroller tilkoblingen og prøv igjen.';
        elements.prepareError.hidden = false;
        elements.matches.hidden = true;
        elements.details.hidden = false;
        if (preserveSubdomain) elements.subdomain.value = preserveSubdomain;
        showAlert(error.status === 403 ? 'Tilgangen ble avvist. Kontroller at du fortsatt er logget inn.' : '');
    }

    async function prepare(companyId = null, preserveSubdomain = '') {
        if (!state.item || state.loading || state.submitting) return;
        const requestId = ++state.request;
        const subdomain = preserveSubdomain || elements.subdomain.value;
        state.retryCompanyId = companyId;
        showAlert('');
        elements.matches.hidden = true;
        elements.prepareError.hidden = true;
        setBusy(true);
        try {
            const payload = {
                action: 'prepare',
                prospect_id: state.item.id,
                version: state.item.version
            };
            if (companyId !== null && companyId !== undefined) payload.company_id = companyId;
            const body = await post(payload);
            if (requestId !== state.request) return;
            renderPrepared(body, subdomain);
        } catch (error) {
            if (requestId === state.request) showPrepareFailure(error, subdomain);
        } finally {
            if (requestId === state.request) setBusy(false);
        }
    }

    function selectedCandidates() {
        return [...elements.candidateList.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)')].map((input) => input.value);
    }

    function lockConfirmControls(locked) {
        if (locked) {
            const controls = [elements.subdomain, ...elements.candidateList.querySelectorAll('input[type="checkbox"]')];
            state.confirmControlState = controls.map((control) => ({ control, disabled: control.disabled }));
            controls.forEach((control) => { control.disabled = true; });
            return;
        }
        (state.confirmControlState || []).forEach(({ control, disabled }) => { control.disabled = disabled; });
        state.confirmControlState = null;
    }

    function validateSubdomain() {
        if (state.mode === 'import') return true;
        const value = elements.subdomain.value;
        const valid = /^[a-z0-9]{1,63}$/.test(value);
        elements.subdomain.setAttribute('aria-invalid', valid ? 'false' : 'true');
        elements.subdomainError.textContent = valid ? '' : 'Bruk 1–63 små bokstaver eller tall.';
        elements.subdomainError.hidden = valid;
        return valid;
    }

    function showResult(body) {
        const imp = body.import || { status: 'not_started', created: 0, message: '' };
        const company = body.company || {};
        state.resultCompanyId = company.id;
        elements.details.hidden = true;
        elements.matches.hidden = true;
        elements.submit.hidden = true;
        elements.result.hidden = false;
        elements.result.replaceChildren();
        const heading = document.createElement('strong');
        const setupWarnings = Array.isArray(body.setup_warnings) ? body.setup_warnings.filter(item => typeof item === 'string') : [];
        const partial = imp.status === 'failed' || imp.status === 'skipped' || imp.status === 'not_started' || setupWarnings.length > 0 || (body.dns && body.dns.success === false);
        elements.result.classList.toggle('is-partial', partial);
        if (body.company_created) {
            heading.textContent = partial ? 'Bedriften er opprettet – oppfølging gjenstår' : 'Bedriften er opprettet';
        } else {
            heading.textContent = partial ? 'Eksisterende bedrift – oppfølging gjenstår' : 'Import til eksisterende bedrift er fullført';
        }
        const summary = document.createElement('p');
        const created = Number(imp.created) || 0;
        summary.textContent = body.company_created
            ? `Ny bedrift opprettet. ${created} ansatte importert.`
            : `Eksisterende bedrift bekreftet. ${created} ansatte importert.`;
        elements.result.append(heading, summary);
        setupWarnings.forEach(text => {
            const message = document.createElement('p');
            message.textContent = text;
            elements.result.append(message);
        });
        if (imp.message) {
            const message = document.createElement('p');
            message.textContent = imp.message;
            elements.result.append(message);
        }
        if (body.dns?.success === false && body.dns.message) {
            const dnsMessage = document.createElement('p');
            dnsMessage.textContent = body.dns.message;
            elements.result.append(dnsMessage);
        }
        if (company.id) elements.result.append(companyLink(company.id, company.name));
        if (['failed', 'skipped', 'not_started'].includes(imp.status) && company.id) {
            state.retryCompanyId = company.id;
            elements.retryImport.hidden = false;
        } else {
            elements.retryImport.hidden = true;
        }
    }

    async function confirmConversion() {
        if (state.submitting || state.loading || !state.prepared || !validateSubdomain()) return;
        if (!state.prepared.token || !['new', 'import'].includes(state.mode)) {
            showAlert('Velg først en eksisterende virksomhet for å klargjøre import.');
            return;
        }
        const selected = selectedCandidates();
        const payload = {
            action: 'confirm',
            prospect_id: state.item.id,
            token: state.prepared.token,
            subdomain: elements.subdomain.value,
            selected
        };
        state.submitting = true;
        elements.submit.disabled = true;
        elements.retryImport.disabled = true;
        elements.button.disabled = true;
        lockConfirmControls(true);
        showAlert('');
        try {
            const body = await post(payload);
            if (body.existing === true && Array.isArray(body.matches)) {
                state.prepared = null;
                elements.details.hidden = true;
                elements.submit.hidden = true;
                elements.retryImport.hidden = true;
                showAlert('En bedrift med dette organisasjonsnummeret finnes allerede. Velg eksplisitt bedriften nedenfor for å klargjøre ansattimport.');
                renderMatches(body.matches);
                return;
            }
            showResult(body);
        } catch (error) {
            if (error.uncertain) {
                showAlert(`${error.message} Gjenta med samme bekreftelse for å hente resultatet uten å opprette bedriften på nytt.`);
            } else if (error.status === 409) {
                showAlert(`${error.message} Oppdateringen er ikke forkastet. Hent en ny bekreftelse før du prøver igjen.`);
                state.prepared = null;
                elements.submit.hidden = true;
                elements.prepareErrorText.textContent = 'Dataene må klargjøres på nytt. Nettadressen du skrev inn beholdes.';
                elements.prepareError.hidden = false;
            } else if (error.status === 422) {
                showAlert(error.message || 'Kontroller feltene og prøv igjen.');
                const subdomainError = error.errors.subdomain;
                if (subdomainError) {
                    elements.subdomainError.textContent = String(subdomainError);
                    elements.subdomainError.hidden = false;
                    elements.subdomain.setAttribute('aria-invalid', 'true');
                }
            } else if (error.status === 403) {
                showAlert('Tilgangen ble avvist. Kontroller innlogging og at prospektet fortsatt har status «interessert».');
            } else {
                showAlert(error.message || 'Opprettelsen kunne ikke fullføres. Skjemaet er beholdt.');
            }
        } finally {
            lockConfirmControls(false);
            state.submitting = false;
            elements.submit.disabled = !state.prepared;
            elements.retryImport.disabled = false;
            updateButton(state.selectionDetail);
        }
    }

    function openDialog() {
        if (!canConvert(state.selectionDetail)) return;
        clearView();
        dialog.showModal();
        prepare();
    }

    function closeDialog() {
        if (state.loading || state.submitting) return;
        dialog.close();
    }

    window.addEventListener('prospect:selection-state', (event) => {
        state.selectionDetail = event.detail || null;
        state.item = event.detail?.item || null;
        updateButton(event.detail);
    });
    elements.button.addEventListener('click', openDialog);
    dialog.querySelector('form').addEventListener('submit', (event) => event.preventDefault());
    el('prospectCompanyClose').addEventListener('click', closeDialog);
    el('prospectCompanyCancel').addEventListener('click', closeDialog);
    el('retryProspectCompanyPrepare').addEventListener('click', () => prepare(state.retryCompanyId, elements.subdomain.value));
    elements.submit.addEventListener('click', confirmConversion);
    elements.retryImport.addEventListener('click', async () => {
        if (!state.retryCompanyId || state.submitting || state.loading) return;
        elements.result.hidden = true;
        await prepare(state.retryCompanyId, elements.subdomain.value);
    });
    elements.subdomain.addEventListener('input', () => {
        if (elements.subdomain.getAttribute('aria-invalid') === 'true') validateSubdomain();
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) closeDialog();
    });
    dialog.addEventListener('cancel', (event) => {
        if (state.loading || state.submitting) event.preventDefault();
    });
    window.addEventListener('beforeunload', (event) => {
        if (state.submitting) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();
