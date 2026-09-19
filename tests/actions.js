const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../public_html/assets/app-loader.js', 'utf8');
function harness(fetch) {
  const timers = new Map();
  let id = 0;
  const element = () => ({setAttribute() {}, appendChild() {}, addEventListener() {}, insertBefore() {}});
  const document = {addEventListener() {}, querySelectorAll: () => [], getElementById: () => null,
    createElement: element, querySelector: element, body: element()};
  const window = {fetch, addEventListener() {}, AbortController,
    setTimeout(callback) { timers.set(++id, callback); return id; }, clearTimeout(id) { timers.delete(id); }};
  vm.runInNewContext(source, {window, document, Promise, Error});
  return {window, timers};
}
const flush = () => new Promise(resolve => setImmediate(resolve));
const ok = value => ({ok: true, status: 200, json: async () => ({ok: true, value})});
(async function () {
  let release;
  const calls = [];
  let h = harness(url => { calls.push(url); return url === 'first' ? new Promise(resolve => { release = resolve; }) : Promise.resolve(ok(2)); });
  const first = h.window.garbaliaActionRequest('first', {method: 'POST'});
  const second = h.window.garbaliaActionRequest('second', {method: 'POST'});
  await flush();
  assert.deepEqual(calls, ['first']);
  release(ok(1));
  assert.equal((await first).value, 1);
  assert.equal((await second).value, 2);
  assert.deepEqual(calls, ['first', 'second']);
  assert.equal(h.timers.size, 0);

  // Headers arrived, but the JSON body stalled: the deadline must still fire.
  let count = 0;
  h = harness(async () => { count++; return {ok: true, status: 200, json: () => new Promise(() => {})}; });
  const stalled = h.window.garbaliaActionRequest('slow', {method: 'POST'});
  const rejected = assert.rejects(stalled, error => error.outcomeUnknown === true);
  await flush();
  assert.equal(h.timers.size, 1);
  Array.from(h.timers.values())[0]();
  await rejected;
  assert.equal(h.window.garbaliaOutcomeUnknown, true);
  await assert.rejects(h.window.garbaliaActionRequest('repeat', {method: 'POST'}));
  assert.equal(count, 1, 'uncertain writes are never automatically retried');

  // A known 409 rejection leaves the next intentional action available.
  h = harness(async () => ({ok: false, status: 409, json: async () => ({ok: false, message: 'Changed order'})}));
  await assert.rejects(h.window.garbaliaActionRequest('stale'), /Changed order/);
  assert.equal(h.window.garbaliaOutcomeUnknown, undefined);
  h.window.fetch = async () => ok(3);
  assert.equal((await h.window.garbaliaActionRequest('corrected')).value, 3);

  h = harness(async () => { throw new TypeError('Network failed'); });
  await assert.rejects(h.window.garbaliaActionRequest('disconnected'), error => error.outcomeUnknown === true);
  assert.equal(h.window.garbaliaOutcomeUnknown, true);
  console.log('Action ordering, body timeout and uncertain-write checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
