<?php
/**
 * Plugin Name:       Simple Digital Downloads – Sell Files, Ebooks & PDFs with Stripe
 * Plugin URI:        https://wordpress.org/plugins/simple-digital-downloads/
 * Description:       Sell digital files with Stripe. Paste a restricted API key, set a price, and drop a buy button into any post.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Simple Digital Downloads
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       simple-digital-downloads
 * GitHub Plugin URI: DannyCooper/simple-digital-downloads
 * Primary Branch:    main
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

define( 'SDD_VERSION', '1.0.2' );
define( 'SDD_PLUGIN_FILE', __FILE__ );
define( 'SDD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SDD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SDD_STRIPE_API_VERSION', '2026-06-24.dahlia' );
define( 'SDD_REST_NAMESPACE', 'simple-digital-downloads/v1' );

require_once SDD_PLUGIN_DIR . 'includes/class-credentials.php';
require_once SDD_PLUGIN_DIR . 'includes/class-post-types.php';
require_once SDD_PLUGIN_DIR . 'includes/class-uploads.php';
require_once SDD_PLUGIN_DIR . 'includes/class-meta-boxes.php';
require_once SDD_PLUGIN_DIR . 'includes/class-stripe.php';
require_once SDD_PLUGIN_DIR . 'includes/class-orders.php';
require_once SDD_PLUGIN_DIR . 'includes/class-checkout.php';
require_once SDD_PLUGIN_DIR . 'includes/class-webhook.php';
require_once SDD_PLUGIN_DIR . 'includes/class-delivery.php';
require_once SDD_PLUGIN_DIR . 'includes/class-download-handler.php';
require_once SDD_PLUGIN_DIR . 'includes/class-admin.php';
require_once SDD_PLUGIN_DIR . 'includes/class-privacy.php';

/**
 * Zero-decimal currencies (no minor units).
 *
 * @return string[]
 */
function sdd_zero_decimal_currencies() {
	return array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
}

/**
 * Convert a decimal price string to minor units for storage.
 *
 * @param string $price    Decimal price from admin input.
 * @param string $currency ISO currency code.
 * @return int
 */
function sdd_price_to_minor( $price, $currency = 'USD' ) {
	$price = str_replace( ',', '.', trim( (string) $price ) );
	if ( ! is_numeric( $price ) ) {
		return 0;
	}
	if ( in_array( strtoupper( $currency ), sdd_zero_decimal_currencies(), true ) ) {
		return (int) round( (float) $price );
	}
	return (int) round( (float) $price * 100 );
}

/**
 * Format minor units for display.
 *
 * @param int    $minor    Amount in minor units.
 * @param string $currency ISO currency code.
 * @return string
 */
function sdd_price_from_minor( $minor, $currency = 'USD' ) {
	$minor = (int) $minor;
	if ( in_array( strtoupper( $currency ), sdd_zero_decimal_currencies(), true ) ) {
		return number_format( $minor, 0, '.', '' );
	}
	return number_format( $minor / 100, 2, '.', '' );
}

/**
 * Format minor units as a localized price string.
 *
 * @param int    $minor    Amount in minor units.
 * @param string $currency ISO currency code.
 * @return string
 */
function sdd_format_price( $minor, $currency = 'USD' ) {
	$currency = strtoupper( $currency );
	$amount   = sdd_price_from_minor( $minor, $currency );
	return $currency . ' ' . $amount;
}

/**
 * Generate a secure download token.
 *
 * @return string
 */
function sdd_generate_token() {
	return bin2hex( random_bytes( 32 ) );
}

/**
 * Get delivery text configured on a download.
 *
 * @param int $download_id Download post ID.
 * @return string
 */
function sdd_get_download_text_content( $download_id ) {
	return (string) get_post_meta( $download_id, '_sdd_text_content', true );
}

/**
 * Format delivery text for display: escape HTML and linkify URLs.
 *
 * @param string $text Plain-text delivery content.
 * @return string Safe HTML.
 */
function sdd_format_delivery_text( $text ) {
	$text = (string) $text;
	if ( $text === '' ) {
		return '';
	}

	$html = make_clickable( esc_html( $text ) );

	return wp_kses(
		$html,
		array(
			'a' => array(
				'href'   => true,
				'rel'    => true,
				'target' => true,
			),
		)
	);
}

