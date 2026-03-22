=== TraceWP ===
Contributors: bellettydigital
Tags: ai, context, debugging, development, inspector
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Package your WordPress site context for AI. One-click export of theme, plugins, page data, and element context — paste into ChatGPT, Claude, or any LLM. Includes optional built-in AI investigator.

== Description ==

TraceWP packages your site's structure, configuration, and runtime environment into AI-ready context. Copy the output into ChatGPT, Claude, or any LLM and skip the back-and-forth about your setup.

Optionally connect an OpenRouter API key for a built-in AI investigator that can read your site files, trace through templates, and suggest specific fixes — all read-only.

= Context Export (no API key required) =

* One-click site context with 15+ data points
* Server environment, wp-config constants, .htaccess, theme customizer settings
* Active plugins with versions and pending updates
* Widget areas, menu structure, content stats, cron schedules, debug log
* Front-end inspector — click any element to capture its selector and context
* Markdown output with table of contents for clean AI rendering
* Safe export mode redacts emails, phone numbers, and external URLs

= AI Investigator (requires OpenRouter key) =

* Built-in chat in admin dashboard and front-end inspector
* 7 read-only tools: file read, directory listing, file search, option lookup, page HTML fetch, template hierarchy, theme file listing
* Streaming responses with tool-call transparency
* Image support — paste screenshots for visual issue diagnosis
* Investigation patterns for accurate Customizer path resolution
* Free model support via OpenRouter's free tier

= Security =

* Completely read-only — never writes to files or the database
* API keys encrypted with AES-256-CBC (requires OpenSSL)
* Keys fetched via authenticated AJAX only — never in HTML source
* File access jailed to ABSPATH via realpath()
* wp-config credentials automatically redacted
* Sensitive database options blocked
* All endpoints require administrator capability and rate limiting
* AI chat output HTML-escaped to prevent XSS

== Installation ==

1. Upload the `tracewp` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Open **TraceWP** in the admin sidebar.
4. (Optional) Add your OpenRouter API key in Settings to enable the AI investigator.

== Frequently Asked Questions ==

= Does this change anything on my site? =

No. TraceWP is completely read-only. It reads files and settings to provide context but never modifies anything.

= Do I need an API key? =

No. The context export works without any API key. The AI investigator requires an OpenRouter API key, which has a free tier.

= Where do AI requests go? =

Directly from your browser to OpenRouter's API. Your WordPress server only handles read-only tool calls.

= Is my API key safe? =

Your key is encrypted with AES-256-CBC using your WordPress AUTH_KEY. It's fetched via authenticated AJAX when needed — it never appears in the page HTML source. OpenSSL is required; the plugin will not store keys without it.

= What models can I use? =

Any model on OpenRouter. The plugin fetches the live model list with pricing. Free models are enabled by default.

== Changelog ==

= 1.1.1 =

* Bug fixes

= 1.0.1 =

* Updated admin panel design — consistent color tokens, proper input borders and backgrounds, tighter radius matching WordPress native UI
* Fixed "Free tier" badge now displays as a visible pill with background and border
* Improved model selector UX — selecting a paid model automatically unchecks "Use free models only", and checking free-only resets the model dropdown
* Fixed broken CSS variable references from design system migration
* Front-end inspector widget refined to match v0 design spec

= 1.0.0 =

First stable public release.

* Context export with 15+ data points: site info, server environment, PHP extensions, wp-config constants, .htaccess, theme customizer settings, active plugins with update status, content statistics, widget areas, menu structure with items, registered shortcodes, image sizes, template overrides, non-core hooks, cron schedules, debug log tail, REST API status, object cache detection
* Markdown output format with table of contents and role instructions
* Single copy-pasteable format — no format selector needed
* Front-end inspector with element capture and embedded AI chat
* AI Investigator with 7 read-only tools and streaming responses
* OpenRouter integration with model selector and free tier support
* Investigation patterns in system prompt for Customizer path verification
* Honest fallback guidance when the AI cannot verify an answer
* API key encrypted with AES-256-CBC (OpenSSL required, no insecure fallback)
* API key fetched via authenticated AJAX — never in HTML source
* Safe export mode with email, phone, and external URL redaction
* XSS prevention: AI output HTML-escaped before rendering
* Model selector built with DOM elements, not string injection
* File search uses RecursiveCallbackFilterIterator for proper directory pruning
* Reusable chat factory for admin and front-end contexts
* Image paste/drag support in AI chat
* Conversation export as markdown
* Per-conversation tool response caching

= 0.9.0 =

Pre-release. AI investigator added, front-end inspector with embedded chat, OpenRouter integration, renamed from earlier development versions.

= 0.5.0 =

Pre-release. Context export with site, page, and element scope. Front-end element inspector. Three output formats (later simplified to one).

= 0.1.0 =

Initial development. Basic site context export with plugin and theme detection.
