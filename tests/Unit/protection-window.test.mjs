import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function fixture() {
    let clock=10000, inspect;
    const events=[], listeners=new Map();
    class Element { matches(){return false} }
    class Input extends Element { matches(){return true} }
    const document={body:{dataset:{studentActivityUrl:'/activity',protected:'true'},classList:{toggle(){},add(){}}},activeElement:null,head:{append(){}},createElement:()=>({dataset:{}}),querySelectorAll:()=>[],visibilityState:'visible',addEventListener:(name,fn)=>{const list=listeners.get(name)||[];list.push(fn);listeners.set(name,list)},dispatchEvent:event=>{events.push(event.type);for(const fn of listeners.get(event.type)||[])fn(event)}};
    const window={outerWidth:1400,outerHeight:900,innerWidth:1400,innerHeight:900,print(){},addEventListener(){},setInterval:fn=>{inspect=fn}};
    let coarse=false;
    const context=vm.createContext({document,window,Element,HTMLInputElement:Input,localStorage:{getItem:()=>null,setItem(){}},matchMedia:()=>({matches:coarse}),Date:{now:()=>clock},CustomEvent:class{constructor(type,options){this.type=type;this.detail=options?.detail}}});
    vm.runInContext(fs.readFileSync(new URL('../../resources/js/protection.js',import.meta.url),'utf8').replaceAll('export ','')+'\ninitProtection();',context);
    return {window,document,events,inspect:()=>inspect(),time:value=>clock=value,keyboard:()=>{coarse=true;document.activeElement=new Input()},key:event=>{for(const fn of listeners.get('keydown')||[])fn(event)}};
}

test('a transient resize gap does not stop protected content',()=>{const f=fixture();f.window.innerWidth=900;f.inspect();f.time(12000);f.window.innerWidth=1400;f.inspect();assert(!f.events.includes('student:session-ended'))});
test('a stable developer-tools-sized gap still stops and reports after confirmation',()=>{const f=fixture();f.window.innerWidth=900;f.inspect();f.time(14000);f.inspect();assert(f.events.includes('protection:devtools'));assert(f.events.includes('student:session-ended'))});
test('the mobile keyboard does not masquerade as developer tools',()=>{const f=fixture();f.keyboard();f.window.innerHeight=400;f.inspect();f.time(14000);f.inspect();assert(!f.events.includes('student:session-ended'))});
test('the developer-tools shortcut still blocks immediately',()=>{const f=fixture();let prevented=false;f.key({key:'F12',target:null,preventDefault:()=>prevented=true});assert(prevented);assert(f.events.includes('student:session-ended'))});
