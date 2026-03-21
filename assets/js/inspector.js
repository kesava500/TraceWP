(function () {
	'use strict';

	var pageUrl = new URL(ptInspector.pageUrl, window.location.origin);
	pageUrl.searchParams.delete('pt_inspect');
	pageUrl.searchParams.delete('_pt_nonce');

	document.documentElement.classList.add('pt-inspector-active');
	document.body.classList.add('pt-inspector-active');

	// Hover overlay.
	var overlay = document.createElement('div');
	overlay.id = 'pt-inspector-overlay';
	document.body.appendChild(overlay);

	// Panel.
	var panel = document.createElement('div');
	panel.id = 'pt-inspector-panel';

	var hasAI = ptInspector.hasApiKey && window.TracewpChat;

	panel.innerHTML =
		'<div class="pt-panel-header">' +
		'  <strong>TraceWP</strong>' +
		'  <div class="pt-panel-tabs">' +
		'    <button type="button" class="pt-panel-tab pt-panel-tab--active" data-tab="inspect">Inspect</button>' +
		(hasAI ? '    <button type="button" class="pt-panel-tab" data-tab="ai" id="pt-ai-tab">Ask AI</button>' : '') +
		'  </div>' +
		'  <button class="pt-panel-close" id="pt-close" title="Close">\u00d7</button>' +
		'</div>' +
		'<div class="pt-panel-body">' +
		// Inspect tab.
		'  <div class="pt-tab-content pt-tab-content--active" data-tab="inspect">' +
		'    <span class="pt-panel-hint">Click any element to capture it.</span>' +
		'    <span class="pt-selected-label">Selected element</span>' +
		'    <pre id="pt-selection-preview">None</pre>' +
		'    <span class="pt-selected-label">Output</span>' +
		'    <textarea id="pt-output" rows="4" readonly placeholder="Click an element\u2026"></textarea>' +
		'    <div id="pt-token-info" class="pt-token-info" style="display:none;"></div>' +
		'    <div class="pt-btn-row">' +
		'      <button type="button" id="pt-copy">Copy</button>' +
		'      <button type="button" id="pt-download-inspector">Download</button>' +
		(hasAI ? '      <button type="button" id="pt-ask-ai-btn" class="pt-btn-primary" style="display:none;">Ask AI about this \u2192</button>' : '') +
		'    </div>' +
		'  </div>' +
		// AI Chat tab.
		(hasAI ?
		'  <div class="pt-tab-content" data-tab="ai">' +
		'    <div id="pt-inspector-chat"></div>' +
		'  </div>' : '') +
		'</div>';

	document.body.appendChild(panel);

	var currentTarget = null;
	var lastCaptured = null;
	var inspectorChat = null;

	// ── Tab switching ───────────────────────────

	panel.querySelectorAll('.pt-panel-tab').forEach(function (tab) {
		tab.addEventListener('click', function () {
			panel.querySelectorAll('.pt-panel-tab').forEach(function (t) { t.classList.remove('pt-panel-tab--active'); });
			panel.querySelectorAll('.pt-tab-content').forEach(function (c) { c.classList.remove('pt-tab-content--active'); });
			tab.classList.add('pt-panel-tab--active');
			var content = panel.querySelector('.pt-tab-content[data-tab="' + tab.dataset.tab + '"]');
			if (content) content.classList.add('pt-tab-content--active');
		});
	});

	// ── Initialize AI chat in inspector ─────────

	if (hasAI) {
		var chatContainer = panel.querySelector('#pt-inspector-chat');
		if (chatContainer) {
			inspectorChat = TracewpChat.create(chatContainer, {
				model: ptInspector.aiModel || '',
				freeOnly: ptInspector.aiFreeOnly,
				restUrl: ptInspector.aiRestUrl,
				nonce: ptInspector.nonce,
				ajaxUrl: ptInspector.ajaxUrl,
				settingsNonce: ptInspector.settingsNonce,
				compact: true,
			});
		}
	}

	// ── Helpers ──────────────────────────────────

	function cssPath(el) {
		if (!(el instanceof Element)) return '';
		var parts = [], node = el, depth = 0;
		while (node && node.nodeType === 1 && depth < 5) {
			var part = node.tagName.toLowerCase();
			if (node.id) { parts.unshift(part + '#' + node.id); break; }
			var cls = Array.from(node.classList).slice(0, 3).map(function (c) { return '.' + c; }).join('');
			parts.unshift(part + cls);
			node = node.parentElement; depth++;
		}
		return parts.join(' > ');
	}

	function captureElement(el) {
		var chain = [];
		var anc = el.parentElement;
		while (anc && chain.length < 4) {
			chain.push({ tag: anc.tagName.toLowerCase(), id: anc.id || '', classes: Array.from(anc.classList).slice(0, 3) });
			anc = anc.parentElement;
		}
		return {
			selector: cssPath(el), tag: el.tagName.toLowerCase(), id: el.id || '',
			classes: Array.from(el.classList),
			text_preview: (el.innerText || '').trim().slice(0, 280),
			html_snippet: (el.outerHTML || '').slice(0, 1200),
			attributes: Array.from(el.attributes).reduce(function (o, a) { if (a.name !== 'class' && a.name !== 'style') o[a.name] = a.value; return o; }, {}),
			path: cssPath(el).split(' > '), parent_chain: chain,
		};
	}

	function moveOverlay(el) {
		var r = el.getBoundingClientRect();
		overlay.style.display = 'block';
		overlay.style.top = (r.top + window.scrollY) + 'px';
		overlay.style.left = (r.left + window.scrollX) + 'px';
		overlay.style.width = r.width + 'px';
		overlay.style.height = r.height + 'px';
	}

	function showTokens(data) {
		var el = panel.querySelector('#pt-token-info');
		if (!el || !data) return;
		el.textContent = '~' + (data.tokens || 0).toLocaleString() + ' tokens';
		el.style.display = 'block';
	}

	// ── API calls ───────────────────────────────

	async function exportElement(elementData) {
		var output = panel.querySelector('#pt-output');
		output.value = 'Exporting\u2026';
		try {
			var res = await fetch(ptInspector.restUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ptInspector.nonce },
				body: JSON.stringify({ url: pageUrl.toString(), safe_export: false, element: elementData }),
			});
			var data = await res.json();
			output.value = res.ok ? data.formatted.output : (data.message || 'Export failed.');
			if (res.ok) showTokens(data.token_estimate);
		} catch (e) {
			output.value = 'Network error: ' + e.message;
		}
	}

	// ── Event listeners ─────────────────────────

	var inspectActive = true;

	function shouldIgnore(target) {
		if (!target || !(target instanceof Element)) return true;
		if (panel.contains(target)) return true;
		if (target.closest('.pt-consent-overlay')) return true;
		if (target.closest('#wpadminbar')) return true;
		return false;
	}

	document.addEventListener('mousemove', function (e) {
		if (!inspectActive || shouldIgnore(e.target)) return;
		currentTarget = e.target;
		moveOverlay(e.target);
		var c = captureElement(e.target);
		panel.querySelector('#pt-selection-preview').textContent =
			c.selector + '\n' +
			c.tag + (c.id ? '#' + c.id : '') + (c.classes.length ? '.' + c.classes.slice(0, 3).join('.') : '') + '\n' +
			(c.text_preview || '').slice(0, 80);
	}, true);

	document.addEventListener('click', function (e) {
		if (!inspectActive || shouldIgnore(e.target)) return;
		e.preventDefault();
		e.stopPropagation();
		if (currentTarget) {
			lastCaptured = captureElement(currentTarget);
			exportElement(lastCaptured);
			// Show the Ask AI button now that we have an element.
			var askBtn = panel.querySelector('#pt-ask-ai-btn');
			if (askBtn) askBtn.style.display = '';
		}
	}, true);

	panel.querySelector('#pt-copy').addEventListener('click', async function () {
		var output = panel.querySelector('#pt-output');
		if (!output.value) return;
		try {
			await navigator.clipboard.writeText(output.value);
			var btn = this, orig = btn.textContent;
			btn.textContent = 'Copied!';
			setTimeout(function () { btn.textContent = orig; }, 1500);
		} catch (e) { output.select(); }
	});

	panel.querySelector('#pt-download-inspector').addEventListener('click', function () {
		var output = panel.querySelector('#pt-output');
		if (!output.value) return;
		var blob = new Blob([output.value], { type: 'text/plain' });
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url; a.download = 'tracewp-element-' + new Date().toISOString().slice(0, 10) + '.txt';
		document.body.appendChild(a); a.click(); document.body.removeChild(a);
		URL.revokeObjectURL(url);
	});

	// When switching to AI tab, auto-prefill captured element context.
	function prefillAiWithElement() {
		if (!lastCaptured || !inspectorChat) return;
		var el = lastCaptured;
		var msg = 'I selected this element on ' + pageUrl.toString() + ':\n' +
			'Selector: ' + (el.selector || 'unknown') + '\n' +
			'Tag: ' + el.tag + (el.id ? '#' + el.id : '') +
			(el.classes.length ? '.' + el.classes.slice(0, 4).join('.') : '') + '\n' +
			(el.text_preview ? 'Text: "' + el.text_preview.slice(0, 100) + '"' : '') + '\n\nIssue: ';
		inspectorChat.prefill(msg);
	}

	var aiTab = panel.querySelector('#pt-ai-tab');
	if (aiTab && inspectorChat) {
		aiTab.addEventListener('click', prefillAiWithElement);
	}

	// Ask AI button — prominent CTA after element capture.
	var askAiBtn = panel.querySelector('#pt-ask-ai-btn');
	if (askAiBtn && inspectorChat) {
		askAiBtn.addEventListener('click', function () {
			// Switch to AI tab.
			if (aiTab) aiTab.click();
			prefillAiWithElement();
		});
	}

	panel.querySelector('#pt-close').addEventListener('click', function () {
		inspectActive = false;
		panel.style.display = 'none';
		overlay.style.display = 'none';
		document.documentElement.classList.remove('pt-inspector-active');
		document.body.classList.remove('pt-inspector-active');
	});
})();
