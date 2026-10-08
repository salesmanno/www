// Real modal markup and scripts, isolated Chromium responses. No application login bypass.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'module-preview-ui-'));
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
function bootstrapAsset(kind) {
    const cached = path.join(os.tmpdir(), `portal-workspace-bootstrap-5.3.0.${kind}`);
    if (!fs.existsSync(cached)) {
        const result = spawnSync('curl', ['--fail', '--silent', '--show-error', '--max-time', '30',
            `https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/${kind === 'css' ? 'css/bootstrap.min.css' : 'js/bootstrap.bundle.min.js'}`],
            {encoding: 'utf8', timeout: 35000, maxBuffer: 1024 * 1024});
        if (result.error || result.status !== 0) throw new Error('Existing Bootstrap asset unavailable');
        fs.writeFileSync(cached, result.stdout);
    }
    return fs.readFileSync(cached, 'utf8');
}
const dashboard = read('pages/dashboard.php');
const start = dashboard.indexOf('<div class="modal fade" id="moduleTransitionPreviewModal"');
const end = dashboard.indexOf('<script src="/saas_portal/assets/portal-workspace.js">', start);
assert(start !== -1 && end > start, 'Real preview modal missing');
const trigger = dashboard.match(/<button[^>]*id="moduleTransitionPreviewButton"[^>]*>[\s\S]*?<\/button>/);
assert(trigger, 'Real preview trigger missing');
const preview = {
    read_only: true, apply_available: false, summary: {company_count: 2, ready_company_count: 1},
    companies: [
        {company_id: 'A', company_name: '<img src=x onerror="window.injected=true"> Alpha', is_active: true,
            proposed_action: 'copy_shared_plan', proposed_features: 'rental,sales', module_names: ['Utleie','Salg'],
            excluded_grant_count: 2, blockers: []},
        {company_id: 'B', company_name: 'Beta', is_active: false,
            proposed_action: 'requires_subscription', proposed_features: '', module_names: [],
            excluded_grant_count: 0, blockers: ['Abonnementsutsteder må velges.']}
    ],
    global_candidates: [
        {tenant_admin_id: 'user', username: '<script>window.injected=true</script>', email: 'user@example.test',
            previous_company_name: 'Alpha', requires_individual_approval: true,
            blockers: ['Kontoene må avklares uten å overskrive innloggingen.']}
    ]
};
const fixture = JSON.stringify(preview).replace(/</g, '\\u003c');
const setup = `
const fixturePreview=${fixture};
let fixtureFail=false;
const requests=[];
window.fetch=async (url,options={}) => {
 requests.push({url,method:options.method||'GET'});
 if(fixtureFail) throw new Error('Forhåndsvisningen er midlertidig utilgjengelig.');
 return {ok:true,json:async()=>({success:true,preview:structuredClone(fixturePreview)})};
};
`;
const checks = `
document.addEventListener('DOMContentLoaded', async()=>{
 const result=document.getElementById('testResult');
 const expect=(value,message)=>{if(!value)throw new Error(message);};
 const settle=()=>new Promise(resolve=>setTimeout(resolve,200));
 try{
  const button=document.getElementById('moduleTransitionPreviewButton');
  expect(requests.length===0,'Preview loaded without asking');
  button.click();
  await settle();
  const modal=document.getElementById('moduleTransitionPreviewModal');
  const content=document.getElementById('moduleTransitionPreviewContent');
  expect(modal.classList.contains('show'),'Modal did not open');
  expect(content.textContent.includes('1 av 2 bedrifter'),'Summary missing');
  expect(content.textContent.includes('Kopier delt plan før endring'),'Shared plan not shown');
  expect(content.textContent.includes('rental,sales'),'CSV features missing');
  expect(content.textContent.includes('Abonnementsutsteder må velges.'),'Blocker missing');
  expect(content.textContent.includes('forblir inaktiv'),'Inactive company unclear');
  expect(content.textContent.includes('2 opphevede eller inaktive'),'Revoked grant exclusion missing');
  expect(content.textContent.includes('Ingen konto får global rolle automatisk'),'Global role approval unclear');
  expect(!window.injected&&!modal.querySelector('img,script'),'Stored HTML executed');
  expect(content.getAttribute('aria-busy')==='false','Loading state stuck');
  expect(!content.querySelector('input,button[type=submit]'),'Unexpected mutation control');
  const dialog=modal.querySelector('.modal-dialog').getBoundingClientRect();
  expect(dialog.left>=0&&dialog.right<=innerWidth+1,'Dialog overflows viewport');
  expect(modal.querySelector('.modal-title').textContent.includes('eldre tilganger'),'Modal title missing');
  fixtureFail=true;
  document.getElementById('moduleTransitionPreviewRefresh').click();
  await settle();
  expect(content.textContent==='','Failed refresh left stale approval data');
  const status=document.getElementById('moduleTransitionPreviewStatus');
  expect(status.textContent.includes('utilgjengelig'),'Failure message missing');
  const retry=status.querySelector('button');
  expect(retry&&retry.textContent==='Prøv igjen','Retry missing');
  fixtureFail=false;
  retry.click();
  await settle();
  expect(content.textContent.includes('1 av 2 bedrifter'),'Retry failed');
  expect(requests.every(request=>request.method==='GET'&&request.url.endsWith('preview_module_transition.php')),'Preview performed writes');
  expect(content.getAttribute('aria-busy')==='false','Retry loading state stuck');
  result.dataset.status='PASS';
  result.textContent='PASS';
 }catch(error){result.dataset.status='FAIL';result.textContent=error.message;}
});
`;
try {
    const html = `<!doctype html><html lang="no"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <style>${bootstrapAsset('css')}</style><script>${setup}</script></head><body>
        ${trigger[0]}${dashboard.slice(start,end)}
        <script>${bootstrapAsset('js')}</script><script>${read('assets/portal-workspace.js')}</script>
        <script>${read('assets/module-transition-preview.js')}</script><div id="testResult"></div><script>${checks}</script></body></html>`;
    const file = path.join(directory, 'preview.html');
    fs.writeFileSync(file, html);
    for (const [width,height] of [[1360,900],[375,812]]) {
        const result = spawnSync('chromium', ['--headless','--no-sandbox','--disable-gpu','--allow-file-access-from-files',
            '--disable-dev-shm-usage','--dump-dom','--virtual-time-budget=12000',`--window-size=${width},${height}`,`file://${file}`],
            {encoding:'utf8',timeout:45000,maxBuffer:2*1024*1024});
        assert(!result.error && result.status===0, 'Chromium failed: '+(result.error||result.stderr));
        const outcome=result.stdout.match(/<div id="testResult"[^>]*>([\s\S]*?)<\/div>/);
        assert(result.stdout.includes('data-status="PASS"'), `${width}px: ${outcome ? outcome[1] : 'No result'}`);
    }
    console.log('PASS: module transition preview UI — desktop/mobile, safe text, blockers, GET-only, retry.');
} finally { fs.rmSync(directory,{recursive:true,force:true}); }
