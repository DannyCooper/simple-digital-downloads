<?php
/**
 * Buy button server-side render.
 *
 * @package SimpleDigitalDownloads
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

echo sdd_render_buy_button(
	absint( $attributes['downloadId'] ?? 0 ),
	sanitize_text_field( $attributes['buttonText'] ?? '' ),
	$attributes['showPrice'] ?? true,
	$block
);
