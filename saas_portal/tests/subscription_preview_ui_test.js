// Tests actual modal markup/script with isolated responses, not an app authentication bypass.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'subscription-ui-'));
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const dashboard = read('pages/dashboard.php');
const start = dashboard.indexOf('<div class="modal fade" id="companyGrantModal"');
const end = dashboard.indexOf('<script src="https://cdn.jsdelivr.net/npm/bootstrap', start);
assert(start >= 0 && end > start, 'Subscription modal missing');
const setup = `
const portalCsrf='test-csrf';
let fixtureFail=false;
let fixtureMissing=false;
let fixtureSlow=false;
const calls=[];
function portalText(parent,tag,text,classes=''){const node=document.createElement(tag);node.textContent=text;node.className=classes;parent.appendChild(node);return node;}
function portalFailure(id,message,retry){const node=document.getElementById(id);node.replaceChildren();portalText(node,'p',message);if(retry){const b=portalText(node,'button','Prøv igjen');b.onclick=retry;}}
function showActivityHistory(){}
function loadActivityStream(){}
function loadActiveSessions(){}
let activityPaused=false;
async function portalPost(url,body){
 calls.push({url,body:structuredClone(body)});
 const slow=fixtureSlow;fixtureSlow=false;
 if(slow) await new Promise(resolve=>setTimeout(resolve,1200));
 if(fixtureFail)throw new Error('Midlertidig utilgjengelig');
 const added=body.module_ids.includes('M2');
 return {success:true,read_only:true,apply_available:false,price_schema_ready:!fixtureMissing,
 issuer:{id:'issuer',name:'Salesman'},warnings:fixtureMissing?['Priskolonner mangler']:[],
 modules:[{id:'M1',module_key:'events',name:'Arrangement',description:null,price_monthly:'100.00',price_setup:'200.00',price_currency:'NOK'},
 {id:'M2',module_key:'sales',name:'<img src=x onerror="window.injected=true"> Salg',description:'Beskrivelse',price_monthly:fixtureMissing?null:'50.00',price_setup:'25.00',price_currency:'NOK'}],
 companies:[{company_id:'A',name:'Alpha',module_ids:added?['M1','M2']:['M1'],new_module_ids:added?['M2']:[],
 features:added?'events,sales':'events',monthly_total:fixtureMissing?null:(added?'150.00':'100.00'),setup_total:added?'25.00':'0.00',currency:'NOK',
 missing_prices:fixtureMissing?[{module_id:'M2',name:'Salg',fields:['price_monthly']}]:[],
 blockers:[],notes:['En bedriftsspesifikk plan må klargjøres.'],
 subscription:{status:'active',billing_cycle:'monthly',plan_name:'Avtale',agreed_monthly:'75.00',currency:'NOK'}}]};
}
`;
const checks = `
portalCompanies=[{id:'A',name:'Alpha',is_active:1}];
portalSelectedCompanies.add('A');
document.addEventListener('DOMContentLoaded',async()=>{
 const output=document.getElementById('testResult');
 const expect=(value,label)=>{if(!value)throw new Error(label)};
 const settle=(ms=600)=>new Promise(resolve=>setTimeout(resolve,ms));
 try {
  document.getElementById('grantConfirmButton').click();await settle(800);
  const modal=document.getElementById('companyGrantModal');
  const quotes=document.getElementById('companyGrantCompanyQuotes');
  const list=document.getElementById('companyGrantModules');
  const submit=document.getElementById('companyGrantSubmit');
  expect(modal.classList.contains('show'),'Modal not opened');
  expect(quotes.textContent.includes('100,00')&&quotes.textContent.includes('75,00'),'Current prices not distinct');
  expect(quotes.textContent.includes('bedriftsspesifikk plan'),'Subscription setup note missing');
  expect(document.getElementById('companyGrantIssuer').textContent.includes('Salesman'),'Issuer missing');
  expect(!window.injected&&!list.querySelector('img'),'Stored HTML executed');
  const choose=()=>list.querySelector('input[value="M2"]');
  choose().click();await settle();
  expect(quotes.textContent.includes('150,00')&&quotes.textContent.includes('25,00'),'Server quote not refreshed');
  expect(submit.disabled,'Saving enabled before approval');
  fixtureSlow=true;
  choose().click();await settle(350);
  choose().click();await settle(1800);
  expect(choose().checked&&quotes.textContent.includes('150,00'),'Stale quote replaced current selection');
  fixtureMissing=true;choose().click();await settle();
  expect(quotes.textContent.includes('Ikke konfigurert')&&quotes.textContent.includes('Manglende prisinformasjon'),'Missing price treated as free');
  fixtureFail=true;choose().click();await settle();
  expect(quotes.textContent==='','Failure left stale price');
  const retry=document.getElementById('companyGrantStatus').querySelector('button');
  expect(retry,'Retry absent');
  fixtureFail=false;fixtureMissing=false;retry.click();await settle();
  expect(quotes.textContent.includes('150,00'),'Retry did not preserve selection');
  expect(list.getAttribute('aria-busy')==='false','Loading stuck');
  expect(calls.every(call=>call.url.endsWith('/preview_company_subscription.php')&&call.body.csrf_token==='test-csrf'),'Mutation or unprotected request sent');
  const bounds=modal.querySelector('.modal-dialog').getBoundingClientRect();
  expect(bounds.left>=0&&bounds.right<=innerWidth+1,'Modal overflow');
  expect(modal.scrollWidth<=innerWidth+1,'Content overflow');
  output.dataset.status='PASS';output.textContent='PASS';
 }catch(error){output.dataset.status='FAIL';output.textContent=error.message;}
});
`;
try {
    const css = fs.readFileSync(path.join(os.tmpdir(), 'portal-workspace-bootstrap-5.3.0.css'), 'utf8');
    const js = fs.readFileSync(path.join(os.tmpdir(), 'portal-workspace-bootstrap-5.3.0.js'), 'utf8');
    const html = `<!doctype html><html lang="no"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><script>${setup}</script></head><body>
        <button id="grantModeButton">Tildel moduler</button><button id="grantConfirmButton">Velg moduler</button>
        <input id="activitySearch"><button id="sessionRefreshButton">Oppdater økter</button>
        ${dashboard.slice(start,end)}<script>${js}</script><script>${read('assets/dashboard-workspace.js')}</script>
        <div id="testResult"></div><script>${checks}</script></body></html>`;
    const file = path.join(directory, 'subscription.html');
    fs.writeFileSync(file, html);
    for (const [width,height] of [[1360,900],[375,812]]) {
        const result = spawnSync('chromium', ['--headless','--no-sandbox','--disable-gpu','--allow-file-access-from-files',
            '--disable-dev-shm-usage','--dump-dom','--virtual-time-budget=12000',`--window-size=${width},${height}`,`file://${file}`],
            {encoding:'utf8',timeout:45000,maxBuffer:2*1024*1024});
        assert(!result.error && result.status === 0, 'Chromium failed');
        const outcome = result.stdout.match(/<div id="testResult"[^>]*>([\s\S]*?)<\/div>/);
        assert(result.stdout.includes('data-status="PASS"'), `${width}px: ${outcome ? outcome[1] : 'No result'}`);
    }
    console.log('Subscription UI passed: desktop/mobile, estimates, stale responses, missing prices, retry, no writes.');
} finally {
    fs.rmSync(directory, {recursive:true,force:true});
}
