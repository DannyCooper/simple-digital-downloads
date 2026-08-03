<?php
/**
 * Delivery page and shortcode.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Delivery handler.
 */
class SDD_Delivery {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_shortcode( 'sdd_delivery', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_filter( 'wp_headers', array( __CLASS__, 'filter_headers' ), 10, 2 );
	}

	/**
	 * Set Referrer-Policy on the delivery page so session IDs don't leak via Referer.
	 *
	 * @param string[] $headers Response headers.
	 * @param WP       $wp      WordPress environment.
	 * @return string[]
	 */
	public static function filter_headers( $headers, $wp ) {
		if ( ! empty( $_GET['sdd_session'] ) || ! empty( $_GET['sdd_token'] ) ) {
			$headers['Referrer-Policy'] = 'no-referrer';
		}
		return $headers;
	}

	/**
	 * Register delivery block.
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			'simple-digital-downloads/delivery',
			array(
				'render_callback' => array( __CLASS__, 'render_shortcode' ),
			)
		);
	}

	/**
	 * Ensure delivery page exists.
	 */
	public static function ensure_delivery_page() {
		$page_id = (int) get_option( 'sdd_delivery_page_id', 0 );
		if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
			return;
		}

		if ( $page_id && get_post_status( $page_id ) === 'trash' ) {
			wp_untrash_post( $page_id );
			wp_update_post(
				array(
					'ID'          => $page_id,
					'post_status' => 'publish',
				)
			);
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Download Delivery', 'simple-digital-downloads' ),
				'post_content' => '<!-- wp:shortcode -->[sdd_delivery]<!-- /wp:shortcode -->',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'sdd_delivery_page_id', $page_id, false );
		}
	}

	/**
	 * Get delivery page URL.
	 *
	 * @return string
	 */
	public static function get_delivery_page_url() {
		$page_id = (int) get_option( 'sdd_delivery_page_id', 0 );
		if ( $page_id ) {
			return get_permalink( $page_id );
		}
		return home_url( '/' );
	}

	/**
	 * Render delivery shortcode.
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		$token      = isset( $_GET['sdd_token'] ) ? sanitize_text_field( wp_unslash( $_GET['sdd_token'] ) ) : '';
		$session_id = isset( $_GET['sdd_session'] ) ? sanitize_text_field( wp_unslash( $_GET['sdd_session'] ) ) : '';

		if ( empty( $token ) && empty( $session_id ) ) {
			return '<p>' . esc_html__( 'No purchase session found.', 'simple-digital-downloads' ) . '</p>';
		}

		$order = self::resolve_order( $token, $session_id );

		if ( $order ) {
			return self::render_file_list( $order );
		}

		return self::render_pending_message();
	}

	/**
	 * Find or create an order for a token or checkout session.
	 *
	 * Looks up by token first (the canonical identifier), then falls back
	 * to session ID for backwards compatibility with old email links.
	 *
	 * @param string $token      Download token.
	 * @param string $session_id Stripe checkout session ID.
	 * @return WP_Post|null
	 */
	public static function resolve_order( $token = '', $session_id = '' ) {
		if ( $token ) {
			$order = SDD_Orders::find_by_token( $token );
			if ( $order ) {
				return $order;
			}
		}

		if ( $session_id ) {
			$order = SDD_Orders::find_by_session_id( $session_id );
			if ( $order ) {
				return $order;
			}

			$result = self::fallback_create_order( $session_id );
			if ( ! is_wp_error( $result ) ) {
				$order = get_post( $result );
				return ( $order && 'sdd_order' === $order->post_type ) ? $order : null;
			}
		}

		return null;
	}

	/**
	 * Render a pending state when payment is still processing.
	 *
	 * @return string
	 */
	private static function render_pending_message() {
		return '<div class="sdd-delivery-pending"><p>' . esc_html__( 'Your payment is being confirmed. You will receive an email with your download link shortly.', 'simple-digital-downloads' ) . '</p></div>';
	}

	/**
	 * Render file list for an order.
	 *
	 * @param WP_Post $order Order post.
	 * @return string
	 */
	public static function render_file_list( $order ) {
		$token          = SDD_Orders::get_token( $order->ID );
		$file_ids       = SDD_Orders::get_file_ids( $order->ID );
		$delivered_text = SDD_Orders::get_delivered_text( $order->ID );
		$name           = SDD_Orders::get_download_name( $order->ID );
		$status         = SDD_Orders::get_status( $order->ID );
		$amount         = SDD_Orders::get_amount( $order->ID );
		$has_files      = ! empty( $file_ids );
		$has_text       = $delivered_text !== '';

		if ( 'refunded' === $status ) {
			return '<div class="sdd-delivery-notice"><p>' . esc_html__( 'This order has been refunded and downloads are no longer available.', 'simple-digital-downloads' ) . '</p></div>';
		}

		if ( empty( $token ) ) {
			return '<div class="sdd-delivery-notice"><p>' . esc_html__( 'This download link is no longer active.', 'simple-digital-downloads' ) . '</p></div>';
		}

		if ( ! $has_files && ! $has_text ) {
			return '<div class="sdd-delivery-notice"><p>' . esc_html__( 'No content found for this order.', 'simple-digital-downloads' ) . '</p></div>';
		}

		if ( 0 === $amount ) {
			return '<div class="sdd-delivery-notice"><p>' . esc_html( sprintf(
				/* translators: %s: download name */
				__( 'Thank you! Check your email for the download link to "%s".', 'simple-digital-downloads' ),
				$name
			) ) . '</p></div>';
		}

		$expires = SDD_Orders::get_token_expires( $order->ID );
		if ( $expires && time() > $expires ) {
			return '<div class="sdd-delivery-notice"><p>' . esc_html__( 'This download link has expired. Please check your email for the original link or contact the seller.', 'simple-digital-downloads' ) . '</p></div>';
		}

		$max_downloads = (int) get_option( 'sdd_max_downloads', 5 );
		$at_limit      = $has_files && SDD_Orders::get_download_count( $order->ID ) >= $max_downloads;

		ob_start();
		?>
		<div class="sdd-delivery-success">
			<h2><?php echo esc_html( sprintf( __( 'Your download: %s', 'simple-digital-downloads' ), $name ) ); ?></h2>

			<?php if ( $has_text ) : ?>
				<?php self::render_text_content( $delivered_text ); ?>
			<?php endif; ?>

			<?php if ( $has_files && ! $at_limit ) : ?>
				<?php if ( count( $file_ids ) === 1 ) : ?>
					<p>
						<a class="button sdd-download-link" href="<?php echo esc_url( SDD_Orders::get_file_download_url( $token, 0 ) ); ?>">
							<?php esc_html_e( 'Download File', 'simple-digital-downloads' ); ?>
						</a>
					</p>
				<?php else : ?>
					<ul class="sdd-file-downloads">
						<?php foreach ( $file_ids as $index => $file_id ) : ?>
							<?php
							$filename = basename( get_attached_file( $file_id ) );
							$url      = SDD_Orders::get_file_download_url( $token, $index );
							?>
							<li>
								<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $filename ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php elseif ( $has_files && $at_limit ) : ?>
				<p><?php esc_html_e( 'You have reached the download limit for this order. Please contact the seller if you need to download again.', 'simple-digital-downloads' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render delivered text with a copy button.
	 *
	 * @param string $text Delivered text content.
	 */
	private static function render_text_content( $text ) {
		?>
		<div class="sdd-delivery-text">
			<p><strong><?php esc_html_e( 'Your access details', 'simple-digital-downloads' ); ?></strong></p>
			<pre class="sdd-delivery-text-content"><?php echo esc_html( $text ); ?></pre>
			<p>
				<button type="button" class="button sdd-copy-text" data-copy-text="<?php echo esc_attr( $text ); ?>">
					<?php esc_html_e( 'Copy', 'simple-digital-downloads' ); ?>
				</button>
			</p>
		</div>
		<script>
		(function () {
			document.querySelectorAll('.sdd-copy-text').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var text = btn.getAttribute('data-copy-text') || '';
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(text);
					}
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Fallback: retrieve session from Stripe and create order inline.
	 *
	 * @param string $session_id Session ID.
	 * @return int|WP_Error
	 */
	public static function fallback_create_order( $session_id ) {
		$session = SDD_Stripe::retrieve_session( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		return SDD_Orders::create_from_session( $session );
	}
}
