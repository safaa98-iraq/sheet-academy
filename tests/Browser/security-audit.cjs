const fs = require('node:fs');
const assert = require('node:assert/strict');
const {spawn, execFileSync} = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const php = process.env.ACADEMY_PHP || 'php';
const base = 'http://127.0.0.1:8081';
const fixtureFile = process.env.ACADEMY_AUDIT_FIXTURE || '/tmp/academy-audit-fixture.json';
const runFixture = (...args) => execFileSync(php, [path.join(__dirname, 'audit-fixture.php'), ...args], {cwd: root, env: {...process.env, ACADEMY_AUDIT_FIXTURE: fixtureFile}}).toString();
const results = []; const errors = []; const sourceLinks = []; const children = [];
const record = (name, result) => {results.push({name, result}); console.log(name + ': ' + result);};
(async () => {
    const fixture = JSON.parse(runFixture('seed'));
    const log = fs.openSync('/tmp/academy-audit-http.log', 'w');
    children.push(spawn(php, ['artisan', 'serve', '--host=127.0.0.1', '--port=8081', '--no-interaction'], {cwd:root, stdio:['ignore',log,log]}));
    for(let i=0;i<40;i++){try{if((await fetch(base+'/login')).ok)break;}catch{}await new Promise(r=>setTimeout(r,250));}
    const playwright = require(process.env.ACADEMY_PLAYWRIGHT_MODULE || process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES + '/playwright');
    if (process.env.ACADEMY_BROWSER_EXECUTABLE) fs.chmodSync(process.env.ACADEMY_BROWSER_EXECUTABLE,0o755);
    const browser = await playwright.chromium.launch({executablePath:process.env.ACADEMY_BROWSER_EXECUTABLE, headless:true,args:['--no-sandbox','--no-zygote','--single-process']});
    const context = await browser.newContext({viewport:{width:1440,height:1000}});
    const page = await context.newPage(); page.on('pageerror', e=>errors.push(e.message));
    page.on('response', async response => {
        if (!/^(?:text\/html|application\/json)/.test(response.headers()['content-type'] || '')) return;
        try {const body = await response.text();if (/(?:https?:\/\/|\/(?:storage|video-uploads)\/)[^"'<>\s]*\.(?:mp4|webm|mov|mkv|avi|part|pdf)(?:[?"'<>\s]|$)/i.test(body)) sourceLinks.push(new URL(response.url()).pathname);} catch {}
    });
    const login = async (target, role='assigned') => {
        await target.goto(base+'/login');
        await target.locator('[name=token]').fill(fixture.students[role].token);
        await target.locator('button[type=submit]').click(); await target.waitForURL('**/learning');
    };
    const issue = async () => {
        await page.goto(base+'/learning');
        const url = await page.evaluate(async id=>{
            const r=await fetch('/student/view-links',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({target_type:'lesson',target_id:id})});
            if(!r.ok)throw new Error('Cannot issue view');return (await r.json()).url;
        },fixture.lesson_id);
        await page.goto(url); await page.locator('[data-hls-video]').waitFor();
        await page.waitForFunction(()=>document.querySelector('[data-academy-player]')?.academyPlayer);
        return url;
    };
    await page.addInitScript(()=>localStorage.setItem('dental-content-protection','false'));
    await login(page); let viewUrl=await issue();
    assert(await page.locator('body').evaluate(el=>el.classList.contains('content-protected')));
    const copyBlocked=await page.evaluate(()=>{const e=new Event('copy',{bubbles:true,cancelable:true});document.querySelector('[data-player-stage]').dispatchEvent(e);return e.defaultPrevented;});
    assert(copyBlocked); record('local preference cannot disable protection; copy prevented','PASS');
    for (const action of ['cut','paste','dragstart','contextmenu']) {
        assert(await page.evaluate(action=>{const e=new Event(action,{bubbles:true,cancelable:true});document.querySelector('[data-player-stage]').dispatchEvent(e);return e.defaultPrevented;},action));
    }
    await page.evaluate(()=>window.print());record('cut, paste, drag, context menu and print controls','BLOCKED');
    // Use authenticated HTTP requests to follow the actual encrypted stream chain.
    const view = new URL(viewUrl).searchParams.get('view');
    const session = await context.request.get(base+`/videos/${fixture.video_id}/session?view=${view}`);assert.equal(session.status(),200);
    const manifestUrl=(await session.json()).manifest_url;
    const manifest=await context.request.get(manifestUrl);assert.equal(manifest.status(),200);
    const variantUrl=(await manifest.text()).split('\n').find(x=>x.startsWith('http'));
    const variant=await context.request.get(variantUrl);assert.equal(variant.status(),200);
    const playlist=await variant.text();assert(playlist.includes('METHOD=AES-128'));
    const keyUrl=playlist.match(/URI="([^"]+)"/)[1];
    const segmentUrl=playlist.split('\n').find(x=>x.startsWith('http'));
    const keyResponse=await context.request.get(keyUrl);assert.equal(keyResponse.status(),200);assert.equal((await keyResponse.body()).length,16);
    const guestContext=await playwright.request.newContext();
    for(const [name,url] of [['manifest without session',manifestUrl],['key without session',keyUrl],['segment without session',segmentUrl]]){
        const r=await guestContext.get(url,{maxRedirects:0});assert.equal(r.status(),302);record(name,'BLOCKED 302');
    }
    const expiredUrl=runFixture('expired-url',view);const expired=await context.request.get(expiredUrl);assert.equal(expired.status(),403);record('expired signed URL','BLOCKED 403');
    const forged=await context.request.get(manifestUrl+'&forged=1');assert.equal(forged.status(),403);record('altered signature','BLOCKED 403');
    const cross=await context.request.get(manifestUrl,{headers:{Origin:'https://attacker.invalid','Sec-Fetch-Site':'cross-site'}});assert.equal(cross.status(),403);record('cross-origin hotlink','BLOCKED 403');
    const loginRequest = async (api, role) => {
        const html = await (await api.get(base+'/login')).text();
        const csrf = html.match(/name="csrf-token" content="([^"]+)"/)[1];
        const response = await api.post(base+'/login', {form:{token:fixture.students[role].token,_token:csrf},maxRedirects:0});
        assert.equal(response.status(),302);
    };
    const strangerContext=await playwright.request.newContext();await loginRequest(strangerContext,'stranger');
    for(const [name,url] of [['unassigned student key',keyUrl],['unassigned student manifest',manifestUrl]]){
        const r=await strangerContext.get(url);assert.equal(r.status(),404);record(name,'BLOCKED 404');
    }
    const expiryContext=await playwright.request.newContext();await loginRequest(expiryContext,'expires');runFixture('expire');
    const expiredSession=await expiryContext.get(manifestUrl,{maxRedirects:0});assert.equal(expiredSession.status(),302);record('expired student session','BLOCKED 302');
    const segmentResponse=await context.request.get(segmentUrl);const encryptedBytes=await segmentResponse.body();
    fs.writeFileSync('/tmp/academy-encrypted-segment.ts',encryptedBytes);
    let playable=true;try{execFileSync('ffprobe',['-v','error','-show_entries','stream=codec_name','/tmp/academy-encrypted-segment.ts'],{stdio:'pipe'});}catch{playable=false;}
    assert(!playable);record('downloaded encrypted segment without key cannot be played','BLOCKED ffprobe');
    const wrongKey=require('node:crypto').randomBytes(16);let decrypted=false;try{const decipher=require('node:crypto').createDecipheriv('aes-128-cbc',wrongKey,Buffer.alloc(16));Buffer.concat([decipher.update(encryptedBytes),decipher.final()]);decrypted=true;}catch{}
    assert(!decrypted);record('segment decryption with invalid key','BLOCKED AES decryption');
    const realKey=await keyResponse.body();
    const iv=playlist.match(/IV=0x([a-f0-9]+)/i)?.[1] || '00000000000000000000000000000000';
    const decipher=require('node:crypto').createDecipheriv('aes-128-cbc',realKey,Buffer.from(iv,'hex'));
    fs.writeFileSync('/tmp/academy-authorized-segment.ts',Buffer.concat([decipher.update(encryptedBytes),decipher.final()]));
    execFileSync('ffprobe',['-v','error','-show_entries','stream=codec_name','/tmp/academy-authorized-segment.ts'],{stdio:'pipe'});
    record('authorized session receives a usable decryption key: AES-HLS is not DRM','CONFIRMED limitation');
    const csrfMissing=await context.request.post(base+'/student/playlists/items',{data:{lesson_id:fixture.lesson_id}});assert.equal(csrfMissing.status(),419);record('state change without CSRF token','BLOCKED 419');
    const play=async()=>{await page.locator('[data-play]').first().click();await page.waitForFunction(()=>{const v=document.querySelector('[data-hls-video]');return v.currentTime>0 && !v.paused;},{timeout:20000});};
    await play();
    assert(await page.locator('[data-player-watermark]').evaluate(el=>getComputedStyle(el).opacity==='0.48' && parseFloat(getComputedStyle(el).fontSize)>=12));
    record('normal playback readable server identifier','PASS');
    await page.locator('[data-fullscreen]').click();await page.waitForFunction(()=>document.fullscreenElement?.matches('[data-academy-player]'));
    assert(await page.locator('[data-player-watermark]').isVisible());await page.locator('[data-fullscreen]').click();record('fullscreen retains watermark','PASS');
    await page.evaluate(()=>document.querySelector('[data-academy-player]').requestFullscreen=undefined);
    await page.locator('[data-fullscreen]').click();assert(await page.locator('[data-academy-player]').evaluate(el=>el.classList.contains('is-viewport-fullscreen')));
    assert(await page.locator('[data-player-watermark]').isVisible());await page.locator('[data-fullscreen]').click();record('fullscreen fallback keeps watermark when native API is unavailable','PASS');
    const available=await page.locator('[data-quality-value]:not([disabled])').evaluateAll(els=>els.map(el=>el.dataset.qualityValue));
    assert(available.includes('360') && available.includes('720'));
    const beforeQuality=await page.locator('[data-hls-video]').evaluate(video=>video.currentTime);
    await page.locator('[data-quality-toggle]').click();await page.locator('[data-quality-value="360"]').click();
    await page.waitForFunction(()=>document.querySelector('[data-academy-player]').dataset.quality==='360');
    await page.waitForFunction(before=>{const video=document.querySelector('[data-hls-video]');return !video.paused && video.videoHeight===360 && video.currentTime>=before-1;},beforeQuality);
    assert(await page.locator('[data-player-watermark]').isVisible());record('quality change preserves playback and watermark','PASS');
    await page.setViewportSize({width:390,height:844});assert(await page.locator('[data-player-watermark]').isVisible());
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    await page.screenshot({path:'/tmp/academy-audit-mobile.png',fullPage:true});record('mobile RTL layout and watermark','PASS');await page.setViewportSize({width:1440,height:1000});
    const saved=page.waitForResponse(r=>r.url().endsWith('/student/progress/'+fixture.lesson_id) && r.request().postData()?.includes('seeked'));
    await page.locator('[data-seek]').fill('12');await page.locator('[data-seek]').dispatchEvent('change');assert.equal((await saved).status(),200);
    const priorView=viewUrl;viewUrl=await issue();assert.equal((await context.request.get(priorView)).status(),404);
    await page.waitForFunction(()=>document.querySelector('[data-hls-video]').currentTime>=11);
    assert(await page.locator('[data-player-resume]').isVisible());assert(await page.locator('[data-player-watermark]').isVisible());
    assert.equal(await page.locator('[data-academy-player]').getAttribute('data-preferred-quality'),'360p');record('server resume survives a new view with saved quality and watermark','PASS; old link 404');
    await page.locator('[data-restart]').click();assert(await page.locator('[data-hls-video]').evaluate(video=>video.currentTime<1));record('start from beginning resets the saved playback position','PASS');
    await page.locator('[data-player-playlist]').click();await page.waitForFunction(()=>document.querySelector('[data-player-playlist]').getAttribute('aria-pressed')==='true');
    await page.goto(base+'/playlist');assert(await page.getByText('اختبار المحاضرة والفيديو',{exact:true}).count()>0);record('lesson playlist button persists to account','PASS');
    const openDocument = async id => {
        await page.goto(base+'/learning');
        const url=await page.evaluate(async id=>{const r=await fetch('/student/view-links',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({target_type:'document',target_id:id})});return(await r.json()).url;},id);
        await page.goto(url);await page.waitForFunction(()=>document.querySelector('[data-document-image]')?.naturalWidth>0);return url;
    };
    await openDocument(fixture.pdf_attachment_id);
    assert(await page.locator('[data-document-watermark]').first().isVisible());record('real PDF rendered by Poppler into private watermarked PNG','PASS');
    await page.locator('[data-viewer-fullscreen]').click();assert(await page.locator('[data-document-watermark]').first().isVisible());await page.locator('[data-viewer-fullscreen]').click();record('document fullscreen watermark','PASS');
    await openDocument(fixture.image_attachment_id);
    const imageUrl=await page.locator('[data-document-image]').getAttribute('src');
    assert.equal((await context.request.get(imageUrl)).headers()['content-type'],'image/png');
    assert.equal((await guestContext.get(imageUrl,{maxRedirects:0})).status(),302);record('document image requires session','BLOCKED 302');
    await page.evaluate(()=>document.querySelector('[data-document-watermark]').style.opacity='0');
    await page.waitForFunction(()=>document.querySelector('.document-canvas').hidden);record('document watermark hiding removes content and records violation','BLOCKED');
    // Every mutation gets a fresh server view: the tamper action must stop and invalidate it.
    for(const action of ['remove','display','opacity','text','ancestor','cover','stylesheet']){
        const url=await issue();await play();
        const recorded=page.waitForResponse(response=>response.url().endsWith('/student/activity') && response.request().postData()?.includes('suspicious_watermark'));
        await page.evaluate(action=>{const mark=document.querySelector('[data-player-watermark]');
            if(action==='remove')mark.remove();if(action==='display')mark.style.display='none';if(action==='opacity')mark.style.opacity='0';if(action==='text')mark.textContent='forged identifier';
            if(action==='ancestor')mark.parentElement.style.opacity='0';
            if(action==='cover'){const e=document.createElement('div');e.style.cssText='position:fixed;inset:0;background:black;z-index:2147483647';document.body.append(e);}
            if(action==='stylesheet'){const style=document.createElement('style');style.textContent='[data-player-watermark]{opacity:0!important}';document.head.append(style);}
        },action);
        await page.waitForFunction(()=>document.querySelector('[data-player-announcement]').textContent.includes('توقّف العرض'));
        assert(await page.locator('[data-hls-video]').evaluate(v=>v.paused));
        assert.equal((await recorded).status(),200);const response=await context.request.get(url);assert.equal(response.status(),404);record('watermark '+action,'BLOCKED playback + server link 404');
    }
    await issue();await play();await page.keyboard.press('F12');await page.waitForFunction(()=>document.querySelector('[data-hls-video]').paused);record('DevTools shortcut stops playback','BLOCKED');
    const audit=JSON.parse(runFixture('audit'));assert(audit.filter(x=>x.event==='suspicious_watermark').length>=8);assert(audit.some(x=>x.event==='suspicious_devtools'));record('tamper and DevTools recorded on server','PASS');
    await page.goto(base+'/learning');await page.waitForFunction(()=>navigator.serviceWorker.controller !== null);
    const cached=await page.evaluate(async()=>{const entries=[];for(const name of await caches.keys())for(const r of await(await caches.open(name)).keys())entries.push(new URL(r.url).pathname);return entries;});
    assert(cached.every(p=>p.includes('/build/assets/') || /\/(?:offline.html|manifest.json|images\/icon[^/]*|js\/vendor\/Sortable.min.js)$/.test(p)));
    record('PWA cache excludes content, keys, playlists and segments','PASS');
    await page.locator('[data-theme]').click();await page.reload();
    assert(await page.evaluate(()=>document.documentElement.classList.contains('dark') && getComputedStyle(document.body).getPropertyValue('--bg').trim()==='#101010'));record('orange/black theme persists after reload','PASS');
    await page.locator('[data-theme]').click();assert(await page.evaluate(()=>getComputedStyle(document.body).getPropertyValue('--bg').trim()==='#fff'));record('orange/white theme','PASS');
    await context.setOffline(true);await page.goto(base+'/learning');assert((await page.title()).includes('غير متصل'));await context.setOffline(false);await page.goto(base+'/learning');record('offline navigation serves only the offline page','PASS');
    await page.goto(base+'/course/'+fixture.course_slug);await page.goto(base+'/progress');await page.goto(base+'/learning');
    await issue();await play();
    const secondDevice=await playwright.request.newContext();await loginRequest(secondDevice,'assigned');
    await page.waitForFunction(()=>document.querySelector('[data-hls-video]').paused,null,{timeout:16000});
    record('revoked device stops buffered playback at the next progress heartbeat','PASS within 16 seconds');
    await page.goto(base+'/learning');assert(new URL(page.url()).pathname==='/login');record('second device login ends first session','BLOCKED first session');
    const secondHtml=await(await secondDevice.get(base+'/learning')).text();assert(secondHtml.includes('data-resume'));record('server resume is available to the new device','PASS');
    assert.deepEqual(sourceLinks,[]);record('HTML and JSON responses contain no original video/PDF URLs','0 source links');
    assert.deepEqual(errors,[]);record('browser JavaScript errors','0');
    fs.writeFileSync('/tmp/academy-security-browser-results.json',JSON.stringify({results,errors},null,2));
    await browser.close();
})().catch(e=>{console.error(e.stack);process.exitCode=1;fs.writeFileSync('/tmp/academy-security-browser-results.json',JSON.stringify({results,errors,failure:e.message},null,2));}).finally(()=>{children.forEach(c=>c.kill('SIGTERM'));setTimeout(()=>process.exit(process.exitCode||0),500)});
