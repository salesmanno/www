'use strict';
// Signed-in UI against the disposable fixture from prospect_company_test.php.
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const port=Number(process.argv[2]),root=process.argv[3];
if(!Number.isInteger(port)||port<1024||!root?.startsWith('/tmp/conversion_test_'))throw Error('Supply disposable fixture only');
const profile=fs.mkdtempSync(path.join(os.tmpdir(),'conversion-chrome-'));
const chrome=spawn('chromium',['--headless','--no-sandbox','--disable-gpu','--remote-debugging-pipe','--user-data-dir='+profile],{detached:true,stdio:['ignore','ignore','ignore','pipe','pipe']});
function stopChrome(signal){try{process.kill(-chrome.pid,signal);}catch(e){if(e.code!=='ESRCH')throw e;}}
let serial=0,buffer='',session;const pending=new Map(),errors=[];
chrome.stdio[4].on('data',chunk=>{
    buffer+=chunk.toString();
    for(let index;(index=buffer.indexOf('\0'))>=0;){
        const raw=buffer.slice(0,index);buffer=buffer.slice(index+1);if(!raw)continue;
        const msg=JSON.parse(raw),p=pending.get(msg.id);
        if(p){pending.delete(msg.id);msg.error?p.reject(Error(JSON.stringify(msg.error))):p.resolve(msg.result);}
        if(msg.method==='Runtime.exceptionThrown')errors.push(msg.params.exceptionDetails);
    }
});
function call(method,params={},sid=session){return new Promise((resolve,reject)=>{
    const id=++serial;pending.set(id,{resolve,reject});chrome.stdio[3].write(JSON.stringify({id,method,params,...(sid?{sessionId:sid}:{})})+'\0');
});}
async function run(expression){
    const r=await call('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});
    if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value;
}
const timeout=setTimeout(()=>{console.error('Browser timeout');stopChrome('SIGKILL');process.exitCode=1;},90000);
(async()=>{
    const target=await call('Target.createTarget',{url:'about:blank'});
    session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
    await call('Page.enable');await call('Runtime.enable');await call('Network.enable');
    await call('Network.setCookie',{name:'PHPSESSID',value:'prospecttest',url:`http://127.0.0.1:${port}`});
    await call('Emulation.setDeviceMetricsOverride',{width:1280,height:900,deviceScaleFactor:1,mobile:false});
    await call('Page.navigate',{url:`http://127.0.0.1:${port}/saas_portal/pages/prospects.php`});
    for(let i=0;i<100;i++){if(await run("document.readyState==='complete' && !!document.getElementById('prospectsList')"))break;await new Promise(r=>setTimeout(r,100));}
    const result=await run(`(async()=>{
        window.$=id=>document.getElementById(id);
        window.wait=async(fn,label)=>{for(let i=0;i<200;i++){if(fn())return;await new Promise(r=>setTimeout(r,40));}throw Error('Timeout '+label);};
        window.check=(ok,label)=>{if(!ok)throw Error(label);return label;};
        window.input=(id,value)=>{$(id).value=value;$(id).dispatchEvent(new Event('input',{bubbles:true}));};
        window.pick=async(q)=>{input('prospectSearch',q);await wait(()=>document.querySelectorAll('.prospect-row').length===1&&document.querySelector('.prospect-row').textContent.includes(q),'search '+q);document.querySelector('.prospect-row').click();await wait(()=>$('companyName').value===q,'detail '+q);};
        const passed=[];
        await wait(()=>document.querySelector('.prospect-row'),'initial list');
        await pick('BROWSER Ny');
        passed.push(check($('createProspectCompany').disabled,'Uninterested lead cannot convert'));
        $('prospectInterestToggle').click();
        passed.push(check($('createProspectCompany').disabled,'Unsaved interested status cannot convert'));
        $('saveProspect').click();await wait(()=>!$('createProspectCompany').disabled,'saved eligible');
        input('prospectNotes','Unsaved data');passed.push(check($('createProspectCompany').disabled,'Dirty form protected'));
        input('prospectNotes','Skal beholdes');await wait(()=>!$('createProspectCompany').disabled,'clean');
        const nativeFetch=window.fetch;let prepares=0,confirms=0,setupWarnings=[];
        window.fetch=async(url,opts)=>{
            const action=String(url).includes('prospect_company.php')?JSON.parse(opts.body).action:'';
            if(action==='prepare')prepares++;
            else if(action==='confirm'){confirms++;await new Promise(r=>setTimeout(r,150));}
            const response=await nativeFetch(url,opts);
            if(action==='prepare')setupWarnings=(await response.clone().json()).setup_warnings||[];
            return response;
        };
        $('createProspectCompany').click();
        await wait(()=>!$('prospectCompanyDetails').hidden&&!$('prospectCompanyLoading').hidden===false,'prepared');
        passed.push(check(prepares===1&&$('prospectCompanyDialog').open,'One click opens one preview'));
        passed.push(check(setupWarnings.every(text=>$('prospectCompanyWarning').textContent.includes(text)),'Deferred website setup explained before confirmation'));
        passed.push(check($('prospectCompanyEmail').textContent==='manuell@example.test','Manual contact retained'));
        passed.push(check($('prospectCompanyCandidateList').querySelectorAll('input:checked:not(:disabled)').length===2&&$('prospectCompanyCandidateList').querySelectorAll('input:disabled').length===1,'Only unambiguous candidates selectable'));
        input('prospectCompanySubdomain','BAD-DOMAIN');$('prospectCompanySubmit').click();
        passed.push(check($('prospectCompanySubdomain').getAttribute('aria-invalid')==='true'&&confirms===0,'Subdomain validation'));
        input('prospectCompanySubdomain','browsernew');
        $('prospectCompanySubmit').click();$('prospectCompanySubmit').click();
        passed.push(check($('prospectCompanySubdomain').disabled,'In-flight controls locked'));
        await wait(()=>!$('prospectCompanyResult').hidden,'created');
        passed.push(check(confirms===1&&$('prospectCompanyResult').textContent.includes('2 ansatte importert'),'One confirmation creates company and employees'));
        passed.push(check(setupWarnings.every(text=>$('prospectCompanyResult').textContent.includes(text)),'Deferred website setup retained in result'));
        passed.push(check($('prospectCompanyResult').querySelector('a').getAttribute('href').startsWith('/saas_portal/pages/company_details.php?id='),'Company link'));
        $('prospectCompanyCancel').click();passed.push(check(!$('prospectCompanyDialog').open,'Dialog closes after success'));
        $('createProspectCompany').click();await wait(()=>!$('prospectCompanyMatches').hidden,'existing');
        passed.push(check($('prospectCompanySubmit').hidden,'Existing company requires explicit selection'));
        $('prospectCompanyMatchList').querySelector('button').click();await wait(()=>!$('prospectCompanyDetails').hidden&&!$('prospectCompanySubmit').hidden,'import preview');
        passed.push(check($('prospectCompanySubdomain').readOnly&&$('prospectCompanyCandidateList').querySelectorAll('input:checked').length===0,'Existing company and employees not recreated'));
        $('prospectCompanySubmit').click();await wait(()=>!$('prospectCompanyResult').hidden,'existing result');
        passed.push(check(!$('prospectCompanyResult').textContent.includes('Ny bedrift opprettet'),'Existing import correctly described'));
        $('prospectCompanyCancel').click();
        window.fetch=nativeFetch;
        await pick('BROWSER Klar');
        $('createProspectCompany').click();await wait(()=>!$('prospectCompanyDetails').hidden&&!$('prospectCompanyLoading').hidden===false,'response loss preview');
        input('prospectCompanySubdomain','browserloss');
        let lose=true;
        window.fetch=async(url,opts)=>{const response=await nativeFetch(url,opts);if(String(url).includes('prospect_company.php')&&JSON.parse(opts.body).action==='confirm'&&lose){lose=false;throw Error('Simulert tapt svar');}return response;};
        $('prospectCompanySubmit').click();await wait(()=>$('prospectCompanyAlert').textContent.includes('Simulert'),'response loss');
        passed.push(check(!$('prospectCompanySubmit').disabled&&!$('prospectCompanyDetails').hidden,'Lost response preserves retry confirmation'));
        $('prospectCompanySubmit').click();await wait(()=>!$('prospectCompanyResult').hidden,'replayed result');
        passed.push(check($('prospectCompanyResult').textContent.includes('2 ansatte importert'),'Lost response safely replayed'));
        window.fetch=nativeFetch;$('prospectCompanyCancel').click();
        await pick('BROWSER Retry');
        $('createProspectCompany').click();await wait(()=>!$('prospectCompanyDetails').hidden&&!$('prospectCompanyLoading').hidden===false,'partial preview');
        passed.push(check(!$('prospectCompanyWarning').hidden,'Role outage explained before confirmation'));
        input('prospectCompanySubdomain','browserretry');$('prospectCompanySubmit').click();
        await wait(()=>!$('prospectCompanyResult').hidden,'partial result');
        passed.push(check(!$('prospectCompanyRetryImport').hidden&&$('prospectCompanyResult').textContent.includes('0 ansatte importert'),'Partial success offers import-only retry'));
        return passed;
    })()`);
    console.log('PASS browser:',result.join('; '));
    const partial=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
    fs.writeFileSync('/tmp/conversion-partial.png',Buffer.from(partial.data,'base64'));
    // Registry outage ends; retry uses the already-created company.
    const modes=JSON.parse(fs.readFileSync(root+'/registry-mode.json','utf8'));
    modes['900000003']={};fs.writeFileSync(root+'/registry-mode.json',JSON.stringify(modes));
    await run(`(async()=>{
        $('prospectCompanyRetryImport').click();
        await wait(()=>!$('prospectCompanySubmit').hidden&&!$('prospectCompanyLoading').hidden===false,'retry preview');
        check($('prospectCompanySubdomain').readOnly,'Retry only imports into existing company');
    })()`);
    let image=await call('Page.captureScreenshot',{format:'png'});
    fs.writeFileSync('/tmp/conversion-desktop.png',Buffer.from(image.data,'base64'));
    await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    await new Promise(r=>setTimeout(r,150));
    const mobile=await run(`(()=>{const r=$('prospectCompanyDialog').getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&document.documentElement.scrollWidth<=innerWidth;})()`);
    if(!mobile)throw Error('Mobile dialog overflow');
    image=await call('Page.captureScreenshot',{format:'png'});
    fs.writeFileSync('/tmp/conversion-mobile.png',Buffer.from(image.data,'base64'));
    await run(`(async()=>{$('prospectCompanySubmit').click();await wait(()=>!$('prospectCompanyResult').hidden,'retry completed');check($('prospectCompanyResult').textContent.includes('2 ansatte importert'),'Retry imports employees');$('prospectCompanyCancel').click();})()`);
    if(errors.length)throw Error('Browser exceptions: '+JSON.stringify(errors));
    console.log('PASS mobile and import-only retry; no uncaught browser exceptions.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{
    clearTimeout(timeout);
    chrome.once('close',()=>fs.rmSync(profile,{recursive:true,force:true,maxRetries:20,retryDelay:100}));
    stopChrome('SIGTERM');
});
