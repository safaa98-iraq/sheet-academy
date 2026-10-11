import {test} from 'node:test';
import assert from 'node:assert/strict';
import {playbackBuffer, playbackPosition} from '../../resources/js/playback-buffer.js';
const media = (position, duration, ranges) => ({currentTime: position, duration, buffered: {length: ranges.length, start: index => ranges[index][0], end: index => ranges[index][1]}});

test('buffer display reports ready content ahead without counting it as watched', () => {
    assert.deepEqual(playbackBuffer(media(42, 600, [[0, 222]])), {end: 222, ahead: 180});
});
test('seeking outside loaded content does not claim a disconnected future range is ready', () => {
    assert.deepEqual(playbackBuffer(media(45, 600, [[0, 20], [90, 240]])), {end: 45, ahead: 0});
    assert.deepEqual(playbackBuffer(media(100, 600, [[0, 20], [90, 240]])), {end: 240, ahead: 140});
});
test('buffer label clamps to video end and tolerates unloaded or changing ranges', () => {
    assert.deepEqual(playbackBuffer(media(290, 300, [[0, 310]])), {end: 300, ahead: 10});
    assert.deepEqual(playbackBuffer(null), {end: 0, ahead: 0});
    assert.deepEqual(playbackBuffer({currentTime: 12, duration: 300, buffered: {length: 1, start() {throw Error('range removed');}}}), {end: 12, ahead: 0});
});
test('cleared or failed streams retain the last viewing position when leaving the page', () => {
    assert.equal(playbackPosition({currentTime: 0, readyState: 0}, 73), 73);
    assert.equal(playbackPosition(null, 73), 73);
    assert.equal(playbackPosition({currentTime: NaN, readyState: 1}, 73), 73);
});
test('ready streams save the actual position including an intentional restart from zero', () => {
    assert.equal(playbackPosition({currentTime: 81, readyState: 4}, 73), 81);
    assert.equal(playbackPosition({currentTime: 0, readyState: 1}, 73), 0);
});
