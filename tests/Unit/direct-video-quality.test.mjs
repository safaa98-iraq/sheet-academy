import {test} from 'node:test';
import assert from 'node:assert/strict';
import {directVideoSource} from '../../resources/js/direct-video-quality.js';
const qualities = {360: '/signed/360', 480: '/signed/480', 720: '/signed/720', 1080: '/signed/1080'};

test('automatic MP4 quality chooses 720p on fast or unspecified connections and the smallest copy on slow connections', () => {
    assert.deepEqual(directVideoSource(qualities, '/original', 'auto', '4g'), {url: '/signed/720', height: 720});
    assert.deepEqual(directVideoSource(qualities, '/original', 'auto'), {url: '/signed/720', height: 720});
    assert.deepEqual(directVideoSource(qualities, '/original', 'auto', '3g'), {url: '/signed/360', height: 360});
});
test('a selected MP4 quality uses that exact file and a withdrawn quality falls back to a real available copy', () => {
    assert.deepEqual(directVideoSource(qualities, '/original', '1080', '3g'), {url: '/signed/1080', height: 1080});
    assert.deepEqual(directVideoSource({360: '/only-copy'}, '/original', '720', '4g'), {url: '/only-copy', height: 360});
});
test('a single legacy MP4 has no invented renditions and a large original remains playable', () => {
    assert.deepEqual(directVideoSource({}, '/original', 'auto', '4g'), {url: '/original', height: undefined});
    assert.deepEqual(directVideoSource({2160: '/4k'}, '/original', 'auto', '4g'), {url: '/4k', height: 2160});
});
