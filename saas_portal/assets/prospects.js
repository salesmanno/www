(() => {
    'use strict';
    const restricted = window.prospectsViewOnly === true;

    const API_URL = '/saas_portal/api/prospects.php';
    const fields = {
        org_nr: document.getElementById('orgNr'),
        company_name: document.getElementById('companyName'),
        website: document.getElementById('website'),
        contact_person: document.getElementById('contactPerson'),
        contact_email: document.getElementById('contactEmail'),
        contact_phone: document.getElementById('contactPhone'),
        interest_score: document.getElementById('interestScore'),
        interest_tags: document.getElementById('interestTags'),
        status: document.getElementById('prospectStatus'),
        source: document.getElementById('prospectSource'),
        last_contacted_at: document.getElementById('lastContactedAt'),
        notes: document.getElementById('prospectNotes')
    };
    const listEl = document.getElementById('prospectsList');
    const form = document.getElementById('prospectForm');
    const state = {
        q: '',
        status: '',
        sort: 'updated',
        page: 1,
        pages: 1,
        total: 0,
        items: [],
        statuses: [],
        selectedId: null,
        selectedItem: null,
        isNew: false,
        dirty: false,
        saving: false,
        listRequest: 0,
        detailRequest: 0,
        savedSnapshot: null,
        currentSnapshot: null,
        searchTimer: null
    };

    const el = (id) => document.getElementById(id);
    const text = (value) => value === null || value === undefined ? '' : String(value);
    const setStatusText = (message) => { el('prospectSaveState').textContent = message || ''; };

    function make(tag, className, content) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (content !== undefined) node.textContent = content;
        return node;
    }

    function escapeSafeUrl(value, allowed) {
        const raw = text(value).trim();
        if (!raw) return null;
        let candidate = raw;
        if (allowed.includes('https:') && !/^[a-z][a-z0-9+.-]*:/i.test(candidate)) candidate = `https://${candidate}`;
        try {
            const parsed = new URL(candidate);
            return allowed.includes(parsed.protocol) ? parsed.href : null;
        } catch (_) {
            return null;
        }
    }

    function setAlert(message, visible = true) {
        el('prospectsGlobalAlertText').textContent = message || '';
        el('prospectsGlobalAlert').hidden = !visible;
    }

    function setFieldError(name, message) {
        const input = fields[name];
        if (!input) return;
        const errorId = input.getAttribute('aria-describedby').split(' ').find(id => id.endsWith('Error'));
        const error = el(errorId);
        input.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (error) {
            error.textContent = message || '';
            error.hidden = !message;
        }
    }

    function clearFieldErrors() {
        Object.keys(fields).forEach((key) => setFieldError(key, ''));
        el('prospectFormAlert').hidden = true;
    }

    function configureRestrictedView() {
        if (restricted) ['newProspect','createProspectCompany','createProspectCompanyHelp'].forEach(id => {
            el(id).hidden = true;
        });
        const toggle=el('prospectInterestToggle');
        toggle.addEventListener('change',()=>{
            const original=state.selectedItem?.status ?? 'ny';
            const originallyInterested=text(original).trim().toLowerCase()==='interessert';
            fields.status.value=toggle.checked===originallyInterested ? text(original) : toggle.checked?'interessert':'ikke_interessert';
            el('prospectInterestState').textContent=toggle.checked?'Interessert':'Ikke interessert';
            updateDirtyState();
        });
    }

    function configureEditorMode(newRecord) {
        form.classList.toggle('is-existing',!newRecord);
        Object.entries(fields).forEach(([name,input]) => { input.readOnly=!newRecord && name!=='notes'; });
        fields.last_contacted_at.type=newRecord?'datetime-local':'text';
        el('interestTagsHelp').hidden=!newRecord;
        el('interestScoreHelp').hidden=!newRecord;
    }

    function updateDirtyState() {
        if (!form || el('editorContent').hidden) return;
        state.currentSnapshot = snapshotForm();
        state.dirty = state.savedSnapshot !== null && state.currentSnapshot !== state.savedSnapshot;
        el('saveProspect').disabled = state.saving || !state.dirty;
        if (!state.saving && state.dirty) setStatusText('Ulagrede endringer');
        else if (!state.saving && !state.dirty) setStatusText(state.selectedItem ? 'Alle endringer lagret' : '');
        window.dispatchEvent(new CustomEvent('prospect:selection-state', { detail: {
            item: state.selectedItem,
            status: state.selectedItem ? text(state.selectedItem.status).trim().toLowerCase() : '',
            saved: !!state.selectedItem && !!state.selectedItem.id,
            dirty: state.dirty,
            saving: state.saving
        } }));
    }

    function snapshotForm() {
        return JSON.stringify(Object.fromEntries(Object.entries(fields).map(([key, input]) => [key, input.value])));
    }

    function allowLeave() {
        if (!state.dirty || state.saving) return !state.saving;
        return window.confirm('Du har ulagrede endringer. Vil du forkaste dem?');
    }

    function localDateInput(value) {
        const raw = text(value).trim();
        if (!raw) return '';
        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
        if (!match) return '';
        const [year, month, day, hour, minute, second] = match.slice(1).map(value => Number(value || 0));
        const date = new Date(Date.UTC(year, month - 1, day, hour, minute, second));
        if (year < 1000 || date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 ||
            date.getUTCDate() !== day || date.getUTCHours() !== hour || date.getUTCMinutes() !== minute ||
            date.getUTCSeconds() !== second) return '';
        return `${match[1]}-${match[2]}-${match[3]}T${match[4]}:${match[5]}:${match[6] || '00'}`;
    }

    function dateForApi(value) {
        if (!value) return null;
        const match = value.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/);
        return match ? `${match[1]} ${match[2]}:${match[3]}:${match[4] || '00'}` : null;
    }

    function readFieldData() {
        const data = {};
        Object.entries(fields).forEach(([key, input]) => {
            const value = input.value;
            if (key === 'interest_score') data[key] = value.trim() === '' ? null : Number(value);
            else if (key === 'interest_tags') data[key] = value.trim() === '' ? null : value;
            else if (key === 'last_contacted_at') data[key] = dateForApi(value);
            else data[key] = value.trim() === '' ? null : value;
        });
        return data;
    }

    function validTagsJson() {
        const raw = fields.interest_tags.value.trim();
        if (!raw) return true;
        try { JSON.parse(raw); return true; } catch (_) { return false; }
    }

    function populateForm(item, newRecord = false) {
        const source = item || {};
        configureEditorMode(newRecord);
        fields.org_nr.value = text(source.org_nr);
        fields.company_name.value = text(source.company_name);
        fields.website.value = text(source.website);
        fields.contact_person.value = text(source.contact_person);
        fields.contact_email.value = text(source.contact_email);
        fields.contact_phone.value = text(source.contact_phone);
        fields.interest_score.value = source.interest_score === null || source.interest_score === undefined ? '' : text(source.interest_score);
        fields.interest_tags.value = source.interest_tags === null || source.interest_tags === undefined ? '' : typeof source.interest_tags === 'string' ? source.interest_tags : JSON.stringify(source.interest_tags);
        fields.source.value = text(source.source);
        fields.last_contacted_at.value = newRecord ? localDateInput(source.last_contacted_at) : text(source.last_contacted_at);
        fields.notes.value = text(source.notes);
        fields.status.value = newRecord && source.status == null ? 'ny' : text(source.status);
        el('prospectInterestToggle').checked=text(source.status).trim().toLowerCase()==='interessert';
        el('prospectInterestState').textContent=el('prospectInterestToggle').checked?'Interessert':'Ikke interessert';
        el('interestTagsWarning').hidden=true;
        clearFieldErrors();
        updateContactLinks(source);
        state.selectedItem = item || null;
        state.selectedId = item ? text(item.id) : null;
        state.isNew = newRecord;
        state.savedSnapshot = snapshotForm();
        state.currentSnapshot = state.savedSnapshot;
        state.dirty = false;
        el('editorEmpty').hidden = true;
        el('editorContent').hidden = false;
        el('prospectEditorTitle').textContent = newRecord ? 'Nytt prospekt' : text(source.company_name) || 'Prospekt';
        el('prospectEditorDescription').textContent = newRecord ? 'Registrer en norsk virksomhet for oppfølging.' : `Organisasjonsnummer ${text(source.org_nr) || 'ikke oppgitt'}`;
        el('saveProspect').disabled = true;
        setStatusText(newRecord ? 'Ny registrering' : 'Alle endringer lagret');
        renderList();
        window.dispatchEvent(new CustomEvent('prospect:selection-state', { detail: {
            item: state.selectedItem,
            status: state.selectedItem ? text(state.selectedItem.status).trim().toLowerCase() : '',
            saved: !!state.selectedItem && !!state.selectedItem.id,
            dirty: state.dirty,
            saving: state.saving
        } }));
    }

    function updateContactLinks(item) {
        const container = el('prospectContactLinks');
        container.replaceChildren();
        const links = [
            { value: item.website, allowed: ['http:', 'https:'], prefix: '', label: 'Nettsted' },
            { value: item.contact_email, allowed: ['mailto:'], prefix: 'mailto:', label: 'E-post' },
            { value: item.contact_phone, allowed: ['tel:'], prefix: 'tel:', label: 'Telefon' }
        ];
        links.forEach((entry) => {
            const raw = text(entry.value).trim();
            if (!raw) return;
            let href = null;
            if (entry.prefix) {
                const candidate = `${entry.prefix}${raw}`;
                try {
                    const parsed = new URL(candidate);
                    if (entry.allowed.includes(parsed.protocol)) href = parsed.href;
                } catch (_) { href = null; }
            } else href = escapeSafeUrl(raw, entry.allowed);
            if (!href) {
                const span = make('span', '', `${entry.label}: ${raw} (ugyldig lenke)`);
                container.append(span);
                return;
            }
            const anchor = make('a', '', raw);
            anchor.href = href;
            anchor.rel = 'noopener noreferrer';
            if (href.startsWith('http:') || href.startsWith('https:')) anchor.target = '_blank';
            container.append(anchor);
        });
    }

    function renderStatusFilter() {
        const select = el('prospectStatusFilter');
        const selected = state.status;
        select.replaceChildren();
        const all = make('option', '', 'Alle statuser');
        all.value = '';
        select.append(all);
        state.statuses.forEach((status) => {
            const option = make('option', '', status);
            option.value = status;
            select.append(option);
        });
        if (selected && !state.statuses.includes(selected)) {
            const option = make('option', '', selected);
            option.value = selected;
            select.append(option);
        }
        select.value = selected;
    }

    function renderList() {
        listEl.replaceChildren();
        listEl.setAttribute('aria-busy', 'false');
        el('prospectCount').textContent = `${state.total} totalt`;
        el('pageInfo').textContent = `Side ${state.page} av ${Math.max(1, state.pages)}`;
        el('previousPage').disabled = state.page <= 1;
        el('nextPage').disabled = state.page >= state.pages;
        if (!state.items.length) {
            const empty = make('div', 'prospects-list-empty');
            empty.append(make('i', 'bi bi-search', ''));
            const hasFilters = !!(state.q || state.status);
            empty.append(make('strong', '', hasFilters ? 'Ingen treff' : 'Ingen prospekter ennå'));
            empty.append(make('span', '', hasFilters ? 'Prøv et annet søk eller fjern filteret.' : 'Opprett et prospekt for å starte oppfølgingen.'));
            listEl.append(empty);
            return;
        }
        state.items.forEach((item) => {
            const button = make('button', `prospect-row${text(item.id) === state.selectedId ? ' is-selected' : ''}`);
            button.type = 'button';
            button.setAttribute('aria-current', text(item.id) === state.selectedId ? 'true' : 'false');
            button.addEventListener('click', () => openItem(text(item.id)));
            const top = make('div', 'prospect-row-top');
            const identity = make('div');
            identity.append(make('span', 'prospect-row-name', text(item.company_name) || 'Uten navn'));
            identity.append(make('span', 'prospect-row-org', text(item.org_nr)));
            top.append(identity);
            const status = make('span', 'prospect-status', text(item.status) || 'Uten status');
            status.dataset.status = text(item.status).toLowerCase();
            top.append(status);
            button.append(top);
            const meta = make('div', 'prospect-row-meta');
            if (item.contact_person) meta.append(make('span', '', text(item.contact_person)));
            if (item.contact_email) meta.append(make('span', '', text(item.contact_email)));
            if (item.interest_score !== null && item.interest_score !== undefined && item.interest_score !== '') meta.append(make('span', '', `Score ${text(item.interest_score)}`));
            button.append(meta);
            listEl.append(button);
        });
    }

    function renderLoadingList() {
        listEl.replaceChildren();
        listEl.setAttribute('aria-busy', 'true');
        const loading = make('div', 'prospects-loading');
        loading.setAttribute('aria-label', 'Laster prospekter');
        for (let i = 0; i < 4; i += 1) loading.append(make('div', 'prospect-skeleton'));
        listEl.append(loading);
    }

    async function loadList() {
        const requestId = ++state.listRequest;
        const params = new URLSearchParams({ q: state.q, status: state.status, page: String(state.page), sort: state.sort });
        setAlert('', false);
        renderLoadingList();
        try {
            const response = await fetch(`${API_URL}?${params.toString()}`, { credentials: 'include', cache: 'no-store', headers: { Accept: 'application/json' } });
            const body = await response.json();
            if (requestId !== state.listRequest) return;
            if (!response.ok || body.success !== true) throw new Error(body.message || 'Prospektlisten kunne ikke lastes.');
            state.items = Array.isArray(body.items) ? body.items : [];
            state.total = Number(body.total) || 0;
            state.page = Number(body.page) || state.page;
            state.pages = Math.max(1, Number(body.pages) || 1);
            if (Array.isArray(body.statuses)) state.statuses = body.statuses.map((value) => String(value));
            renderStatusFilter();
            renderList();
        } catch (error) {
            if (requestId !== state.listRequest) return;
            listEl.replaceChildren();
            listEl.setAttribute('aria-busy', 'false');
            const failure = make('div', 'prospects-list-state');
            failure.append(make('i', 'bi bi-exclamation-circle', ''));
            failure.append(make('strong', '', 'Prospektlisten kunne ikke lastes'));
            failure.append(make('div', '', error.message || 'Kontroller tilkoblingen og prøv igjen.'));
            const retry = make('button', 'btn btn-sm btn-outline-secondary mt-2', 'Prøv igjen');
            retry.type = 'button';
            retry.addEventListener('click', loadList);
            failure.append(retry);
            listEl.append(failure);
            setAlert(error.message || 'Prospektlisten kunne ikke lastes.', false);
        }
    }

    async function openItem(id) {
        if (state.selectedId === id && !state.isNew && !state.dirty) return;
        if (!allowLeave()) return;
        const requestId = ++state.detailRequest;
        state.saving = false;
        el('editorEmpty').hidden = false;
        el('editorContent').hidden = true;
        el('editorEmpty').replaceChildren(make('i', 'bi bi-arrow-repeat', ''), make('strong', '', 'Henter prospekt'), make('span', '', 'Opplysningene lastes inn.'));
        try {
            const response = await fetch(`${API_URL}?id=${encodeURIComponent(id)}`, { credentials: 'include', cache: 'no-store', headers: { Accept: 'application/json' } });
            const body = await response.json();
            if (requestId !== state.detailRequest) return;
            if (!response.ok || body.success !== true || !body.item) throw new Error(body.message || 'Prospektet kunne ikke åpnes.');
            populateForm(body.item);
            if (window.matchMedia('(max-width: 767px)').matches) el('prospectEditor').scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            if (requestId !== state.detailRequest) return;
            el('editorEmpty').replaceChildren(make('i', 'bi bi-exclamation-circle', ''), make('strong', '', 'Prospektet kunne ikke åpnes'), make('span', '', error.message || 'Prøv igjen.'));
            const retry = make('button', 'btn btn-sm btn-outline-secondary mt-3', 'Prøv igjen');
            retry.type = 'button';
            retry.addEventListener('click', () => openItem(id));
            el('editorEmpty').append(retry);
            el('editorEmpty').hidden = false;
            el('editorContent').hidden = true;
        }
    }

    function startNew() {
        if (restricted) return;
        if (!allowLeave()) return;
        ++state.detailRequest;
        populateForm({ status: 'ny', interest_tags: null }, true);
        fields.org_nr.focus();
        if (window.matchMedia('(max-width: 767px)').matches) el('prospectEditor').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function validateForm() {
        clearFieldErrors();
        if (!state.isNew) {
            if (new TextEncoder().encode(fields.notes.value).length>65535) {
                setFieldError('notes','Notatet er for langt. Maksimalt 65535 byte.');return false;
            }
            return true;
        }
        let valid = true;
        const org = fields.org_nr.value.trim();
        if (!/^\d{9}$/.test(org)) { setFieldError('org_nr', 'Organisasjonsnummer må bestå av 9 siffer.'); valid = false; }
        if (!fields.company_name.value.trim()) { setFieldError('company_name', 'Bedriftsnavn er obligatorisk.'); valid = false; }
        if (fields.interest_score.value.trim() !== '') {
            const score = Number(fields.interest_score.value);
            if (!Number.isInteger(score) || score < -32768 || score > 32767) { setFieldError('interest_score', 'Oppgi et heltall mellom -32768 og 32767.'); valid = false; }
        }
        if (!validTagsJson()) { setFieldError('interest_tags', 'Verdien må være gyldig JSON, eller stå tom.'); valid = false; }
        const website = fields.website.value.trim();
        if (website && !escapeSafeUrl(website, ['http:', 'https:'])) { setFieldError('website', 'Bruk en gyldig nettadresse med http eller https.'); valid = false; }
        const email = fields.contact_email.value.trim();
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setFieldError('contact_email', 'Oppgi en gyldig e-postadresse.'); valid = false; }
        if (!valid) el('prospectFormAlert').textContent = 'Kontroller feltene som er markert.';
        el('prospectFormAlert').hidden = valid;
        return valid;
    }

    function applyServerErrors(errors) {
        let hasMapped = false;
        Object.entries(errors || {}).forEach(([key, message]) => {
            if (fields[key]) { setFieldError(key, String(message)); hasMapped = true; }
        });
        return hasMapped;
    }

    async function saveProspect(event) {
        event.preventDefault();
        if (state.saving || !validateForm()) return;
        const values = !state.isNew ? {notes:fields.notes.value,status:el('prospectInterestToggle').checked?'interessert':'ikke_interessert'} : readFieldData();
        const payload = { csrf_token: window.prospectsCsrf, action: 'save', ...values };
        if (!state.isNew && state.selectedItem) {
            payload.id = state.selectedItem.id;
            payload.version = state.selectedItem.version;
        }
        state.saving = true;
        Object.values(fields).forEach(input => { input.disabled = true; });
        el('prospectInterestToggle').disabled = true;
        el('newProspect').disabled = true;
        el('saveProspect').disabled = true;
        setStatusText('Lagrer …');
        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                credentials: 'include',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload)
            });
            const body = await response.json();
            if (response.status === 409) {
                const error = new Error(body.message || 'Prospektet er endret siden du åpnet det. Last inn ny versjon før du lagrer.');
                error.conflict = true;
                throw error;
            }
            if (response.status === 422) {
                applyServerErrors(body.errors);
                el('prospectFormAlert').textContent = body.message || 'Kontroller opplysningene og prøv igjen.';
                el('prospectFormAlert').hidden = false;
                throw new Error(body.message || 'Kontroller opplysningene og prøv igjen.');
            }
            if (!response.ok || body.success !== true || !body.item) throw new Error(body.message || 'Lagringen mislyktes.');
            const item = body.item;
            populateForm(item, false);
            state.selectedId = text(item.id);
            state.selectedItem = item;
            setStatusText('Lagret');
            await loadList();
        } catch (error) {
            if (error.conflict) {
                el('prospectFormAlert').textContent = `${error.message} Skjemaet er beholdt. Oppdater prospektet manuelt ved å velge det på nytt etter at du har kopiert endringene.`;
                el('prospectFormAlert').hidden = false;
            } else if (!el('prospectFormAlert').hidden) {
                // Validation message is already visible; retain the entered form unchanged.
            } else {
                el('prospectFormAlert').textContent = error.message || 'Lagringen mislyktes. Prøv igjen.';
                el('prospectFormAlert').hidden = false;
            }
            setStatusText('Ikke lagret');
        } finally {
            state.saving = false;
            Object.values(fields).forEach(input => { input.disabled = false; });
            el('prospectInterestToggle').disabled = false;
            el('newProspect').disabled = false;
            updateDirtyState();
        }
    }

    function bindEvents() {
        form.addEventListener('input', (event) => {
            if (event.target.matches('[aria-invalid="true"]')) setFieldError(Object.keys(fields).find((key) => fields[key] === event.target), '');
            updateDirtyState();
        });
        form.addEventListener('change', updateDirtyState);
        form.addEventListener('submit', saveProspect);
        el('newProspect').addEventListener('click', startNew);
        el('refreshProspects').addEventListener('click', async () => {
            if (!allowLeave()) return;
            const selected = state.selectedId && !state.isNew ? state.selectedId : null;
            state.dirty = false;
            await loadList();
            if (selected) {
                state.selectedId = null;
                await openItem(selected);
            }
        });
        el('retryProspects').addEventListener('click', loadList);
        el('prospectSearch').addEventListener('input', () => {
            window.clearTimeout(state.searchTimer);
            state.searchTimer = window.setTimeout(() => {
                state.q = el('prospectSearch').value.trim();
                state.page = 1;
                loadList();
            }, 250);
        });
        el('prospectStatusFilter').addEventListener('change', () => {
            state.status = el('prospectStatusFilter').value;
            state.page = 1;
            loadList();
        });
        el('prospectSort').addEventListener('change', () => {
            state.sort = el('prospectSort').value;
            state.page = 1;
            loadList();
        });
        el('previousPage').addEventListener('click', () => {
            if (state.page <= 1) return;
            state.page -= 1;
            loadList();
        });
        el('nextPage').addEventListener('click', () => {
            if (state.page >= state.pages) return;
            state.page += 1;
            loadList();
        });
        window.addEventListener('beforeunload', (event) => {
            if (state.dirty || state.saving) { event.preventDefault(); event.returnValue = ''; }
        });
        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && !el('editorContent').hidden) {
                event.preventDefault();
                if (state.dirty && !state.saving) form.requestSubmit();
            }
            if (event.key === 'Escape' && state.dirty && document.activeElement && form.contains(document.activeElement)) {
                if (window.confirm('Forkaste ulagrede endringer?')) {
                    if (state.isNew) {
                        state.dirty = false;
                        el('editorContent').hidden = true;
                        el('editorEmpty').hidden = false;
                    } else populateForm(state.selectedItem);
                }
            }
        });
    }

    Object.values(fields).forEach((input) => {
        input.addEventListener('input', updateDirtyState);
    });
    configureRestrictedView();
    bindEvents();
    loadList();
})();
