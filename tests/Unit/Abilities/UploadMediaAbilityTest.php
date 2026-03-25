<?php
/**
 * Tests for UploadMediaAbility class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Abilities;

use WP\MCP\Abilities\UploadMediaAbility;
use WP\MCP\Upload\TempFileManager;
use WP\MCP\Tests\TestCase;

/**
 * Test UploadMediaAbility functionality.
 */
final class UploadMediaAbilityTest extends TestCase {

	/**
	 * Admin user ID (has upload_files capability).
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Subscriber user ID (no upload_files capability).
	 *
	 * @var int
	 */
	private static $subscriber_id;

	/**
	 * Second admin user ID for ownership tests.
	 *
	 * @var int
	 */
	private static $other_admin_id;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// Register the upload-media ability if not already registered.
		if ( ! wp_get_ability( 'mcp-adapter/upload-media' ) ) {
			add_action(
				'wp_abilities_api_init',
				static function () {
					if ( ! wp_get_ability( 'mcp-adapter/upload-media' ) ) {
						UploadMediaAbility::register();
					}
				}
			);
			if ( did_action( 'wp_abilities_api_init' ) ) {
				UploadMediaAbility::register();
			} else {
				do_action( 'wp_abilities_api_init' );
			}
		}

		self::$admin_id = wp_insert_user(
			array(
				'user_login' => 'upload_test_admin',
				'user_pass'  => 'testpass',
				'user_email' => 'upload_admin@example.com',
				'role'       => 'administrator',
			)
		);

		self::$subscriber_id = wp_insert_user(
			array(
				'user_login' => 'upload_test_subscriber',
				'user_pass'  => 'testpass',
				'user_email' => 'upload_sub@example.com',
				'role'       => 'subscriber',
			)
		);

