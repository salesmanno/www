'use strict';
let portalActivityTypes = [];
let portalActivityRequest = 0;
let portalCompanies = [];
let portalGrantMode = false;
let portalCompanyRenderSignature = '';
const portalSelectedCompanies = new Set();
function portalGrantControls() {
    const next = document.getElementById('grantConfirmButton');
    next.hidden = !portalGrantMode;
    next.disabled = portalSelectedCompanies.size === 0;
    next.textContent = `Velg moduler (${portalSelectedCompanies.size})`;
    document.getElementById('grantModeButton').textContent = portalGrantMode ? 'Avbryt valg' : 'Tildel moduler';
    document.getElementById('grantModeButton').setAttribute('aria-pressed', String(portalGrantMode));
}
function portalRenderCompanies(companies) {
    const signature = JSON.stringify([companies, portalGrantMode]);
    if (signature === portalCompanyRenderSignature) return;
    portalCompanyRenderSignature = signature;
    portalCompanies = companies;
    const validIds = new Set(companies.map(c => String(c.id)));
    for (const id of portalSelectedCompanies) if (!validIds.has(id)) portalSelectedCompanies.delete(id);
    const container = document.getElementById('companiesList');
    container.replaceChildren();
    if (!companies.length) portalText(container, 'div', 'Ingen bedrifter registrert. Opprett en bedrift for å komme i gang.', 'portal-empty');
    companies.forEach(company => {
        const id = String(company.id);
        const card = portalText(container, 'article', '', 'company-card');
        card.classList.toggle('selected', portalSelectedCompanies.has(id));
        const link = document.createElement(portalGrantMode ? 'button' : 'a');
        link.className = 'company-identity';
        if (portalGrantMode) {
            link.type = 'button';
            link.classList.add('w-100', 'border-0', 'bg-transparent', 'p-0', 'text-start');
            link.setAttribute('aria-pressed', String(portalSelectedCompanies.has(id)));
            link.setAttribute('aria-label', `Velg ${company.name} for modultildeling`);
            link.addEventListener('click', () => {
                if (portalSelectedCompanies.has(id)) portalSelectedCompanies.delete(id); else portalSelectedCompanies.add(id);
                card.classList.toggle('selected', portalSelectedCompanies.has(id));
                link.setAttribute('aria-pressed', String(portalSelectedCompanies.has(id)));
                link.querySelector('.company-selection-label').textContent = portalSelectedCompanies.has(id) ? 'Valgt' : 'Velg';
                portalGrantControls();
            });
        } else link.href = '/saas_portal/pages/company_details.php?id=' + encodeURIComponent(id);
        portalLogo(link, company);
        const identity = portalText(link, 'div', '', 'flex-grow-1');
        portalText(identity, 'h4', company.name);
        portalText(identity, 'div', company.subdomain, 'subdomain small');
        if (portalGrantMode) portalText(link, 'span', portalSelectedCompanies.has(id) ? 'Valgt' : 'Velg', 'small company-selection-label');
        card.appendChild(link);
        const stats = portalText(card, 'div', '', 'company-stats');
        portalText(stats, 'span', `${Number(company.admin_count) || 0} kontoer`, 'stat-item');
        portalText(stats, 'span', `${Number(company.staff_count) || 0} ansatte`, 'stat-item');
        portalText(stats, 'span', `${Number(company.module_count) || 0} moduler`, 'stat-item');
        const actions = portalText(card, 'div', '', 'company-actions');
        portalButton(actions, 'Rediger', event => editCompany(id, event), 'btn btn-sm btn-outline-primary');
        const remove = portalButton(actions, 'Slett', event => deleteCompanyBtn(remove, event), 'btn btn-sm btn-outline-danger');
        remove.dataset.companyId = id;
        remove.dataset.companyName = company.name || '';
        portalText(actions, 'span', company.is_active == 1 ? 'Aktiv' : 'Inaktiv', 'status-badge ' + (company.is_active == 1 ? 'status-active' : 'status-inactive'));
    });
    portalGrantControls();
}
document.addEventListener('DOMContentLoaded', () => {
    const modalRoot = document.getElementById('companyGrantModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalRoot);
    const list = document.getElementById('companyGrantModules');
    const submit = document.getElementById('companyGrantSubmit');
    const status = document.getElementById('companyGrantStatus');
    const quotes = document.getElementById('companyGrantCompanyQuotes');
    const issuer = document.getElementById('companyGrantIssuer');
    const warnings = document.getElementById('companyGrantWarnings');
    const acknowledgement = document.getElementById('companyGrantAcknowledgement');
    let previewRevision = 0;
    let previewTimer = null;
    let previewQuote = null;
    let savingSubscription = false;
    let subscriptionSaved = false;

    function updateSaveControls() {
        const ready = previewQuote && previewQuote.apply_available === true && previewQuote.price_schema_ready === true &&
            /^[a-f0-9]{64}$/.test(previewQuote.quote_hash || '') &&
            previewQuote.companies.length === portalSelectedCompanies.size &&
            list.querySelector('input:checked') &&
            previewQuote.companies.every(company => !company.blockers.length && !company.missing_prices.length &&
                company.proposed_monthly !== null && company.setup_total !== null && company.currency);
        acknowledgement.disabled = !ready || savingSubscription || subscriptionSaved;
        submit.disabled = acknowledgement.disabled || !acknowledgement.checked;
    }
    acknowledgement.addEventListener('change', updateSaveControls);

    document.getElementById('grantModeButton').addEventListener('click', () => {
        portalGrantMode = !portalGrantMode;
        if (!portalGrantMode) portalSelectedCompanies.clear();
        portalRenderCompanies(portalCompanies);
    });

    function decimalText(value) {
        if (value === null || value === undefined || value === '') return 'Ikke konfigurert';
        const raw = String(value).trim();
        if (/^[+-]?0+(?:[.,]0+)?$/.test(raw)) return 'Gratis';
        return raw.replace('.', ',');
    }
    function priceNode(parent, label, value, currency) {
        const row = portalText(parent, 'div', '', 'd-flex justify-content-between gap-3 small py-1');
        portalText(row, 'span', label, 'text-muted');
        const amount = portalText(row, 'strong', decimalText(value), 'text-end');
        if (currency) amount.appendChild(document.createTextNode(' ' + String(currency)));
        return row;
    }
    function companyCurrency(id) {
        const company = portalCompanies.find(item => String(item.id) === String(id));
        return company && company.is_active == 0 ? 'Inaktiv' : '';
    }
    function renderModules(modules, selectedIds) {
        list.replaceChildren();
        if (!modules.length) {
            portalText(list, 'div', 'Ingen moduler tilgjengelig i katalogen.', 'portal-empty');
            return;
        }
        modules.forEach(module => {
            const moduleId = String(module.id);
            const label = portalText(list, 'label', '', 'form-check module-checkbox d-block border rounded p-3 mb-2');
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = moduleId;
            input.className = 'form-check-input me-2';
            input.checked = selectedIds.includes(moduleId);
            input.disabled = savingSubscription || subscriptionSaved;
            input.addEventListener('change', queueSubscriptionPreview);
            label.appendChild(input);
            const content = portalText(label, 'span', '', 'd-inline-block align-top');
            portalText(content, 'span', module.name || 'Uten navn', 'form-check-label fw-semibold d-block');
            if (module.description) portalText(content, 'span', module.description, 'small text-muted d-block mt-1');
            const prices = portalText(content, 'span', '', 'd-flex flex-wrap gap-3 small mt-2');
            const monthly = portalText(prices, 'span', 'Månedlig: ' + decimalText(module.price_monthly));
            const setup = portalText(prices, 'span', 'Oppstart: ' + decimalText(module.price_setup));
            if (module.price_currency) {
                monthly.appendChild(document.createTextNode(' ' + String(module.price_currency)));
                setup.appendChild(document.createTextNode(' ' + String(module.price_currency)));
            }
        });
    }
    function renderSubscription(company) {
        const card = portalText(quotes, 'article', '', 'border rounded p-3 mb-2');
        const heading = portalText(card, 'div', '', 'd-flex flex-wrap justify-content-between align-items-start gap-2');
        portalText(heading, 'h4', company.name || 'Bedrift', 'h6 mb-1');
        const inactive = companyCurrency(company.company_id);
        if (inactive) portalText(heading, 'span', inactive, 'badge text-bg-secondary');
        const subscription = company.subscription;
        const statusRow = portalText(card, 'div', '', 'small text-muted mb-2');
        if (subscription) {
            const cycleLabels = {monthly: 'Månedlig', month: 'Månedlig', yearly: 'Årlig', annual: 'Årlig', one_time: 'Engangsbetaling'};
            const stateLabels = {active: 'Aktivt', trialing: 'Prøveperiode', past_due: 'Forfalt', canceled: 'Avsluttet', inactive: 'Inaktiv'};
            const statusText = stateLabels[String(subscription.status || '').toLowerCase()] || subscription.status || 'Status ikke oppgitt';
            const cycleText = cycleLabels[String(subscription.billing_cycle || '').toLowerCase()] || subscription.billing_cycle || 'Ikke oppgitt';
            statusRow.textContent = `Eksisterende abonnement: ${statusText} · ${cycleText}`;
            if (subscription.plan_name) portalText(card, 'div', 'Avtalt plan: ' + subscription.plan_name, 'small mb-1');
            priceNode(card, 'Gjeldende avtalt månedspris', subscription.agreed_monthly, subscription.currency);
        } else {
            portalText(card, 'div', 'Ingen eksisterende abonnement registrert.', 'small text-muted mb-1');
        }
        portalText(card, 'div', 'Katalogestimat etter valgte moduler', 'small fw-semibold mt-2');
        priceNode(card, 'Månedlig pris for hele modulpakken', company.monthly_total, company.currency);
        priceNode(card, 'Engangsoppsett for nye moduler', company.setup_total, company.currency);
        priceNode(card, 'Månedspris som lagres', company.proposed_monthly, company.currency);
        if (Array.isArray(company.missing_prices) && company.missing_prices.length) {
            const missing = portalText(card, 'div', '', 'alert alert-warning py-2 px-3 small mt-2 mb-0');
            portalText(missing, 'strong', 'Manglende prisinformasjon');
            company.missing_prices.forEach(item => {
                const fields = Array.isArray(item.fields) ? item.fields.join(', ') : '';
                portalText(missing, 'div', `${item.name || 'Modul'}${fields ? ': ' + fields : ''} – pris må konfigureres.`);
            });
        }
        if (Array.isArray(company.blockers) && company.blockers.length) {
            const blocked = portalText(card, 'ul', '', 'small text-danger mt-2 mb-0 ps-3');
            company.blockers.forEach(reason => portalText(blocked, 'li', reason));
        }
        if (Array.isArray(company.notes) && company.notes.length) {
            const notes = portalText(card, 'ul', '', 'small text-muted mt-2 mb-0 ps-3');
            company.notes.forEach(note => portalText(notes, 'li', note));
        }
    }
    function clearQuoteContent() {
        quotes.replaceChildren();
        issuer.textContent = 'Henter fakturainformasjon …';
        warnings.replaceChildren();
    }
    function queueSubscriptionPreview() {
        if (savingSubscription || subscriptionSaved) return;
        const revision = ++previewRevision;
        window.clearTimeout(previewTimer);
        submit.disabled = true;
        acknowledgement.checked = false;
        acknowledgement.disabled = true;
        previewQuote = null;
        status.replaceChildren();
        portalText(status, 'div', 'Oppdaterer forhåndsvisningen …', 'small text-muted mb-2');
        clearQuoteContent();
        previewTimer = window.setTimeout(() => loadSubscriptionPreview(revision), 280);
    }
    async function loadSubscriptionPreview(revision) {
        const companyIds = Array.from(portalSelectedCompanies);
        const moduleIds = Array.from(list.querySelectorAll('input[type="checkbox"]:checked'), input => input.value);
        document.getElementById('companyGrantSummary').textContent =
            `${companyIds.length} valgte bedrifter · ${moduleIds.length} valgte moduler. Eksisterende tilganger beholdes.`;
        list.setAttribute('aria-busy', 'true');
        try {
            const data = await portalPost('/saas_portal/api/preview_company_subscription.php', {
                company_ids: companyIds,
                module_ids: moduleIds,
                csrf_token: portalCsrf
            });
            if (revision !== previewRevision) return;
            if (data.read_only !== true || typeof data.apply_available !== 'boolean') throw new Error('Svaret bekrefter ikke en gyldig prisforhåndsvisning.');
            const modules = Array.isArray(data.modules) ? data.modules : [];
            const companies = Array.isArray(data.companies) ? data.companies : [];
            previewQuote = data;
            warnings.replaceChildren();
            renderModules(modules, moduleIds);
            quotes.replaceChildren();
            companies.forEach(renderSubscription);
            issuer.replaceChildren();
            const issuerInfo = data.issuer || {};
            portalText(issuer, 'strong', issuerInfo.name || 'Ikke oppgitt', 'd-block');
            if (issuerInfo.id !== null && issuerInfo.id !== undefined) portalText(issuer, 'span', 'ID ' + issuerInfo.id, 'small text-muted');
            status.replaceChildren();
            if (data.price_schema_ready === false) {
                portalText(warnings, 'div', 'Prisgrunnlaget er ikke klart. Kontroller prisoppsettet før estimater brukes.', 'alert alert-warning py-2');
            }
            if (Array.isArray(data.warnings) && data.warnings.length) {
                const notice = portalText(warnings, 'div', '', 'alert alert-warning py-2');
                data.warnings.forEach(message => portalText(notice, 'div', message));
            }
            if (!moduleIds.length) portalText(status, 'div', 'Velg moduler for å se et estimat for tillegg. Gjeldende abonnement vises uavhengig av valget.', 'small text-muted');
            if (!companies.length) portalText(quotes, 'div', 'Velg minst én bedrift for å se pris per bedrift.', 'portal-empty');
            updateSaveControls();
        } catch (error) {
            if (revision !== previewRevision) return;
            quotes.replaceChildren();
            issuer.textContent = 'Ikke tilgjengelig';
            warnings.replaceChildren();
            previewQuote = null;
            acknowledgement.checked = false;
            acknowledgement.disabled = true;
            submit.disabled = true;
            if (!list.querySelector('input[type="checkbox"]')) {
                list.replaceChildren();
                portalText(list, 'div', 'Katalogen kunne ikke lastes.', 'portal-empty');
            }
            portalFailure('companyGrantStatus', error.message, () => queueSubscriptionPreview());
        } finally {
            if (revision === previewRevision) list.setAttribute('aria-busy', 'false');
        }
    }
    document.getElementById('grantConfirmButton').addEventListener('click', () => {
        modal.show();
    });
    submit.addEventListener('click', async () => {
        updateSaveControls();
        if (submit.disabled || savingSubscription || !previewQuote) return;
        const data = {
            company_ids: Array.from(portalSelectedCompanies),
            module_ids: Array.from(list.querySelectorAll('input:checked'), input => input.value),
            quote_hash: previewQuote.quote_hash,
            confirmed: true,
            csrf_token: portalCsrf
        };
        savingSubscription = true;
        updateSaveControls();
        list.querySelectorAll('input').forEach(input => { input.disabled = true; });
        submit.textContent = 'Lagrer …';
        status.replaceChildren();
        try {
            const result = await portalPost('/saas_portal/api/save_company_subscription.php', data);
            subscriptionSaved = true;
            portalText(status, 'div', result.message || 'Abonnement og modultilgang er lagret.', 'alert alert-success');
            portalSelectedCompanies.clear();
            portalGrantMode = false;
            portalRenderCompanies(portalCompanies);
            loadCompanies();
            submit.textContent = 'Lagret';
        } catch (error) {
            previewQuote = null;
            acknowledgement.checked = false;
            portalFailure('companyGrantStatus', error.message, queueSubscriptionPreview);
            submit.textContent = 'Bekreft og lagre';
            list.querySelectorAll('input').forEach(input => { input.disabled = false; });
        } finally {
            savingSubscription = false;
            updateSaveControls();
        }
    });
    modalRoot.addEventListener('hide.bs.modal', event => {
        if (savingSubscription) event.preventDefault();
        else {
            ++previewRevision;
            window.clearTimeout(previewTimer);
            previewQuote = null;
            updateSaveControls();
        }
    });
    modalRoot.addEventListener('shown.bs.modal', () => {
        window.clearTimeout(previewTimer);
        previewRevision++;
        previewQuote = null;
        subscriptionSaved = false;
        acknowledgement.checked = false;
        acknowledgement.disabled = true;
        submit.textContent = 'Bekreft og lagre';
        list.replaceChildren();
        portalText(list, 'div', '', 'portal-skeleton');
        quotes.replaceChildren();
        portalText(quotes, 'div', '', 'portal-skeleton');
        warnings.replaceChildren();
        status.replaceChildren();
        portalText(status, 'div', 'Laster katalog og gjeldende prisgrunnlag …', 'small text-muted mb-2');
        issuer.textContent = 'Henter fakturainformasjon …';
        submit.disabled = true;
        loadSubscriptionPreview(previewRevision);
    });
    document.getElementById('activitySearch').addEventListener('input', showActivityHistory);
    document.getElementById('sessionRefreshButton').addEventListener('click', loadActiveSessions);
    loadActivityStream();
    loadActiveSessions();
    setInterval(() => {
        if (document.hidden) return;
        if (!activityPaused) loadActivityStream();
        loadActiveSessions();
    }, 15000);
});
