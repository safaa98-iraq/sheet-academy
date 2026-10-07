import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { getProgress, saveProgress } from '../../resources/js/progress-store.js';

const values = new Map();
const events = [];
globalThis.document = { body: { dataset: { storageScope: 'test-student' } }, dispatchEvent: event => events.push(event) };
globalThis.CustomEvent = class { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } };
const storage = { getItem: key => values.get(key) ?? null, setItem: (key,value) => values.set(key,value) };
beforeEach(() => { values.clear(); events.length = 0; document.body.dataset.storageScope = `student-${Math.random()}`; globalThis.localStorage = storage; });

test('progress survives a reload and keeps separate lesson positions', () => {
    saveProgress('anatomy', {position:120, watched:140, duration:750, completed:false});
    saveProgress('dental', {position:50, watched:50, duration:300});
    assert.equal(getProgress('anatomy').position,120);
    assert.equal(getProgress('dental').position,50);
    assert.ok(getProgress('anatomy').updatedAt);
});
test('accounts cannot see each other’s local progress', () => {
    saveProgress('lesson-1',{position:200,duration:300,completed:true});
    document.body.dataset.storageScope='different-student';
    assert.equal(getProgress('lesson-1').position,0);
    assert.equal(getProgress('lesson-1').completed,false);
});
test('positions are finite and clamped to the lesson duration', () => {
    saveProgress('lesson-1',{position:9999,watched:9999,duration:600});
    assert.equal(getProgress('lesson-1').position,600);
    assert.equal(getProgress('lesson-1').watched,600);
    saveProgress('lesson-1',{position:-10,watched:Infinity});
    assert.equal(getProgress('lesson-1').position,0);
    assert.equal(getProgress('lesson-1').watched,0);
});
test('partial updates preserve completion and report a progress event', () => {
    saveProgress('lesson-1',{position:30,watched:100,duration:300,completed:true});
    saveProgress('lesson-1',{position:60});
    assert.equal(getProgress('lesson-1').completed,true);
    assert.equal(getProgress('lesson-1').watched,100);
    assert.equal(events.at(-1).type,'academy:progress');
    assert.equal(events.at(-1).detail.lessonId,'lesson-1');
});
test('blocked storage retains progress for the current visit', () => {
    globalThis.localStorage={getItem(){throw new Error('blocked')},setItem(){throw new Error('blocked')}};
    saveProgress('lesson-1',{position:240,duration:600,completed:true});
    assert.equal(getProgress('lesson-1').position,240);
    assert.equal(getProgress('lesson-1').completed,true);
    assert.ok(events.some(event=>event.type==='academy:storage-unavailable'));
});
test('corrupted saved data does not break the player', () => {
    values.set(`academy:progress:${document.body.dataset.storageScope}:broken`,'{invalid json');
    assert.equal(getProgress('broken').position,0);
    saveProgress('broken',{position:25,duration:50});
    assert.equal(getProgress('broken').position,25);
});
