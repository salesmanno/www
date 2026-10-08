// Isolated UI contract test; it never calls the real portal API.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const runChromium = require('./run_chromium_fixture');
const root = path.resolve(__dirname, '..');
const dashboard = fs.readFileSync(path.join(root, 'pages/dashboard.php'), 'utf8');
const sectionStart = dashboard.indexOf('<section class="portal-panel global-users-panel"');
const sectionEnd = dashboard.indexOf('<div class="dashboard-bottom">', sectionStart);
assert(sectionStart >= 0 && sectionEnd > sectionStart, 'Global users dashboard section is missing');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'global-users-ui-'));
const setup = `
window.setInterval=()=>0;
const portalCsrf='global-ui-csrf';
let converted=false;
let deactivated=false;
let schemaReady=true;
const calls=[];
function portalPost(url,body){
 calls.push({url,body:structuredClone(body)});
 if(!url.endsWith('/global_users.php'))throw new Error('Unexpected endpoint');
 if(body.action==='list')return Promise.resolve({success:true,schema_ready:schemaReady,
  global_users:[{id:90,email:'saa@example.no',username:'saa-user',is_active:deactivated?0:1}],
  candidates:converted?[{id:2,company_id:12,company_name:'Nordlys Drift',email:'blocked@example.no',username:'blocked-user'}]:
   [{id:1,company_id:11,company_name:'Fjord Lab',email:'kandidat@example.no',username:'kandidat'},
    {id:2,company_id:12,company_name:'Nordlys Drift',email:'blocked@example.no',username:'blocked-user'}]});
 if(body.action==='preview'&&body.admin_id===1)return Promise.resolve({success:true,preview:{admin_id:1,email:'kandidat@example.no',username:'kandidat',company_name:'Fjord Lab',blockers:[],quote_hash:'quote-global-1'}});
 if(body.action==='preview')return Promise.resolve({success:true,preview:{admin_id:2,email:'blocked@example.no',username:'blocked-user',company_name:'Nordlys Drift',blockers:['MFA må gjennomgås'],quote_hash:null}});
 if(body.action==='convert'){converted=true;return Promise.resolve({success:true,message:'Global identitet opprettet.'});}
 if(body.action==='deactivate'){deactivated=true;return Promise.resolve({success:true,message:'Global innlogging deaktivert.'});}
 return Promise.resolve({success:false,message:'Ukjent handling'});
}
`;
const checks = `
document.addEventListener('DOMContentLoaded',async()=>{
 const output=document.getElementById('testResult');
 const expect=(value,message)=>{if(!value)throw new Error(message)};
 const wait=()=>new Promise(resolve=>setTimeout(resolve,80));
 try{
  await wait();
  const candidates=document.getElementById('globalUsersCandidates');
  const preview=document.getElementById('globalUserPreview');
  const accountList=document.getElementById('globalUsersList');
  expect(candidates.textContent.includes('Fjord Lab'),'Candidate tenant identity missing');
  expect(accountList.textContent.includes('Aktiv global innlogging'),'Existing global identity missing');
  expect(!document.querySelector('input[type=password]'),'UI requests or exposes a password');
  const candidateButton=Array.from(candidates.querySelectorAll('button')).find(button=>button.textContent.includes('Forhåndsvis'));
  expect(candidateButton,'Preview action missing from candidate');
  candidateButton.click();await wait();
  expect(preview.textContent.includes('alle bedrifter')&&preview.textContent.includes('deaktiveres'),'Global access consequences are unclear');
  expect(preview.textContent.includes('Fjord Lab')&&!preview.textContent.includes('password'),'Preview lacks safe source identity details');
  const confirm=Array.from(preview.querySelectorAll('button')).find(button=>button.textContent.includes('Bekreft og opprett'));
  expect(confirm&&!confirm.disabled,'Valid preview cannot be explicitly confirmed');
  confirm.click();await wait();await wait();
  const conversion=calls.find(call=>call.body.action==='convert');
  expect(conversion&&conversion.body.admin_id===1&&conversion.body.quote_hash==='quote-global-1'&&conversion.body.confirmed===true,'Conversion did not use the preview quote and explicit confirmation');
  expect(candidates.textContent.includes('blocked-user'),'Candidate list did not refresh after conversion');
  const blockedButton=Array.from(candidates.querySelectorAll('button')).find(button=>button.textContent.includes('Forhåndsvis'));
  expect(blockedButton,'Blocked candidate preview action missing');
  blockedButton.click();await wait();
  expect(preview.textContent.includes('MFA må gjennomgås')&&!Array.from(preview.querySelectorAll('button')).some(button=>button.textContent.includes('Bekreft og opprett')),'MFA blocker did not prevent conversion');
  const account=candidates.closest('.global-users-panel').querySelector('#globalUsersList');
  const deactivate=Array.from(account.querySelectorAll('button')).find(button=>button.textContent.includes('Deaktiver global konto'));
  deactivate.click();
  const confirmDeactivate=Array.from(account.querySelectorAll('button')).find(button=>button.textContent.includes('Bekreft deaktivering'));
  expect(confirmDeactivate,'Deactivation was not explicit');
  confirmDeactivate.click();await wait();await wait();
  const deactivation=calls.find(call=>call.body.action==='deactivate');
  expect(deactivation&&deactivation.body.global_id===90&&deactivation.body.confirmed===true,'Global account deactivation contract mismatch');
  schemaReady=false;document.getElementById('globalUsersRefresh').click();await wait();
  expect(document.getElementById('globalUsersSchemaNotice').textContent.includes('databasemodell mangler'),'Missing schema state is not explained');
  expect(Array.from(document.querySelectorAll('#globalUsersCandidates button,#globalUsersList button')).every(button=>button.disabled),'Actions remained available without schema');
  output.dataset.status='PASS';output.textContent='PASS';
 }catch(error){output.dataset.status='FAIL';output.textContent=error.message+' '+error.stack;}
});
`;
(async () => {
try {
    const css = fs.readFileSync(path.join(os.tmpdir(), 'portal-workspace-bootstrap-5.3.0.css'), 'utf8');
    const bootstrapJs = fs.readFileSync(path.join(os.tmpdir(), 'portal-workspace-bootstrap-5.3.0.js'), 'utf8');
    const html = `<!doctype html><html lang="no"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><script>${setup}</script></head><body>
    ${dashboard.slice(sectionStart, sectionEnd)}
    <div id="testResult"></div><script>${bootstrapJs}</script><script>${fs.readFileSync(path.join(root,'assets/global-users.js'),'utf8')}</script><script>${checks}</script></body></html>`;
    const file = path.join(directory, 'global-users.html');
    fs.writeFileSync(file, html);
    const result = await runChromium(['--headless','--no-sandbox','--disable-gpu','--allow-file-access-from-files','--disable-dev-shm-usage',
        '--disable-background-networking','--disable-extensions','--disable-default-apps','--no-first-run',
        `--user-data-dir=${path.join(directory,'profile')}`,'--dump-dom','--virtual-time-budget=30000','--window-size=1200,900',`file://${file}`],
        {timeoutMs:30000,maxBuffer:2*1024*1024});
    assert(!result.error && result.status === 0, `Chromium failed: ${result.error?.message || result.stderr || result.status}`);
    const outcome=result.stdout.match(/<div id="testResult"[^>]*>([\s\S]*?)<\/div>/);
    assert(result.stdout.includes('data-status="PASS"'), `Global users UI test failed: ${outcome?outcome[1]:'No result'}`);
    console.log('Global user UI passed: preview/confirmation, MFA blocker, deactivation, refresh and missing-schema states.');
} finally {
    fs.rmSync(directory, {recursive:true,force:true});
}
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
