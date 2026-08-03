<?php
/**
 * Secure file download handler with streaming and range support.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Download handler.
 */
class SDD_Download_Handler {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_download' ) );
	}

	/**
	 * Validate download access for a token and optional file index.
	 *
	 * @param string   $token      Download token.
	 * @param int|null $file_index File index, or null to validate order access only.
	 * @return array|WP_Error {
	 *     @type WP_Post $order     Order post.
	 *     @type int[]   $file_ids  Attachment IDs.
	 *     @type int     $file_index Resolved file index when a file was requested.
	 *     @type string  $file_path  Resolved file path when a file was requested.
	 * }
	 */
	public static function validate_access( $token, $file_index = null ) {
		$order = SDD_Orders::find_by_token( $token );
		if ( ! $order ) {
			return new WP_Error( 'invalid_token', __( 'Invalid download link.', 'simple-digital-downloads' ) );
		}

		$stored_token = SDD_Orders::get_token( $order->ID );
		if ( ! $stored_token || ! hash_equals( $stored_token, $token ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid download link.', 'simple-digital-downloads' ) );
		}

		if ( 'refunded' === SDD_Orders::get_status( $order->ID ) ) {
			return new WP_Error( 'refunded', __( 'This order has been refunded.', 'simple-digital-downloads' ) );
		}

		$expires = SDD_Orders::get_token_expires( $order->ID );
		if ( $expires && time() > $expires ) {
			return new WP_Error( 'expired', __( 'This download link has expired.', 'simple-digital-downloads' ) );
		}

		$max_downloads = (int) get_option( 'sdd_max_downloads', 5 );
		if ( SDD_Orders::get_download_count( $order->ID ) >= $max_downloads ) {
			return new WP_Error( 'over_cap', __( 'Download limit reached.', 'simple-digital-downloads' ) );
		}

		$file_ids = SDD_Orders::get_file_ids( $order->ID );
		if ( empty( $file_ids ) ) {
			return new WP_Error( 'file_missing', __( 'No files found.', 'simple-digital-downloads' ) );
		}

		if ( null === $file_index ) {
			return array(
				'order'    => $order,
				'file_ids' => $file_ids,
			);
		}

		if ( $file_index < 0 || $file_index >= count( $file_ids ) ) {
			return new WP_Error( 'invalid_index', __( 'File not found.', 'simple-digital-downloads' ) );
		}

		$path = SDD_Uploads::get_attachment_path( $file_ids[ $file_index ], true );
		if ( ! $path ) {
			return new WP_Error( 'file_missing', __( 'File not found.', 'simple-digital-downloads' ) );
		}

		return array(
			'order'      => $order,
			'file_ids'   => $file_ids,
			'file_index' => $file_index,
			'file_path'  => $path,
		);
	}

	/**
	 * Handle download requests.
	 */
	public static function handle_download() {
		if ( empty( $_GET['sdd_download'] ) ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['sdd_download'] ) );

		if ( ! isset( $_GET['f'] ) ) {
			$result = self::validate_access( $token );
			if ( is_wp_error( $result ) ) {
				self::deny_access( $result );
			}

			if ( 1 === count( $result['file_ids'] ) ) {
				$file_result = self::validate_access( $token, 0 );
				if ( is_wp_error( $file_result ) ) {
					self::deny_access( $file_result );
				}
				self::stream_file( $file_result );
			}

			self::render_file_listing( $result['order'], $token, $result['file_ids'] );
			return;
		}

		if ( ! is_numeric( $_GET['f'] ) ) {
			wp_die( esc_html__( 'Invalid file index.', 'simple-digital-downloads' ), '', array( 'response' => 400 ) );
		}

		$result = self::validate_access( $token, (int) $_GET['f'] );
		if ( is_wp_error( $result ) ) {
			self::deny_access( $result );
		}

		self::stream_file( $result );
	}

	/**
	 * Map validation errors to HTTP responses.
	 *
	 * @param WP_Error $error Error.
	 */
	private static function deny_access( $error ) {
		$status = 403;
		if ( in_array( $error->get_error_code(), array( 'invalid_index', 'file_missing' ), true ) ) {
			$status = 404;
		}

		wp_die( esc_html( $error->get_error_message() ), '', array( 'response' => $status ) );
	}

	/**
	 * Render a file listing page.
	 *
	 * @param WP_Post $order    Order.
	 * @param string  $token    Token.
	 * @param array   $file_ids File IDs.
	 */
	private static function render_file_listing( $order, $token, $file_ids ) {
		$name = SDD_Orders::get_download_name( $order->ID );
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><title>' . esc_html( $name ) . '</title></head><body>';
		echo '<h1>' . esc_html( $name ) . '</h1><ul>';
		foreach ( $file_ids as $index => $file_id ) {
			$filename = basename( get_attached_file( $file_id ) );
			$url      = SDD_Orders::get_file_download_url( $token, $index );
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $filename ) . '</a></li>';
		}
		echo '</ul></body></html>';
		exit;
	}

	/**
	 * Stream a file with range support.
	 *
	 * @param array $result Validated access result from validate_access().
	 */
	private static function stream_file( $result ) {
		$order_id  = $result['order']->ID;
		$file_ids  = $result['file_ids'];
		$index     = $result['file_index'];
		$path      = $result['file_path'];
		$filename  = basename( $path );
		$size      = filesize( $path );
		$mime      = wp_check_filetype( $filename )['type'] ?: 'application/octet-stream';

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( 200 );
		nocache_headers();
		set_time_limit( 0 );

		$start  = 0;
		$end    = $size - 1;
		$length = $size;

		if ( isset( $_SERVER['HTTP_RANGE'] ) ) {
			if ( preg_match( '/bytes=(\d+)-(\d*)/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ), $matches ) ) {
				$start = (int) $matches[1];
				if ( '' !== $matches[2] ) {
					$end = (int) $matches[2];
				}
				if ( $start > $end || $start >= $size ) {
					header( 'HTTP/1.1 416 Requested Range Not Satisfiable' );
					header( "Content-Range: bytes */{$size}" );
					exit;
				}
				$length = $end - $start + 1;
				header( 'HTTP/1.1 206 Partial Content' );
				header( "Content-Range: bytes {$start}-{$end}/{$size}" );
			}
		}

		$safe_filename = str_replace( array( '"', "\r", "\n" ), '', $filename );

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . $length );

		if ( self::try_sendfile( $path, $start, $length ) ) {
			SDD_Orders::increment_download_count( $order_id );
			exit;
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'Could not open file.', 'simple-digital-downloads' ), '', array( 'response' => 500 ) );
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$remaining = $length;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, min( 1024 * 1024, $remaining ) );
			echo $chunk;
			flush();
			$remaining -= strlen( $chunk );
		}
		fclose( $handle );

		SDD_Orders::increment_download_count( $order_id );
		exit;
	}

	/**
	 * Try X-Sendfile or X-Accel-Redirect.
	 *
	 * @param string $path   File path.
	 * @param int    $start  Start byte.
	 * @param int    $length Content length.
	 * @return bool
	 */
	private static function try_sendfile( $path, $start, $length ) {
		// Apache mod_xsendfile — only available under Apache with the module loaded.
		if ( function_exists( 'apache_get_modules' ) && in_array( 'mod_xsendfile', apache_get_modules(), true ) ) {
			header( 'X-Sendfile: ' . $path );
			return true;
		}

		// Nginx X-Accel-Redirect — only attempt when the server software is nginx.
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		if ( stripos( $server_software, 'nginx' ) !== false && strpos( $path, WP_CONTENT_DIR ) !== false ) {
			$internal = '/internal' . str_replace( WP_CONTENT_DIR, '/wp-content', $path );
			header( 'X-Accel-Redirect: ' . $internal );
			header( 'Content-Length: ' . $length );
			return true;
		}

		return false;
	}
}
