'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Do not fire page events: this suite exercises the actual pure checkout
// functions, without requiring a browser or imitating a successful payment.
const context = {
  window: {},
  document: { addEventListener() {} },
  console,
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../public_html/assets/close-confirm.js'), 'utf8'), context);
const money = context.window.GarbaliaMoney;
assert.ok(money && typeof money.calculate === 'function', 'checkout exports its tested calculator');
let checks = 1;

for (const [input, expected] of [
  ['1,234.50 ₾', 1234.50],
  ['10,000.00 ₾', 10000],
  ['100.00 ₾', 100],
  ['0.05 ₾', 0.05],
  ['-25.50 ₾', -25.50],
  ['12,50', 12.50],
  ['', 0],
]) {
  assert.equal(money.parse(input), expected, 'read money: ' + input);
  checks++;
}
assert.equal(money.format(-25.5), '-25.50 ₾', 'cash differences must keep their minus sign');
checks++;
assert.equal(money.toCents(10.075), 1008, 'binary-sensitive value rounds up to exact tetri');
checks++;

const cases = JSON.parse(fs.readFileSync(path.join(__dirname, 'pricing-cases.json'), 'utf8'));
for (const fixture of cases) {
  const result = money.calculate(fixture.subtotal, fixture.discount_type, fixture.discount_value, fixture.takeaway ? 0 : 10);
  for (const field of ['subtotal', 'discount', 'service', 'total']) {
    assert.equal(result[field], fixture[field], fixture.name + ': ' + field);
    checks++;
  }
}
console.log('Checkout money: ' + checks + ' assertions passed.');
