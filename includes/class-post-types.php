<?php
/**
 * Custom post types and capabilities.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types registration.
 */
class SDD_Post_Types {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register CPTs and meta.
	 */
	public static function register() {
		register_post_type(
			'sdd_download',
			array(
				'labels'              => array(
					'name'               => __( 'Downloads', 'simple-digital-downloads' ),
					'singular_name'      => __( 'Download', 'simple-digital-downloads' ),
					'add_new'            => __( 'Add Download', 'simple-digital-downloads' ),
					'add_new_item'       => __( 'Add New Download', 'simple-digital-downloads' ),
					'edit_item'          => __( 'Edit Download', 'simple-digital-downloads' ),
					'new_item'           => __( 'New Download', 'simple-digital-downloads' ),
					'view_item'          => __( 'View Download', 'simple-digital-downloads' ),
					'search_items'       => __( 'Search Downloads', 'simple-digital-downloads' ),
					'not_found'          => __( 'No downloads found.', 'simple-digital-downloads' ),
					'not_found_in_trash' => __( 'No downloads found in Trash.', 'simple-digital-downloads' ),
					'menu_name'          => __( 'Downloads', 'simple-digital-downloads' ),
				),
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-download',
				'supports'            => array( 'title', 'editor', 'thumbnail' ),
				'has_archive'         => false,
				'rewrite'             => array( 'slug' => 'download' ),
				'capability_type'     => 'post',
				'template'            => array(
					array( 'simple-digital-downloads/buy-button' ),
				),
			)
		);

		register_post_type(
			'sdd_order',
			array(
				'labels'              => array(
					'name'          => __( 'Orders', 'simple-digital-downloads' ),
					'singular_name' => __( 'Order', 'simple-digital-downloads' ),
					'edit_item'     => __( 'View Order', 'simple-digital-downloads' ),
					'search_items'  => __( 'Search Orders', 'simple-digital-downloads' ),
					'not_found'     => __( 'No orders found.', 'simple-digital-downloads' ),
					'menu_name'     => __( 'Orders', 'simple-digital-downloads' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=sdd_download',
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'capability_type'     => array( 'sdd_order', 'sdd_orders' ),
				'map_meta_cap'        => true,
				'capabilities'        => array(
					'create_posts' => 'do_not_allow',
				),
				'supports'            => array( 'title' ),
			)
		);

		register_post_meta(
			'sdd_download',
			'_sdd_price',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_post_meta(
			'sdd_download',
			'_sdd_files',
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => function ( $value ) {
					if ( ! is_array( $value ) ) {
						return array();
					}
					return array_values( array_filter( array_map( 'absint', $value ) ) );
				},
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_post_meta(
			'sdd_download',
			'_sdd_text_content',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		$order_meta = array(
			'_sdd_customer_email'         => 'string',
			'_sdd_download_id'            => 'integer',
			'_sdd_download_name'          => 'string',
			'_sdd_amount'                 => 'integer',
			'_sdd_currency'               => 'string',
			'_sdd_stripe_session_id'      => 'string',
			'_sdd_stripe_payment_intent'  => 'string',
			'_sdd_token'                  => 'string',
			'_sdd_token_expires'          => 'integer',
			'_sdd_download_count'         => 'integer',
			'_sdd_status'                 => 'string',
			'_sdd_file_ids'               => 'array',
			'_sdd_delivered_text'         => 'string',
			'_sdd_email_sent'             => 'string',
			'_sdd_email_error'            => 'string',
		);

		foreach ( $order_meta as $key => $type ) {
			register_post_meta(
				'sdd_order',
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => false,
					'auth_callback'     => function () {
						return current_user_can( 'edit_sdd_orders' );
					},
				)
			);
		}

		register_rest_field(
			'sdd_download',
			'sdd_file_count',
			array(
				'get_callback' => function ( $post ) {
					$files = get_post_meta( $post['id'], '_sdd_files', true );
					return is_array( $files ) ? count( $files ) : 0;
				},
				'schema'       => array(
					'type'    => 'integer',
					'context' => array( 'view', 'edit' ),
				),
			)
		);

		register_rest_field(
			'sdd_download',
			'sdd_price_display',
			array(
				'get_callback' => function ( $post ) {
					$price    = (int) get_post_meta( $post['id'], '_sdd_price', true );
					$currency = get_option( 'sdd_currency', 'USD' );
					return sdd_format_price( $price, $currency );
				},
				'schema'       => array(
					'type'    => 'string',
					'context' => array( 'view', 'edit' ),
				),
			)
		);
	}

	/**
	 * Grant order capabilities to administrators on activation.
	 */
	public static function grant_capabilities() {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		$caps = array(
			'edit_sdd_order',
			'read_sdd_order',
			'delete_sdd_order',
			'edit_sdd_orders',
			'edit_others_sdd_orders',
			'read_private_sdd_orders',
			'delete_sdd_orders',
			'delete_others_sdd_orders',
			'delete_private_sdd_orders',
			'delete_published_sdd_orders',
			'edit_private_sdd_orders',
			'edit_published_sdd_orders',
		);
		foreach ( $caps as $cap ) {
			$role->add_cap( $cap );
		}
	}
}
