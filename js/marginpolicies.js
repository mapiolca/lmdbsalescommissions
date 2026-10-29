/* Native Dolibarr jQuery UI dialog; keep ordinary POST forms and a no-JavaScript page. */
jQuery(function ($) {
	var editor = $('#lsc-policy-editor');
	// select_produits is single-valued on the supported core versions. Keep the native
	// multiselect, adapting its remote results from the native product search endpoint.
	var products = editor.find('#products[data-products-url]');
	if (products.length && $.fn.select2 && !products.data('select2')) {
		products.select2({
			width: 'resolve', language: select2arrayoflanguage,
			minimumInputLength: Number(products.attr('data-products-min-length')),
			ajax: {
				url: products.attr('data-products-url'), dataType: 'json', delay: 250,
				data: function (params) {
					return {htmlname: 'lsc_product_search', lsc_product_search: params.term, type: products.attr('data-products-type'), mode: 1, outjson: 1, status: -1, status_purchase: -1, finished: 2, hidepriceinlabel: 1};
				},
				processResults: function (data) {
					var results = [], seen = {};
					if (Array.isArray(data)) data.forEach(function (row) {
						if (!row || !/^[1-9][0-9]*$/.test(String(row.key)) || seen[row.key] || typeof row.value !== 'string' || typeof row.label2 !== 'string') return;
						seen[row.key] = true;
						results.push({id: String(row.key), text: row.value + ' - ' + row.label2});
					});
					return {results: results};
				}
			}
		});
		editor.find('#lsc-product-search-fallback').hide();
	}
	if (!editor.length || typeof $.fn.dialog !== 'function' || editor.data('ui-dialog')) return;
	var form = editor.find('#lsc-policy-form');
	var actions = editor.find('#lsc-policy-form-actions');
	var saveButton = actions.find('.button-save');
	var submitting = false;
	editor.dialog({
		modal: true,
		width: Math.min(850, window.innerWidth - 32),
		maxHeight: window.innerHeight - 48,
		resizable: false,
		buttons: [
			{
				text: saveButton.text(),
				'class': 'button button-save',
				click: function () { saveButton[0].click(); }
			},
			{
				text: actions.find('.button-cancel').text(),
				'class': 'button button-cancel',
				click: function () { editor.dialog('close'); }
			}
		],
		close: function () {
			// A valid POST must finish; closing after submit must not navigate to Cancel.
			if (!submitting) window.location.href = editor.attr('data-return-url');
		}
	});
	actions.hide();
	form.off('submit.lscPolicy').on('submit.lscPolicy', function (event) {
		if (submitting) { event.preventDefault(); return; }
		submitting = true;
		editor.dialog('close');
	});
	var context = editor.find('#policy_context');
	function updateContext() {
		var general = context.val() === 'general';
		editor.find('#lsc-policy-rate-row').toggle(general).find('input').prop('required', general).prop('disabled', !general);
		editor.find('#lsc-policy-bands').toggle(!general).find('input, select, button').prop('disabled', general);
		var effect = editor.find('#policy_effect');
		effect.find('option[value="sale"], option[value="both"]').prop('disabled', !general);
		if (!general) effect.val('commission').trigger('change');
	}
	context.off('change.lscPolicy').on('change.lscPolicy', updateContext);
	updateContext();
	var scope = editor.find('#policy_scope');
	function updateScope() {
		var selected = scope.val();
		var categories = editor.find('#lsc-policy-categories');
		var products = editor.find('#lsc-policy-products');
		categories.toggle(selected === 'category').find('input, select, button').prop('disabled', selected !== 'category');
		products.toggle(selected === 'product').find('input, select, button').prop('disabled', selected !== 'product');
	}
	scope.off('change.lscPolicy').on('change.lscPolicy', updateScope);
	updateScope();
});
