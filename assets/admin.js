(function ($) {
	'use strict';

	$(function () {
		$('form.migrator-export-form').on('submit', function () {
			var $btn = $(this).find('button[type="submit"]');
			$btn.prop('disabled', true).text($btn.data('working') || 'Working…');
		});
	});
})(jQuery);
