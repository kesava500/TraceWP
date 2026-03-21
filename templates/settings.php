<?php
/**
 * Settings template.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_key    = PT_Settings::instance()->has_api_key();
$masked_key = PT_Settings::instance()->get_masked_key();
?>
<div class="wrap pt-wrap">
	<h1><?php esc_html_e( 'Settings', 'tracewp' ); ?></h1>

	<form method="post" action="options.php" class="pt-card">
		<h2><?php esc_html_e( 'Export', 'tracewp' ); ?></h2>
		<?php settings_fields( 'tracewp_settings_group' ); ?>
		<label class="pt-checkbox">
			<input type="checkbox" name="<?php echo esc_attr( PT_Settings::OPTION_NAME ); ?>[safe_export_default]" value="1" <?php checked( ! empty( $tpl['settings']['safe_export_default'] ) ); ?>>
			<span><?php esc_html_e( 'Redact sensitive data by default', 'tracewp' ); ?></span>
		</label>
		<label class="pt-checkbox">
			<input type="checkbox" name="<?php echo esc_attr( PT_Settings::OPTION_NAME ); ?>[inspector_admin_bar]" value="1" <?php checked( ! empty( $tpl['settings']['inspector_admin_bar'] ) ); ?>>
			<span><?php esc_html_e( 'Admin bar inspector shortcut', 'tracewp' ); ?></span>
		</label>
		<?php submit_button(); ?>
	</form>

	<section class="pt-card" id="pt-ai-settings">
		<h2><?php esc_html_e( 'AI Investigator', 'tracewp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Connect an OpenRouter API key to enable the AI investigator. The AI can read your site files (read-only) and help diagnose issues. Your key is encrypted and stored locally — AI requests go directly from your browser to OpenRouter.', 'tracewp' ); ?></p>

		<?php wp_nonce_field( 'tracewp_settings_nonce', 'tracewp_settings_nonce' ); ?>

		<label>
			<span><?php esc_html_e( 'OpenRouter API Key', 'tracewp' ); ?></span>
			<div class="pt-key-row">
				<input type="password" id="pt-api-key-input" class="regular-text code"
					value="<?php echo esc_attr( $has_key ? $masked_key : '' ); ?>"
					placeholder="sk-or-v1-..."
					autocomplete="off">
				<button type="button" class="button" id="pt-save-key"><?php echo $has_key ? esc_html__( 'Update Key', 'tracewp' ) : esc_html__( 'Save Key', 'tracewp' ); ?></button>
				<?php if ( $has_key ) : ?>
					<button type="button" class="button" id="pt-remove-key"><?php esc_html_e( 'Remove', 'tracewp' ); ?></button>
				<?php endif; ?>
			</div>
		</label>
		<div id="pt-key-status" class="pt-key-status" style="display:none;"></div>

		<div id="pt-model-section" style="<?php echo esc_attr( $has_key ? '' : 'display:none;' ); ?>">
			<label>
				<span><?php esc_html_e( 'Model', 'tracewp' ); ?></span>
				<div class="pt-model-row">
					<select id="pt-model-select" class="regular-text">
						<option value=""><?php esc_html_e( 'Loading models...', 'tracewp' ); ?></option>
					</select>
					<button type="button" class="button" id="pt-refresh-models"><?php esc_html_e( 'Refresh', 'tracewp' ); ?></button>
				</div>
			</label>
			<label class="pt-checkbox">
				<input type="checkbox" id="pt-free-only" <?php checked( ! empty( $tpl['settings']['ai_free_only'] ) ); ?>>
				<span><?php esc_html_e( 'Use free models only (routes to OpenRouter free tier)', 'tracewp' ); ?></span>
			</label>
			<div id="pt-model-info" class="pt-model-info" style="display:none;"></div>
			<p>
				<button type="button" class="button button-primary" id="pt-save-ai-settings"><?php esc_html_e( 'Save AI Settings', 'tracewp' ); ?></button>
				<button type="button" class="button" id="pt-validate-key"><?php esc_html_e( 'Test Connection', 'tracewp' ); ?></button>
			</p>
		</div>
	</section>
</div>
