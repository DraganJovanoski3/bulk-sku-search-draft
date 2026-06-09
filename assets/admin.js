(function ($) {
	'use strict';

	var totals = {
		succeeded: 0,
		failed: 0,
		skipped: 0,
	};

	function resetTotals() {
		totals.succeeded = 0;
		totals.failed = 0;
		totals.skipped = 0;
	}

	function updateProgress($el, offset, total) {
		$el.text(
			bssdAdmin.i18n.drafting +
				' ' +
				Math.min(offset, total) +
				' / ' +
				total
		);
	}

	function showComplete($el, $btn) {
		var message =
			bssdAdmin.i18n.complete +
			' ' +
			totals.succeeded +
			' drafted';

		if (totals.skipped > 0) {
			message += ', ' + totals.skipped + ' skipped';
		}

		if (totals.failed > 0) {
			message += ', ' + totals.failed + ' failed';
		}

		$el.removeClass('is-busy').text(message);
		$btn.prop('disabled', true);

		window.setTimeout(function () {
			window.location.reload();
		}, 1500);
	}

	function runBatch($btn, $progress, offset, total) {
		$.post(bssdAdmin.ajaxUrl, {
			action: 'bssd_draft_batch',
			nonce: bssdAdmin.nonce,
			offset: offset,
		})
			.done(function (response) {
				if (!response || !response.success || !response.data) {
					$progress.removeClass('is-busy').text(bssdAdmin.i18n.error);
					$btn.prop('disabled', false);
					return;
				}

				var data = response.data;
				totals.succeeded += data.succeeded || 0;
				totals.failed += data.failed || 0;
				totals.skipped += data.skipped || 0;

				updateProgress($progress, data.offset || 0, total);

				if (data.done) {
					showComplete($progress, $btn);
					return;
				}

				runBatch($btn, $progress, data.offset || 0, total);
			})
			.fail(function () {
				$progress.removeClass('is-busy').text(bssdAdmin.i18n.error);
				$btn.prop('disabled', false);
			});
	}

	$(function () {
		var $btn = $('#bssd-draft-btn');
		var $progress = $('#bssd-draft-progress');

		if (!$btn.length) {
			return;
		}

		$btn.on('click', function () {
			var count = parseInt($btn.data('count'), 10) || 0;

			if (count < 1) {
				return;
			}

			var message = bssdAdmin.i18n.confirmDraft.replace('%d', String(count));
			if (!window.confirm(message)) {
				return;
			}

			resetTotals();
			$btn.prop('disabled', true);
			$progress.addClass('is-busy').text(bssdAdmin.i18n.drafting);
			runBatch($btn, $progress, 0, count);
		});
	});
})(jQuery);
