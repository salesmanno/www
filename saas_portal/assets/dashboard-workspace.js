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
    const availableList = document.getElementById('companyGrantAvailable');
    const selectedList = document.getElementById('companyGrantSelected');
    const companySwitcher = document.getElementById('companyGrantCompanySwitcher');
    const userLimit = document.getElementById('companyGrantMaxUsers');
    const monthlyDiscountInput = document.getElementById('companyGrantMonthlyDiscount');
    const setupDiscountInput = document.getElementById('companyGrantSetupDiscount');
    const discountHelp = document.getElementById('companyGrantDiscountHelp');
    const discountHelpText = 'Rabatten gjelder denne bedriften. Angi 0–100 prosent med maks to desimaler.';
    const activeUsers = document.getElementById('companyGrantActiveUsers');
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
    let hasInitializedSelection = false;
    let hasNormalizedSelectionQuote = false;
    let editingCompanyId = null;
    const moduleSelections = new Map();
    const userLimits = new Map();
    const planDiscounts = new Map();
    const companyMetadata = new Map();
    let catalogModules = [];
    let focusAfterPreview = null;

    function parseUserLimit(value) {
        if (value === null || value === undefined || value === '') return null;
        const parsed = Number(value);
        return Number.isInteger(parsed) && parsed >= 0 ? parsed : null;
    }
    function discountValue(value) {
        if (value === null || value === undefined || value === '') return '0.00';
        const normalized = String(value).trim().replace(',', '.');
        if (!/^\d{1,3}(?:\.\d{1,2})?$/.test(normalized)) return '0.00';
        const amount = Number(normalized);
        return Number.isFinite(amount) && amount >= 0 && amount <= 100 ? amount.toFixed(2) : '0.00';
    }
    function discountsFor(id) {
        return planDiscounts.get(String(id)) || {monthly: '0.00', setup: '0.00'};
    }
    function quotePricingIsConsistent(company) {
        if (!company.currency) return false;
        const selected = moduleSelections.get(String(company.company_id)) || new Set();
        const quoted = Array.isArray(company.module_ids) ? company.module_ids.map(String) : [];
        if (quoted.length !== selected.size || new Set(quoted).size !== quoted.length ||
            quoted.some(id => !selected.has(id))) return false;
        return Array.from(selected).every(id => {
            const module = catalogModules.find(item => String(item.id) === String(id));
            return module && module.price_monthly !== null && module.price_monthly !== undefined && module.price_monthly !== '' &&
                module.price_setup !== null && module.price_setup !== undefined && module.price_setup !== '' &&
                module.price_currency && String(module.price_currency).toUpperCase() === String(company.currency).toUpperCase();
        });
    }
    function updateSaveControls() {
        const ready = previewQuote && previewQuote.apply_available === true && previewQuote.price_schema_ready === true &&
            hasNormalizedSelectionQuote &&
            /^[a-f0-9]{64}$/.test(previewQuote.quote_hash || '') &&
            previewQuote.companies.length === portalSelectedCompanies.size &&
            previewQuote.companies.every(company => !company.blockers.length && !company.missing_prices.length &&
                company.proposed_monthly !== null && company.monthly_discount_amount !== null &&
                company.setup_total !== null && company.setup_gross_total !== null &&
                company.setup_discount_amount !== null && company.selected_setup_total !== null &&
                company.selected_setup_net_total !== null && quotePricingIsConsistent(company) &&
                Number.isInteger(userLimits.get(String(company.company_id))) &&
                userLimits.get(String(company.company_id)) >= Math.max(0, Number(company.active_users) || 0)) &&
            monthlyDiscountInput.validity.valid && setupDiscountInput.validity.valid;
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
        if (/^[+-]?0+(?:[.,]0+)?$/.test(raw)) return '0,00';
        return raw.replace('.', ',');
    }
    function priceNode(parent, label, value, currency) {
        const row = portalText(parent, 'div', '', 'd-flex justify-content-between gap-3 small py-1 subscription-price-row');
        portalText(row, 'span', label, 'text-muted');
        const amount = portalText(row, 'strong', decimalText(value), 'text-end');
        if (currency) amount.appendChild(document.createTextNode(' ' + String(currency)));
        return row;
    }
    function moneyCents(value) {
        if (value === null || value === undefined || value === '') return null;
        const match = String(value).trim().match(/^([+-]?)(\d+)(?:[.,](\d{1,2}))?$/);
        if (!match) return null;
        const whole = Number(match[2]);
        const fraction = Number((match[3] || '').padEnd(2, '0'));
        const cents = whole * 100 + fraction;
        if (!Number.isSafeInteger(cents)) return null;
        return match[1] === '-' ? -cents : cents;
    }
    function moneyFromCents(cents) {
        if (!Number.isSafeInteger(cents)) return null;
        const absolute = Math.abs(cents);
        return `${cents < 0 ? '-' : ''}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
    }
    function companyCurrency(id) {
        const company = portalCompanies.find(item => String(item.id) === String(id));
        return company && company.is_active == 0 ? 'Inaktiv' : '';
    }
    function selectedForEditing() {
        return moduleSelections.get(String(editingCompanyId)) || new Set();
    }
    function renderModulePane(target, modules, moveToSelected) {
        target.replaceChildren();
        if (!modules.length) {
            portalText(target, 'div', moveToSelected ? 'Alle moduler er valgt for denne bedriften.' : 'Ingen moduler valgt ennå.', 'portal-empty');
            return;
        }
        modules.forEach(module => {
            const id = String(module.id);
            const button = portalText(target, 'button', '', 'module-choice');
            button.type = 'button';
            button.disabled = savingSubscription || subscriptionSaved;
            button.dataset.moduleId = id;
            button.setAttribute('aria-label', `${moveToSelected ? 'Legg til' : 'Fjern'} ${module.name || 'modul'}${moveToSelected ? '' : ' fra bedriften'}`);
            const text = portalText(button, 'span', '', 'module-choice-copy');
            portalText(text, 'strong', module.name || 'Uten navn');
            if (module.description) portalText(text, 'span', module.description, 'module-description');
            const price = portalText(text, 'span', `${decimalText(module.price_monthly)} ${module.price_currency || ''} / mnd`, 'module-price');
            price.title = 'Katalogpris per måned';
            portalText(button, 'span', moveToSelected ? 'Legg til' : 'Fjern', 'module-choice-action');
            button.addEventListener('click', () => {
                const selection = selectedForEditing();
                if (moveToSelected) selection.add(id); else selection.delete(id);
                focusAfterPreview = {id, target: moveToSelected ? 'selected' : 'available'};
                renderModuleEditor(id, moveToSelected ? 'selected' : 'available');
                queueSubscriptionPreview();
            });
            target.appendChild(button);
        });
    }
    function renderModuleEditor(focusModuleId = null, focusTarget = null) {
        const selection = selectedForEditing();
        const available = catalogModules.filter(module => !selection.has(String(module.id)));
        const selected = catalogModules.filter(module => selection.has(String(module.id)));
        renderModulePane(availableList, available, true);
        renderModulePane(selectedList, selected, false);
        const activeQuote = companyMetadata.get(String(editingCompanyId)) ||
            (previewQuote?.companies || []).find(company => String(company.company_id) === String(editingCompanyId));
        const active = Number(activeQuote?.active_users) || 0;
        activeUsers.textContent = activeQuote?.active_users === null || activeQuote?.active_users === undefined
            ? 'Aktive brukere ikke oppgitt' : `${active} aktive brukere`;
        const limit = userLimits.has(String(editingCompanyId)) ? userLimits.get(String(editingCompanyId)) : activeQuote?.max_users;
        userLimit.value = limit === null || limit === undefined ? '' : String(limit);
        userLimit.min = String(Math.max(0, active));
        userLimit.disabled = savingSubscription || subscriptionSaved || !editingCompanyId;
        const discounts = discountsFor(editingCompanyId);
        monthlyDiscountInput.value = discounts.monthly;
        setupDiscountInput.value = discounts.setup;
        monthlyDiscountInput.setCustomValidity('');
        setupDiscountInput.setCustomValidity('');
        discountHelp.textContent = discountHelpText;
        discountHelp.className = 'small text-muted mb-0';
        monthlyDiscountInput.disabled = savingSubscription || subscriptionSaved || !editingCompanyId;
        setupDiscountInput.disabled = savingSubscription || subscriptionSaved || !editingCompanyId;
        document.getElementById('companyGrantEditorName').textContent =
            portalCompanies.find(company => String(company.id) === String(editingCompanyId))?.name ||
            activeQuote?.name || 'Bedrift';
        if (focusModuleId !== null) {
            const pane = focusTarget === 'available' ? availableList : selectedList;
            const movedButton = Array.from(pane.querySelectorAll('button[data-module-id]'))
                .find(button => button.dataset.moduleId === String(focusModuleId));
            (movedButton || pane).focus({preventScroll: true});
        }
    }
    function renderCompanySwitcher(companies) {
        const previous = editingCompanyId;
        companySwitcher.replaceChildren();
        companies.forEach(company => {
            const option = document.createElement('option');
            option.value = String(company.company_id);
            option.textContent = company.name || `Bedrift ${company.company_id}`;
            companySwitcher.appendChild(option);
        });
        if (!companies.length) {
            editingCompanyId = null;
            renderModuleEditor();
            return;
        }
        editingCompanyId = companies.some(company => String(company.company_id) === String(previous))
            ? String(previous) : String(companies[0].company_id);
        companySwitcher.value = editingCompanyId;
        const focus = focusAfterPreview;
        renderModuleEditor(focus?.id ?? null, focus?.target ?? null);
        focusAfterPreview = null;
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
        } else {
            portalText(card, 'div', 'Ingen eksisterende abonnement registrert.', 'small text-muted mb-1');
        }
        const setupCents = moneyCents(company.selected_setup_net_total);
        const monthlyCents = moneyCents(company.proposed_monthly);
        const total = setupCents === null || monthlyCents === null
            ? null : moneyFromCents(setupCents + monthlyCents);
        priceNode(card, 'Etablering', company.selected_setup_net_total, company.currency);
        priceNode(card, 'Månedlig', company.proposed_monthly, company.currency);
        const totalRow = priceNode(card, 'Total', total, company.currency);
        totalRow.title = 'Etablering + én månedlig betaling';
        if (Array.isArray(company.removed_module_ids) && company.removed_module_ids.length) {
            portalText(card, 'div', `${company.removed_module_ids.length} eksisterende ${company.removed_module_ids.length === 1 ? 'modul fjernes' : 'moduler fjernes'} fra tilgangen.`, 'small text-warning-emphasis mt-2');
        }
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
        hasNormalizedSelectionQuote = false;
        status.replaceChildren();
        portalText(status, 'div', 'Oppdaterer forhåndsvisningen …', 'small text-muted mb-2');
        clearQuoteContent();
        previewTimer = window.setTimeout(() => loadSubscriptionPreview(revision), 280);
    }
    async function loadSubscriptionPreview(revision) {
        const companyIds = Array.from(portalSelectedCompanies);
        const selections = {};
        companyIds.forEach(id => {
            const maxUsers = userLimits.has(id) ? userLimits.get(id) : userLimit.value;
            const discount = discountsFor(id);
            selections[id] = {
                module_ids: Array.from(moduleSelections.get(id) || []),
                max_users: maxUsers === null || maxUsers === undefined || maxUsers === '' ? null : Number(maxUsers),
                plan_setup_discount: discount.setup,
                plan_monthly_discount: discount.monthly
            };
        });
        const isInitial = !hasInitializedSelection;
        const moduleCount = Object.values(selections).reduce((count, selection) => count + selection.module_ids.length, 0);
        document.getElementById('companyGrantSummary').textContent =
            `${companyIds.length} bedrifter · ${moduleCount} valgte bedriftstilganger. Endringer vurderes per bedrift.`;
        availableList.setAttribute('aria-busy', 'true');
        selectedList.setAttribute('aria-busy', 'true');
        try {
            const request = {
                company_ids: companyIds,
                csrf_token: portalCsrf
            };
            if (!isInitial) request.selections = selections;
            const data = await portalPost('/saas_portal/api/preview_company_subscription.php', request);
            if (revision !== previewRevision) return;
            if (data.read_only !== true || typeof data.apply_available !== 'boolean') throw new Error('Svaret bekrefter ikke en gyldig prisforhåndsvisning.');
            const modules = Array.isArray(data.modules) ? data.modules : [];
            const companies = Array.isArray(data.companies) ? data.companies : [];
            previewQuote = data;
            if (!hasInitializedSelection) {
                companies.forEach(company => {
                    const id = String(company.company_id);
                    moduleSelections.set(id, new Set((company.module_ids || company.existing_module_ids || []).map(String)));
                    userLimits.set(id, parseUserLimit(company.max_users));
                    planDiscounts.set(id, {
                        monthly: discountValue(company.plan_monthly_discount),
                        setup: discountValue(company.plan_setup_discount)
                    });
                });
                hasInitializedSelection = true;
            } else {
                companies.forEach(company => {
                    const id = String(company.company_id);
                    if (!moduleSelections.has(id)) moduleSelections.set(id, new Set((company.existing_module_ids || []).map(String)));
                    if (!userLimits.has(id)) userLimits.set(id, parseUserLimit(company.max_users));
                    if (!planDiscounts.has(id)) {
                        planDiscounts.set(id, {
                            monthly: discountValue(company.plan_monthly_discount),
                            setup: discountValue(company.plan_setup_discount)
                        });
                    }
                });
            }
            companies.forEach(company => companyMetadata.set(String(company.company_id), company));
            hasNormalizedSelectionQuote = !isInitial;
            warnings.replaceChildren();
            catalogModules = modules;
            if (companies.some(company => {
                const selected = moduleSelections.get(String(company.company_id));
                const quoted = Array.isArray(company.module_ids) ? company.module_ids.map(String) : [];
                return !selected || selected.size !== quoted.length ||
                    quoted.some(id => !selected.has(id)) || new Set(quoted).size !== quoted.length;
            })) throw new Error('Prisberegningen samsvarer ikke med modulvalget. Ingen feilaktig totalsum kan lagres. Oppdater forhåndsvisningen.');
            renderCompanySwitcher(companies);
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
            if (!moduleCount) portalText(status, 'div', 'Ingen moduler valgt. Et tomt utvalg er en gyldig endring og fjerner alle modultilganger for bedriften.', 'small text-muted');
            if (!companies.length) portalText(quotes, 'div', 'Velg minst én bedrift for å se pris per bedrift.', 'portal-empty');
            updateSaveControls();
            if (isInitial) queueSubscriptionPreview();
        } catch (error) {
            if (revision !== previewRevision) return;
            quotes.replaceChildren();
            issuer.textContent = 'Ikke tilgjengelig';
            warnings.replaceChildren();
            previewQuote = null;
            acknowledgement.checked = false;
            acknowledgement.disabled = true;
            submit.disabled = true;
            if (!catalogModules.length) {
                availableList.replaceChildren();
                selectedList.replaceChildren();
                portalText(availableList, 'div', 'Katalogen kunne ikke lastes.', 'portal-empty');
            }
            portalFailure('companyGrantStatus', error.message, () => queueSubscriptionPreview());
        } finally {
            if (revision === previewRevision) {
                availableList.setAttribute('aria-busy', 'false');
                selectedList.setAttribute('aria-busy', 'false');
            }
        }
    }
    companySwitcher.addEventListener('change', () => {
        editingCompanyId = companySwitcher.value;
        renderModuleEditor();
    });
    userLimit.addEventListener('input', () => {
        if (!editingCompanyId) return;
        const metadata = companyMetadata.get(String(editingCompanyId));
        const active = Math.max(0, Number(metadata?.active_users) || 0);
        const raw = userLimit.value.trim();
        const parsed = raw === '' ? null : Number(raw);
        userLimits.set(String(editingCompanyId),
            parsed !== null && Number.isInteger(parsed) && parsed >= active ? parsed : null);
        queueSubscriptionPreview();
    });
    function handleDiscountInput(input, key) {
        if (!editingCompanyId) return;
        const raw = input.value.trim().replace(',', '.');
        if (raw === '') {
            input.setCustomValidity('');
            discountHelp.textContent = discountHelpText;
            discountHelp.className = 'small text-muted mb-0';
            planDiscounts.set(String(editingCompanyId), {...discountsFor(editingCompanyId), [key]: '0.00'});
            queueSubscriptionPreview();
            return;
        }
        if (!/^\d{1,3}(?:\.\d{1,2})?$/.test(raw) || !Number.isFinite(Number(raw)) || Number(raw) > 100) {
            input.setCustomValidity('Angi en rabatt mellom 0 og 100 med maks to desimaler.');
            discountHelp.textContent = input.validationMessage;
            discountHelp.className = 'small text-danger mb-0';
            acknowledgement.checked = false;
            updateSaveControls();
            return;
        }
        input.setCustomValidity('');
        discountHelp.textContent = discountHelpText;
        discountHelp.className = 'small text-muted mb-0';
        planDiscounts.set(String(editingCompanyId), {
            ...discountsFor(editingCompanyId),
            [key]: Number(raw).toFixed(2)
        });
        queueSubscriptionPreview();
    }
    monthlyDiscountInput.addEventListener('input', () => handleDiscountInput(monthlyDiscountInput, 'monthly'));
    setupDiscountInput.addEventListener('input', () => handleDiscountInput(setupDiscountInput, 'setup'));
    document.getElementById('grantConfirmButton').addEventListener('click', () => {
        modal.show();
    });
    submit.addEventListener('click', async () => {
        updateSaveControls();
        if (submit.disabled || savingSubscription || !previewQuote) return;
        const data = {
            company_ids: Array.from(portalSelectedCompanies),
            selections: Object.fromEntries(Array.from(portalSelectedCompanies, id => [id, {
                module_ids: Array.from(moduleSelections.get(id) || []),
                max_users: userLimits.get(id) ?? null,
                plan_setup_discount: discountsFor(id).setup,
                plan_monthly_discount: discountsFor(id).monthly
            }])),
            quote_hash: previewQuote.quote_hash,
            confirmed: true,
            csrf_token: portalCsrf
        };
        savingSubscription = true;
        updateSaveControls();
        renderModuleEditor();
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
            renderModuleEditor();
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
            hasNormalizedSelectionQuote = false;
            updateSaveControls();
        }
    });
    modalRoot.addEventListener('shown.bs.modal', () => {
        window.clearTimeout(previewTimer);
        previewRevision++;
        previewQuote = null;
        hasNormalizedSelectionQuote = false;
        subscriptionSaved = false;
        acknowledgement.checked = false;
        acknowledgement.disabled = true;
        submit.textContent = 'Bekreft og lagre';
        availableList.replaceChildren();
        selectedList.replaceChildren();
        portalText(availableList, 'div', '', 'portal-skeleton');
        portalText(selectedList, 'div', '', 'portal-skeleton');
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
