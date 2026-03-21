<?php
/**
 * Output formatter.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Formatter {

	/**
	 * Format a canonical payload into two output formats.
	 *
	 * Text: Prompt instructions + human-readable context summary.
	 * Data: Prompt instructions + full JSON payload.
	 *
	 * Both formats are self-contained — paste either into an AI and it works.
	 *
	 * @param array $payload Canonical payload.
	 * @return array
	 */
	public function format( $payload ) {
		$instructions = $this->build_instructions( $payload );
		$output       = $instructions . "\n\n" . $this->to_text( $payload );

		return array(
			'output' => $output,
		);
	}

	/**
	 * Build prompt instructions (role, constraints, task).
	 *
	 * Shared prefix for both formats so either one is self-contained.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private function build_instructions( $payload ) {
		$mode  = $payload['task']['mode'] ?? 'general';
		$parts = array();

		$parts[] = "Role:\n" . $this->build_role( $mode, $payload );
		$parts[] = "Constraints:\n" . implode( "\n", array_map(
			static function ( $line ) {
				return '- ' . $line;
			},
			$this->build_constraints( $mode, $payload )
		) );

		if ( ! empty( $payload['task']['notes'] ) ) {
			$parts[] = "User notes:\n" . $payload['task']['notes'];
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Create a comprehensive plain-text context summary.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private function to_text( $payload ) {
		$lines = array();

		$lines[] = '# TraceWP Site Context';
		$lines[] = 'Scope: ' . $payload['meta']['scope'];
		$lines[] = 'URL: ' . ( $payload['page']['url'] ?: $payload['site']['home_url'] );
		$lines[] = '';

		// Build a table of contents so the AI knows what's available.
		$toc = array( 'Site', 'Server Environment', 'WP Configuration' );
		if ( ! empty( $payload['page']['title'] ) || ! empty( $payload['page']['front_page_type'] ) ) {
			$toc[] = 'Page';
		}
		if ( ! empty( $payload['element']['selector'] ) ) {
			$toc[] = 'Element';
		}
		$toc[] = 'Active Plugins (' . count( $payload['site']['active_plugins'] ?? array() ) . ')';
		if ( ! empty( $payload['content_stats'] ) ) {
			$toc[] = 'Content Stats';
		}
		if ( ! empty( $payload['theme_mods'] ) ) {
			$toc[] = 'Theme Customizer Settings';
		}
		if ( ! empty( $payload['widgets'] ) ) {
			$toc[] = 'Widget Areas';
		}
		if ( ! empty( $payload['menu_structure'] ) ) {
			$toc[] = 'Menus';
		}
		if ( ! empty( $payload['shortcodes'] ) ) {
			$toc[] = 'Shortcodes';
		}
		if ( ! empty( $payload['image_sizes'] ) ) {
			$toc[] = 'Image Sizes';
		}
		if ( ! empty( $payload['template_overrides'] ) ) {
			$toc[] = 'Template Overrides';
		}
		if ( ! empty( $payload['custom_hooks'] ) ) {
			$toc[] = 'Non-Core Hooks';
		}
		if ( ! empty( $payload['environment']['cron']['total'] ) ) {
			$toc[] = 'Scheduled Tasks';
		}
		if ( ! empty( $payload['environment']['htaccess'] ) ) {
			$toc[] = '.htaccess';
		}
		if ( ! empty( $payload['environment']['debug_log']['lines'] ) ) {
			$toc[] = 'Debug Log';
		}
		$lines[] = 'Includes: ' . implode( ', ', $toc );
		$lines[] = '';

		// ── Site basics ──
		$lines[] = '## Site';
		$lines[] = 'Name: ' . $payload['site']['name'];
		$lines[] = 'WordPress: ' . $payload['site']['wordpress_version'];
		$lines[] = 'PHP: ' . $payload['site']['php_version'];
		$theme = $payload['site']['theme'];
		$theme_line = 'Theme: ' . $theme['name'] . ' ' . $theme['version'] . ' (' . $payload['detections']['theme']['type'] . ')';
		if ( ! empty( $theme['update_available'] ) ) {
			$theme_line .= ' -> ' . $theme['update_available'];
		}
		$lines[] = $theme_line;
		if ( empty( $theme['is_child_theme'] ) ) {
			$lines[] = 'Child theme: none';
		} else {
			$lines[] = 'Child theme: ' . $theme['stylesheet'];
		}
		$lines[] = 'Permalink: ' . $payload['site']['permalink'];
		$lines[] = '';

		// ── Server Environment ──
		if ( ! empty( $payload['environment']['server'] ) ) {
			$srv = $payload['environment']['server'];
			$lines[] = '## Server Environment';
			$lines[] = 'Memory limit: ' . ( $srv['memory_limit'] ?? 'unknown' );
			$lines[] = 'Max execution time: ' . ( $srv['max_execution_time'] ?? 'unknown' ) . 's';
			$lines[] = 'Upload max: ' . ( $srv['upload_max_filesize'] ?? 'unknown' );
			$lines[] = 'Post max: ' . ( $srv['post_max_size'] ?? 'unknown' );
			$lines[] = 'Max input vars: ' . ( $srv['max_input_vars'] ?? 'unknown' );
			$lines[] = 'SSL: ' . ( ! empty( $srv['ssl'] ) ? 'yes' : 'no' );
			if ( ! empty( $srv['php_extensions'] ) ) {
				$lines[] = 'PHP extensions: ' . implode( ', ', $srv['php_extensions'] );
			}
			$lines[] = '';
		}

		// ── WP Config Constants ──
		if ( ! empty( $payload['environment']['wp_constants'] ) ) {
			$lines[] = '## WP Configuration';
			foreach ( $payload['environment']['wp_constants'] as $name => $val ) {
				$lines[] = $name . ': ' . $val;
			}
			$lines[] = '';
		}

		// ── Object Cache ──
		if ( ! empty( $payload['environment']['object_cache'] ) ) {
			$cache = $payload['environment']['object_cache'];
			if ( ! empty( $cache['external'] ) ) {
				$lines[] = 'Object cache: ' . ( $cache['type'] ?? 'external' );
			}
		}

		// ── Page context ──
		if ( ! empty( $payload['page']['title'] ) || ! empty( $payload['page']['front_page_type'] ) ) {
			$lines[] = '## Page';
			if ( ! empty( $payload['page']['title'] ) ) {
				$lines[] = 'Title: ' . $payload['page']['title'];
			}
			if ( ! empty( $payload['page']['post_type'] ) ) {
				$lines[] = 'Type: ' . $payload['page']['post_type'];
			}
			if ( ! empty( $payload['page']['template'] ) ) {
				$lines[] = 'Template: ' . $payload['page']['template'];
			}
			if ( ! empty( $payload['page']['front_page_type'] ) ) {
				$lines[] = 'Front page: ' . $payload['page']['front_page_type'];
			}
			if ( ! empty( $payload['page']['content_excerpt'] ) ) {
				$lines[] = 'Content: ' . substr( $payload['page']['content_excerpt'], 0, 400 );
			}
			if ( ! empty( $payload['page']['block_inventory'] ) ) {
				$block_list = array_map(
					static function ( $b ) {
						return $b['block'] . ' (' . $b['count'] . ')';
					},
					array_slice( $payload['page']['block_inventory'], 0, 10 )
				);
				$lines[] = 'Blocks: ' . implode( ', ', $block_list );
			}
			if ( ! empty( $payload['page']['woo_data']['product_type'] ) ) {
				$woo = $payload['page']['woo_data'];
				$lines[] = 'Product: ' . $woo['product_type'] . ', price: ' . ( $woo['price'] ?: 'n/a' ) . ', stock: ' . ( $woo['stock_status'] ?: 'n/a' );
			}
			$lines[] = '';
		}

		// ── Element ──
		if ( ! empty( $payload['element']['selector'] ) ) {
			$lines[] = '## Element';
			$lines[] = 'Selector: ' . $payload['element']['selector'];
			$lines[] = 'Type: ' . ( $payload['element']['element_type'] ?: 'unknown' );
			if ( ! empty( $payload['element']['text_preview'] ) ) {
				$lines[] = 'Text: ' . substr( $payload['element']['text_preview'], 0, 120 );
			}
			$lines[] = '';
		}

		// ── Active Plugins ──
		if ( ! empty( $payload['site']['active_plugins'] ) ) {
			$lines[] = '## Active Plugins (' . count( $payload['site']['active_plugins'] ) . ')';
			foreach ( $payload['site']['active_plugins'] as $p ) {
				$pline = $p['name'] . ' ' . $p['version'];
				if ( ! empty( $p['update_available'] ) ) {
					$pline .= ' -> ' . $p['update_available'];
				}
				$lines[] = '- ' . $pline;
			}
			$lines[] = '';
		}

		// ── Content Stats ──
		if ( ! empty( $payload['content_stats'] ) ) {
			$lines[] = '## Content';
			foreach ( $payload['content_stats'] as $type => $stats ) {
				$parts = array( $stats['published'] . ' published' );
				if ( $stats['draft'] > 0 ) {
					$parts[] = $stats['draft'] . ' draft';
				}
				$lines[] = $stats['label'] . ': ' . implode( ', ', $parts );
			}
			$lines[] = '';
		}

		// ── Theme Customizer Settings ──
		if ( ! empty( $payload['theme_mods'] ) ) {
			$lines[] = '## Theme Customizer Settings';
			foreach ( $payload['theme_mods'] as $key => $val ) {
				if ( is_array( $val ) || is_object( $val ) ) {
					$val = wp_json_encode( $val );
				}
				$display = is_bool( $val ) ? ( $val ? 'true' : 'false' ) : (string) $val;
				if ( strlen( $display ) > 120 ) {
					$display = substr( $display, 0, 120 ) . '...';
				}
				$lines[] = $key . ': ' . $display;
			}
			$lines[] = '';
		}

		// ── Widgets ──
		if ( ! empty( $payload['widgets'] ) ) {
			$lines[] = '## Widget Areas';
			foreach ( $payload['widgets'] as $sidebar => $widgets ) {
				$lines[] = $sidebar . ': ' . implode( ', ', $widgets );
			}
			$lines[] = '';
		}

		// ── Menu Structure ──
		if ( ! empty( $payload['menu_structure'] ) ) {
			$lines[] = '## Menus';
			foreach ( $payload['menu_structure'] as $menu ) {
				$lines[] = $menu['name'] . ' (' . $menu['location'] . ', ' . $menu['count'] . ' items)';
				if ( ! empty( $menu['items'] ) ) {
					foreach ( array_slice( $menu['items'], 0, 15 ) as $item ) {
						$indent = $item['parent'] > 0 ? '    ' : '  ';
						$lines[] = $indent . '- ' . $item['title'] . ' [' . $item['type'] . ']';
					}
				}
			}
			$lines[] = '';
		}

		// ── Shortcodes ──
		if ( ! empty( $payload['shortcodes'] ) ) {
			$tags = array_column( $payload['shortcodes'], 'tag' );
			$lines[] = '## Registered Shortcodes (' . count( $tags ) . ')';
			$lines[] = implode( ', ', array_slice( $tags, 0, 40 ) );
			if ( count( $tags ) > 40 ) {
				$lines[] = '(and ' . ( count( $tags ) - 40 ) . ' more)';
			}
			$lines[] = '';
		}

		// ── Image Sizes ──
		if ( ! empty( $payload['image_sizes'] ) ) {
			$lines[] = '## Image Sizes';
			foreach ( $payload['image_sizes'] as $size ) {
				$crop = $size['crop'] ? ', crop' : '';
				$lines[] = $size['name'] . ': ' . $size['width'] . 'x' . $size['height'] . $crop;
			}
			$lines[] = '';
		}

		// ── Template Overrides ──
		if ( ! empty( $payload['template_overrides'] ) ) {
			$lines[] = '## Template Overrides';
			foreach ( $payload['template_overrides'] as $override ) {
				$lines[] = '- ' . $override;
			}
			$lines[] = '';
		}

		// ── Custom Hooks ──
		if ( ! empty( $payload['custom_hooks'] ) ) {
			$lines[] = '## Non-Core Hooks';
			foreach ( $payload['custom_hooks'] as $action => $hooks ) {
				$names = array_column( $hooks, 'callback' );
				$lines[] = $action . ': ' . implode( ', ', array_slice( $names, 0, 10 ) );
			}
			$lines[] = '';
		}

		// ── Cron ──
		if ( ! empty( $payload['environment']['cron'] ) ) {
			$cron = $payload['environment']['cron'];
			if ( $cron['total'] > 0 ) {
				$lines[] = '## Scheduled Tasks';
				if ( $cron['overdue'] > 0 ) {
					$lines[] = 'WARNING: ' . $cron['overdue'] . ' overdue task(s)';
				}
				if ( ! empty( $cron['disabled'] ) ) {
					$lines[] = 'WP-Cron is DISABLED (DISABLE_WP_CRON = true)';
				}
				foreach ( array_slice( $cron['jobs'], 0, 15 ) as $job ) {
					$flag = $job['overdue'] ? ' [OVERDUE]' : '';
					$lines[] = '- ' . $job['hook'] . ' (' . $job['schedule'] . ') next: ' . $job['next_run'] . $flag;
				}
				$lines[] = '';
			}
		}

		// ── .htaccess ──
		if ( ! empty( $payload['environment']['htaccess'] ) ) {
			$lines[] = '## .htaccess';
			$lines[] = $payload['environment']['htaccess'];
			$lines[] = '';
		}

		// ── Debug Log ──
		if ( ! empty( $payload['environment']['debug_log']['lines'] ) ) {
			$log = $payload['environment']['debug_log'];
			$lines[] = '## Debug Log (last ' . count( $log['lines'] ) . ' lines, ' . $log['size_human'] . ' total)';
			foreach ( $log['lines'] as $line ) {
				$lines[] = $line;
			}
			$lines[] = '';
		}

		// ── REST API ──
		if ( ! empty( $payload['environment']['rest_api'] ) ) {
			$rest = $payload['environment']['rest_api'];
			if ( empty( $rest['accessible'] ) ) {
				$lines[] = 'REST API: NOT ACCESSIBLE' . ( ! empty( $rest['error'] ) ? ' — ' . $rest['error'] : '' );
				$lines[] = '';
			}
		}

		// ── Editing Hints ──
		if ( ! empty( $payload['editing_hints'] ) ) {
			$lines[] = '## Editing Hints';
			foreach ( $payload['editing_hints'] as $hint ) {
				$lines[] = '- ' . $hint['label'];
			}
			$lines[] = '';
		}

		// ── Notes ──
		if ( ! empty( $payload['site']['key_plugins_summary'] ) ) {
			$lines[] = '## Notes';
			foreach ( $payload['site']['key_plugins_summary'] as $summary ) {
				$lines[] = '- ' . $summary;
			}
			$lines[] = '';
		}

		// ── User Notes ──
		if ( ! empty( $payload['export']['notes'] ) ) {
			$lines[] = '## User Notes';
			$lines[] = $payload['export']['notes'];
		}

		return rtrim( implode( "\n", $lines ) );
	}

	/**
	 * Build a single adaptive role string based on auto-detected mode.
	 *
	 * @param string $mode    Auto-detected mode.
	 * @param array  $payload Full payload.
	 * @return string
	 */
	private function build_role( $mode, $payload ) {
		$base = 'You are a WordPress expert assisting with a site task. Use only the exported context below. Prefer the editing paths indicated by the editing hints.';

		if ( 'woo' === $mode ) {
			$base .= ' This is a WooCommerce page — use the product data and WooCommerce detection signals to guide your recommendations. Distinguish between WooCommerce settings, theme templates, and custom code.';
		}

		if ( 'element' === $mode ) {
			$base .= ' A front-end element has been captured — use the selector, classes, and parent chain to identify it precisely. If a builder controls this element, suggest the builder\'s editing path first.';
		}

		return $base;
	}

	/**
	 * Build adaptive constraints based on what's present in the payload.
	 *
	 * @param string $mode    Auto-detected mode.
	 * @param array  $payload Full payload.
	 * @return array
	 */
	private function build_constraints( $mode, $payload ) {
		$constraints = array(
			'This plugin exports context only. It does not edit files or connect to AI providers.',
			'Prefer editing paths that align with detected builders and post mappings.',
		);

		// Child theme awareness.
		if ( empty( $payload['detections']['theme']['is_child_theme'] ) ) {
			$constraints[] = 'No child theme is active — be cautious suggesting theme file edits as they will be overwritten by updates.';
		}

		// Snippets plugin awareness.
		if ( ! empty( $payload['detections']['plugins']['wpcode'] ) || ! empty( $payload['detections']['plugins']['code_snippets'] ) ) {
			$constraints[] = 'A snippets plugin is available — prefer it over direct theme file edits for PHP/hook changes.';
		}

		// Update awareness.
		$has_updates = false;
		foreach ( $payload['site']['active_plugins'] ?? array() as $p ) {
			if ( ! empty( $p['update_available'] ) ) {
				$has_updates = true;
				break;
			}
		}
		if ( $has_updates || ! empty( $payload['site']['theme']['update_available'] ) ) {
			$constraints[] = 'Some plugins or the theme have pending updates — if troubleshooting a bug, suggest updating as an early step.';
		}

		// WooCommerce-specific.
		if ( 'woo' === $mode ) {
			$constraints[] = 'Distinguish between WooCommerce settings changes and code/template changes.';
		}

		return $constraints;
	}

}
