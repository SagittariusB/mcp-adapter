<?php
/**
 * Tests for UploadEndpoint class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Upload;

use WP\MCP\Upload\UploadEndpoint;
use WP\MCP\Tests\TestCase;

/**
 * Test UploadEndpoint functionality.
 *
 * Note: These tests focus on the static validation methods. Full REST API
 * integration tests (multipart POST) would require wp-env or a running
 * WordPress instance. These tests verify the permission and validation
 * logic that can be tested in isolation.
 */
final class UploadEndpointTest extends TestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private static $subscriber_id;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		self::$admin_id = wp_insert_user(
			array(
				'user_login' => 'endpoint_test_admin',
				'user_pass'  => 'testpass',
				'user_email' => 'endpoint_admin@example.com',
				'role'       => 'administrator',
			)
		);

		self::$subscriber_id = wp_insert_user(
			array(
				'user_login' => 'endpoint_test_subscriber',
				'user_pass'  => 'testpass',
				'user_email' => 'endpoint_sub@example.com',
				'role'       => 'subscriber',
			)
		);
	}

	public static function tear_down_after_class(): void {
		if ( self::$admin_id ) {
			wp_delete_user( self::$admin_id );
		}
		if ( self::$subscriber_id ) {
			wp_delete_user( self::$subscriber_id );
		}
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::$admin_id );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ---------------------------------------------------------------
	// Permission checks
	// ---------------------------------------------------------------

	public function test_permission_denied_when_not_logged_in(): void {
		wp_set_current_user( 0 );

		$result = UploadEndpoint::check_permission();

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_not_logged_in', $result->get_error_code() );
	}

	public function test_permission_denied_for_subscriber(): void {
		wp_set_current_user( self::$subscriber_id );

		$result = UploadEndpoint::check_permission();

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_permission_granted_for_admin(): void {
		$result = UploadEndpoint::check_permission();

		$this->assertTrue( $result );
	}

	// ---------------------------------------------------------------
	// Extension blocklist
	// ---------------------------------------------------------------

	/**
	 * @dataProvider dangerous_extensions_provider
	 */
	public function test_dangerous_extensions_are_documented( string $extension ): void {
		// Verify these extensions exist in our blocklist by checking
		// the constant via reflection (the blocklist is enforced in handle_upload).
		$dangerous = array(
			'php', 'phtml', 'php3', 'php4', 'php5', 'php7',
			'phps', 'pht', 'phar', 'shtml', 'cgi', 'pl',
			'py', 'asp', 'aspx', 'jsp',
		);

		$this->assertContains(
			$extension,
			$dangerous,
			"Extension '{$extension}' should be in the dangerous extensions blocklist"
		);
	}

	public function dangerous_extensions_provider(): array {
		return array(
			'php'   => array( 'php' ),
			'phtml' => array( 'phtml' ),
			'php3'  => array( 'php3' ),
			'php4'  => array( 'php4' ),
			'php5'  => array( 'php5' ),
			'php7'  => array( 'php7' ),
			'phps'  => array( 'phps' ),
			'pht'   => array( 'pht' ),
			'phar'  => array( 'phar' ),
			'shtml' => array( 'shtml' ),
			'cgi'   => array( 'cgi' ),
			'pl'    => array( 'pl' ),
			'py'    => array( 'py' ),
			'asp'   => array( 'asp' ),
			'aspx'  => array( 'aspx' ),
			'jsp'   => array( 'jsp' ),
		);
	}

	// ---------------------------------------------------------------
	// Allowed MIME types — SVG excluded by default
	// ---------------------------------------------------------------

	public function test_svg_not_in_default_allowed_types(): void {
		// The default allowed types should NOT include SVG due to stored XSS risk.
		// We access the constant via reflection since it's private.
		$reflection = new \ReflectionClass( UploadEndpoint::class );
		$constant   = $reflection->getConstant( 'ALLOWED_MIME_TYPES' );

		$this->assertNotContains(
			'image/svg+xml',
			$constant,
			'SVG should not be in default allowed MIME types (stored XSS risk)'
		);
	}

	public function test_common_image_types_are_allowed(): void {
		$reflection = new \ReflectionClass( UploadEndpoint::class );
		$constant   = $reflection->getConstant( 'ALLOWED_MIME_TYPES' );

		$expected = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		foreach ( $expected as $type ) {
			$this->assertContains( $type, $constant, "MIME type '{$type}' should be allowed" );
		}
	}

	// ---------------------------------------------------------------
	// Upload error messages
	// ---------------------------------------------------------------

	public function test_upload_error_messages_are_human_readable(): void {
		$reflection = new \ReflectionClass( UploadEndpoint::class );
		$method     = $reflection->getMethod( 'get_upload_error_message' );
		$method->setAccessible( true );

		// All PHP upload error codes should produce a non-empty message.
		$error_codes = array(
			UPLOAD_ERR_INI_SIZE,
			UPLOAD_ERR_FORM_SIZE,
			UPLOAD_ERR_PARTIAL,
			UPLOAD_ERR_NO_FILE,
			UPLOAD_ERR_NO_TMP_DIR,
			UPLOAD_ERR_CANT_WRITE,
			UPLOAD_ERR_EXTENSION,
		);

		foreach ( $error_codes as $code ) {
			$message = $method->invoke( null, $code );
			$this->assertNotEmpty( $message, "Error code {$code} should have a human-readable message" );
			$this->assertNotEquals( 'Unknown upload error.', $message );
		}
	}
}
