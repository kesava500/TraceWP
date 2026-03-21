<?php
/**
 * Canonical payload builder.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Payload_Builder {

	/**
	 * Site collector.
	 *
	 * @var PT_Site_Collector
	 */
	private $site_collector;

	/**
	 * Environment collector.
	 *
	 * @var PT_Environment_Collector
	 */
	private $environment;

	/**
	 * Page collector.
	 *
	 * @var PT_Page_Collector
	 */
	private $page_collector;

	/**
	 * Detector.
	 *
	 * @var PT_Detector
	 */
	private $detector;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->site_collector = new PT_Site_Collector();
		$this->page_collector = new PT_Page_Collector();
		$this->environment    = new PT_Environment_Collector();
		$this->detector       = new PT_Detector();
	}

	/**
	 * Build payload for a request.
	 *
	 * @param array $args Inputs.
	 * @return array
	 */
	public function build( $args = array() ) {
		$defaults = array(
			'context_scope'   => 'site',
			'url'             => home_url( '/' ),
			'safe_export'     => true,
			'notes'           => '',
			'element'         => array(),
		);

		$args       = wp_parse_args( $args, $defaults );
		$site       = $this->site_collector->collect();
		$page       = $this->page_collector->collect( $args['url'], $args );
		$detections = $this->detector->detect();
		$env        = $this->environment->collect();
		$element    = 'element' === $args['context_scope']
			? $this->prepare_element( $args['element'] )
			: $this->empty_element();

		// Auto-detect the context mode from actual site/page data.
		$auto_mode = $this->detect_mode( $page, $element, $detections, $args['context_scope'] );

		$payload    = array(
			'meta'              => array(
				'plugin'         => 'TraceWP',
				'version'        => PT_VERSION,
				'generated_at'   => gmdate( 'c' ),
				'scope'          => sanitize_key( $args['context_scope'] ),
				'safe_export'    => (bool) $args['safe_export'],
				'generator'      => 'canonical-payload-v1.0',
				'text_domain'    => 'tracewp',
				'admin_only'     => true,
			),
			'site'              => $site,
			'environment'       => $env,
			'theme_mods'        => $this->site_collector->collect_theme_mods(),
			'content_stats'     => $this->site_collector->collect_content_stats(),
			'widgets'           => $this->site_collector->collect_widgets(),
			'menu_structure'    => $this->site_collector->collect_menu_items(),
			'shortcodes'        => $this->site_collector->collect_shortcodes(),
			'image_sizes'       => $this->site_collector->collect_image_sizes(),
			'template_overrides' => $this->site_collector->collect_template_overrides(),
			'custom_hooks'      => $this->site_collector->collect_custom_hooks(),
			'page'              => $page,
			'element'           => $element,
			'detections'        => $detections,
			'editing_hints'     => $this->build_editing_hints( $site, $page, $element, $detections ),
			'cautions'      => $this->build_cautions( $auto_mode, $detections, $site ),
			'task'          => $this->build_task_context( $auto_mode, $args ),
			'export'        => array(
				'notes'         => sanitize_textarea_field( $args['notes'] ),
				'copy_variants' => array(),
			),
		);

		if ( $args['safe_export'] ) {
			$payload = PT_Support::redact_deep( $payload );
		}

		return $payload;
	}

	/**
	 * Auto-detect the appropriate context mode from the actual data.
	 *
	 * Priority: woo (if WooCommerce page) > element (if element scope) > general.
	 *
	 * @param array  $page       Page data.
	 * @param array  $element    Element data.
	 * @param array  $detections Detection data.
	 * @param string $scope      Context scope.
	 * @return string
	 */
	private function detect_mode( $page, $element, $detections, $scope ) {
		// WooCommerce page with WooCommerce active.
		if ( ! empty( $page['is_woocommerce'] ) && ! empty( $detections['plugins']['woocommerce'] ) ) {
			return 'woo';
		}

		// Element scope with a captured element.
		if ( 'element' === $scope && ! empty( $element['selector'] ) ) {
			return 'element';
		}

		return 'general';
	}

	/**
	 * Return an empty element structure for non-element scopes.
	 *
	 * @return array
	 */
	private function empty_element() {
		return array(
			'selector'        => '',
			'tag'             => '',
			'id'              => '',
			'classes'         => array(),
			'text_preview'    => '',
			'html_snippet'    => '',
			'attributes'      => array(),
			'path'            => array(),
			'parent_chain'    => array(),
			'selector_hint'   => '',
			'element_type'    => '',
			'likely_control'  => '',
			'builder_signals' => array(),
			'confidence'      => 0,
			'evidence'        => array(),
		);
	}

	/**
	 * Prepare element fragment.
	 *
	 * @param array $element Raw element data.
	 * @return array
	 */
	private function prepare_element( $element ) {
		$element = is_array( $element ) ? $element : array();
		$parent_chain = array();

		if ( ! empty( $element['parent_chain'] ) && is_array( $element['parent_chain'] ) ) {
			foreach ( array_slice( $element['parent_chain'], 0, 4 ) as $ancestor ) {
				if ( ! is_array( $ancestor ) ) {
					continue;
				}

				$parent_chain[] = array(
					'tag'     => sanitize_key( $ancestor['tag'] ?? '' ),
					'id'      => sanitize_html_class( $ancestor['id'] ?? '' ),
					'classes' => PT_Support::class_string_to_array( is_array( $ancestor['classes'] ?? null ) ? implode( ' ', $ancestor['classes'] ) : ( $ancestor['classes'] ?? '' ) ),
				);
			}
		}

		$prepared = array(
			'selector'       => sanitize_text_field( $element['selector'] ?? '' ),
			'tag'            => sanitize_key( $element['tag'] ?? '' ),
			'id'             => sanitize_html_class( $element['id'] ?? '' ),
			'classes'        => PT_Support::class_string_to_array( is_array( $element['classes'] ?? null ) ? implode( ' ', $element['classes'] ) : ( $element['classes'] ?? '' ) ),
			'text_preview'   => sanitize_textarea_field( $element['text_preview'] ?? '' ),
			'html_snippet'   => wp_kses_post( substr( (string) ( $element['html_snippet'] ?? '' ), 0, 1200 ) ),
			'attributes'     => array(),
			'path'           => array_map( 'sanitize_text_field', is_array( $element['path'] ?? null ) ? $element['path'] : array() ),
			'parent_chain'   => $parent_chain,
			'selector_hint'  => '',
			'element_type'   => 'generic_content',
			'likely_control' => '',
			'builder_signals'=> array(),
			'confidence'     => empty( $element['selector'] ) ? 0 : 0.8,
			'evidence'       => array(),
		);

		if ( ! empty( $element['attributes'] ) && is_array( $element['attributes'] ) ) {
			foreach ( $element['attributes'] as $key => $value ) {
				$prepared['attributes'][ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
			}
		}

		if ( empty( $prepared['selector'] ) ) {
			$prepared['selector'] = PT_Support::generate_selector_hint( $prepared );
		}

		$prepared['selector_hint'] = PT_Support::generate_selector_hint( $prepared );
		$heuristics                = $this->infer_element_heuristics( $prepared );
		$prepared['element_type']  = $heuristics['element_type'];
		$prepared['likely_control']= $heuristics['likely_control'];
		$prepared['builder_signals'] = $heuristics['builder_signals'];

		if ( ! empty( $prepared['selector'] ) ) {
			$prepared['evidence'][] = array(
				'type'  => 'selector',
				'label' => 'Selected element',
				'value' => $prepared['selector'],
			);
		}

		if ( ! empty( $prepared['selector_hint'] ) ) {
			$prepared['evidence'][] = array(
				'type'  => 'selector_hint',
				'label' => __( 'Selector hint', 'tracewp' ),
				'value' => $prepared['selector_hint'],
			);
		}

		if ( ! empty( $prepared['element_type'] ) ) {
			$prepared['evidence'][] = array(
				'type'  => 'element_type',
				'label' => __( 'Element type heuristic', 'tracewp' ),
				'value' => $prepared['element_type'],
			);
		}

		return $prepared;
	}

	/**
	 * Build task context.
	 *
	 * @param string $mode Auto-detected mode.
	 * @param array  $args Inputs.
	 * @return array
	 */
	private function build_task_context( $mode, $args ) {
		$labels = array(
			'general' => __( 'General WordPress assistance', 'tracewp' ),
			'element' => __( 'Front-end element analysis', 'tracewp' ),
			'woo'     => __( 'WooCommerce context', 'tracewp' ),
		);

		return array(
			'mode'        => $mode,
			'label'       => $labels[ $mode ] ?? $labels['general'],
			'notes'       => sanitize_textarea_field( $args['notes'] ),
			'constraints' => array(
				'no_provider_integration',
				'no_file_editing_from_plugin',
				'heuristics_may_be_uncertain',
				'admin_only_visibility',
			),
		);
	}

	/**
	 * Build editing hints.
	 *
	 * @param array $page Page data.
	 * @param array $element Element data.
	 * @param array $detections Detection data.
	 * @return array
	 */
	private function build_editing_hints( $site, $page, $element, $detections ) {
		$hints = array();

		if ( ! empty( $page['post_id'] ) ) {
			$hints[] = array(
				'path'       => 'post_editor',
				'label'      => __( 'Edit the mapped content item in the WordPress editor.', 'tracewp' ),
				'target'     => $page['edit_link'],
				'confidence' => 1,
				'evidence'   => $page['evidence'],
			);
		}

		if ( ! empty( $site['theme']['is_child_theme'] ) ) {
			$hints[] = array(
				'path'       => 'child_theme',
				'label'      => __( 'Prefer child theme edits for theme-level changes because a child theme is active.', 'tracewp' ),
				'target'     => $site['theme']['stylesheet'],
				'confidence' => 1,
				'evidence'   => array(
					array(
						'type'  => 'theme_state',
						'label' => __( 'Child theme active', 'tracewp' ),
						'value' => $site['theme']['stylesheet'],
					),
				),
			);
		} else {
			$hints[] = array(
				'path'       => 'parent_theme_caution',
				'label'      => __( 'No child theme detected. Be cautious with parent theme template or function edits.', 'tracewp' ),
				'target'     => $site['theme']['stylesheet'],
				'confidence' => 0.96,
				'evidence'   => array(
					array(
						'type'  => 'theme_state',
						'label' => __( 'Parent theme is active directly', 'tracewp' ),
						'value' => $site['theme']['stylesheet'],
					),
				),
			);
		}

		if ( ! empty( $page['template'] ) && 'default' !== $page['template'] ) {
			$hints[] = array(
				'path'       => 'assigned_template',
				'label'      => __( 'This content item has a custom assigned template.', 'tracewp' ),
				'target'     => $page['template'],
				'confidence' => 0.95,
				'evidence'   => array(
					array(
						'type'  => 'template',
						'label' => __( 'Assigned template', 'tracewp' ),
						'value' => $page['template'],
					),
				),
			);
		}

		if ( ! empty( $detections['plugins']['wpcode']['active'] ) || ! empty( $detections['plugins']['code_snippets']['active'] ) ) {
			$snippets_target = ! empty( $detections['plugins']['wpcode']['active'] ) ? admin_url( 'admin.php?page=wpcode' ) : admin_url( 'snippets.php' );
			$hints[] = array(
				'path'       => 'snippets_plugin',
				'label'      => __( 'Prefer the active snippets plugin for hook or PHP changes before editing theme files.', 'tracewp' ),
				'target'     => $snippets_target,
				'confidence' => 0.95,
				'evidence'   => array_values(
					array_filter(
						array(
							! empty( $detections['plugins']['wpcode']['active'] ) ? $detections['plugins']['wpcode']['evidence'][0] : null,
							! empty( $detections['plugins']['code_snippets']['active'] ) ? $detections['plugins']['code_snippets']['evidence'][0] : null,
						)
					)
				),
			);
		}

		$builder_plugins = array( 'elementor', 'bricks', 'divi_builder', 'beaver_builder' );
		$matched_builder = $this->find_matching_builder_context( $page, $element, $detections, $builder_plugins );
		if ( $matched_builder ) {
			$hints[] = array(
				'path'       => 'builder_controlled:' . $matched_builder,
				'label'      => sprintf( __( 'This page or element looks builder-controlled. Check %s content/templates first.', 'tracewp' ), $detections['plugins'][ $matched_builder ]['label'] ),
				'target'     => ! empty( $page['edit_link'] ) ? $page['edit_link'] : admin_url(),
				'confidence' => 0.9,
				'evidence'   => $this->collect_builder_evidence( $page, $element, $detections, $matched_builder ),
			);
		}

		if ( 'block' === $detections['theme']['type'] ) {
			$hints[] = array(
				'path'       => 'site_editor',
				'label'      => __( 'This is a block theme. Template and global layout changes may live in the Site Editor.', 'tracewp' ),
				'target'     => admin_url( 'site-editor.php' ),
				'confidence' => 0.94,
				'evidence'   => $detections['theme']['evidence'],
			);
		}

		if ( 'navigation_link' === $element['element_type'] || false !== strpos( (string) $element['selector_hint'], 'nav' ) ) {
			$hints[] = array(
				'path'       => 'menus',
				'label'      => __( 'This looks like a navigation element. Check menus/navigation settings before template files.', 'tracewp' ),
				'target'     => admin_url( 'nav-menus.php' ),
				'confidence' => 0.93,
				'evidence'   => array_merge(
					$element['evidence'],
					array(
						array(
							'type'  => 'element_type',
							'label' => __( 'Navigation-like element', 'tracewp' ),
							'value' => $element['element_type'],
						),
					)
				),
			);
		}

		if ( ! empty( $detections['plugins']['woocommerce']['active'] ) && ! empty( $page['is_woocommerce'] ) ) {
			$hints[] = array(
				'path'       => 'woocommerce',
				'label'      => __( 'WooCommerce context detected. Product editor, Woo settings, or Woo templates may control this view.', 'tracewp' ),
				'target'     => ! empty( $page['edit_link'] ) ? $page['edit_link'] : admin_url( 'admin.php?page=wc-admin' ),
				'confidence' => 0.96,
				'evidence'   => array_merge(
					$detections['plugins']['woocommerce']['evidence'],
					$page['builder_signals']
				),
			);
		}

		if ( ! empty( $element['selector'] ) ) {
			$hints[] = array(
				'path'       => 'front_end_element',
				'label'      => __( 'Use the captured selector to trace this element in theme, builder, or widget output.', 'tracewp' ),
				'target'     => $element['selector'],
				'confidence' => $element['confidence'],
				'evidence'   => $element['evidence'],
			);
		}

		return $hints;
	}

	/**
	 * Build cautions.
	 *
	 * @param string $mode Auto-detected mode.
	 * @param array  $detections Detection data.
	 * @param array  $site Site data.
	 * @return array
	 */
	private function build_cautions( $mode, $detections, $site = array() ) {
		$cautions = array(
			array(
				'type'       => 'heuristic',
				'message'    => __( 'Detections are heuristic and may not reflect all rendering layers.', 'tracewp' ),
				'confidence' => 0.7,
			),
			array(
				'type'       => 'scope',
				'message'    => __( 'This plugin exports context only. It does not edit files or call AI providers.', 'tracewp' ),
				'confidence' => 1,
			),
		);

		if ( empty( $detections['theme']['is_child_theme'] ) ) {
			$cautions[] = array(
				'type'       => 'theme_edit_risk',
				'message'    => __( 'No child theme was detected. Theme-level code changes may be overwritten by parent theme updates.', 'tracewp' ),
				'confidence' => 0.94,
			);
		}

		// Flag pending plugin updates — especially useful in debug mode.
		$outdated = array();
		if ( ! empty( $site['active_plugins'] ) ) {
			foreach ( $site['active_plugins'] as $plugin ) {
				if ( ! empty( $plugin['update_available'] ) ) {
					$outdated[] = $plugin['name'];
				}
			}
		}

		if ( ! empty( $outdated ) ) {
			$cautions[] = array(
				'type'       => 'pending_updates',
				'message'    => sprintf(
					/* translators: %d: number of plugins, %s: comma-separated plugin names */
					__( '%1$d plugin(s) have updates available (%2$s). If troubleshooting an issue, updating these plugins may resolve bugs fixed in newer versions.', 'tracewp' ),
					count( $outdated ),
					implode( ', ', $outdated )
				),
				'confidence' => 0.9,
			);
		}

		// Flag pending theme update.
		if ( ! empty( $site['theme']['update_available'] ) ) {
			$cautions[] = array(
				'type'       => 'theme_update',
				'message'    => sprintf(
					/* translators: %1$s: theme name, %2$s: current version, %3$s: available version */
					__( 'Theme "%1$s" has an update available (%2$s → %3$s). Theme bugs or compatibility issues may be resolved by updating.', 'tracewp' ),
					$site['theme']['name'],
					$site['theme']['version'],
					$site['theme']['update_available']
				),
				'confidence' => 0.9,
			);
		}

		return $cautions;
	}

	/**
	 * Infer richer element heuristics.
	 *
	 * @param array $element Element fragment.
	 * @return array
	 */
	private function infer_element_heuristics( $element ) {
		$text          = strtolower( (string) $element['text_preview'] );
		$selector_blob = strtolower(
			implode(
				' ',
				array_filter(
					array(
						$element['selector'] ?? '',
						$element['tag'] ?? '',
						$element['id'] ?? '',
						implode( ' ', $element['classes'] ?? array() ),
						implode( ' ', array_keys( $element['attributes'] ?? array() ) ),
						implode(
							' ',
							array_map(
								static function( $ancestor ) {
									return implode( ' ', array_merge( array( $ancestor['tag'] ?? '', $ancestor['id'] ?? '' ), $ancestor['classes'] ?? array() ) );
								},
								$element['parent_chain'] ?? array()
							)
						),
					)
				)
			)
		);
		$attributes    = $element['attributes'] ?? array();
		$builder_signals = array();
		$element_type  = 'generic_content';
		$likely_control = '';

		if ( in_array( $element['tag'], array( 'a', 'nav' ), true ) || preg_match( '/\b(menu|nav|navigation)\b/', $selector_blob ) ) {
			$element_type   = 'navigation_link';
			$likely_control = 'menu';
		} elseif ( in_array( $element['tag'], array( 'button', 'summary' ), true ) || 'button' === ( $attributes['role'] ?? '' ) || preg_match( '/\b(btn|button|cta)\b/', $selector_blob ) ) {
			$element_type   = 'button';
			$likely_control = 'content_or_builder';
		} elseif ( preg_match( '/^h[1-6]$/', $element['tag'] ) ) {
			$element_type   = 'heading';
			$likely_control = 'content';
		} elseif ( in_array( $element['tag'], array( 'input', 'select', 'textarea', 'label' ), true ) ) {
			$element_type   = 'form_field';
			$likely_control = 'form_plugin_or_content';
		}

		if ( preg_match( '/\b(elementor|bricks|et_pb|fl-builder|wp-block)\b/', $selector_blob ) ) {
			$element_type      = 'builder_block';
			$likely_control    = 'builder';
			$builder_signals[] = array(
				'type'       => 'builder_signal',
				'label'      => __( 'Builder-style classes detected on element', 'tracewp' ),
				'value'      => trim( substr( $selector_blob, 0, 120 ) ),
				'confidence' => 0.88,
			);
		}

		if ( preg_match( '/\b(woocommerce|product|cart|checkout|add-to-cart|woocommerce-)/', $selector_blob ) || false !== strpos( $text, 'add to cart' ) ) {
			$element_type      = 'woo_ui';
			$likely_control    = 'woocommerce';
			$builder_signals[] = array(
				'type'       => 'builder_signal',
				'label'      => __( 'WooCommerce-style element markers detected', 'tracewp' ),
				'value'      => trim( substr( $selector_blob, 0, 120 ) ),
				'confidence' => 0.9,
			);
		}

		return array(
			'element_type'   => $element_type,
			'likely_control' => $likely_control,
			'builder_signals'=> $builder_signals,
		);
	}

	/**
	 * Match page/element context to an active builder.
	 *
	 * @param array $page Page data.
	 * @param array $element Element data.
	 * @param array $detections Detection data.
	 * @param array $builder_plugins Builder plugin slugs.
	 * @return string
	 */
	private function find_matching_builder_context( $page, $element, $detections, $builder_plugins ) {
		foreach ( $builder_plugins as $builder_slug ) {
			if ( empty( $detections['plugins'][ $builder_slug ]['active'] ) ) {
				continue;
			}

			$normalized = str_replace( '_builder', '', $builder_slug );

			foreach ( $page['builder_signals'] as $signal ) {
				if ( false !== strpos( (string) ( $signal['slug'] ?? '' ), $normalized ) ) {
					return $builder_slug;
				}
			}

			foreach ( $element['builder_signals'] as $signal ) {
				if ( false !== stripos( (string) ( $signal['value'] ?? '' ), $normalized ) ) {
					return $builder_slug;
				}
			}
		}

		return '';
	}

	/**
	 * Gather evidence for a builder hint.
	 *
	 * @param array  $page Page data.
	 * @param array  $element Element data.
	 * @param array  $detections Detection data.
	 * @param string $builder_slug Builder slug.
	 * @return array
	 */
	private function collect_builder_evidence( $page, $element, $detections, $builder_slug ) {
		$evidence   = $detections['plugins'][ $builder_slug ]['evidence'];
		$normalized = str_replace( '_builder', '', $builder_slug );

		foreach ( $page['builder_signals'] as $signal ) {
			if ( false !== strpos( (string) ( $signal['slug'] ?? '' ), $normalized ) ) {
				$evidence[] = $signal;
			}
		}

		foreach ( $element['builder_signals'] as $signal ) {
			$evidence[] = $signal;
		}

		return $evidence;
	}
}
