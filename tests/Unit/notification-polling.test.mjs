import {test} from 'node:test';
import assert from 'node:assert/strict';
import {initializeNotificationPolling} from '../../resources/js/notification-polling.js';

const flush = () => new Promise(resolve => setImmediate(resolve));
function fixture(fetcher) {
    const badge = {textContent: '0', hidden: true};
    const message = {textContent: ''};
    Object.defineProperty(message, 'innerHTML', {set() { throw Error('Unsafe HTML insertion'); }});
    const arrival = {hidden: true, querySelector: () => message};
    const attributes = {};
    const bell = {dataset: {notificationsUrl: '/notifications/unread-count'}, querySelector: () => badge, setAttribute: (key, value) => {attributes[key] = value;}};
    const handlers = new Map(), timerHandlers = new Map(), events = [];
    const root = {hidden: false, querySelector: selector => selector === '[data-notifications-url]' ? bell : arrival,
        addEventListener: (type, callback) => handlers.set(type, callback), removeEventListener: type => handlers.delete(type), dispatchEvent: event => events.push(event.type)};
    let cleared = 0;
    const timers = {setInterval: (callback, delay) => {assert.equal(delay, 15000); timerHandlers.set('tick', callback); return 1;}, clearInterval: () => {cleared++;}, addEventListener: (type, callback) => timerHandlers.set(type, callback)};
    initializeNotificationPolling(root, timers, fetcher);
    return {badge, message, arrival, attributes, root, handlers, timerHandlers, events, get cleared() {return cleared;}};
}
const response = data => ({ok: true, json: async () => data});

test('live alerts update badge and show lecture text safely without page reload', async () => {
    const item = {count: 125, latest: {id: 'lecture-1', message: '<script>new lecture</script>'}};
    const ui = fixture(async (url, options) => {
        assert.equal(url, '/notifications/unread-count');
        assert.equal(options.credentials, 'same-origin');
        return response(item);
    });
    await flush();
    assert.equal(ui.badge.textContent, '99+');
    assert.equal(ui.badge.hidden, false);
    assert.equal(ui.message.textContent, '<script>new lecture</script>');
    assert.equal(ui.arrival.hidden, false);
    assert.equal(ui.attributes['aria-label'], 'تنبيهاتي: 125 غير مقروء');
    ui.message.textContent = 'already announced';
    await ui.timerHandlers.get('tick')();
    assert.equal(ui.message.textContent, 'already announced');
    item.count = 0; item.latest = null;
    await ui.timerHandlers.get('tick')();
    assert.equal(ui.badge.hidden, true);
    assert.equal(ui.arrival.hidden, true);
});

test('polling skips hidden pages and overlapping requests then resumes on visibility', async () => {
    let resolve, calls = 0;
    const ui = fixture(() => {calls++; return new Promise(done => {resolve = done;});});
    await ui.timerHandlers.get('tick')();
    assert.equal(calls, 1);
    resolve(response({count: 0, latest: null})); await flush();
    ui.root.hidden = true;
    await ui.timerHandlers.get('tick')();
    assert.equal(calls, 1);
    ui.root.hidden = false;
    ui.handlers.get('visibilitychange')();
    assert.equal(calls, 2);
    resolve(response({count: 1, latest: {id: 'second', message: 'تنبيه جديد'}})); await flush();
    assert.equal(ui.message.textContent, 'تنبيه جديد');
});

test('expired sessions stop polling and notify protected players to discard playback', async () => {
    globalThis.CustomEvent = class {constructor(type) {this.type = type;}};
    let calls = 0;
    const ui = fixture(async () => {calls++; return {ok: false, status: 423};});
    await flush();
    assert.equal(ui.cleared, 1);
    assert.deepEqual(ui.events, ['student:session-ended']);
    await ui.timerHandlers.get('tick')();
    assert.equal(calls, 1);
});

test('temporary network failures retry and leaving the page clears the polling timer', async () => {
    let calls = 0;
    const ui = fixture(async () => {
        calls++;
        if (calls === 1) throw Error('network interrupted');
        return response({count: 1, latest: {id: 'renewal', message: 'انتهى الاشتراك'}});
    });
    await flush();
    await ui.timerHandlers.get('tick')();
    assert.equal(ui.message.textContent, 'انتهى الاشتراك');
    ui.timerHandlers.get('pagehide')();
    assert.equal(ui.cleared, 1);
    assert.equal(ui.handlers.has('visibilitychange'), false);
    await ui.timerHandlers.get('tick')();
    assert.equal(calls, 2);
});
