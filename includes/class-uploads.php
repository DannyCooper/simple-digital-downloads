<?php
/**
 * Protected file storage and attachment leakage mitigation.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Uploads handler.
 */
class SDD_Uploads {

	const META_PROTECTED     = '_sdd_protected';
	const META_ORIGINAL_NAME = '_sdd_original_filename';

	/**
	 * Whether a protected file is currently being served.
	 *
	 * @var bool
	 */
	private static $serving_download = false;

	/**
	 * Whether the current upload targets the protected directory.
	 *
	 * @var bool
	 */
	private static $upload_to_protected = false;

	/**
	 * Original filename of the file currently being uploaded to the protected directory.
	 *
	 * @var string
	 */
	private static $pending_original_name = '';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );
		add_action( 'add_attachment', array( __CLASS__, 'mark_protected_attachment' ) );
		add_filter( 'rest_attachment_query', array( __CLASS__, 'exclude_protected_from_rest' ), 10, 2 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'hide_protected_url' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_protected_from_search' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'exclude_protected_from_sitemaps' ), 10, 2 );
		add_action( 'sdd_protection_self_check', array( __CLASS__, 'run_protection_self_check' ) );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'filter_media_library' ) );
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'capture_original_filename' ) );
		add_filter( 'wp_unique_filename', array( __CLASS__, 'randomize_protected_filename' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_filenames' ) );

		if ( ! wp_next_scheduled( 'sdd_protection_self_check' ) ) {
			wp_schedule_event( time(), 'weekly', 'sdd_protection_self_check' );
		}
	}

	/**
	 * Get protected subdirectory name.
	 *
	 * @return string
	 */
	public static function get_subdir_name() {
		$key = get_option( 'sdd_upload_dir_key', '' );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 20, false );
			update_option( 'sdd_upload_dir_key', $key, false );
		}
		return 'sdd-protected-' . $key;
	}

	/**
	 * Get absolute path to protected directory.
	 *
	 * @return string
	 */
	public static function get_protected_path() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . self::get_subdir_name();
	}

	/**
	 * Get public URL to protected directory (should be blocked).
	 *
	 * @return string
	 */
	public static function get_protected_url() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . self::get_subdir_name();
	}

	/**
	 * Ensure protected directory exists with server config files.
	 */
	public static function ensure_protected_directory() {
		$path = self::get_protected_path();
		if ( ! file_exists( $path ) ) {
			wp_mkdir_p( $path );
		}

		$htaccess = $path . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "<IfModule mod_authz_core.c>\n";
			$rules .= "Require all denied\n";
			$rules .= "</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\n";
			$rules .= "Order deny,allow\n";
			$rules .= "Deny from all\n";
			$rules .= "</IfModule>\n";
			file_put_contents( $htaccess, $rules );
		}

		$webconfig = $path . '/web.config';
		if ( ! file_exists( $webconfig ) ) {
			$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
			$xml .= '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>';
			file_put_contents( $webconfig, $xml );
		}

		$index = $path . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Filter upload directory for protected file uploads.
	 *
	 * @param array $dirs Upload dir array.
	 * @return array
	 */
	public static function filter_upload_dir( $dirs ) {
		$protected = self::$upload_to_protected || ! empty( $_REQUEST['sdd_protected'] );
		if ( ! $protected ) {
			return $dirs;
		}
		self::$upload_to_protected = true;
		self::ensure_protected_directory();
		$subdir = '/' . self::get_subdir_name();
		$dirs['path']   = $dirs['basedir'] . $subdir;
		$dirs['url']    = $dirs['baseurl'] . $subdir;
		$dirs['subdir'] = $subdir;
		return $dirs;
	}

	/**
	 * Move an attachment into the protected directory and mark it protected.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function protect_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		if ( get_post_meta( $attachment_id, self::META_PROTECTED, true ) ) {
			return true;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return false;
		}

		self::ensure_protected_directory();
		$protected_path = wp_normalize_path( self::get_protected_path() );
		$file           = wp_normalize_path( $file );

		if ( 0 === strpos( $file, $protected_path ) ) {
			update_post_meta( $attachment_id, self::META_PROTECTED, 1 );
			return true;
		}

		if ( ! self::relocate_attachment( $attachment_id, $file ) ) {
			return false;
		}

		update_post_meta( $attachment_id, self::META_PROTECTED, 1 );
		return true;
	}

	/**
	 * Remember the original name of a file being uploaded for a download.
	 *
	 * @param array $file Uploaded file data.
	 * @return array
	 */
	public static function capture_original_filename( $file ) {
		if ( ! empty( $_REQUEST['sdd_protected'] ) && ! empty( $file['name'] ) ) {
			self::$pending_original_name = sanitize_file_name( $file['name'] );
		}
		return $file;
	}

	/**
	 * Give files in the protected directory unguessable storage names.
	 *
	 * @param string $filename Filename WordPress settled on.
	 * @param string $ext      Extension including the leading dot.
	 * @param string $dir      Target directory.
	 * @return string
	 */
	public static function randomize_protected_filename( $filename, $ext, $dir ) {
		$protected = trailingslashit( wp_normalize_path( self::get_protected_path() ) );
		if ( 0 !== strpos( trailingslashit( wp_normalize_path( $dir ) ), $protected ) ) {
			return $filename;
		}
		return bin2hex( random_bytes( 16 ) ) . strtolower( $ext );
	}

	/**
	 * Filename shown to admins and buyers (the original name, not the storage name).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function get_display_filename( $attachment_id ) {
		$original = get_post_meta( $attachment_id, self::META_ORIGINAL_NAME, true );
		if ( $original ) {
			return $original;
		}
		$file = get_attached_file( $attachment_id );
		return $file ? basename( $file ) : '';
	}

	/**
	 * Move a file into the protected directory under a random name.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          Current absolute path.
	 * @return bool
	 */
	private static function relocate_attachment( $attachment_id, $file ) {
		self::ensure_protected_directory();
		$protected_path = wp_normalize_path( self::get_protected_path() );
		$file           = wp_normalize_path( $file );
		$filename       = wp_unique_filename( $protected_path, basename( $file ) );
		$new_file       = trailingslashit( $protected_path ) . $filename;
		$old_meta       = wp_get_attachment_metadata( $attachment_id );

		if ( ! @rename( $file, $new_file ) ) {
			return false;
		}

		if ( ! get_post_meta( $attachment_id, self::META_ORIGINAL_NAME, true ) ) {
			update_post_meta( $attachment_id, self::META_ORIGINAL_NAME, basename( $file ) );
		}

		update_attached_file( $attachment_id, $new_file );

		$upload   = wp_upload_dir();
		$relative = ltrim( str_replace( wp_normalize_path( $upload['basedir'] ), '', $new_file ), '/' );
		update_post_meta( $attachment_id, '_wp_attached_file', $relative );

		wp_update_post(
			array(
				'ID'   => $attachment_id,
				'guid' => trailingslashit( self::get_protected_url() ) . $filename,
			)
		);

		// Remove generated image sizes left behind at the old location.
		if ( ! empty( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ) {
			foreach ( $old_meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					@unlink( trailingslashit( dirname( $file ) ) . $size['file'] );
				}
			}
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $new_file );
		if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return true;
	}

	/**
	 * Protected attachments that still use their original, guessable filename.
	 *
	 * @param int $limit Maximum IDs to return (-1 for all).
	 * @return int[]
	 */
	private static function get_unrenamed_attachment_ids( $limit = -1 ) {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => self::META_PROTECTED,
						'value' => '1',
					),
					array(
						'key'     => self::META_ORIGINAL_NAME,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
	}

	/**
	 * Rename protected files uploaded by earlier versions to random names.
	 */
	public static function maybe_migrate_filenames() {
		if ( get_option( 'sdd_filenames_migrated' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ids = self::get_unrenamed_attachment_ids( 25 );
		foreach ( $ids as $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( ! $file || ! file_exists( $file ) ) {
				update_post_meta( $attachment_id, self::META_ORIGINAL_NAME, $file ? basename( $file ) : '' );
				continue;
			}
			self::relocate_attachment( $attachment_id, $file );
		}

		if ( empty( self::get_unrenamed_attachment_ids( 1 ) ) ) {
			update_option( 'sdd_filenames_migrated', 1, false );
		}
	}

	/**
	 * Sanitize and protect a list of download file attachment IDs.
	 *
	 * @param mixed $value Raw file ID list.
	 * @return int[]
	 */
	public static function sanitize_download_file_ids( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$file_ids = array();
		foreach ( array_values( array_filter( array_map( 'absint', $value ) ) ) as $file_id ) {
			if ( $file_id && self::protect_attachment( $file_id ) ) {
				$file_ids[] = $file_id;
			}
		}

		return $file_ids;
	}

	/**
	 * Mark attachment as protected.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function mark_protected_attachment( $attachment_id ) {
		if ( self::$upload_to_protected ) {
			update_post_meta( $attachment_id, self::META_PROTECTED, 1 );
			if ( '' !== self::$pending_original_name ) {
				update_post_meta( $attachment_id, self::META_ORIGINAL_NAME, self::$pending_original_name );
				self::$pending_original_name = '';
			}
		}
	}

	/**
	 * Exclude protected attachments from REST API.
	 *
	 * @param array           $args    Query args.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function exclude_protected_from_rest( $args, $request ) {
		if ( current_user_can( 'edit_sdd_orders' ) ) {
			return $args;
		}
		$args['meta_query']   = $args['meta_query'] ?? array();
		$args['meta_query'][] = self::get_exclusion_meta_query();
		return $args;
	}

	/**
	 * Meta query that excludes protected attachments.
	 *
	 * @return array
	 */
	private static function get_exclusion_meta_query() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::META_PROTECTED,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META_PROTECTED,
				'value'   => '1',
				'compare' => '!=',
			),
		);
	}

	/**
	 * Meta query that matches only protected attachments.
	 *
	 * @return array
	 */
	private static function get_protected_only_meta_query() {
		return array(
			'key'   => self::META_PROTECTED,
			'value' => '1',
		);
	}

	/**
	 * Return empty URL for protected attachments outside delivery.
	 *
	 * @param string $url           Attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public static function hide_protected_url( $url, $attachment_id ) {
		if ( self::$serving_download ) {
			return $url;
		}
		if ( get_post_meta( $attachment_id, self::META_PROTECTED, true ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Exclude protected attachments from front-end search.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function exclude_protected_from_search( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}
		$meta_query   = $query->get( 'meta_query' ) ?: array();
		$meta_query[] = self::get_exclusion_meta_query();
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Exclude protected attachments from sitemaps.
	 *
	 * @param array  $args      Query args.
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function exclude_protected_from_sitemaps( $args, $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return $args;
		}
		$args['meta_query']   = $args['meta_query'] ?? array();
		$args['meta_query'][] = self::get_exclusion_meta_query();
		return $args;
	}

	/**
	 * Run protection self-check and store result.
	 *
	 * Result states:
	 * - yes:      direct requests to the protected folder are blocked.
	 * - obscured: direct requests are served, but every file has an unguessable name.
	 * - no:       direct requests are served and some files have guessable names.
	 * - unknown:  the check could not get a clear answer.
	 *
	 * @return string The stored state.
	 */
	public static function run_protection_self_check() {
		self::ensure_protected_directory();
		$path     = self::get_protected_path();
		$canary   = bin2hex( random_bytes( 8 ) ) . '.txt';
		$filepath = $path . '/' . $canary;
		file_put_contents( $filepath, 'canary' );

		$url      = trailingslashit( self::get_protected_url() ) . $canary;
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => false,
			)
		);

		@unlink( $filepath );

		$state  = 'unknown';
		$server = '';
		if ( ! is_wp_error( $response ) ) {
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$server = sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'server' ) );
			if ( 200 === $code ) {
				$state = empty( self::get_unrenamed_attachment_ids( 1 ) ) ? 'obscured' : 'no';
			} elseif ( in_array( $code, array( 401, 403, 404, 410 ), true ) ) {
				$state = 'yes';
			}
		}

		$previous = get_option( 'sdd_file_protection_ok', 'unknown' );

		update_option( 'sdd_file_protection_ok', $state, false );
		update_option( 'sdd_file_protection_server', $server, false );
		update_option( 'sdd_file_protection_checked', time(), false );

		// Only nag when the situation newly becomes risky, not every week.
		if ( 'no' === $state && 'no' !== $previous ) {
			set_transient( 'sdd_protection_notice', 1, WEEK_IN_SECONDS );
		} elseif ( 'no' !== $state ) {
			delete_transient( 'sdd_protection_notice' );
		}

		return $state;
	}

	/**
	 * Describe the current file protection state for the settings screen.
	 *
	 * @return array{state:string,server:string,nginx:bool}
	 */
	public static function get_protection_report() {
		$server = (string) get_option( 'sdd_file_protection_server', '' );
		return array(
			'state'      => (string) get_option( 'sdd_file_protection_ok', 'unknown' ),
			'server'     => $server,
			'nginx'      => false !== stripos( $server, 'nginx' ),
		);
	}

	/**
	 * Limit media library to protected files when uploading for downloads.
	 *
	 * @param array $query Query args.
	 * @return array
	 */
	public static function filter_media_library( $query ) {
		if ( ! self::$upload_to_protected && empty( $_REQUEST['sdd_protected'] ) ) {
			return $query;
		}
		$query['meta_query']   = $query['meta_query'] ?? array();
		$query['meta_query'][] = self::get_protected_only_meta_query();
		return $query;
	}

	/**
	 * Get attachment file path.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $serving       Whether the file is being served to a buyer.
	 * @return string|false
	 */
	public static function get_attachment_path( $attachment_id, $serving = false ) {
		$previous = self::$serving_download;
		if ( $serving ) {
			self::$serving_download = true;
		}

		$path   = get_attached_file( $attachment_id );
		$result = $path && file_exists( $path ) ? $path : false;

		if ( $serving ) {
			self::$serving_download = $previous;
		}

		return $result;
	}

	/**
	 * Nginx rules for admin notice.
	 *
	 * @return string
	 */
	public static function get_nginx_rules() {
		$subdir = self::get_subdir_name();
		return "location ~* /wp-content/uploads/{$subdir}/ {\n    deny all;\n    return 403;\n}";
	}
}
