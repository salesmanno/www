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
    document.getElementById('grantModeButton').addEventListener('click', () => {
        portalGrantMode = !portalGrantMode;
        if (!portalGrantMode) portalSelectedCompanies.clear();
        portalRenderCompanies(portalCompanies);
    });
    async function loadGrantModules() {
        submit.disabled = true;
        list.replaceChildren();
        portalText(list, 'div', '', 'portal-skeleton');
        document.getElementById('companyGrantStatus').replaceChildren();
        document.getElementById('companyGrantSummary').textContent = `Tillegg til ${portalSelectedCompanies.size} valgte bedrifter. Eksisterende tilganger beholdes.`;
        try {
            const selected = Array.from(portalSelectedCompanies);
            const results = await Promise.all(selected.map(id => portalRequest('/saas_portal/api/get_company_modules.php?company_id=' + encodeURIComponent(id))));
            const modules = new Map();
            results.forEach(result => (result.modules || []).forEach(module => modules.set(String(module.id), module)));
            list.replaceChildren();
            if (!modules.size) portalText(list, 'p', 'Ingen moduler tilgjengelig.', 'portal-empty');
            modules.forEach(module => {
                const label = portalText(list, 'label', '', 'form-check module-checkbox d-block');
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.value = String(module.id);
                input.className = 'form-check-input';
                input.addEventListener('change', () => { submit.disabled = !list.querySelector('input:checked'); });
                label.appendChild(input);
                portalText(label, 'span', module.name, 'form-check-label');
                if (module.description) portalText(label, 'div', module.description, 'small text-muted');
            });
        } catch (error) { portalFailure('companyGrantStatus', error.message, loadGrantModules); }
    }
    document.getElementById('grantConfirmButton').addEventListener('click', () => { modal.show(); loadGrantModules(); });
    submit.addEventListener('click', async () => {
        const ids = Array.from(list.querySelectorAll('input:checked'), input => input.value);
        if (!ids.length || !portalSelectedCompanies.size || submit.disabled) return;
        submit.disabled = true;
        submit.textContent = 'Tildeler…';
        try {
            await portalPost('/saas_portal/api/grant_company_modules.php', {company_ids: Array.from(portalSelectedCompanies), module_ids: ids, csrf_token: portalCsrf});
            modal.hide();
            portalSelectedCompanies.clear();
            portalGrantMode = false;
            loadCompanies();
        } catch (error) { portalFailure('companyGrantStatus', error.message); submit.disabled = false; }
        finally { submit.textContent = 'Bekreft tildeling'; }
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
