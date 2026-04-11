(function () {
	'use strict';

	var lastPayload = null;

	function formDataToObject(formData) {
		var data = {};

		for (const [key, value] of formData.entries()) {
			if (key.endsWith(']')) {
				var match = key.match(/^([^[]+)\[([^\]]+)\]$/);
				if (match) {
					data[match[1]] = data[match[1]] || {};
					data[match[1]][match[2]] = value;
				}
				continue;
			}

			if (key === 'safe_export') {
				data[key] = true;
				continue;
			}

			data[key] = value;
		}

		if (!data.safe_export) {
			data.safe_export = false;
		}

		return data;
	}

	function showNotice(visible) {
		var notice = document.getElementById('pt-sensitive-notice');
		if (notice) {
			notice.style.display = visible ? 'block' : 'none';
		}
	}

	function showTokenEstimate(tokenData) {
		var container = document.getElementById('pt-token-estimate');
		var countEl = container ? container.querySelector('.pt-token-count') : null;

		if (!container || !countEl || !tokenData) {
			return;
		}

		var count = tokenData.tokens || 0;
		countEl.textContent = '~' + count.toLocaleString() + ' tokens';
		container.style.display = 'block';
	}

	async function submitExportForm(event) {
		event.preventDefault();

		var form = event.currentTarget;
		var endpoint = form.dataset.endpoint;
		var output = document.querySelector('.pt-output');
		var preview = document.querySelector('.pt-payload-preview');
		var submitBtn = form.querySelector('button[type="submit"]');
		var originalText = submitBtn ? submitBtn.textContent : '';

		output.value = 'Generating export\u2026';
		preview.textContent = '';
		showNotice(false);

		if (submitBtn) {
			submitBtn.disabled = true;
			submitBtn.textContent = 'Generating\u2026';
		}

		try {
			var response = await fetch(ptAdmin.restUrl + endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': ptAdmin.nonce,
				},
				body: JSON.stringify(formDataToObject(new FormData(form))),
			});

			var data = await response.json();

			if (!response.ok) {
				output.value = data.message || 'Request failed (HTTP ' + response.status + ').';
				return;
			}

			output.value = data.formatted.output;

			// Show the output panel.
			var outputPanel = document.getElementById('pt-output-panel');
			if (outputPanel) outputPanel.style.display = '';
			preview.textContent = JSON.stringify(data.payload, null, 2);
			lastPayload = data.payload;

			var isSafeExport = data.payload && data.payload.meta && data.payload.meta.safe_export;
			showNotice(!isSafeExport);
			showTokenEstimate(data.token_estimate);
		} catch (error) {
			output.value = 'Network error: ' + (error.message || 'Could not reach the server. Check your connection and try again.');
		} finally {
			if (submitBtn) {
				submitBtn.disabled = false;
				submitBtn.textContent = originalText;
			}
		}
	}

	var copyIconSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
	var checkIconSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

	async function copyOutput(event) {
		var btn = event.currentTarget;
		var output = document.querySelector('.pt-output');
		if (!output || !output.value) return;

		var success = false;
		try {
			await navigator.clipboard.writeText(output.value);
			success = true;
		} catch (e) {
			try {
				var temp = document.createElement('textarea');
				temp.value = output.value;
				temp.style.position = 'fixed';
				temp.style.opacity = '0';
				document.body.appendChild(temp);
				temp.select();
				document.execCommand('copy');
				document.body.removeChild(temp);
				success = true;
			} catch (e2) { /* truly failed */ }
		}

		var originalHtml = btn.innerHTML;
		if (success) {
			btn.innerHTML = checkIconSvg + ' Copied';
			btn.classList.add('pt-btn-copied');
		} else {
			btn.innerHTML = 'Failed';
		}
		window.setTimeout(function () {
			btn.innerHTML = originalHtml;
			btn.classList.remove('pt-btn-copied');
		}, 2000);
	}

	function downloadExport() {
		var output = document.querySelector('.pt-output');
		if (!output || !output.value) return;

		var filename = 'tracewp-export-' + new Date().toISOString().slice(0, 10) + '.txt';
		var blob = new Blob([output.value], { type: 'text/plain' });
		var url = URL.createObjectURL(blob);
		var link = document.createElement('a');
		link.href = url;
		link.download = filename;
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		URL.revokeObjectURL(url);
	}

	// Bind export forms.
	document.querySelectorAll('.pt-export-form').forEach(function (form) {
		form.addEventListener('submit', submitExportForm);
	});

	// Unified scope selector — toggles endpoint and URL field visibility.
	var scopeSelector = document.getElementById('pt-scope-selector');
	if (scopeSelector) {
		scopeSelector.addEventListener('change', function () {
			var form = this.closest('form');
			if (!form) return;

			var selected = this.options[this.selectedIndex];
			var urlInput = form.querySelector('input[name="url"]');
			var postIdInput = form.querySelector('input[name="post_id"]');
			var urlRow = document.getElementById('pt-url-row');
			var isPage = !!this.value;

			// Switch endpoint.
			form.dataset.endpoint = isPage ? 'context/page' : 'context/site';

			// Update URL and post_id.
			if (selected && selected.dataset.url && urlInput) {
				urlInput.value = selected.dataset.url;
			}
			if (postIdInput) {
				postIdInput.value = this.value || '';
			}

			// Show URL row only when a page is selected (for manual URL editing).
			if (urlRow) {
				urlRow.style.display = isPage ? 'block' : 'none';
			}
		});

		// Fire once on load to set initial state.
		scopeSelector.dispatchEvent(new Event('change'));
	}

	// Legacy content selector support (for any remaining forms).
	document.querySelectorAll('.pt-content-selector:not(#pt-scope-selector)').forEach(function (select) {
		select.addEventListener('change', function (event) {
			var field = event.currentTarget;
			var form = field.closest('form');
			if (!form) return;

			var selected = field.options[field.selectedIndex];
			var urlInput = form.querySelector('input[name="url"]');
			var postIdInput = form.querySelector('input[name="post_id"]');

			if (selected && selected.dataset.url && urlInput) {
				urlInput.value = selected.dataset.url;
			}
			if (postIdInput) {
				postIdInput.value = field.value || '';
			}
		});
	});

	// Pre-fill URL if passed via query param.
	document.querySelectorAll('input[name="url"]').forEach(function (input) {
		if (ptAdmin.currentPageUrl) {
			input.value = ptAdmin.currentPageUrl;
		}
	});

	// Bind copy buttons.
	var copyBtn = document.getElementById('pt-copy-output');
	if (copyBtn) {
		copyBtn.addEventListener('click', copyOutput);
	}

	// Bind download button.
	var downloadBtn = document.getElementById('pt-download');
	if (downloadBtn) {
		downloadBtn.addEventListener('click', downloadExport);
	}

	// Initialize backend AI chat via the factory.
	var adminChatContainer = document.getElementById('pt-investigate');
	if (adminChatContainer && window.TracewpChat && ptAdmin.hasApiKey) {
		window._tracewpAdminChat = TracewpChat.create(adminChatContainer, {
			model: ptAdmin.aiModel || '',
			freeOnly: ptAdmin.aiFreeOnly,
			restUrl: ptAdmin.restUrl,
			nonce: ptAdmin.nonce,
			compact: false,
		});
	}

	// ── AI Settings ──────────────────────────────────────

	function setKeyStatus(message, type) {
		var el = document.getElementById('pt-key-status');
		if (!el) return;
		el.textContent = message;
		el.className = 'pt-key-status pt-key-status--' + (type || 'info');
		el.style.display = 'block';
	}

	// Save API key.
	var saveKeyBtn = document.getElementById('pt-save-key');
	if (saveKeyBtn) {
		saveKeyBtn.addEventListener('click', async function () {
			var input = document.getElementById('pt-api-key-input');
			var key = input ? input.value.trim() : '';

			if (!key) { setKeyStatus('Please enter an API key.', 'error'); return; }

			// Don't save masked key.
			if (/^\w{4}\*+\w{4}$/.test(key)) { setKeyStatus('Enter a new key to update.', 'error'); return; }

			setKeyStatus('Saving...', 'info');

			try {
				var form = new FormData();
				form.append('action', 'pt_save_api_key');
				form.append('nonce', ptAdmin.settingsNonce);
				form.append('api_key', key);

				var res = await fetch(ptAdmin.ajaxUrl, { method: 'POST', body: form });
				var data = await res.json();

				if (data.success) {
					setKeyStatus('Key saved successfully.', 'success');
					if (input && data.data.masked) input.value = data.data.masked;
					var modelSection = document.getElementById('pt-model-section');
					if (modelSection) modelSection.style.display = '';
					loadModels();
				} else {
					setKeyStatus(data.data.message || 'Failed to save key.', 'error');
				}
			} catch (e) {
				setKeyStatus('Network error: ' + e.message, 'error');
			}
		});
	}

	// Remove API key.
	var removeKeyBtn = document.getElementById('pt-remove-key');
	if (removeKeyBtn) {
		removeKeyBtn.addEventListener('click', async function () {
			if (!confirm('Remove your OpenRouter API key?')) return;

			try {
				var form = new FormData();
				form.append('action', 'pt_save_api_key');
				form.append('nonce', ptAdmin.settingsNonce);
				form.append('api_key', '');

				var res = await fetch(ptAdmin.ajaxUrl, { method: 'POST', body: form });
				var data = await res.json();

				if (data.success) {
					setKeyStatus('Key removed.', 'info');
					var input = document.getElementById('pt-api-key-input');
					if (input) input.value = '';
					var modelSection = document.getElementById('pt-model-section');
					if (modelSection) modelSection.style.display = 'none';
				}
			} catch (e) {
				setKeyStatus('Error: ' + e.message, 'error');
			}
		});
	}

	// Validate key.
	var validateBtn = document.getElementById('pt-validate-key');
	if (validateBtn) {
		validateBtn.addEventListener('click', async function () {
			setKeyStatus('Testing connection...', 'info');

			try {
				var form = new FormData();
				form.append('action', 'pt_validate_api_key');
				form.append('nonce', ptAdmin.settingsNonce);

				var res = await fetch(ptAdmin.ajaxUrl, { method: 'POST', body: form });
				var data = await res.json();

				if (data.success) {
					var info = data.data.data || {};
					var msg = 'Connected';
					if (info.label) msg += ' (' + info.label + ')';
					if (info.limit != null) msg += ' — credits: $' + (info.usage != null ? (info.limit - info.usage).toFixed(2) : info.limit);
					setKeyStatus(msg, 'success');
				} else {
					setKeyStatus(data.data.message || 'Connection failed.', 'error');
				}
			} catch (e) {
				setKeyStatus('Network error: ' + e.message, 'error');
			}
		});
	}

	// Fetch models.
	async function loadModels() {
		var select = document.getElementById('pt-model-select');
		if (!select) return;

		select.innerHTML = '<option value="">Loading models...</option>';

		try {
			var form = new FormData();
			form.append('action', 'pt_fetch_models');
			form.append('nonce', ptAdmin.settingsNonce);

			var res = await fetch(ptAdmin.ajaxUrl, { method: 'POST', body: form });
			var data = await res.json();

			if (!data.success || !data.data.models) {
				select.innerHTML = '<option value="">Failed to load models</option>';
				return;
			}

			var models = data.data.models;
			var currentModel = ptAdmin.aiModel || '';

			// Clear and build with DOM elements (no innerHTML from external data).
			select.innerHTML = '';
			var defaultOpt = document.createElement('option');
			defaultOpt.value = '';
			defaultOpt.textContent = 'Select a model...';
			select.appendChild(defaultOpt);

			// Group by provider.
			var grouped = {};
			models.forEach(function (m) {
				var parts = m.id.split('/');
				var provider = parts[0] || 'other';
				if (!grouped[provider]) grouped[provider] = [];
				grouped[provider].push(m);
			});

			Object.keys(grouped).sort().forEach(function (provider) {
				var optgroup = document.createElement('optgroup');
				optgroup.label = provider;
				grouped[provider].forEach(function (m) {
					var price = m.pricing.prompt > 0 ? '$' + (m.pricing.prompt * 1000000).toFixed(2) + '/M' : 'Free';
					var vision = m.supports_vision ? ' \uD83D\uDC41' : '';
					var ctx = m.context_length ? ' (' + Math.round(m.context_length / 1000) + 'K)' : '';
					var opt = document.createElement('option');
					opt.value = m.id;
					opt.textContent = m.name + ctx + ' \u2014 ' + price + vision;
					if (m.id === currentModel) opt.selected = true;
					optgroup.appendChild(opt);
				});
				select.appendChild(optgroup);
			});

			// Show model info on change.
			select.addEventListener('change', function () {
				var infoEl = document.getElementById('pt-model-info');
				if (!infoEl) return;
				var sel = models.find(function (m) { return m.id === select.value; });
				if (sel) {
					infoEl.textContent = 'Context: ' + (sel.context_length || 'unknown').toLocaleString() + ' tokens' + (sel.supports_vision ? ' | Vision supported' : '');
					infoEl.style.display = 'block';

					// If user selects a specific non-free model, uncheck free-only.
					var freeOnlyCb = document.getElementById('pt-free-only');
					if (freeOnlyCb && freeOnlyCb.checked && sel.pricing && sel.pricing.prompt > 0) {
						freeOnlyCb.checked = false;
					}
				} else {
					infoEl.style.display = 'none';
				}
			});
		} catch (e) {
			select.innerHTML = '<option value="">Error loading models</option>';
		}
	}

	// Refresh models button.
	var refreshBtn = document.getElementById('pt-refresh-models');
	if (refreshBtn) {
		refreshBtn.addEventListener('click', function () {
			loadModels();
		});
	}

	// When free-only is checked, reset model selection to auto.
	var freeOnlyCheckbox = document.getElementById('pt-free-only');
	if (freeOnlyCheckbox) {
		freeOnlyCheckbox.addEventListener('change', function () {
			if (this.checked) {
				var modelSelect = document.getElementById('pt-model-select');
				if (modelSelect) modelSelect.value = '';
				var infoEl = document.getElementById('pt-model-info');
				if (infoEl) infoEl.style.display = 'none';
			}
		});
	}

	// Save AI settings (model + free only).
	var saveAiBtn = document.getElementById('pt-save-ai-settings');
	if (saveAiBtn) {
		saveAiBtn.addEventListener('click', async function () {
			var modelSelect = document.getElementById('pt-model-select');
			var freeOnly = document.getElementById('pt-free-only');

			// Save via the standard settings form by submitting to options.php,
			// but we need to do it via AJAX to only update AI fields.
			// For now, use a simple REST call.
			try {
				var res = await fetch(ptAdmin.restUrl + 'settings/ai', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ptAdmin.nonce },
					body: JSON.stringify({
						ai_model: modelSelect ? modelSelect.value : '',
						ai_free_only: freeOnly ? freeOnly.checked : false,
					}),
				});
				var data = await res.json();
				if (res.ok) {
					setKeyStatus('AI settings saved.', 'success');
				} else {
					setKeyStatus(data.message || 'Failed to save settings.', 'error');
				}
			} catch (e) {
				setKeyStatus('Error: ' + e.message, 'error');
			}
		});
	}

	// Auto-load models on settings page if key exists.
	if (ptAdmin.hasApiKey && document.getElementById('pt-model-select')) {
		loadModels();
	}
})();
