'use strict';
document.addEventListener('DOMContentLoaded', () => {
    const trigger = document.getElementById('moduleTransitionPreviewButton');
    const root = document.getElementById('moduleTransitionPreviewModal');
    if (!trigger || !root) return;
    const modal = bootstrap.Modal.getOrCreateInstance(root);
    const content = document.getElementById('moduleTransitionPreviewContent');
    let loading = false;
    async function load() {
        if (loading) return;
        loading = true;
        content.replaceChildren();
        content.setAttribute('aria-busy', 'true');
        document.getElementById('moduleTransitionPreviewStatus').replaceChildren();
        portalText(content, 'p', 'Kontrollerer abonnementer og kontoer…', 'text-muted');
        try {
            const response = await portalRequest('/saas_portal/api/preview_module_transition.php');
            const report = response.preview;
            if (!report || report.read_only !== true || report.apply_available !== false ||
                !Array.isArray(report.companies) || !Array.isArray(report.global_candidates)) {
                throw new Error('Forhåndsvisningen kunne ikke bekreftes. Ingen data er endret.');
            }
            content.replaceChildren();
            portalText(content, 'p',
                `${report.summary.ready_company_count} av ${report.summary.company_count} bedrifter har eldre tilknytninger klare for gjennomgang.`,
                'fw-semibold');
            portalText(content, 'p',
                'Dette er en skrivebeskyttet kontroll av eldre tilgangsrader, ikke bedriftens aktive modulvalg. Planens features bestemmer tilgangen. Endringer gjøres bare gjennom bekreftet abonnementsoppsett; ingen priser, kontoer eller tilganger endres her.',
                'small text-muted');
            const actions = {
                create_dedicated_plan: 'Opprett bedriftsspesifikk plan',
                copy_shared_plan: 'Kopier delt plan før endring',
                review_existing_plan: 'Kontroller eksisterende plan',
                requires_subscription: 'Abonnement må klargjøres'
            };
            report.companies.forEach(company => {
                const card = portalText(content, 'section', '', 'border rounded p-3 mb-3');
                portalText(card, 'h3', company.company_name, 'h6');
                portalText(card, 'p', actions[company.proposed_action] || 'Krever avklaring', 'small mb-1');
                if (!company.is_active) portalText(card, 'p', 'Bedriften er inaktiv og forblir inaktiv.', 'small text-muted');
                portalText(card, 'p', company.module_names.length
                    ? 'Eldre aktive modulrader: ' + company.module_names.join(', ')
                    : 'Ingen eldre aktive modulrader.', 'small mb-1');
                if (company.proposed_features) portalText(card, 'code', company.proposed_features, 'd-block text-break small mb-2');
                if (company.excluded_grant_count) portalText(card, 'p',
                    `${company.excluded_grant_count} opphevede eller inaktive tildelinger holdes utenfor.`, 'small text-muted');
                if (company.blockers.length) {
                    const list = portalText(card, 'ul', '', 'small text-danger mb-0');
                    company.blockers.forEach(message => portalText(list, 'li', message));
                } else portalText(card, 'p', 'Klar for gjennomgang — ikke konvertert.', 'small text-success mb-0');
            });
            portalText(content, 'h3', 'Globale brukere — separat godkjenning', 'h5 mt-4');
            portalText(content, 'p',
                'En konto uten ansattlenke er kun en kandidat. Ingen konto får global rolle automatisk. Eventuell konvertering gir tilgang til alle bedrifter gjennom eksisterende SaaS-innlogging.',
                'small text-muted');
            if (!report.global_candidates.length) portalText(content, 'p', 'Ingen ukoblede firmakontoer funnet.', 'small');
            report.global_candidates.forEach(account => {
                const card = portalText(content, 'section', '', 'border rounded p-3 mb-2');
                portalText(card, 'h4', account.username || 'Konto uten brukernavn', 'h6');
                portalText(card, 'p', account.email, 'small mb-1 text-break');
                portalText(card, 'p', 'Nåværende bedrift: ' + account.previous_company_name, 'small text-muted mb-1');
                if (account.blockers.length) {
                    const list = portalText(card, 'ul', '', 'small text-danger mb-0');
                    account.blockers.forEach(message => portalText(list, 'li', message));
                } else portalText(card, 'p', 'Krever individuell godkjenning av global tilgang.', 'small mb-0');
            });
        } catch (error) {
            content.replaceChildren();
            portalFailure('moduleTransitionPreviewStatus', error.message, load);
        } finally {
            loading = false;
            content.setAttribute('aria-busy', 'false');
        }
    }
    trigger.addEventListener('click', () => { modal.show(); load(); });
    document.getElementById('moduleTransitionPreviewRefresh').addEventListener('click', load);
});
