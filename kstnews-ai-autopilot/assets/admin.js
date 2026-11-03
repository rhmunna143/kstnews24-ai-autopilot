/**
 * KSTNews AI Telegram AutoPilot - Admin Scripts
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		const $notice = $('#kstnews-ajax-notice');

		function showNotice(message, type) {
			$notice
				.removeClass('is-success is-error is-info')
				.addClass('is-' + type)
				.html(message)
				.fadeIn(200);

			if (type === 'is-success') {
				setTimeout(function () {
					$notice.fadeOut(400);
				}, 6000);
			}
		}

		// Toggle / Change API Key
		$('#kstnews-toggle-key').on('click', function (e) {
			e.preventDefault();
			const $input = $('#gemini_key');
			const currentType = $input.attr('type');

			if (currentType === 'password') {
				$input.attr('type', 'text');
				if ($input.val().indexOf('••••') !== -1) {
					$input.val('').attr('placeholder', 'Enter new API key here');
				}
				$input.focus();
				$(this).text('Hide');
			} else {
				$input.attr('type', 'password');
				$(this).text('Show/Change');
			}
		});

		// Test Gemini Connection
		$('#kstnews-btn-test-gemini').on('click', function (e) {
			e.preventDefault();
			const $btn = $(this);
			const originalHtml = $btn.html();
			const apiKey = $('#gemini_key').val();

			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + kstnewsAiData.strings.testing);

			$.ajax({
				url: kstnewsAiData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'kstnews_test_gemini',
					nonce: kstnewsAiData.ajaxNonce,
					api_key: apiKey
				},
				success: function (res) {
					$btn.prop('disabled', false).html(originalHtml);
					if (res && res.success) {
						showNotice(res.data.message || 'Gemini API connection test passed!', 'success');
					} else {
						const msg = (res && res.data && res.data.message) ? res.data.message : 'Gemini connection failed.';
						showNotice(msg, 'error');
					}
				},
				error: function (xhr, status, error) {
					$btn.prop('disabled', false).html(originalHtml);
					showNotice('Network error: ' + error, 'error');
				}
			});
		});

		// Manual Fetch & Publish
		$('#kstnews-btn-manual-run').on('click', function (e) {
			e.preventDefault();
			const $btn = $(this);
			const originalHtml = $btn.html();

			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + kstnewsAiData.strings.running);
			showNotice('Pipeline running: Fetching Telegram channel posts and processing...', 'info');

			$.ajax({
				url: kstnewsAiData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'kstnews_run_manual',
					nonce: kstnewsAiData.ajaxNonce
				},
				success: function (res) {
					$btn.prop('disabled', false).html(originalHtml);
					if (res && res.success) {
						const details = res.data.message || ('Processed ' + (res.data.processed || 0) + ' items.');
						showNotice(details + ' Reloading dashboard...', 'success');
						setTimeout(function () {
							window.location.reload();
						}, 1500);
					} else {
						const err = (res && res.data && res.data.message) ? res.data.message : 'Manual run failed or locked.';
						showNotice(err, 'error');
					}
				},
				error: function (xhr, status, error) {
					$btn.prop('disabled', false).html(originalHtml);
					showNotice('Pipeline error: ' + error, 'error');
				}
			});
		});

		// Reset Tracking State
		$('#kstnews-btn-reset-state').on('click', function (e) {
			e.preventDefault();
			if (!confirm(kstnewsAiData.strings.confirmReset)) {
				return;
			}

			const $btn = $(this);
			const originalHtml = $btn.html();
			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

			$.ajax({
				url: kstnewsAiData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'kstnews_reset_state',
					nonce: kstnewsAiData.ajaxNonce
				},
				success: function (res) {
					$btn.prop('disabled', false).html(originalHtml);
					if (res && res.success) {
						showNotice(res.data.message || 'Tracking state reset.', 'success');
						setTimeout(function () {
							window.location.reload();
						}, 1200);
					} else {
						showNotice('Failed to reset state.', 'error');
					}
				},
				error: function () {
					$btn.prop('disabled', false).html(originalHtml);
					showNotice('Network error while resetting state.', 'error');
				}
			});
		});

		// Clear Logs
		$('#kstnews-btn-clear-logs').on('click', function (e) {
			e.preventDefault();
			if (!confirm(kstnewsAiData.strings.confirmClearLog)) {
				return;
			}

			const $btn = $(this);
			$btn.prop('disabled', true);

			$.ajax({
				url: kstnewsAiData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'kstnews_clear_logs',
					nonce: kstnewsAiData.ajaxNonce
				},
				success: function (res) {
					if (res && res.success) {
						window.location.reload();
					} else {
						$btn.prop('disabled', false);
						showNotice('Failed to clear logs.', 'error');
					}
				},
				error: function () {
					$btn.prop('disabled', false);
					showNotice('Network error while clearing logs.', 'error');
				}
			});
		});
	});
})(jQuery);
