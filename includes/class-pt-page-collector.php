<?php
/**
 * Page-specific collector.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Page_Collector {

	/**
	 * Collect page data from a URL.
	 *
	 * @param string $url Target URL.
	 * @param array  $args Optional context.
	 * @return array
	 */
	public function collect( $url, $args = array() ) {
		$resolved = PT_Support::resolve_url_to_context( $url );
		$post_id  = ! empty( $args['post_id'] ) ? absint( $args['post_id'] ) : absint( $resolved['post_id'] );
		$page     = array(
			'url'               => esc_url_raw( $url ),
			'post_id'           => $post_id,
			'post_type'         => '',
			'slug'              => '',
			'parent_id'         => 0,
			'object_type'       => $resolved['object_type'],
			'title'             => '',
			'status'            => '',
			'template'          => '',
			'edit_link'         => '',
			'content_excerpt'   => '',
			'block_inventory'   => array(),
			'custom_meta'       => array(),
			'woo_data'          => array(),
			'body_classes'      => array(),
			'builder_signals'   => array(),
			'is_front_page'     => 'front_page' === $resolved['object_type'],
			'front_page_type'   => '',
			'is_posts_page'     => false,
			'is_archive'        => false,
			'is_singular'       => false,
			'is_woocommerce'    => false,
			'query_context'     => array(
				'is_front_page'        => 'front_page' === $resolved['object_type'],
				'is_home'              => false,
				'is_archive'           => false,
				'is_post_type_archive' => false,
				'is_tax'               => false,
				'is_singular'          => false,
				'is_single'            => false,
				'is_page'              => false,
				'is_attachment'        => false,
				'is_search'            => false,
				'is_404'               => false,
			),
			'evidence'          => array(),
		);

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				$post_type_object                       = get_post_type_object( $post->post_type );
				$page['object_type']                    = $post->post_type;
				$page['post_type']                      = $post->post_type;
				$page['slug']                           = $post->post_name;
				$page['parent_id']                      = (int) $post->post_parent;
				$page['title']                          = get_the_title( $post );
				$page['status']                         = $post->post_status;
				$page['template']                       = get_page_template_slug( $post_id ) ?: 'default';
				$page['edit_link']                      = get_edit_post_link( $post_id, '' );
				$page['is_front_page']                  = (int) get_option( 'page_on_front' ) === $post_id;
				$page['is_posts_page']                  = (int) get_option( 'page_for_posts' ) === $post_id;
				$page['is_archive']                     = false;
				$page['is_singular']                    = true;
				$page['is_woocommerce']                 = in_array( $post->post_type, array( 'product', 'shop_order', 'shop_coupon' ), true );
				$page['body_classes']                   = $this->infer_body_classes( $post, $page );
				$page['builder_signals']                = $this->detect_builder_signals( $post, $page );
				$page['content_excerpt']                = $this->extract_content_excerpt( $post, $args );
				$page['block_inventory']                = $this->extract_block_inventory( $post );
				$page['custom_meta']                    = $this->collect_custom_meta( $post );

				if ( $page['is_woocommerce'] && 'product' === $post->post_type ) {
					$page['woo_data'] = $this->collect_woo_product_data( $post );
				}

				$page['query_context']['is_front_page'] = $page['is_front_page'];
				$page['query_context']['is_home']       = $page['is_posts_page'];
				$page['query_context']['is_archive']    = false;
				$page['query_context']['is_singular']   = true;
				$page['query_context']['is_single']     = in_array( $post->post_type, array( 'post', 'product' ), true );
				$page['query_context']['is_page']       = 'page' === $post->post_type;
				$page['query_context']['is_attachment'] = 'attachment' === $post->post_type;
				$page['evidence'][]                     = array(
					'type'  => 'post',
					'label' => $post->post_type,
					'value' => $post_id,
				);
				$page['evidence'][]                     = array(
					'type'  => 'slug',
					'label' => __( 'Post slug', 'tracewp' ),
					'value' => $post->post_name,
				);

				if ( ! empty( $page['builder_signals'] ) ) {
					foreach ( $page['builder_signals'] as $signal ) {
						$page['evidence'][] = $signal;
					}
				}
			}
		}

		if ( empty( $page['body_classes'] ) ) {
			$page['body_classes'] = $this->infer_non_singular_body_classes( $page, $resolved );
		}

		// Detect "your latest posts" homepage (no static front page assigned).
		if ( $page['is_front_page'] && empty( $post_id ) ) {
			$show_on_front = get_option( 'show_on_front' );
			if ( 'posts' === $show_on_front ) {
				$page['front_page_type']      = 'latest_posts';
				$page['query_context']['is_home'] = true;
				$page['evidence'][]           = array(
					'type'  => 'front_page_config',
					'label' => __( 'Homepage displays latest posts (not a static page)', 'tracewp' ),
					'value' => 'show_on_front=posts',
				);
			} else {
				$page['front_page_type'] = 'static_page';
			}
		}

		return $page;
	}

	/**
	 * Infer body classes for a mapped singular object.
	 *
	 * @param WP_Post $post Post object.
	 * @param array   $page Page payload.
	 * @return array
	 */
	private function infer_body_classes( $post, $page ) {
		$classes = array(
			'singular',
			'single-' . sanitize_html_class( $post->post_type ),
			'postid-' . $post->ID,
		);

		if ( 'page' === $post->post_type ) {
			$classes[] = 'page';
			$classes[] = 'page-id-' . $post->ID;
			$classes[] = 'page-slug-' . sanitize_html_class( $post->post_name );
		} elseif ( 'post' === $post->post_type ) {
			$classes[] = 'single';
			$classes[] = 'single-post';
		} elseif ( 'product' === $post->post_type ) {
			$classes[] = 'single';
			$classes[] = 'single-product';
			$classes[] = 'woocommerce';
			$classes[] = 'woocommerce-page';
		} else {
			$classes[] = 'single';
		}

		if ( $page['is_front_page'] ) {
			$classes[] = 'home';
			$classes[] = 'front-page';
		}

		if ( $page['is_posts_page'] ) {
			$classes[] = 'blog';
			$classes[] = 'home';
		}

		if ( 'default' !== $page['template'] ) {
			$template_class = sanitize_html_class( str_replace( array( '/', '.', '\\' ), '-', $page['template'] ) );
			$classes[]      = 'page-template';
			$classes[]      = 'page-template-' . $template_class;
		}

		return array_values( array_unique( array_filter( $classes ) ) );
	}

	/**
	 * Infer basic body classes for non-singular requests.
	 *
	 * @param array $page Page payload.
	 * @param array $resolved Resolved URL mapping.
	 * @return array
	 */
	private function infer_non_singular_body_classes( $page, $resolved ) {
		$classes = array();

		if ( $page['is_front_page'] ) {
			$classes[] = 'home';
			$classes[] = 'front-page';
		}

		if ( ! empty( $resolved['archive_type'] ) ) {
			$classes[] = 'archive';
			$classes[] = sanitize_html_class( $resolved['archive_type'] );
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Detect page-level builder signals.
	 *
	 * @param WP_Post $post Post object.
	 * @param array   $page Page payload.
	 * @return array
	 */
	private function detect_builder_signals( $post, $page ) {
		$signals      = array();
		$content      = (string) $post->post_content;
		$meta_keys    = get_post_custom_keys( $post->ID );
		$meta_keys    = is_array( $meta_keys ) ? $meta_keys : array();
		$signal_rules = array(
			'elementor' => array(
				'confidence' => 0.98,
				'label'      => __( 'Elementor page data detected', 'tracewp' ),
				'meta_keys'  => array( '_elementor_data', '_elementor_edit_mode', '_elementor_template_type' ),
				'content'    => array( 'elementor' ),
			),
			'bricks'    => array(
				'confidence' => 0.98,
				'label'      => __( 'Bricks page data detected', 'tracewp' ),
				'meta_keys'  => array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' ),
				'content'    => array( 'bricks-' ),
			),
			'divi'      => array(
				'confidence' => 0.95,
				'label'      => __( 'Divi builder markers detected', 'tracewp' ),
				'meta_keys'  => array( '_et_pb_use_builder', '_et_pb_page_layout', '_et_pb_old_content' ),
				'content'    => array( 'et_pb_section', 'et_pb_row' ),
			),
			'beaver'    => array(
				'confidence' => 0.95,
				'label'      => __( 'Beaver Builder data detected', 'tracewp' ),
				'meta_keys'  => array( '_fl_builder_data', '_fl_builder_enabled' ),
				'content'    => array( 'fl-builder-content' ),
			),
			'blocks'    => array(
				'confidence' => 0.78,
				'label'      => __( 'Block editor markup detected', 'tracewp' ),
				'meta_keys'  => array(),
				'content'    => array( '<!-- wp:' ),
			),
			'woo'       => array(
				'confidence' => 0.9,
				'label'      => __( 'WooCommerce product object detected', 'tracewp' ),
				'meta_keys'  => array(),
				'content'    => array(),
			),
		);

		foreach ( $signal_rules as $slug => $rule ) {
			$matched = false;

			if ( ! empty( $rule['meta_keys'] ) && array_intersect( $rule['meta_keys'], $meta_keys ) ) {
				$matched = true;
			}

			if ( ! $matched && ! empty( $rule['content'] ) ) {
				foreach ( $rule['content'] as $needle ) {
					if ( false !== stripos( $content, $needle ) ) {
						$matched = true;
						break;
					}
				}
			}

			if ( 'woo' === $slug && $page['is_woocommerce'] ) {
				$matched = true;
			}

			if ( ! $matched ) {
				continue;
			}

			$signals[] = array(
				'type'       => 'builder_signal',
				'slug'       => $slug,
				'label'      => $rule['label'],
				'value'      => $page['post_type'] ?: $page['object_type'],
				'confidence' => $rule['confidence'],
			);
		}

		return $signals;
	}

	/**
	 * Extract a truncated content excerpt suitable for AI context.
	 *
	 * @param WP_Post $post Post object.
	 * @param array   $args Context args.
	 * @return string
	 */
	private function extract_content_excerpt( $post, $args ) {
		$content = (string) $post->post_content;
		$limit   = 2000;

		// Strip blocks/shortcodes but keep structure.
		$clean = wp_strip_all_tags( strip_shortcodes( $content ) );
		$clean = preg_replace( '/\s+/', ' ', trim( $clean ) );

		if ( strlen( $clean ) > $limit ) {
			$clean = substr( $clean, 0, $limit ) . '... [truncated]';
		}

		return $clean;
	}

	/**
	 * Extract a list of Gutenberg blocks used in the content.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function extract_block_inventory( $post ) {
		$content = (string) $post->post_content;

		if ( ! has_blocks( $content ) ) {
			return array();
		}

		$blocks    = parse_blocks( $content );
		$inventory = array();

		$this->walk_blocks( $blocks, $inventory );

		// Dedupe and count.
		$summary = array();
		foreach ( $inventory as $block_name ) {
			if ( ! isset( $summary[ $block_name ] ) ) {
				$summary[ $block_name ] = 0;
			}
			$summary[ $block_name ]++;
		}

		arsort( $summary );

		$result = array();
		foreach ( $summary as $name => $count ) {
			$result[] = array(
				'block' => $name,
				'count' => $count,
			);
		}

		return $result;
	}

	/**
	 * Recursively walk block tree and collect block names.
	 *
	 * @param array $blocks    Parsed blocks.
	 * @param array &$inventory Collected block names.
	 * @return void
	 */
	private function walk_blocks( $blocks, &$inventory ) {
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$inventory[] = $block['blockName'];
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk_blocks( $block['innerBlocks'], $inventory );
			}
		}
	}

	/**
	 * Collect public custom meta for a post.
	 *
	 * Excludes internal/private meta keys (prefixed with _) and known
	 * builder data blobs. Returns key names and truncated values.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function collect_custom_meta( $post ) {
		$all_meta = get_post_meta( $post->ID );
		$result   = array();
		$count    = 0;

		// Keys to always skip (builder data blobs, internal).
		$skip_keys = array(
			'_elementor_data',
			'_elementor_controls_usage',
			'_bricks_page_content_2',
			'_fl_builder_data',
			'_fl_builder_data_settings',
			'_et_pb_old_content',
		);

		foreach ( $all_meta as $key => $values ) {
			// Skip known builder data blobs.
			if ( in_array( $key, $skip_keys, true ) ) {
				continue;
			}

			// Skip private/internal meta (underscore-prefixed) unless it's
			// a known public-facing pattern. Public custom meta keys from
			// ACF visible fields don't start with underscore — the underscore
			// keys are ACF's internal field references.
			if ( 0 === strpos( $key, '_' ) ) {
				continue;
			}

			if ( $count >= 30 ) {
				$result[] = array(
					'key'   => '...',
					'value' => sprintf( __( '%d more meta keys not shown', 'tracewp' ), count( $all_meta ) - $count ),
				);
				break;
			}

			$value = $values[0] ?? '';
			if ( is_serialized( $value ) ) {
				$value = '[serialized data]';
			} elseif ( strlen( (string) $value ) > 200 ) {
				$value = substr( (string) $value, 0, 200 ) . '...';
			}

			$result[] = array(
				'key'   => sanitize_text_field( $key ),
				'value' => sanitize_text_field( (string) $value ),
			);

			$count++;
		}

		return $result;
	}

	/**
	 * Collect WooCommerce product-specific data.
	 *
	 * @param WP_Post $post Product post object.
	 * @return array
	 */
	private function collect_woo_product_data( $post ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return array();
		}

		$data = array(
			'product_type'    => $product->get_type(),
			'sku'             => $product->get_sku(),
			'price'           => $product->get_price(),
			'regular_price'   => $product->get_regular_price(),
			'sale_price'      => $product->get_sale_price(),
			'stock_status'    => $product->get_stock_status(),
			'stock_quantity'  => $product->get_stock_quantity(),
			'is_virtual'      => $product->is_virtual(),
			'is_downloadable' => $product->is_downloadable(),
			'weight'          => $product->get_weight(),
			'categories'      => array(),
			'tags'            => array(),
			'attributes'      => array(),
		);

		$categories = get_the_terms( $post->ID, 'product_cat' );
		if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
			$data['categories'] = wp_list_pluck( $categories, 'name' );
		}

		$tags = get_the_terms( $post->ID, 'product_tag' );
		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			$data['tags'] = wp_list_pluck( $tags, 'name' );
		}

		foreach ( $product->get_attributes() as $attr ) {
			$data['attributes'][] = array(
				'name'    => $attr->get_name(),
				'options' => $attr->get_options(),
				'visible' => $attr->get_visible(),
			);
		}

		return $data;
	}
}
