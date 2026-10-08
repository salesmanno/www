// Run: node saas_portal/tests/portal_workspace_ui_test.js
// Isolated Chromium fixtures of the REAL page markup/scripts/assets. No app
// authentication changes or database calls. Bootstrap is the existing CDN version.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'portal-workspace-ui-'));
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

function bootstrapAsset(kind) {
    const cached = path.join(os.tmpdir(), `portal-workspace-bootstrap-5.3.0.${kind}`);
    if (!fs.existsSync(cached)) {
        const url = `https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/${kind === 'css' ? 'css/bootstrap.min.css' : 'js/bootstrap.bundle.min.js'}`;
        const result = spawnSync('curl', ['--fail', '--silent', '--show-error', '--max-time', '30', url], {encoding: 'utf8', timeout: 35000, maxBuffer: 1024 * 1024});
        if (result.error || result.status !== 0) throw new Error(`Cannot load existing Bootstrap ${kind}: ${result.error || result.stderr}`);
        fs.writeFileSync(cached, result.stdout);
    }
    return fs.readFileSync(cached, 'utf8');
}

const setup = `
'use strict';
const fixtureRequests = [], fixtureIntervals = [], fixtureErrors = [];
window.addEventListener('error', event => fixtureErrors.push(event.message));
window.addEventListener('unhandledrejection', event => fixtureErrors.push(String(event.reason)));
window.alert = () => {};
window.confirm = () => true;
window.setInterval = callback => { fixtureIntervals.push(callback); return fixtureIntervals.length; };
const fixtureCompanies = [
 {id:'company-a',name:'Nordhavn & Sønner',subdomain:'nordhavn',is_active:1,staff_count:2,admin_count:1,module_count:1,logo_url:'javascript:alert(1)'},
 {id:'company-b',name:'Lunde "Studio" <test>',subdomain:'lunde',is_active:1,staff_count:1,admin_count:1,module_count:2},
 {id:'company-c',name:'Fjordverk',subdomain:'fjordverk',is_active:0,staff_count:0,admin_count:0,module_count:0}
];
const fixtureStaff = [
 {id:'staff-linked',first_name:'Ingrid',last_name:'Lunde',email:'staff@example.test',phone:'47382916',job_title:'Daglig leder',has_account:1},
 {id:'staff-new',first_name:'Maja',last_name:'Berg',email:'maja@example.test',phone:'',job_title:'Rådgiver',has_account:0}
];
let fixtureAdmins = [
 {admin_id:'admin-linked',tenant_staff_id:'staff-linked',username:"ingrid' <test>",account_email:'account@example.test',email:'staff@example.test',is_active:1,created_at:'2026-10-01T10:00:00',last_login:'2026-10-07T12:10:00'},
 {admin_id:'admin-legacy',tenant_staff_id:null,username:'eldre.konto',account_email:'legacy@example.test',is_active:1}
];
const fixtureModules = [
 {id:'module-existing',name:'Kunder',description:'Kunderegister',has_access:true,granted:true},
 {id:'module-new',name:'Tilbud',description:'Tilbud og avtaler',has_access:false,granted:false}
];
const fixtureSessions = [
 {session_id:'session-one',tenant_admin_id:'admin-linked',username:'ingrid',admin_name:'Ingrid Lunde',company_id:'company-a',company_name:'Nordhavn & Sønner',minutes_idle:3},
 {session_id:'session-two',tenant_admin_id:'admin-linked',username:'ingrid',admin_name:'Ingrid Lunde',company_id:'company-a',company_name:'Nordhavn & Sønner',minutes_idle:7}
];
let fixtureFailGrant = false, fixtureFailStaff = false, fixtureFailSeo = false;
let fixtureSeoGetMode = 'ok';
let fixtureSeo = {seo_title:'Nordhavn – rådgivning',seo_description:'Lokale råd for nye prosjekter.',seo_keywords:'rådgivning, prosjekter',canonical_url:'https://nordhavn.example.test/tjenester'};
let fixtureSeoGetDelay = 0;
window.fetch = async (input, options = {}) => {
 const url = new URL(String(input), document.baseURI);
 const body = options.body instanceof FormData ? Object.fromEntries(options.body) : options.body ? JSON.parse(options.body) : null;
 fixtureRequests.push({path:url.pathname,search:url.search,payload:body,method:options.method || 'GET'});
 let data = {success:true};
 let status = 200;
 if (url.pathname.endsWith('/get_companies.php')) data.companies = fixtureCompanies;
 else if (url.pathname.endsWith('/get_staff.php')) {
  if (fixtureFailStaff) { data={success:false,message:'Ansattdata utilgjengelig'}; status=503; }
  else data.staff=fixtureStaff;
 } else if (url.pathname.endsWith('/get_admins.php')) data.admins=fixtureAdmins;
 else if (url.pathname.endsWith('/get_company_modules.php') || url.pathname.endsWith('/get_admin_modules.php')) data.modules=fixtureModules;
 else if (url.pathname.endsWith('/get_active_sessions.php')) data.sessions=fixtureSessions;
 else if (url.pathname.endsWith('/get_activity_stream.php')) { data.activities=[{action_type:'login',actor_display:'Ingrid Lunde',action_summary:'Logget inn',company_name:'Nordhavn & Sønner',subdomain:'nordhavn',created_at:'2026-10-07T12:10:00'}]; data.action_types=['login','company_seo_updated']; }
 else if (url.pathname.endsWith('/preview_company_subscription.php')) {
  if (fixtureFailGrant) {data={success:false,message:'Forhåndsvisning utilgjengelig'};status=503;}
  else data={success:true,read_only:true,apply_available:false,price_schema_ready:true,issuer:{id:'issuer',name:'Salesman'},warnings:[],
   modules:fixtureModules.map(module=>({...module,module_key:module.id==='module-existing'?'customers':'quotes',price_monthly:'10.00',price_setup:'20.00',price_currency:'NOK'})),
   companies:body.company_ids.map(id=>({company_id:id,name:id,module_ids:body.module_ids,new_module_ids:body.module_ids,
    monthly_total:'20.00',setup_total:'20.00',currency:'NOK',missing_prices:[],blockers:[],subscription:null}))};
 }
 else if (url.pathname.endsWith('/delete_admin.php')) {
  fixtureAdmins=fixtureAdmins.filter(account => account.admin_id !== body.admin_id);
  const staff=fixtureStaff.find(staff => staff.id==='staff-linked');
  if(body.admin_id==='admin-linked') staff.has_account=0;
 } else if (url.pathname.endsWith('/create_admin.php')) {
  const staff=fixtureStaff.find(staff => staff.id===body.tenant_staff_id);
  staff.has_account=1;
  fixtureAdmins.push({admin_id:'admin-created',tenant_staff_id:staff.id,username:body.username,account_email:staff.email,is_active:1});
 } else if (url.pathname.endsWith('/logout_user.php')) {
  const index=fixtureSessions.findIndex(session => session.session_id===body.session_id);
  if(index>=0) fixtureSessions.splice(index,1);
 } else if (url.pathname.endsWith('/company_seo.php')) {
   if (!body) {
    const seoSnapshot={...fixtureSeo};
    if (fixtureSeoGetDelay) await new Promise(resolve=>setTimeout(resolve,fixtureSeoGetDelay));
     if (fixtureSeoGetMode==='fail') { data={success:false,error_code:'database_unavailable',request_id:'abcd123456ef',message:'Databasen er midlertidig utilgjengelig. Prøv igjen når forbindelsen er tilbake. Feilreferanse: abcd123456ef'}; status=503; }
    else if (fixtureSeoGetMode==='missing') data.seo={seo_title:seoSnapshot.seo_title,seo_description:seoSnapshot.seo_description,seo_keywords:seoSnapshot.seo_keywords};
    else data.seo=seoSnapshot;
    } else if (fixtureFailSeo) { data={success:false,error_code:'database_unavailable',request_id:'abcd123456ef',message:'Databasen er midlertidig utilgjengelig. Lagringen kunne ikke bekreftes. Feilreferanse: abcd123456ef'}; status=503; }
   else { fixtureSeo={seo_title:body.seo_title,seo_description:body.seo_description,seo_keywords:body.seo_keywords,canonical_url:body.canonical_url}; data.message='SEO-innstillinger lagret.'; }
 } else if (url.pathname.endsWith('/get_email_config.php')) {
   data.config={smtp_host:'smtp.example.test',smtp_port:587,smtp_username:'sender',smtp_from_email:'sender@example.test',smtp_from_name:'Sender',imap_host:'imap.example.test',imap_port:993,imap_username:'sender'};
   data.credentials_readonly=true; data.notice='Hentes fra email_configs. Eksisterende passord beholdes.';
   if (window.fixtureSettingsOutage) {status=503;data={success:false,message:'Databasen er midlertidig utilgjengelig. Feilreferanse: settings-fixture'};}
 } else if (url.pathname.endsWith('/get_payment_gateways.php')) {
   data.gateways=[{gateway_name:'vipps',display_name:'Custom Vipps',is_active:1,test_mode:1,vipps_client_id:'fixture-client',vipps_merchant_serial_number:'fixture-merchant'}];
   data.credentials_readonly=true; data.notice='Eksisterende hemmelige nøkler beholdes.';
   if (window.fixtureSettingsOutage) {status=503;data={success:false,message:'Databasen er midlertidig utilgjengelig. Feilreferanse: settings-fixture'};}
 }
 return {ok:status===200,status,json:async()=>data,text:async()=>JSON.stringify(data),headers:{get:()=> 'application/json'}};
};
// A real Bootstrap modal opens its backdrop (150ms), then dialog (300ms).
const settle = () => new Promise(resolve => setTimeout(resolve, 600));
function expect(value, message) { if (!value) throw new Error(message); }
function same(actual, expected, message) { expect(JSON.stringify(actual)===JSON.stringify(expected), message+'; received '+JSON.stringify(actual)); }
function lastRequest(endpoint) { return fixtureRequests.filter(request=>request.path.endsWith('/'+endpoint)).at(-1); }
function action(root,label) { return Array.from(root.querySelectorAll('button')).find(button=>button.textContent.trim()===label); }
function change(input,checked) { input.checked=checked; input.dispatchEvent(new Event('change',{bubbles:true})); }
function submit(form) { form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})); }
function checkNoOverflow() { expect(document.documentElement.scrollWidth<=window.innerWidth,'Horizontal overflow at '+window.innerWidth); }
`;

