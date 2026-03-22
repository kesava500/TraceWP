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
		<span class="pt-output-title"><?php esc_html_e( 'Export Output', 'tracewp' ); ?></span>
		<div class="pt-actions">
			<button type="button" class="button pt-btn-outline-sm" id="pt-copy-output">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
				<?php esc_html_e( 'Copy', 'tracewp' ); ?>
			</button>
			<button type="button" class="button pt-btn-ghost-sm" id="pt-download"><?php esc_html_e( 'Download', 'tracewp' ); ?></button>
		</div>
	</div>
	<div class="pt-notice pt-notice--warning" style="display:none;" id="pt-sensitive-notice">
		<p><strong><?php esc_html_e( 'Sensitive data warning:', 'tracewp' ); ?></strong>
		<?php esc_html_e( 'This export contains site structure details that could be useful to an attacker. Do not share publicly.', 'tracewp' ); ?></p>
	</div>
	<div class="pt-token-estimate" id="pt-token-estimate" style="display:none;">
		<span class="pt-token-count"></span>
	</div>
	<div class="pt-output-content">
		<textarea class="pt-output" rows="16" readonly></textarea>
	</div>
	<details class="pt-details">
		<summary><?php esc_html_e( 'Canonical Payload Preview', 'tracewp' ); ?></summary>
		<pre class="pt-payload-preview"></pre>
	</details>
</div>
