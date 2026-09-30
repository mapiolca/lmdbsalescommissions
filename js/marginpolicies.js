/* Native Dolibarr jQuery UI dialog; keep ordinary POST forms and a no-JavaScript page. */
jQuery(function ($) {
	var editor = $('#lsc-policy-editor');
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
});