/**
 * Whether a download has files or text to deliver.
 *
 * @param int $download_id Download post ID.
 * @return bool
 */
function sdd_download_has_deliverable( $download_id ) {
	$files = get_post_meta( $download_id, '_sdd_files', true );
	if ( is_array( $files ) && ! empty( $files ) ) {
		return true;
	}
	return sdd_get_download_text_content( $download_id ) !== '';
}

/**
 * Enqueue buy button block assets for shortcode and other non-block renders.
 */
function sdd_enqueue_buy_button_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	$style_handle = generate_block_asset_handle( 'simple-digital-downloads/buy-button', 'style' );
	$view_style_handle = generate_block_asset_handle( 'simple-digital-downloads/buy-button', 'viewStyle' );
	$view_script_handle = generate_block_asset_handle( 'simple-digital-downloads/buy-button', 'viewScript' );

	if ( wp_style_is( $style_handle, 'registered' ) ) {
		wp_enqueue_style( $style_handle );
	}

	if ( wp_style_is( $view_style_handle, 'registered' ) ) {
		wp_enqueue_style( $view_style_handle );
	}

	if ( wp_script_is( $view_script_handle, 'registered' ) ) {
		wp_enqueue_script( $view_script_handle );
	}
}

/**
 * Resolve a download ID from explicit attribute, block context, or current post.
 *
 * @param int           $download_id Explicit download ID.
 * @param WP_Block|null $block       Block instance.
 * @return int
 */
function sdd_resolve_download_id( $download_id, $block = null ) {
	$download_id = absint( $download_id );
	if ( $download_id ) {
		return $download_id;
	}

	if ( $block instanceof WP_Block && ! empty( $block->context['postId'] ) && 'sdd_download' === ( $block->context['postType'] ?? '' ) ) {
		return absint( $block->context['postId'] );
	}

	$post_id = get_the_ID();
	if ( $post_id && 'sdd_download' === get_post_type( $post_id ) ) {
		return $post_id;
	}

	return 0;
}

/**
 * Enable border controls for the buy button block in the editor.
 *
 * Border UI is gated by theme.json settings; plugins must opt in per block.
 *
 * @param WP_Theme_JSON_Data $theme_json Theme JSON data.
 * @return WP_Theme_JSON_Data
 */
function sdd_buy_button_theme_json( $theme_json ) {
	if ( ! method_exists( $theme_json, 'update_with' ) ) {
		return $theme_json;
	}

	return $theme_json->update_with(
		array(
			'version'  => 3,
			'settings' => array(
				'blocks' => array(
					'simple-digital-downloads/buy-button' => array(
						'border' => array(
							'color'  => true,
							'radius' => true,
							'style'  => true,
							'width'  => true,
						),
					),
				),
			),
		)
	);
}

/**
 * Attribute defaults from block.json (object defaults are stripped at registration).
 *
 * @return array<string, mixed>
 */
function sdd_get_buy_button_attribute_defaults() {
	static $defaults = null;

	if ( null !== $defaults ) {
		return $defaults;
	}

	$defaults  = array();
	$block_json = SDD_PLUGIN_DIR . 'build/blocks/buy-button/block.json';

	if ( is_readable( $block_json ) ) {
		$metadata = json_decode( file_get_contents( $block_json ), true );
		if ( is_array( $metadata['attributes'] ?? null ) ) {
			foreach ( $metadata['attributes'] as $name => $config ) {
				if ( array_key_exists( 'default', $config ) ) {
					$defaults[ $name ] = $config['default'];
				}
			}
		}
	}

	return $defaults;
}

/**
 * Deep-merge block.json defaults into the block currently being rendered.
 *
 * WordPress only fills missing top-level attributes on the server, and object-type
 * defaults are not kept on the registered block type at all.
 *
 * @param WP_Block $block Block instance.
 */
