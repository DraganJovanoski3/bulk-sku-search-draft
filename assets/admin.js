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

	function showSkuEditor($cell) {
		$cell.find('.bssd-sku-display').attr('hidden', true);
		$cell.find('.bssd-sku-editor').removeAttr('hidden');
		$cell.find('.bssd-sku-input').trigger('focus').select();
		$cell.find('.bssd-sku-feedback').text('');
	}

	function hideSkuEditor($cell, sku) {
		if (typeof sku === 'string') {
			$cell.find('.bssd-sku-value').text(sku);
			$cell.find('.bssd-sku-input').val(sku);
		}

		$cell.find('.bssd-sku-editor').attr('hidden', true);
		$cell.find('.bssd-sku-display').removeAttr('hidden');
		$cell.find('.bssd-sku-feedback').text('');
	}

	function setSkuFeedback($cell, message, isError) {
		var $feedback = $cell.find('.bssd-sku-feedback');
		$feedback.text(message);
		$feedback.toggleClass('is-error', !!isError);
	}

	function saveSku($row) {
		var $cell = $row.find('.bssd-sku-cell');
		var $input = $cell.find('.bssd-sku-input');
		var $saveBtn = $cell.find('.bssd-sku-save-btn');
		var productId = parseInt($row.data('product-id'), 10) || 0;
		var newSku = $.trim($input.val());

		if (!productId) {
			return;
		}

		$saveBtn.prop('disabled', true);
		setSkuFeedback($cell, bssdAdmin.i18n.savingSku, false);

		$.post(bssdAdmin.ajaxUrl, {
			action: 'bssd_update_sku',
			nonce: bssdAdmin.skuNonce,
			product_id: productId,
			sku: newSku,
		})
			.done(function (response) {
				if (!response || !response.success || !response.data) {
					var errMsg =
						(response && response.data && response.data.message) ||
						bssdAdmin.i18n.skuFailed;
					setSkuFeedback($cell, errMsg, true);
					$saveBtn.prop('disabled', false);
					return;
				}

				hideSkuEditor($cell, response.data.sku || newSku);
			})
			.fail(function (xhr) {
				var errMsg = bssdAdmin.i18n.skuFailed;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					errMsg = xhr.responseJSON.data.message;
				}
				setSkuFeedback($cell, errMsg, true);
				$saveBtn.prop('disabled', false);
			});
	}

	function initDraftButton() {
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
	}

	function initSkuEditors() {
		var $table = $('.bssd-results-table');

		if (!$table.length) {
			return;
		}

		$table.on('click', '.bssd-sku-edit-btn', function () {
			showSkuEditor($(this).closest('.bssd-sku-cell'));
		});

		$table.on('click', '.bssd-sku-cancel-btn', function () {
			var $cell = $(this).closest('.bssd-sku-cell');
			var originalSku = $.trim($cell.find('.bssd-sku-value').text());
			hideSkuEditor($cell, originalSku);
		});

		$table.on('click', '.bssd-sku-save-btn', function () {
			saveSku($(this).closest('tr'));
		});

		$table.on('keydown', '.bssd-sku-input', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				saveSku($(this).closest('tr'));
			}

			if (event.key === 'Escape') {
				event.preventDefault();
				var $cell = $(this).closest('.bssd-sku-cell');
				hideSkuEditor($cell, $.trim($cell.find('.bssd-sku-value').text()));
			}
		});
	}

	$(function () {
		initDraftButton();
		initSkuEditors();
	});
})(jQuery);
