<?php
/**
 * Admin-area functionality.
 *
 * Provides a Settings API-based options page and a simple submissions
 * viewer. All admin actions are capability-checked (manage_options)
 * and nonce-protected, consistent with the same security-by-design
 * standard applied to the public-facing form.
 *
 * @package SecureContactForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCF_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var SCF_Admin|null
	 */
	private static $instance = null;

	/**
	 * Option group used by the Settings API.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'scf_settings_group';

	/**
	 * Get (or create) the singleton instance.
	 *
	 * @return SCF_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_scf_delete_submission', array( $this, 'handle_delete_submission' ) );
	}

	/**
	 * Register the top-level admin menu and its submenu pages.
	 */
	public function register_menu() {
		$capability = 'manage_options';

		add_menu_page(
			__( 'Secure Contact Form', 'secure-contact-form' ),
			__( 'Contact Form', 'secure-contact-form' ),
			$capability,
			'scf-submissions',
			array( $this, 'render_submissions_page' ),
			'dashicons-email-alt',
			30
		);

		add_submenu_page(
			'scf-submissions',
			__( 'Submissions', 'secure-contact-form' ),
			__( 'Submissions', 'secure-contact-form' ),
			$capability,
			'scf-submissions',
			array( $this, 'render_submissions_page' )
		);

		add_submenu_page(
			'scf-submissions',
			__( 'Settings', 'secure-contact-form' ),
			__( 'Settings', 'secure-contact-form' ),
			$capability,
			'scf-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings, section, and fields via the Settings API.
	 * Using register_setting()'s sanitize_callback means every saved
	 * value passes through our sanitizer automatically -- there is no
	 * path for unsanitized data to reach the options table.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			'scf_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'scf_main_section',
			__( 'Form Settings', 'secure-contact-form' ),
			'__return_false',
			'scf-settings'
		);

		$fields = array(
			'recipient_email' => __( 'Recipient Email', 'secure-contact-form' ),
			'subject_prefix'  => __( 'Subject Prefix', 'secure-contact-form' ),
			'success_message' => __( 'Success Message', 'secure-contact-form' ),
			'rate_limit'      => __( 'Rate Limit (submissions/hour per IP)', 'secure-contact-form' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				array( $this, 'render_field' ),
				'scf-settings',
				'scf_main_section',
				array( 'key' => $key )
			);
		}
	}

	/**
	 * Sanitize the settings array before it is saved to the database.
	 * Every field is run through the sanitizer appropriate to its type.
	 *
	 * @param array $input Raw settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$existing = get_option( 'scf_settings', array() );

		$output = array(
			'recipient_email' => isset( $input['recipient_email'] ) && is_email( $input['recipient_email'] )
				? sanitize_email( $input['recipient_email'] )
				: $existing['recipient_email'] ?? get_option( 'admin_email' ),
			'subject_prefix'  => isset( $input['subject_prefix'] )
				? sanitize_text_field( $input['subject_prefix'] )
				: ( $existing['subject_prefix'] ?? '[Contact Form]' ),
			'success_message' => isset( $input['success_message'] )
				? sanitize_textarea_field( $input['success_message'] )
				: ( $existing['success_message'] ?? '' ),
			'rate_limit'      => isset( $input['rate_limit'] )
				? max( 1, min( 100, absint( $input['rate_limit'] ) ) )
				: ( $existing['rate_limit'] ?? 5 ),
		);

		add_settings_error(
			'scf_settings',
			'scf_settings_saved',
			__( 'Settings saved.', 'secure-contact-form' ),
			'success'
		);

		return $output;
	}

	/**
	 * Render an individual settings field based on its key.
	 *
	 * @param array $args Field arguments, contains 'key'.
	 */
	public function render_field( $args ) {
		$settings = get_option( 'scf_settings', array() );
		$key      = $args['key'];
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';

		$name = "scf_settings[{$key}]";

		switch ( $key ) {
			case 'success_message':
				printf(
					'<textarea name="%1$s" id="%1$s" rows="3" class="large-text">%2$s</textarea>',
					esc_attr( $name ),
					esc_textarea( $value )
				);
				break;

			case 'rate_limit':
				printf(
					'<input type="number" min="1" max="100" name="%1$s" id="%1$s" value="%2$s" class="small-text" />',
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'recipient_email':
				printf(
					'<input type="email" name="%1$s" id="%1$s" value="%2$s" class="regular-text" required />',
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			default:
				printf(
					'<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $name ),
					esc_attr( $value )
				);
		}
	}

	/**
	 * Render the Settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'secure-contact-form' ) );
		}
		?>
		<div class="wrap scf-admin-wrap">
			<h1><?php esc_html_e( 'Secure Contact Form – Settings', 'secure-contact-form' ); ?></h1>
			<p><?php esc_html_e( 'Use the shortcode below to display the form on any page or post.', 'secure-contact-form' ); ?></p>
			<code>[secure_contact_form]</code>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP ); // Outputs nonce + option_page + action hidden fields.
				do_settings_sections( 'scf-settings' );
				submit_button( __( 'Save Settings', 'secure-contact-form' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Submissions listing page.
	 */
	public function render_submissions_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'secure-contact-form' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . SCF_TABLE_NAME;

		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;

		// $wpdb->prepare() with %d placeholders parameterizes the
		// LIMIT/OFFSET values, preventing SQL injection even though
		// they originate from a $_GET parameter.
		$submissions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, email, subject, submitted_at, status FROM {$table} ORDER BY submitted_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$per_page,
				$offset
			)
		);

		$total = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		?>
		<div class="wrap scf-admin-wrap">
			<h1><?php esc_html_e( 'Contact Form Submissions', 'secure-contact-form' ); ?></h1>

			<?php if ( empty( $submissions ) ) : ?>
				<p><?php esc_html_e( 'No submissions yet.', 'secure-contact-form' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'secure-contact-form' ); ?></th>
							<th><?php esc_html_e( 'Email', 'secure-contact-form' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'secure-contact-form' ); ?></th>
							<th><?php esc_html_e( 'Date', 'secure-contact-form' ); ?></th>
							<th><?php esc_html_e( 'Status', 'secure-contact-form' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'secure-contact-form' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $submissions as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->name ); ?></td>
								<td><a href="<?php echo esc_url( 'mailto:' . $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td>
								<td><?php echo esc_html( $row->subject ); ?></td>
								<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $row->submitted_at ) ); ?></td>
								<td><?php echo esc_html( ucfirst( $row->status ) ); ?></td>
								<td>
									<a
										class="scf-delete-link"
										href="<?php echo esc_url( $this->get_delete_url( $row->id ) ); ?>"
										onclick="return confirm('<?php echo esc_js( __( 'Delete this submission permanently?', 'secure-contact-form' ) ); ?>');"
									>
										<?php esc_html_e( 'Delete', 'secure-contact-form' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php
				$total_pages = (int) ceil( $total / $per_page );
				if ( $total_pages > 1 ) :
					?>
					<div class="tablenav">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%' ),
										'format'    => '',
										'prev_text' => __( '&laquo;', 'secure-contact-form' ),
										'next_text' => __( '&raquo;', 'secure-contact-form' ),
										'total'     => $total_pages,
										'current'   => $paged,
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build a nonce-protected delete URL for a given submission.
	 *
	 * @param int $id Submission ID.
	 * @return string
	 */
	private function get_delete_url( $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'scf_delete_submission',
					'id'     => absint( $id ),
				),
				admin_url( 'admin-post.php' )
			),
			'scf_delete_submission_' . absint( $id )
		);
	}

	/**
	 * Handle deletion of a submission from admin-post.php, protected
	 * by both a capability check and a per-item nonce.
	 */
	public function handle_delete_submission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'secure-contact-form' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( ! $id || ! isset( $_GET['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'scf_delete_submission_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'secure-contact-form' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . SCF_TABLE_NAME;

		// $wpdb->delete() parameterizes the WHERE clause internally.
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=scf-submissions&deleted=1' ) );
		exit;
	}
}
