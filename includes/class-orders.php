<?php
/**
 * Order management.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orders class.
 */
class SDD_Orders {

	const META_CUSTOMER_EMAIL        = '_sdd_customer_email';
	const META_DOWNLOAD_ID           = '_sdd_download_id';
	const META_DOWNLOAD_NAME         = '_sdd_download_name';
	const META_AMOUNT                = '_sdd_amount';
	const META_CURRENCY              = '_sdd_currency';
	const META_STRIPE_SESSION_ID     = '_sdd_stripe_session_id';
	const META_STRIPE_PAYMENT_INTENT = '_sdd_stripe_payment_intent';
	const META_TOKEN                 = '_sdd_token';
	const META_TOKEN_EXPIRES         = '_sdd_token_expires';
	const META_DOWNLOAD_COUNT        = '_sdd_download_count';
	const META_STATUS                = '_sdd_status';
	const META_FILE_IDS              = '_sdd_file_ids';
	const META_DELIVERED_TEXT        = '_sdd_delivered_text';
	const META_EMAIL_SENT            = '_sdd_email_sent';
	const META_EMAIL_ERROR           = '_sdd_email_error';

	/**
	 * Format an order title for admin display.
	 *
	 * @param string $download_name Download name.
	 * @param string $email         Customer email.
	 * @return string
	 */
	public static function format_order_title( $download_name, $email ) {
		return sprintf( '%s — %s', $download_name, $email );
	}

	/**
	 * Get customer email for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_customer_email( $order_id ) {
		return (string) get_post_meta( $order_id, self::META_CUSTOMER_EMAIL, true );
	}

	/**
	 * Get download ID for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return int
	 */
	public static function get_download_id( $order_id ) {
		return (int) get_post_meta( $order_id, self::META_DOWNLOAD_ID, true );
	}

	/**
	 * Get download name for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_download_name( $order_id ) {
		return (string) get_post_meta( $order_id, self::META_DOWNLOAD_NAME, true );
	}

	/**
	 * Get order amount in minor units.
	 *
	 * @param int $order_id Order ID.
	 * @return int
	 */
	public static function get_amount( $order_id ) {
		return (int) get_post_meta( $order_id, self::META_AMOUNT, true );
	}

	/**
	 * Get order currency code.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_currency( $order_id ) {
		return (string) get_post_meta( $order_id, self::META_CURRENCY, true );
	}

	/**
	 * Get Stripe session ID for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_stripe_session_id( $order_id ) {
		return (string) get_post_meta( $order_id, self::META_STRIPE_SESSION_ID, true );
	}

	/**
	 * Get Stripe payment intent ID for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_stripe_payment_intent( $order_id ) {
		return (string) get_post_meta( $order_id, self::META_STRIPE_PAYMENT_INTENT, true );
	}

	/**
	 * Get download token for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_token( $order_id ) {
		return (string) get_post_meta( $order_id, self::META_TOKEN, true );
	}

	/**
	 * Get token expiry timestamp.
	 *
	 * @param int $order_id Order ID.
	 * @return int
	 */
	public static function get_token_expires( $order_id ) {
		return (int) get_post_meta( $order_id, self::META_TOKEN_EXPIRES, true );
	}

	/**
	 * Get download count for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return int
	 */
	public static function get_download_count( $order_id ) {
		return (int) get_post_meta( $order_id, self::META_DOWNLOAD_COUNT, true );
	}

	/**
	 * Get order status.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_status( $order_id ) {
		return (string) get_post_meta( $order_id, self::META_STATUS, true );
	}

	/**
	 * Get file attachment IDs for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return int[]
	 */
	public static function get_file_ids( $order_id ) {
		$file_ids = get_post_meta( $order_id, self::META_FILE_IDS, true );
		return is_array( $file_ids ) ? array_map( 'absint', $file_ids ) : array();
	}

	/**
	 * Get delivered text content for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_delivered_text( $order_id ) {
		return (string) get_post_meta( $order_id, self::META_DELIVERED_TEXT, true );
	}

	/**
	 * Snapshot delivery text from a download onto an order.
	 *
	 * @param int $order_id    Order ID.
	 * @param int $download_id Download post ID.
	 */
	public static function snapshot_delivered_text( $order_id, $download_id ) {
		$text = sdd_get_download_text_content( $download_id );
		if ( $text !== '' ) {
			update_post_meta( $order_id, self::META_DELIVERED_TEXT, $text );
		}
	}

