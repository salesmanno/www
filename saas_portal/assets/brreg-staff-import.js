(function () {
    'use strict';

    const endpoint = '/saas_portal/api/import_brreg_staff.php';
    const openButton = document.getElementById('importBrregStaffButton');
    const modalElement = document.getElementById('brregStaffImportModal');
    if (!openButton || !modalElement) return;

    const statusElement = document.getElementById('brregStaffImportStatus');
    const candidatesElement = document.getElementById('brregStaffImportCandidates');
    const orgNumberElement = document.getElementById('brregStaffImportOrgNumber');
    const resultElement = document.getElementById('brregStaffImportResult');
    const retryButton = document.getElementById('brregStaffRetryButton');
    const confirmButton = document.getElementById('brregStaffConfirmButton');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    let previewToken = null;
    let candidates = [];
    let previewing = false;
    let confirming = false;
    let selection = new Map();

    function addText(parent, tagName, text, className) {
        const element = document.createElement(tagName);
        if (className) element.className = className;
        element.textContent = text == null ? '' : String(text);
        parent.appendChild(element);
        return element;
    }

    function setStatus(message, type) {
        statusElement.replaceChildren();
        if (!message) return;
        addText(statusElement, 'div', message, `alert alert-${type || 'info'} mb-0`);
    }

    function setLoading(isLoading) {
        previewing = isLoading;
        retryButton.disabled = isLoading || confirming;
        retryButton.setAttribute('aria-busy', String(isLoading));
        updateConfirmButton();
    }

    function updateConfirmButton() {
        const selectedCount = candidates.filter(function (candidate) {
            return candidate.status === 'missing' && selection.get(candidate.id) === true;
        }).length;
        const label = confirmButton.querySelector('.brreg-confirm-label');
        label.textContent = confirming ? 'Importerer…' : `Importer valgte (${selectedCount})`;
        confirmButton.disabled = confirming || previewing || !previewToken || selectedCount === 0;
        confirmButton.setAttribute('aria-busy', String(confirming));
    }

    function addSkeletons() {
        candidatesElement.replaceChildren();
        const wrapper = document.createElement('div');
        wrapper.setAttribute('aria-label', 'Laster rolleinnehavere');
        wrapper.setAttribute('aria-hidden', 'true');
        for (let i = 0; i < 3; i += 1) {
            const row = document.createElement('div');
            row.className = 'border rounded p-3 mb-2 placeholder-glow';
            addText(row, 'span', '', 'placeholder col-5 me-2');
            addText(row, 'span', '', 'placeholder col-3');
            addText(row, 'span', '', 'placeholder col-8 mt-3');
            wrapper.appendChild(row);
        }
        candidatesElement.appendChild(wrapper);
    }

    function formatCandidate(candidate) {
        const first = candidate.first_name == null ? '' : String(candidate.first_name);
        const last = candidate.last_name == null ? '' : String(candidate.last_name);
        const name = `${first} ${last}`.trim();
        return {
            id: String(candidate.id == null ? '' : candidate.id),
            name: name || 'Navn ikke oppgitt',
            title: candidate.job_title == null ? '' : String(candidate.job_title),
            email: candidate.email == null ? '' : String(candidate.email),
            phone: candidate.phone == null ? '' : String(candidate.phone),
            status: ['missing', 'existing', 'ambiguous', 'blocked'].includes(candidate.status)
                ? candidate.status
                : 'blocked',
            message: candidate.message == null ? '' : String(candidate.message)
        };
    }

    function renderCandidates() {
        candidatesElement.replaceChildren();
        if (candidates.length === 0) {
            addText(candidatesElement, 'p', 'Ingen rolleinnehavere ble funnet i forhåndsvisningen.', 'text-muted border rounded p-3 mb-0');
            return;
        }

        const list = document.createElement('div');
        list.className = 'd-grid gap-2';
        candidates.forEach(function (candidate, index) {
            const row = document.createElement('section');
            row.className = 'border rounded p-3';
            const topLine = document.createElement('div');
            topLine.className = 'd-flex flex-wrap align-items-start gap-2';

            if (candidate.status === 'missing') {
                const formCheck = document.createElement('div');
                formCheck.className = 'form-check me-1';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'form-check-input';
                checkbox.id = `brreg-staff-candidate-${index}`;
                checkbox.checked = selection.get(candidate.id) !== false;
                checkbox.addEventListener('change', function () {
                    selection.set(candidate.id, checkbox.checked);
                    updateConfirmButton();
                });
                const label = document.createElement('label');
                label.className = 'form-check-label visually-hidden';
                label.htmlFor = checkbox.id;
                label.textContent = `Velg ${candidate.name} for import`;
                formCheck.append(checkbox, label);
                topLine.appendChild(formCheck);
            } else {
                const spacer = document.createElement('span');
                spacer.className = 'me-1';
                spacer.setAttribute('aria-hidden', 'true');
                topLine.appendChild(spacer);
            }

            const identity = document.createElement('div');
            identity.className = 'flex-grow-1';
            addText(identity, 'h6', candidate.name, 'mb-1');
            if (candidate.title) addText(identity, 'div', candidate.title, 'small text-muted');
            topLine.appendChild(identity);

            const statusInfo = {
                missing: ['bg-success', 'Kan importeres'],
                existing: ['bg-secondary', 'Finnes allerede'],
                ambiguous: ['bg-warning text-dark', 'Må avklares'],
                blocked: ['bg-danger', 'Kan ikke importeres']
            }[candidate.status];
            addText(topLine, 'span', statusInfo[1], `badge ${statusInfo[0]}`);
            row.appendChild(topLine);

            const details = document.createElement('div');
            details.className = 'small text-muted mt-2 d-flex flex-wrap column-gap-4 row-gap-1';
            if (candidate.email) addText(details, 'span', `E-post: ${candidate.email}`);
            if (candidate.phone) addText(details, 'span', `Telefon: ${candidate.phone}`);
            if (details.childNodes.length) row.appendChild(details);

            let explanation = candidate.message;
            if (candidate.status === 'ambiguous') {
                explanation = [explanation, 'Avklar personen manuelt med «Ny ansatt»; denne oppføringen kan ikke importeres automatisk.']
                    .filter(Boolean).join(' ');
            }
            if (candidate.status === 'existing' && !explanation) explanation = 'Oppføringen finnes allerede og blir ikke endret.';
            if (candidate.status === 'blocked' && !explanation) explanation = 'Denne oppføringen kan ikke importeres.';
            if (explanation) {
                addText(row, 'p', explanation, 'small text-muted mt-2 mb-0');
            }
            list.appendChild(row);
        });
        candidatesElement.appendChild(list);
    }

    async function post(payload) {
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('Serveren returnerte et ugyldig svar. Prøv igjen.');
        }
        return { response: response, data: data };
    }

    async function loadPreview() {
        if (previewing || confirming) return;
        previewToken = null;
        candidates = [];
        selection = new Map();
        orgNumberElement.textContent = '';
        resultElement.replaceChildren();
        setStatus('Henter registrerte rolleinnehavere…', 'info');
        addSkeletons();
        setLoading(true);

        try {
            const result = await post({
                action: 'preview',
                company_id: companyId,
                csrf_token: brregStaffCsrf
            });
            if (!result.response.ok || !result.data || result.data.success !== true) {
                throw new Error((result.data && result.data.message) || 'Kunne ikke hente forhåndsvisningen.');
            }
            if (typeof result.data.token !== 'string' || !Array.isArray(result.data.candidates)) {
                throw new Error('Forhåndsvisningen hadde et uventet format. Prøv igjen.');
            }
            previewToken = result.data.token;
            orgNumberElement.textContent = result.data.org_number
                ? `Organisasjonsnummer ${String(result.data.org_number)}`
                : '';
            candidates = result.data.candidates.map(formatCandidate).filter(function (candidate) {
                return candidate.id !== '';
            });
            candidates.forEach(function (candidate) {
                if (candidate.status === 'missing') selection.set(candidate.id, true);
            });
            setStatus(result.data.message || 'Kontroller forslagene før du importerer.', 'success');
            renderCandidates();
        } catch (error) {
            candidatesElement.replaceChildren();
            setStatus(error.message || 'Kunne ikke hente forhåndsvisningen.', 'danger');
            const retry = document.createElement('p');
            retry.className = 'small text-muted mt-2 mb-0';
            retry.textContent = 'Du kan prøve på nytt med «Hent forhåndsvisning på nytt».';
            statusElement.appendChild(retry);
        } finally {
            setLoading(false);
        }
    }

    async function confirmImport() {
        if (confirming || previewing || !previewToken) return;
        const selected = candidates
            .filter(function (candidate) {
                return candidate.status === 'missing' && selection.get(candidate.id) === true;
            })
            .map(function (candidate) { return candidate.id; });
        if (selected.length === 0) return;

        confirming = true;
        resultElement.replaceChildren();
        setStatus('Oppretter valgte ansattoppføringer…', 'info');
        retryButton.disabled = true;
        updateConfirmButton();

        try {
            const result = await post({
                action: 'confirm',
                company_id: companyId,
                csrf_token: brregStaffCsrf,
                token: previewToken,
                selected: selected
            });
            if (result.response.status === 409) {
                previewToken = null;
                setStatus(
                    `${(result.data && result.data.message) || 'Forhåndsvisningen er utløpt eller endret.'} Hent en ny forhåndsvisning før du importerer.`,
                    'warning'
                );
                renderCandidates();
                return;
            }
            if (!result.response.ok || !result.data || result.data.success !== true) {
                throw new Error((result.data && result.data.message) || 'Importen kunne ikke fullføres.');
            }

            previewToken = null;
            setStatus(result.data.message || `${Number(result.data.created) || 0} ansattoppføringer ble importert.`, 'success');
            addText(resultElement, 'p', 'Listen over ansatte og valgene for administratorer er oppdatert.', 'small text-muted mb-0');
            if (typeof window.loadStaff === 'function') window.loadStaff();
            if (typeof window.loadAdmins === 'function') window.loadAdmins();
            renderCandidates();
        } catch (error) {
            setStatus(error.message || 'Importen kunne ikke fullføres.', 'danger');
        } finally {
            confirming = false;
            retryButton.disabled = previewing;
            updateConfirmButton();
        }
    }

    openButton.addEventListener('click', function () {
        modal.show();
        loadPreview();
    });
    retryButton.addEventListener('click', loadPreview);
    confirmButton.addEventListener('click', confirmImport);
})();