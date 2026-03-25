<?php
/**
 * Temporary file manager for staged uploads.
 *
 * Handles storing, retrieving, and cleaning up files that have been
 * uploaded via the multipart staging endpoint before being moved
 * into the WordPress media library.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Upload;

/**
 * Manages temporary file storage for the two-step upload process.
 */
class TempFileManager {

	/**
	 * Default TTL for temp files in seconds (1 hour).
	 */
	private const DEFAULT_TTL = 3600;

	/**
	 * Transient prefix for temp file metadata.
	 */
	private const TRANSIENT_PREFIX = 'mcp_upload_';

	/**
	 * Get the temp upload directory path.
	 *
	 * @return string Absolute path to the temp upload directory.
	 */
	public static function get_temp_dir(): string {
		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'mcp-temp-uploads';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );

			// Protect directory from direct access.
			$htaccess = $temp_dir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $htaccess, "Deny from all\n" );
			}

			$index = $temp_dir . '/index.php';
			if ( ! file_exists( $index ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $index, '<?php // Silence is golden.' );
			}
		}

		return $temp_dir;
	}

	/**
	 * Store a file temporarily and return its temp ID.
	 *
	 * @param array $file      The $_FILES array entry for the uploaded file.
	 * @param int   $ttl       Optional. Time-to-live in seconds. Default 3600 (1 hour).
	 *
	 * @return array|false Array with temp_id and metadata on success, false on failure.
	 */
	public static function store( array $file, int $ttl = self::DEFAULT_TTL ) {
		$temp_dir = self::get_temp_dir();
		$temp_id  = wp_generate_uuid4();

		// Sanitize filename.
		$filename  = sanitize_file_name( $file['name'] );
		$extension = pathinfo( $filename, PATHINFO_EXTENSION );

		// Store with temp_id as filename to prevent collisions.
		$dest_filename = $temp_id . ( $extension ? '.' . $extension : '' );
		$dest_path     = trailingslashit( $temp_dir ) . $dest_filename;

		// Move the uploaded file.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$moved = @move_uploaded_file( $file['tmp_name'], $dest_path );
		if ( ! $moved ) {
			return false;
		}

		// Restrict file permissions (owner read/write only — not executable, not world-readable).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		chmod( $dest_path, 0600 );

		$metadata = array(
			'temp_id'       => $temp_id,
			'filename'      => $filename,
			'stored_as'     => $dest_filename,
			'path'          => $dest_path,
			'size'          => $file['size'],
			'mime_type'     => $file['type'],
			'uploaded_at'   => time(),
			'expires_at'    => time() + $ttl,
			'uploaded_by'   => get_current_user_id(),
		);

		// Store metadata in a transient for retrieval and auto-expiry.
		set_transient( self::TRANSIENT_PREFIX . $temp_id, $metadata, $ttl );

		return $metadata;
	}

	/**
	 * Retrieve metadata for a staged file.
	 *
	 * @param string $temp_id The temporary file ID.
	 *
	 * @return array|false File metadata array or false if not found/expired.
	 */
	public static function get( string $temp_id ) {
		// Validate temp_id is a UUID v4 format to prevent transient key injection.
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $temp_id ) ) {
			return false;
		}

		$metadata = get_transient( self::TRANSIENT_PREFIX . $temp_id );

		if ( false === $metadata ) {
			return false;
		}

		// Verify the file still exists on disk.
		if ( ! file_exists( $metadata['path'] ) ) {
			delete_transient( self::TRANSIENT_PREFIX . $temp_id );
			return false;
		}

		return $metadata;
	}

	/**
	 * Atomically claim a staged file (retrieve + delete transient).
	 *
	 * This prevents race conditions where two concurrent requests both
	 * pass the permission check and try to finalize the same temp file.
	 * The first caller gets the metadata; the second gets false.
	 *
	 * @param string $temp_id The temporary file ID.
	 *
	 * @return array|false File metadata array or false if not found/already claimed.
	 */
	public static function claim( string $temp_id ) {
		// Validate temp_id is a UUID v4 format.
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $temp_id ) ) {
			return false;
		}

		$transient_key = self::TRANSIENT_PREFIX . $temp_id;
		$metadata      = get_transient( $transient_key );

		if ( false === $metadata ) {
			return false;
		}

		// Immediately delete the transient so no other request can claim it.
		delete_transient( $transient_key );

		// Verify the file still exists on disk.
		if ( ! file_exists( $metadata['path'] ) ) {
			return false;
		}

		return $metadata;
	}

	/**
	 * Clean up a staged file after it has been processed.
	 *
	 * @param string $temp_id The temporary file ID.
	 *
	 * @return bool True if cleanup succeeded.
	 */
	public static function cleanup( string $temp_id ): bool {
		$metadata = get_transient( self::TRANSIENT_PREFIX . $temp_id );

		if ( false !== $metadata && ! empty( $metadata['path'] ) && file_exists( $metadata['path'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $metadata['path'] );
		}

		delete_transient( self::TRANSIENT_PREFIX . $temp_id );

		return true;
	}

	/**
	 * Count how many staged files a user currently has (DoS protection).
	 *
	 * @param int $user_id The WordPress user ID.
	 *
	 * @return int Number of active staged files for this user.
	 */
	public static function count_user_files( int $user_id ): int {
		global $wpdb;

		// Query transients that belong to this user.
		// Transient values are serialized arrays; we check for the uploaded_by field.
		// This is intentionally a broad count — it's a rate limit, not a billing meter.
		$prefix = '_transient_' . self::TRANSIENT_PREFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s",
				$wpdb->esc_like( $prefix ) . '%',
				'%"uploaded_by";i:' . $user_id . ';%'
			)
		);

		return (int) $count;
	}

	/**
	 * Schedule the cleanup cron job.
	 */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'mcp_adapter_cleanup_temp_uploads' ) ) {
			wp_schedule_event( time(), 'hourly', 'mcp_adapter_cleanup_temp_uploads' );
		}

		add_action( 'mcp_adapter_cleanup_temp_uploads', array( self::class, 'cleanup_expired' ) );
	}

	/**
	 * Clean up all expired temp files.
	 *
	 * Called on a scheduled basis to remove orphaned temp files
	 * whose transients have already expired.
	 *
	 * @return int Number of files cleaned up.
	 */
	public static function cleanup_expired(): int {
		$temp_dir = self::get_temp_dir();
		$count    = 0;

		if ( ! is_dir( $temp_dir ) ) {
			return 0;
		}

		$files = glob( trailingslashit( $temp_dir ) . '*' );
		if ( false === $files ) {
			return 0;
		}

		foreach ( $files as $file ) {
			$basename = basename( $file );

			// Skip .htaccess and index.php.
			if ( in_array( $basename, array( '.htaccess', 'index.php' ), true ) ) {
				continue;
			}

			// Extract temp_id from filename (UUID before the extension).
			$temp_id = pathinfo( $basename, PATHINFO_FILENAME );

			// If the transient is gone, the file is expired — delete it.
			$metadata = get_transient( self::TRANSIENT_PREFIX . $temp_id );
			if ( false === $metadata ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file );
				++$count;
			}
		}

		return $count;
	}
}
