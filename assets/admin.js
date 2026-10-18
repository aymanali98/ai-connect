/*
 * Ai Connect admin behavior: copy buttons, tool selection helpers,
 * live design rows, connection test, and the diagnostics runner.
 */
(function () {
	'use strict';

	var config = window.AiConnectAdmin || {};
	var i18n = config.i18n || {};

	function onReady(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function each(list, fn) {
		Array.prototype.forEach.call(list || [], fn);
	}

	function esc(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function chip(label, variant) {
		return '<span class="sitw-chip sitw-chip-' + esc(variant) + '">' + esc(label) + '</span>';
	}

	function copyText(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}
		var area = document.createElement('textarea');
		area.value = text;
		area.setAttribute('readonly', 'readonly');
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild(area);
		area.select();
		try {
			document.execCommand('copy');
		} catch (err) {
			// Ignore: nothing else we can do in this environment.
		}
		document.body.removeChild(area);
		return Promise.resolve();
	}

	function bindCopyButtons() {
		each(document.querySelectorAll('[data-sitw-copy]'), function (button) {
			if (button.dataset.sitwCopyBound) {
				return;
			}
			button.dataset.sitwCopyBound = '1';
			button.addEventListener('click', function () {
				var target = document.querySelector(button.getAttribute('data-sitw-copy'));
				if (!target) {
					return;
				}
				copyText(target.textContent.trim()).then(function () {
					var original = button.textContent;
					button.textContent = i18n.copied || 'Copied';
					window.setTimeout(function () {
						button.textContent = original;
					}, 1500);
				});
			});
		});
	}

	function bindConfirmations() {
		each(document.querySelectorAll('[data-sitw-confirm]'), function (element) {
			element.addEventListener('click', function (event) {
				var message = element.getAttribute('data-sitw-confirm') || i18n.confirm || 'Are you sure?';
				if (!window.confirm(message)) {
					event.preventDefault();
					event.stopPropagation();
				}
			});
		});
	}

	function bindToolToggles() {
		each(document.querySelectorAll('[data-sitw-toggle]'), function (button) {
			button.addEventListener('click', function () {
				var form = button.closest('form');
				if (!form) {
					return;
				}
				var mode = button.getAttribute('data-sitw-toggle');
				each(form.querySelectorAll('input[name="enabled[]"]'), function (box) {
					if (mode === 'all') {
						box.checked = true;
					} else if (mode === 'none') {
						box.checked = false;
					} else {
						box.checked = box.getAttribute('data-risk') === 'read';
					}
				});
			});
		});
	}

	function bindDesignRows() {
		each(document.querySelectorAll('[data-sitw-add-row]'), function (button) {
			button.addEventListener('click', function () {
				var table = document.getElementById(button.getAttribute('data-sitw-add-row'));
				if (!table) {
					return;
				}
				var tbody = table.querySelector('tbody');
				var row = tbody ? tbody.querySelector('tr') : null;
				if (!row) {
					return;
				}
				var clone = row.cloneNode(true);
				each(clone.querySelectorAll('input, textarea'), function (input) {
					input.value = '';
				});
				var swatch = clone.querySelector('.sitw-swatch');
				if (swatch) {
					swatch.style.background = '';
				}
				tbody.appendChild(clone);
			});
		});

		document.addEventListener('click', function (e) {
			var button = e.target && e.target.closest ? e.target.closest('[data-sitw-remove-row]') : null;
			if (!button) {
				return;
			}
			var row = button.closest('tr');
			if (!row) {
				return;
			}
			var tbody = row.parentNode;
			if (tbody && tbody.querySelectorAll('tr').length > 1) {
				tbody.removeChild(row);
			} else {
				each(row.querySelectorAll('input, textarea'), function (input) {
					input.value = '';
				});
				var swatch = row.querySelector('.sitw-swatch');
				if (swatch) {
					swatch.style.background = '';
				}
			}
		});

		document.addEventListener('input', function (e) {
			if (!e.target || !e.target.classList || !e.target.classList.contains('sitw-color-input')) {
				return;
			}
			var input = e.target;
			var row = input.closest('tr');
			var swatch = row ? row.querySelector('.sitw-swatch') : null;
			if (swatch) {
				swatch.style.background = input.value;
			}
		});
	}

	function bindTestConnection() {
		var button = document.getElementById('sitw-test-now');
		var box = document.getElementById('sitw-test-result');
		if (!button || !config.restUrl) {
			return;
		}
		button.addEventListener('click', function () {
			var token = button.getAttribute('data-token') || '';
			button.disabled = true;
			if (box) {
				box.hidden = false;
				box.className = 'sitw-test-result';
				box.textContent = i18n.testing || 'Testing...';
			}
			fetch(config.restUrl + 'test-connection', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce
				},
				body: JSON.stringify({ token: token })
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (data) {
					button.disabled = false;
					if (!box) {
						return;
					}
					box.hidden = false;
					if (data && data.ok) {
						box.className = 'sitw-test-result sitw-test-ok';
						box.textContent = 'Connected. Server ' + (data.server ? data.server.name + ' ' + data.server.version : '') +
							' · protocol ' + (data.protocol_version || '') +
							' · ' + (data.tools || 0) + ' tools visible' +
							' · ' + (data.latency_ms || 0) + ' ms round trip.';
					} else {
						box.className = 'sitw-test-result sitw-test-err';
						box.textContent = (data && data.error) || i18n.error || 'Request failed.';
					}
				})
				.catch(function () {
					button.disabled = false;
					if (box) {
						box.hidden = false;
						box.className = 'sitw-test-result sitw-test-err';
						box.textContent = i18n.error || 'Request failed.';
					}
				});
		});
	}

	function bindDiagnostics() {
		var button = document.getElementById('sitw-run-diagnostics');
		var status = document.getElementById('sitw-diag-status');
		var results = document.getElementById('sitw-diag-results');
		if (!button || !results || !config.restUrl) {
			return;
		}

		function render(data) {
			var report = (data && data.checks) || {};
			var checks = report.checks || [];
			var summary = report.summary || {};

			var html = '<p class="sitw-diag-summary">' +
				chip((summary.ok || 0) + ' ok', 'ok') +
				chip((summary.warn || 0) + ' warnings', 'warn') +
				chip((summary.fail || 0) + ' failures', 'err') +
				'</p>';

			html += '<table class="sitw-table"><thead><tr>' +
				'<th>Status</th><th>Check</th><th>Detail</th><th>Fix</th>' +
				'</tr></thead><tbody>';

			checks.forEach(function (check) {
				var variant = check.status === 'ok' ? 'ok' : (check.status === 'warn' ? 'warn' : 'err');
				html += '<tr>' +
					'<td>' + chip(check.status, variant) + '</td>' +
					'<td>' + esc(check.label) + '</td>' +
					'<td>' + esc(check.detail) + '</td>' +
					'<td class="sitw-cell-sub">' + esc(check.fix || '') + '</td>' +
					'</tr>';
			});

			html += '</tbody></table>';
			results.innerHTML = html;

			var json = document.getElementById('sitw-diag-json');
			if (json) {
				json.textContent = JSON.stringify(report, null, 2);
			}
			var copy = document.getElementById('sitw-copy-diag');
			if (copy) {
				copy.disabled = false;
			}
		}

		function run() {
			button.disabled = true;
			if (status) {
				status.textContent = i18n.running || 'Running checks...';
			}
			fetch(config.restUrl + 'diagnostics', {
				method: 'GET',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.nonce }
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (data) {
					render(data);
					button.disabled = false;
					if (status) {
						status.textContent = '';
					}
				})
				.catch(function () {
					button.disabled = false;
					if (status) {
						status.textContent = i18n.error || 'Request failed.';
					}
				});
		}

		button.addEventListener('click', run);
		run();
	}

	onReady(function () {
		bindCopyButtons();
		bindConfirmations();
		bindToolToggles();
		bindDesignRows();
		bindTestConnection();
		bindDiagnostics();
	});
}());
