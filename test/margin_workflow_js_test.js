/* Isolated jQuery/DOM/Ajax doubles; exercise idempotence, independent dialogs and stale replies. */
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const script = fs.readFileSync(require('path').join(__dirname, '../js/marginworkflow.js'), 'utf8');
const document = {};
let handler;
const requests = [];
const events = {off() {handler = undefined; return this;}, on(event, selector, callback) {handler = callback; return this;}};
function target(url) {
	const store = {};
	return {length: 1, value: '', data(key, value) {if (value !== undefined) {store[key] = value; return this;} return store[key];}, attr(key) {return key === 'data-url' ? url : 'native-token';}, text(value) {this.value = value;}};
}
function input(t, value) {return {val() {return value;}, closest() {return {find() {return t;}};}};}
function jQuery(value) {if (typeof value === 'function') {return value(jQuery);} return value === document ? events : value;}
jQuery.ajax = function (options) {const request = {options, done(fn) {this.success = fn; return this;}, fail(fn) {this.failure = fn; return this;}}; requests.push(request); return request;};
const context = {jQuery, document};
vm.runInNewContext(script, context);
vm.runInNewContext(script, context);
const a = target('/workflow?id=10'), b = target('/workflow?id=20');
handler.call(input(a, '25')); handler.call(input(a, '50')); handler.call(input(b, '75'));
assert.equal(requests.length, 3);
assert.equal(requests[0].options.type, 'POST');
assert.equal(requests[0].options.data.token, 'native-token');
assert.equal(requests[0].options.data.retained_percent, '25');
assert.equal(requests[2].options.url, '/workflow?id=20');
requests[0].success({amount: 'old'});
assert.equal(a.value, '');
requests[2].success({amount: '75,00 EUR'});
assert.equal(b.value, '75,00 EUR');
requests[1].success({amount: '50,00 EUR'});
requests[0].failure();
assert.equal(a.value, '50,00 EUR');
vm.runInNewContext(script, context);
handler.call(input(a, '80'));
assert.equal(requests.length, 4);
requests[3].failure();
assert.equal(a.value, '—');
console.log('Workflow preview: 10 assertions passed (DOM and Ajax simulated).');
