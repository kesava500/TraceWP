<?php
/**
 * AI Investigator panel partial.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! PT_Settings::instance()->has_api_key() ) {
	// Show setup prompt.
	?>
	<section class="pt-card pt-investigate-promo">
		<div class="pt-card-header">
			<div class="pt-card-header-left">
				<div class="pt-card-icon pt-card-icon--accent">
					<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
				</div>
				<div>
					<h2 class="pt-card-title"><?php esc_html_e( 'AI Investigator', 'tracewp' ); ?></h2>
					<p class="pt-card-desc"><?php esc_html_e( 'Chat with an AI that can read your site files, trace issues through your theme and plugins, and tell you exactly how to fix things. Requires an OpenRouter API key (free tier available).', 'tracewp' ); ?></p>
				</div>
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
	<div class="pt-card-header">
		<div class="pt-card-header-left">
			<div class="pt-card-icon pt-card-icon--accent">
				<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
			</div>
			<div>
				<h2 class="pt-card-title"><?php esc_html_e( 'AI Investigator', 'tracewp' ); ?></h2>
				<p class="pt-card-desc"><?php esc_html_e( 'Describe an issue and the AI will read your site files to diagnose it. Powered by your connected OpenRouter model — all requests go directly from your browser.', 'tracewp' ); ?></p>
			</div>
		</div>
		<span class="pt-badge pt-badge--accent"><?php echo esc_html( $model_label ); ?></span>
	</div>
	<div id="pt-investigate" class="pt-investigate-panel">
		<!-- Populated by investigate.js -->
	</div>
</section>