const dashboardAssertions = `
await settle();
expect(document.querySelectorAll('#companiesList .company-card').length===3,'Company cards missing');
expect(document.querySelector('.company-identity').getAttribute('href').endsWith('id=company-a'),'Normal company link does not route to details');
expect(!document.querySelector('#companiesList img'),'Unsafe company logo created');
expect(document.querySelectorAll('#companiesList h4')[1].textContent===fixtureCompanies[1].name,'Company name not rendered as text');
expect(!document.getElementById('dashboardTabs'),'Obsolete dashboard tabs remain');
document.getElementById('grantModeButton').click();
const selections=Array.from(document.querySelectorAll('#companiesList .company-identity'));
expect(selections.every(button=>button.tagName==='BUTTON'),'Selection mode is not keyboard accessible');
selections[0].click(); selections[1].click();
expect(portalSelectedCompanies.size===2,'Multi-selection failed');
expect(selections[0].getAttribute('aria-pressed')==='true','Selected state inaccessible');
await loadCompanies(); await settle();
expect(document.querySelectorAll('.company-card.selected').length===2,'Polling erased selected companies');
document.getElementById('grantConfirmButton').click(); await settle();
expect(document.getElementById('companyGrantModal').classList.contains('show'),'Grant confirmation modal did not open');
expect(document.getElementById('companyGrantSubmit').disabled,'Grant enabled without a module selection');
change(document.querySelector('#companyGrantModules input[value="module-new"]'),true);
fixtureFailGrant=true;
await settle();
expect(document.getElementById('companyGrantStatus').textContent.includes('Forhåndsvisning utilgjengelig'),'Preview failure not visible');
expect(portalSelectedCompanies.size===2,'Failed preview erased company selection');
expect(document.querySelector('#companyGrantModules input[value="module-new"]').checked,'Failed preview erased module selection');
fixtureFailGrant=false;
document.querySelector('#companyGrantStatus button').click(); await settle();
same(lastRequest('preview_company_subscription.php').payload,{company_ids:['company-a','company-b'],module_ids:['module-new'],csrf_token:'fixture-csrf'},'Preview payload incorrect');
expect(!lastRequest('grant_company_modules.php'),'Preview granted real access');
expect(document.getElementById('companyGrantSubmit').disabled,'Preview enabled actual saving');
bootstrap.Modal.getInstance(document.getElementById('companyGrantModal')).hide();await settle();
document.getElementById('grantModeButton').click();
expect(!portalGrantMode && portalSelectedCompanies.size===0,'Cancel did not exit selection mode');
expect(document.querySelector('.company-identity').tagName==='A','Company navigation not restored');
const terminate=document.querySelector('#activeSessionsPanel button');
expect(document.querySelectorAll('#activeSessionsPanel .session-row').length===2,'Active sessions missing');
terminate.click(); await settle();
same(lastRequest('logout_user.php').payload,{admin_id:'admin-linked',session_id:'session-one',csrf_token:'fixture-csrf'},'Session termination was not targeted');
expect(document.querySelectorAll('#activeSessionsPanel .session-row').length===1,'Terminating one session erased the other session');
expect(fixtureSessions[0].session_id==='session-two','Wrong session terminated');
checkNoOverflow();
document.body.dataset.checks='company navigation, safe text/logo, multi-selection, polling persistence, subscription preview failure/retry/payload, targeted session termination';
`;

