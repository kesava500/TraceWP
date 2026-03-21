# Contributing to TraceWP

Thanks for your interest in contributing. Here's how to get started.

## Development Setup

1. Clone the repo into `wp-content/plugins/tracewp/`
2. Activate the plugin in WordPress admin
3. No build step required — the plugin uses vanilla JS and PHP only

## Coding Standards

- Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Use tabs for indentation (PHP and JS)
- All PHP classes use the `PT_` prefix
- CSS classes use the `pt-` prefix
- Text domain is `tracewp`

## Security

This plugin handles file system access and API keys. If you're modifying:

- **File read tools** — all paths must go through `resolve_path()` which jails to ABSPATH
- **Option reading** — check against `BLOCKED_OPTION_PATTERNS`
- **API key handling** — the key must never appear in HTML source. It's fetched via AJAX only

## Translations

Generate a `.pot` file with WP-CLI:

```bash
wp i18n make-pot . languages/tracewp.pot
```

## Pull Requests

- One feature or fix per PR
- Describe what changed and why
- Test on a real WordPress install before submitting
