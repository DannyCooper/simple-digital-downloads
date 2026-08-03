<?php
/**
 * Admin settings, order columns, and notices.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin class.
 */
class SDD_Admin {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'manage_sdd_order_posts_columns', array( __CLASS__, 'order_columns' ) );
		add_action( 'manage_sdd_order_posts_custom_column', array( __CLASS__, 'order_column_content' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'order_row_actions' ), 10, 2 );
		add_action( 'admin_post_sdd_resend_email', array( __CLASS__, 'handle_resend_email' ) );
		add_action( 'admin_post_sdd_test_connection', array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_post_sdd_delete_all_data', array( __CLASS__, 'handle_delete_all_data' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ) );
	}

	/**
	 * Add settings submenu.
	 */
	public static function add_settings_page() {
		add_submenu_page(
			'edit.php?post_type=sdd_download',
			__( 'Settings', 'simple-digital-downloads' ),
			__( 'Settings', 'simple-digital-downloads' ),
			'manage_options',
			'sdd-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public static function register_settings() {
		register_setting( 'sdd_settings', 'sdd_currency', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'sdd_settings', 'sdd_token_expiry_hours', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'sdd_settings', 'sdd_max_downloads', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'sdd_settings', 'sdd_delivery_page_id', array( 'sanitize_callback' => 'absint' ) );
		register_setting(
			'sdd_settings',
			'sdd_stripe_webhook_secret',
			array(
				'sanitize_callback' => function ( $value ) {
					if ( empty( $value ) ) {
						return get_option( 'sdd_stripe_webhook_secret', '' );
					}
					return sanitize_text_field( $value );
				},
			)
		);

		add_settings_section( 'sdd_stripe', __( 'Stripe', 'simple-digital-downloads' ), '__return_false', 'sdd_settings' );
		add_settings_section( 'sdd_general', __( 'General', 'simple-digital-downloads' ), '__return_false', 'sdd_settings' );
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['sdd_save_key'] ) && check_admin_referer( 'sdd_save_key' ) ) {
			self::save_stripe_key();
		}

		$stored_key   = SDD_Credentials::get_secret_key();
		$masked       = $stored_key ? SDD_Credentials::mask_key( $stored_key ) : '';
		$mode         = SDD_Credentials::get_mode();
		$protection   = get_option( 'sdd_file_protection_ok', 'unknown' );
		$webhook_id   = get_option( 'sdd_stripe_webhook_endpoint_id', '' );
		$delivery_id  = (int) get_option( 'sdd_delivery_page_id', 0 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Simple Digital Downloads Settings', 'simple-digital-downloads' ); ?></h1>

			<div class="notice notice-info inline" style="margin: 15px 0; padding: 10px 15px;">
				<p><strong><?php esc_html_e( 'Tax & VAT', 'simple-digital-downloads' ); ?></strong></p>
				<p><?php esc_html_e( 'This plugin does not calculate or collect sales tax, VAT, or GST. You are responsible for understanding and meeting your tax obligations in every jurisdiction where you sell.', 'simple-digital-downloads' ); ?></p>
			</div>

			<h2><?php esc_html_e( 'Stripe API Key', 'simple-digital-downloads' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'sdd_save_key' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="sdd_stripe_secret_key"><?php esc_html_e( 'Restricted Secret Key', 'simple-digital-downloads' ); ?></label></th>
						<td>
							<?php if ( $masked ) : ?>
								<p><code><?php echo esc_html( $masked ); ?></code> <?php echo $mode ? '(' . esc_html( ucfirst( $mode ) ) . ')' : ''; ?></p>
							<?php endif; ?>
							<input type="password" id="sdd_stripe_secret_key" name="sdd_stripe_secret_key" class="regular-text" autocomplete="off" placeholder="rk_live_... or sk_test_...">
							<p class="description">
								<?php esc_html_e( 'Create a restricted key in your Stripe Dashboard with these permissions:', 'simple-digital-downloads' ); ?>
							</p>
							<ul style="list-style:disc;margin-left:20px;">
								<li><?php esc_html_e( 'Checkout Sessions — Write', 'simple-digital-downloads' ); ?></li>
								<li><?php esc_html_e( 'Payment Intents — Read', 'simple-digital-downloads' ); ?></li>
								<li><?php esc_html_e( 'Webhook Endpoints — Write', 'simple-digital-downloads' ); ?></li>
								<li><?php esc_html_e( 'Refunds — Read', 'simple-digital-downloads' ); ?></li>
							</ul>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Key', 'simple-digital-downloads' ), 'primary', 'sdd_save_key' ); ?>
			</form>

			<?php if ( SDD_Credentials::is_connected() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sdd_test_connection">
					<?php wp_nonce_field( 'sdd_test_connection' ); ?>
					<?php submit_button( __( 'Test Connection', 'simple-digital-downloads' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Webhook', 'simple-digital-downloads' ); ?></h2>
			<p>
				<?php if ( $webhook_id ) : ?>
					<span style="color:green;">✓</span> <?php esc_html_e( 'Webhook endpoint registered.', 'simple-digital-downloads' ); ?>
					<code><?php echo esc_html( rest_url( SDD_REST_NAMESPACE . '/webhook' ) ); ?></code>
				<?php else : ?>
					<span style="color:orange;">⚠</span> <?php esc_html_e( 'Webhook not registered. Save a valid API key to auto-register, or paste a signing secret below.', 'simple-digital-downloads' ); ?>
				<?php endif; ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'sdd_settings' ); ?>
				<h2><?php esc_html_e( 'General Settings', 'simple-digital-downloads' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="sdd_currency"><?php esc_html_e( 'Currency', 'simple-digital-downloads' ); ?></label></th>
						<td>
							<input type="text" id="sdd_currency" name="sdd_currency" value="<?php echo esc_attr( get_option( 'sdd_currency', 'USD' ) ); ?>" class="small-text" maxlength="3">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sdd_token_expiry_hours"><?php esc_html_e( 'Download Link Expiry (hours)', 'simple-digital-downloads' ); ?></label></th>
						<td>
							<input type="number" id="sdd_token_expiry_hours" name="sdd_token_expiry_hours" value="<?php echo esc_attr( get_option( 'sdd_token_expiry_hours', 48 ) ); ?>" min="1" class="small-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sdd_max_downloads"><?php esc_html_e( 'Max Downloads per Order', 'simple-digital-downloads' ); ?></label></th>
						<td>
							<input type="number" id="sdd_max_downloads" name="sdd_max_downloads" value="<?php echo esc_attr( get_option( 'sdd_max_downloads', 5 ) ); ?>" min="1" class="small-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sdd_delivery_page_id"><?php esc_html_e( 'Delivery Page', 'simple-digital-downloads' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'sdd_delivery_page_id',
									'id'                => 'sdd_delivery_page_id',
									'selected'          => $delivery_id,
									'show_option_none'  => __( '— Select —', 'simple-digital-downloads' ),
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sdd_stripe_webhook_secret"><?php esc_html_e( 'Webhook Signing Secret (fallback)', 'simple-digital-downloads' ); ?></label></th>
						<td>
							<input type="password" id="sdd_stripe_webhook_secret" name="sdd_stripe_webhook_secret" value="" class="regular-text" autocomplete="off" placeholder="whsec_...">
							<?php if ( SDD_Credentials::get_webhook_secret() ) : ?>
								<p class="description"><?php esc_html_e( 'A signing secret is stored. Leave blank to keep the current value.', 'simple-digital-downloads' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'File Protection', 'simple-digital-downloads' ); ?></th>
						<td>
							<?php if ( 'yes' === $protection ) : ?>
								<span style="color:green;">✓</span> <?php esc_html_e( 'Your download files are protected.', 'simple-digital-downloads' ); ?>
							<?php elseif ( 'no' === $protection ) : ?>
								<span style="color:red;">✗</span> <?php esc_html_e( 'Your download files may be publicly accessible. Please contact your hosting provider and ask them to block direct access to your protected uploads folder. You can share the following with them:', 'simple-digital-downloads' ); ?>
								<pre style="background:#f0f0f1;padding:10px;overflow:auto;"><?php echo esc_html( SDD_Uploads::get_nginx_rules() ); ?></pre>
							<?php else : ?>
								<?php esc_html_e( 'Not yet checked.', 'simple-digital-downloads' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Delete All Data', 'simple-digital-downloads' ); ?></h2>
			<p><?php esc_html_e( 'Permanently delete all orders and downloads. Uploaded files in the protected directory are not deleted — remove them manually if needed. This action cannot be undone.', 'simple-digital-downloads' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sdd-delete-data-form">
				<input type="hidden" name="action" value="sdd_delete_all_data">
				<?php wp_nonce_field( 'sdd_delete_all_data' ); ?>
				<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Are you sure? This will permanently delete all orders and downloads. This cannot be undone.', 'simple-digital-downloads' ) ); ?>');">
					<?php esc_html_e( 'Delete All Orders & Downloads', 'simple-digital-downloads' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Save Stripe API key and register webhook.
	 */
	private static function save_stripe_key() {
		if ( defined( 'SDD_STRIPE_SECRET_KEY' ) && SDD_STRIPE_SECRET_KEY ) {
			add_settings_error( 'sdd_settings', 'sdd_constant', __( 'Key is defined via SDD_STRIPE_SECRET_KEY constant in wp-config.php.', 'simple-digital-downloads' ), 'info' );
			return;
		}

		$key = isset( $_POST['sdd_stripe_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['sdd_stripe_secret_key'] ) ) : '';
		if ( empty( $key ) ) {
			return;
		}

		if ( ! preg_match( '/^(sk|rk)_(test|live)_/', $key ) ) {
			add_settings_error( 'sdd_settings', 'sdd_invalid_key', __( 'Invalid Stripe key format.', 'simple-digital-downloads' ) );
			return;
		}

		SDD_Credentials::save_secret_key( $key );

		$result = SDD_Webhook::register_endpoint();
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'sdd_settings', 'sdd_webhook', $result->get_error_message(), 'warning' );
		} else {
			add_settings_error( 'sdd_settings', 'sdd_saved', __( 'API key saved and webhook registered.', 'simple-digital-downloads' ), 'success' );
		}
	}

	/**
	 * Handle test connection.
	 */
	public static function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sdd_test_connection' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'simple-digital-downloads' ) );
		}

		$result = SDD_Stripe::test_connection();
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'sdd_settings', 'sdd_test_fail', $result->get_error_message(), 'error' );
		} else {
			add_settings_error( 'sdd_settings', 'sdd_test_ok', __( 'Connection successful.', 'simple-digital-downloads' ), 'success' );
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=sdd_download&page=sdd-settings' ) );
		exit;
	}

	/**
	 * Admin notices.
	 */
	public static function admin_notices() {
		settings_errors( 'sdd_settings' );

		if ( get_transient( 'sdd_stripe_auth_error' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Your Stripe API key is invalid or has been revoked. Please update it in Downloads → Settings.', 'simple-digital-downloads' ) . '</p></div>';
			delete_transient( 'sdd_stripe_auth_error' );
		}

		if ( get_transient( 'sdd_protection_notice' ) && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Simple Digital Downloads: your download files may be publicly accessible. Please contact your hosting provider to fix this.', 'simple-digital-downloads' );
			echo ' <a href="' . esc_url( admin_url( 'edit.php?post_type=sdd_download&page=sdd-settings' ) ) . '">' . esc_html__( 'View settings', 'simple-digital-downloads' ) . '</a>';
			echo '</p></div>';
		}
	}

	/**
	 * Order list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function order_columns( $columns ) {
		return array(
			'cb'                => $columns['cb'],
			'title'             => __( 'Order', 'simple-digital-downloads' ),
			'sdd_customer'      => __( 'Customer', 'simple-digital-downloads' ),
			'sdd_download'      => __( 'Download', 'simple-digital-downloads' ),
			'sdd_amount'        => __( 'Amount', 'simple-digital-downloads' ),
			'sdd_status'        => __( 'Status', 'simple-digital-downloads' ),
			'date'              => __( 'Date', 'simple-digital-downloads' ),
		);
	}

	/**
	 * Order column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function order_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'sdd_customer':
				echo esc_html( SDD_Orders::get_customer_email( $post_id ) );
				break;
			case 'sdd_download':
				echo esc_html( SDD_Orders::get_download_name( $post_id ) );
				break;
			case 'sdd_amount':
				echo esc_html( sdd_format_price( SDD_Orders::get_amount( $post_id ), SDD_Orders::get_currency( $post_id ) ) );
				break;
			case 'sdd_status':
				echo esc_html( ucfirst( SDD_Orders::get_status( $post_id ) ?: 'unknown' ) );
				break;
		}
	}

	/**
	 * Order row actions.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function order_row_actions( $actions, $post ) {
		if ( 'sdd_order' !== $post->post_type ) {
			return $actions;
		}
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=sdd_resend_email&order_id=' . $post->ID ),
			'sdd_resend_email_' . $post->ID
		);
		$actions['resend'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Resend Email', 'simple-digital-downloads' ) . '</a>';
		return $actions;
	}

	/**
	 * Handle resend email action.
	 */
	public static function handle_resend_email() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! $order_id || ! current_user_can( 'edit_sdd_order', $order_id ) || ! check_admin_referer( 'sdd_resend_email_' . $order_id ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'simple-digital-downloads' ) );
		}

		$sent = SDD_Orders::send_email( $order_id );
		$type = $sent ? 'success' : 'error';
		$msg  = $sent ? __( 'Email resent.', 'simple-digital-downloads' ) : __( 'Failed to send email.', 'simple-digital-downloads' );
		add_settings_error( 'sdd_settings', 'sdd_resend', $msg, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'edit.php?post_type=sdd_order' ) );
		exit;
	}

	/**
	 * Handle delete all data.
	 */
	public static function handle_delete_all_data() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sdd_delete_all_data' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'simple-digital-downloads' ) );
		}

		$post_types = array( 'sdd_order', 'sdd_download' );
		$deleted    = 0;
		foreach ( $post_types as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			foreach ( $posts as $post_id ) {
				wp_delete_post( $post_id, true );
				$deleted++;
			}
		}

		add_settings_error(
			'sdd_settings',
			'sdd_deleted',
			sprintf(
				/* translators: %d: number of posts deleted */
				__( 'Deleted %d orders and downloads.', 'simple-digital-downloads' ),
				$deleted
			),
			'success'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=sdd_download&page=sdd-settings' ) );
		exit;
	}

	/**
	 * Add read-only order detail meta box.
	 */
	public static function add_order_meta_box() {
		add_meta_box(
			'sdd_order_details',
			__( 'Order Details', 'simple-digital-downloads' ),
			array( __CLASS__, 'render_order_meta_box' ),
			'sdd_order',
			'normal',
			'high'
		);
	}

	/**
	 * Render order detail meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_order_meta_box( $post ) {
		$id       = $post->ID;
		$currency = SDD_Orders::get_currency( $id );

		$fields = array(
			__( 'Customer Email', 'simple-digital-downloads' )  => SDD_Orders::get_customer_email( $id ),
			__( 'Download', 'simple-digital-downloads' )        => SDD_Orders::get_download_name( $id ),
			__( 'Amount', 'simple-digital-downloads' )          => sdd_format_price( SDD_Orders::get_amount( $id ), $currency ),
			__( 'Currency', 'simple-digital-downloads' )        => $currency,
			__( 'Stripe Session', 'simple-digital-downloads' )  => SDD_Orders::get_stripe_session_id( $id ),
			__( 'Payment Intent', 'simple-digital-downloads' )  => SDD_Orders::get_stripe_payment_intent( $id ),
			__( 'Status', 'simple-digital-downloads' )          => SDD_Orders::get_status( $id ),
			__( 'Download Count', 'simple-digital-downloads' )  => SDD_Orders::get_download_count( $id ),
			__( 'Email Sent', 'simple-digital-downloads' )      => SDD_Orders::get_email_sent( $id ),
			__( 'Email Error', 'simple-digital-downloads' )     => SDD_Orders::get_email_error( $id ),
		);

		echo '<table class="form-table"><tbody>';
		foreach ( $fields as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		$delivered_text = SDD_Orders::get_delivered_text( $id );
		if ( $delivered_text !== '' ) {
			echo '<tr><th>' . esc_html__( 'Delivered text', 'simple-digital-downloads' ) . '</th><td><pre style="white-space:pre-wrap;">' . esc_html( $delivered_text ) . '</pre></td></tr>';
		}

		echo '</tbody></table>';
	}
}
