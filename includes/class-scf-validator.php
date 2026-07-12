<?php
/**
 * Validation helper.
 *
 * Pure, stateless validation methods kept separate from the AJAX
 * handler so they can be unit tested independently and reused
 * anywhere else in the plugin (e.g. a future REST endpoint).
 *
 * @package SecureContactForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCF_Validator {

	/**
	 * Maximum allowed lengths, mirrored on the client for UX but
	 * always enforced here since client-side limits are trivially
	 * bypassed.
	 */
	const MAX_NAME_LENGTH    = 100;
	const MAX_SUBJECT_LENGTH = 150;
	const MAX_MESSAGE_LENGTH = 5000;

	/**
	 * Validate and sanitize the raw POST payload.
	 *
	 * @param array $raw Unsanitized $_POST data.
	 * @return array {
	 *     @type bool     $valid  Whether validation passed.
	 *     @type array    $data   Sanitized fields (only meaningful if valid).
	 *     @type string[] $errors Field => error message map.
	 * }
	 */
	public static function validate( array $raw ) {
		$errors = array();

		// --- Sanitize first, then validate the sanitized value. -------
		// Sanitizing first ensures we never evaluate raw/tainted input,
		// and WordPress's sanitize_* functions are purpose-built to
		// strip exactly the right things per field type.
		$name    = isset( $raw['scf_name'] ) ? sanitize_text_field( wp_unslash( $raw['scf_name'] ) ) : '';
		$email   = isset( $raw['scf_email'] ) ? sanitize_email( wp_unslash( $raw['scf_email'] ) ) : '';
		$subject = isset( $raw['scf_subject'] ) ? sanitize_text_field( wp_unslash( $raw['scf_subject'] ) ) : '';
		$message = isset( $raw['scf_message'] ) ? sanitize_textarea_field( wp_unslash( $raw['scf_message'] ) ) : '';

		if ( '' === $name ) {
			$errors['scf_name'] = __( 'Please enter your name.', 'secure-contact-form' );
		} elseif ( mb_strlen( $name ) > self::MAX_NAME_LENGTH ) {
			$errors['scf_name'] = __( 'Name is too long.', 'secure-contact-form' );
		}

		if ( '' === $email ) {
			$errors['scf_email'] = __( 'Please enter your email address.', 'secure-contact-form' );
		} elseif ( ! is_email( $email ) ) {
			$errors['scf_email'] = __( 'Please enter a valid email address.', 'secure-contact-form' );
		}

		if ( '' === $subject ) {
			$errors['scf_subject'] = __( 'Please enter a subject.', 'secure-contact-form' );
		} elseif ( mb_strlen( $subject ) > self::MAX_SUBJECT_LENGTH ) {
			$errors['scf_subject'] = __( 'Subject is too long.', 'secure-contact-form' );
		}

		if ( '' === $message ) {
			$errors['scf_message'] = __( 'Please enter a message.', 'secure-contact-form' );
		} elseif ( mb_strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
			$errors['scf_message'] = __( 'Message is too long.', 'secure-contact-form' );
		}

		// --- Basic header-injection guard -----------------------------
		// Defense in depth: even though sanitize_text_field() already
		// strips line breaks from single-line fields, we explicitly
		// reject any residual CR/LF in fields that will end up in
		// email headers, blocking classic header-injection attempts.
		foreach ( array( 'scf_name' => $name, 'scf_email' => $email, 'scf_subject' => $subject ) as $key => $value ) {
			if ( preg_match( '/[\r\n]/', $value ) ) {
				$errors[ $key ] = __( 'Invalid characters detected.', 'secure-contact-form' );
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'data'   => compact( 'name', 'email', 'subject', 'message' ),
			'errors' => $errors,
		);
	}

	/**
	 * Honeypot check.
	 *
	 * The honeypot field is hidden from real users via CSS (not
	 * `type="hidden"`, which basic bots specifically know to skip) and
	 * left empty. Bots that blindly fill every field will trip it.
	 *
	 * @param array $raw Unsanitized $_POST data.
	 * @return bool True if the submission looks like spam.
	 */
	public static function is_honeypot_triggered( array $raw ) {
		return ! empty( $raw['scf_website'] );
	}

	/**
	 * Time-trap check: reject submissions completed implausibly fast,
	 * a strong signal of scripted/automated submission rather than a
	 * human filling out a form.
	 *
	 * @param array $raw               Unsanitized $_POST data.
	 * @param int   $min_seconds Minimum plausible fill time.
	 * @return bool True if submitted too quickly.
	 */
	public static function is_too_fast( array $raw, $min_seconds = 3 ) {
		if ( empty( $raw['scf_ts'] ) || ! is_numeric( $raw['scf_ts'] ) ) {
			return true; // Missing/invalid timestamp is itself suspicious.
		}

		$elapsed = time() - (int) $raw['scf_ts'];
		return $elapsed < $min_seconds;
	}
}