const companyAssertions = `
await settle();
expect(document.querySelectorAll('#staffList .staff-item').length===2,'Staff list missing');
let linked=document.querySelector('#staffList .staff-item');
const employeeToggle=linked.querySelector('.staff-toggle');
expect(linked.querySelector('.staff-details').hidden,'Employee details initially expanded');
employeeToggle.click();
expect(!linked.querySelector('.staff-details').hidden,'Employee click did not expand inline');
expect(employeeToggle.nextElementSibling.classList.contains('staff-details'),'Employee detail not directly below employee');
expect(employeeToggle.getAttribute('aria-expanded')==='true','Employee expansion missing ARIA');
linked.querySelector('.account-toggle').click();
expect(!linked.querySelector('.account-details').hidden,'Account row did not expand');
expect(linked.querySelector('.account-details').textContent.includes('account@example.test'),'Actual account email not shown');
expect(document.getElementById('adminsList').textContent.includes('eldre.konto'),'Legacy account hidden');
expect(!document.getElementById('adminsList').textContent.includes("ingrid' <test>"),'Linked account duplicated in legacy section');
await Promise.all([loadStaff(),loadAdmins()]); await settle();
linked=document.querySelector('#staffList .staff-item');
expect(!linked.querySelector('.staff-details').hidden && !linked.querySelector('.account-details').hidden,'Refresh erased expanded employee/account rows');
action(linked,'Tildel moduler').click(); await settle();
expect(lastRequest('get_admin_modules.php').search.includes('admin_id=admin-linked'),'Module action uses wrong account');
expect(document.getElementById('adminModulesModal').classList.contains('show'),'Account module modal missing');
change(document.querySelector('#adminModulesCheckboxes input[value="module-new"]'),true);
document.getElementById('saveAdminModulesButton').click(); await settle();
expect(!document.getElementById('adminModulesModal').classList.contains('show'),'Successful module save did not close modal');
same(lastRequest('update_admin_modules.php').payload,{admin_id:'admin-linked',module_ids:['module-existing','module-new'],csrf_token:'fixture-csrf'},'Account module assignment payload incorrect');
linked=document.querySelector('#staffList .staff-item');
action(linked,'Slett bruker').click(); await settle();
same(lastRequest('delete_admin.php').payload,{admin_id:'admin-linked',csrf_token:'fixture-csrf'},'Delete user targeted wrong account');
expect(!fixtureRequests.some(request=>request.path.endsWith('/delete_staff.php')),'Delete USER deleted staff');
expect(document.querySelectorAll('#staffList .staff-item').length===2,'Delete account removed employee');
expect(action(document.querySelector('#staffList .staff-item'),'Opprett SuperUser'),'Staff did not refresh after account deletion');
const unlinked=document.querySelectorAll('#staffList .staff-item')[1];
unlinked.querySelector('.staff-toggle').click();
action(unlinked,'Opprett SuperUser').click();
await settle();
expect(document.getElementById('admin_staff_select').value==='staff-new','Creation form not tied to clicked employee');
expect(document.getElementById('admin_staff_select').disabled,'Employee link can accidentally be cleared');
expect(document.getElementById('admin_email').readOnly && document.getElementById('admin_email').value==='maja@example.test','Linked employee email not protected');
const form=document.getElementById('createAdminForm');
form.elements.username.value='maja.berg';
form.elements.password.value='FixturePassword27!';
form.elements.email.value='attacker@example.test';
submit(form); await settle();
expect(!document.getElementById('createAdminModal').classList.contains('show'),'Successful account creation did not close modal');
const created=lastRequest('create_admin.php').payload;
expect(created.tenant_staff_id==='staff-new' && created.company_id==='company-a','SuperUser submitted unlinked or wrong employee');
expect(created.email==='maja@example.test','Creation trusted tampered account email rather than employee email');
expect(created.csrf_token==='fixture-csrf','SuperUser CSRF missing');
expect(document.querySelectorAll('#staffList .staff-item')[1].textContent.includes('maja.berg'),'Creation did not refresh staff/account mapping');
const beforeCreates=fixtureRequests.filter(request=>request.path.endsWith('/create_admin.php')).length;
portalLinkedAccountStaffId='';
createAdmin(); await settle();
expect(fixtureRequests.filter(request=>request.path.endsWith('/create_admin.php')).length===beforeCreates,'Unlinked account request was allowed');
expect(document.getElementById('createAccountStatus').textContent.includes('må være koblet'),'Unlinked creation failure invisible');
 fixtureSeoGetMode='fail';
 fixtureSeoGetDelay=40;
 const beforeSeoLoads=fixtureRequests.filter(request=>request.path.endsWith('/company_seo.php') && request.method==='GET').length;
 const loadOne=portalSeoLoad(), loadTwo=portalSeoLoad();
 expect(loadOne===loadTwo,'Concurrent SEO loads were not deduplicated');
 expect(['seoTitle','seoDescription','seoKeywords','seoCanonicalUrl'].every(id=>document.getElementById(id).disabled),'SEO fields enabled during initial load');
 const seoPostsBeforeLoad=fixtureRequests.filter(request=>request.path.endsWith('/company_seo.php') && request.method==='POST').length;
 submit(document.getElementById('companySeoForm'));
 expect(fixtureRequests.filter(request=>request.path.endsWith('/company_seo.php') && request.method==='POST').length===seoPostsBeforeLoad,'SEO could save before successful load');
 await Promise.all([loadOne,loadTwo]);
 expect(fixtureRequests.filter(request=>request.path.endsWith('/company_seo.php') && request.method==='GET').length===beforeSeoLoads+1,'Concurrent SEO loads made multiple requests');
  expect(document.getElementById('companySeoStatus').textContent.includes('Databasen er midlertidig utilgjengelig') && document.getElementById('companySeoStatus').textContent.includes('abcd123456ef'),'Database load error/reference invisible');
 expect(['seoTitle','seoDescription','seoKeywords','seoCanonicalUrl'].every(id=>document.getElementById(id).disabled),'SEO fields enabled after failed load');
 fixtureSeoGetMode='missing';
 document.querySelector('#companySeoStatus button').click(); await settle();
 expect(document.getElementById('companySeoStatus').textContent.includes('ufullstendige data'),'Malformed SEO response was silently accepted');
 expect(document.getElementById('seoSaveButton').disabled && document.getElementById('seoCanonicalUrl').disabled,'Malformed response enabled SEO form');
 fixtureSeoGetMode='ok';
 document.querySelector('#companySeoStatus button').click(); await settle();
 expect(document.getElementById('seoTitle').value==='Nordhavn – rådgivning','SEO GET retry did not initialize form');
 expect(document.getElementById('seoKeywords').value==='rådgivning, prosjekter','SEO keywords did not load');
 expect(document.getElementById('seoCanonicalUrl').value==='https://nordhavn.example.test/tjenester','Canonical URL did not load');

 // A newer generation must win even when an older request resolves later.
 portalSeoInitialized=false; portalSeoCompanyId=''; portalSeoSetEnabled(false);
 fixtureSeoGetDelay=80; fixtureSeo.seo_title='Stale response';
 const staleSeoLoad=portalSeoLoad();
 await Promise.resolve();
 portalSeoGeneration++; portalSeoLoadInFlight=null;
 fixtureSeoGetDelay=0; fixtureSeo.seo_title='Current response';
 const currentSeoLoad=portalSeoLoad();
 await currentSeoLoad; await staleSeoLoad;
 expect(document.getElementById('seoTitle').value==='Current response','Stale SEO response replaced newer data');
 fixtureSeoGetDelay=0;
 document.getElementById('seo-tab').click(); await settle();

 document.getElementById('seoTitle').value='Nordhavn | Prosjekter';
 document.getElementById('seoDescription').value='Råd & retning <uten markup>';
 document.getElementById('seoKeywords').value='lokale råd, prosjekter';
 document.getElementById('seoCanonicalUrl').value='https://www.nordhavn.example.test/prosjekter';
 const seoValues=()=>['seoTitle','seoDescription','seoKeywords','seoCanonicalUrl'].map(id=>document.getElementById(id).value);
 const originalSeoValues=seoValues();
 fixtureFailSeo=true;
 submit(document.getElementById('companySeoForm')); await settle();
  expect(document.getElementById('companySeoStatus').textContent.includes('Lagringen kunne ikke bekreftes') && document.getElementById('companySeoStatus').textContent.includes('abcd123456ef'),'Database save error/reference invisible');
 same(seoValues(),originalSeoValues,'SEO save error erased one or more fields');
 fixtureFailSeo=false;
 const seoPostCount=()=>fixtureRequests.filter(request=>request.path.endsWith('/company_seo.php') && request.method==='POST').length;
 const beforeInvalidSeo=seoPostCount();
 for (const invalid of ['javascript:alert(1)','https://user:pass@example.test/path','https://bad host.example/path','https://bad\\\\host.example/path']) {
  document.getElementById('seoCanonicalUrl').value=invalid;
  submit(document.getElementById('companySeoForm')); await settle();
  expect(seoPostCount()===beforeInvalidSeo,'Invalid canonical URL was posted: '+invalid);
  expect(document.getElementById('companySeoStatus').textContent.includes('Kanonisk URL'),'Invalid canonical URL error not visible');
 }
 document.getElementById('seoCanonicalUrl').value='https://valid.example.test/path';
 document.getElementById('seoTitle').value='😀'.repeat(256);
 submit(document.getElementById('companySeoForm')); await settle();
 expect(seoPostCount()===beforeInvalidSeo && document.getElementById('companySeoStatus').textContent.includes('255'),'Unicode title limit was not enforced by code point');
 for (const [id,maximum] of [['seoDescription',1000],['seoKeywords',2000],['seoCanonicalUrl',500]]) {
  document.getElementById('seoTitle').value='Nordhavn | Prosjekter';
  document.getElementById(id).value='x'.repeat(maximum+1);
  submit(document.getElementById('companySeoForm')); await settle();
  expect(seoPostCount()===beforeInvalidSeo,'SEO field code-point limit was not enforced for '+id);
 }
 document.getElementById('seoTitle').value='Nordhavn | Prosjekter';
 document.getElementById('seoDescription').value='Råd & retning <uten markup>';
 document.getElementById('seoKeywords').value='lokale råd, prosjekter';
 document.getElementById('seoCanonicalUrl').value='https://www.nordhavn.example.test/prosjekter';
 submit(document.getElementById('companySeoForm')); await settle();
 same(lastRequest('company_seo.php').payload,{company_id:'company-a',seo_title:'Nordhavn | Prosjekter',seo_description:'Råd & retning <uten markup>',seo_keywords:'lokale råd, prosjekter',canonical_url:'https://www.nordhavn.example.test/prosjekter',csrf_token:'fixture-csrf'},'SEO save payload incorrect');
 ['seoTitle','seoDescription','seoKeywords','seoCanonicalUrl'].forEach(id=>{document.getElementById(id).value='';});
 submit(document.getElementById('companySeoForm')); await settle();
 same(lastRequest('company_seo.php').payload,{company_id:'company-a',seo_title:'',seo_description:'',seo_keywords:'',canonical_url:'',csrf_token:'fixture-csrf'},'SEO empty values did not round-trip');
document.getElementById('email-tab').click(); await settle();
expect(document.getElementById('smtp_host').value==='smtp.example.test','Email settings did not load');
expect(document.getElementById('smtp_from_email').value==='sender@example.test','Email sender mapping');
expect(document.getElementById('smtp_password').disabled && document.getElementById('smtp_encryption').disabled,'Unsupported credential edits enabled');
document.getElementById('smtp_from_name').value='Updated Sender';
saveEmailConfig(); await settle();
expect(lastRequest('save_email_config.php').payload.csrf_token==='fixture-csrf','Email save missing CSRF');
expect(!('smtp_password' in lastRequest('save_email_config.php').payload),'Email password sent');
connectEmail(); await settle();
expect(lastRequest('connect_email.php').payload.csrf_token==='fixture-csrf','Email activation missing CSRF');
window.fixtureSettingsOutage=true;
await loadEmailConfig(); await settle();
expect(document.getElementById('smtp_from_name').value==='Updated Sender','Failed email read erased edited input');
expect(document.getElementById('smtp_host').disabled,'Failed email read left save enabled');
expect(document.getElementById('emailConfigStatus').textContent.includes('settings-fixture'),'Email failure reference invisible');
window.fixtureSettingsOutage=false;
await loadEmailConfig(); await settle();
expect(!document.getElementById('smtp_host').disabled,'Email recovery failed');
document.getElementById('payment-tab').click(); await settle();
expect(document.getElementById('vipps_client_id').value==='fixture-client','Linked payment config did not load');
expect(document.getElementById('vipps_subscription_key').disabled,'Payment secret editable');
expect(document.getElementById('stripe_public_key').disabled,'Missing gateway allowed editing');
document.getElementById('vipps_client_id').value='updated-client';
savePaymentGateway('vipps'); await settle();
expect(lastRequest('save_payment_gateway.php').payload.csrf_token==='fixture-csrf','Payment save missing CSRF');
expect(!('vipps_subscription_key' in lastRequest('save_payment_gateway.php').payload),'Payment secret sent');
window.fixtureSettingsOutage=true;
await loadPaymentGateways(); await settle();
expect(document.getElementById('vipps_client_id').value==='updated-client','Failed payment read erased input');
expect(document.getElementById('vipps_client_id').disabled,'Failed payment read allowed saving');
window.fixtureSettingsOutage=false;
await loadPaymentGateways(); await settle();
expect(document.getElementById('vipps_client_id').value==='fixture-client' && !document.getElementById('vipps_client_id').disabled,'Payment recovery failed');
document.getElementById('email-tab').click(); await settle();
fixtureFailStaff=true;
await loadStaff();
expect(document.getElementById('staffLoadStatus').textContent.includes('Ansattdata utilgjengelig'),'Staff error invisible');
expect(!document.querySelector('.staff-details').hidden,'Staff failure erased expanded row');
fixtureFailStaff=false;
await loadStaff();
expect(!document.getElementById('staffLoadStatus').textContent,'Staff recovery left error');
checkNoOverflow();
 document.body.dataset.checks='inline employee/account expansion, account email, legacy account, refresh persistence, modules payload, user-only deletion, linked SuperUser/tampered email/guard, SEO GET failure/retry/malformed response, deduplication/stale guard, disabled pending fields, four-field save/error/empty round-trip, canonical URL and Unicode limits, staff failure/recovery';
`;

