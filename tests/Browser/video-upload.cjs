// Local integration: real authoring UI, resumable upload, Redis/BullMQ, FFmpeg and callback.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {spawn, execFileSync} = require('node:child_process');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '../..');
const php = process.env.ACADEMY_PHP || 'php';
const base = 'http://127.0.0.1:8081';
const children = [], results = [];
const record = (name, result) => {results.push({name, result}); console.log(name+': '+result);};
const wait = ms => new Promise(resolve=>setTimeout(resolve,ms));
let browser;
(async()=>{
    const env = {...process.env};
    const token = fs.readFileSync(path.join(root,'.env'),'utf8').match(/^VIDEO_WORKER_TOKEN=(.+)$/m)[1].replace(/^"|"$/g,'');
    const log = fs.openSync('/tmp/academy-upload-services.log','w');
    const start = (bin,args,options={}) => {const child=spawn(bin,args,{cwd:root,env,stdio:['ignore',log,log],...options});children.push(child);return child;};
    start(process.env.ACADEMY_REDIS || 'redis-server',['--bind','127.0.0.1','--port','6383','--save','','--appendonly','no'],{env:{...env,LD_LIBRARY_PATH:process.env.ACADEMY_REDIS_LIB || env.LD_LIBRARY_PATH || ''}});
    start(process.execPath,['index.js'],{cwd:path.join(root,'video-worker'),env:{...env,PORT:'3300',REDIS_URL:'redis://127.0.0.1:6383',VIDEO_WORKER_TOKEN:token,PRIVATE_STORAGE_ROOT:path.join(root,'storage/app/private'),INTERNAL_CALLBACK_URL:base+'/internal/video-processing',FFMPEG_PRESET:'ultrafast'}});
    const password=crypto.randomBytes(24).toString('hex');
    const fixture='/tmp/academy-upload-fixture.json';
    const data=JSON.parse(execFileSync(php,[path.join(__dirname,'audit-fixture.php'),'upload-setup',password],{cwd:root,env:{...env,ACADEMY_AUDIT_FIXTURE:fixture},stdio:'pipe'}));
    start(php,['artisan','serve','--host=127.0.0.1','--port=8081','--no-interaction']);
    for(let i=0;i<80;i++){try{if((await fetch(base+'/admin/login')).ok)break;}catch{}await wait(250);}
    const playwright=require(process.env.ACADEMY_PLAYWRIGHT_MODULE || process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES+'/playwright');
    if (process.env.ACADEMY_BROWSER_EXECUTABLE) fs.chmodSync(process.env.ACADEMY_BROWSER_EXECUTABLE,0o755);
    browser=await playwright.chromium.launch({executablePath:process.env.ACADEMY_BROWSER_EXECUTABLE,headless:true,args:['--no-sandbox','--no-zygote','--single-process']});
    const context=await browser.newContext();const page=await context.newPage();
    await page.goto(base+'/admin/login');
    await page.locator('[name=email]').fill(data.teacher_email);await page.locator('[name=password]').fill(password);
    await page.locator('button[type=submit]').click();
    if (new URL(page.url()).pathname==='/admin/login') {
        throw new Error('Teacher login refused: '+await page.locator('form').innerText());
    }
    await page.waitForURL('**/admin');
    await page.goto(base+'/admin/lessons');await page.locator('[data-lecture-grade]').selectOption(String(data.grade_id));
    await page.locator('[data-lecture-course]').selectOption(String(data.course_id));
    await page.getByRole('link',{name:'إضافة محاضرة',exact:true}).click();
    await page.locator('[name=title]').fill('اختبار المحاضرة والفيديو');
    await page.locator('[data-rich-editor]').fill('شرح تجريبي لتشريح الأسنان');
    await page.locator('[name=publication_status]').selectOption('published');
    await page.locator('#lecture-content-form button.btn.primary').click();await page.waitForURL('**/lessons/*/edit');
    const lessonId=Number(new URL(page.url()).pathname.match(/lessons\/(\d+)/)[1]);
    record('stage → course → lecture created through teacher UI','PASS');
    const csrf=await page.locator('meta[name=csrf-token]').getAttribute('content');
    execFileSync('ffmpeg',['-v','error','-y','-f','lavfi','-i','testsrc2=size=1280x720:rate=24','-f','lavfi','-i','sine=frequency=440:sample_rate=44100','-t','45','-c:v','libx264','-preset','ultrafast','-c:a','aac','/tmp/academy-upload.mp4'],{stdio:'pipe'});
    const bytes=fs.readFileSync('/tmp/academy-upload.mp4');
    const headers={'Tus-Resumable':'1.0.0','X-CSRF-TOKEN':csrf,Accept:'application/json'};
    const created=await context.request.post(base+`/admin/lessons/${lessonId}/video-uploads`,{headers:{...headers,'Upload-Length':String(bytes.length),'Upload-Metadata':'filename '+Buffer.from('lecture.mp4').toString('base64')+',fingerprint '+Buffer.from(crypto.createHash('sha256').update(bytes).digest('hex')).toString('base64')}});
    assert.equal(created.status(),201);const location=created.headers().location;
    const chunk=async(offset,end)=>context.request.patch(location,{headers:{...headers,'Upload-Offset':String(offset),'Content-Type':'application/offset+octet-stream'},data:bytes.subarray(offset,end)});
    assert.equal((await chunk(0,131072)).status(),204);
    const head=await context.request.head(location,{headers});assert.equal(head.status(),200);assert.equal(Number(head.headers()['upload-offset']),131072);
    record('interrupted upload resumes from server HEAD offset','PASS 131072 bytes');
    const wrong=await chunk(0,1024);assert([409,422].includes(wrong.status()));record('wrong chunk offset','BLOCKED '+wrong.status());
    for(let offset=131072;offset<bytes.length;offset+=4194304) assert.equal((await chunk(offset,Math.min(bytes.length,offset+4194304))).status(),204);
    const states=new Set();let status;
    for(let i=0;i<240;i++){
        const response=await context.request.get(base+`/admin/lessons/${lessonId}/video-status`);assert.equal(response.status(),200);
        status=await response.json();states.add(status.status);
        if(status.status==='failed')throw new Error(status.error);
        if(status.status==='ready')break;await wait(500);
    }
    assert.equal(status.status,'ready');assert.deepEqual(status.resolutions.map(Number).sort((a,b)=>a-b),[360,480,720]);
    record('real Redis queue → FFmpeg → authenticated callback','PASS '+[...states].join(' → '));
    record('generated adaptive resolutions','360 / 480 / 720');
    await page.reload();assert(await page.getByText('جاهز',{exact:true}).count()>0);
    assert.equal(await page.locator('[name=type]').inputValue(),'video');record('upload updates lesson type and teacher status','PASS');
    const unauthorized=await fetch('http://127.0.0.1:3300/jobs',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});assert.equal(unauthorized.status,401);record('worker API without service token','BLOCKED 401');
    fs.writeFileSync('/tmp/academy-video-upload-results.json',JSON.stringify({results,lessonId,bytes:bytes.length},null,2));
})().catch(error=>{console.error(error.stack);process.exitCode=1;fs.writeFileSync('/tmp/academy-video-upload-results.json',JSON.stringify({results,failure:error.message},null,2));}).finally(async()=>{if(browser)await browser.close();children.forEach(child=>child.kill('SIGTERM'));setTimeout(()=>process.exit(process.exitCode||0),500);});