		self::$other_admin_id = wp_insert_user(
			array(
				'user_login' => 'upload_test_other_admin',
				'user_pass'  => 'testpass',
				'user_email' => 'upload_other_admin@example.com',
				'role'       => 'administrator',
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
		if ( self::$other_admin_id ) {
			wp_delete_user( self::$other_admin_id );
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
	// Ability registration
	// ---------------------------------------------------------------

	public function test_register_creates_ability(): void {
		$ability = wp_get_ability( 'mcp-adapter/upload-media' );

		$this->assertNotNull( $ability );
		$this->assertEquals( 'mcp-adapter/upload-media', $ability->get_name() );
		$this->assertEquals( 'Upload Media', $ability->get_label() );
	}

	public function test_ability_has_correct_input_schema(): void {
		$ability      = wp_get_ability( 'mcp-adapter/upload-media' );
		$input_schema = $ability->get_input_schema();

		$this->assertEquals( 'object', $input_schema['type'] );
		$this->assertArrayHasKey( 'temp_id', $input_schema['properties'] );
		$this->assertArrayHasKey( 'url', $input_schema['properties'] );
		$this->assertArrayHasKey( 'title', $input_schema['properties'] );
		$this->assertArrayHasKey( 'alt_text', $input_schema['properties'] );
		$this->assertArrayHasKey( 'caption', $input_schema['properties'] );
		$this->assertArrayHasKey( 'post_id', $input_schema['properties'] );
		$this->assertFalse( $input_schema['additionalProperties'] );
	}

	public function test_ability_has_mcp_public_metadata(): void {
		$ability = wp_get_ability( 'mcp-adapter/upload-media' );
		$meta    = $ability->get_meta();

		$this->assertTrue( $meta['mcp']['public'] );
		$this->assertEquals( 'tool', $meta['mcp']['type'] );
	}

	public function test_ability_annotations_not_destructive(): void {
		$ability = wp_get_ability( 'mcp-adapter/upload-media' );
		$meta    = $ability->get_meta();

		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertFalse( $meta['annotations']['idempotent'] );
	}

	// ---------------------------------------------------------------
	// Permission checks
	// ---------------------------------------------------------------

	public function test_permission_requires_authentication(): void {
		wp_set_current_user( 0 );

		$result = UploadMediaAbility::check_permission( array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'authentication_required', $result->get_error_code() );
	}

	public function test_permission_requires_upload_files_capability(): void {
		wp_set_current_user( self::$subscriber_id );

		$result = UploadMediaAbility::check_permission( array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	public function test_permission_granted_for_admin(): void {
		$result = UploadMediaAbility::check_permission( array() );

		$this->assertTrue( $result );
	}

	public function test_permission_rejects_nonexistent_temp_id(): void {
		$result = UploadMediaAbility::check_permission(
			array( 'temp_id' => '00000000-0000-4000-a000-000000000000' )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'temp_file_not_found', $result->get_error_code() );
	}

	public function test_permission_rejects_other_users_temp_file(): void {
		// Stage a file as the other admin.
		wp_set_current_user( self::$other_admin_id );
		$staged = $this->stage_dummy_file();
		$this->assertIsArray( $staged );

		// Switch to our main admin and try to finalize it.
		wp_set_current_user( self::$admin_id );

		$result = UploadMediaAbility::check_permission(
			array( 'temp_id' => $staged['temp_id'] )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'ownership_mismatch', $result->get_error_code() );

		// Clean up.
		TempFileManager::cleanup( $staged['temp_id'] );
	}

	public function test_permission_allows_own_temp_file(): void {
		$staged = $this->stage_dummy_file();
		$this->assertIsArray( $staged );

		$result = UploadMediaAbility::check_permission(
			array( 'temp_id' => $staged['temp_id'] )
		);

		$this->assertTrue( $result );

		TempFileManager::cleanup( $staged['temp_id'] );
	}

	// ---------------------------------------------------------------
	// Execute — input validation
	// ---------------------------------------------------------------

	public function test_execute_requires_temp_id_or_url(): void {
		$result = UploadMediaAbility::execute( array() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'temp_id or url', $result['error'] );
	}

	public function test_execute_rejects_expired_temp_id(): void {
		$result = UploadMediaAbility::execute(
			array( 'temp_id' => '00000000-0000-4000-a000-000000000000' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	// ---------------------------------------------------------------
	// Execute — post_id authorization
	// ---------------------------------------------------------------

	public function test_execute_rejects_nonexistent_post_id(): void {
		$result = UploadMediaAbility::execute(
			array(
				'url'     => 'https://example.com/image.jpg',
				'post_id' => 999999999,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'does not exist', $result['error'] );
	}

	public function test_execute_rejects_post_id_user_cannot_edit(): void {
		// Create a post owned by someone else.
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
				'post_author' => self::$other_admin_id,
			)
		);

		// Switch to subscriber who can't edit it.
		wp_set_current_user( self::$subscriber_id );

		$result = UploadMediaAbility::execute(
			array(
				'url'     => 'https://example.com/image.jpg',
				'post_id' => $post_id,
			)
		);

		// Subscriber fails at permission check (upload_files), but if they had upload_files
		// they'd still fail at edit_post. Test via the execute path directly.
		// We need to use an admin for this to isolate the post_id check.
		wp_set_current_user( self::$admin_id );

		// Admins can edit all posts, so create a user with upload_files but not edit_others_posts.
		$author_id = wp_insert_user(
			array(
				'user_login' => 'upload_test_author',
				'user_pass'  => 'testpass',
				'user_email' => 'upload_author@example.com',
				'role'       => 'author',
			)
		);
		wp_set_current_user( $author_id );

		$result = UploadMediaAbility::execute(
			array(
				'url'     => 'https://example.com/image.jpg',
				'post_id' => $post_id,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'permission to attach media', $result['error'] );

		wp_delete_user( $author_id );
		wp_delete_post( $post_id, true );
		wp_set_current_user( self::$admin_id );
	}

	// ---------------------------------------------------------------
	// SSRF protection
	// ---------------------------------------------------------------

	/**
	 * @dataProvider ssrf_urls_provider
	 */
	public function test_ssrf_blocks_private_urls( string $url ): void {
		$result = UploadMediaAbility::execute( array( 'url' => $url ) );

		$this->assertFalse( $result['success'] );
		// Should fail with ssrf_blocked, invalid_url, or dns_resolution_failed.
		$this->assertNotEmpty( $result['error'] );
	}

	public function ssrf_urls_provider(): array {
		return array(
			'localhost'         => array( 'http://localhost/secret' ),
			'127.0.0.1'        => array( 'http://127.0.0.1/admin' ),
			'AWS metadata'     => array( 'http://169.254.169.254/latest/meta-data/' ),
			'private 10.x'     => array( 'http://10.0.0.1/internal' ),
			'private 192.168'  => array( 'http://192.168.1.1/router' ),
			'private 172.16'   => array( 'http://172.16.0.1/service' ),
			'IPv6 loopback'    => array( 'http://[::1]/secret' ),
			'ftp scheme'       => array( 'ftp://evil.com/malware.exe' ),
			'file scheme'      => array( 'file:///etc/passwd' ),
			'no scheme'        => array( 'not-a-url' ),
		);
	}

	public function test_ssrf_allows_public_url(): void {
		// This tests the SSRF check in isolation — the actual download will fail
		// because we're in a test env, but it should NOT fail with ssrf_blocked.
		$result = UploadMediaAbility::execute(
			array( 'url' => 'https://example.com/nonexistent-image.jpg' )
		);

		// Should fail, but NOT because of SSRF. The error should be about download
		// failure or file type, not about private IPs.
		if ( ! $result['success'] ) {
			$this->assertStringNotContainsString( 'private or reserved IP', $result['error'] );
			$this->assertStringNotContainsString( 'ssrf', strtolower( $result['error'] ) );
		}
	}

	public function test_download_failure_does_not_leak_internal_details(): void {
		// A URL that will fail to download. The error message should be generic,
		// not exposing internal hostnames, IPs, or network topology.
		$result = UploadMediaAbility::execute(
			array( 'url' => 'https://example.com/nonexistent-image.jpg' )
		);

		if ( ! $result['success'] ) {
			// Should not contain IP addresses or internal hostnames.
			$this->assertDoesNotMatchRegularExpression(
				'/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/',
				$result['error'],
				'Error message should not contain IP addresses'
			);
			$this->assertStringNotContainsString( 'resolve', strtolower( $result['error'] ) );
			$this->assertStringNotContainsString( 'curl', strtolower( $result['error'] ) );
		}
	}

	// ---------------------------------------------------------------
	// TempFileManager — UUID validation
	// ---------------------------------------------------------------

	public function test_temp_file_manager_rejects_invalid_uuid(): void {
		$result = TempFileManager::get( 'not-a-uuid' );
		$this->assertFalse( $result );
	}

	public function test_temp_file_manager_rejects_sql_injection_temp_id(): void {
		$result = TempFileManager::get( "'; DROP TABLE wp_options; --" );
		$this->assertFalse( $result );
	}

	public function test_temp_file_manager_rejects_path_traversal_temp_id(): void {
		$result = TempFileManager::get( '../../etc/passwd' );
		$this->assertFalse( $result );
	}

	public function test_claim_rejects_invalid_uuid(): void {
		$result = TempFileManager::claim( 'not-a-uuid' );
		$this->assertFalse( $result );
	}

	// ---------------------------------------------------------------
	// TempFileManager — claim atomicity
	// ---------------------------------------------------------------

	public function test_claim_returns_false_on_second_call(): void {
		$staged = $this->stage_dummy_file();
		$this->assertIsArray( $staged );

		// First claim succeeds.
		$claimed = TempFileManager::claim( $staged['temp_id'] );
		$this->assertIsArray( $claimed );
		$this->assertEquals( $staged['temp_id'], $claimed['temp_id'] );

		// Second claim fails (transient already deleted).
		$claimed_again = TempFileManager::claim( $staged['temp_id'] );
		$this->assertFalse( $claimed_again );

		// Clean up file if it still exists.
		if ( file_exists( $staged['path'] ) ) {
			unlink( $staged['path'] ); // phpcs:ignore
		}
	}

	public function test_get_still_works_after_store(): void {
		$staged = $this->stage_dummy_file();
		$this->assertIsArray( $staged );

		// get() should work (doesn't consume the transient).
		$meta = TempFileManager::get( $staged['temp_id'] );
		$this->assertIsArray( $meta );
		$this->assertEquals( $staged['temp_id'], $meta['temp_id'] );

		// get() again still works.
		$meta2 = TempFileManager::get( $staged['temp_id'] );
		$this->assertIsArray( $meta2 );

		TempFileManager::cleanup( $staged['temp_id'] );
	}

	// ---------------------------------------------------------------
	// TempFileManager — cleanup
	// ---------------------------------------------------------------

	public function test_cleanup_removes_file_and_transient(): void {
		$staged = $this->stage_dummy_file();
		$this->assertIsArray( $staged );
		$this->assertFileExists( $staged['path'] );

		$result = TempFileManager::cleanup( $staged['temp_id'] );
		$this->assertTrue( $result );

		// File should be gone.
		$this->assertFileDoesNotExist( $staged['path'] );

		// Transient should be gone.
		$meta = TempFileManager::get( $staged['temp_id'] );
		$this->assertFalse( $meta );
	}

	// ---------------------------------------------------------------
	// TempFileManager — per-user count
	// ---------------------------------------------------------------

	public function test_count_user_files_returns_correct_count(): void {
		$staged1 = $this->stage_dummy_file();
		$staged2 = $this->stage_dummy_file();

		$count = TempFileManager::count_user_files( self::$admin_id );
		$this->assertGreaterThanOrEqual( 2, $count );

		TempFileManager::cleanup( $staged1['temp_id'] );
		TempFileManager::cleanup( $staged2['temp_id'] );
	}

	public function test_count_user_files_scoped_to_user(): void {
		// Stage as admin.
		$staged = $this->stage_dummy_file();

		// Other user should have 0.
		$count = TempFileManager::count_user_files( self::$subscriber_id );
		$this->assertEquals( 0, $count );

		TempFileManager::cleanup( $staged['temp_id'] );
	}

	// ---------------------------------------------------------------
	// TempFileManager — temp directory protection
	// ---------------------------------------------------------------

	public function test_temp_dir_has_htaccess(): void {
		$temp_dir = TempFileManager::get_temp_dir();
		$this->assertFileExists( $temp_dir . '/.htaccess' );
		$this->assertStringContainsString( 'Deny from all', file_get_contents( $temp_dir . '/.htaccess' ) ); // phpcs:ignore
	}

	public function test_temp_dir_has_index_php(): void {
		$temp_dir = TempFileManager::get_temp_dir();
		$this->assertFileExists( $temp_dir . '/index.php' );
	}

	public function test_staged_file_has_restrictive_permissions(): void {
		$staged = $this->stage_dummy_file();
		$this->assertIsArray( $staged );

		$perms = fileperms( $staged['path'] ) & 0777;
		$this->assertEquals( 0600, $perms, 'Staged file should have 0600 permissions' );

		TempFileManager::cleanup( $staged['temp_id'] );
	}

	// ---------------------------------------------------------------
	// Helper: stage a dummy file via TempFileManager
	// ---------------------------------------------------------------

	/**
	 * Create a temporary file and stage it through TempFileManager.
	 *
	 * We can't use move_uploaded_file() in tests (PHP checks is_uploaded_file),
	 * so we simulate by writing directly and using set_transient.
	 *
	 * @return array|false Staged file metadata.
	 */
	private function stage_dummy_file() {
		$temp_dir  = TempFileManager::get_temp_dir();
		$temp_id   = wp_generate_uuid4();
		$filename  = 'test-image.jpg';
		$dest_path = trailingslashit( $temp_dir ) . $temp_id . '.jpg';

		// Create a minimal JPEG (FF D8 FF header).
		file_put_contents( $dest_path, "\xFF\xD8\xFF\xE0" . str_repeat( "\x00", 100 ) ); // phpcs:ignore
		chmod( $dest_path, 0600 ); // phpcs:ignore

		$metadata = array(
			'temp_id'     => $temp_id,
			'filename'    => $filename,
			'stored_as'   => $temp_id . '.jpg',
			'path'        => $dest_path,
			'size'        => filesize( $dest_path ),
			'mime_type'   => 'image/jpeg',
			'uploaded_at' => time(),
			'expires_at'  => time() + 3600,
			'uploaded_by' => get_current_user_id(),
		);

		set_transient( 'mcp_upload_' . $temp_id, $metadata, 3600 );

		return $metadata;
	}
}