function fixture(page, bootstrapCss, bootstrapJs) {
    let source = read(`pages/${page}.php`);
    // Remove ONLY the server-only DB error branch in the isolated document.
    source = source.replace(/<\?php if \(\$company_load_error\): \?>[\s\S]*?<\?php endif; \?>/, '');
    source = source.slice(source.indexOf('<!DOCTYPE html>'));
    source = source.replace(/<\?=([\s\S]*?)\?>/g, (_, expression) => {
        if (expression.includes('json_encode')) {
            if (expression.includes('portal_csrf')) return '"fixture-csrf"';
            if (expression.includes('brreg_staff_csrf')) return '"fixture-staff-csrf"';
            if (expression.includes('$company_id')) return '"company-a"';
            return '""';
        }
        if (expression.includes("['subdomain']")) return 'nordhavn';
        if (expression.includes("['name']")) return 'Nordhavn &amp; Sønner';
        return '';
    }).replace(/<\?php[\s\S]*?\?>/g, '');
    source = source.replace(/<link[^>]+>/g, '');
    source = source.replace(/<script src="([^"]+)"><\/script>/g, (_, url) => {
        if (url.includes('bootstrap.bundle.min.js')) return `<script>${bootstrapJs}</script>`;
        if (url.startsWith('/saas_portal/assets/')) return `<script>${read(url.replace('/saas_portal/', ''))}</script>`;
        throw new Error('Unexpected script: ' + url);
    });
    source = source.replace('<head>', `<head><base href="https://fixture.invalid/"><style>${bootstrapCss}</style><script>${setup}</script>`);
    source = source.replace('</head>', `<style>${read('assets/portal-workspace.css')}</style></head>`);
    const assertions = page === 'dashboard' ? dashboardAssertions : companyAssertions;
    source = source.replace('</body>', `<script>
document.addEventListener('DOMContentLoaded', async () => {
 try {
  ${assertions}
  expect(fixtureErrors.length===0,'Browser runtime errors: '+fixtureErrors.join('; '));
  document.body.dataset.result='PASS';
 } catch(error) {
  document.body.dataset.result='FAIL';
  const failure=document.createElement('pre'); failure.id='fixture-failure'; failure.textContent=error.stack; document.body.appendChild(failure);
 }
});
</script></body>`);
    return source;
}