	/**
	 * Get email sent timestamp.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_email_sent( $order_id ) {
		return (string) get_post_meta( $order_id, self::META_EMAIL_SENT, true );
	}

	/**
	 * Get email error message.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_email_error( $order_id ) {
		return (string) get_post_meta( $order_id, self::META_EMAIL_ERROR, true );
	}

	/**
	 * Increment the download count for an order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function increment_download_count( $order_id ) {
		update_post_meta( $order_id, self::META_DOWNLOAD_COUNT, self::get_download_count( $order_id ) + 1 );
	}

	/**
	 * Clear customer email for privacy erasure.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function clear_customer_email( $order_id ) {
		delete_post_meta( $order_id, self::META_CUSTOMER_EMAIL );
	}

	/**
	 * Clear download token for an order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function clear_token( $order_id ) {
		delete_post_meta( $order_id, self::META_TOKEN );
	}

	/**
	 * Find order by Stripe session ID.
	 *
	 * @param string $session_id Session ID.
	 * @return WP_Post|null
	 */
	public static function find_by_session_id( $session_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'sdd_order',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => self::META_STRIPE_SESSION_ID,
				'meta_value'     => sanitize_text_field( $session_id ),
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Find order by download token.
	 *
	 * @param string $token Download token.
	 * @return WP_Post|null
	 */
	public static function find_by_token( $token ) {
		$posts = get_posts(
			array(
				'post_type'      => 'sdd_order',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => self::META_TOKEN,
				'meta_value'     => sanitize_text_field( $token ),
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Find order by payment intent ID.
	 *
	 * @param string $payment_intent Payment intent ID.
	 * @return WP_Post|null
	 */
	public static function find_by_payment_intent( $payment_intent ) {
		$posts = get_posts(
			array(
				'post_type'      => 'sdd_order',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => self::META_STRIPE_PAYMENT_INTENT,
				'meta_value'     => sanitize_text_field( $payment_intent ),
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Create order from Stripe session data.
	 *
	 * @param array $session Stripe session object.
	 * @return int|WP_Error Order ID or error.
	 */
	public static function create_from_session( $session ) {
		$session_id = $session['id'] ?? '';
		if ( empty( $session_id ) ) {
			return new WP_Error( 'sdd_invalid_session', __( 'Invalid session.', 'simple-digital-downloads' ) );
		}

		$existing = self::find_by_session_id( $session_id );
		if ( $existing ) {
			return $existing->ID;
		}

		if ( ( $session['payment_status'] ?? '' ) !== 'paid' ) {
			return new WP_Error( 'sdd_not_paid', __( 'Payment not completed.', 'simple-digital-downloads' ) );
		}

		$metadata    = $session['metadata'] ?? array();
		$download_id = absint( $metadata['download_id'] ?? 0 );
		$token       = sanitize_text_field( $metadata['token'] ?? '' );

		if ( ! $download_id || ! $token ) {
			return new WP_Error( 'sdd_missing_metadata', __( 'Session metadata is incomplete.', 'simple-digital-downloads' ) );
		}

		$download = get_post( $download_id );
		if ( ! $download || 'sdd_download' !== $download->post_type ) {
			return new WP_Error( 'sdd_invalid_download', __( 'Download not found.', 'simple-digital-downloads' ) );
		}

		$files = get_post_meta( $download_id, '_sdd_files', true );
		if ( ! is_array( $files ) ) {
			$files = array();
		}

		$currency = get_option( 'sdd_currency', 'USD' );
		$amount   = (int) get_post_meta( $download_id, '_sdd_price', true );
		$email    = sanitize_email( $session['customer_details']['email'] ?? ( $session['customer_email'] ?? '' ) );

		$expiry_hours = (int) get_option( 'sdd_token_expiry_hours', 48 );

		$order_id = wp_insert_post(
			array(
				'post_type'   => 'sdd_order',
				'post_status' => 'publish',
				'post_title'  => self::format_order_title( $download->post_title, $email ?: __( 'Unknown', 'simple-digital-downloads' ) ),
			),
			true
		);

		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		update_post_meta( $order_id, self::META_CUSTOMER_EMAIL, $email );
		update_post_meta( $order_id, self::META_DOWNLOAD_ID, $download_id );
		update_post_meta( $order_id, self::META_DOWNLOAD_NAME, $download->post_title );
		update_post_meta( $order_id, self::META_AMOUNT, $amount );
		update_post_meta( $order_id, self::META_CURRENCY, $currency );
		update_post_meta( $order_id, self::META_STRIPE_SESSION_ID, $session_id );
		update_post_meta( $order_id, self::META_STRIPE_PAYMENT_INTENT, sanitize_text_field( $session['payment_intent'] ?? '' ) );
		update_post_meta( $order_id, self::META_TOKEN, $token );
		update_post_meta( $order_id, self::META_TOKEN_EXPIRES, time() + ( $expiry_hours * HOUR_IN_SECONDS ) );
		update_post_meta( $order_id, self::META_DOWNLOAD_COUNT, 0 );
		update_post_meta( $order_id, self::META_STATUS, 'completed' );
		update_post_meta( $order_id, self::META_FILE_IDS, array_map( 'absint', $files ) );
		self::snapshot_delivered_text( $order_id, $download_id );

		self::send_email( $order_id );

		return $order_id;
	}

	/**
	 * Send delivery email.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function send_email( $order_id ) {
		$email = self::get_customer_email( $order_id );
		if ( ! $email ) {
			update_post_meta( $order_id, self::META_EMAIL_ERROR, __( 'No customer email.', 'simple-digital-downloads' ) );
			return false;
		}

		$download_name = self::get_download_name( $order_id );

		$subject = sprintf(
			/* translators: %s: download name */
			__( 'Your download: %s', 'simple-digital-downloads' ),
			$download_name
		);

		$message = self::build_email_message( $order_id );

		$sent = wp_mail( $email, $subject, $message );

		if ( $sent ) {
			update_post_meta( $order_id, self::META_EMAIL_SENT, current_time( 'mysql' ) );
			delete_post_meta( $order_id, self::META_EMAIL_ERROR );
		} else {
			update_post_meta( $order_id, self::META_EMAIL_ERROR, __( 'wp_mail() failed.', 'simple-digital-downloads' ) );
		}

		return $sent;
	}

	/**
	 * Build the plain-text delivery email body.
	 *
	 * Free orders include direct download links in the email only. Paid orders
	 * link to the delivery page where files are shown after checkout.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function build_email_message( $order_id ) {
		$download_name  = self::get_download_name( $order_id );
		$delivered_text = self::get_delivered_text( $order_id );
		$token          = self::get_token( $order_id );
		$file_ids       = self::get_file_ids( $order_id );
		$amount         = self::get_amount( $order_id );
		$expiry_hours   = (int) get_option( 'sdd_token_expiry_hours', 48 );

		$lines   = array();
		$lines[] = __( 'Thank you for your purchase!', 'simple-digital-downloads' );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: download name */
			__( 'Your download "%s" is ready:', 'simple-digital-downloads' ),
			$download_name
		);

		if ( 0 === $amount ) {
			if ( ! empty( $file_ids ) ) {
				$lines[] = '';
				if ( count( $file_ids ) === 1 ) {
					$lines[] = self::get_file_download_url( $token, 0 );
				} else {
					foreach ( $file_ids as $index => $file_id ) {
						$lines[] = basename( get_attached_file( $file_id ) );
						$lines[] = self::get_file_download_url( $token, $index );
						$lines[] = '';
					}
				}
			}
		} else {
			$lines[] = self::get_delivery_url( $order_id );
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %d: number of hours */
			_n(
				'This link expires in %d hour.',
				'This link expires in %d hours.',
				$expiry_hours,
				'simple-digital-downloads'
			),
			$expiry_hours
		);

		if ( $delivered_text !== '' ) {
			$lines[] = '';
			$lines[] = __( 'Your access details:', 'simple-digital-downloads' );
			$lines[] = $delivered_text;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get delivery page URL for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_delivery_url( $order_id ) {
		$token   = self::get_token( $order_id );
		$page_id = (int) get_option( 'sdd_delivery_page_id', 0 );
		$base    = $page_id ? get_permalink( $page_id ) : home_url( '/' );
		return add_query_arg( 'sdd_token', $token, $base );
	}

	/**
	 * Get download link for a file index.
	 *
	 * @param string $token Download token.
	 * @param int    $index File index.
	 * @return string
	 */
	public static function get_file_download_url( $token, $index = null ) {
		$args = array( 'sdd_download' => $token );
		if ( null !== $index ) {
			$args['f'] = $index;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Mark order as refunded and invalidate token.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function mark_refunded( $order_id ) {
		update_post_meta( $order_id, self::META_STATUS, 'refunded' );
		self::clear_token( $order_id );
	}

	/**
	 * Initialize reconciliation cron.
	 */
	public static function init() {
		add_action( 'sdd_reconcile_sessions', array( __CLASS__, 'reconcile' ) );

		if ( ! wp_next_scheduled( 'sdd_reconcile_sessions' ) ) {
			wp_schedule_event( time(), 'daily', 'sdd_reconcile_sessions' );
		}
	}

	/**
	 * Daily reconciliation — catch paid sessions that the webhook missed.
	 *
	 * Stripe gives up retrying after ~3 days, so a site that was down during
	 * a delivery window loses that order permanently with no way to notice.
	 * This sweep covers that gap. create_from_session() is idempotent, so
	 * duplicates are harmless.
	 */
	public static function reconcile() {
		if ( ! SDD_Credentials::is_connected() ) {
			return;
		}

		$result = SDD_Stripe::get( 'checkout/sessions', array(
			'limit'  => 50,
			'status' => 'complete',
		) );

		if ( is_wp_error( $result ) || empty( $result['data'] ) ) {
			return;
		}

		foreach ( $result['data'] as $session ) {
			$session_id = $session['id'] ?? '';
			if ( empty( $session_id ) ) {
				continue;
			}

			// Skip sessions already recorded.
			if ( self::find_by_session_id( $session_id ) ) {
				continue;
			}

			// Only process sessions from this site.
			$site_url = $session['metadata']['site_url'] ?? '';
			if ( $site_url !== home_url() ) {
				continue;
			}

			self::create_from_session( $session );
		}
	}
}