function sdd_apply_block_attribute_defaults( WP_Block $block ) {
	if ( 'simple-digital-downloads/buy-button' !== $block->name ) {
		return;
	}

	if ( ! class_exists( 'WP_Block_Supports' ) || ! is_array( WP_Block_Supports::$block_to_render ) ) {
		return;
	}

	$schema_defaults = sdd_get_buy_button_attribute_defaults();
	$schema          = $block->block_type->attributes ?? array();
	$attrs           = WP_Block_Supports::$block_to_render['attrs'] ?? array();

	foreach ( $schema as $name => $config ) {
		$default = $schema_defaults[ $name ] ?? ( $config['default'] ?? null );
		if ( null === $default ) {
			continue;
		}

		if ( 'style' === $name && is_array( $default ) ) {
			$current        = isset( $attrs['style'] ) && is_array( $attrs['style'] ) ? $attrs['style'] : array();
			$attrs['style'] = array_replace_recursive( $default, $current );
		} elseif ( ! array_key_exists( $name, $attrs ) ) {
			$attrs[ $name ] = $default;
		}
	}

	WP_Block_Supports::$block_to_render['attrs'] = $attrs;
}

/**
 * Render a buy button for a download.
 *
 * Shared by the block render callback and the [sdd_buy_button] shortcode.
 *
 * @param int           $download_id Download post ID.
 * @param string        $button_text Custom button text (empty for default).
 * @param bool          $show_price  Whether to append the price.
 * @param WP_Block|null $block       Block instance (applies editor supports on the wrapper).
 * @return string
 */
function sdd_render_buy_button( $download_id, $button_text = '', $show_price = true, $block = null ) {
	$download_id = sdd_resolve_download_id( $download_id, $block );

	if ( ! $download_id ) {
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return '<p class="sdd-block-notice">' . esc_html__( 'Select a download for this buy button.', 'simple-digital-downloads' ) . '</p>';
		}
		return '';
	}

	$download = get_post( $download_id );
	if ( ! $download || 'sdd_download' !== $download->post_type || 'publish' !== $download->post_status ) {
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return '<p class="sdd-block-notice">' . esc_html__( 'Selected download is not available.', 'simple-digital-downloads' ) . '</p>';
		}
		return '';
	}

	$price    = (int) get_post_meta( $download_id, '_sdd_price', true );
	$currency = get_option( 'sdd_currency', 'USD' );
	$is_free  = $price <= 0;

	if ( ! $is_free && ! SDD_Credentials::is_connected() ) {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return '<p class="sdd-block-notice">' . esc_html__( 'Stripe is not configured. Go to Downloads → Settings.', 'simple-digital-downloads' ) . '</p>';
		}
		return '';
	}

	if ( ! sdd_download_has_deliverable( $download_id ) ) {
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return '<p class="sdd-block-notice">' . esc_html__( 'Add files or delivery text to this download before the buy button can appear.', 'simple-digital-downloads' ) . '</p>';
		}
		return '';
	}

	sdd_enqueue_buy_button_assets();

	if ( empty( $button_text ) ) {
		$button_text = $is_free
			? __( 'Download Free', 'simple-digital-downloads' )
			: __( 'Buy Now', 'simple-digital-downloads' );
	}

	$price_suffix = '';
	if ( $show_price && ! $is_free ) {
		$price_suffix = ' — ' . sdd_format_price( $price, $currency );
	}

	$wrapper_attrs = sprintf(
		'class="sdd-buy-button-wrapper" data-download-id="%1$s" data-checkout-url="%2$s" data-free="%3$s"',
		esc_attr( (string) $download_id ),
		esc_url( rest_url( SDD_REST_NAMESPACE . '/checkout' ) ),
		$is_free ? '1' : '0'
	);

	$button_attrs = array(
		'class'            => 'sdd-buy-button',
		'type'             => 'button',
		'data-download-id' => (string) $download_id,
	);

	if ( $block instanceof WP_Block ) {
		sdd_apply_block_attribute_defaults( $block );
		$button = get_block_wrapper_attributes( $button_attrs );
	} else {
		$button = '';
		foreach ( $button_attrs as $name => $value ) {
			$button .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}
		$button = trim( $button );
	}

	ob_start();
	?>
	<div <?php echo $wrapper_attrs; ?>>
		<button <?php echo $button; ?>>
			<?php echo esc_html( $button_text . $price_suffix ); ?>
		</button>
		<?php if ( $is_free ) : ?>
			<div class="sdd-email-overlay" hidden aria-hidden="true">
				<button type="button" class="sdd-email-overlay-backdrop" tabindex="-1" aria-label="<?php esc_attr_e( 'Close', 'simple-digital-downloads' ); ?>"></button>
				<div class="sdd-email-popover" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( 'sdd-email-title-' . $download_id ); ?>">
					<button type="button" class="sdd-email-close" aria-label="<?php esc_attr_e( 'Close', 'simple-digital-downloads' ); ?>">
						<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M4 4L12 12M12 4L4 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
						</svg>
					</button>
					<h2 class="sdd-email-popover-title" id="<?php echo esc_attr( 'sdd-email-title-' . $download_id ); ?>">
						<?php esc_html_e( 'Where should we send your free download?', 'simple-digital-downloads' ); ?>
					</h2>
					<p class="sdd-email-popover-description">
						<?php esc_html_e( 'Enter your email and we\'ll send you a secure download link.', 'simple-digital-downloads' ); ?>
					</p>
					<div class="sdd-email-form">
						<label class="sdd-email-field-label" for="<?php echo esc_attr( 'sdd-email-input-' . $download_id ); ?>">
							<?php esc_html_e( 'Email address', 'simple-digital-downloads' ); ?>
						</label>
						<input type="email" id="<?php echo esc_attr( 'sdd-email-input-' . $download_id ); ?>" class="sdd-email-input" placeholder="<?php esc_attr_e( 'you@example.com', 'simple-digital-downloads' ); ?>" autocomplete="email" required>
						<button type="button" class="sdd-email-submit">
							<?php esc_html_e( 'Send my download', 'simple-digital-downloads' ); ?>
						</button>
					</div>
					<p class="sdd-buy-error" hidden></p>
				</div>
			</div>
		<?php else : ?>
			<p class="sdd-buy-error" hidden></p>
		<?php endif; ?>
		<noscript>
			<p><?php esc_html_e( 'JavaScript is required to complete your purchase.', 'simple-digital-downloads' ); ?></p>
		</noscript>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Buy button shortcode.
 *
 * Usage: [sdd_buy_button id="123" text="Get it now" show_price="yes"]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function sdd_buy_button_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id'         => 0,
			'text'       => '',
			'show_price' => 'yes',
		),
		$atts,
		'sdd_buy_button'
	);

	return sdd_render_buy_button(
		absint( $atts['id'] ),
		sanitize_text_field( $atts['text'] ),
		in_array( strtolower( $atts['show_price'] ), array( 'yes', '1', 'true' ), true )
	);
}
add_shortcode( 'sdd_buy_button', 'sdd_buy_button_shortcode' );

