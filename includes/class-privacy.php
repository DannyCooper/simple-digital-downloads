<?php
/**
 * GDPR privacy exporters and erasers.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy class.
 */
class SDD_Privacy {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Register personal data exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['simple-digital-downloads'] = array(
			'exporter_friendly_name' => __( 'Simple Digital Downloads Orders', 'simple-digital-downloads' ),
			'callback'               => array( __CLASS__, 'export_orders' ),
		);
		return $exporters;
	}

	/**
	 * Register personal data eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['simple-digital-downloads'] = array(
			'eraser_friendly_name' => __( 'Simple Digital Downloads Orders', 'simple-digital-downloads' ),
			'callback'             => array( __CLASS__, 'erase_orders' ),
		);
		return $erasers;
	}

	/**
	 * Export order data for an email address.
	 *
	 * @param string $email       Email address.
	 * @param int    $page        Page number.
	 * @return array
	 */
	public static function export_orders( $email, $page = 1 ) {
		$orders = get_posts(
			array(
				'post_type'      => 'sdd_order',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'paged'          => $page,
				'meta_query'     => array(
					array(
						'key'   => SDD_Orders::META_CUSTOMER_EMAIL,
						'value' => $email,
					),
				),
			)
		);

		$export_items = array();
		foreach ( $orders as $order ) {
			$data = array(
				array(
					'name'  => __( 'Download', 'simple-digital-downloads' ),
					'value' => SDD_Orders::get_download_name( $order->ID ),
				),
				array(
					'name'  => __( 'Amount', 'simple-digital-downloads' ),
					'value' => sdd_format_price(
						SDD_Orders::get_amount( $order->ID ),
						SDD_Orders::get_currency( $order->ID )
					),
				),
				array(
					'name'  => __( 'Status', 'simple-digital-downloads' ),
					'value' => SDD_Orders::get_status( $order->ID ),
				),
				array(
					'name'  => __( 'Date', 'simple-digital-downloads' ),
					'value' => $order->post_date,
				),
			);

			$delivered_text = SDD_Orders::get_delivered_text( $order->ID );
			if ( $delivered_text !== '' ) {
				$data[] = array(
					'name'  => __( 'Delivered text', 'simple-digital-downloads' ),
					'value' => $delivered_text,
				);
			}

			$export_items[] = array(
				'group_id'    => 'sdd-orders',
				'group_label' => __( 'Digital Download Orders', 'simple-digital-downloads' ),
				'item_id'     => 'order-' . $order->ID,
				'data'        => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $orders ) < 50,
		);
	}

	/**
	 * Erase order data for an email address.
	 *
	 * @param string $email       Email address.
	 * @param int    $page        Page number.
	 * @return array
	 */
	public static function erase_orders( $email, $page = 1 ) {
		$orders = get_posts(
			array(
				'post_type'      => 'sdd_order',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'paged'          => $page,
				'meta_query'     => array(
					array(
						'key'   => SDD_Orders::META_CUSTOMER_EMAIL,
						'value' => $email,
					),
				),
			)
		);

		$items_removed  = 0;
		$items_retained = 0;
		$messages       = array();

		foreach ( $orders as $order ) {
			SDD_Orders::clear_customer_email( $order->ID );
			SDD_Orders::clear_token( $order->ID );
			delete_post_meta( $order->ID, SDD_Orders::META_DELIVERED_TEXT );
			$items_removed++;
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => count( $orders ) < 50,
		);
	}
}
