<?php
/**
 * Ability for uploading media to the WordPress media library.
 *
 * This is the second step in the two-step upload process:
 * 1. Client uploads file via POST /wp-json/mcp-adapter/v1/upload → receives temp_id
 * 2. Client calls this ability with temp_id (or a URL) → file enters media library
 *
 * Supports two upload modes:
 * - temp_id: Uses a previously staged file (no base64 needed)
 * - url: Downloads from a remote URL (WordPress fetches server-side)
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

use WP\MCP\Upload\TempFileManager;

/**
 * Upload Media Ability - Moves staged files or remote URLs into the WordPress media library.
 */
final class UploadMediaAbility {
	use McpAbilityHelperTrait;

	/**
	 * Register the ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'mcp-adapter/upload-media',
			array(
				'label'               => 'Upload Media',
				'description'         => 'Upload a file to the WordPress media library. Provide either a temp_id from the staging endpoint (POST /wp-json/mcp-adapter/v1/upload) or a url to download from.',
				'category'            => 'mcp-adapter',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'temp_id'   => array(
							'type'        => 'string',
							'description' => 'Temporary file ID from the staging endpoint. Use this for local file uploads.',
						),
						'url'       => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'URL to download the file from. WordPress will fetch it server-side.',
						),
						'title'     => array(
							'type'        => 'string',
							'description' => 'Optional title for the media attachment.',
						),
						'alt_text'  => array(
							'type'        => 'string',
							'description' => 'Optional alt text for the media attachment.',
						),
						'caption'   => array(
							'type'        => 'string',
							'description' => 'Optional caption for the media attachment.',
						),
						'post_id'   => array(
							'type'        => 'integer',
							'description' => 'Optional post ID to attach the media to.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'attachment_id' => array( 'type' => 'integer' ),
						'url'           => array( 'type' => 'string' ),
						'title'         => array( 'type' => 'string' ),
						'mime_type'     => array( 'type' => 'string' ),
						'size'          => array( 'type' => 'integer' ),
						'width'         => array( 'type' => 'integer' ),
						'height'        => array( 'type' => 'integer' ),
						'error'         => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( self::class, 'check_permission' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Check permissions for uploading media.
	 *
	 * @param array|null $input Input parameters.
	 *
	 * @return bool|\WP_Error True if the user can upload.
	 */
	public static function check_permission( $input = array() ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'authentication_required', 'User must be authenticated to upload media.' );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'insufficient_capability', 'User lacks the upload_files capability.' );
		}

		// If temp_id is provided, verify ownership.
		if ( ! empty( $input['temp_id'] ) ) {
			$metadata = TempFileManager::get( $input['temp_id'] );
			if ( false === $metadata ) {
				return new \WP_Error( 'temp_file_not_found', 'Staged file not found or expired. Upload the file again via POST /wp-json/mcp-adapter/v1/upload.' );
			}

			// Only the user who staged the file can finalize it.
			if ( (int) $metadata['uploaded_by'] !== get_current_user_id() ) {
				return new \WP_Error( 'ownership_mismatch', 'You can only finalize files you uploaded.' );
			}
		}

		return true;
	}

	/**
	 * Execute the media upload.
	 *
	 * @param array|null $input Input parameters.
	 *
	 * @return array Result array.
	 */
	public static function execute( $input = array() ): array {
		$temp_id = $input['temp_id'] ?? '';
		$url     = $input['url'] ?? '';

		if ( empty( $temp_id ) && empty( $url ) ) {
			return array(
				'success' => false,
				'error'   => 'Provide either temp_id or url. Use POST /wp-json/mcp-adapter/v1/upload to stage a file first, or provide a URL to download from.',
			);
		}

		// Require WordPress media functions.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$post_id = ! empty( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		// Verify the user can edit the target post before attaching media to it.
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return array(
					'success' => false,
					'error'   => 'The specified post_id does not exist.',
				);
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return array(
					'success' => false,
					'error'   => 'You do not have permission to attach media to this post.',
				);
			}
		}

		if ( ! empty( $temp_id ) ) {
			$result = self::upload_from_temp( $temp_id, $post_id );
		} else {
			$result = self::upload_from_url( $url, $post_id );
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		$attachment_id = $result;

		// Set optional metadata.
		if ( ! empty( $input['title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => sanitize_text_field( $input['title'] ),
				)
			);
		}

		if ( ! empty( $input['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		}

		if ( ! empty( $input['caption'] ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => sanitize_text_field( $input['caption'] ),
				)
			);
		}

		// Build response with attachment details.
		$response = array(
			'success'       => true,
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'title'         => get_the_title( $attachment_id ),
			'mime_type'     => get_post_mime_type( $attachment_id ),
		);

		// Add file size.
		$file_path = get_attached_file( $attachment_id );
		if ( $file_path && file_exists( $file_path ) ) {
			$response['size'] = filesize( $file_path );
		}

		// Add dimensions for images.
		$image_meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $image_meta['width'] ) ) {
			$response['width']  = (int) $image_meta['width'];
			$response['height'] = (int) $image_meta['height'];
		}

		return $response;
	}

	/**
	 * Upload a staged temp file to the media library.
	 *
	 * @param string $temp_id The temporary file ID.
	 * @param int    $post_id Optional post ID to attach to.
	 *
	 * @return int|\WP_Error Attachment ID on success, WP_Error on failure.
	 */
	private static function upload_from_temp( string $temp_id, int $post_id = 0 ) {
		// Atomic claim: retrieve and immediately delete the transient to prevent
		// race conditions where concurrent requests both pass the permission check.
		$metadata = TempFileManager::claim( $temp_id );

		if ( false === $metadata ) {
			return new \WP_Error( 'temp_file_not_found', 'Staged file not found, expired, or already claimed.' );
		}

		// Prepare file array for media_handle_sideload.
		// media_handle_sideload expects a file array similar to $_FILES.
		$file_array = array(
			'name'     => $metadata['filename'],
			'type'     => $metadata['mime_type'],
			'tmp_name' => $metadata['path'],
			'error'    => 0,
			'size'     => $metadata['size'],
		);

		// media_handle_sideload will move the file into the uploads directory.
		$attachment_id = media_handle_sideload( $file_array, $post_id );

		// If sideload failed and the file still exists, clean it up.
		if ( is_wp_error( $attachment_id ) && file_exists( $metadata['path'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $metadata['path'] );
		}

		return $attachment_id;
	}

	/**
	 * SSRF protection: resolve hostname and block private/reserved IP ranges.
	 *
	 * Prevents the server from being used as a proxy to access internal services,
	 * cloud metadata endpoints (169.254.169.254), or private network resources.
	 *
	 * Returns the validated IP on success so callers can pin it for the actual
	 * HTTP request, defeating DNS rebinding attacks (where the hostname resolves
	 * to a public IP on first lookup but a private IP on second lookup).
	 *
	 * @param string $url The URL to validate.
	 *
	 * @return string|\WP_Error Validated public IP address on success, WP_Error if blocked.
	 */
	private static function check_ssrf( string $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( empty( $host ) ) {
			return new \WP_Error( 'invalid_host', 'URL has no valid hostname.' );
		}

		// If the host is already an IP literal, validate it directly.
		$host_without_brackets = trim( $host, '[]' );
		if ( false !== filter_var( $host_without_brackets, FILTER_VALIDATE_IP ) ) {
			$is_public = filter_var(
				$host_without_brackets,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			if ( false === $is_public ) {
				return new \WP_Error(
					'ssrf_blocked',
					'URL points to a private or reserved IP address. Only public URLs are allowed.'
				);
			}
			return $host_without_brackets;
		}

		// Resolve hostname to IP(s).
		$ips = gethostbynamel( $host );

		if ( false === $ips || empty( $ips ) ) {
			return new \WP_Error( 'dns_resolution_failed', 'Could not resolve hostname.' );
		}

		// Validate ALL resolved IPs — if any is private, block the request.
		foreach ( $ips as $ip ) {
			// FILTER_VALIDATE_IP with FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			// blocks: 10.x, 172.16-31.x, 192.168.x, 127.x, 169.254.x, ::1, fc00::/7, etc.
			$is_public = filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);

			if ( false === $is_public ) {
				return new \WP_Error(
					'ssrf_blocked',
					'URL resolves to a private or reserved IP address. Only public URLs are allowed.'
				);
			}
		}

		// Return the first validated IP for pinning.
		return $ips[0];
	}

	/**
	 * Validate that a resolved IP is public (not private/reserved).
	 *
	 * Used as a post-download belt-and-suspenders check against DNS rebinding.
	 *
	 * @param string $ip The IP address to validate.
	 *
	 * @return bool True if the IP is public.
	 */
	private static function is_public_ip( string $ip ): bool {
		return false !== filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Maximum download size for URL-based uploads in bytes (20 MB).
	 * Matches the staging endpoint's default limit.
	 */
	private const MAX_DOWNLOAD_SIZE = 20 * 1024 * 1024;

	/**
	 * Download a file from a URL and upload to the media library.
	 *
	 * @param string $url     The URL to download from.
	 * @param int    $post_id Optional post ID to attach to.
	 *
	 * @return int|\WP_Error Attachment ID on success, WP_Error on failure.
	 */
	private static function upload_from_url( string $url, int $post_id = 0 ) {
		// Validate the URL.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', 'The provided URL is not valid.' );
		}

		// Only allow http and https schemes.
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'invalid_scheme', 'Only http and https URLs are allowed.' );
		}

		// SSRF protection: resolve DNS and block private/reserved IPs.
		// Returns the validated IP so we can pin it for the download,
		// defeating DNS rebinding attacks.
		$validated_ip = self::check_ssrf( $url );
		if ( is_wp_error( $validated_ip ) ) {
			return $validated_ip;
		}

		// Pin the validated IP for the HTTP request to prevent DNS rebinding.
		// WordPress HTTP API uses the 'http_request_args' filter, but the most
		// reliable approach is 'pre_http_request' to validate the actual connection.
		// We use 'http_api_curl' to set CURLOPT_RESOLVE, which forces cURL to use
		// our pre-validated IP, bypassing a second DNS lookup entirely.
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$port   = wp_parse_url( $url, PHP_URL_PORT ) ?? ( 'https' === $scheme ? 443 : 80 );
		$pinned = "{$host}:{$port}:{$validated_ip}";

		$pin_dns = static function ( $handle ) use ( $pinned ) {
			if ( function_exists( 'curl_setopt' ) && is_resource( $handle ) || $handle instanceof \CurlHandle ) {
				// CURLOPT_RESOLVE pins hostname→IP so cURL never re-resolves DNS.
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $pinned ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			}
		};

		add_action( 'http_api_curl', $pin_dns );

		// Download the file to a temp location with a 30-second timeout.
		$tmp_file = download_url( $url, 30 );

		// Always remove the filter after download to avoid leaking into other requests.
		remove_action( 'http_api_curl', $pin_dns );

		if ( is_wp_error( $tmp_file ) ) {
			// Sanitize error message to avoid leaking internal network details
			// (hostnames, IPs, ports) from download_url() failures.
			return new \WP_Error(
				'download_failed',
				'Failed to download file from the provided URL.'
			);
		}

		// Enforce download size limit (prevents fetching multi-GB files).
		$max_size = apply_filters( 'mcp_adapter_upload_max_size', self::MAX_DOWNLOAD_SIZE );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$file_size = @filesize( $tmp_file );
		if ( $file_size && $file_size > $max_size ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $tmp_file );
			return new \WP_Error(
				'file_too_large',
				sprintf( 'Downloaded file exceeds maximum allowed size of %s.', size_format( $max_size ) )
			);
		}

		// Extract filename from URL.
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$filename = $url_path ? basename( $url_path ) : 'downloaded-file';

		// Prepare file array for media_handle_sideload.
		$file_array = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		// Clean up the temp file if sideload failed.
		if ( is_wp_error( $attachment_id ) && file_exists( $tmp_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $tmp_file );
		}

		return $attachment_id;
	}
}
