<?php
/**
 * AJAX request handler for the contact form.
 *
 * This is the security-critical entry point: every request is nonce
 * verified, honeypot-checked, rate-limited, sanitized, and validated
 * before anything is persisted or emailed.
 *
 * @package SecureContactForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCF_Ajax_Handler {

	/**
	 * Singleton instance.
	 *
	 * @var SCF_Ajax_Handler|null
	 */
	private static $instance = null;

	/**
	 * Nonce action string. Must match the one used in
	 * wp_create_nonce()/wp_localize_script() in SCF_Core.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'scf_submit_form_action';

	/**
	 * Get (or create) the singleton instance.
	 *
	 * @return SCF_Ajax_Handler
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register AJAX hooks for both authenticated and guest visitors --
	 * a contact form must be usable by logged-out users, which is why
	 * the `nopriv` variant is essential here.
	 */
	private function __construct() {
		add_action( 'wp_ajax_scf_submit_form', array( $this, 'handle_submission' ) );
		add_action( 'wp_ajax_nopriv_scf_submit_form', array( $this, 'handle_submission' ) );
	}

	/**
	 * Main submission handler.
	 *
	 * Order of checks matters: cheap, non-DB checks (nonce, honeypot,
	 * timing) run before the rate-limit lookup and any database writes,
	 * so obviously-bad requests are rejected as early and cheaply as
	 * possible.
	 */
	public function handle_submission() {

		// Defensive guard for local/dev environments: if WP_DEBUG and
		// display_errors are both on (the default in many local dev
		// stacks like Local, MAMP, XAMPP), any stray PHP notice or
		// warning gets printed as HTML *before* our JSON, which breaks
		// response.json() on the client and makes the request look
		// like it silently failed. wp_send_json_*() already sends the
		// correct Content-Type and calls wp_die(), but it does not
		// clear a buffer that already has content in it -- so we do
		// that explicitly here, keeping only whatever the JSON
		// functions below write out.
		if ( ob_get_length() ) {
			ob_clean();
		}

		// 1. NONCE VERIFICATION -----------------------------------------
		// check_ajax_referer() dies with a 403-style -1 response by
		// default; we call it in non-dying mode so we can return a
		// consistent, structured JSON error instead.
		if ( ! isset( $_POST['scf_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scf_nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'secure-contact-form' ) ),
				403
			);
		}

		// 2. HONEYPOT + TIMING TRAP (silent spam rejection) -------------
		// We deliberately respond with a generic *success* message to
		// suspected bots rather than an error. Telling an automated
		// script "spam detected" only teaches it to adapt; a fake
		// success wastes the bot's time and gives away nothing.
		if ( SCF_Validator::is_honeypot_triggered( $_POST ) || SCF_Validator::is_too_fast( $_POST ) ) {
			$this->log_blocked_attempt( 'honeypot_or_timing' );
			wp_send_json_success(
				array( 'message' => $this->get_success_message() )
			);
		}

		// 3. RATE LIMITING -----------------------------------------------
		$ip_hash = $this->get_hashed_ip();
		if ( $this->is_rate_limited( $ip_hash ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Too many submissions. Please try again later.', 'secure-contact-form' ) ),
				429
			);
		}

		// 4. SANITIZATION + VALIDATION ------------------------------------
		$result = SCF_Validator::validate( $_POST );

		if ( ! $result['valid'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please correct the errors below.', 'secure-contact-form' ),
					'errors'  => $result['errors'],
				),
				422
			);
		}

		$data = $result['data'];

		// 5. PERSIST SUBMISSION -------------------------------------------
		$stored = $this->store_submission( $data, $ip_hash );

		if ( false === $stored ) {
			wp_send_json_error(
				array( 'message' => __( 'Unable to save your message. Please try again.', 'secure-contact-form' ) ),
				500
			);
		}

		// 6. SEND NOTIFICATION EMAIL ---------------------------------------
		$mailed = SCF_Mailer::send( $data );

		if ( ! $mailed ) {
			// The submission is safely stored even if mail delivery
			// fails (e.g. misconfigured SMTP), so no user data is lost.
			// We still tell the visitor it worked, since from their
			// perspective the message *was* received by the site.
			error_log( 'Secure Contact Form: wp_mail() failed for submission ID ' . $stored ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		// 7. INCREMENT RATE LIMIT COUNTER ----------------------------------
		$this->bump_rate_limit( $ip_hash );

		wp_send_json_success(
			array( 'message' => $this->get_success_message() )
		);
	}

	/**
	 * Persist a validated submission to the custom database table
	 * using $wpdb->insert(), which parameterizes values internally
	 * and avoids any manual SQL string concatenation.
	 *
	 * @param array  $data    Sanitized form data.
	 * @param string $ip_hash Hashed visitor IP for abuse tracking.
	 * @return int|false Insert ID on success, false on failure.
	 */
	private function store_submission( array $data, $ip_hash ) {
		global $wpdb;

		$table = $wpdb->prefix . SCF_TABLE_NAME;

		$inserted = $wpdb->insert(
			$table,
			array(
				'name'         => $data['name'],
				'email'        => $data['email'],
				'subject'      => $data['subject'],
				'message'      => $data['message'],
				'ip_hash'      => $ip_hash,
				'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] )
					? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
					: '',
				'submitted_at' => current_time( 'mysql' ),
				'status'       => 'new',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? $wpdb->insert_id : false;
	}

	/**
	 * Hash the visitor's IP address before storing or using it as a
	 * rate-limit key. We never store raw IPs -- SHA-256 hashing with a
	 * site-specific salt (the WordPress AUTH_SALT) lets us detect
	 * repeat abuse from the same address without retaining personally
	 * identifiable data, aligning with data-minimization principles.
	 *
	 * @return string 64-character hex hash.
	 */
	private function get_hashed_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : wp_salt();

		return hash( 'sha256', $ip . $salt );
	}

	/**
	 * Check whether the given hashed IP has exceeded the configured
	 * submission rate limit within the last hour, using a WordPress
	 * transient as a lightweight, self-expiring counter store.
	 *
	 * @param string $ip_hash Hashed visitor IP.
	 * @return bool True if the visitor should be blocked.
	 */
	private function is_rate_limited( $ip_hash ) {
		$settings = get_option( 'scf_settings', array() );
		$limit    = isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 5;

		$count = (int) get_transient( 'scf_rate_' . $ip_hash );

		return $count >= $limit;
	}

	/**
	 * Increment the rate-limit counter for this IP, creating it with a
	 * one-hour expiry if it doesn't already exist.
	 *
	 * @param string $ip_hash Hashed visitor IP.
	 */
	private function bump_rate_limit( $ip_hash ) {
		$key   = 'scf_rate_' . $ip_hash;
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Fetch the configurable success message shown to the visitor.
	 *
	 * @return string
	 */
	private function get_success_message() {
		$settings = get_option( 'scf_settings', array() );

		return ! empty( $settings['success_message'] )
			? $settings['success_message']
			: __( 'Thanks! Your message has been sent successfully.', 'secure-contact-form' );
	}

	/**
	 * Lightweight, privacy-respecting logging hook for blocked bot
	 * attempts. Left as a no-op action by default so site owners can
	 * hook in their own monitoring/alerting without modifying core
	 * plugin files.
	 *
	 * @param string $reason Short machine-readable reason code.
	 */
	private function log_blocked_attempt( $reason ) {
		/**
		 * Fires when a submission is silently blocked as suspected spam.
		 *
		 * @param string $reason Reason code, e.g. 'honeypot_or_timing'.
		 */
		do_action( 'scf_blocked_submission', $reason );
	}
}
