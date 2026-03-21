<?php
/**
 * Shared output panel.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="pt-output-panel" id="pt-output-panel" style="display:none;">
	<div class="pt-output-header">
		<h3><?php esc_html_e( 'Output', 'tracewp' ); ?></h3>
		<div class="pt-actions">
			<button type="button" class="button" id="pt-copy-output"><?php esc_html_e( 'Copy', 'tracewp' ); ?></button>
			<button type="button" class="button" id="pt-download"><?php esc_html_e( 'Download', 'tracewp' ); ?></button>
		</div>
	</div>
	<div class="pt-notice pt-notice--warning" style="display:none;" id="pt-sensitive-notice">
		<p><strong><?php esc_html_e( 'Sensitive data warning:', 'tracewp' ); ?></strong>
		<?php esc_html_e( 'This export contains site structure details that could be useful to an attacker. Do not share publicly.', 'tracewp' ); ?></p>
	</div>
	<div class="pt-token-estimate" id="pt-token-estimate" style="display:none;">
		<span class="pt-token-count"></span>
	</div>
	<textarea class="large-text code pt-output" rows="16" readonly></textarea>
	<details class="pt-details">
		<summary><?php esc_html_e( 'Canonical Payload Preview', 'tracewp' ); ?></summary>
		<pre class="pt-payload-preview"></pre>
	</details>
</div>
