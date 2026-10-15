(() => {
    'use strict';

    const apiUrl = '/saas_portal/api/email_templates.php';
    const $ = (id) => document.getElementById(id);
    const el = {
        status: $('workshopStatus'), list: $('templateList'), count: $('templateCount'),
        search: $('templateSearch'), ownerFilter: $('templateOwnerFilter'), usageFilter: $('templateUsageFilter'),
        newButton: $('newTemplateButton'), refreshButton: $('refreshTemplatesButton'),
        editorTitle: $('editorTitle'), editorDescription: $('editorDescription'), ownerBadge: $('ownerBadge'),
        linkedCopies: $('linkedCopies'),
        form: $('templateForm'), name: $('templateName'), key: $('templateKey'), subject: $('templateSubject'),
        body: $('templateBody'), owner: $('templateOwner'), ownerHelp: $('ownerHelp'), active: $('templateActive'),
        variables: $('variableButtons'), save: $('saveTemplateButton'), previewButton: $('previewButton'),
        deleteTemplate: $('deleteTemplateButton'), removeTemplate: $('removeTemplateButton'), saveState: $('saveState'),
        previewSubject: $('previewSubject'), frame: $('emailPreviewFrame'), frameWrap: $('previewFrameWrap'),
        previewStatus: $('previewStatus'), previewDesktop: $('previewDesktop'), previewMobile: $('previewMobile'),
        companySearch: $('companySearch'), companyTargets: $('companyTargets'), libraryTarget: $('libraryTarget'),
        operation: $('operationSelect'), conflict: $('conflictSelect'), moveTargetWrap: $('moveTargetWrap'),
        moveTarget: $('moveTarget'), newKeyWrap: $('newKeyWrap'), newKey: $('newConflictKey'),
        operationHelp: $('operationHelp'), runOperation: $('runOperationButton'), dialog: $('operationDialog'),
        dialogTitle: $('operationDialogTitle'), planSummary: $('planSummary'), planEffects: $('planEffects'),
        planWarningsWrap: $('planWarningsWrap'), planWarnings: $('planWarnings'), planError: $('planError'),
        closeDialog: $('closePlanDialog'), cancelPlan: $('cancelPlanButton'), applyPlan: $('applyPlanButton')
    };

    const state = {
        templates: [], companies: [], variables: [], selected: null, detail: null, selectedCompanies: new Set(),
        baseline: null, isNew: false, loading: false, saving: false, previewing: false,
        planPayload: null, planHash: null, operationBusy: false, planActive: false,
        editorReady: false, listRequest: 0, detailRequest: 0, previewRequest: 0,
        initialOwner: new URLSearchParams(window.location.search).get('owner'),
        initialOwnerApplied: false, variableTarget: null,
        debounceTimer: null, companyTimer: null, listError: false
    };

    const safeText = (value) => value == null ? '' : String(value);
    const ownerLabel = (template) => template.company_id == null
        ? 'SaaS-bibliotek'
        : (template.company_name || 'Bedrift #' + template.company_id);

    function setStatus(message, type = 'info', retry = null) {
        el.status.replaceChildren();
        if (!message) return;
        const box = document.createElement('div');
        box.className = 'alert alert-' + type + ' py-2 d-flex flex-wrap align-items-center justify-content-between gap-2';
        const text = document.createElement('span');
        text.textContent = message;
        box.append(text);
        if (retry) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-' + (type === 'danger' ? 'danger' : 'secondary');
            button.textContent = 'Prøv igjen';
            button.addEventListener('click', retry, { once: true });
            box.append(button);
        }
        el.status.append(box);
    }

    async function request(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                ...(options.headers || {})
            }
        });
        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error('Serveren svarte med et ugyldig svar. Prøv igjen.');
        }
        if (!response.ok || data.success !== true) {
            const error = new Error(data.message || 'Forespørselen kunne ikke fullføres.');
            error.status = response.status;
            error.code = data.code || '';
            throw error;
        }
        return data;
    }

    async function post(payload) {
        return request(apiUrl, {
            method: 'POST',
            body: JSON.stringify({ csrf_token: window.emailWorkshopCsrf, ...payload })
        });
    }

    function filtersChanged() {
        window.clearTimeout(state.debounceTimer);
        state.debounceTimer = window.setTimeout(loadTemplates, 280);
    }

    function buildOwnerFilter(companies) {
        const current = el.ownerFilter.value;
        const ownerOptions = Array.from(el.ownerFilter.options).filter((option) => option.value === 'all' || option.value === 'library');
        el.ownerFilter.replaceChildren(...ownerOptions);
        companies.forEach((company) => {
            const option = document.createElement('option');
            option.value = String(company.id);
            option.textContent = safeText(company.name);
            el.ownerFilter.append(option);
        });
        el.ownerFilter.value = [...el.ownerFilter.options].some((option) => option.value === current) ? current : 'all';
        if (!state.initialOwnerApplied) {
            state.initialOwnerApplied = true;
            if (state.initialOwner && [...el.ownerFilter.options].some((option) => option.value === state.initialOwner)) {
                el.ownerFilter.value = state.initialOwner;
            }
        }

        const previousOwner = el.owner.value;
        const libraryOption = document.createElement('option');
        libraryOption.value = '';
        libraryOption.textContent = 'SaaS-bibliotek';
        el.owner.replaceChildren(libraryOption);
        const moveLibrary = document.createElement('option');
        moveLibrary.value = '';
        moveLibrary.textContent = 'SaaS-bibliotek';
        el.moveTarget.replaceChildren(moveLibrary);
        companies.forEach((company) => {
            const ownerOption = document.createElement('option');
            ownerOption.value = String(company.id);
            ownerOption.textContent = safeText(company.name);
            el.owner.append(ownerOption);
            const moveOption = document.createElement('option');
            moveOption.value = String(company.id);
            moveOption.textContent = safeText(company.name);
            el.moveTarget.append(moveOption);
        });
        if (state.isNew && [...el.owner.options].some((option) => option.value === previousOwner)) el.owner.value = previousOwner;
        renderCompanies();
    }

    function renderLoadingList() {
        el.list.setAttribute('aria-busy', 'true');
        el.list.replaceChildren();
        for (let i = 0; i < 3; i += 1) {
            const skeleton = document.createElement('div');
            skeleton.className = 'workshop-skeleton';
            skeleton.setAttribute('aria-hidden', 'true');
            el.list.append(skeleton);
        }
    }

    function renderTemplates() {
        el.list.setAttribute('aria-busy', 'false');
        el.list.replaceChildren();
        el.count.textContent = state.templates.length + (state.templates.length === 1 ? ' mal' : ' mal');
        if (!state.templates.length) {
            const empty = document.createElement('div');
            empty.className = 'workshop-empty';
            const strong = document.createElement('strong');
            strong.textContent = state.listError ? 'Maler kunne ikke lastes.' : 'Ingen maler funnet.';
            empty.append(strong);
            const note = document.createElement('div');
            note.className = 'mt-1';
            note.textContent = state.listError ? 'Biblioteket er utilgjengelig akkurat nå.' : 'Prøv et annet søk, eller opprett en ny mal.';
            empty.append(note);
            if (state.listError) {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'btn btn-sm btn-outline-primary mt-3';
                retry.textContent = 'Last inn på nytt';
                retry.addEventListener('click', loadTemplates);
                empty.append(retry);
            }
            el.list.append(empty);
            return;
        }
        state.templates.forEach((template) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'workshop-template-row';
            button.disabled = state.saving || state.operationBusy || state.planActive;
            button.setAttribute('aria-current', state.selected && String(state.selected.id) === String(template.id) && !state.isNew ? 'true' : 'false');
            button.addEventListener('click', () => selectTemplate(template.id));
            const title = document.createElement('strong');
            title.textContent = safeText(template.name);
            const key = document.createElement('small');
            key.textContent = safeText(template.template_key);
            const meta = document.createElement('span');
            meta.className = 'workshop-row-meta';
            const owner = document.createElement('span');
            owner.className = 'workshop-chip';
            owner.textContent = ownerLabel(template);
            meta.append(owner);
            if (template.source_template_id != null) {
                const source = document.createElement('span');
                source.className = 'workshop-chip source';
                source.textContent = 'Kopi fra #' + safeText(template.source_template_id);
                meta.append(source);
            }
            if (Number(template.usage_count) > 0) {
                const uses = document.createElement('span');
                uses.className = 'workshop-chip';
                uses.textContent = safeText(template.usage_count) + ' bruk';
                meta.append(uses);
            }
            button.append(title, key, meta);
            el.list.append(button);
        });
    }

    async function loadTemplates() {
        const requestId = ++state.listRequest;
        state.listError = false;
        renderLoadingList();
        const query = new URLSearchParams({
            action: 'list',
            q: el.search.value.trim(),
            owner: !state.initialOwnerApplied && state.initialOwner ? state.initialOwner : el.ownerFilter.value,
            usage: el.usageFilter.value.trim()
        });
        try {
            const data = await request(apiUrl + '?' + query.toString());
            if (requestId !== state.listRequest) return;
            state.companies = Array.isArray(data.companies) ? data.companies : [];
            buildOwnerFilter(state.companies);
            if (query.get('owner') !== el.ownerFilter.value) {
                loadTemplates();
                return;
            }
            state.templates = Array.isArray(data.templates) ? data.templates : [];
            state.variables = Array.isArray(data.variables) ? data.variables : [];
            renderTemplates();
            if (data.truncated) setStatus('Resultatet er begrenset. Legg til et søk for å snevre inn listen.', 'warning');
            else setStatus('');
            renderVariables();
        } catch (error) {
            if (requestId !== state.listRequest) return;
            state.listError = true;
            state.templates = [];
            renderTemplates();
            setStatus(error.message, 'danger', loadTemplates);
        }
    }

    function renderCompanies() {
        const query = el.companySearch.value.trim().toLocaleLowerCase('no');
        el.companyTargets.setAttribute('aria-busy', 'false');
        el.companyTargets.replaceChildren();
        const matches = state.companies.filter((company) => safeText(company.name).toLocaleLowerCase('no').includes(query));
        if (!matches.length) {
            const empty = document.createElement('p');
            empty.className = 'workshop-empty mb-0';
            empty.textContent = state.companies.length ? 'Ingen bedrifter samsvarer med søket.' : 'Bedriftslisten er ikke tilgjengelig.';
            el.companyTargets.append(empty);
            return;
        }
        matches.forEach((company) => {
            const label = document.createElement('label');
            label.className = 'workshop-target-row';
            const checkbox = document.createElement('input');
            checkbox.className = 'form-check-input company-target-check';
            checkbox.type = 'checkbox';
            checkbox.value = String(company.id);
            checkbox.checked = state.selectedCompanies.has(checkbox.value);
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) state.selectedCompanies.add(checkbox.value);
                else state.selectedCompanies.delete(checkbox.value);
            });
            checkbox.setAttribute('aria-label', 'Velg ' + safeText(company.name));
            const text = document.createElement('span');
            text.textContent = safeText(company.name);
            label.append(checkbox, text);
            el.companyTargets.append(label);
        });
    }

    function renderVariables() {
        el.variables.replaceChildren();
        if (!state.variables.length) {
            const note = document.createElement('span');
            note.className = 'small text-muted';
            note.textContent = 'Variabler lastes fra serveren.';
            el.variables.append(note);
            return;
        }
        const knownKey = state.variables.some((variable) => variable.template_keys?.includes(el.key.value));
        state.variables.filter((variable) => !knownKey || variable.template_keys?.includes(el.key.value)).forEach((variable) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-secondary';
            const key = safeText(variable.key);
            const label = safeText(variable.label || key);
            const example = variable.example ? ' · ' + safeText(variable.example) : '';
            button.textContent = '{{' + key + '}} · ' + label + example;
            button.title = label + (variable.example ? ' · eksempel: ' + safeText(variable.example) : '');
            button.setAttribute('aria-label', 'Sett inn ' + key + ', ' + label + (variable.example ? ', eksempel ' + safeText(variable.example) : ''));
            button.addEventListener('click', () => insertAtCursor('{{' + key + '}}'));
            el.variables.append(button);
        });
    }

    function renderLinkedCopies(copies = []) {
        el.linkedCopies.replaceChildren();
        if (state.isNew || !state.selected) {
            el.linkedCopies.hidden = true;
            return;
        }
        el.linkedCopies.hidden = false;
        const heading = document.createElement('strong');
        heading.textContent = 'Kopier knyttet til denne originalen · ' + copies.length;
        el.linkedCopies.append(heading);
        if (!copies.length) {
            const note = document.createElement('span');
            note.className = 'small text-muted';
            note.textContent = 'Ingen bedriftskopier er registrert.';
            el.linkedCopies.append(note);
            return;
        }
        const list = document.createElement('div');
        list.className = 'workshop-copy-list';
        copies.forEach((copy) => {
            const item = document.createElement('span');
            item.className = 'workshop-copy-item';
            const identity = document.createElement('span');
            identity.textContent = safeText(copy.company_name || 'Bedrift #' + copy.company_id) + ' · ' + safeText(copy.name);
            const key = document.createElement('code');
            key.textContent = safeText(copy.template_key);
            item.append(identity, key);
            list.append(item);
        });
        el.linkedCopies.append(list);
    }

    function insertAtCursor(value) {
        const target = state.variableTarget || el.body;
        if (target.disabled) return;
        const start = target.selectionStart ?? target.value.length;
        const end = target.selectionEnd ?? start;
        target.setRangeText(value, start, end, 'end');
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.focus();
    }

    function currentValues() {
        const values = {
            name: el.name.value,
            template_key: el.key.value,
            subject_template: el.subject.value,
            body_template: el.body.value,
            is_active: el.active.checked
        };
        if (state.isNew) values.company_id = el.owner.value || null;
        return values;
    }

    function isDirty() {
        if (!state.isNew && !state.baseline) return false;
        const value = currentValues();
        if (state.isNew) {
            return Boolean(value.name || value.template_key || value.subject_template || value.body_template
                || value.company_id !== null || value.is_active !== true);
        }
        return JSON.stringify(value) !== JSON.stringify(state.baseline);
    }

    function allowNavigation() {
        if (!isDirty()) return true;
        return window.confirm('Du har ulagrede endringer. Vil du forkaste dem og åpne en annen mal?');
    }

    function setEditorEnabled(enabled) {
        state.editorReady = enabled;
        refreshWorkflowControls();
    }

    function refreshWorkflowControls() {
        const locked = state.saving || state.operationBusy || state.planActive;
        const editable = state.editorReady && !locked;
        [el.name, el.subject, el.body, el.active].forEach((control) => { control.disabled = !editable; });
        el.key.disabled = !editable || !state.isNew;
        el.key.readOnly = !state.isNew;
        el.owner.disabled = !editable || !state.isNew;
        el.save.disabled = !editable;
        el.previewButton.disabled = !editable || state.previewing;
        el.newButton.disabled = locked;
        el.refreshButton.disabled = locked;
        el.list.querySelectorAll('.workshop-template-row').forEach((button) => { button.disabled = locked; });
        el.libraryTarget.disabled = locked || el.operation.value === 'move' || el.operation.value === 'delete';
        el.companyTargets.querySelectorAll('.company-target-check').forEach((checkbox) => {
            checkbox.disabled = locked || el.operation.value === 'move';
        });
        el.moveTarget.disabled = locked || !state.editorReady;
        el.newKey.disabled = locked || el.conflict.value !== 'rename';
        const operationDisabled = !editable || !state.selected || state.isNew || isDirty();
        el.deleteTemplate.disabled = operationDisabled;
        el.removeTemplate.disabled = operationDisabled;
        el.operation.disabled = !editable || !state.selected || state.isNew;
        el.conflict.disabled = !editable || !state.selected || state.isNew;
        el.runOperation.disabled = operationDisabled;
    }

    function fillEditor(template, isNew = false) {
        state.detail = template;
        state.selected = isNew ? null : template;
        state.isNew = isNew;
        const fields = ['name', 'template_key', 'subject_template', 'body_template'];
        fields.forEach((field) => { el[{ name: 'name', template_key: 'key', subject_template: 'subject', body_template: 'body' }[field]].value = safeText(template?.[field]); });
        el.active.checked = isNew ? true : Boolean(Number(template?.is_active));
        if (isNew) {
            el.editorTitle.textContent = 'Ny e-postmal';
            el.editorDescription.textContent = 'Velg hvor originalen skal eies. Senere eierskifte gjøres med «Flytt».';
            el.ownerBadge.hidden = true;
            el.owner.value = template?.company_id == null ? '' : String(template.company_id);
            el.ownerHelp.textContent = 'Velg SaaS-biblioteket eller en bedrift som opprinnelig eier.';
            renderLinkedCopies([]);
        } else {
            el.editorTitle.textContent = safeText(template.name) || 'E-postmal';
            el.editorDescription.textContent = 'Rediger originalen. Endringer i bedriftskopier krever en egen «Oppdater»-handling.';
            el.ownerBadge.hidden = false;
            el.ownerBadge.replaceChildren();
            const icon = document.createElement('i');
            icon.className = template.company_id == null ? 'bi bi-collection' : 'bi bi-building';
            icon.setAttribute('aria-hidden', 'true');
            const owner = document.createElement('span');
            owner.textContent = ownerLabel(template);
            el.ownerBadge.append(icon, owner);
            el.ownerHelp.textContent = 'Eierskap endres bare med «Flytt».';
            el.owner.value = template.company_id == null ? '' : String(template.company_id);
            renderLinkedCopies(Array.isArray(template.copies) ? template.copies : []);
        }
        state.baseline = isNew ? null : currentValues();
        renderVariables();
        el.saveState.textContent = '';
        setEditorEnabled(true);
        el.previewSubject.textContent = 'Forhåndsvisningen vises her etter behandling.';
        el.frame.removeAttribute('srcdoc');
        renderTemplates();
        updateOperationControls();
    }

    async function selectTemplate(id, force = false) {
        if (!force && (state.saving || state.operationBusy || state.planActive)) return;
        if (!allowNavigation()) return;
        state.previewRequest += 1;
        state.previewing = false;
        el.previewStatus.textContent = '';
        const requestId = ++state.detailRequest;
        setEditorEnabled(false);
        el.editorTitle.textContent = 'Laster mal …';
        el.editorDescription.textContent = 'Henter innhold og kopier fra serveren.';
        try {
            const data = await request(apiUrl + '?action=detail&id=' + encodeURIComponent(id));
            if (requestId !== state.detailRequest) return;
            fillEditor(data.template, false);
            state.detail.copies = Array.isArray(data.copies) ? data.copies : [];
            renderLinkedCopies(state.detail.copies);
        } catch (error) {
            if (requestId !== state.detailRequest) return;
            setEditorEnabled(false);
            el.editorTitle.textContent = 'Malen kunne ikke åpnes';
            el.editorDescription.textContent = error.message;
            setStatus(error.message, 'danger', () => selectTemplate(id));
        }
    }

    function createNew() {
        if (state.saving || state.operationBusy || state.planActive) return;
        if (!allowNavigation()) return;
        state.previewRequest += 1;
        state.previewing = false;
        el.previewStatus.textContent = '';
        ++state.detailRequest;
        fillEditor({ company_id: null, is_active: true }, true);
        el.name.focus();
    }

    async function saveTemplate(event) {
        if (event) event.preventDefault();
        if (state.saving || !state.selected && !state.isNew) return;
        if (!el.form.reportValidity()) return;
        state.saving = true;
        refreshWorkflowControls();
        el.saveState.textContent = 'Lagrer …';
        setStatus('');
        const template = {
            ...(state.isNew ? {} : { id: state.selected.id }),
            company_id: state.isNew ? (el.owner.value || null) : state.selected.company_id,
            ...currentValues()
        };
        const payload = { action: 'save', template };
        if (!state.isNew) payload.version = state.selected.version;
        try {
            const data = await post(payload);
            const savedTemplate = { ...data.template, copies: state.detail?.copies || [] };
            fillEditor(savedTemplate, false);
            await loadTemplates();
            setStatus('Malen er lagret.', 'success');
        } catch (error) {
            el.saveState.textContent = 'Lagring mislyktes';
            setStatus(error.message, 'danger', () => saveTemplate());
        } finally {
            state.saving = false;
            refreshWorkflowControls();
        }
    }

    async function previewTemplate() {
        if (state.previewing || !el.form.reportValidity()) return;
        const requestId = ++state.previewRequest;
        const editorId = state.detailRequest;
        state.previewing = true;
        refreshWorkflowControls();
        el.previewStatus.textContent = 'Behandler sikker forhåndsvisning …';
        try {
            const data = await post({
                action: 'preview',
                template: { subject_template: el.subject.value, body_template: el.body.value }
            });
            if (requestId !== state.previewRequest || editorId !== state.detailRequest) return;
            el.previewSubject.textContent = safeText(data.subject);
            // The preview endpoint is the only source allowed to populate this sandboxed document.
            el.frame.srcdoc = safeText(data.html);
            el.previewStatus.textContent = Array.isArray(data.variables) && data.variables.length
                ? 'Eksempelverdier brukt for: ' + data.variables.map(safeText).join(', ')
                : 'Forhåndsvisning klar med eksempelverdier.';
        } catch (error) {
            if (requestId !== state.previewRequest || editorId !== state.detailRequest) return;
            el.previewStatus.textContent = error.message;
            setStatus(error.message, 'danger', previewTemplate);
        } finally {
            if (requestId === state.previewRequest) {
                state.previewing = false;
                refreshWorkflowControls();
            }
        }
    }

    function selectedTargets() {
        const ids = [...state.selectedCompanies];
        const targets = ids;
        if (el.libraryTarget.checked) targets.unshift(null);
        return targets;
    }

    function selectedCopyDeleteIds() {
        if (!state.detail || !Array.isArray(state.detail.copies)) return [];
        const companies = state.selectedCompanies;
        return state.detail.copies
            .filter((copy) => companies.has(String(copy.company_id)) && String(copy.id) !== String(state.selected.id))
            .map((copy) => ({ id: copy.id, version: copy.version }));
    }

    function updateOperationControls() {
        const operation = el.operation.value;
        el.moveTargetWrap.hidden = operation !== 'move';
        el.newKeyWrap.hidden = el.conflict.value !== 'rename';
        el.libraryTarget.disabled = operation === 'move' || operation === 'delete';
        el.companyTargets.querySelectorAll('.company-target-check').forEach((checkbox) => {
            checkbox.disabled = operation === 'move';
        });
        if (operation === 'copy') el.operationHelp.textContent = 'Oppretter selvstendige kopier hos valgte eiere. Kilde og eksisterende kopier blir stående.';
        else if (operation === 'move') el.operationHelp.textContent = 'Flytter den valgte malen til nøyaktig én eier. Kilden fjernes først etter bekreftet serversuksess.';
        else if (operation === 'update') el.operationHelp.textContent = 'Oppdaterer bare valgte kopier som er koblet til denne originalen. Ingen fremtidig synkronisering aktiveres.';
        else el.operationHelp.textContent = 'Fjerner bare kopiene for valgte bedrifter. Originalmalen og e-postlogger beholdes.';
        el.runOperation.textContent = operation === 'delete' ? 'Gjennomgå fjerning' : 'Gjennomgå plan';
        refreshWorkflowControls();
    }

    function planRequestForOperation() {
        const operation = el.operation.value;
        if (!state.selected) throw new Error('Velg en lagret mal først.');
        const conflict = el.conflict.value;
        const common = {
            operation,
            id: state.selected.id,
            version: state.selected.version,
            targets: selectedTargets(),
            conflict,
            new_key: conflict === 'rename' ? el.newKey.value.trim() : ''
        };
        if (operation === 'move') {
            if (!el.moveTarget.value && state.selected.company_id == null) throw new Error('Velg én bedrift som mål for flyttingen.');
            common.targets = [el.moveTarget.value || null];
        } else if (operation === 'delete') {
            common.targets = [];
            common.delete_ids = selectedCopyDeleteIds();
            if (!common.delete_ids.length) throw new Error('Velg en bedrift som har en kopi av denne malen.');
        } else if (!common.targets.length) {
            throw new Error('Velg minst én mottaker.');
        }
        if (conflict === 'rename' && !common.new_key) throw new Error('Skriv inn en ny malnøkkel for konfliktløsing.');
        return common;
    }

    function renderPlan(data, operation) {
        el.planEffects.replaceChildren();
        el.planWarnings.replaceChildren();
        const effects = Array.isArray(data.effects) ? data.effects : [];
        effects.forEach((effect) => {
            const item = document.createElement('li');
            const action = document.createElement('strong');
            action.textContent = safeText(effect.action);
            const detail = document.createElement('span');
            detail.textContent = [safeText(effect.owner), safeText(effect.name), safeText(effect.template_key)].filter(Boolean).join(' · ');
            item.append(action, detail);
            el.planEffects.append(item);
        });
        if (!effects.length) {
            const empty = document.createElement('li');
            empty.textContent = 'Serveren fant ingen berørte maler.';
            el.planEffects.append(empty);
        }
        const warnings = Array.isArray(data.warnings) ? data.warnings : [];
        warnings.forEach((warning) => {
            const item = document.createElement('li');
            item.textContent = safeText(warning);
            el.planWarnings.append(item);
        });
        el.planWarningsWrap.hidden = warnings.length === 0;
        const labels = { copy: 'Kopier', move: 'Flytt', update: 'Oppdater kopier', delete: 'Fjern kopier' };
        el.dialogTitle.textContent = 'Gjennomgå: ' + (labels[operation] || operation);
        el.planSummary.textContent = 'Kontroller den nøyaktige serverberegnede virkningen før du bekrefter. Ingen endring er utført ennå.';
        el.planError.hidden = true;
        el.applyPlan.disabled = false;
        el.applyPlan.textContent = 'Bekreft og utfør';
        state.planActive = true;
        refreshWorkflowControls();
    }

    async function createPlan(overridePayload = null) {
        if (state.operationBusy) return;
        let payload;
        try {
            payload = overridePayload || planRequestForOperation();
        } catch (error) {
            setStatus(error.message, 'warning');
            return;
        }
        state.planPayload = payload;
        state.planHash = null;
        state.planActive = false;
        state.operationBusy = true;
        refreshWorkflowControls();
        el.applyPlan.disabled = true;
        el.planSummary.textContent = 'Beregner berørte maler …';
        el.planEffects.replaceChildren();
        el.planWarnings.replaceChildren();
        el.planWarningsWrap.hidden = true;
        el.planError.hidden = true;
        el.dialogTitle.textContent = 'Beregner plan';
        if (!el.dialog.open) el.dialog.showModal();
        try {
            const data = await post({ action: 'plan', ...payload });
            if (!data.plan_hash) throw new Error('Serveren returnerte ingen bekreftelsesnøkkel. Ingen endring er utført.');
            state.planHash = data.plan_hash;
            renderPlan(data, payload.operation);
        } catch (error) {
            el.planSummary.textContent = 'Planen kunne ikke beregnes. Ingen endring er utført.';
            el.planError.textContent = error.message;
            el.planError.hidden = false;
            el.applyPlan.disabled = true;
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn btn-sm btn-outline-danger ms-2';
            retry.textContent = 'Prøv plan på nytt';
            retry.addEventListener('click', () => createPlan(state.planPayload));
            el.planError.append(retry);
        } finally {
            state.operationBusy = false;
            updateOperationControls();
        }
    }

    async function applyPlan() {
        if (state.operationBusy || !state.planPayload || !state.planHash) return;
        const appliedPayload = state.planPayload;
        state.operationBusy = true;
        refreshWorkflowControls();
        el.applyPlan.disabled = true;
        el.applyPlan.textContent = 'Utfører …';
        el.planError.hidden = true;
        try {
            const data = await post({
                action: 'apply',
                ...appliedPayload,
                confirmed: true,
                plan_hash: state.planHash
            });
            const successMessage = safeText(data.message) || 'Handlingen er fullført.';
            const removedSelected = appliedPayload.operation === 'move'
                || (appliedPayload.operation === 'delete'
                    && appliedPayload.delete_ids.some((item) => String(item.id) === String(state.selected?.id)));
            const selectedId = state.selected?.id;
            state.planActive = false;
            state.planHash = null;
            state.planPayload = null;
            el.dialog.close();
            await loadTemplates();
            if (removedSelected) {
                state.selected = null;
                state.detail = null;
                state.baseline = null;
                state.isNew = false;
                el.editorTitle.textContent = 'Velg en mal';
                el.editorDescription.textContent = 'Åpne en eksisterende mal eller start med en ny.';
                el.ownerBadge.hidden = true;
                setEditorEnabled(false);
                renderTemplates();
            } else if (selectedId) {
                await selectTemplate(selectedId, true);
            }
            setStatus(successMessage, 'success');
        } catch (error) {
            if (error.status === 409) {
                const stalePayload = appliedPayload;
                state.planHash = null;
                state.planPayload = null;
                state.planActive = true;
                el.planError.replaceChildren();
                const explanation = document.createElement('span');
                explanation.textContent = error.message + ' Planen er foreldet og kan ikke brukes på nytt.';
                el.planError.append(explanation);
                const regenerate = document.createElement('button');
                regenerate.type = 'button';
                regenerate.className = 'btn btn-sm btn-outline-danger ms-2';
                regenerate.textContent = 'Hent siste versjon og lag ny plan';
                regenerate.addEventListener('click', async () => {
                    if (!state.selected || state.operationBusy) return;
                    state.operationBusy = true;
                    refreshWorkflowControls();
                    regenerate.disabled = true;
                    try {
                        const latest = await request(apiUrl + '?action=detail&id=' + encodeURIComponent(state.selected.id));
                        const copies = Array.isArray(latest.copies) ? latest.copies : [];
                        const latestTemplate = { ...latest.template, copies };
                        const versionById = new Map([[String(latest.template.id), latest.template.version]]);
                        copies.forEach((copy) => versionById.set(String(copy.id), copy.version));
                        const updatedDeleteIds = (stalePayload.delete_ids || []).map((item) => {
                            const version = versionById.get(String(item.id));
                            if (version == null) throw new Error('En valgt kopi finnes ikke lenger. Velg mottakere på nytt.');
                            return { id: item.id, version };
                        });
                        fillEditor(latestTemplate, false);
                        state.detail.copies = copies;
                        renderLinkedCopies(copies);
                        const nextPayload = {
                            ...stalePayload,
                            id: latest.template.id,
                            version: latest.template.version,
                            delete_ids: updatedDeleteIds
                        };
                        state.operationBusy = false;
                        await createPlan(nextPayload);
                    } catch (refreshError) {
                        el.planError.textContent = refreshError.message + ' Ingen endring er bekreftet.';
                        el.planError.hidden = false;
                        regenerate.disabled = false;
                        state.operationBusy = false;
                        refreshWorkflowControls();
                    }
                });
                el.planError.append(regenerate);
                el.planError.hidden = false;
                el.applyPlan.disabled = true;
                el.applyPlan.textContent = 'Plan foreldet';
            } else {
                el.planError.textContent = error.message + ' Ingen suksess er bekreftet. Kontroller status før du prøver igjen.';
                el.planError.hidden = false;
                el.applyPlan.disabled = false;
                el.applyPlan.textContent = 'Prøv bekreftelse på nytt';
            }
        } finally {
            state.operationBusy = false;
            updateOperationControls();
        }
    }

    async function planDeleteSelected() {
        if (!state.selected || state.isNew || state.operationBusy) return;
        state.planPayload = {
            operation: 'delete',
            id: state.selected.id,
            version: state.selected.version,
            targets: [],
            conflict: el.conflict.value,
            new_key: '',
            delete_ids: [{ id: state.selected.id, version: state.selected.version }]
        };
        el.operation.value = 'delete';
        await createPlan(state.planPayload);
    }

    function setPreviewSize(mobile) {
        el.frameWrap.classList.toggle('is-mobile', mobile);
        el.frameWrap.classList.toggle('is-desktop', !mobile);
        el.previewMobile.classList.toggle('active', mobile);
        el.previewDesktop.classList.toggle('active', !mobile);
        el.previewMobile.setAttribute('aria-pressed', String(mobile));
        el.previewDesktop.setAttribute('aria-pressed', String(!mobile));
    }

    el.search.addEventListener('input', filtersChanged);
    el.usageFilter.addEventListener('input', filtersChanged);
    el.ownerFilter.addEventListener('change', filtersChanged);
    el.newButton.addEventListener('click', createNew);
    el.refreshButton.addEventListener('click', loadTemplates);
    el.form.addEventListener('submit', saveTemplate);
    el.previewButton.addEventListener('click', previewTemplate);
    el.subject.addEventListener('focus', () => { state.variableTarget = el.subject; });
    el.body.addEventListener('focus', () => { state.variableTarget = el.body; });
    el.companySearch.addEventListener('input', () => {
        window.clearTimeout(state.companyTimer);
        state.companyTimer = window.setTimeout(renderCompanies, 140);
    });
    el.operation.addEventListener('change', updateOperationControls);
    el.conflict.addEventListener('change', updateOperationControls);
    el.runOperation.addEventListener('click', () => createPlan());
    el.applyPlan.addEventListener('click', applyPlan);
    el.deleteTemplate.addEventListener('click', planDeleteSelected);
    el.removeTemplate.addEventListener('click', planDeleteSelected);
    el.closeDialog.addEventListener('click', () => el.dialog.close());
    el.cancelPlan.addEventListener('click', () => el.dialog.close());
    el.dialog.addEventListener('click', (event) => {
        if (event.target === el.dialog) el.dialog.close();
    });
    el.dialog.addEventListener('close', () => {
        // A queued close event can arrive after a fresh plan has reopened the dialog.
        if (el.dialog.open || state.operationBusy) return;
        state.planActive = false;
        state.planPayload = null;
        state.planHash = null;
        refreshWorkflowControls();
    });
    el.previewDesktop.addEventListener('click', () => setPreviewSize(false));
    el.previewMobile.addEventListener('click', () => setPreviewSize(true));
    [el.name, el.key, el.subject, el.body, el.active, el.owner].forEach((control) => {
        control.addEventListener('input', () => {
            if (!state.saving) el.saveState.textContent = isDirty() ? 'Ulagrede endringer' : '';
            updateOperationControls();
        });
        control.addEventListener('change', () => {
            if (!state.saving) el.saveState.textContent = isDirty() ? 'Ulagrede endringer' : '';
            updateOperationControls();
        });
    });
    el.body.addEventListener('keydown', (event) => {
        if (event.key === 'Tab' && !el.body.disabled) {
            event.preventDefault();
            insertAtCursor('  ');
        }
    });
    el.key.addEventListener('input', renderVariables);
    window.addEventListener('beforeunload', (event) => {
        if (isDirty()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    renderCompanies();
    loadTemplates();
})();
