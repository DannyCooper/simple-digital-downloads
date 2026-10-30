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
	 * Stripe events the webhook endpoint listens to.
	 */
	const EVENTS = array(
		'checkout.session.completed',
		'checkout.session.async_payment_succeeded',
		'checkout.session.async_payment_failed',
		'charge.refunded',
	);

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
	 * Keeps the stored endpoint when it still exists and its secret is known,
	 * deletes any other endpoint pointing at this site's webhook URL, and only
	 * creates a new endpoint when there is none to keep.
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

		$url       = self::get_endpoint_url();
		$endpoints = self::find_endpoints( $url );
		if ( is_wp_error( $endpoints ) ) {
			return $endpoints;
		}

		$stored_id = get_option( 'sdd_stripe_webhook_endpoint_id', '' );
		$keep_id   = '';
		if ( $stored_id && '' !== SDD_Credentials::get_webhook_secret() && in_array( $stored_id, $endpoints, true ) ) {
			$keep_id = $stored_id;
		}

		$failed = self::delete_endpoints( array_diff( $endpoints, array( $keep_id ) ) );

		if ( $keep_id ) {
			$result = SDD_Stripe::update_webhook_endpoint(
				$keep_id,
				array(
					'enabled_events' => self::EVENTS,
					'disabled'       => 'false',
				)
			);
		} else {
			$result = SDD_Stripe::create_webhook_endpoint(
				array(
					'url'            => $url,
					'enabled_events' => self::EVENTS,
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $keep_id ) {
			update_option( 'sdd_stripe_webhook_endpoint_id', $result['id'] ?? '', false );
			SDD_Credentials::save_webhook_secret( $result['secret'] ?? '' );
		}

		if ( $failed ) {
			return new WP_Error(
				'sdd_webhook_cleanup',
				sprintf(
					/* translators: 1: number of endpoints, 2: Stripe error message */
					_n(
						'API key saved and webhook registered, but %1$d old webhook endpoint for this site could not be deleted from Stripe. Delete it in your Stripe Dashboard. Stripe error: %2$s',
						'API key saved and webhook registered, but %1$d old webhook endpoints for this site could not be deleted from Stripe. Delete them in your Stripe Dashboard. Stripe error: %2$s',
						count( $failed ),
						'simple-digital-downloads'
					),
					count( $failed ),
					reset( $failed )->get_error_message()
				)
			);
		}

		return true;
	}

	/**
	 * Delete every Stripe webhook endpoint pointing at this site's webhook URL.
	 *
	 * Used on uninstall, so failures are ignored.
	 */
	public static function delete_site_endpoints() {
		$endpoints = self::find_endpoints( self::get_endpoint_url() );
		if ( ! is_wp_error( $endpoints ) ) {
			self::delete_endpoints( $endpoints );
		}
	}

	/**
	 * This site's webhook URL.
	 *
	 * @return string
	 */
	public static function get_endpoint_url() {
		return rest_url( SDD_REST_NAMESPACE . '/webhook' );
	}

	/**
	 * Find Stripe webhook endpoints whose URL exactly matches.
	 *
	 * @param string $url Webhook URL.
	 * @return string[]|WP_Error Endpoint IDs.
	 */
	private static function find_endpoints( $url ) {
		$ids            = array();
		$starting_after = '';

		do {
			$page = SDD_Stripe::list_webhook_endpoints( $starting_after );
			if ( is_wp_error( $page ) ) {
				return $page;
			}

			$data = $page['data'] ?? array();
			foreach ( $data as $endpoint ) {
				if ( ( $endpoint['url'] ?? '' ) === $url ) {
					$ids[] = $endpoint['id'];
				}
			}

			$last           = end( $data );
			$starting_after = $last['id'] ?? '';
		} while ( ! empty( $page['has_more'] ) && $starting_after );

		return $ids;
	}

	/**
	 * Delete Stripe webhook endpoints.
	 *
	 * @param string[] $ids Endpoint IDs.
	 * @return WP_Error[] Errors for endpoints that could not be deleted.
	 */
	private static function delete_endpoints( $ids ) {
		$failed = array();
		foreach ( $ids as $id ) {
			$result = SDD_Stripe::delete_webhook_endpoint( $id );
			if ( is_wp_error( $result ) ) {
				$failed[] = $result;
			}
		}
		return $failed;
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
