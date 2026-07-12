<?php
/**
 * Mailer helper.
 *
 * Wraps wp_mail() with safe header construction and consistent
 * plain-text formatting, keeping email-building logic out of the
 * AJAX handler.
 *
 * @package SecureContactForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCF_Mailer {

	/**
	 * Send the contact form notification to the configured recipient.
	 *
	 * @param array $data Sanitized submission data (name, email, subject, message).
	 * @return bool True on success.
	 */
	public static function send( array $data ) {
		$settings  = wp_parse_args(
			get_option( 'scf_settings', array() ),
			array(
				'recipient_email' => get_option( 'admin_email' ),
				'subject_prefix'  => '[Contact Form]',
			)
		);
		$recipient = $settings['recipient_email'];

		if ( ! is_email( $recipient ) ) {
			$recipient = get_option( 'admin_email' );
		}

		$subject = sprintf(
			'%s %s',
			trim( $settings['subject_prefix'] ),
			$data['subject']
		);

		$body = self::build_body( $data );

		// Reply-To (not From) is set to the visitor's address. Using
		// the site's own domain for the envelope "From" avoids SPF/DKIM
		// failures and prevents the classic email-spoofing anti-pattern
		// of sending "From" an address you don't control.
		$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$from_email = 'wordpress@' . wp_parse_url( home_url(), PHP_URL_HOST );

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: %s <%s>', $site_name, $from_email ),
			sprintf( 'Reply-To: %s <%s>', $data['name'], $data['email'] ),
		);

		return wp_mail( $recipient, $subject, $body, $headers );
	}

	/**
	 * Build the plain-text email body.
	 *
	 * @param array $data Sanitized submission data.
	 * @return string
	 */
	private static function build_body( array $data ) {
		$lines = array(
			__( 'You have received a new message via your website contact form.', 'secure-contact-form' ),
			'',
			__( 'Name:', 'secure-contact-form' ) . ' ' . $data['name'],
			__( 'Email:', 'secure-contact-form' ) . ' ' . $data['email'],
			__( 'Subject:', 'secure-contact-form' ) . ' ' . $data['subject'],
			'',
			__( 'Message:', 'secure-contact-form' ),
			$data['message'],
			'',
			'---',
			sprintf(
				/* translators: %s: site URL */
				__( 'Sent from the contact form on %s', 'secure-contact-form' ),
				home_url( '/' )
			),
		);

		return implode( "\n", $lines );
	}
}
