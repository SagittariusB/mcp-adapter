<?php
/**
 * REST API endpoint for staging file uploads via multipart form data.
 *
 * This endpoint is the first step in the two-step upload process:
 * 1. Client uploads file here via standard multipart POST → receives a temp_id
 * 2. Client calls the upload-media MCP tool with the temp_id → file moves to media library
 *
 * This avoids sending binary data through JSON-RPC / MCP protocol.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Upload;

/**
 * Registers and handles the multipart file upload staging endpoint.
 */
class UploadEndpoint {

	/**
	 * Maximum file size in bytes (20 MB).
	 */
	private const MAX_FILE_SIZE = 20 * 1024 * 1024;

	/**
	 * Allowed MIME types for upload.
	 *
	 * Note: SVG is excluded by default due to stored XSS risk (SVGs can contain JavaScript).
	 * Add 'image/svg+xml' via the mcp_adapter_upload_allowed_types filter if your site
	 * has SVG sanitization in place.
	 */
	private const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/bmp',
		'image/tiff',
		'video/mp4',
		'video/quicktime',
		'video/webm',
		'application/pdf',
		'audio/mpeg',
		'audio/ogg',
		'audio/wav',
	);

	/**
	 * Maximum number of staged files per user (DoS protection).
	 */
	private const MAX_STAGED_FILES_PER_USER = 10;

	/**
	 * Register the REST API routes.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register the upload staging route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'mcp-adapter/v1',
			'/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_upload' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);
	}

	/**
	 * Check if the current user has permission to upload.
	 *
	 * @return bool|\WP_Error True if permitted.
	 */
	public static function check_permission() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to upload files.', 'mcp-adapter' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to upload files.', 'mcp-adapter' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle the file upload request.
	 *
	 * Accepts a multipart form-data POST with a 'file' field.
	 * Stores the file temporarily and returns a temp_id for use
	 * with the upload-media MCP tool.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error Response with temp_id or error.
	 */
	public static function handle_upload( \WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new \WP_Error(
				'no_file',
				__( 'No file provided. Send a multipart form-data POST with a "file" field.', 'mcp-adapter' ),
				array( 'status' => 400 )
			);
		}

		$file = $files['file'];

		// Check for upload errors.
		if ( ! empty( $file['error'] ) ) {
			return new \WP_Error(
				'upload_error',
				self::get_upload_error_message( $file['error'] ),
				array( 'status' => 400 )
			);
		}

		// Validate file size.
		$max_size = apply_filters( 'mcp_adapter_upload_max_size', self::MAX_FILE_SIZE );
		if ( $file['size'] > $max_size ) {
			return new \WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: maximum file size in human-readable format */
					__( 'File exceeds maximum allowed size of %s.', 'mcp-adapter' ),
					size_format( $max_size )
				),
				array( 'status' => 413 )
			);
		}

		// Validate MIME type using both extension and file content inspection.
		$allowed_types = apply_filters( 'mcp_adapter_upload_allowed_types', self::ALLOWED_MIME_TYPES );

		// wp_check_filetype_and_ext reads actual file bytes (not just extension).
		$validated = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$mime      = $validated['type'] ?? false;

		// Fallback to extension-based check if content sniffing returned false
		// (some server configs disable fileinfo).
		if ( ! $mime ) {
			$filetype = wp_check_filetype( $file['name'] );
			$mime     = $filetype['type'] ?? false;
		}

		if ( ! $mime || ! in_array( $mime, $allowed_types, true ) ) {
			return new \WP_Error(
				'invalid_file_type',
				sprintf(
					/* translators: %s: the MIME type that was rejected */
					__( 'File type "%s" is not allowed.', 'mcp-adapter' ),
					esc_html( $mime ?: 'unknown' )
				),
				array( 'status' => 415 )
			);
		}

		// Block dangerous extensions that could execute server-side, regardless of MIME.
		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$dangerous = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'phar', 'shtml', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp' );
		if ( in_array( $extension, $dangerous, true ) ) {
			return new \WP_Error(
				'dangerous_file_type',
				__( 'This file type is not allowed for security reasons.', 'mcp-adapter' ),
				array( 'status' => 415 )
			);
		}

		// Also check for double extensions (e.g., evil.php.jpg).
		$all_extensions = explode( '.', strtolower( $file['name'] ) );
		array_shift( $all_extensions ); // Remove the base name.
		foreach ( $all_extensions as $ext ) {
			if ( in_array( $ext, $dangerous, true ) ) {
				return new \WP_Error(
					'dangerous_file_type',
					__( 'This file type is not allowed for security reasons.', 'mcp-adapter' ),
					array( 'status' => 415 )
				);
			}
		}

		// Per-user staging limit (DoS protection).
		$max_staged = apply_filters( 'mcp_adapter_upload_max_staged_per_user', self::MAX_STAGED_FILES_PER_USER );
		$user_id    = get_current_user_id();
		$staged     = TempFileManager::count_user_files( $user_id );
		if ( $staged >= $max_staged ) {
			return new \WP_Error(
				'too_many_staged',
				sprintf(
					/* translators: %d: maximum number of staged files */
					__( 'You have too many staged files (%d). Finalize or wait for existing uploads to expire.', 'mcp-adapter' ),
					$max_staged
				),
				array( 'status' => 429 )
			);
		}

		// Store the file temporarily.
		$result = TempFileManager::store( $file );

		if ( false === $result ) {
			return new \WP_Error(
				'storage_failed',
				__( 'Failed to store uploaded file.', 'mcp-adapter' ),
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response(
			array(
				'temp_id'   => $result['temp_id'],
				'filename'  => $result['filename'],
				'size'      => $result['size'],
				'mime_type' => $result['mime_type'],
				'expires_in' => $result['expires_at'] - time(),
			),
			201
		);
	}

	/**
	 * Get a human-readable error message for PHP upload error codes.
	 *
	 * @param int $error_code The PHP upload error code.
	 *
	 * @return string Error message.
	 */
	private static function get_upload_error_message( int $error_code ): string {
		$messages = array(
			UPLOAD_ERR_INI_SIZE   => __( 'File exceeds server upload size limit.', 'mcp-adapter' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds form upload size limit.', 'mcp-adapter' ),
			UPLOAD_ERR_PARTIAL    => __( 'File was only partially uploaded.', 'mcp-adapter' ),
			UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'mcp-adapter' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'Server missing temporary upload directory.', 'mcp-adapter' ),
			UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'mcp-adapter' ),
			UPLOAD_ERR_EXTENSION  => __( 'A server extension stopped the upload.', 'mcp-adapter' ),
		);

		return $messages[ $error_code ] ?? __( 'Unknown upload error.', 'mcp-adapter' );
	}
}
