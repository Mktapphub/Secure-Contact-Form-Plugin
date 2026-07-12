<?php
/**
 * Front-end contact form template.
 *
 * Included via output buffering from SCF_Core::render_shortcode().
 * $atts is available in scope from the calling method.
 *
 * @package SecureContactForm
 * @var array $atts Shortcode attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="scf-form-wrapper">

	<?php if ( ! empty( $atts['title'] ) ) : ?>
		<h2 class="scf-form-title"><?php echo esc_html( $atts['title'] ); ?></h2>
	<?php endif; ?>

	<!-- aria-live regions announce success/error state changes to
	     screen reader users without requiring a page reload/focus jump. -->
	<div class="scf-form-message" role="status" aria-live="polite" hidden></div>

	<form id="scf-contact-form" class="scf-form" novalidate>

		<?php
		// Nonce field: WordPress core helper outputs a hidden input
		// containing a signed, time-limited token tied to the current
		// user session. Verified server-side in SCF_Ajax_Handler.
		wp_nonce_field( 'scf_submit_form_action', 'scf_nonce', false );
		?>

		<!-- Time-trap: records when the form was rendered so the
		     server can reject submissions completed implausibly fast. -->
		<input type="hidden" name="scf_ts" value="<?php echo esc_attr( time() ); ?>" />

		<!-- HONEYPOT FIELD -------------------------------------------
		     Hidden via CSS (assets/css/scf-style.css), NOT type="hidden"
		     or display:none applied inline -- some spam bots specifically
		     skip those. A legitimate visitor never sees or fills this in;
		     tabindex="-1" and autocomplete="off" further keep it out of
		     the way of keyboard and autofill for the rare edge case where
		     CSS fails to load. -->
		<div class="scf-hp-field" aria-hidden="true">
			<label for="scf_website"><?php esc_html_e( 'Website', 'secure-contact-form' ); ?></label>
			<input
				type="text"
				id="scf_website"
				name="scf_website"
				tabindex="-1"
				autocomplete="off"
			/>
		</div>

		<div class="scf-form-row">
			<label for="scf_name"><?php esc_html_e( 'Name', 'secure-contact-form' ); ?> <span class="scf-required">*</span></label>
			<input type="text" id="scf_name" name="scf_name" required maxlength="100" autocomplete="name" />
			<span class="scf-field-error" data-field="scf_name"></span>
		</div>

		<div class="scf-form-row">
			<label for="scf_email"><?php esc_html_e( 'Email', 'secure-contact-form' ); ?> <span class="scf-required">*</span></label>
			<input type="email" id="scf_email" name="scf_email" required maxlength="191" autocomplete="email" />
			<span class="scf-field-error" data-field="scf_email"></span>
		</div>

		<div class="scf-form-row">
			<label for="scf_subject"><?php esc_html_e( 'Subject', 'secure-contact-form' ); ?> <span class="scf-required">*</span></label>
			<input type="text" id="scf_subject" name="scf_subject" required maxlength="150" autocomplete="off" />
			<span class="scf-field-error" data-field="scf_subject"></span>
		</div>

		<div class="scf-form-row">
			<label for="scf_message"><?php esc_html_e( 'Message', 'secure-contact-form' ); ?> <span class="scf-required">*</span></label>
			<textarea id="scf_message" name="scf_message" rows="6" required maxlength="5000"></textarea>
			<span class="scf-field-error" data-field="scf_message"></span>
		</div>

		<div class="scf-form-row scf-form-submit">
			<button type="submit" class="scf-submit-btn">
				<span class="scf-btn-text"><?php esc_html_e( 'Send Message', 'secure-contact-form' ); ?></span>
				<span class="scf-spinner" aria-hidden="true" hidden></span>
			</button>
		</div>

	</form>
</div>
