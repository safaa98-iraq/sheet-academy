import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function fixture({covered = false, offscreen = false} = {}) {
    class Element {
        constructor(rect, style = {}) { this.rect = rect; this.style = {opacity:'1',display:'block',visibility:'visible',contentVisibility:'visible',overflowX:'visible',overflowY:'visible',...style}; this.isConnected = true; }
        getBoundingClientRect() { return this.rect; }
        matches() { return false; }
        contains(node) { return node === this; }
    }
    const canvas = new Element({left:0,right:220,top:120,bottom:400}, {overflowY:'auto'});
    const container = new Element({left:0,right:220,top:0,bottom:600}); container.parentElement = canvas;
    const mark = new Element({left:60,right:160,top:offscreen ? 0 : 60,bottom:offscreen ? 80 : 160,width:100,height:offscreen ? 80 : 100}); mark.textContent = 'student'; mark.parentElement = container;
    let marks = [mark], violations = 0;
    container.querySelectorAll = () => marks;
    const image = new Element({}); image.matches = () => true;
    const overlay = new Element({});
    const context = vm.createContext({Element,document:{hidden:false,documentElement:{},elementFromPoint:(_x,y)=>covered || y<120 ? overlay : image,addEventListener(){}},window:{addEventListener(){}},innerWidth:500,innerHeight:500,getComputedStyle:node=>node.style,MutationObserver:class{observe(){}disconnect(){}},setInterval:()=>1,clearInterval(){},queueMicrotask:fn=>fn()});
    vm.runInContext(fs.readFileSync(new URL('../../resources/js/watermark-guard.js',import.meta.url),'utf8').replaceAll('export ','')+'\nglobalThis.guard=guardWatermark;',context);
    const guard = context.guard({container,selector:'watermark',text:'student',onTamper:()=>violations++});
    return {guard,mark,remove:()=>{marks=[];},violations:()=>violations};
}

test('scroll clipping checks the visible part instead of the toolbar above it',()=>{const f=fixture();assert.equal(f.violations(),0);f.guard.inspect();assert.equal(f.violations(),0)});
test('a watermark outside the scroll viewport remains intact without a false violation',()=>{const f=fixture({offscreen:true});assert.equal(f.violations(),0)});
test('covering the visible watermark still blocks content',()=>{const f=fixture({covered:true});assert.equal(f.violations(),1)});
test('removing or making the watermark transparent still blocks content',()=>{const removed=fixture();removed.remove();removed.guard.inspect();assert.equal(removed.violations(),1);const hidden=fixture();hidden.mark.style.opacity='0';hidden.guard.inspect();assert.equal(hidden.violations(),1)});
