<?php
/**
 * Theme/plugin detectors.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Detector {

	/**
	 * Build detection array with evidence.
	 *
	 * Only includes active detections to keep the payload compact.
	 *
	 * @return array
	 */
	public function detect() {
		$theme        = wp_get_theme();
		$parent_theme = $theme->parent();

		$is_divi = 'Divi' === $theme->get( 'Name' ) || 'Divi' === $theme->parent_theme;

		$theme_detection = array(
			'type'            => wp_is_block_theme() ? 'block' : 'classic',
			'is_child_theme'  => (bool) $parent_theme,
			'parent_theme'    => $parent_theme ? $parent_theme->get( 'Name' ) : '',
		);

		// Only include builder detection if active.
		if ( $is_divi ) {
			$theme_detection['builder'] = 'divi';
		}

		// Detect key plugins — only return active ones.
		$plugin_checks = array(
			'elementor'      => array( 'elementor/elementor.php', 'Elementor' ),
			'bricks'         => array( 'bricks/bricks.php', 'Bricks' ),
			'divi_builder'   => array( 'divi-builder/divi-builder.php', 'Divi Builder' ),
			'beaver_builder' => array( 'bb-plugin/fl-builder.php', 'Beaver Builder' ),
			'woocommerce'    => array( 'woocommerce/woocommerce.php', 'WooCommerce' ),
			'acf'            => array( 'advanced-custom-fields/acf.php', 'ACF' ),
			'wpcode'         => array( 'insert-headers-and-footers/ihaf.php', 'WPCode' ),
			'code_snippets'  => array( 'code-snippets/code-snippets.php', 'Code Snippets' ),
		);

		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		$active_detections = array();
		foreach ( $plugin_checks as $slug => $info ) {
			if ( is_plugin_active( $info[0] ) ) {
				$active_detections[ $slug ] = array(
					'active'   => true,
					'label'    => $info[1],
					'evidence' => array(
						array(
							'type'  => 'plugin_basename',
							'label' => $info[1],
							'value' => $info[0],
						),
					),
				);
			}
		}

		return array(
			'theme'   => $theme_detection,
			'plugins' => $active_detections,
		);
	}
}
