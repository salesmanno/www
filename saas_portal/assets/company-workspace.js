'use strict';
let portalStaff = [];
let portalAdmins = [];
let portalStaffReady = false;
let portalAccountsReady = false;
let portalAccountQuota = null;
let portalLinkedAccountStaffId = '';
const portalExpandedStaff = new Set();
const portalExpandedAccounts = new Set();
let portalCompanyActivities = [];
let portalCompanyActivityRequest = 0;
async function portalRefreshStaff() {
    try {
        const data = await portalRequest('/saas_portal/api/get_staff.php?company_id=' + encodeURIComponent(companyId));
        portalStaff = Array.isArray(data.staff) ? data.staff : [];
        availableAdminStaff = Object.fromEntries(portalStaff.map(staff => [staff.id, staff]));
        portalStaffReady = true;
        document.getElementById('staffLoadStatus').replaceChildren();
        portalRenderPeople();
    } catch (error) {
        if (!portalStaffReady) {
            const list = document.getElementById('staffList');
            list.replaceChildren();
            portalText(list, 'p', 'Ansattlisten er ikke tilgjengelig.', 'portal-empty');
        }
        portalFailure('staffLoadStatus', error.message, loadStaff);
    }
}
async function portalRefreshAdmins() {
    try {
        const data = await portalRequest('/saas_portal/api/get_admins.php?company_id=' + encodeURIComponent(companyId));
        portalAdmins = Array.isArray(data.admins) ? data.admins : [];
        portalAccountQuota = data.quota || null;
        portalAccountsReady = true;
        document.getElementById('adminLoadStatus').replaceChildren();
        portalRenderPeople();
    } catch (error) {
        portalAccountsReady = false;
        const list = document.getElementById('adminsList');
        list.replaceChildren();
        portalText(list, 'p', 'Kontolisten er ikke tilgjengelig.', 'small text-muted');
        portalFailure('adminLoadStatus', error.message, loadAdmins);
        if (portalStaffReady) portalRenderPeople();
    }
}
function portalAccountRow(parent, account) {
    const id = String(account.admin_id);
    const root = portalText(parent, 'div', '', 'account-row');
    const toggle = portalButton(root, `Brukerkonto: ${account.username || 'Uten brukernavn'}`, () => {
        if (portalExpandedAccounts.has(id)) portalExpandedAccounts.delete(id); else portalExpandedAccounts.add(id);
        content.hidden = !portalExpandedAccounts.has(id);
        toggle.setAttribute('aria-expanded', String(!content.hidden));
    }, 'account-toggle');
    const content = portalText(root, 'div', '', 'account-details');
    content.id = 'account-detail-' + encodeURIComponent(id);
    toggle.setAttribute('aria-controls', content.id);
    content.hidden = !portalExpandedAccounts.has(id);
    toggle.setAttribute('aria-expanded', String(!content.hidden));
    portalText(content, 'p', 'Konto-e-post: ' + (account.account_email || 'Ikke oppgitt'));
    portalText(content, 'p', 'Status: ' + (account.is_active == 1 ? 'Aktiv' : 'Inaktiv'));
    portalText(content, 'p', 'Siste innlogging: ' + (account.last_login ? new Date(account.last_login).toLocaleString('no-NO') : 'Aldri'));
    portalText(content, 'p', 'Opprettet: ' + (account.created_at ? new Date(account.created_at).toLocaleString('no-NO') : 'Ukjent'));
    const actions = portalText(content, 'div', '', 'workspace-actions');
    portalButton(actions, 'Tildel moduler', () => manageAdminModules(id, account.username || ''), 'btn btn-sm btn-outline-primary');
    portalButton(actions, 'Slett bruker', () => deleteAdmin(id), 'btn btn-sm btn-outline-danger');
}
function portalRenderPeople() {
    if (!portalStaffReady) return;
    const list = document.getElementById('staffList');
    list.replaceChildren();
    if (portalAccountQuota) {
        const limit = portalAccountQuota.max_users;
        portalText(list, 'p', `${portalAccountQuota.active_users} aktive SuperUsers / ${limit === null ? 'brukergrense ikke satt' : 'maks ' + limit}`, 'small text-muted');
    }
    if (!portalStaff.length) portalText(list, 'p', 'Ingen ansatte registrert. Legg til en ansatt eller importer rolleinnehavere.', 'portal-empty');
    portalStaff.forEach(staff => {
        const id = String(staff.id);
        const name = `${staff.first_name || ''} ${staff.last_name || ''}`.trim() || 'Ansatt uten navn';
        const root = portalText(list, 'section', '', 'staff-item');
        const toggle = portalButton(root, '', () => {
            if (portalExpandedStaff.has(id)) portalExpandedStaff.delete(id); else portalExpandedStaff.add(id);
            detail.hidden = !portalExpandedStaff.has(id);
            toggle.setAttribute('aria-expanded', String(!detail.hidden));
            chevron.textContent = detail.hidden ? '+' : '−';
        }, 'staff-toggle');
        const identity = portalText(toggle, 'span', '');
        portalText(identity, 'strong', name);
        portalText(identity, 'small', staff.job_title || staff.email || 'Ingen stilling oppgitt', 'text-muted');
        const chevron = portalText(toggle, 'span', portalExpandedStaff.has(id) ? '−' : '+', 'text-muted');
        chevron.setAttribute('aria-hidden', 'true');
        const detail = portalText(root, 'div', '', 'staff-details');
        detail.id = 'staff-detail-' + encodeURIComponent(id);
        detail.hidden = !portalExpandedStaff.has(id);
        toggle.setAttribute('aria-controls', detail.id);
        toggle.setAttribute('aria-expanded', String(!detail.hidden));
        portalText(detail, 'p', 'E-post: ' + (staff.email || 'Ikke oppgitt'));
        portalText(detail, 'p', 'Telefon: ' + (staff.phone || 'Ikke oppgitt'));
        portalText(detail, 'p', 'Stilling: ' + (staff.job_title || 'Ikke oppgitt'));
        const actions = portalText(detail, 'div', '', 'workspace-actions mb-2');
        portalButton(actions, 'Rediger ansatt', () => showEditStaff(id), 'btn btn-sm btn-outline-primary');
        portalButton(actions, 'Slett ansatt', () => deleteStaff(id), 'btn btn-sm btn-outline-danger');
        const accounts = portalAdmins.filter(account => String(account.tenant_staff_id || '') === id);
        if (!portalAccountsReady) {
            portalText(detail, 'p', 'Kontodata er ikke tilgjengelig. Oppdater kontolisten før du oppretter en bruker.', 'small text-muted');
            portalButton(detail, 'Oppdater kontodata', loadAdmins);
        } else if (accounts.length) accounts.forEach(account => portalAccountRow(detail, account));
        else if (staff.has_account == 1) {
            portalText(detail, 'p', 'Ansatt er registrert med konto, men koblingen kunne ikke leses. Kontroller eldre kontoer nedenfor.', 'small text-muted');
        } else {
            const create = portalButton(detail, 'Opprett SuperUser', () => showCreateAdmin(id), 'btn btn-sm btn-primary-custom');
            create.disabled = !staff.email || !portalAccountQuota || portalAccountQuota.max_users === null ||
                portalAccountQuota.active_users >= portalAccountQuota.max_users;
            if (staff.email && create.disabled) create.title = 'Sett eller øk brukergrensen i bedriftens abonnementsoppsett.';
            if (!staff.email) portalText(detail, 'p', 'Legg til e-post på den ansatte først.', 'small text-muted mt-2');
        }
    });
    const legacy = document.getElementById('adminsList');
    if (!portalAccountsReady) return;
    legacy.replaceChildren();
    const ids = new Set(portalStaff.map(staff => String(staff.id)));
    const unlinked = portalAdmins.filter(account => !account.tenant_staff_id || !ids.has(String(account.tenant_staff_id)));
    if (!unlinked.length) portalText(legacy, 'p', 'Ingen ukoblede administratorer.', 'small text-muted');
    unlinked.forEach(account => portalAccountRow(legacy, account));
}
function portalCompanyActivityRender() {
    const feed = document.getElementById('companyActivityFeed');
    const query = document.getElementById('companyActivitySearch').value.trim().toLocaleLowerCase('no');
    const filtered = portalCompanyActivities.filter(activity => !query || [activity.actor_display, activity.action_summary, activity.action_type].join(' ').toLocaleLowerCase('no').includes(query));
    const scrollTop = feed.scrollTop;
    feed.replaceChildren();
    if (!filtered.length) portalText(feed, 'div', query ? 'Ingen aktivitet samsvarer med søket.' : 'Ingen aktivitet for dette utvalget.', 'portal-empty');
    filtered.forEach(activity => {
        const item = portalText(feed, 'article', '', 'activity-item border mb-2');
        portalText(item, 'strong', activity.actor_display || 'System');
        portalText(item, 'div', activity.action_summary || '', 'small mt-1');
        if (activity.amount) portalText(item, 'div', `${activity.amount} kr`, 'small fw-bold');
        portalText(item, 'div', portalCompanyActivityLabel(activity.action_type), 'small text-muted mt-1');
        portalText(item, 'div', new Date(activity.created_at).toLocaleString('no-NO'), 'small text-muted');
    });
    feed.scrollTop = scrollTop;
}
function portalCompanyActivityLabel(type) {
    const labels = {company_created: 'Bedrift opprettet', company_updated: 'Bedrift oppdatert', company_deleted: 'Bedrift slettet', company_action: 'Bedriftshendelse', staff_created: 'Ansatt opprettet', staff_updated: 'Ansatt oppdatert', staff_deleted: 'Ansatt slettet', admin_created: 'Brukerkonto opprettet', admin_deleted: 'Brukerkonto slettet', admin_modules_updated: 'Moduler oppdatert', sale: 'Salg registrert', login: 'Innlogging'};
    return labels[type] || String(type || 'Aktivitet').replace(/[_-]+/g, ' ').replace(/^./, letter => letter.toLocaleUpperCase('no'));
}
async function portalCompanyActivityLoad() {
    const select = document.getElementById('companyActivityType');
    const selected = select.value;
    const params = new URLSearchParams({company_id: companyId, limit: '1000'});
    if (selected) params.set('action_type', selected);
    const request = ++portalCompanyActivityRequest;
    const feed = document.getElementById('companyActivityFeed');
    feed.setAttribute('aria-busy', 'true');
    try {
        const data = await portalRequest('/saas_portal/api/get_activity_stream.php?' + params);
        if (request !== portalCompanyActivityRequest) return;
        portalCompanyActivities = Array.isArray(data.activities) ? data.activities.slice().sort((a, b) => new Date(b.created_at) - new Date(a.created_at)) : [];
        const types = Array.isArray(data.action_types) ? data.action_types : Array.from(new Set(portalCompanyActivities.map(activity => activity.action_type)));
        select.replaceChildren(new Option('Alle hendelser', ''));
        types.forEach(type => select.add(new Option(portalCompanyActivityLabel(type), String(type))));
        select.value = types.includes(selected) ? selected : '';
        document.getElementById('companyActivityStatus').replaceChildren();
        portalCompanyActivityRender();
    } catch (error) {
        if (request === portalCompanyActivityRequest) {
            if (feed.querySelector('.portal-skeleton')) {
                feed.replaceChildren();
                portalText(feed, 'div', 'Aktivitetsloggen er ikke tilgjengelig.', 'portal-empty');
            }
            portalFailure('companyActivityStatus', error.message, portalCompanyActivityLoad);
        }
    } finally { if (request === portalCompanyActivityRequest) feed.setAttribute('aria-busy', 'false'); }
}
let portalSeoInitialized = false;
let portalSeoCompanyId = '';
let portalSeoGeneration = 0;
let portalSeoLoadInFlight = null;
let portalSeoSaving = false;
const portalSeoFields = [
    ['seoTitle', 'seo_title', 255],
    ['seoDescription', 'seo_description', 1000],
    ['seoKeywords', 'seo_keywords', 2000],
    ['seoCanonicalUrl', 'canonical_url', 500]
];
function portalSeoSetEnabled(enabled) {
    portalSeoFields.forEach(([id]) => { document.getElementById(id).disabled = !enabled; });
    document.getElementById('seoSaveButton').disabled = !enabled || portalSeoSaving;
}
function portalSeoCanonicalIsValid(value) {
    value = value.trim();
    if (!value) return true;
    if (/[\u0000-\u001f\u007f-\u009f\\\s]/u.test(value) || !/^https?:\/\//i.test(value)) return false;
    try {
        const authority = value.match(/^https?:\/\/([^/?#]+)/i)?.[1];
        if (!authority || authority.includes('@')) return false;
        const rawHost = authority.startsWith('[') ? authority.slice(0, authority.indexOf(']') + 1) : authority.split(':')[0];
        const url = new URL(value);
        if (!['http:', 'https:'].includes(url.protocol) || !url.hostname || url.username || url.password) return false;
        const hostname = url.hostname.toLowerCase();
        if (hostname.startsWith('[') && hostname.endsWith(']')) return true;
        if (hostname !== rawHost.toLowerCase()) return false;
        if (/^\d{1,3}(?:\.\d{1,3}){3}$/.test(hostname)) {
            return hostname.split('.').every(part => Number(part) <= 255);
        }
        if (hostname.length > 253) return false;
        return hostname.split('.').every(label =>
            label.length > 0 && label.length <= 63 &&
            /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i.test(label)
        );
    } catch (_) { return false; }
}
function portalSeoValidationError() {
    for (const [id, field, maximum] of portalSeoFields) {
        const value = document.getElementById(id).value;
        if (Array.from(value).length > maximum) {
            return `${field === 'seo_title' ? 'SEO-tittel' : field === 'seo_description' ? 'SEO-beskrivelse' : field === 'seo_keywords' ? 'Søkeord' : 'Kanonisk URL'} kan ikke være lengre enn ${maximum} tegn.`;
        }
    }
    if (!portalSeoCanonicalIsValid(document.getElementById('seoCanonicalUrl').value)) {
        return 'Kanonisk URL må være en gyldig, absolutt HTTP- eller HTTPS-adresse uten brukernavn, mellomrom eller ugyldige tegn.';
    }
    return '';
}
function portalSeoLoad() {
    const requestedCompanyId = String(companyId);
    if (portalSeoInitialized && portalSeoCompanyId === requestedCompanyId) return Promise.resolve();
    if (portalSeoLoadInFlight && portalSeoLoadInFlight.companyId === requestedCompanyId) return portalSeoLoadInFlight.promise;

    const generation = ++portalSeoGeneration;
    if (portalSeoCompanyId !== requestedCompanyId) {
        portalSeoInitialized = false;
        portalSeoCompanyId = '';
    }
    portalSeoSetEnabled(false);
    const status = document.getElementById('companySeoStatus');
    status.textContent = 'Henter SEO-innstillinger…';
    const promise = Promise.resolve().then(async () => {
        try {
            const data = await portalRequest('/saas_portal/api/company_seo.php?company_id=' + encodeURIComponent(requestedCompanyId));
            if (generation !== portalSeoGeneration || String(companyId) !== requestedCompanyId) return;
            if (!data.seo || typeof data.seo !== 'object' ||
                portalSeoFields.some(([, field]) => typeof data.seo[field] !== 'string')) {
                throw new Error('SEO-innstillingene kunne ikke leses. Serveren returnerte ufullstendige data.');
            }
            portalSeoFields.forEach(([id, field]) => { document.getElementById(id).value = data.seo[field]; });
            portalSeoInitialized = true;
            portalSeoCompanyId = requestedCompanyId;
            portalSeoSetEnabled(true);
            status.replaceChildren();
        } catch (error) {
            if (generation === portalSeoGeneration && String(companyId) === requestedCompanyId) {
                portalSeoInitialized = false;
                portalSeoSetEnabled(false);
                portalFailure('companySeoStatus', error.message, portalSeoLoad);
            }
        } finally {
            if (portalSeoLoadInFlight && portalSeoLoadInFlight.generation === generation) portalSeoLoadInFlight = null;
        }
    });
    portalSeoLoadInFlight = {companyId: requestedCompanyId, generation, promise};
    return promise;
}
document.addEventListener('DOMContentLoaded', () => {
    loadStaff();
    loadAdmins();
    loadEmailConfig();
    portalCompanyActivityLoad();
    document.getElementById('companyActivityRefresh').addEventListener('click', portalCompanyActivityLoad);
    document.getElementById('companyActivityType').addEventListener('change', portalCompanyActivityLoad);
    document.getElementById('companyActivitySearch').addEventListener('input', portalCompanyActivityRender);
    document.getElementById('seo-tab').addEventListener('shown.bs.tab', portalSeoLoad);
    document.getElementById('createAdminForm').addEventListener('submit', event => { event.preventDefault(); createAdmin(); });
    document.getElementById('createStaffForm').addEventListener('submit', event => { event.preventDefault(); saveStaff(); });
    document.getElementById('companySeoForm').addEventListener('submit', async event => {
        event.preventDefault();
        const button = document.getElementById('seoSaveButton');
        if (button.disabled || !portalSeoInitialized || portalSeoCompanyId !== String(companyId)) return;
        const validationError = portalSeoValidationError();
        if (validationError) {
            portalFailure('companySeoStatus', validationError);
            return;
        }
        button.disabled = true;
        button.textContent = 'Lagrer…';
        portalSeoSaving = true;
        const requestCompanyId = String(companyId);
        try {
            const data = await portalPost('/saas_portal/api/company_seo.php', {
                company_id: requestCompanyId,
                seo_title: document.getElementById('seoTitle').value,
                seo_description: document.getElementById('seoDescription').value,
                seo_keywords: document.getElementById('seoKeywords').value,
                canonical_url: document.getElementById('seoCanonicalUrl').value,
                csrf_token: portalCsrf
            });
            const status = document.getElementById('companySeoStatus');
            status.replaceChildren();
            portalText(status, 'div', data.message || 'SEO-innstillinger lagret.', 'alert alert-success');
        } catch (error) { portalFailure('companySeoStatus', error.message); }
        finally {
            portalSeoSaving = false;
            button.textContent = 'Lagre SEO';
            portalSeoSetEnabled(portalSeoInitialized && portalSeoCompanyId === String(companyId));
        }
    });
    portalSeoFields.forEach(([id]) => document.getElementById(id).addEventListener('input', () => {
        const status = document.getElementById('companySeoStatus');
        if (status.querySelector('.alert-danger')) status.replaceChildren();
    }));
    const image = document.getElementById('companyActivityWatermark');
    const url = portalSafeImage(companyLogoUrl);
    if (url) {
        image.referrerPolicy = 'no-referrer';
        image.addEventListener('error', () => { image.hidden = true; }, {once: true});
        image.src = url;
        image.hidden = false;
    }
    setInterval(() => { if (!document.hidden) portalCompanyActivityLoad(); }, 15000);
});
