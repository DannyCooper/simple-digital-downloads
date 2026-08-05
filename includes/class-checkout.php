<?php
/**
 * Checkout REST endpoint.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checkout handler.
 */
class SDD_Checkout {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			SDD_REST_NAMESPACE,
			'/checkout',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_session' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'download_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'email'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'cancel_url'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * Create a Stripe checkout session or process a free download.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_session( $request ) {
		$download_id = $request->get_param( 'download_id' );
		$download    = get_post( $download_id );

		if ( ! $download || 'sdd_download' !== $download->post_type || 'publish' !== $download->post_status ) {
			return new WP_Error( 'sdd_invalid_download', __( 'Download not available.', 'simple-digital-downloads' ), array( 'status' => 404 ) );
		}

		if ( post_password_required( $download ) ) {
			return new WP_Error( 'sdd_password_protected', __( 'This download is not available for purchase.', 'simple-digital-downloads' ), array( 'status' => 403 ) );
		}

		$price = (int) get_post_meta( $download_id, '_sdd_price', true );
		$files = get_post_meta( $download_id, '_sdd_files', true );
		if ( ! is_array( $files ) ) {
			$files = array();
		}

		if ( ! sdd_download_has_deliverable( $download_id ) ) {
			return new WP_Error( 'sdd_no_content', __( 'This download has nothing to deliver yet. Add files or delivery text.', 'simple-digital-downloads' ), array( 'status' => 400 ) );
		}

		if ( $price <= 0 ) {
			return self::process_free_download( $request, $download, $files );
		}

		return self::process_paid_download( $request, $download, $price, $files );
	}

	/**
	 * Process a free download — collect email and create order directly.
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param WP_Post         $download Download post.
	 * @param int[]           $files    File attachment IDs.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function process_free_download( $request, $download, $files ) {
		$email = $request->get_param( 'email' );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'sdd_invalid_email', __( 'Please enter a valid email address.', 'simple-digital-downloads' ), array( 'status' => 400 ) );
		}

		$currency     = get_option( 'sdd_currency', 'USD' );
		$token        = sdd_generate_token();
		$expiry_hours = (int) get_option( 'sdd_token_expiry_hours', 48 );

		$order_id = wp_insert_post(
			array(
				'post_type'   => 'sdd_order',
				'post_status' => 'publish',
				'post_title'  => SDD_Orders::format_order_title( $download->post_title, $email ),
			),
			true
		);

		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		update_post_meta( $order_id, SDD_Orders::META_CUSTOMER_EMAIL, $email );
		update_post_meta( $order_id, SDD_Orders::META_DOWNLOAD_ID, $download->ID );
		update_post_meta( $order_id, SDD_Orders::META_DOWNLOAD_NAME, $download->post_title );
		update_post_meta( $order_id, SDD_Orders::META_AMOUNT, 0 );
		update_post_meta( $order_id, SDD_Orders::META_CURRENCY, $currency );
		update_post_meta( $order_id, SDD_Orders::META_TOKEN, $token );
		update_post_meta( $order_id, SDD_Orders::META_TOKEN_EXPIRES, time() + ( $expiry_hours * HOUR_IN_SECONDS ) );
		update_post_meta( $order_id, SDD_Orders::META_DOWNLOAD_COUNT, 0 );
		update_post_meta( $order_id, SDD_Orders::META_STATUS, 'completed' );
		update_post_meta( $order_id, SDD_Orders::META_FILE_IDS, array_map( 'absint', $files ) );
		SDD_Orders::snapshot_delivered_text( $order_id, $download->ID );

		SDD_Orders::send_email( $order_id );

		$delivery_url = SDD_Delivery::get_delivery_page_url();

		return rest_ensure_response(
			array(
				'delivery_url' => add_query_arg( 'sdd_sent', '1', $delivery_url ),
				'free'         => true,
			)
		);
	}

	/**
	 * Process a paid download — create Stripe checkout session.
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param WP_Post         $download Download post.
	 * @param int             $price    Price in minor units.
	 * @param int[]           $files    File attachment IDs.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function process_paid_download( $request, $download, $price, $files ) {
		if ( ! SDD_Credentials::is_connected() ) {
			return new WP_Error( 'sdd_not_connected', __( 'Stripe is not configured.', 'simple-digital-downloads' ), array( 'status' => 503 ) );
		}

		$currency     = get_option( 'sdd_currency', 'USD' );
		$token        = sdd_generate_token();
		$delivery_url = SDD_Delivery::get_delivery_page_url();
		$cancel_url   = $request->get_param( 'cancel_url' ) ?: wp_get_referer() ?: home_url( '/' );

		$params = array(
			'mode'                 => 'payment',
			'success_url'          => add_query_arg(
				array(
					'sdd_token'   => $token,
					'sdd_session' => '{CHECKOUT_SESSION_ID}',
				),
				$delivery_url
			),
			'cancel_url'           => esc_url_raw( $cancel_url ),
			'customer_creation'    => 'always',
			'payment_method_types' => array( 'card' ),
			'line_items'           => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => strtolower( $currency ),
						'unit_amount'  => $price,
						'product_data' => array(
							'name' => $download->post_title,
						),
					),
				),
			),
			'metadata'             => array(
				'download_id' => (string) $download->ID,
				'token'       => $token,
				'site_url'    => home_url(),
			),
		);

		$session = SDD_Stripe::create_checkout_session( $params );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return rest_ensure_response(
			array(
				'checkout_url' => $session['url'] ?? '',
				'session_id'   => $session['id'] ?? '',
			)
		);
	}

}
