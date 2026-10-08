// Tests actual modal markup/script with isolated responses, not an app authentication bypass.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const runChromium = require('./run_chromium_fixture');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'subscription-ui-'));
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const dashboard = read('pages/dashboard.php');
const start = dashboard.indexOf('<div class="modal fade" id="companyGrantModal"');
const end = dashboard.indexOf('<script src="https://cdn.jsdelivr.net/npm/bootstrap', start);
assert(start >= 0 && end > start, 'Subscription modal missing');
const setup = `
window.setInterval=()=>0;
window.addEventListener('error',event=>{window.testError=event.message+': '+event.filename+':'+event.lineno;});
const portalCsrf='test-csrf';
let fixtureFail=false;
let fixtureMissing=false;
let fixtureSlow=false;
let fixtureSaveFail=false;
let fixtureWrongSelection=false;
let fixturePrice='50.00';
const calls=[];
function portalText(parent,tag,text,classes=''){const node=document.createElement(tag);node.textContent=text;node.className=classes;parent.appendChild(node);return node;}
function portalFailure(id,message,retry){const node=document.getElementById(id);node.replaceChildren();portalText(node,'p',message);if(retry){const b=portalText(node,'button','Prøv igjen');b.onclick=retry;}}
function showActivityHistory(){}
function loadActivityStream(){}
function loadActiveSessions(){}
function loadCompanies(){}
function portalLogo(){}
function portalButton(parent,label,handler,classes=''){const button=portalText(parent,'button',label,classes);button.addEventListener('click',handler);return button;}
let activityPaused=false;
async function portalPost(url,body){
 calls.push({url,body:structuredClone(body)});
 if(url.endsWith('/save_company_subscription.php')){
  if(fixtureSaveFail)throw new Error('Pris endret. Oppdater oppsettet.');
  return {success:true,message:'Abonnement og modultilgang er lagret.'};
 }
 const slow=fixtureSlow;fixtureSlow=false;
 if(slow) await new Promise(resolve=>setTimeout(resolve,1200));
 if(fixtureFail)throw new Error('Midlertidig utilgjengelig');
  const ids=body.company_ids.map(String);
  const base={A:['M1'],B:['M2']};
   const limits={A:0,B:null};
  const companies=ids.map(id=>{
   const selection=body.selections?.[id];
   const chosen=selection?selection.module_ids.map(String):base[id];
    const active=id==='A'?0:5;
   const priceOf=moduleId=>moduleId==='M1'?100:Number(fixturePrice);
   const setupOf=moduleId=>moduleId==='M1'?200:25;
   const monthly=chosen.reduce((sum,moduleId)=>sum+priceOf(moduleId),0);
   const fullSetup=chosen.reduce((sum,moduleId)=>sum+setupOf(moduleId),0);
   const newly=chosen.filter(moduleId=>!base[id].includes(moduleId));
   const removed=base[id].filter(moduleId=>!chosen.includes(moduleId));
    const monthlyDiscount=Number(selection?.plan_monthly_discount??0);
    const setupDiscount=Number(selection?.plan_setup_discount??0);
    const discount=(gross,rate)=>Math.round((gross*rate/100+Number.EPSILON)*100)/100;
    const monthlyDiscountAmount=discount(monthly,monthlyDiscount);
    const setupDiscountAmount=discount(newly.reduce((sum,moduleId)=>sum+setupOf(moduleId),0),setupDiscount);
    const selectedSetupDiscountAmount=discount(fullSetup,setupDiscount);
    const setupGross=newly.reduce((sum,moduleId)=>sum+setupOf(moduleId),0);
    return {company_id:id,name:id==='A'?'Alpha':'Beta',existing_module_ids:id==='A'?['M1','M2']:base[id],module_ids:fixtureWrongSelection&&id==='A'?['M1','M2']:chosen,new_module_ids:newly,removed_module_ids:removed,
     monthly_total:monthly.toFixed(2),monthly_discount_amount:monthlyDiscountAmount.toFixed(2),proposed_monthly:(monthly-monthlyDiscountAmount).toFixed(2),
     selected_setup_total:fullSetup.toFixed(2),selected_setup_net_total:(fullSetup-selectedSetupDiscountAmount).toFixed(2),
     setup_gross_total:setupGross.toFixed(2),setup_discount_amount:setupDiscountAmount.toFixed(2),setup_total:(setupGross-setupDiscountAmount).toFixed(2),
     ...(window.testUnchanged&&!selection?{}:{plan_monthly_discount:selection?.plan_monthly_discount??'0.00',plan_setup_discount:selection?.plan_setup_discount??'0.00'}),
     currency:'NOK',max_users:selection&&Object.hasOwn(selection,'max_users')?selection.max_users:limits[id],active_users:active,
    missing_prices:fixtureMissing&&chosen.includes('M2')?[{module_id:'M2',name:'Salg',fields:['price_monthly']}]:[],blockers:[],notes:id==='A'?['En bedriftsspesifikk plan må klargjøres.']:[],
    subscription:{status:'active',billing_cycle:'monthly',plan_name:'Avtale',agreed_monthly:id==='A'?'75.00':'40.00',currency:'NOK'}};
  });
 return {success:true,read_only:true,apply_available:true,quote_hash:'a'.repeat(64),price_schema_ready:!fixtureMissing,
 issuer:{id:'issuer',name:'Salesman'},warnings:fixtureMissing?['Priskolonner mangler']:[],
 modules:[{id:'M1',module_key:'events',name:'Arrangement',description:null,price_monthly:'100.00',price_setup:'200.00',price_currency:'NOK'},
  {id:'M2',module_key:'sales',name:'<img src=x onerror="window.injected=true"> Salg',description:'Beskrivelse',price_monthly:fixtureMissing?null:fixturePrice,price_setup:'25.00',price_currency:'NOK'}],
  companies};
}
`;
const checks = `
portalCompanies=[{id:'A',name:'Alpha',is_active:1},{id:'B',name:'Beta',is_active:1}];
portalSelectedCompanies.add('A');
if(!window.testUnchanged)portalSelectedCompanies.add('B');
document.addEventListener('DOMContentLoaded',async()=>{
 const output=document.getElementById('testResult');
 const expect=(value,label)=>{if(!value)throw new Error(label)};
  const settle=(ms=380)=>new Promise(resolve=>setTimeout(resolve,ms));
  const waitFor=async predicate=>{for(let i=0;i<120&&!predicate();i++)await settle(50);return predicate()};
 try {
    document.getElementById('grantConfirmButton').click();
  const modal=document.getElementById('companyGrantModal');
  const quotes=document.getElementById('companyGrantCompanyQuotes');
  const submit=document.getElementById('companyGrantSubmit');
    expect(await waitFor(()=>modal.classList.contains('show')),'Modal not opened');
    expect(await waitFor(()=>calls.filter(call=>call.url.endsWith('/preview_company_subscription.php')).length>=2&&quotes.querySelector('article')),'Initial normalized preview did not settle');
   if(window.testUnchanged){
    const acknowledgement=document.getElementById('companyGrantAcknowledgement');
    const previewCalls=calls.filter(call=>call.url.endsWith('/preview_company_subscription.php'));
    expect(previewCalls[0].body.selections===undefined&&previewCalls[1].body.selections.A.plan_monthly_discount==='0.00'&&previewCalls[1].body.selections.A.plan_setup_discount==='0.00','Optional discount schema did not normalize to zero discounts');
    expect(!acknowledgement.disabled,'Unchanged initial selection lacked a normalized save quote');
    acknowledgement.click();expect(!submit.disabled,'Explicit confirmation did not enable unchanged-selection save');submit.click();await settle();
    const save=calls.find(call=>call.url.endsWith('/save_company_subscription.php'));
    expect(save&&save.body.selections.A.module_ids.length===1&&save.body.selections.A.module_ids[0]==='M1','Unchanged selection was not saved exactly');
    expect(save.body.selections.A.max_users===0,'Preloaded zero max_users changed before save');
    expect(save.body.selections.A.plan_monthly_discount==='0.00'&&save.body.selections.A.plan_setup_discount==='0.00','Default discounts were not saved as decimal strings');
    output.dataset.status='PASS';output.textContent='PASS';return;
   }
    expect(quotes.textContent.includes('100,00')&&quotes.textContent.includes('75,00'),'Current prices not distinct: '+quotes.textContent+' / '+document.getElementById('companyGrantStatus').textContent+' / '+window.testError);
  expect(quotes.textContent.includes('bedriftsspesifikk plan'),'Subscription setup note missing');
  expect(document.getElementById('companyGrantIssuer').textContent.includes('Salesman'),'Issuer missing');
   expect(calls[0].body.selections===undefined&&calls[0].body.module_ids===undefined,'Initial preview must load stored selections');
   expect(document.getElementById('companyGrantSelected').textContent.includes('Arrangement'),'Initial existing module was not preloaded');
    expect(document.getElementById('companyGrantActiveUsers').textContent.includes('0 aktive'),'Zero active user count missing');
    expect(document.getElementById('companyGrantMaxUsers').value==='0','Preloaded max_users=0 was replaced');
    expect(quotes.textContent.includes('200,00'),'Full establishment price not shown separately');
   const switcher=document.getElementById('companyGrantCompanySwitcher');
   const selected=document.getElementById('companyGrantSelected');
   const selectedModule=()=>selected.querySelector('button');
   expect(selectedModule().textContent.includes('Arrangement'),'Alpha selection missing');
    const normalized=calls.filter(call=>call.url.endsWith('/preview_company_subscription.php'))[1];
    expect(normalized&&normalized.body.selections.A.max_users===0&&normalized.body.selections.B.max_users===null,'Normalized preview lost zero or unknown user limit');
    switcher.value='B';switcher.dispatchEvent(new Event('change'));await settle(20);
   expect(selectedModule().textContent.includes('Salg')&&document.getElementById('companyGrantActiveUsers').textContent.includes('5 aktive'),'Company-specific module state not isolated');
    expect(document.getElementById('companyGrantMaxUsers').value==='','Unknown max_users was guessed');
    document.getElementById('companyGrantMaxUsers').dispatchEvent(new Event('input'));await settle();
    expect(calls.filter(call=>call.url.endsWith('/preview_company_subscription.php')).at(-1).body.selections.B.max_users===null,'Blank max_users became zero');
    expect(document.getElementById('companyGrantActiveUsers').textContent.includes('5 aktive'),'Active-user metadata disappeared during quote refresh');
   document.getElementById('companyGrantMaxUsers').value='7';document.getElementById('companyGrantMaxUsers').dispatchEvent(new Event('input'));await settle();
   switcher.value='A';switcher.dispatchEvent(new Event('change'));
   expect(selectedModule().textContent.includes('Arrangement'),'Switching company overwrote another selection');
   selectedModule().click();await settle();
    expect(document.activeElement.dataset.moduleId==='M1'&&document.activeElement.parentElement===document.getElementById('companyGrantAvailable'),'Focus did not follow the moved module');
   expect(selected.textContent.includes('Ingen moduler valgt'),'Removing the active module did not allow empty selections');
     const alphaAmount=label=>Array.from(quotes.querySelector('article').querySelectorAll('div')).find(node=>node.firstElementChild?.textContent===label)?.lastElementChild.textContent;
     expect(alphaAmount('Månedlig modulpakke, brutto')==='0,00 NOK'&&alphaAmount('Etablering for alle valgte moduler, brutto')==='0,00 NOK','Empty exact selection did not produce zero totals');
   const inPane=(pane,name)=>Array.from(pane.querySelectorAll('button')).find(button=>button.textContent.includes(name));
   const addM2=()=>inPane(document.getElementById('companyGrantAvailable'),'Salg');
   const removeM2=()=>inPane(document.getElementById('companyGrantSelected'),'Salg');
   addM2().click();await settle();
   expect(quotes.textContent.includes('50,00')&&quotes.textContent.includes('25,00'),'Server quote did not reflect exact added module');
  expect(submit.disabled,'Saving enabled before approval');
   removeM2().click();await settle();
   fixturePrice='65.00';addM2().click();await settle();
   expect(quotes.textContent.includes('65,00'),'Price update was not reflected by fresh preview');
    fixtureSlow=true;removeM2().click();await settle(350);addM2().click();await settle(1800);
    expect(quotes.textContent.includes('65,00')&&removeM2(),'A stale quote replaced the latest per-company selection');
   fixtureMissing=true;removeM2().click();await settle();
   addM2().click();await settle();
   expect(document.getElementById('companyGrantSelected').textContent.includes('Ikke konfigurert')&&quotes.textContent.includes('Manglende prisinformasjon'),'Missing price treated as free');
   fixtureMissing=false;
    fixtureWrongSelection=true;removeM2().click();await settle();
    expect(quotes.textContent===''&&submit.disabled&&document.getElementById('companyGrantStatus').textContent.includes('samsvarer ikke'),'Wrong quoted module set was displayed or saveable');
    expect(selected.textContent.includes('Ingen moduler valgt'),'Rejected response changed user selection');
    fixtureWrongSelection=false;document.querySelector('#companyGrantStatus button').click();await settle();
    addM2().click();await settle();
   fixtureFail=true;removeM2().click();await settle();
  expect(quotes.textContent==='','Failure left stale price');
  const retry=document.getElementById('companyGrantStatus').querySelector('button');
  expect(retry,'Retry absent');
   fixtureFail=false;retry.click();await settle();
     expect(alphaAmount('Månedlig modulpakke, brutto')==='0,00 NOK','Retry did not preserve the exact empty selection');
   expect(document.getElementById('companyGrantAvailable').getAttribute('aria-busy')==='false','Loading stuck');
   const previews=calls.filter(call=>call.url.endsWith('/preview_company_subscription.php'));
   expect(previews.every(call=>call.body.csrf_token==='test-csrf'),'Unexpected preview payload');
   expect(previews.at(-1).body.selections.A.module_ids.length===0,'Preview did not send exact empty selection');
   expect(previews.at(-1).body.selections.B.max_users===7,'Per-company user limit was not sent');
   inPane(document.getElementById('companyGrantAvailable'),'Arrangement').click();await settle();
    fixturePrice='1650.00';
   addM2().click();await settle();
    expect(quotes.textContent.includes('1750,00'),'Gross monthly amount missing before discount');
    const monthlyDiscount=document.getElementById('companyGrantMonthlyDiscount');
    const setupDiscount=document.getElementById('companyGrantSetupDiscount');
    monthlyDiscount.value='10.00';monthlyDiscount.dispatchEvent(new Event('input'));await settle();
    expect(quotes.textContent.includes('1575,00')&&quotes.textContent.includes('175,00'),'Ten percent monthly discount did not reduce 1750 to 1575');
    setupDiscount.value='10.00';setupDiscount.dispatchEvent(new Event('input'));await settle();
    expect(quotes.textContent.includes('202,50')&&quotes.textContent.includes('22,50'),'Setup discount did not show selected net and new setup due');
    switcher.value='B';switcher.dispatchEvent(new Event('change'));
    expect(monthlyDiscount.value==='0.00'&&setupDiscount.value==='0.00','Discount settings leaked between companies');
    monthlyDiscount.value='2.50';monthlyDiscount.dispatchEvent(new Event('input'));await settle();
    setupDiscount.value='12.50';setupDiscount.dispatchEvent(new Event('input'));await settle();
    switcher.value='A';switcher.dispatchEvent(new Event('change'));
    expect(monthlyDiscount.value==='10.00'&&setupDiscount.value==='10.00','Discounts were not retained after switching companies');
    expect(quotes.textContent.includes('1575,00'),'Discounted price changed after switching companies');
  const acknowledgement=document.getElementById('companyGrantAcknowledgement');
  acknowledgement.click();
  expect(!submit.disabled,'Confirmed valid quote cannot be saved');
  fixtureSaveFail=true;submit.click();await settle();
  expect(submit.disabled&&document.getElementById('companyGrantStatus').textContent.includes('Pris endret'),'Stale save not handled');
   expect(selected.textContent.includes('Arrangement')&&selected.textContent.includes('Salg'),'Save failure erased module selection');
  fixtureSaveFail=false;document.querySelector('#companyGrantStatus button').click();await settle();
  expect(submit.disabled&&!acknowledgement.checked,'Refresh did not require confirmation again');
  acknowledgement.click();submit.click();submit.click();await settle();
   const saves=calls.filter(call=>call.url.endsWith('/save_company_subscription.php'));
   expect(saves.length===2&&saves[1].body.confirmed===true&&saves[1].body.quote_hash==='a'.repeat(64),'Duplicate save or wrong confirmation payload');
   expect(saves[1].body.selections.A.module_ids.includes('M1')&&saves[1].body.selections.A.module_ids.includes('M2'),'Save did not send exact module ids');
   expect(saves[1].body.selections.B.module_ids.length===1&&saves[1].body.selections.B.max_users===7,'Save lost independent company selections/limit');
    expect(saves[1].body.selections.A.plan_monthly_discount==='10.00'&&saves[1].body.selections.A.plan_setup_discount==='10.00','Save lost Alpha discounts');
    expect(saves[1].body.selections.B.plan_monthly_discount==='2.50'&&saves[1].body.selections.B.plan_setup_discount==='12.50','Save lost Beta discounts');
  expect(document.getElementById('companyGrantStatus').textContent.includes('er lagret'),'Success invisible');
  expect(submit.disabled&&portalSelectedCompanies.size===0,'Success left saving active');
  const bounds=modal.querySelector('.modal-dialog').getBoundingClientRect();
  expect(bounds.left>=0&&bounds.right<=innerWidth+1,'Modal overflow');
  expect(modal.scrollWidth<=innerWidth+1,'Content overflow');
  output.dataset.status='PASS';output.textContent='PASS';
 }catch(error){output.dataset.status='FAIL';output.textContent=error.message;}
});
`;
(async () => {
try {
    const css = fs.readFileSync(path.join(os.tmpdir(), 'portal-workspace-bootstrap-5.3.0.css'), 'utf8');
    const js = fs.readFileSync(path.join(os.tmpdir(), 'portal-workspace-bootstrap-5.3.0.js'), 'utf8');
        const htmlFor = unchanged => `<!doctype html><html lang="no"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style>${unchanged ? '<script>window.testUnchanged=true;</script>' : ''}<script>${setup}</script></head><body>
        <button id="grantModeButton">Tildel moduler</button><button id="grantConfirmButton">Velg moduler</button>
        <input id="activitySearch"><button id="sessionRefreshButton">Oppdater økter</button><div id="companiesList"></div>
        ${dashboard.slice(start,end)}<script>${js}</script><script>${read('assets/dashboard-workspace.js')}</script>
        <div id="testResult"></div><script>${checks}</script></body></html>`;
    const file = path.join(directory, 'subscription.html');
    fs.writeFileSync(file, htmlFor(false));
    for (const [width,height] of [[1360,900],[375,812]]) {
        const result = await runChromium(['--headless','--no-sandbox','--disable-gpu','--allow-file-access-from-files',
            '--disable-dev-shm-usage','--disable-background-networking','--disable-extensions','--disable-default-apps','--no-first-run',
            `--user-data-dir=${path.join(directory, `profile-${width}`)}`,'--dump-dom','--virtual-time-budget=30000',
            `--window-size=${width},${height}`,`file://${file}`], {timeoutMs:30000,maxBuffer:2*1024*1024});
        assert(!result.error && result.status === 0, `Chromium failed: ${result.error?.message || result.stderr || result.status}`);
        const outcome = result.stdout.match(/<div id="testResult"[^>]*>([\s\S]*?)<\/div>/);
        assert(result.stdout.includes('data-status="PASS"'), `${width}px: ${outcome ? outcome[1] : 'No result'}`);
    }
    const unchangedFile=path.join(directory,'subscription-unchanged.html');
    fs.writeFileSync(unchangedFile,htmlFor(true));
    const unchangedResult=await runChromium(['--headless','--no-sandbox','--disable-gpu','--allow-file-access-from-files','--disable-dev-shm-usage',
        '--disable-background-networking','--disable-extensions','--disable-default-apps','--no-first-run',
        `--user-data-dir=${path.join(directory,'profile-unchanged')}`,'--dump-dom','--virtual-time-budget=30000',
        '--window-size=1360,900',`file://${unchangedFile}`],{timeoutMs:30000,maxBuffer:2*1024*1024});
    assert(!unchangedResult.error&&unchangedResult.status===0,`Unchanged-selection Chromium failed: ${unchangedResult.error?.message||unchangedResult.stderr||unchangedResult.status}`);
    const unchangedOutcome=unchangedResult.stdout.match(/<div id="testResult"[^>]*>([\s\S]*?)<\/div>/);
    assert(unchangedResult.stdout.includes('data-status="PASS"'),`Unchanged-selection save test failed: ${unchangedOutcome?unchangedOutcome[1]:'No result'}`);
    console.log('Subscription UI passed: desktop/mobile, exact and unchanged selections, zero/unknown user limits, retained company discounts, gross/net pricing, price freshness, retry and duplicate-save protection.');
} finally {
    fs.rmSync(directory, {recursive:true,force:true});
}
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
