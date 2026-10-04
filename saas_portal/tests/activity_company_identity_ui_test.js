// Run: node saas_portal/tests/activity_company_identity_ui_test.js
// Tests the real activity renderer in isolated HTML, not a bypass of app login.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const source = fs.readFileSync(path.join(__dirname, '../pages/dashboard.php'), 'utf8');
const styles = source.match(/<style>([\s\S]*?)<\/style>/)[1];
const renderer = source.slice(
    source.indexOf('        function safeActivityImageUrl('),
    source.indexOf('        // Last aktive sessions')
);
const filterSetup = source.slice(
    source.indexOf('        const activityTypeLabels = {'),
    source.indexOf('        // Slider for synlighet')
);
const history = source.slice(
    source.indexOf('        // Filter aktiviteter'),
    source.indexOf('        // Last aktivitetshistorikk')
);
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'activity-identity-ui-'));
const file = path.join(dir, 'test.html');
const assertions = `
function expect(value, message) { if (!value) throw new Error(message); }
const feed = document.getElementById('activityFeed');
const select = document.getElementById('eventTypeFilter');
const sample = {company_name:'Firma <script>bad()</scr'+'ipt>',subdomain:'alpha',
    company_logo_url:'/logo.png',action_type:'sale',actor_display:'Admin',
    action_summary:'Test <img onerror=bad()>',created_at:'2026-10-04T10:00:00',
    visibility_seconds:120};
try {
    expect(safeActivityImageUrl('javascript:alert(1)') === '', 'javascript image allowed');
    expect(safeActivityImageUrl('data:image/svg+xml,bad') === '', 'data image allowed');
    expect(safeActivityImageUrl('file:///tmp/bad.png') === '', 'file image allowed');
    expect(safeActivityImageUrl('/logo.png') === '/logo.png', 'relative image rejected');
    expect(safeActivityImageUrl('https://example.test/logo.png') !== '', 'https image rejected');
    const row = renderActivityItem(sample, feed);
    expect(row.querySelector('.activity-company-name').textContent === sample.company_name, 'name escaping');
    expect(row.querySelector('.activity-company-domain').textContent === 'alpha.salesman.no', 'domain format');
    expect(!row.querySelector('script') && !row.querySelector('a'), 'injected markup or linked domain');
    const image = row.querySelector('.activity-company-logo img');
    expect(image && image.alt === 'Logo for ' + sample.company_name, 'accessible logo missing');
    image.dispatchEvent(new Event('error'));
    expect(!row.querySelector('.activity-company-logo img'), 'broken image remains');
    expect(!row.querySelector('.activity-company-logo i').hidden, 'broken-image fallback hidden');
    const old = createActivityElement({...sample, company_logo_url:null});
    expect(!old.querySelector('img') && old.querySelector('.bi-building'), 'missing-logo fallback');
    const unsafe = createActivityElement({...sample, company_logo_url:'javascript:bad()'});
    expect(!unsafe.querySelector('img'), 'unsafe image created');
    allActivities = [
        sample, {...sample,action_type:'company_deleted'},
        {...sample,action_type:'company_action'},
        {...sample,action_type:'login'},
        {...sample,action_type:'sale'}
    ];
    updateActivityTypeFilter();
    const optionValues = Array.from(select.options, option => option.value);
    expect(optionValues.includes('company_action') && optionValues.includes('login'), 'saved activity types missing from filters');
    expect(!optionValues.includes('staff_deleted'), 'filter offers a type without any events');
    expect(select.options[0].textContent === 'Alle hendelser (5)', 'total event count incorrect');
    expect(Array.from(select.options).find(option => option.value === 'sale').textContent === 'Salg registrert (1)', 'per-type event count incorrect');
    expect(getActivityTypeLabel('custom_action') === 'Custom action', 'unknown type label fallback');
    currentFilter = 'missing_type';
    updateActivityTypeFilter();
    expect(currentFilter === 'all' && select.value === 'all', 'unavailable saved filter was not reset');
    currentFilter = 'sale';
    showActivityHistory();
    expect(feed.querySelectorAll('.activity-item').length === 1, 'history filter changed');
    currentFilter = 'all';
    const before = feed.querySelectorAll('.activity-item').length;
    activityQueue = [sample];
    displayNextActivity();
    expect(feed.querySelectorAll('.activity-item').length === before + 1, 'live insertion erased history');
    expect(feed.firstChild.querySelector('.activity-company-domain').textContent === 'alpha.salesman.no', 'live identity differs');
    activityPaused = true; activityQueue = [sample]; displayNextActivity();
    expect(activityQueue.length === 1, 'pause shifted queue');
    feed.appendChild(createActivityElement({...sample,
        company_name:'VeryLongCompanyName'.repeat(12),subdomain:'longsubdomain'.repeat(12)}));
    expect(document.documentElement.scrollWidth <= window.innerWidth, 'narrow viewport overflows');
    document.body.dataset.result = 'PASS';
} catch (error) {
    document.body.dataset.result = 'FAIL';
    document.body.appendChild(document.createTextNode(error.message));
}`;
try {
    fs.writeFileSync(file, `<!doctype html><meta charset="utf-8">
        <base href="https://127.0.0.1:9/"><style>
        ${styles}
        .d-flex{display:flex}.align-items-start{align-items:flex-start}
        .flex-grow-1{flex-grow:1}body{margin:0;padding:12px;box-sizing:border-box}
        </style><select id="eventTypeFilter"><option value="all">Alle hendelser</option></select>
        <button id="activity-tab"></button><div id="activityFeed"></div><script>
        let activityPaused=false,activityQueue=[],visibilitySeconds=30,currentFilter='all',allActivities=[];
        ${filterSetup}${history}${renderer}${assertions}</script>`);
    for (const width of [390, 1280]) {
        const result = spawnSync('chromium', [
            '--headless', '--no-sandbox', '--disable-gpu', '--no-proxy-server',
            '--dump-dom', '--virtual-time-budget=500', `--window-size=${width},900`,
            `file://${file}`
        ], {encoding: 'utf8', timeout: 20000, maxBuffer: 2 * 1024 * 1024});
        if (result.error || result.status !== 0 || !result.stdout.includes('data-result="PASS"')) {
            const failure = result.stdout.match(/<\/script>([^<]*)<\/body>/)?.[1];
            throw new Error(`Browser assertions failed at width ${width}: ${result.error || failure || result.stderr || 'missing PASS marker'}`);
        }
        console.log(`PASS width ${width}: dynamic type options/counts, safe labels, history filter, live insertion, logo fallback, pause, wrapping`);
    }
} finally {
    fs.rmSync(dir, {recursive: true, force: true});
}