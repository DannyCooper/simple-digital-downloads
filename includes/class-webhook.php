<?php
/**
 * Stripe webhook handler.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Webhook class.
 */
class SDD_Webhook {

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
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle incoming webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( $request ) {
		$payload   = $request->get_body();
		$sig_header = $request->get_header( 'stripe-signature' );
		$secret    = SDD_Credentials::get_webhook_secret();

		if ( empty( $secret ) ) {
			return new WP_Error( 'sdd_no_secret', __( 'Webhook secret not configured.', 'simple-digital-downloads' ), array( 'status' => 500 ) );
		}

		if ( ! self::verify_signature( $payload, $sig_header, $secret ) ) {
			return new WP_Error( 'sdd_invalid_signature', __( 'Invalid signature.', 'simple-digital-downloads' ), array( 'status' => 400 ) );
		}

		$event = json_decode( $payload, true );
		if ( empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		switch ( $event['type'] ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				$session = $event['data']['object'] ?? array();
				SDD_Orders::create_from_session( $session );
				break;

			case 'charge.refunded':
				$charge          = $event['data']['object'] ?? array();
				$payment_intent  = $charge['payment_intent'] ?? '';
				if ( $payment_intent ) {
					$order = SDD_Orders::find_by_payment_intent( $payment_intent );
					if ( $order ) {
						SDD_Orders::mark_refunded( $order->ID );
					}
				}
				break;
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Verify Stripe webhook signature.
	 *
	 * @param string $payload    Raw body.
	 * @param string $sig_header Stripe-Signature header.
	 * @param string $secret     Webhook secret.
	 * @return bool
	 */
	public static function verify_signature( $payload, $sig_header, $secret ) {
		if ( empty( $sig_header ) ) {
			return false;
		}

		$parts     = array();
		$elements  = explode( ',', $sig_header );
		foreach ( $elements as $element ) {
			$item = explode( '=', $element, 2 );
			if ( 2 === count( $item ) ) {
				$parts[ trim( $item[0] ) ] = trim( $item[1] );
			}
		}

		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			return false;
		}

		$timestamp = (int) $parts['t'];
		if ( abs( time() - $timestamp ) > 300 ) {
			return false;
		}

		$signed_payload = $timestamp . '.' . $payload;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		return hash_equals( $expected, $parts['v1'] );
	}

	/**
	 * Register webhook endpoint with Stripe.
	 *
	 * @return true|WP_Error
	 */
	public static function register_endpoint() {
		if ( ! self::is_public_url() ) {
			return new WP_Error(
				'sdd_localhost',
				__( 'Your site URL is not publicly accessible. Use the Stripe CLI (stripe listen --forward-to) for local development, or paste a webhook signing secret manually.', 'simple-digital-downloads' )
			);
		}

		$stored_id = get_option( 'sdd_stripe_webhook_endpoint_id', '' );
		if ( $stored_id ) {
			SDD_Stripe::delete_webhook_endpoint( $stored_id );
		}

		$url = rest_url( SDD_REST_NAMESPACE . '/webhook' );

		$result = SDD_Stripe::create_webhook_endpoint(
			array(
				'url'            => $url,
				'enabled_events' => array(
					'checkout.session.completed',
					'checkout.session.async_payment_succeeded',
					'checkout.session.async_payment_failed',
					'charge.refunded',
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_option( 'sdd_stripe_webhook_endpoint_id', $result['id'] ?? '', false );
		SDD_Credentials::save_webhook_secret( $result['secret'] ?? '' );

		return true;
	}

	/**
	 * Check if site URL is publicly accessible.
	 *
	 * @return bool
	 */
	public static function is_public_url() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$private = array( 'localhost', '127.0.0.1', '::1' );
		if ( in_array( $host, $private, true ) ) {
			return false;
		}
		if ( preg_match( '/\.(local|test|localhost)$/', $host ) ) {
			return false;
		}
		return true;
	}
}
