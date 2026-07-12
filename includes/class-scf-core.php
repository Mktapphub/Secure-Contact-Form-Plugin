<?php
/**
 * Core plugin class.
 *
 * Handles the shortcode, front-end asset loading, and lifecycle
 * (activation / deactivation) tasks, including safe creation of the
 * custom submissions table via dbDelta().
 *
 * @package SecureContactForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCF_Core {

	/**
	 * Singleton instance.
	 *
	 * @var SCF_Core|null
	 */
	private static $instance = null;

	/**
	 * Get (or create) the singleton instance.
	 *
	 * @return SCF_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up hooks. Constructor is private to enforce the singleton
	 * pattern -- there is never a reason to have two instances of core.
	 */
	private function __construct() {
		add_shortcode( 'secure_contact_form', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Self-healing schema check. register_activation_hook() only
		// fires on the inactive -> active transition, so it is
		// silently skipped in several real-world scenarios: the site
		// was copied/migrated with the plugin already active, files
		// were updated over SFTP without a deactivate/reactivate
		// cycle, or the plugin was network-activated across a
		// multisite install after new sites were later added. Rather
		// than leaving the plugin in a broken "form works, nothing is
		// ever stored" state in those cases, we cheaply verify the
		// table exists (and matches the current schema version) once
		// per admin page load and self-repair if not.
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade_db' ) );
	}

	/**
	 * Enqueue front-end CSS/JS only when the shortcode is likely present.
	 * We conditionally register everything, and only enqueue when the
	 * form is actually rendered (see render_shortcode) to avoid loading
	 * unused assets on every single page of the site.
	 */
	public function enqueue_assets() {
		wp_register_style(
			'scf-style',
			SCF_PLUGIN_URL . 'assets/css/scf-style.css',
			array(),
			SCF_VERSION
		);

		wp_register_script(
			'scf-form',
			SCF_PLUGIN_URL . 'assets/js/scf-form.js',
			array(), // No jQuery dependency -- uses native fetch().
			SCF_VERSION,
			true
		);
	}

	/**
	 * Render the [secure_contact_form] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML markup.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title' => __( 'Get in Touch', 'secure-contact-form' ),
			),
			$atts,
			'secure_contact_form'
		);

		// Only load assets on pages where the form is actually used.
		wp_enqueue_style( 'scf-style' );
		wp_enqueue_script( 'scf-form' );

		// Pass data to JS safely via wp_localize_script rather than
		// inline <script> tags -- this avoids any risk of breaking out
		// of a JS string context and keeps CSP-friendly separation of
		// markup and behavior.
		wp_localize_script(
			'scf-form',
			'scfData',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'scf_submit_form_action' ),
				'i18n'         => array(
					'sending'      => __( 'Sending…', 'secure-contact-form' ),
					'submit'       => __( 'Send Message', 'secure-contact-form' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'secure-contact-form' ),
				),
			)
		);

		ob_start();
		include SCF_PLUGIN_DIR . 'templates/contact-form.php';
		$html = ob_get_clean();

		// Collapse whitespace between tags before returning.
		//
		// Shortcode output that runs through the_content passes through
		// wpautop(), which inserts <p> and <br> tags based on blank
		// lines / single line breaks in the raw HTML. Since <form> is
		// not permitted inside a <p>, browsers silently "fix" the
		// resulting invalid markup by closing/relocating tags -- which
		// can detach the .scf-form-message element from where the JS
		// expects it, or otherwise reshape the DOM in ways that break
		// the form. Removing whitespace between tags gives wpautop
		// nothing to match against, so it leaves our markup alone.
		return (string) preg_replace( '/>\s+</', '><', trim( $html ) );
	}

	/**
	 * Plugin activation callback.
	 *
	 * Creates/updates the custom submissions table using dbDelta(),
	 * which is idempotent and safe to run on every activation, including
	 * plugin updates -- it will alter existing tables rather than
	 * duplicating them.
	 */
	public static function activate() {
		self::create_or_upgrade_table();

		// Sensible defaults on first activation, without clobbering
		// settings that may already exist from a prior install.
		if ( false === get_option( 'scf_settings' ) ) {
			add_option(
				'scf_settings',
				array(
					'recipient_email' => get_option( 'admin_email' ),
					'subject_prefix'  => '[Contact Form]',
					'success_message' => __( 'Thanks! Your message has been sent successfully.', 'secure-contact-form' ),
					'rate_limit'      => 5, // Max submissions per IP per hour.
				)
			);
		}

		flush_rewrite_rules();
	}

	/**
	 * Create (or update) the submissions table via dbDelta().
	 *
	 * dbDelta() is idempotent: running it against an already-correct
	 * table is a safe no-op, and running it after the schema constant
	 * changes will ALTER the existing table rather than duplicating it.
	 * This is intentionally a plain static method (not hooked directly)
	 * so it can be called both from activate() and from the admin_init
	 * self-healing check below.
	 */
	public static function create_or_upgrade_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . SCF_TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB -- schema definition, not a query.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL,
			subject VARCHAR(255) NOT NULL,
			message TEXT NOT NULL,
			ip_hash CHAR(64) NOT NULL,
			user_agent VARCHAR(255) DEFAULT '' NOT NULL,
			submitted_at DATETIME NOT NULL,
			status VARCHAR(20) DEFAULT 'new' NOT NULL,
			PRIMARY KEY  (id),
			KEY submitted_at (submitted_at),
			KEY status (status)
		) {$charset_collate};";
		// phpcs:enable

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'scf_db_version', SCF_DB_VERSION );
	}

	/**
	 * Lightweight self-healing check, run on `admin_init`.
	 *
	 * Compares the stored schema version against the current
	 * SCF_DB_VERSION constant. On any mismatch -- including a missing
	 * option, which covers the "table was never created" case -- it
	 * re-runs dbDelta(). This check is a single get_option() call
	 * (cheap, no DB write) on every admin page load, and only performs
	 * the actual CREATE TABLE work when something is actually out of
	 * date, so the steady-state cost is negligible.
	 */
	public static function maybe_upgrade_db() {
		if ( get_option( 'scf_db_version' ) !== SCF_DB_VERSION ) {
			self::create_or_upgrade_table();
		}
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * Deliberately does NOT drop the submissions table or delete
	 * settings -- deactivation should be non-destructive. Data removal
	 * (if ever desired) belongs in uninstall.php, gated behind an
	 * explicit user setting, per WordPress plugin guidelines.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
