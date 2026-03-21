<?php
/**
 * Site-wide context collector.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Site_Collector {

	/**
	 * Collect site data.
	 *
	 * @return array
	 */
	public function collect() {
		global $wp_registered_sidebars;

		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		$theme                  = wp_get_theme();
		$parent_theme           = $theme->parent();
		$post_types             = get_post_types( array(), 'objects' );
		$taxonomies             = get_taxonomies( array(), 'objects' );
		$menus                  = wp_get_nav_menus();
		$menu_locations         = get_nav_menu_locations();
		$menu_details           = array();
		$plugin_inventory       = $this->collect_plugin_inventory();
		$active_plugin_slugs    = wp_list_pluck( $plugin_inventory['active_plugins'], 'slug' );
		$key_plugins_summary    = array();

		if ( in_array( 'woocommerce', $active_plugin_slugs, true ) ) {
			$key_plugins_summary[] = __( 'WooCommerce is active; product editors, shop settings, or Woo templates may control commerce views.', 'tracewp' );
		}

		if ( array_intersect( array( 'elementor', 'bricks', 'divi-builder', 'bb-plugin' ), $active_plugin_slugs ) ) {
			$key_plugins_summary[] = __( 'A visual builder plugin is active; some pages or templates may be builder-controlled instead of theme-template-only.', 'tracewp' );
		}

		if ( array_intersect( array( 'insert-headers-and-footers', 'code-snippets' ), $active_plugin_slugs ) ) {
			$key_plugins_summary[] = __( 'A snippets plugin is active; hook or PHP customizations may live in snippets instead of theme files.', 'tracewp' );
		}

		if ( array_intersect( array( 'seo-by-rank-math', 'wordpress-seo', 'all-in-one-seo-pack' ), $active_plugin_slugs ) ) {
			$key_plugins_summary[] = __( 'An SEO plugin is active; meta titles, descriptions, schema, sitemaps, and redirects may be managed by the SEO plugin rather than the theme.', 'tracewp' );
		}

		if ( array_intersect( array( 'w3-total-cache', 'wp-super-cache', 'litespeed-cache', 'wp-fastest-cache', 'wp-rocket' ), $active_plugin_slugs ) ) {
			$key_plugins_summary[] = __( 'A caching plugin is active; changes to templates or content may require a cache purge to appear on the front end.', 'tracewp' );
		}

		foreach ( $menus as $menu ) {
			$menu_details[] = array(
				'id'         => (int) $menu->term_id,
				'name'       => $menu->name,
				'slug'       => $menu->slug,
				'item_count' => (int) $menu->count,
				'locations'  => array_keys( $menu_locations, (int) $menu->term_id, true ),
			);
		}

		// Flag menus not assigned to any theme location.
		$unassigned_menus = array();
		foreach ( $menu_details as $menu_info ) {
			if ( empty( $menu_info['locations'] ) ) {
				$unassigned_menus[] = $menu_info['name'];
			}
		}
		if ( ! empty( $unassigned_menus ) ) {
			$key_plugins_summary[] = sprintf(
				/* translators: %s: comma-separated list of menu names */
				__( 'Unassigned menus detected (%s); these menus exist but are not assigned to any theme location and may not display.', 'tracewp' ),
				implode( ', ', $unassigned_menus )
			);
		}

		// Flag plugins with pending updates.
		$outdated_plugins = array();
		foreach ( $plugin_inventory['active_plugins'] as $p ) {
			if ( ! empty( $p['update_available'] ) ) {
				$outdated_plugins[] = sprintf( '%s (%s → %s)', $p['name'], $p['version'], $p['update_available'] );
			}
		}
		if ( ! empty( $outdated_plugins ) ) {
			$key_plugins_summary[] = sprintf(
				/* translators: %s: comma-separated list of "Plugin (current → available)" strings */
				__( 'Plugin updates available: %s. If experiencing issues, updating may resolve bugs fixed in newer versions.', 'tracewp' ),
				implode( '; ', $outdated_plugins )
			);
		}

		// Check for pending theme update.
		$theme_update = $this->get_pending_theme_update( $theme->get_stylesheet() );

		return array(
			'home_url'            => home_url( '/' ),
			'site_url'            => site_url( '/' ),
			'name'                => get_bloginfo( 'name' ),
			'description'         => get_bloginfo( 'description' ),
			'language'            => get_bloginfo( 'language' ),
			'charset'             => get_bloginfo( 'charset' ),
			'wordpress_version'   => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'is_multisite'        => is_multisite(),
			'theme'               => array(
				'name'            => $theme->get( 'Name' ),
				'stylesheet'      => $theme->get_stylesheet(),
				'template'        => $theme->get_template(),
				'version'         => $theme->get( 'Version' ),
				'update_available'=> $theme_update,
				'is_child_theme'  => (bool) $parent_theme,
				'parent_theme'    => $parent_theme ? array(
					'name'       => $parent_theme->get( 'Name' ),
					'stylesheet' => $parent_theme->get_stylesheet(),
					'template'   => $parent_theme->get_template(),
					'version'    => $parent_theme->get( 'Version' ),
				) : null,
			),
			'front_page_id'       => (int) get_option( 'page_on_front' ),
			'posts_page_id'       => (int) get_option( 'page_for_posts' ),
			'permalink'           => get_option( 'permalink_structure' ),
			'post_types'          => array_values(
				array_map(
					static function( $post_type ) {
						return array(
							'name'         => $post_type->name,
							'label'        => $post_type->label,
							'public'       => (bool) $post_type->public,
							'has_archive'  => (bool) $post_type->has_archive,
						);
					},
					array_filter(
						$post_types,
						static function( $pt ) {
							// Include public types and non-core private types (custom CPTs).
							if ( $pt->public ) {
								return true;
							}
							// Skip WordPress core internal types.
							$core_private = array(
								'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
								'oembed_cache', 'user_request', 'wp_block', 'wp_template',
								'wp_template_part', 'wp_global_styles', 'wp_navigation',
								'wp_font_family', 'wp_font_face',
							);
							return ! in_array( $pt->name, $core_private, true );
						}
					)
				)
			),
			'taxonomies'          => array_values(
				array_map(
					static function( $taxonomy ) {
						return array(
							'name'         => $taxonomy->name,
							'label'        => $taxonomy->label,
							'public'       => (bool) $taxonomy->public,
							'object_types' => array_values( $taxonomy->object_type ),
						);
					},
					array_filter(
						$taxonomies,
						static function( $tax ) {
							// Include public taxonomies and non-core private ones.
							if ( $tax->public ) {
								return true;
							}
							$core_private = array(
								'nav_menu', 'link_category', 'post_format', 'wp_theme',
								'wp_template_part_area', 'wp_pattern_category',
							);
							return ! in_array( $tax->name, $core_private, true );
						}
					)
				)
			),
			'menus'               => $menu_details,
			'sidebars'            => array_values(
				array_map(
					static function( $sidebar ) {
						return array(
							'id'   => $sidebar['id'],
							'name' => $sidebar['name'],
						);
					},
					is_array( $wp_registered_sidebars ) ? $wp_registered_sidebars : array()
				)
			),
			'active_plugins'      => $plugin_inventory['active_plugins'],
			'inactive_plugin_count' => $plugin_inventory['inactive_plugin_count'],
			'key_plugins_summary' => $key_plugins_summary,
		);
	}

	/**
	 * Collect plugin inventory details.
	 *
	 * @return array
	 */
	private function collect_plugin_inventory() {
		$plugins             = get_plugins();
		$active_plugins      = array();
		$inactive_count      = 0;
		$network_active      = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$update_data         = $this->get_pending_plugin_updates();

		foreach ( $plugins as $basename => $plugin ) {
			$is_active = is_plugin_active( $basename ) || in_array( $basename, $network_active, true );
			$slug      = dirname( $basename );

			if ( '.' === $slug || empty( $slug ) ) {
				$slug = sanitize_title( $plugin['Name'] ?? $basename );
			}

			if ( $is_active ) {
				$entry = array(
					'name'           => $plugin['Name'] ?? $basename,
					'slug'           => $slug,
					'version'        => $plugin['Version'] ?? '',
					'plugin_basename'=> $basename,
					'network_active' => in_array( $basename, $network_active, true ),
				);

				// Attach pending update info if available.
				if ( isset( $update_data[ $basename ] ) ) {
					$entry['update_available'] = $update_data[ $basename ]['new_version'];
				}

				$active_plugins[] = $entry;
				continue;
			}

			++$inactive_count;
		}

		usort(
			$active_plugins,
			static function( $left, $right ) {
				return strcasecmp( $left['name'], $right['name'] );
			}
		);

		return array(
			'active_plugins'        => $active_plugins,
			'inactive_plugin_count' => $inactive_count,
		);
	}

	/**
	 * Get pending plugin updates from the WordPress update transient.
	 *
	 * Returns an associative array keyed by plugin basename with update
	 * info (new_version, slug, url, etc.) for plugins that have updates.
	 *
	 * @return array
	 */
	private function get_pending_plugin_updates() {
		$update_plugins = get_site_transient( 'update_plugins' );

		if ( ! is_object( $update_plugins ) || empty( $update_plugins->response ) ) {
			return array();
		}

		$updates = array();
		foreach ( $update_plugins->response as $basename => $update_info ) {
			$updates[ $basename ] = array(
				'new_version' => isset( $update_info->new_version ) ? (string) $update_info->new_version : '',
				'slug'        => isset( $update_info->slug ) ? (string) $update_info->slug : '',
			);
		}

		return $updates;
	}

	/**
	 * Get pending theme update version, if any.
	 *
	 * @param string $stylesheet Active theme stylesheet slug.
	 * @return string New version available, or empty string.
	 */
	private function get_pending_theme_update( $stylesheet ) {
		$update_themes = get_site_transient( 'update_themes' );

		if ( ! is_object( $update_themes ) || empty( $update_themes->response[ $stylesheet ] ) ) {
			return '';
		}

		$update = $update_themes->response[ $stylesheet ];

		return isset( $update['new_version'] ) ? (string) $update['new_version'] : '';
	}

	/**
	 * Collect theme Customizer settings.
	 *
	 * @return array
	 */
	public function collect_theme_mods() {
		$theme_slug = get_option( 'stylesheet' );
		$mods       = get_option( 'theme_mods_' . $theme_slug, array() );

		if ( ! is_array( $mods ) ) {
			return array();
		}

		// Remove internal/binary data.
		unset( $mods[0] );
		unset( $mods['sidebars_widgets'] );
		unset( $mods['nav_menu_locations'] );
		unset( $mods['custom_css_post_id'] );

		return $mods;
	}

	/**
	 * Collect widget areas and their contents.
	 *
	 * @return array
	 */
	public function collect_widgets() {
		$sidebars = get_option( 'sidebars_widgets', array() );
		if ( ! is_array( $sidebars ) ) {
			return array();
		}

		unset( $sidebars['wp_inactive_widgets'] );
		unset( $sidebars['array_version'] );

		$result = array();
		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( ! is_array( $widgets ) || empty( $widgets ) ) {
				continue;
			}

			$widget_details = array();
			foreach ( $widgets as $widget_id ) {
				// Parse widget type from ID (e.g., "text-2" -> "text").
				$type = preg_replace( '/-\d+$/', '', $widget_id );
				$widget_details[] = $type;
			}
			$result[ $sidebar_id ] = $widget_details;
		}

		return $result;
	}

	/**
	 * Collect menu structures with items.
	 *
	 * @return array
	 */
	public function collect_menu_items() {
		$menus     = wp_get_nav_menus();
		$locations = get_nav_menu_locations();
		$result    = array();

		foreach ( $menus as $menu ) {
			$items    = wp_get_nav_menu_items( $menu->term_id );
			$location = array_search( $menu->term_id, $locations, true );

			$item_list = array();
			if ( is_array( $items ) ) {
				foreach ( array_slice( $items, 0, 30 ) as $item ) {
					$item_list[] = array(
						'title'  => $item->title,
						'type'   => $item->type,
						'url'    => $item->url,
						'parent' => (int) $item->menu_item_parent,
					);
				}
			}

			$result[] = array(
				'name'     => $menu->name,
				'location' => $location ?: 'unassigned',
				'count'    => count( $items ?: array() ),
				'items'    => $item_list,
			);
		}

		return $result;
	}

	/**
	 * Collect content statistics.
	 *
	 * @return array
	 */
	public function collect_content_stats() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$stats      = array();

		foreach ( $post_types as $pt ) {
			$counts = wp_count_posts( $pt->name );
			if ( ! $counts ) {
				continue;
			}
			$stats[ $pt->name ] = array(
				'label'     => $pt->label,
				'published' => (int) ( $counts->publish ?? 0 ),
				'draft'     => (int) ( $counts->draft ?? 0 ),
				'pending'   => (int) ( $counts->pending ?? 0 ),
				'trash'     => (int) ( $counts->trash ?? 0 ),
			);
		}

		return $stats;
	}

	/**
	 * Collect registered shortcodes.
	 *
	 * @return array
	 */
	public function collect_shortcodes() {
		global $shortcode_tags;
		if ( empty( $shortcode_tags ) ) {
			return array();
		}

		$result = array();
		foreach ( $shortcode_tags as $tag => $callback ) {
			// Identify the source (plugin/theme).
			$source = 'unknown';
			if ( is_string( $callback ) ) {
				$source = $callback;
			} elseif ( is_array( $callback ) && isset( $callback[0] ) ) {
				$source = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			}
			$result[] = array( 'tag' => $tag, 'source' => $source );
		}

		return $result;
	}

	/**
	 * Collect registered image sizes.
	 *
	 * @return array
	 */
	public function collect_image_sizes() {
		$sizes  = wp_get_registered_image_subsizes();
		$result = array();

		foreach ( $sizes as $name => $data ) {
			$result[] = array(
				'name'   => $name,
				'width'  => $data['width'],
				'height' => $data['height'],
				'crop'   => $data['crop'],
			);
		}

		return $result;
	}

	/**
	 * Collect template overrides (WooCommerce and other plugins).
	 *
	 * @return array
	 */
	public function collect_template_overrides() {
		$overrides = array();
		$theme_dir = get_stylesheet_directory();

		// WooCommerce template overrides.
		$woo_dir = $theme_dir . '/woocommerce';
		if ( is_dir( $woo_dir ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $woo_dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$overrides[] = 'woocommerce/' . ltrim( substr( $file->getPathname(), strlen( $woo_dir ) ), '/' );
				}
			}
		}

		// EDD template overrides.
		$edd_dir = $theme_dir . '/edd_templates';
		if ( is_dir( $edd_dir ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $edd_dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$overrides[] = 'edd_templates/' . ltrim( substr( $file->getPathname(), strlen( $edd_dir ) ), '/' );
				}
			}
		}

		return $overrides;
	}

	/**
	 * Collect non-core hooks on key actions.
	 *
	 * @return array
	 */
	public function collect_custom_hooks() {
		global $wp_filter;

		$actions = array( 'wp_head', 'wp_footer', 'init', 'wp_enqueue_scripts' );
		$result  = array();

		foreach ( $actions as $action ) {
			if ( empty( $wp_filter[ $action ] ) ) {
				continue;
			}

			$hooks = array();
			foreach ( $wp_filter[ $action ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$name = $this->callback_name( $cb['function'] );
					// Skip WordPress core functions.
					if ( 0 === strpos( $name, 'wp_' ) || 0 === strpos( $name, '_wp_' ) || 0 === strpos( $name, 'WP_' ) ) {
						continue;
					}
					$hooks[] = array( 'callback' => $name, 'priority' => $priority );
				}
			}

			if ( ! empty( $hooks ) ) {
				$result[ $action ] = $hooks;
			}
		}

		return $result;
	}

	/**
	 * Get a readable name for a callback.
	 *
	 * @param mixed $callback The callback.
	 * @return string
	 */
	private function callback_name( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) ) {
			$class  = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$method = $callback[1] ?? '';
			return $class . '::' . $method;
		}
		if ( $callback instanceof Closure ) {
			return '{closure}';
		}
		return '{unknown}';
	}
}

