# TraceWP

**Give AI the context it needs to actually help with your WordPress site.**

TraceWP packages your site's structure — theme, plugins, page content, element data — into AI-ready context that you can paste into ChatGPT, Claude, or any LLM. Instead of spending 10 minutes explaining your setup, one click gives the AI everything it needs to give you a useful answer.

Also includes a built-in AI investigator (bring your own OpenRouter key) that can read your site files directly and help diagnose issues from your dashboard or the front end.

## The Problem

When you ask an AI for help with your WordPress site, it doesn't know anything about your setup. You end up going back and forth:

> "What theme are you using?"  
> "What plugins do you have?"  
> "Can you show me the HTML?"  
> "What version of PHP?"  

TraceWP eliminates that. One export gives the AI your full site context upfront.

## Features

### Context Export (no API key needed)

- **One-click site context** — theme, plugins (with versions and pending updates), PHP/WP versions, permalink structure, child theme status
- **Page-level context** — content excerpt, block inventory, template file, custom meta, WooCommerce product data
- **Front-end inspector** — click any element on your live site to capture its selector, classes, parent chain, and surrounding context
- **Markdown output** — structured context with role instructions, table of contents, and comprehensive site data in a single copy-pasteable format
- **Safe export mode** — redacts emails, phone numbers, and external URLs while preserving your site's own links
- **Token estimates** — see how much context window each format uses before you paste

### AI Investigator (requires OpenRouter key)

- **Built-in chat** in both the admin dashboard and the front-end inspector
- **7 read-only tools** — the AI can read files, list directories, search code, check database options, fetch rendered HTML, trace the template hierarchy, and list theme files
- **Streaming responses** with tool-call transparency (see exactly what files the AI reads)
- **Image support** — paste or drag screenshots for visual issue diagnosis
- **Investigation patterns** — the AI follows structured checklists (reads theme_mods before guessing Customizer paths, checks plugin settings before suggesting code changes)
- **Free model support** — use OpenRouter's free tier for zero-cost investigations
- **Per-conversation caching** — files read once aren't re-sent to the AI unless you've made changes

## How It Works

### Export Flow

1. Install and activate TraceWP
2. Open **TraceWP** in the admin sidebar
3. Select a scope (entire site or specific page)
4. Click **Generate Export**
5. Copy the output and paste it into your AI conversation

The AI now knows your theme, every active plugin and its version, your PHP version, what page builder you're using, what blocks are on the page, whether you have a child theme, what updates are pending, and more.

### Inspector Flow

1. Click **Open Inspector** from the TraceWP admin page
2. Your site opens with an overlay — hover to highlight, click to capture
3. The element's selector, classes, attributes, and parent chain are packaged automatically
4. Copy the output, or click **Ask AI →** to investigate directly (if API key is configured)

### AI Investigator Flow

1. Add your OpenRouter API key in **Settings** (free tier works)
2. Describe an issue in the chat — "the header font is wrong" or "my popup close button is misaligned"
3. The AI reads the relevant theme files, checks Customizer settings, and traces the issue
4. You get a specific fix: which setting to change, or exact CSS to paste, with the correct admin path

## Security

- **All operations are read-only.** TraceWP never writes to files or the database (other than its own settings).
- **API keys are encrypted** with AES-256-CBC using your WordPress AUTH_KEY.
- **AI requests go directly from your browser to OpenRouter** — never proxied through your server.
- **File access is jailed** to your WordPress installation via `realpath()`. No path traversal.
- **wp-config.php** is served with database credentials and security keys automatically redacted.
- **Sensitive options** (passwords, secrets, tokens, keys, salts) are blocked from the option reader.
- **`.env` files** are blocked entirely.
- **Rate limited** — 60 tool calls per minute per user.
- **All endpoints require `manage_options` capability** — only administrators can use the plugin.

## Installation

1. Download or clone this repository
2. Upload the `tracewp` folder to `wp-content/plugins/`
3. Activate through the WordPress Plugins screen
4. Open **TraceWP** in the admin sidebar

For the AI Investigator, add an OpenRouter API key in **TraceWP → Settings**. Free models are enabled by default.

## File Structure

```
tracewp/
├── tracewp.php                        Main plugin file
├── assets/
│   ├── css/
│   │   ├── admin.css                  Admin styles
│   │   └── inspector.css              Front-end inspector styles (dark theme)
│   └── js/
│       ├── admin.js                   Admin page logic + settings
│       ├── inspector.js               Front-end element inspector with AI chat tab
│       └── investigate.js             Reusable AI chat factory (works in both contexts)
├── includes/
│   ├── class-pt-admin.php             Admin pages, menus, asset enqueuing
│   ├── class-pt-ai-controller.php     REST endpoints for 7 read-only AI tools
│   ├── class-pt-ai-tools.php          Tool implementations (file read, search, etc.)
│   ├── class-pt-crypto.php            AES-256-CBC encryption for API key storage
│   ├── class-pt-detector.php          Theme/plugin type detection (classic, block, builder)
│   ├── class-pt-formatter.php         Output formatting (prompt, text, JSON)
│   ├── class-pt-inspector.php         Front-end inspector bootstrap
│   ├── class-pt-page-collector.php    Page data collection (content, blocks, meta, WooCommerce)
│   ├── class-pt-payload-builder.php   Canonical context payload assembly
│   ├── class-pt-plugin.php            Plugin bootstrap, activation/deactivation
│   ├── class-pt-rest-controller.php   REST endpoints for context export
│   ├── class-pt-security.php          Capability checks, rate limiting
│   ├── class-pt-settings.php          Settings + API key management
│   ├── class-pt-site-collector.php    Site-level data (plugins, theme, menus)
│   └── class-pt-support.php           Sanitization, redaction, URL resolution
└── templates/
    ├── export.php                     Main plugin page
    ├── partials-investigate.php        AI investigator panel
    ├── partials-output.php            Export output panel
    └── settings.php                   Settings page
```

## Architecture Decisions

**API key stays client-side.** OpenRouter calls go directly from the browser. The WordPress server only handles read-only tool endpoints.

**investigate.js is a reusable factory.** `TracewpChat.create(container, config)` works in both the backend admin page and the front-end inspector. Same streaming, tool calls, caching — different containers.

**Auto-detected context mode.** The export automatically detects WooCommerce pages, captured elements, and general site context. No manual mode selection.

**Safe export redaction.** Emails, phone numbers, and external URLs are redacted while preserving the site's own domain URLs. ISO dates are protected from the phone regex.

## Requirements

- WordPress 6.4+
- PHP 7.4+
- OpenSSL extension (required for API key encryption; AI investigator is disabled without it)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup, coding standards, and PR guidelines.

To generate translation files: `wp i18n make-pot . languages/tracewp.pot`

## License

GPL v2 or later. See [LICENSE](LICENSE).

## Author

Built by [Belletty Digital](https://belletty.com).
