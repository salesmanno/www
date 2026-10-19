'use strict';
// Only a disposable localhost fixture created by prospects_test.php.
const {spawn} = require('node:child_process');
const fs=require('node:fs'), os=require('node:os'), path=require('node:path');
const port=Number(process.argv[2]);
if (!Number.isInteger(port) || port<1024 || port>65535) throw Error('Supply fixture port');
const profile=fs.mkdtempSync(path.join(os.tmpdir(),'prospect-chrome-'));
const browser=spawn('chromium',['--headless','--no-sandbox','--disable-gpu','--remote-debugging-pipe','--user-data-dir='+profile],{detached:true,stdio:['ignore','ignore','ignore','pipe','pipe']});
function stopBrowser(signal){try{process.kill(-browser.pid,signal);}catch(e){if(e.code!=='ESRCH')throw e;}}
let serial=0,buffer='';
const pending=new Map();
browser.stdio[4].on('data',chunk=>{
    buffer+=chunk.toString();
    for(let at;(at=buffer.indexOf('\0'))>=0;) {
        const raw=buffer.slice(0,at);buffer=buffer.slice(at+1);if(!raw)continue;
        const msg=JSON.parse(raw),p=pending.get(msg.id);
        if(p){pending.delete(msg.id);msg.error?p.reject(Error(JSON.stringify(msg.error))):p.resolve(msg.result);}
    }
});
function call(method,params={},sessionId) {
    return new Promise((resolve,reject)=>{
        const id=++serial;pending.set(id,{resolve,reject});
        browser.stdio[3].write(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})})+'\0');
    });
}
const timeout=setTimeout(()=>{console.error('Browser timeout');stopBrowser('SIGKILL');process.exitCode=1;},90000);
(async()=>{
    const target=await call('Target.createTarget',{url:'about:blank'});
    const session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
    await call('Page.enable',{},session);
    await call('Network.enable',{},session);
    await call('Network.setCookie',{name:'PHPSESSID',value:'prospecttest',url:`http://127.0.0.1:${port}`},session);
    await call('Page.navigate',{url:`http://127.0.0.1:${port}/saas_portal/pages/prospects.php`},session);
    for(let i=0;i<100;i++) {
        const ready=await call('Runtime.evaluate',{expression:"document.readyState === 'complete' && !!document.getElementById('prospectsList')",returnByValue:true},session);
        if(ready.result?.value)break;
        await new Promise(r=>setTimeout(r,100));
    }
    const result=await call('Runtime.evaluate',{awaitPromise:true,returnByValue:true,expression:`(async()=>{
        const $=id=>document.getElementById(id), pass=[];
        const wait=async(fn,label)=>{for(let i=0;i<160;i++){if(fn())return;await new Promise(r=>setTimeout(r,50));}throw Error('Timeout '+label);};
        const check=(ok,label)=>{if(!ok)throw Error(label);pass.push(label);};
        const input=(id,value)=>{$(id).value=value;$(id).dispatchEvent(new Event('input',{bubbles:true}));};
        const rows=()=>document.querySelectorAll('.prospect-row');
        const save=async()=>{$('saveProspect').click();await wait(()=>$('prospectSaveState').textContent==='Alle endringer lagret','saved');};
        await wait(()=>rows().length===20,'list');
        $('nextPage').click();await wait(()=>$('pageInfo').textContent==='Side 2 av 2'&&rows().length===6,'pagination');
        check(rows().length===6,'Next page uses real paginated data');
        $('newProspect').click();
        input('orgNr','12');input('companyName','Test');
        $('saveProspect').click();
        check($('orgNr').getAttribute('aria-invalid')==='true','Client validation');
        input('orgNr','888888888');input('companyName','Nettleser <img src=x onerror=alert(1)>');
        input('prospectStatus','Interessert - egen status');
        input('interestTags','{"modules":["Events"],"large":9007199254740993}');
        input('website','https://example.test'); input('contactEmail','kontakt@example.test');input('contactPhone','+47 12345678');
        await save();
        check($('prospectStatus').value==='Interessert - egen status','Custom status created');
        check($('interestTags').value.includes('9007199254740993'),'JSON large integer retained as text');
        check(!document.querySelector('.prospect-row img'),'Untrusted name rendered as text');
        check([...$('prospectContactLinks').querySelectorAll('a')].every(a=>/^(https:|mailto:|tel:)/.test(a.href)),'Safe contact links');
        input('prospectNotes','Behold dette ved feil');
        const originalFetch=window.fetch;
        window.fetch=async(url,options)=>{if(options?.method==='POST')throw Error('Simulert nettverksfeil');return originalFetch(url,options);};
        $('saveProspect').click();
        await wait(()=>$('prospectFormAlert').textContent.includes('Simulert'),'failed save');
        check($('prospectNotes').value==='Behold dette ved feil'&&!$('saveProspect').disabled,'Failed save preserves form and allows retry');
        window.fetch=originalFetch;
        $('registerContact').click();
        check(!!$('lastContactedAt').value,'Contact date set without immediate write');
        let posts=0;
        window.fetch=async(url,options)=>{if(options?.method==='POST'){posts++;await new Promise(r=>setTimeout(r,200));}return originalFetch(url,options);};
        $('saveProspect').click();$('saveProspect').click();
        await wait(()=>$('prospectSaveState').textContent==='Alle endringer lagret','saved retry');
        check(posts===1,'Double submit prevented');
        window.fetch=originalFetch;
        input('prospectNotes','Ulagret søk');
        input('prospectSearch','Nettleser');
        await wait(()=>rows().length===1,'search');
        check($('prospectNotes').value==='Ulagret søk'&&!$('saveProspect').disabled,'Searching preserves unsaved edits');
        await save();
        $('prospectStatusFilter').value='Interessert - egen status';$('prospectStatusFilter').dispatchEvent(new Event('change'));
        await wait(()=>rows().length===1,'status filter');
        check(rows()[0].textContent.includes('Nettleser'),'Status filter');
        window.fetch=async(url,options)=>{const r=await originalFetch(url,options);if(String(url).includes('q=Firma'))await new Promise(r=>setTimeout(r,800));return r;};
        input('prospectSearch','Firma');await new Promise(r=>setTimeout(r,300));input('prospectSearch','Nettleser');
        await new Promise(r=>setTimeout(r,1200));
        check(rows().length===1&&rows()[0].textContent.includes('Nettleser'),'Stale search cannot overwrite latest result');
        window.fetch=originalFetch;
        $('newProspect').click();input('orgNr','888888888');input('companyName','Duplikat');
        $('saveProspect').click();await wait(()=>$('orgNrError').textContent.includes('finnes allerede'),'duplicate');
        check($('companyName').value==='Duplikat','Duplicate rejected without clearing form');
        window.confirm=()=>true;rows()[0].click();
        await wait(()=>$('companyName').value.startsWith('Nettleser'),'reopen');
        input('prospectNotes','Konflikt - ikke mist');
        const item=(await(await originalFetch('/saas_portal/api/prospects.php?q=Nettleser')).json()).items[0];
        await originalFetch('/saas_portal/api/prospects.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...item,notes:'Annen operatør',action:'save',csrf_token:window.prospectsCsrf})});
        $('saveProspect').click();await wait(()=>$('prospectFormAlert').textContent.includes('beholdt'),'conflict');
        check($('prospectNotes').value==='Konflikt - ikke mist','Conflict preserves unsaved edits');
        rows()[0].click();await wait(()=>$('prospectNotes').value==='Annen operatør','conflict reload');
        check($('prospectNotes').value==='Annen operatør','Same row can reload conflict');
        input('prospectSearch','ingen-treff');await wait(()=>$('prospectsList').textContent.includes('Ingen treff'),'no results');
        input('prospectSearch','Nettleser');await wait(()=>rows().length===1,'restore');
        check(!!document.querySelector('label[for="companyName"]'),'Visible labels');
        $('prospectStatusFilter').value='';$('prospectStatusFilter').dispatchEvent(new Event('change'));
        input('prospectSearch','HTTP bedrift');await wait(()=>rows().length===1&&rows()[0].textContent.includes('HTTP bedrift'),'legacy row');
        rows()[0].click();await wait(()=>$('companyName').value==='HTTP bedrift','legacy detail');
        check($('interestTags').value==='{bad legacy'&&!$('interestTagsWarning').hidden,'Invalid legacy JSON retained and warned');
        check(!$('prospectFormAlert').hidden&&$('prospectFormAlert').textContent.includes('kontaktdato'),'Invalid legacy date warned instead of silently cleared');
        input('interestTags','null');
        $('saveProspect').click();await wait(()=>$('lastContactedAtError').textContent.includes('gyldig'),'legacy date rejection');
        check($('companyName').value==='HTTP bedrift','Invalid legacy date blocks silent data loss');
        input('lastContactedAt','2026-10-19T12:00:00');await save();
        input('prospectSearch','Nettleser');await wait(()=>rows().length===1&&rows()[0].textContent.includes('Nettleser'),'restore screenshot row');
        rows()[0].click();await wait(()=>$('companyName').value.startsWith('Nettleser'),'screenshot detail');
        return pass;
    })()`},session);
    if(result.exceptionDetails)throw Error(JSON.stringify(result.exceptionDetails));
    console.log('PASS browser:',result.result.value.join('; '));
    await call('Emulation.setDeviceMetricsOverride',{width:1280,height:1000,deviceScaleFactor:1,mobile:false},session);
    await call('Runtime.evaluate',{expression:'window.scrollTo(0,0)'},session);
    let image=await call('Page.captureScreenshot',{format:'png'},session);
    fs.writeFileSync('/tmp/prospects-desktop.png',Buffer.from(image.data,'base64'));
    await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true},session);
    await new Promise(r=>setTimeout(r,150));
    const layout=await call('Runtime.evaluate',{returnByValue:true,expression:"document.documentElement.scrollWidth <= window.innerWidth"},session);
    if(!layout.result.value)throw Error('Mobile horizontal overflow');
    image=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true},session);
    fs.writeFileSync('/tmp/prospects-mobile.png',Buffer.from(image.data,'base64'));
    console.log('PASS mobile: no horizontal overflow. Fixture screenshots saved in /tmp.');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(()=>{
    clearTimeout(timeout);
    browser.once('close',()=>fs.rmSync(profile,{recursive:true,force:true,maxRetries:20,retryDelay:100}));
    stopBrowser('SIGTERM');
});
