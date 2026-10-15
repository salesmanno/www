<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    header('Location: /saas_portal/login.php?error=not_authorized');
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../auth/require_active.php';

if (empty($_SESSION['portal_csrf'])) {
    $_SESSION['portal_csrf'] = bin2hex(random_bytes(32));
}

$admin_name = $_SESSION['saas_admin_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-postmaler · Salesman</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/saas_portal/assets/portal-workspace.css" rel="stylesheet">
    <link href="/saas_portal/assets/email-templates.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="/saas_portal/pages/dashboard.php">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Salesman <span class="fw-normal opacity-75">/ tilbake til oversikt</span></span>
            </a>
            <div class="ms-auto d-flex align-items-center gap-3">
                <span class="text-white small"><i class="bi bi-person-circle me-1" aria-hidden="true"></i><?= htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8') ?></span>
                <a href="/saas_portal/auth/logout.php" class="btn btn-outline-light btn-sm">Logg ut</a>
            </div>
        </div>
    </nav>

    <main class="container email-workshop">
        <header class="workspace-heading">
            <div>
                <div class="workspace-eyebrow">Salg / e-postinnhold</div>
                <h2>E-postmalverksted</h2>
                <p class="workshop-intro">Tilpass transaksjonsmaler én gang, og fordel eksplisitte kopier til bedriftene som skal bruke dem.</p>
            </div>
            <div class="workspace-actions">
                <button type="button" class="btn btn-primary-custom" id="newTemplateButton">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Ny mal
                </button>
                <button type="button" class="btn btn-outline-secondary" id="refreshTemplatesButton">Oppdater bibliotek</button>
            </div>
        </header>

        <div class="workshop-ribbon">
            <i class="bi bi-shield-check" aria-hidden="true"></i>
            <span><strong>Kopier, ikke synkroniser.</strong> Bedriftskopier er selvstendige. Endringer går aldri på tvers uten en eksplisitt handling.</span>
        </div>
        <div id="workshopStatus" class="workshop-status" role="status" aria-live="polite"></div>

        <div class="workshop-layout">
            <aside class="workshop-library" aria-labelledby="templateLibraryTitle">
                <div class="workshop-library-head">
                    <div class="workshop-title-row">
                        <h3 id="templateLibraryTitle">Malbibliotek</h3>
                        <span class="workshop-counter" id="templateCount" aria-live="polite">—</span>
                    </div>
                    <div class="workshop-filters">
                        <div>
                            <label class="visually-hidden" for="templateSearch">Søk etter mal</label>
                            <input id="templateSearch" class="form-control form-control-sm" type="search" placeholder="Søk på navn, nøkkel …" autocomplete="off">
                        </div>
                        <div>
                            <label class="visually-hidden" for="templateOwnerFilter">Filtrer på eier</label>
                            <select id="templateOwnerFilter" class="form-select form-select-sm">
                                <option value="all">Alle eiere</option>
                                <option value="library">SaaS-bibliotek</option>
                            </select>
                        </div>
                        <div>
                            <label class="visually-hidden" for="templateUsageFilter">Filtrer på malnøkkel</label>
                            <input id="templateUsageFilter" class="form-control form-control-sm workshop-mono" type="search" placeholder="Bruk / nøkkel (valgfritt)" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div id="templateList" class="workshop-list" aria-label="E-postmaler" aria-busy="true">
                    <div class="workshop-skeleton" aria-label="Laster maler"></div>
                    <div class="workshop-skeleton" aria-hidden="true"></div>
                    <div class="workshop-skeleton" aria-hidden="true"></div>
                </div>
            </aside>

            <div class="workshop-surface">
                <section class="workshop-editor" aria-labelledby="editorTitle">
                    <div class="workshop-section-heading">
                        <div>
                            <h3 id="editorTitle">Velg en mal</h3>
                            <p id="editorDescription">Åpne en eksisterende mal eller start med en ny.</p>
                        </div>
                        <div class="workshop-heading-actions">
                            <span id="ownerBadge" class="workshop-owner-state" hidden></span>
                            <button type="button" id="deleteTemplateButton" class="btn btn-sm btn-outline-danger" disabled>Fjern mal</button>
                        </div>
                    </div>
                    <div id="linkedCopies" class="workshop-linked-copies" aria-live="polite" hidden></div>
                    <form id="templateForm" autocomplete="off">
                        <div class="workshop-form-grid">
                            <div class="workshop-field">
                                <label for="templateName">Malnavn</label>
                                <input id="templateName" name="name" class="form-control" type="text" maxlength="255" required disabled>
                            </div>
                            <div class="workshop-field">
                                <label for="templateKey">Malnøkkel</label>
                                <input id="templateKey" name="template_key" class="form-control workshop-mono" type="text" maxlength="100" required disabled>
                                <p class="workshop-help">Brukes av den transaksjonelle e-postflyten.</p>
                            </div>
                        </div>
                        <div class="workshop-form-grid">
                            <div class="workshop-field">
                                <label for="templateSubject">Emne</label>
                                <input id="templateSubject" name="subject_template" class="form-control" type="text" maxlength="500" required disabled>
                            </div>
                            <div class="workshop-field">
                                <label for="templateOwner">Eierskap for ny mal</label>
                                <select id="templateOwner" class="form-select" disabled>
                                    <option value="">SaaS-bibliotek</option>
                                </select>
                                <p id="ownerHelp" class="workshop-help">Eksisterende eierskap endres bare med «Flytt».</p>
                            </div>
                        </div>
                        <div class="workshop-field">
                            <label for="templateBody">HTML-innhold</label>
                            <textarea id="templateBody" name="body_template" class="form-control workshop-html-area workshop-mono" spellcheck="false" required disabled aria-describedby="bodyHelp"></textarea>
                            <p id="bodyHelp" class="workshop-help">HTML-redigering. Forhåndsvisningen leveres av serveren i en isolert ramme; rå HTML settes aldri inn i denne siden.</p>
                        </div>
                        <div class="workshop-field">
                            <span class="field-label">Tilgjengelige variabler</span>
                            <div id="variableButtons" class="workshop-variable-bar" aria-label="Sett inn variabel"></div>
                            <p class="workshop-help">Nøkkel og eksempelverdi vises for hver støttede variabel. Andre nøkler blir ikke automatisk koblet til hendelsesdata.</p>
                        </div>
                        <div class="workshop-editor-footer">
                            <label class="workshop-toggle" for="templateActive">
                                <input id="templateActive" class="form-check-input" type="checkbox" disabled>
                                <span>Mal aktiv</span>
                            </label>
                            <div>
                                <span id="saveState" class="workshop-saving-indicator align-self-center" aria-live="polite"></span>
                                <button type="button" id="previewButton" class="btn btn-outline-secondary btn-sm" disabled>Forhåndsvis</button>
                                <button type="submit" id="saveTemplateButton" class="btn btn-primary-custom btn-sm" disabled>Lagre mal</button>
                            </div>
                        </div>
                    </form>
                </section>

                <section class="workshop-preview" aria-labelledby="previewTitle">
                    <div class="workshop-preview-top">
                        <div>
                            <h3 id="previewTitle">Isolert forhåndsvisning</h3>
                            <div class="small text-muted">Eksempelverdier · ingen e-post sendes</div>
                        </div>
                        <div class="workshop-preview-controls" role="group" aria-label="Forhåndsvisningsstørrelse">
                            <button type="button" class="btn btn-sm btn-outline-secondary active" id="previewDesktop" aria-pressed="true">Skrivebord</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="previewMobile" aria-pressed="false">Mobil</button>
                        </div>
                    </div>
                    <div class="workshop-subject-preview"><strong>Emne:</strong> <span id="previewSubject">Forhåndsvisningen vises her etter behandling.</span></div>
                    <div id="previewFrameWrap" class="workshop-frame-wrap is-desktop">
                        <iframe id="emailPreviewFrame" title="Sanitert e-postmalforhåndsvisning" sandbox="" referrerpolicy="no-referrer"></iframe>
                    </div>
                    <div id="previewStatus" class="small text-muted mt-2" role="status" aria-live="polite"></div>
                </section>

                <section class="workshop-distribution" aria-labelledby="distributionTitle">
                    <div class="workshop-section-heading">
                        <div>
                            <h3 id="distributionTitle">Fordeling og eierskap</h3>
                            <p>Kopier er uavhengige. Velg mottakere og handling, og gjennomgå planen før den utføres.</p>
                        </div>
                    </div>
                    <div class="workshop-distribution-grid">
                        <div>
                            <div class="workshop-field mb-2">
                                <label for="companySearch">Søk etter bedrift</label>
                                <input id="companySearch" type="search" class="form-control form-control-sm" placeholder="Filtrer mottakerlisten" autocomplete="off">
                            </div>
                            <div id="companyTargets" class="workshop-target-list" aria-label="Velg bedrifter" aria-busy="true">
                                <div class="workshop-skeleton" aria-label="Laster bedrifter"></div>
                            </div>
                            <div class="form-check mt-2">
                                <input id="libraryTarget" class="form-check-input" type="checkbox">
                                <label for="libraryTarget" class="form-check-label small">SaaS-bibliotek som mål (kopier)</label>
                            </div>
                        </div>
                        <div>
                            <div class="workshop-operation-controls">
                                <div>
                                    <label class="form-label small fw-semibold" for="operationSelect">Handling</label>
                                    <select id="operationSelect" class="form-select form-select-sm" disabled>
                                        <option value="copy">Kopier til mottakere</option>
                                        <option value="move">Flytt mal</option>
                                        <option value="update">Oppdater valgte kopier</option>
                                        <option value="delete">Fjern valgte kopier</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label small fw-semibold" for="conflictSelect">Ved nøkkelkonflikt</label>
                                    <select id="conflictSelect" class="form-select form-select-sm" disabled>
                                        <option value="reject">Stopp og vis konflikt</option>
                                        <option value="replace">Erstatt eksisterende</option>
                                        <option value="rename">Gi nytt navn til den nye kopien</option>
                                    </select>
                                </div>
                            </div>
                            <div id="moveTargetWrap" class="workshop-field mt-2" hidden>
                                <label for="moveTarget">Flytt til én eier</label>
                                <select id="moveTarget" class="form-select form-select-sm"></select>
                            </div>
                            <div id="newKeyWrap" class="workshop-field mt-2" hidden>
                                <label for="newConflictKey">Ny nøkkel ved konflikt</label>
                                <input id="newConflictKey" class="form-control form-control-sm workshop-mono" type="text" maxlength="100">
                            </div>
                            <p id="operationHelp" class="workshop-distribution-note">Velg minst én mottaker. Ingen endring utføres før planen er gjennomgått og bekreftet.</p>
                            <div class="workshop-operation-actions">
                                <button type="button" id="runOperationButton" class="btn btn-primary-custom btn-sm" disabled>Gjennomgå plan</button>
                                <button type="button" id="removeTemplateButton" class="btn btn-outline-danger btn-sm" disabled>Fjern valgt mal</button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>

    <dialog id="operationDialog" class="workshop-dialog" aria-labelledby="operationDialogTitle">
        <div class="workshop-dialog-head">
            <h2 id="operationDialogTitle">Gjennomgå endring</h2>
            <button type="button" id="closePlanDialog" class="btn-close btn-close-white" aria-label="Lukk"></button>
        </div>
        <div class="workshop-dialog-body">
            <p id="planSummary" class="workshop-dialog-message"></p>
            <h3 class="h6">Berørte maler og eiere</h3>
            <ul id="planEffects" class="workshop-plan-list"></ul>
            <div id="planWarningsWrap" hidden>
                <h3 class="h6 mt-3">Viktig å vite</h3>
                <ul id="planWarnings" class="workshop-warning-list"></ul>
            </div>
            <div id="planError" role="alert" class="alert alert-danger mt-3" hidden></div>
        </div>
        <div class="workshop-dialog-foot">
            <button type="button" id="cancelPlanButton" class="btn btn-outline-secondary">Avbryt</button>
            <button type="button" id="applyPlanButton" class="btn btn-primary-custom">Bekreft og utfør</button>
        </div>
    </dialog>

    <script>
        window.emailWorkshopCsrf = <?= json_encode($_SESSION['portal_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="/saas_portal/assets/email-templates.js"></script>
</body>
</html>
