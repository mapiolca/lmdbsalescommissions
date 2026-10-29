/* Native confirmation forms: the server formats and recomputes all financial values. */
jQuery(function ($) {
	$(document).off('input.lscWorkflow', 'input[name="retained_percent"]').on('input.lscWorkflow', 'input[name="retained_percent"]', function () {
		// Native Ajax formconfirm is a dialog div; the non-JS renderer is a form.
		var target = $(this).closest('.ui-dialog-content, form').find('.lsc-retained-amount');
		if (!target.length) { return; }
		var sequence = (target.data('lscSequence') || 0) + 1;
		target.data('lscSequence', sequence);
		$.ajax({url: target.attr('data-url'), type: 'POST', data: {
			action: 'previewcommission', token: target.attr('data-token'), retained_percent: $(this).val()
		}, dataType: 'json'}).done(function (data) {
			if (sequence === target.data('lscSequence') && data.amount !== undefined) { target.text(data.amount); }
		}).fail(function () { if (sequence === target.data('lscSequence')) { target.text('—'); } });
	});
});
