<?php
/**
 * Download meta boxes — price and file repeater.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta boxes.
 */
class SDD_Meta_Boxes {

	const META_STRIPE_PRODUCT_ID_LIVE = '_sdd_stripe_product_id_live';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_sdd_download', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_sdd_create_stripe_product', array( __CLASS__, 'ajax_create_stripe_product' ) );
	}

	/**
	 * Get the Stripe product ID for a download in the current Stripe mode.
	 *
	 * Only live mode is supported, so test-mode checkouts fall back to an
	 * inline product.
	 *
	 * @param int $download_id Download post ID.
	 * @return string
	 */
	public static function get_stripe_product_id( $download_id ) {
		if ( 'live' !== SDD_Credentials::get_mode() ) {
			return '';
		}
		return (string) get_post_meta( $download_id, self::META_STRIPE_PRODUCT_ID_LIVE, true );
	}

	/**
	 * AJAX: create a Stripe product for a download.
	 */
	public static function ajax_create_stripe_product() {
		check_ajax_referer( 'sdd_create_stripe_product', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'sdd_download' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'simple-digital-downloads' ) ), 403 );
		}

		if ( 'live' !== SDD_Credentials::get_mode() ) {
			wp_send_json_error( array( 'message' => __( 'Stripe products can only be created with a live API key.', 'simple-digital-downloads' ) ) );
		}

		$existing = get_post_meta( $post_id, self::META_STRIPE_PRODUCT_ID_LIVE, true );
		if ( $existing ) {
			wp_send_json_success( array( 'product_id' => $existing ) );
		}

		$name = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( '' === $name ) {
			$name = $post->post_title;
		}
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Add a title to the download first.', 'simple-digital-downloads' ) ) );
		}

		$product = SDD_Stripe::create_product(
			array(
				'name'     => $name,
				'metadata' => array(
					'download_id' => (string) $post_id,
					'site_url'    => home_url(),
				),
			)
		);

		if ( is_wp_error( $product ) ) {
			$message = $product->get_error_message();
			$data    = $product->get_error_data();
			if ( is_array( $data ) && 403 === ( $data['status'] ?? 0 ) ) {
				$message .= ' ' . __( 'Add the "Products — Write" permission to your restricted key in the Stripe Dashboard.', 'simple-digital-downloads' );
			}
			wp_send_json_error( array( 'message' => $message ) );
		}

		if ( empty( $product['id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Stripe API error.', 'simple-digital-downloads' ) ) );
		}

		update_post_meta( $post_id, self::META_STRIPE_PRODUCT_ID_LIVE, sanitize_text_field( $product['id'] ) );

		wp_send_json_success( array( 'product_id' => $product['id'] ) );
	}

	/**
	 * Register meta boxes.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'sdd_download_details',
			__( 'Download Details', 'simple-digital-downloads' ),
			array( __CLASS__, 'render_meta_box' ),
			'sdd_download',
			'normal',
			'high'
		);
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue_admin_assets( $hook ) {
		global $post;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $post || 'sdd_download' !== $post->post_type ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script(
			'sdd-meta-boxes',
			SDD_PLUGIN_URL . 'assets/js/meta-boxes.js',
			array( 'jquery', 'jquery-ui-sortable', 'media-editor' ),
			SDD_VERSION,
			true
		);
		wp_enqueue_style(
			'sdd-meta-boxes',
			SDD_PLUGIN_URL . 'assets/css/meta-boxes.css',
			array(),
			SDD_VERSION
		);
		wp_localize_script(
			'sdd-meta-boxes',
			'sddMetaBoxes',
			array(
				'selectFile'            => __( 'Select File', 'simple-digital-downloads' ),
				'changeFile'            => __( 'Change File', 'simple-digital-downloads' ),
				'remove'                => __( 'Remove', 'simple-digital-downloads' ),
				'currency'              => get_option( 'sdd_currency', 'USD' ),
				'zeroDecimalCurrencies' => sdd_zero_decimal_currencies(),
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
				'postId'                => $post->ID,
				'stripeProductNonce'    => wp_create_nonce( 'sdd_create_stripe_product' ),
				'creating'              => __( 'Creating…', 'simple-digital-downloads' ),
				'copy'                  => __( 'Copy', 'simple-digital-downloads' ),
				'copied'                => __( 'Copied', 'simple-digital-downloads' ),
				'requestFailed'         => __( 'Request failed. Please try again.', 'simple-digital-downloads' ),
			)
		);
	}

	/**
	 * Render meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'sdd_save_download', 'sdd_download_nonce' );

		$currency = get_option( 'sdd_currency', 'USD' );
		$price    = get_post_meta( $post->ID, '_sdd_price', true );
		$display  = $price ? sdd_price_from_minor( (int) $price, $currency ) : '';
		$files    = get_post_meta( $post->ID, '_sdd_files', true );
		$text     = get_post_meta( $post->ID, '_sdd_text_content', true );
		if ( ! is_array( $files ) ) {
			$files = array();
		}
		?>
		<p>
			<label for="sdd_price"><strong><?php esc_html_e( 'Price', 'simple-digital-downloads' ); ?></strong></label><br>
			<input type="text" id="sdd_price" name="sdd_price" value="<?php echo esc_attr( $display ); ?>" class="regular-text" placeholder="9.99">
			<span class="description"><?php echo esc_html( sprintf( __( 'Currency: %s (set in Downloads → Settings)', 'simple-digital-downloads' ), $currency ) ); ?></span>
		</p>

		<p>
			<label for="sdd_text_content"><strong><?php esc_html_e( 'Delivery text', 'simple-digital-downloads' ); ?></strong></label><br>
			<textarea id="sdd_text_content" name="sdd_text_content" rows="4" class="large-text"><?php echo esc_textarea( $text ); ?></textarea>
			<span class="description"><?php esc_html_e( 'Optional. Shown to the buyer after purchase — e.g. a password, license key, or access instructions. URLs become clickable links. Can be used alone or alongside files.', 'simple-digital-downloads' ); ?></span>
		</p>

		<div id="sdd-files-repeater">
			<p><strong><?php esc_html_e( 'Files', 'simple-digital-downloads' ); ?></strong></p>
			<ul id="sdd-files-list" class="sdd-files-list">
				<?php foreach ( $files as $file_id ) : ?>
					<?php self::render_file_row( (int) $file_id ); ?>
				<?php endforeach; ?>
			</ul>
			<p>
				<button type="button" class="button" id="sdd-add-file"><?php esc_html_e( 'Add File', 'simple-digital-downloads' ); ?></button>
			</p>
		</div>

		<script type="text/html" id="tmpl-sdd-file-row">
			<?php self::render_file_row( 0, true ); ?>
		</script>

		<?php self::render_stripe_product( $post ); ?>
		<?php
	}

	/**
	 * Render the Stripe product ID section.
	 *
	 * @param WP_Post $post Post object.
	 */
	private static function render_stripe_product( $post ) {
		$product_id = get_post_meta( $post->ID, self::META_STRIPE_PRODUCT_ID_LIVE, true );
		$is_live    = 'live' === SDD_Credentials::get_mode();
		?>
		<div id="sdd-stripe-product">
			<p><strong><?php esc_html_e( 'Stripe Product', 'simple-digital-downloads' ); ?></strong></p>
			<p class="sdd-stripe-product-id"<?php echo $product_id ? '' : ' hidden'; ?>>
				<code id="sdd-stripe-product-id"><?php echo esc_html( $product_id ); ?></code>
				<button type="button" class="button" id="sdd-copy-stripe-product"><?php esc_html_e( 'Copy', 'simple-digital-downloads' ); ?></button>
			</p>
			<?php if ( ! $product_id ) : ?>
				<?php if ( $is_live ) : ?>
					<p class="sdd-stripe-product-create">
						<button type="button" class="button" id="sdd-create-stripe-product"><?php esc_html_e( 'Create Stripe Product', 'simple-digital-downloads' ); ?></button>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Connect a live Stripe API key to create a Stripe product.', 'simple-digital-downloads' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<p class="sdd-stripe-product-error" hidden></p>
			<span class="description"><?php esc_html_e( 'Optional. Gives this download a permanent Stripe product ID for integrations such as BookFunnel. Requires the "Products — Write" permission on your restricted key.', 'simple-digital-downloads' ); ?></span>
		</div>
		<?php
	}

	/**
	 * Render a single file row.
	 *
	 * @param int  $file_id Attachment ID.
	 * @param bool $is_template Whether this is a JS template.
	 */
	private static function render_file_row( $file_id, $is_template = false ) {
		$filename = '';
		if ( $file_id ) {
			$filename = basename( get_attached_file( $file_id ) );
		}
		$id_attr = $is_template ? '{{ data.id }}' : esc_attr( (string) $file_id );
		?>
		<li class="sdd-file-row">
			<span class="sdd-drag-handle dashicons dashicons-move"></span>
			<input type="hidden" name="sdd_files[]" value="<?php echo esc_attr( $is_template ? '{{ data.id }}' : (string) $file_id ); ?>">
			<span class="sdd-file-name"><?php echo esc_html( $is_template ? '{{ data.filename }}' : $filename ); ?></span>
			<button type="button" class="button sdd-select-file"><?php esc_html_e( 'Select File', 'simple-digital-downloads' ); ?></button>
			<button type="button" class="button sdd-remove-file"><?php esc_html_e( 'Remove', 'simple-digital-downloads' ); ?></button>
		</li>
		<?php
	}

	/**
	 * Persist download meta from request values.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Meta values.
	 */
	public static function persist_download_meta( $post_id, $data ) {
		if ( isset( $data['price'] ) ) {
			$currency = get_option( 'sdd_currency', 'USD' );
			$price    = sdd_price_to_minor( sanitize_text_field( (string) $data['price'] ), $currency );
			update_post_meta( $post_id, '_sdd_price', $price );
		}

		if ( isset( $data['files'] ) ) {
			$file_ids = SDD_Uploads::sanitize_download_file_ids( $data['files'] );
			update_post_meta( $post_id, '_sdd_files', $file_ids );
		}

		if ( isset( $data['text_content'] ) ) {
			$text = sanitize_textarea_field( (string) $data['text_content'] );
			update_post_meta( $post_id, '_sdd_text_content', $text );
		}
	}

	/**
	 * Save meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['sdd_download_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sdd_download_nonce'] ) ), 'sdd_save_download' ) ) {
			return;
		}

		self::persist_download_meta(
			$post_id,
			array(
				'price'        => wp_unslash( $_POST['sdd_price'] ?? '' ),
				'files'        => isset( $_POST['sdd_files'] ) && is_array( $_POST['sdd_files'] ) ? wp_unslash( $_POST['sdd_files'] ) : array(),
				'text_content' => wp_unslash( $_POST['sdd_text_content'] ?? '' ),
			)
		);
	}
}
