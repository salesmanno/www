'use strict';
function portalText(parent, tag, text, className) {
    const node = document.createElement(tag);
    node.textContent = text == null ? '' : String(text);
    if (className) node.className = className;
    parent.appendChild(node);
    return node;
}
function portalButton(parent, label, action, className) {
    const button = portalText(parent, 'button', label, className || 'btn btn-sm btn-outline-secondary');
    button.type = 'button';
    button.addEventListener('click', action);
    return button;
}
function portalFailure(id, message, retry) {
    const root = document.getElementById(id);
    if (!root) return;
    root.replaceChildren();
    const alert = portalText(root, 'div', message, 'alert alert-danger');
    alert.setAttribute('role', 'alert');
    if (retry) portalButton(alert, 'Prøv igjen', retry, 'btn btn-sm btn-outline-danger ms-2');
}
async function portalRequest(url, options) {
    const response = await fetch(url, {credentials: 'same-origin', ...options});
    let data;
    try { data = await response.json(); }
    catch (_) { throw new Error('Serveren returnerte ikke et gyldig svar. Kontroller innloggingen og prøv igjen.'); }
    if (!response.ok || !data.success) throw new Error(data.message || 'Forespørselen kunne ikke fullføres.');
    return data;
}
function portalPost(url, payload) {
    return portalRequest(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: JSON.stringify(payload)});
}
function portalSafeImage(value) {
    if (typeof value !== 'string' || !value.trim() || /[\u0000-\u001f\u007f\\]/.test(value)) return '';
    const url = value.trim();
    if (/^[a-z][a-z0-9+.-]*:/i.test(url) && !/^https?:\/\//i.test(url)) return '';
    try { return ['http:', 'https:'].includes(new URL(url, document.baseURI).protocol) ? url : ''; }
    catch (_) { return ''; }
}
function portalLogo(parent, company) {
    const logo = portalText(parent, 'span', String(company.name || 'B').trim().slice(0, 2).toLocaleUpperCase('no'), 'company-logo');
    const url = portalSafeImage(company.logo_url);
    if (url) {
        const img = document.createElement('img');
        img.alt = 'Logo for ' + String(company.name || 'bedrift');
        img.loading = 'lazy';
        img.referrerPolicy = 'no-referrer';
        img.addEventListener('error', () => img.remove(), {once: true});
        img.src = url;
        logo.replaceChildren(img);
        img.addEventListener('error', () => { logo.textContent = String(company.name || 'B').slice(0, 2); }, {once: true});
    }
    return logo;
}
async function portalLoadSessions(company) {
    try {
        const params = company ? '?company_id=' + encodeURIComponent(company) : '';
        const data = await portalRequest('/saas_portal/api/get_active_sessions.php' + params);
        document.getElementById('sessionStatus').replaceChildren();
        const panel = document.getElementById('activeSessionsPanel');
        panel.replaceChildren();
        const sessions = Array.isArray(data.sessions) ? data.sessions : [];
        if (!sessions.length) portalText(panel, 'div', 'Ingen aktive økter akkurat nå.', 'portal-empty');
        sessions.forEach(session => {
            const row = portalText(panel, 'div', '', 'session-row');
            const identity = portalText(row, 'div', '', 'session-identity');
            portalText(identity, 'strong', session.admin_name || session.username);
            portalText(identity, 'div', session.company_name || '', 'small text-muted');
            portalText(identity, 'div', `${Number(session.minutes_idle) || 0} min inaktiv`, 'small text-muted');
            const button = portalButton(row, 'Avslutt', async () => {
                if (!session.session_id) {
                    portalFailure('sessionStatus', 'Denne økten mangler økt-ID og kan ikke avsluttes sikkert.', () => portalLoadSessions(company));
                    return;
                }
                if (!confirm(`Avslutte denne økten for ${session.username || session.admin_name || 'brukeren'}?`)) return;
                button.disabled = true;
                try {
                    await portalPost('/saas_portal/api/logout_user.php', {
                        admin_id: session.tenant_admin_id || session.admin_id,
                        session_id: session.session_id,
                        identity_kind: session.identity_kind || 'tenant',
                        csrf_token: portalCsrf
                    });
                    await portalLoadSessions(company);
                } catch (error) { portalFailure('sessionStatus', error.message); button.disabled = false; }
            }, 'btn btn-sm btn-outline-danger');
            button.setAttribute('aria-label', `Avslutt økt for ${session.username || session.admin_name || 'bruker'}`);
        });
    } catch (error) {
        const panel = document.getElementById('activeSessionsPanel');
        if (panel.querySelector('.portal-skeleton')) {
            panel.replaceChildren();
            portalText(panel, 'div', 'Øktlisten er ikke tilgjengelig.', 'portal-empty');
        }
        portalFailure('sessionStatus', error.message, () => portalLoadSessions(company));
    }
}
