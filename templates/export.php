<?php
/**
 * Main plugin page template.
 *
 * Layout: AI Investigator → Front-End Inspector → Export (controls + output).
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tracewp_settings = PT_Settings::instance()->get();
?>
<div class="wrap pt-wrap">
	<h1><?php esc_html_e( 'TraceWP', 'tracewp' ); ?></h1>

	<?php include PT_PLUGIN_DIR . 'templates/partials-investigate.php'; ?>

	<section class="pt-card pt-inspect-card">
		<div class="pt-inspect-card-inner">
			<div class="pt-inspect-card-text">
				<h3><?php esc_html_e( 'Front-End Inspector', 'tracewp' ); ?></h3>
				<p><?php esc_html_e( 'Open your live site with inspect mode enabled. Click any element to capture its selector, classes, and page context — then paste it into any AI tool or use it with the AI Investigator above.', 'tracewp' ); ?></p>
			</div>
			<a class="button button-primary" href="<?php echo esc_url( PT_Inspector::get_inspect_url( home_url( '/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Inspector', 'tracewp' ); ?> &rarr;</a>
		</div>
	</section>

	<section class="pt-card pt-export-section">
		<h2><?php esc_html_e( 'Context Export', 'tracewp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Generate a snapshot of your site structure, theme, plugins, and page data. Copy the output and paste it into ChatGPT, Claude, or any external AI tool to give it full context about your site.', 'tracewp' ); ?></p>

		<form class="pt-export-form" data-endpoint="context/site" id="pt-main-form">
			<?php wp_nonce_field( 'pt_admin_form', 'pt_admin_nonce' ); ?>

			<div class="pt-export-controls">
				<label>
					<span><?php esc_html_e( 'Scope', 'tracewp' ); ?></span>
					<select class="pt-content-selector" id="pt-scope-selector">
						<option value="" data-url="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Entire site', 'tracewp' ); ?></option>
						<?php foreach ( $tpl['content_options'] as $option ) : ?>
							<option value="<?php echo esc_attr( $option['id'] ); ?>" data-url="<?php echo esc_url( $option['url'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label id="pt-url-row" style="display:none;">
					<span><?php esc_html_e( 'URL', 'tracewp' ); ?></span>
					<input type="url" name="url" class="regular-text code" value="<?php echo esc_url( home_url( '/' ) ); ?>">
				</label>
				<input type="hidden" name="post_id" value="">

				<div class="pt-inline-controls">
					<label class="pt-checkbox">
						<input type="checkbox" name="safe_export" value="1" <?php checked( ! empty( $tracewp_settings['safe_export_default'] ) ); ?>>
						<span><?php esc_html_e( 'Redact sensitive data', 'tracewp' ); ?></span>
					</label>
				</div>

				<label>
					<span><?php esc_html_e( 'Notes', 'tracewp' ); ?></span>
					<textarea name="notes" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Describe the issue or what you need help with (optional)', 'tracewp' ); ?>"></textarea>
				</label>

				<p><button type="submit" class="button button-primary" id="pt-export-btn"><?php esc_html_e( 'Generate Export', 'tracewp' ); ?></button></p>
			</div>
		</form>

		<?php include PT_PLUGIN_DIR . 'templates/partials-output.php'; ?>
	</section>
</div>