try {
    const bootstrapCss = bootstrapAsset('css');
    const bootstrapJs = bootstrapAsset('js');
    for (const page of ['dashboard', 'company_details']) {
        const file = path.join(directory, `${page}.html`);
        fs.writeFileSync(file, fixture(page, bootstrapCss, bootstrapJs));
        for (const width of [390, 1280]) {
            const screenshot = path.join(os.tmpdir(), `portal-workspace-${page}-${width}.png`);
            const result = spawnSync('chromium', [
                '--headless', '--no-sandbox', '--disable-gpu', '--no-proxy-server',
                '--disable-background-networking', '--run-all-compositor-stages-before-draw',
                '--dump-dom', '--virtual-time-budget=30000', `--window-size=${width},1000`,
                `--screenshot=${screenshot}`, `file://${file}`
            ], {encoding: 'utf8', timeout: 65000, maxBuffer: 4 * 1024 * 1024});
            const failure = result.stdout?.match(/<pre id="fixture-failure">([\s\S]*?)<\/pre>/)?.[1];
            assert(!result.error && result.status === 0 && result.stdout.includes('data-result="PASS"'),
                `${page} at ${width}: ${result.error || failure || result.stderr || 'Missing PASS marker'}`);
            const checks = result.stdout.match(/data-checks="([^"]+)"/)?.[1] || '';
            console.log(`PASS ${page} ${width}px: ${checks}`);
            console.log(`Screenshot: ${screenshot}`);
        }
    }
} finally {
    fs.rmSync(directory, {recursive: true, force: true});
}
