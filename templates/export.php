<?php
/**
 * Main plugin page template.
 *
 * Layout: Front-End Inspector → Context Export → AI Investigator.
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

	<section class="pt-card">
		<div class="pt-card-header">
			<div class="pt-card-header-left">
				<div class="pt-card-icon pt-card-icon--neutral">
					<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"/></svg>
				</div>
				<div>
					<h2 class="pt-card-title"><?php esc_html_e( 'Front-End Inspector', 'tracewp' ); ?></h2>
					<p class="pt-card-desc"><?php esc_html_e( 'Open your live site with inspect mode enabled. Click any element to capture its selector, classes, and page context — then paste it into any AI tool or use it with the AI Investigator below.', 'tracewp' ); ?></p>
				</div>
			</div>
			<a class="button button-primary" href="<?php echo esc_url( PT_Inspector::get_inspect_url( home_url( '/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Inspector', 'tracewp' ); ?> &rarr;</a>
		</div>
	</section>

	<section class="pt-card pt-export-section">
		<div class="pt-card-header">
			<div class="pt-card-header-left">
				<div class="pt-card-icon pt-card-icon--neutral">
					<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
				</div>
				<div>
					<h2 class="pt-card-title"><?php esc_html_e( 'Context Export', 'tracewp' ); ?></h2>
					<p class="pt-card-desc"><?php esc_html_e( 'Generate a snapshot of your site structure, theme, plugins, and page data. Copy the output and paste it into ChatGPT, Claude, or any external AI tool.', 'tracewp' ); ?></p>
				</div>
			</div>
		</div>

		<form class="pt-export-form" data-endpoint="context/site" id="pt-main-form">
			<?php wp_nonce_field( 'pt_admin_form', 'pt_admin_nonce' ); ?>

			<div class="pt-form-grid">
				<label>
					<span><?php esc_html_e( 'Scope', 'tracewp' ); ?></span>
					<select class="pt-content-selector" id="pt-scope-selector">
						<option value="" data-url="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Entire site', 'tracewp' ); ?></option>
						<?php foreach ( $tpl['content_options'] as $option ) : ?>
							<option value="<?php echo esc_attr( $option['id'] ); ?>" data-url="<?php echo esc_url( $option['url'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Notes (optional)', 'tracewp' ); ?></span>
					<textarea name="notes" rows="1" placeholder="<?php esc_attr_e( 'Describe the issue or what you need help with...', 'tracewp' ); ?>"></textarea>
				</label>
			</div>

			<label id="pt-url-row" style="display:none;">
				<span><?php esc_html_e( 'URL', 'tracewp' ); ?></span>
				<input type="url" name="url" class="regular-text code" value="<?php echo esc_url( home_url( '/' ) ); ?>">
			</label>
			<input type="hidden" name="post_id" value="">

			<label class="pt-checkbox">
				<input type="checkbox" name="safe_export" value="1" <?php checked( ! empty( $tracewp_settings['safe_export_default'] ) ); ?>>
				<span><?php esc_html_e( 'Redact sensitive data (API keys, passwords, emails)', 'tracewp' ); ?></span>
			</label>

			<p><button type="submit" class="button button-primary" id="pt-export-btn"><?php esc_html_e( 'Generate Export', 'tracewp' ); ?></button></p>
		</form>

		<?php include PT_PLUGIN_DIR . 'templates/partials-output.php'; ?>
	</section>

	<?php include PT_PLUGIN_DIR . 'templates/partials-investigate.php'; ?>

	<p class="pt-contact-note">
		<?php
		printf(
			/* translators: %s: email link */
			esc_html__( 'Have a feature request or found a bug? Get in touch at %s', 'tracewp' ),
			'<a href="mailto:hello@belletty.com">hello@belletty.com</a>'
		);
		?>
	</p>
</div>