register_activation_hook( SDD_PLUGIN_FILE, function () {
	SDD_Post_Types::register();
	SDD_Post_Types::grant_capabilities();
	SDD_Uploads::ensure_protected_directory();
	SDD_Uploads::run_protection_self_check();
	SDD_Delivery::ensure_delivery_page();
	flush_rewrite_rules();
} );

register_deactivation_hook( SDD_PLUGIN_FILE, function () {
	wp_clear_scheduled_hook( 'sdd_protection_self_check' );
	wp_clear_scheduled_hook( 'sdd_reconcile_sessions' );
	flush_rewrite_rules();
} );

add_action( 'plugins_loaded', function () {
	SDD_Post_Types::init();
	SDD_Uploads::init();
	SDD_Meta_Boxes::init();
	SDD_Admin::init();
	SDD_Orders::init();
	SDD_Checkout::init();
	SDD_Webhook::init();
	SDD_Delivery::init();
	SDD_Download_Handler::init();
	SDD_Privacy::init();

	add_filter( 'wp_theme_json_data_theme', 'sdd_buy_button_theme_json' );
	add_action( 'init', function () {
		$block_dir = SDD_PLUGIN_DIR . 'build/blocks/buy-button';
		if ( file_exists( $block_dir . '/block.json' ) ) {
			register_block_type( $block_dir );
		}
	} );
	add_action( 'enqueue_block_editor_assets', 'sdd_enqueue_buy_button_editor_data' );
} );

/**
 * Pass download settings to the buy button block editor script.
 */
function sdd_enqueue_buy_button_editor_data() {
	$handle = generate_block_asset_handle( 'simple-digital-downloads/buy-button', 'editorScript' );
	if ( ! wp_script_is( $handle, 'registered' ) ) {
		return;
	}
	wp_localize_script(
		$handle,
		'sddBuyButtonEditor',
		array(
			'currency' => get_option( 'sdd_currency', 'USD' ),
		)
	);
}
