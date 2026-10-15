'use strict';
// Run against the disposable port-5001 fixture only. No production credentials.
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'workshop-chromium-'));
const browser = spawn('chromium', ['--headless', '--no-sandbox', '--disable-gpu', '--remote-debugging-pipe', '--user-data-dir='+profile], {detached:true,stdio:['ignore','ignore','ignore','pipe','pipe']});
const closed = new Promise(resolve => browser.once('exit', resolve));
let serial=0, buffer='', session;
const pending=new Map();
browser.stdio[4].on('data', chunk => {
    buffer+=chunk.toString();
    for(let at;(at=buffer.indexOf('\0'))>=0;) {
        const text=buffer.slice(0,at);buffer=buffer.slice(at+1);
        if(!text)continue;
        const message=JSON.parse(text), p=pending.get(message.id);
        if(p) {pending.delete(message.id);message.error?p.reject(new Error(JSON.stringify(message.error))):p.resolve(message.result);}
    }
});
function call(method, params={}, sessionId) {
    return new Promise((resolve,reject)=>{
        const id=++serial;pending.set(id,{resolve,reject});
        browser.stdio[3].write(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})})+'\0');
    });
}
const timeout=setTimeout(()=>{console.error('Browser test timed out');browser.kill('SIGKILL');process.exitCode=1;},90000);
(async()=>{
    const target=await call('Target.createTarget',{url:'about:blank'});
    session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
    await call('Runtime.enable',{},session);
    await call('Page.enable',{},session);
    await call('Page.navigate',{url:'http://127.0.0.1:5001/saas_portal/pages/email_templates.php'},session);
    const result=await call('Runtime.evaluate',{awaitPromise:true,returnByValue:true,expression:`(async()=>{
        const $=id=>document.getElementById(id), pass=[];
        const wait=async(fn,label)=>{for(let i=0;i<160;i++){if(fn())return;await new Promise(r=>setTimeout(r,50));}throw Error('Timeout: '+label);};
        const check=(ok,label)=>{if(!ok)throw Error(label);pass.push(label);};
        await wait(()=>$('templateList')?.querySelector('button'),'library load');
        const api=async(data)=>{const r=await fetch('/saas_portal/api/email_templates.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...data,csrf_token:window.emailWorkshopCsrf})});const j=await r.json();if(!j.success)throw Error(j.message);return j;};
        const list=async()=> (await(await fetch('/saas_portal/api/email_templates.php?action=list')).json()).templates;
        const select=async(t)=>{const button=[...$('templateList').querySelectorAll('button')].find(b=>b.textContent.includes(t.name)&&b.textContent.includes(t.company_id===null?'SaaS-bibliotek':({'a':'Firma Alfa','b':'Firma Beta','c':'Firma Gamma'}[t.company_id])));if(!button)throw Error('Missing template button');button.click();await wait(()=>$('templateName').value===t.name&&!$('templateName').disabled,'select');};
        const input=(id,value)=>{$(id).value=value;$(id).dispatchEvent(new Event('input',{bubbles:true}));};
        const operation=async(op,targets)=>{await wait(()=>!$('runOperationButton').disabled,'operation ready');$('operationSelect').value=op;$('operationSelect').dispatchEvent(new Event('change'));for(const x of document.querySelectorAll('.company-target-check')){x.checked=targets.includes(x.value);x.dispatchEvent(new Event('change'));}$('runOperationButton').click();await wait(()=>$('operationDialog').open&&!$('applyPlanButton').disabled,'plan '+op);};
        const apply=async()=>{$('applyPlanButton').click();await wait(()=>!$('operationDialog').open,'apply');await wait(()=>!$('refreshTemplatesButton').disabled,'settled');};
        const initial=await list(), source=initial.find(t=>t.company_id===null&&t.template_key==='sale_receipt');
        await select(source);
        check($('templateKey').disabled||$('templateKey').readOnly,'Existing key protected');
        $('previewButton').click();await wait(()=>$('emailPreviewFrame').hasAttribute('srcdoc'),'preview');
        check($('emailPreviewFrame').getAttribute('sandbox')===''&&$('emailPreviewFrame').srcdoc.includes('Kari Eksempel'),'Sandboxed substituted preview');
        input('templateName','Oppdatert kvittering');$('saveTemplateButton').click();
        await wait(()=>$('workshopStatus').textContent.includes('Malen er lagret'),'save');
        check((await list()).find(t=>t.company_id==='a').name==='Salgskvittering','Source edit leaves firm copy intact');
        await operation('copy',['b']);
        check($('planEffects').textContent.includes('Firma Beta'),'Exact recipient confirmation');
        $('cancelPlanButton').click();await wait(()=>!$('operationDialog').open,'cancel');
        check(!(await list()).some(t=>t.company_id==='b'),'Cancelled plan changes nothing');
        await operation('copy',['b']);await apply();
        check((await list()).some(t=>t.company_id==='b'&&t.name==='Oppdatert kvittering'),'Copy saved to selected firm');
        await operation('update',['a']);await apply();
        check((await list()).find(t=>t.company_id==='a').name==='Oppdatert kvittering','Explicit linked copy update');
        await operation('delete',['b']);await apply();
        check(!(await list()).some(t=>t.company_id==='b')&&(await list()).some(t=>t.company_id==='a'),'Remove selected firm only');
        $('deleteTemplateButton').click();await wait(()=>$('operationDialog').open&&!$('applyPlanButton').disabled,'delete source plan');await apply();
        check((await list()).some(t=>t.company_id==='a')&&!(await list()).some(t=>t.id===source.id),'Delete original preserves distributed copy');
        const a=(await list()).find(t=>t.company_id==='a');await select(a);
        $('operationSelect').value='move';$('operationSelect').dispatchEvent(new Event('change'));
        $('moveTarget').value='';$('runOperationButton').click();await wait(()=>$('operationDialog').open&&!$('applyPlanButton').disabled,'move plan');await apply();
        const moved=(await list()).find(t=>t.company_id===null&&t.template_key==='sale_receipt');
        check(Boolean(moved)&&!(await list()).some(t=>t.company_id==='a'),'Move firm to library');
        await select(moved);$('operationSelect').value='move';$('operationSelect').dispatchEvent(new Event('change'));$('moveTarget').value='c';$('runOperationButton').click();
        await wait(()=>$('operationDialog').open&&!$('applyPlanButton').disabled,'move back plan');await apply();
        check((await list()).some(t=>t.company_id==='c'&&t.template_key==='sale_receipt')&&!(await list()).some(t=>t.company_id===null&&t.template_key==='sale_receipt'),'Move library to firm');
        for(const key of ['event_confirmation','membership_confirmation','rental_confirmation','service_booking_confirmation']) {
            const t=(await list()).find(t=>t.company_id===null&&t.template_key===key);
            await select(t);$('previewButton').click();await wait(()=>$('emailPreviewFrame').hasAttribute('srcdoc'),'family preview '+key);
            check(!$('emailPreviewFrame').srcdoc.includes('{{'),'Existing '+key+' variables preview correctly');
            await operation('copy',['b']);await apply();
            check((await list()).some(t=>t.company_id==='b'&&t.template_key===key),'Existing '+key+' distributes through UI');
        }
        $('newTemplateButton').click();await wait(()=>!$('templateName').disabled,'new');
        input('templateName','Mobiltest');input('templateKey','custom_draft');input('templateSubject','Hei {{customer_name}}');input('templateBody','<p>Hei {{customer_name}}</p>');
        return pass;
    })()`},session);
    if(result.exceptionDetails) {
        const diagnostic=await call('Runtime.evaluate',{returnByValue:true,expression:'({status:document.getElementById("workshopStatus")?.textContent,plan:document.getElementById("planError")?.textContent,dialog:document.getElementById("operationDialog")?.open,runDisabled:document.getElementById("runOperationButton")?.disabled})'},session);
        throw new Error(result.exceptionDetails.text+' '+result.exceptionDetails.exception?.description+' '+JSON.stringify(diagnostic.result.value));
    }
    console.log(JSON.stringify(result.result.value,null,2));
    await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true},session);
    const mobile=await call('Runtime.evaluate',{returnByValue:true,expression:'({width:innerWidth,scroll:document.documentElement.scrollWidth})'},session);
    if(mobile.result.value.scroll>mobile.result.value.width+1)throw new Error('Mobile horizontal overflow: '+JSON.stringify(mobile.result.value));
    console.log('PASS mobile390 no horizontal overflow');
})().catch(e=>{console.error(e.stack);process.exitCode=1;}).finally(async()=>{
    clearTimeout(timeout);
    try{await call('Browser.close');}catch{}
    try { process.kill(-browser.pid, 'SIGTERM'); } catch (e) { if (e.code !== 'ESRCH') console.warn('Browser group cleanup:', e.code); }
    await closed;
    try { fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:150}); }
    catch (e) { console.warn('Temporary browser-profile cleanup deferred:', e.code, profile); }
});
