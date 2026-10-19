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

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$admin_name = $_SESSION['saas_admin_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prospekter · Salesman</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/saas_portal/assets/portal-workspace.css" rel="stylesheet">
    <link href="/saas_portal/assets/prospects.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="/saas_portal/pages/dashboard.php">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Salesman <span class="fw-normal opacity-75">/ tilbake til oversikt</span></span>
            </a>
            <div class="ms-auto d-flex align-items-center gap-3">
                <span class="text-white small"><i class="bi bi-person-circle me-1" aria-hidden="true"></i><?= htmlspecialchars((string)$admin_name, ENT_QUOTES, 'UTF-8') ?></span>
                <a href="/saas_portal/auth/logout.php" class="btn btn-outline-light btn-sm">Logg ut</a>
            </div>
        </div>
    </nav>

    <main class="container prospects-workspace">
        <header class="workspace-heading prospects-heading">
            <div>
                <div class="workspace-eyebrow">Salg / arbeidsflate</div>
                <h2>Prospekter</h2>
                <p>Et samlet arbeidsbord for å kvalifisere norske virksomheter og følge opp neste samtale.</p>
            </div>
        </header>

        <div class="prospects-context">
            <i class="bi bi-compass" aria-hidden="true"></i>
            <span><strong>Arbeidsliste for salg.</strong> Prospektopplysninger holdes adskilt fra live bedriftsadministrasjon.</span>
        </div>

        <section class="prospects-toolbar" aria-label="Søk og filtrer prospekter">
            <div class="prospects-control">
                <label for="prospectSearch">Søk i prospekter</label>
                <div class="prospects-search-wrap">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input id="prospectSearch" class="form-control form-control-sm" type="search" placeholder="Bedrift, org.nr. eller kontakt" autocomplete="off">
                </div>
            </div>
            <div class="prospects-control">
                <label for="prospectStatusFilter">Status</label>
                <select id="prospectStatusFilter" class="form-select form-select-sm">
                    <option value="">Alle statuser</option>
                </select>
            </div>
            <div class="prospects-control">
                <label for="prospectSort">Sorter etter</label>
                <select id="prospectSort" class="form-select form-select-sm">
                    <option value="updated">Sist oppdatert</option>
                    <option value="name">Bedriftsnavn</option>
                    <option value="contact">Kontaktperson</option>
                </select>
            </div>
            <div class="prospects-toolbar-actions">
                <button type="button" id="refreshProspects" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Oppdater</button>
                <button type="button" id="newProspect" class="btn btn-primary-custom btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nytt prospekt</button>
            </div>
        </section>

        <div id="prospectsGlobalAlert" class="alert alert-danger prospects-alert" role="alert" hidden>
            <span id="prospectsGlobalAlertText"></span>
            <button type="button" id="retryProspects" class="btn btn-sm btn-outline-danger ms-2">Prøv igjen</button>
        </div>

        <div class="prospects-layout">
            <section class="portal-panel prospects-list-panel" aria-labelledby="prospectsListTitle">
                <div class="prospects-list-head">
                    <h3 id="prospectsListTitle">Arbeidsliste</h3>
                    <span id="prospectCount" class="prospects-count" aria-live="polite">—</span>
                </div>
                <div id="prospectsList" class="prospects-list" aria-label="Prospektliste" aria-busy="true">
                    <div class="prospects-loading" aria-label="Laster prospekter">
                        <div class="prospect-skeleton"></div><div class="prospect-skeleton"></div><div class="prospect-skeleton"></div>
                    </div>
                </div>
                <nav class="prospects-pagination" aria-label="Sider i prospektlisten">
                    <button type="button" id="previousPage" class="btn btn-sm btn-outline-secondary" aria-label="Forrige side" disabled><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                    <span id="pageInfo" class="prospects-page-info" aria-live="polite">Side —</span>
                    <button type="button" id="nextPage" class="btn btn-sm btn-outline-secondary" aria-label="Neste side" disabled><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                </nav>
            </section>

            <section id="prospectEditor" class="portal-panel prospects-editor-panel" aria-labelledby="prospectEditorTitle">
                <div class="prospects-editor-empty" id="editorEmpty">
                    <i class="bi bi-card-list" aria-hidden="true"></i>
                    <strong>Velg et prospekt</strong>
                    <span>Åpne en bedrift i listen, eller opprett et nytt prospekt.</span>
                </div>
                <div id="editorContent" hidden>
                    <div class="prospects-editor-head">
                        <div>
                            <h3 id="prospectEditorTitle">Nytt prospekt</h3>
                            <p id="prospectEditorDescription">Registrer en norsk virksomhet for oppfølging.</p>
                            <div id="prospectContactLinks" class="prospects-contact-row" aria-label="Kontaktlenker"></div>
                        </div>
                        <div class="prospects-editor-actions">
                            <button type="button" id="registerContact" class="btn btn-outline-secondary btn-sm" disabled><i class="bi bi-telephone me-1" aria-hidden="true"></i>Registrer kontakt nå</button>
                        </div>
                    </div>
                    <form id="prospectForm" class="prospects-form" novalidate>
                        <div id="prospectFormAlert" class="alert alert-danger py-2 px-3 small" role="alert" hidden></div>
                        <div class="prospects-form-grid">
                            <div class="prospects-field">
                                <label for="orgNr">Organisasjonsnummer <span class="text-danger">*</span></label>
                                <input id="orgNr" name="org_nr" class="form-control" type="text" inputmode="numeric" autocomplete="off" maxlength="9" required aria-describedby="orgNrError">
                                <span id="orgNrError" class="prospects-field-error" hidden></span>
                            </div>
                            <div class="prospects-field">
                                <label for="companyName">Bedriftsnavn <span class="text-danger">*</span></label>
                                <input id="companyName" name="company_name" class="form-control" type="text" maxlength="255" required aria-describedby="companyNameError">
                                <span id="companyNameError" class="prospects-field-error" hidden></span>
                            </div>
                            <div class="prospects-field">
                                <label for="website">Nettsted</label>
                                <input id="website" name="website" class="form-control" type="text" inputmode="url" maxlength="255" placeholder="https://…" aria-describedby="websiteError">
                                <span id="websiteError" class="prospects-field-error" hidden></span>
                            </div>
                            <div class="prospects-field">
                                <label for="contactPerson">Kontaktperson</label>
                                <input id="contactPerson" name="contact_person" class="form-control" type="text" maxlength="255" aria-describedby="contactPersonError">
                                <span id="contactPersonError" class="prospects-field-error" hidden></span>
                            </div>
                            <div class="prospects-field">
                                <label for="contactEmail">E-post</label>
                                <input id="contactEmail" name="contact_email" class="form-control" type="email" maxlength="255" aria-describedby="contactEmailError">
                                <span id="contactEmailError" class="prospects-field-error" hidden></span>
                            </div>
                            <div class="prospects-field">
                                <label for="contactPhone">Telefon</label>
                                <input id="contactPhone" name="contact_phone" class="form-control" type="tel" maxlength="50" aria-describedby="contactPhoneError">
                                <span id="contactPhoneError" class="prospects-field-error" hidden></span>
                            </div>
                            <div class="prospects-field">
                                <label for="interestScore">Interessescore</label>
                                <input id="interestScore" name="interest_score" class="form-control" type="number" step="1" min="-32768" max="32767" placeholder="Valgfritt heltall" aria-describedby="interestScoreHelp interestScoreError">
                                <div id="interestScoreHelp" class="form-text">Valgfritt heltall. Ingen fast skala.</div>
                                <span id="interestScoreError" class="prospects-field-error" hidden></span>
                            </div>
                            <div class="prospects-field">
                                <label for="prospectStatus">Status</label>
                                <input id="prospectStatus" name="status" class="form-control" list="prospectStatuses" maxlength="50" aria-describedby="statusError">
                                <datalist id="prospectStatuses"></datalist>
                                <span id="statusError" class="prospects-field-error" hidden></span>
                            </div>
                            <div class="prospects-field">
                                <label for="prospectSource">Kilde</label>
                                <input id="prospectSource" name="source" class="form-control" type="text" maxlength="100" aria-describedby="sourceError">
                                <span id="sourceError" class="prospects-field-error" hidden></span>
                            </div>
                            <div class="prospects-field">
                                <label for="lastContactedAt">Sist kontaktet</label>
                                <input id="lastContactedAt" name="last_contacted_at" class="form-control" type="datetime-local" step="1" aria-describedby="lastContactedAtError">
                                <span id="lastContactedAtError" class="prospects-field-error" hidden></span>
                            </div>
                            <div class="prospects-field span-2">
                                <label for="interestTags">Interessetagger (JSON)</label>
                                <textarea id="interestTags" name="interest_tags" class="form-control" spellcheck="false" aria-describedby="interestTagsHelp interestTagsWarning interestTagsError"></textarea>
                                <div id="interestTagsHelp" class="form-text">Lim inn gyldig JSON. Verdien lagres uendret; bruk null eller la feltet stå tomt for ingen tagger.</div>
                                <div id="interestTagsWarning" class="prospects-tags-warning" role="status" hidden></div>
                                <span id="interestTagsError" class="prospects-field-error" hidden></span>
                            </div>
                            <div class="prospects-field span-2">
                                <label for="prospectNotes">Notater</label>
                                <textarea id="prospectNotes" name="notes" class="form-control" rows="4" aria-describedby="notesError"></textarea>
                                <span id="notesError" class="prospects-field-error" hidden></span>
                            </div>
                        </div>
                        <div class="prospects-form-footer">
                            <span id="prospectSaveState" class="prospects-save-state" role="status" aria-live="polite"></span>
                            <button type="submit" id="saveProspect" class="btn btn-primary-custom" disabled><i class="bi bi-check2 me-1" aria-hidden="true"></i>Lagre prospekt</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </main>

    <script>
        window.prospectsCsrf = <?= json_encode($_SESSION['portal_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="/saas_portal/assets/prospects.js" defer></script>
</body>
</html>
