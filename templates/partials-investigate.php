<?php
/**
 * AI Investigator panel partial.
 *
 * Only rendered when an API key is configured.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! PT_Settings::instance()->has_api_key() ) {
	// Show setup prompt instead.
	?>
	<section class="pt-card pt-investigate-promo">
		<div class="pt-investigate-promo-inner">
			<div>
				<h3><?php esc_html_e( 'AI Investigator', 'tracewp' ); ?></h3>
				<p><?php esc_html_e( 'Chat with an AI that can read your site files, trace issues through your theme and plugins, and tell you exactly how to fix things — right here in your dashboard. Requires an OpenRouter API key (free tier available).', 'tracewp' ); ?></p>
			</div>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=pt-settings#pt-ai-settings' ) ); ?>"><?php esc_html_e( 'Connect API Key', 'tracewp' ); ?></a>
		</div>
	</section>
	<?php
	return;
}

$ai_settings = PT_Settings::instance()->get();
$model_label = ! empty( $ai_settings['ai_model'] ) ? $ai_settings['ai_model'] : __( 'Auto', 'tracewp' );
if ( ! empty( $ai_settings['ai_free_only'] ) ) {
	$model_label = __( 'Free tier', 'tracewp' );
}
?>
<section class="pt-card pt-investigate-card">
	<div class="pt-investigate-header">
		<div>
			<h3><?php esc_html_e( 'AI Investigator', 'tracewp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Describe an issue and the AI will read your site files to diagnose it. Powered by your connected OpenRouter model — all requests go directly from your browser.', 'tracewp' ); ?></p>
		</div>
		<span class="pt-investigate-model"><?php echo esc_html( $model_label ); ?></span>
	</div>
	<div id="pt-investigate" class="pt-investigate-panel">
		<!-- Populated by investigate.js -->
	</div>
</section>
