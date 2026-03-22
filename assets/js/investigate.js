/**
 * TraceWP AI Investigate Chat — reusable module.
 *
 * Usage:
 *   window.TracewpChat.create(containerEl, config)
 *
 * Config:
 *   apiKey        (string)  OpenRouter API key
 *   model         (string)  Model ID or '' for auto
 *   freeOnly      (bool)    Use free tier routing
 *   restUrl       (string)  WP REST base URL for tool endpoints
 *   nonce         (string)  WP REST nonce
 *   compact       (bool)    Compact mode for front-end panel (shorter messages area)
 *   elementData   (object)  Optional pre-captured element to auto-send
 *   pageUrl       (string)  Optional page URL for element context
 */
(function () {
	'use strict';

	// Tool definitions (shared across all instances).
	var toolDefinitions = [
		{ type: 'function', function: { name: 'read_file', description: 'Read a file from the WordPress installation. Path relative to WP root or wp-content.', parameters: { type: 'object', properties: { path: { type: 'string', description: 'e.g. wp-content/themes/mytheme/style.css' } }, required: ['path'] } } },
		{ type: 'function', function: { name: 'list_directory', description: 'List files and subdirectories at a path.', parameters: { type: 'object', properties: { path: { type: 'string' }, depth: { type: 'integer', description: '1-3, default 1' } }, required: ['path'] } } },
		{ type: 'function', function: { name: 'search_files', description: 'Search for files by name or content.', parameters: { type: 'object', properties: { directory: { type: 'string' }, pattern: { type: 'string' }, type: { type: 'string', enum: ['name', 'content'] } }, required: ['directory', 'pattern'] } } },
		{ type: 'function', function: { name: 'get_option', description: 'Read a WordPress option value.', parameters: { type: 'object', properties: { option_name: { type: 'string' } }, required: ['option_name'] } } },
		{ type: 'function', function: { name: 'fetch_page_html', description: 'Fetch rendered HTML of a same-domain page. Scripts stripped.', parameters: { type: 'object', properties: { url: { type: 'string' } }, required: ['url'] } } },
		{ type: 'function', function: { name: 'get_template_hierarchy', description: 'Get template files WordPress uses for a URL.', parameters: { type: 'object', properties: { url: { type: 'string' } }, required: ['url'] } } },
		{ type: 'function', function: { name: 'get_active_theme_files', description: 'List all PHP/CSS/JS files in the active theme.', parameters: { type: 'object', properties: {} } } },
	];

	var toolEndpoints = {
		read_file: 'tool/read-file', list_directory: 'tool/list-directory',
		search_files: 'tool/search-files', get_option: 'tool/get-option',
		fetch_page_html: 'tool/fetch-page-html', get_template_hierarchy: 'tool/template-hierarchy',
		get_active_theme_files: 'tool/theme-files',
	};

	function buildSystemMessage(contextJson) {
		return 'You are an expert WordPress developer investigating an issue on a live site.\n' +
			'You have read-only access to the site\'s files, database options, and rendered pages through the tools provided.\n' +
			'You CANNOT and MUST NOT edit any files \u2014 only read them.\n\n' +

			'CRITICAL RULES:\n' +
			'- Always use your tools to investigate BEFORE responding. Never guess when you can look it up.\n' +
			'- You already have the site context below. Do NOT ask the user for theme name, URL, or plugin list.\n' +
			'- Only ask the user for clarification about THEIR INTENT, not about site structure you can look up.\n' +
			'- Give ONE clear recommended fix. Do not present multiple options.\n' +
			'- Never guess admin UI navigation paths. Verify them by reading the registration code (see patterns below).\n\n' +

			'INVESTIGATION PATTERNS — follow these depending on the issue type:\n\n' +

			'Theme appearance issue:\n' +
			'1. Use get_option to read "theme_mods_{theme_slug}" to see all current Customizer settings and values\n' +
			'2. Search the theme files for Customizer registration (search for "$wp_customize" or "customize_register")\n' +
			'3. Read that file to find the exact panel > section > setting hierarchy\n' +
			'4. Map the setting key to its panel/section label so you can give the exact admin path\n' +
			'5. Only then tell the user: "Go to Appearance > Customize > [Panel Name] > [Section Name] > [Setting Label]"\n\n' +

			'Plugin behavior/settings issue:\n' +
			'1. Read the plugin\'s main PHP file to find its settings option name(s)\n' +
			'2. Use get_option to read the current plugin configuration\n' +
			'3. Search the plugin files for its admin menu registration (search for "add_menu_page", "add_submenu_page", or "add_options_page")\n' +
			'4. Identify the exact settings screen and the specific field that needs changing\n\n' +

			'CSS/layout issue:\n' +
			'1. Fetch the page HTML to see the actual rendered markup and classes\n' +
			'2. Read the theme\'s stylesheet to find the relevant CSS rules and variables\n' +
			'3. Check theme_mods to see if the value is controlled by a Customizer setting\n' +
			'4. If it IS a Customizer setting, follow the theme appearance pattern above — never suggest CSS overrides for things the Customizer controls\n' +
			'5. If CSS override is genuinely needed, provide the exact selector from the actual rendered HTML\n\n' +

			'Widget/menu/sidebar issue:\n' +
			'1. Use get_option to read "sidebars_widgets" and "nav_menu_locations"\n' +
			'2. Check the template file for the relevant region (get_template_hierarchy + read the template)\n' +
			'3. Read widget instance data from the relevant "widget_*" options\n\n' +

			'WooCommerce issue:\n' +
			'1. Read relevant "woocommerce_*" options for current configuration\n' +
			'2. Check for WooCommerce template overrides in the theme (search for "woocommerce" in theme directory)\n' +
			'3. Check if it\'s a setting in WooCommerce > Settings vs a theme/template issue\n\n' +

			'RESPONSE STYLE:\n' +
			'- Be concise and direct. State what the problem is, why, and exactly how to fix it.\n' +
			'- If a theme/plugin setting controls the behavior, that is always the preferred fix.\n' +
			'- Specify the exact admin navigation path (verified from the code, not guessed).\n' +
			'- Provide copy-pasteable code when applicable.\n\n' +

			'EDITING PATH PRIORITY:\n' +
			'1. Theme/plugin settings in admin UI (Customizer, plugin settings page)\n' +
			'2. Appearance > Customize > Additional CSS\n' +
			'3. Snippets plugin (if available)\n' +
			'4. Child theme files (if active)\n' +
			'5. Direct theme file edit (last resort, warn about update risk)\n\n' +

			'WHEN YOU\'RE NOT 100% CERTAIN:\n' +
			'- If you found the setting in the code but cannot verify the exact Customizer panel name or admin path, say so honestly.\n' +
			'- Suggest the user check the theme or plugin\'s documentation for the specific setting location.\n' +
			'- For popular themes/plugins, suggest searching "[theme/plugin name] + [setting name] documentation" as a next step.\n' +
			'- Never present a guessed navigation path as if it were verified. Say "based on the code, this setting appears to be..." rather than "go to X > Y > Z" when you\'re inferring.\n' +
			'- It is always better to say "I found the relevant code but recommend checking the theme docs for the exact location" than to give a confidently wrong answer.\n\n' +

			'If the user says they\'ve made a change, re-read the relevant files rather than relying on earlier reads.\n\n' +
			'Site context:\n' + contextJson;
	}

	// ── Factory ──────────────────────────────────────────

	function create(container, config) {
		if (!container) return null;

		var state = {
			history: [],
			systemMessage: '',
			toolCache: {},
			streaming: false,
			pendingImages: [],
			tokenEstimate: 0,
			hasConsented: false,
			apiKey: null,
		};

		// Check prior consent.
		try { if (localStorage.getItem('tracewp_investigate_consent') === 'yes') state.hasConsented = true; } catch (e) {}

		var maxH = config.compact ? '320px' : '500px';
		var rows = config.compact ? 1 : 2;

		// Build UI.
		var starters = config.compact ? '' :
			'<div class="pt-chat-starters">' +
			'  <button type="button" class="pt-chat-starter">Something looks wrong on this page</button>' +
			'  <button type="button" class="pt-chat-starter">How do I change how the header looks?</button>' +
			'  <button type="button" class="pt-chat-starter">A plugin isn\u2019t behaving correctly</button>' +
			'</div>';

		container.innerHTML =
			'<div class="pt-chat-messages" style="max-height:' + maxH + ';">' +
			'  <div class="pt-chat-msg pt-chat-msg--assistant"><div class="pt-chat-msg-avatar">AI</div><div class="pt-chat-msg-body">I can read your site\u2019s files and settings to help diagnose issues. I can\u2019t make changes directly \u2014 I\u2019ll tell you exactly where and how to fix things.</div></div>' +
			starters +
			'</div>' +
			'<div class="pt-chat-input-area">' +
			'  <div class="pt-chat-images" style="display:none;"></div>' +
			'  <div class="pt-chat-input-row">' +
			'    <textarea rows="' + rows + '" placeholder="Describe the issue\u2026"></textarea>' +
			'  </div>' +
			'  <div class="pt-chat-footer">' +
			'    <div class="pt-chat-footer-left">' +
			'      <label class="pt-chat-attach" title="Attach screenshot">' +
			'        <input type="file" accept="image/*" style="display:none;">' +
			'        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>' +
			'      </label>' +
			'      <button type="button" class="pt-chat-new" title="New conversation"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg> New</button>' +
			'      <button type="button" class="pt-chat-download" title="Download conversation">Export</button>' +
			'    </div>' +
			'    <button type="button" class="pt-chat-send-btn"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send</button>' +
			'  </div>' +
			'</div>';

		var els = {
			messages: container.querySelector('.pt-chat-messages'),
			input: container.querySelector('textarea'),
			sendBtn: container.querySelector('.pt-chat-send-btn'),
			fileInput: container.querySelector('input[type="file"]'),
			images: container.querySelector('.pt-chat-images'),
			tokens: container.querySelector('.pt-chat-tokens'),
			downloadBtn: container.querySelector('.pt-chat-download'),
		};

		// ── System Context ──────────────────────────

		function initContext(formattedOutput) {
			var context = formattedOutput || '(No site context loaded. The AI will use tools to gather information.)';
			state.systemMessage = buildSystemMessage(context);
		}

		// Auto-fetch site context.
		(async function () {
			try {
				var res = await fetch(config.restUrl + 'context/site', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
					body: JSON.stringify({ url: window.location.origin + '/', safe_export: false }),
				});
				var data = await res.json();
				if (res.ok && data.formatted && data.formatted.output) {
					initContext(data.formatted.output);
				}
			} catch (e) {}

			// If element data was provided, auto-send.
			if (config.elementData) {
				var el = config.elementData;
				var msg = 'I selected this element' + (config.pageUrl ? ' on ' + config.pageUrl : '') + ':\n' +
					'Selector: ' + (el.selector || 'unknown') + '\n' +
					'Tag: ' + (el.tag || '') + (el.id ? '#' + el.id : '') +
					(el.classes && el.classes.length ? '.' + el.classes.slice(0, 4).join('.') : '') + '\n' +
					(el.text_preview ? 'Text: "' + el.text_preview.slice(0, 100) + '"' : '') + '\n\n';
				els.input.value = msg;
				els.input.focus();
				els.input.selectionStart = els.input.selectionEnd = els.input.value.length;
			}
		})();

		initContext(null);

		// ── Rendering ───────────────────────────────

		function addMessage(role, content, extra) {
			var div = document.createElement('div');
			div.className = 'pt-chat-msg pt-chat-msg--' + role;

			// Avatar circle.
			if (role === 'assistant') {
				var avatar = document.createElement('div');
				avatar.className = 'pt-chat-msg-avatar';
				avatar.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 2a3 3 0 00-3 3v4a3 3 0 006 0V5a3 3 0 00-3-3z"/><path d="M19 10v2a7 7 0 01-14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/></svg>';
				div.appendChild(avatar);
			}

			var body = document.createElement('div');
			body.className = 'pt-chat-msg-body';

			if (extra && extra.images) {
				extra.images.forEach(function (src) {
					var img = document.createElement('img');
					img.src = src;
					img.className = 'pt-chat-msg-image';
					body.appendChild(img);
				});
			}

			var text = document.createElement('div');
			text.className = 'pt-chat-msg-text';
			text.innerHTML = fmtMd(content);
			body.appendChild(text);
			div.appendChild(body);

			// User avatar after body.
			if (role === 'user') {
				var uAvatar = document.createElement('div');
				uAvatar.className = 'pt-chat-msg-avatar';
				uAvatar.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
				div.appendChild(uAvatar);
			}

			els.messages.appendChild(div);
			els.messages.scrollTop = els.messages.scrollHeight;
			return text;
		}

		function addTool(name, args) {
			var labels = { read_file: '\uD83D\uDCC4 Reading', list_directory: '\uD83D\uDCC2 Listing', search_files: '\uD83D\uDD0D Searching', get_option: '\u2699\uFE0F Option', fetch_page_html: '\uD83C\uDF10 Fetching', get_template_hierarchy: '\uD83D\uDDC2\uFE0F Templates', get_active_theme_files: '\uD83C\uDFA8 Theme files' };
			var detail = args.path || args.url || args.option_name || args.pattern || '';
			var div = document.createElement('div');
			div.className = 'pt-chat-tool';
			div.textContent = (labels[name] || name) + ': ' + detail;
			els.messages.appendChild(div);
			els.messages.scrollTop = els.messages.scrollHeight;
			return div;
		}

		function finishTool(el, size) {
			el.textContent += ' \u2713 ' + fmtBytes(size);
			el.classList.add('pt-chat-tool--done');
		}

		function escHtml(s) {
			var d = document.createElement('div');
			d.textContent = s;
			return d.innerHTML;
		}

		function fmtMd(t) {
			if (!t) return '';
			// Escape all HTML first to prevent XSS.
			var safe = escHtml(t);
			// Then apply safe formatting on the escaped string.
			safe = safe.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>');
			safe = safe.replace(/`([^`]+)`/g, '<code>$1</code>');
			safe = safe.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
			safe = safe.replace(/\n/g, '<br>');
			return safe;
		}

		function fmtBytes(b) { return b < 1024 ? b + 'B' : (b / 1024).toFixed(1) + 'KB'; }

		function updateTokens() {
			if (els.tokens) els.tokens.textContent = '~' + state.tokenEstimate.toLocaleString() + ' tokens';
		}

		// ── Images ──────────────────────────────────

		function addImageThumb(base64, type) {
			state.pendingImages.push({ data: base64, type: type });
			var thumb = document.createElement('div');
			thumb.className = 'pt-chat-thumb';
			thumb.innerHTML = '<img src="' + base64 + '"><button type="button" class="pt-chat-thumb-remove">\u00d7</button>';
			thumb.querySelector('button').addEventListener('click', function () {
				var idx = Array.from(els.images.children).indexOf(thumb);
				if (idx >= 0) state.pendingImages.splice(idx, 1);
				thumb.remove();
				if (!state.pendingImages.length) els.images.style.display = 'none';
			});
			els.images.appendChild(thumb);
			els.images.style.display = 'flex';
		}

		els.fileInput.addEventListener('change', function () {
			Array.from(this.files || []).forEach(function (f) {
				if (!f.type.startsWith('image/')) return;
				var r = new FileReader();
				r.onload = function (e) { addImageThumb(e.target.result, f.type); };
				r.readAsDataURL(f);
			});
			this.value = '';
		});

		els.input.addEventListener('paste', function (e) {
			Array.from(e.clipboardData && e.clipboardData.items || []).forEach(function (item) {
				if (!item.type.startsWith('image/')) return;
				var f = item.getAsFile();
				if (!f) return;
				var r = new FileReader();
				r.onload = function (ev) { addImageThumb(ev.target.result, f.type); };
				r.readAsDataURL(f);
			});
		});

		// ── Tool Execution ──────────────────────────

		async function execTool(name, args) {
			var ep = toolEndpoints[name];
			if (!ep) return JSON.stringify({ error: 'Unknown tool' });

			var key = name + ':' + JSON.stringify(args);
			if (state.toolCache[key]) return state.toolCache[key];

			try {
				var res = await fetch(config.restUrl + ep, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
					body: JSON.stringify(args),
				});
				var result = JSON.stringify(await res.json(), null, 2);
				state.toolCache[key] = result;
				return result;
			} catch (e) {
				return JSON.stringify({ error: 'Tool call failed: ' + e.message });
			}
		}

		// ── Consent ─────────────────────────────────

		function checkConsent() {
			if (state.hasConsented) return Promise.resolve(true);

			return new Promise(function (resolve) {
				var ov = document.createElement('div');
				ov.className = 'pt-consent-overlay';
				ov.innerHTML =
					'<div class="pt-consent-dialog">' +
					'<h3>AI Investigator</h3>' +
					'<p>The AI will read files from your site and send their contents to the selected AI model via OpenRouter. No files will be modified.</p>' +
					'<p>Your API key goes directly from your browser to OpenRouter.</p>' +
					'<div class="pt-consent-actions">' +
					'<button type="button" class="pt-consent-accept">I understand</button>' +
					'<button type="button" class="pt-consent-cancel">Cancel</button>' +
					'</div></div>';

				document.body.appendChild(ov);
				ov.querySelector('.pt-consent-accept').addEventListener('click', function () {
					state.hasConsented = true;
					try { localStorage.setItem('tracewp_investigate_consent', 'yes'); } catch (e) {}
					ov.remove();
					resolve(true);
				});
				ov.querySelector('.pt-consent-cancel').addEventListener('click', function () { ov.remove(); resolve(false); });
			});
		}

		// ── API Key (fetched on-demand, never in HTML source) ──

		async function ensureApiKey() {
			if (state.apiKey) return true;

			try {
				var form = new FormData();
				form.append('action', 'pt_get_api_key');
				form.append('nonce', config.settingsNonce);
				var res = await fetch(config.ajaxUrl, { method: 'POST', body: form });
				var data = await res.json();
				if (data.success && data.data.key) {
					state.apiKey = data.data.key;
					return true;
				}
			} catch (e) {}

			addMessage('assistant', 'Could not retrieve API key. Check your settings.');
			return false;
		}

		// ── Streaming ───────────────────────────────

		async function send() {
			var text = els.input.value.trim();
			if (!text && !state.pendingImages.length) return;
			if (state.streaming) return;

			var ok = await checkConsent();
			if (!ok) return;

			var hasKey = await ensureApiKey();
			if (!hasKey) return;

			state.streaming = true;
			els.sendBtn.disabled = true;
			els.sendBtn.textContent = '\u2026';

			// Warn if attaching images with free-only model (likely no vision support).
			if (state.pendingImages.length && config.freeOnly) {
				addMessage('assistant', 'Note: Free models may not support image analysis. If you get an error, try switching to a vision-capable model in TraceWP Settings.');
			}

			var content = [];
			var imgSrcs = [];
			state.pendingImages.forEach(function (img) {
				content.push({ type: 'image_url', image_url: { url: img.data } });
				imgSrcs.push(img.data);
			});
			if (text) content.push({ type: 'text', text: text });

			addMessage('user', text, { images: imgSrcs });
			state.history.push({ role: 'user', content: content.length === 1 && content[0].type === 'text' ? text : content });

			els.input.value = '';
			state.pendingImages = [];
			els.images.innerHTML = '';
			els.images.style.display = 'none';

			await agenticLoop();

			state.streaming = false;
			els.sendBtn.disabled = false;
			els.sendBtn.textContent = 'Send';
		}

		async function agenticLoop() {
			for (var i = 0; i < 15; i++) {
				var messages = [{ role: 'system', content: state.systemMessage }].concat(state.history);

				var body = {
					model: config.freeOnly ? 'openrouter/free' : (config.model || 'openrouter/auto'),
					messages: messages, tools: toolDefinitions, stream: true, max_tokens: 4096,
				};

				try {
					var response = await fetch('https://openrouter.ai/api/v1/chat/completions', {
						method: 'POST',
						headers: {
							'Authorization': 'Bearer ' + state.apiKey,
							'Content-Type': 'application/json',
							'HTTP-Referer': window.location.origin,
							'X-Title': 'TraceWP',
						},
						body: JSON.stringify(body),
					});

					if (!response.ok) {
						var err = await response.json().catch(function () { return {}; });
						if (response.status === 429) { addMessage('assistant', 'Rate limited, retrying\u2026'); await sleep(3000); continue; }
						var errMsg = err.error ? err.error.message : 'HTTP ' + response.status;
						// Detect common OpenRouter errors and provide helpful guidance.
						if (errMsg.indexOf('No endpoints') !== -1 || errMsg.indexOf('provider routing') !== -1) {
							if (state.pendingImages.length || (state.history.length && JSON.stringify(state.history).indexOf('image_url') !== -1)) {
								errMsg = 'The selected model doesn\u2019t support images with tool use. Try removing the image, or switch to a vision-capable model (e.g. Claude Sonnet or GPT-4o) in TraceWP Settings.';
							} else {
								errMsg = 'The selected model doesn\u2019t support tool use. Try a different model in TraceWP Settings (Claude, GPT-4, or Gemini models work well).';
							}
						}
						addMessage('assistant', errMsg);
						state.history.push({ role: 'assistant', content: 'Error' });
						return;
					}

					var result = await streamResponse(response);
					if (!result) return;

					if (result.toolCalls && result.toolCalls.length) {
						state.history.push({ role: 'assistant', content: result.text || null, tool_calls: result.toolCalls });
						for (var j = 0; j < result.toolCalls.length; j++) {
							var tc = result.toolCalls[j];
							var args = {}; try { args = JSON.parse(tc.function.arguments); } catch (e) {}
							var ind = addTool(tc.function.name, args);
							var toolResult = await execTool(tc.function.name, args);
							finishTool(ind, toolResult.length);
							state.history.push({ role: 'tool', tool_call_id: tc.id, content: toolResult });
							state.tokenEstimate += Math.ceil(toolResult.length / 4);
						}
						updateTokens();
						continue;
					}

					state.history.push({ role: 'assistant', content: result.text });
					state.tokenEstimate += Math.ceil((result.text || '').length / 4);
					updateTokens();
					return;

				} catch (e) {
					addMessage('assistant', 'Network error: ' + e.message);
					return;
				}
			}
			addMessage('assistant', 'Reached maximum investigation depth (15 steps).');
		}

		async function streamResponse(response) {
			var reader = response.body.getReader();
			var decoder = new TextDecoder();
			var buffer = '', text = '', toolCalls = [], textEl = null;

			while (true) {
				var chunk = await reader.read();
				if (chunk.done) break;
				buffer += decoder.decode(chunk.value, { stream: true });
				var lines = buffer.split('\n');
				buffer = lines.pop() || '';

				for (var i = 0; i < lines.length; i++) {
					var line = lines[i].trim();
					if (!line.startsWith('data: ')) continue;
					var d = line.slice(6);
					if (d === '[DONE]') continue;
					try {
						var p = JSON.parse(d);
						var delta = p.choices && p.choices[0] && p.choices[0].delta;
						if (!delta) continue;
						if (delta.content) {
							if (!textEl) textEl = addMessage('assistant', '');
							text += delta.content;
							textEl.innerHTML = fmtMd(text);
							els.messages.scrollTop = els.messages.scrollHeight;
						}
						if (delta.tool_calls) {
							delta.tool_calls.forEach(function (tc) {
								var idx = tc.index || 0;
								if (!toolCalls[idx]) toolCalls[idx] = { id: tc.id || '', type: 'function', function: { name: '', arguments: '' } };
								if (tc.function) {
									if (tc.function.name) toolCalls[idx].function.name = tc.function.name;
									if (tc.function.arguments) toolCalls[idx].function.arguments += tc.function.arguments;
								}
								if (tc.id) toolCalls[idx].id = tc.id;
							});
						}
					} catch (e) {}
				}
			}
			return { text: text, toolCalls: toolCalls.filter(Boolean) };
		}

		function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

		// ── Events ──────────────────────────────────

		els.sendBtn.addEventListener('click', send);
		els.input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
		});

		// Starter prompt buttons.
		container.querySelectorAll('.pt-chat-starter').forEach(function (btn) {
			btn.addEventListener('click', function () {
				els.input.value = btn.textContent;
				els.input.focus();
			});
		});

		// New conversation button.
		var newBtn = container.querySelector('.pt-chat-new');
		if (newBtn) {
			newBtn.addEventListener('click', function () {
				state.history = [];
				state.toolCache = {};
				state.tokenEstimate = 0;
				state.pendingImages = [];
				els.messages.innerHTML = config.compact ? '' :
					'<div class="pt-chat-starters">' +
					'  <button type="button" class="pt-chat-starter">Something looks wrong on this page</button>' +
					'  <button type="button" class="pt-chat-starter">How do I change how the header looks?</button>' +
					'  <button type="button" class="pt-chat-starter">A plugin isn\u2019t behaving correctly</button>' +
					'</div>';
				els.images.innerHTML = '';
				els.images.style.display = 'none';
				els.input.value = '';
				if (els.tokens) els.tokens.textContent = '';
				// Re-bind starters.
				container.querySelectorAll('.pt-chat-starter').forEach(function (btn) {
					btn.addEventListener('click', function () {
						els.input.value = btn.textContent;
						els.input.focus();
					});
				});
			});
		}

		els.downloadBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			if (!state.history.length) return;
			var md = '# TraceWP Investigation\nDate: ' + new Date().toISOString().slice(0, 10) + '\n\n---\n\n';
			state.history.forEach(function (m) {
				if (m.role === 'system') return;
				if (m.role === 'user') {
					var t = typeof m.content === 'string' ? m.content : m.content.filter(function (c) { return c.type === 'text'; }).map(function (c) { return c.text; }).join('\n');
					md += '## You\n\n' + t + '\n\n';
				} else if (m.role === 'assistant') {
					md += '## AI\n\n' + (m.content || '(tool calls)') + '\n\n';
				} else if (m.role === 'tool') {
					md += '*Tool: ' + m.content.slice(0, 200) + (m.content.length > 200 ? '...' : '') + '*\n\n';
				}
			});
			var blob = new Blob([md], { type: 'text/markdown' });
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url;
			a.download = 'tracewp-investigation-' + new Date().toISOString().slice(0, 10) + '.md';
			a.style.display = 'none';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
		});

		// ── Public interface ────────────────────────

		return {
			loadContext: function (payload) {
				initContext(payload);
				state.history = [];
				state.toolCache = {};
				state.tokenEstimate = 0;
				els.messages.innerHTML = '';
				if (els.tokens) els.tokens.textContent = '';
			},
			prefill: function (msg) {
				els.input.value = msg;
				els.input.focus();
				// Place cursor at end.
				els.input.selectionStart = els.input.selectionEnd = els.input.value.length;
			},
			sendMessage: function (msg) {
				els.input.value = msg;
				send();
			}
		};
	}

	// ── Expose globally ─────────────────────────────────

	window.TracewpChat = { create: create };

})();
