/* Native Dolibarr jQuery UI dialog; keep ordinary POST forms and a no-JavaScript page. */
jQuery(function ($) {
	var editor = $('#lsc-policy-editor');
	if (!editor.length || typeof $.fn.dialog !== 'function' || editor.data('ui-dialog')) return;
	editor.dialog({
		modal: true,
		width: Math.min(850, window.innerWidth - 32),
		maxHeight: window.innerHeight - 48,
		resizable: false,
		close: function () { window.location.href = editor.attr('data-return-url'); }
	});
	var context = editor.find('#policy_context');
	function updateContext() {
		var general = context.val() === 'general';
		editor.find('#lsc-policy-rate-row').toggle(general).find('input').prop('required', general);
		var effect = editor.find('#policy_effect');
		effect.find('option[value="sale"], option[value="both"]').prop('disabled', !general);
		if (!general) effect.val('commission').trigger('change');
	}
	context.off('change.lscPolicy').on('change.lscPolicy', updateContext);
	updateContext();
});
