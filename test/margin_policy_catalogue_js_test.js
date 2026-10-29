/* Native Select2 configuration; DOM and product search transport are simulated. */
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source = fs.readFileSync(require('path').join(__dirname, '../js/marginpolicies.js'), 'utf8');
let options, initializations = 0, fallbackHidden = 0;
const products = {length: 1, data() {return initializations > 0;}, attr(key) {return ({'data-products-url':'/erp/product/ajax/products.php','data-products-type':'0','data-products-min-length':'2'})[key];}, select2(config) {options=config; initializations++;}};
const editor = {length:1, find(selector) {if (selector === '#lsc-product-search-fallback') return {hide() {fallbackHidden++;}}; assert.equal(selector, '#products[data-products-url]'); return products;}, data() {return true;}};
function jQuery(arg) {return typeof arg === 'function' ? arg(jQuery) : editor;}
jQuery.fn = {select2() {}, dialog() {}};
const context = {jQuery, select2arrayoflanguage: {}};
vm.runInNewContext(source, context); vm.runInNewContext(source, context);
assert.equal(initializations, 1);
assert.equal(fallbackHidden, 1);
assert.equal(options.minimumInputLength, 2);
assert.equal(options.ajax.url, '/erp/product/ajax/products.php');
const query = options.ajax.data({term: "Réf' <&"});
assert.equal(query.htmlname, 'lsc_product_search');
assert.equal(query.lsc_product_search, "Réf' <&");
assert.equal(query.type, '0');
assert.equal(query.status, -1);
assert.equal(query.outjson, 1);
const result = options.ajax.processResults([{key:21,value:'PV',label2:'<script>'},{key:21,value:'PV',label2:'Duplicate price'},{key:'bad',value:'bad',label2:'bad'},null]);
assert.equal(result.results.length, 1);
assert.equal(result.results[0].text, 'PV - <script>'); // Plain text; keep native Select2 escaping.
assert.equal(options.escapeMarkup, undefined);
assert.equal(options.ajax.processResults(null).results.length, 0);
console.log('Margin policy product search: 13 assertions passed (Select2 configuration and transport simulated).');
