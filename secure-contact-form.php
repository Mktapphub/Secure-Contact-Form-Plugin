<?php
/**
 * Plugin Name:       Secure Contact Form
 * Plugin URI:        https://example.com/secure-contact-form-pro
 * Description:       An enterprise-grade, security-first contact form plugin featuring nonce-verified AJAX submissions, honeypot anti-spam protection, rate limiting, full input sanitization, and an admin submissions dashboard.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your Name
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       secure-contact-form
 * Domain Path:       /languages
 *
 * @package SecureContactForm
 */

// Exit if accessed directly. This is the first line of defense against
// direct file access, preventing any code execution outside the WP context.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * -----------------------------------------------------------------------
 * PLUGIN CONSTANTS
 * -----------------------------------------------------------------------
 * Centralizing constants makes the plugin portable and easy to refactor.
 * Using plugin_dir_path()/plugin_dir_url() instead of hardcoded paths
 * ensures compatibility regardless of install location (symlinks, etc.).
 */
define( 'SCF_VERSION', '1.0.0' );
define( 'SCF_PLUGIN_FILE', __FILE__ );
define( 'SCF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SCF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SCF_DB_VERSION', '1.0' );
define( 'SCF_TABLE_NAME', 'scf_submissions' );

/**
 * -----------------------------------------------------------------------
 * CLASS AUTOLOADING
 * -----------------------------------------------------------------------
 * A lightweight PSR-4-ish autoloader keeps includes declarative rather
 * than a long list of manual require_once calls. Falls back silently
 * (no fatal) if a class file doesn't exist, which keeps the plugin
 * resilient to partial deployments.
 */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'SCF_' ) !== 0 ) {
			return;
		}

		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$file_path = SCF_PLUGIN_DIR . 'includes/' . $file_name;

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
);

/**
 * -----------------------------------------------------------------------
 * ACTIVATION / DEACTIVATION / UNINSTALL
 * -----------------------------------------------------------------------
 */
register_activation_hook( SCF_PLUGIN_FILE, array( 'SCF_Core', 'activate' ) );
register_deactivation_hook( SCF_PLUGIN_FILE, array( 'SCF_Core', 'deactivate' ) );

/**
 * -----------------------------------------------------------------------
 * BOOTSTRAP
 * -----------------------------------------------------------------------
 * Everything is wired up on `plugins_loaded` so translations and other
 * plugins have a chance to load first, and so we're not doing work
 * before WordPress core is fully ready.
 */
function scf_init_plugin() {
	SCF_Core::get_instance();
	SCF_Ajax_Handler::get_instance();

	if ( is_admin() ) {
		SCF_Admin::get_instance();
	}
}
add_action( 'plugins_loaded', 'scf_init_plugin' );
