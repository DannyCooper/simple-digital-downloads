<?php
/**
 * Stripe API wrapper.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe HTTP client.
 */
class SDD_Stripe {

	const API_BASE = 'https://api.stripe.com/v1/';

	/**
	 * Create a checkout session.
	 *
	 * @param array $params Session parameters.
	 * @return array|WP_Error
	 */
	public static function create_checkout_session( $params ) {
		return self::post( 'checkout/sessions', $params );
	}

	/**
	 * Retrieve a checkout session.
	 *
	 * @param string $session_id Session ID.
	 * @return array|WP_Error
	 */
	public static function retrieve_session( $session_id ) {
		return self::get( 'checkout/sessions/' . rawurlencode( $session_id ) );
	}

	/**
	 * Create a product.
	 *
	 * @param array $params Product parameters.
	 * @return array|WP_Error
	 */
	public static function create_product( $params ) {
		return self::post( 'products', $params );
	}

	/**
	 * Create a webhook endpoint.
	 *
	 * @param array $params Endpoint parameters.
	 * @return array|WP_Error
	 */
	public static function create_webhook_endpoint( $params ) {
		return self::post( 'webhook_endpoints', $params );
	}

	/**
	 * Update a webhook endpoint.
	 *
	 * @param string $endpoint_id Endpoint ID.
	 * @param array  $params      Endpoint parameters.
	 * @return array|WP_Error
	 */
	public static function update_webhook_endpoint( $endpoint_id, $params ) {
		return self::post( 'webhook_endpoints/' . rawurlencode( $endpoint_id ), $params );
	}

	/**
	 * List webhook endpoints.
	 *
	 * @param string $starting_after Endpoint ID to page after.
	 * @return array|WP_Error
	 */
	public static function list_webhook_endpoints( $starting_after = '' ) {
		$params = array( 'limit' => 100 );
		if ( $starting_after ) {
			$params['starting_after'] = $starting_after;
		}
		return self::get( 'webhook_endpoints', $params );
	}

	/**
	 * Delete a webhook endpoint.
	 *
	 * @param string $endpoint_id Endpoint ID.
	 * @return array|WP_Error
	 */
	public static function delete_webhook_endpoint( $endpoint_id ) {
		return self::delete( 'webhook_endpoints/' . rawurlencode( $endpoint_id ) );
	}

	/**
	 * Test connection by retrieving balance.
	 *
	 * @return array|WP_Error
	 */
	public static function test_connection() {
		return self::get( 'balance' );
	}

	/**
	 * GET request.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $params   Query params.
	 * @return array|WP_Error
	 */
	public static function get( $endpoint, $params = array() ) {
		$url = self::API_BASE . $endpoint;
		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => self::get_headers(),
				'timeout' => 30,
			)
		);

		return self::handle_response( $response );
	}

	/**
	 * POST request.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $params   Body params.
	 * @return array|WP_Error
	 */
	private static function post( $endpoint, $params ) {
		$headers                  = self::get_headers();
		$headers['Idempotency-Key'] = wp_generate_uuid4();

		$response = wp_remote_post(
			self::API_BASE . $endpoint,
			array(
				'headers' => $headers,
				'body'    => self::build_body( $params ),
				'timeout' => 30,
			)
		);

		return self::handle_response( $response );
	}

	/**
	 * DELETE request.
	 *
	 * @param string $endpoint API endpoint.
	 * @return array|WP_Error
	 */
	private static function delete( $endpoint ) {
		$response = wp_remote_request(
			self::API_BASE . $endpoint,
			array(
				'method'  => 'DELETE',
				'headers' => self::get_headers(),
				'timeout' => 30,
			)
		);

		return self::handle_response( $response );
	}

	/**
	 * Build request headers.
	 *
	 * @return array<string,string>
	 */
	private static function get_headers() {
		return array_merge(
			SDD_Credentials::get_request_args(),
			array( 'Content-Type' => 'application/x-www-form-urlencoded' )
		);
	}

	/**
	 * Flatten nested params for Stripe form encoding.
	 *
	 * @param array  $params Params.
	 * @param string $prefix Key prefix.
	 * @return array<string,string>
	 */
	private static function build_body( $params, $prefix = '' ) {
		$result = array();
		foreach ( $params as $key => $value ) {
			$full_key = '' === $prefix ? $key : $prefix . '[' . $key . ']';
			if ( is_array( $value ) ) {
				$result = array_merge( $result, self::build_body( $value, $full_key ) );
			} else {
				$result[ $full_key ] = $value;
			}
		}
		return $result;
	}

	/**
	 * Handle API response.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return array|WP_Error
	 */
	private static function handle_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return $body;
		}

		if ( 401 === $code ) {
			set_transient( 'sdd_stripe_auth_error', 1, HOUR_IN_SECONDS );
		}

		$message = $body['error']['message'] ?? __( 'Stripe API error.', 'simple-digital-downloads' );

		if ( 403 === $code ) {
			$message .= ' ' . __( 'Your Stripe API key may not have the required permissions. Please check your key settings in your Stripe Dashboard.', 'simple-digital-downloads' );
		}

		return new WP_Error( 'sdd_stripe_error', $message, array( 'status' => $code ) );
	}
}
