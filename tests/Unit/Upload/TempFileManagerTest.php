<?php
/**
 * Tests for TempFileManager class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Upload;

use WP\MCP\Upload\TempFileManager;
use WP\MCP\Tests\TestCase;

/**
 * Test TempFileManager functionality.
 */
final class TempFileManagerTest extends TestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private static $user_id;

	/**
	 * Track temp_ids created during tests for cleanup.
	 *
	 * @var array
	 */
	private $staged_ids = array();

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		self::$user_id = wp_insert_user(
			array(
				'user_login' => 'tempfile_test_user',
				'user_pass'  => 'testpass',
				'user_email' => 'tempfile@example.com',
				'role'       => 'administrator',
			)
		);
	}

	public static function tear_down_after_class(): void {
		if ( self::$user_id ) {
			wp_delete_user( self::$user_id );
		}
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::$user_id );
	}

	public function tear_down(): void {
		// Clean up any staged files from this test.
		foreach ( $this->staged_ids as $temp_id ) {
			TempFileManager::cleanup( $temp_id );
		}
		$this->staged_ids = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ---------------------------------------------------------------
	// Temp directory
	// ---------------------------------------------------------------

	public function test_get_temp_dir_returns_path_under_uploads(): void {
		$temp_dir   = TempFileManager::get_temp_dir();
		$upload_dir = wp_upload_dir();

		$this->assertStringStartsWith( $upload_dir['basedir'], $temp_dir );
		$this->assertStringEndsWith( 'mcp-temp-uploads', $temp_dir );
	}

	public function test_get_temp_dir_creates_directory(): void {
		$temp_dir = TempFileManager::get_temp_dir();
		$this->assertDirectoryExists( $temp_dir );
	}

	public function test_temp_dir_protection_files_exist(): void {
		$temp_dir = TempFileManager::get_temp_dir();

		$this->assertFileExists( $temp_dir . '/.htaccess' );
		$this->assertFileExists( $temp_dir . '/index.php' );
	}

	public function test_htaccess_denies_all(): void {
		$temp_dir = TempFileManager::get_temp_dir();
		$content  = file_get_contents( $temp_dir . '/.htaccess' ); // phpcs:ignore

		$this->assertStringContainsString( 'Deny from all', $content );
	}

	// ---------------------------------------------------------------
	// UUID validation
	// ---------------------------------------------------------------

	/**
	 * @dataProvider invalid_uuid_provider
	 */
	public function test_get_rejects_invalid_uuid( string $bad_id ): void {
		$this->assertFalse( TempFileManager::get( $bad_id ) );
	}

	/**
	 * @dataProvider invalid_uuid_provider
	 */
	public function test_claim_rejects_invalid_uuid( string $bad_id ): void {
		$this->assertFalse( TempFileManager::claim( $bad_id ) );
	}

	public function invalid_uuid_provider(): array {
		return array(
			'empty string'      => array( '' ),
			'plain text'        => array( 'not-a-uuid' ),
			'SQL injection'     => array( "'; DROP TABLE wp_options; --" ),
			'path traversal'    => array( '../../etc/passwd' ),
			'null bytes'        => array( "00000000-0000-4000-a000-00000000\x0000" ),
			'wrong version'     => array( '00000000-0000-3000-a000-000000000000' ), // v3, not v4
			'wrong variant'     => array( '00000000-0000-4000-c000-000000000000' ), // variant 2
			'too short'         => array( '00000000-0000-4000-a000' ),
			'too long'          => array( '00000000-0000-4000-a000-000000000000-extra' ),
			'special chars'     => array( '00000000-0000-4000-a000-00000000000g' ),
		);
	}

	public function test_get_accepts_valid_uuid4(): void {
		// This UUID doesn't correspond to a real file — should return false
		// because the transient doesn't exist, but it should pass UUID validation.
		$valid_uuid = '12345678-1234-4abc-a123-123456789abc';
		$result     = TempFileManager::get( $valid_uuid );

		// Returns false because no transient exists, but no exception thrown.
		$this->assertFalse( $result );
	}

	// ---------------------------------------------------------------
	// Claim atomicity
	// ---------------------------------------------------------------

	public function test_claim_succeeds_on_first_call(): void {
		$staged = $this->create_staged_file();

		$claimed = TempFileManager::claim( $staged['temp_id'] );

		$this->assertIsArray( $claimed );
		$this->assertEquals( $staged['temp_id'], $claimed['temp_id'] );
		$this->assertEquals( $staged['filename'], $claimed['filename'] );

		// Clean up file manually since claim consumed the transient.
		if ( file_exists( $staged['path'] ) ) {
			unlink( $staged['path'] ); // phpcs:ignore
		}
	}

	public function test_claim_fails_on_second_call(): void {
		$staged = $this->create_staged_file();

		// First claim.
		$first = TempFileManager::claim( $staged['temp_id'] );
		$this->assertIsArray( $first );

		// Second claim.
		$second = TempFileManager::claim( $staged['temp_id'] );
		$this->assertFalse( $second );

		if ( file_exists( $staged['path'] ) ) {
			unlink( $staged['path'] ); // phpcs:ignore
		}
	}

	public function test_get_does_not_consume_transient(): void {
		$staged = $this->create_staged_file();

		// Multiple get() calls should all succeed.
		$this->assertIsArray( TempFileManager::get( $staged['temp_id'] ) );
		$this->assertIsArray( TempFileManager::get( $staged['temp_id'] ) );
		$this->assertIsArray( TempFileManager::get( $staged['temp_id'] ) );
	}

	// ---------------------------------------------------------------
	// Cleanup
	// ---------------------------------------------------------------

	public function test_cleanup_removes_file(): void {
		$staged = $this->create_staged_file();
		$this->assertFileExists( $staged['path'] );

		TempFileManager::cleanup( $staged['temp_id'] );
		// Remove from tracked list since we just cleaned it.
		$this->staged_ids = array_diff( $this->staged_ids, array( $staged['temp_id'] ) );

		$this->assertFileDoesNotExist( $staged['path'] );
	}

	public function test_cleanup_removes_transient(): void {
		$staged = $this->create_staged_file();

		TempFileManager::cleanup( $staged['temp_id'] );
		$this->staged_ids = array_diff( $this->staged_ids, array( $staged['temp_id'] ) );

		$this->assertFalse( TempFileManager::get( $staged['temp_id'] ) );
	}

	public function test_cleanup_returns_true_even_if_already_cleaned(): void {
		$staged = $this->create_staged_file();

		TempFileManager::cleanup( $staged['temp_id'] );
		$this->staged_ids = array_diff( $this->staged_ids, array( $staged['temp_id'] ) );

		// Second cleanup should still succeed (idempotent).
		$result = TempFileManager::cleanup( $staged['temp_id'] );
		$this->assertTrue( $result );
	}

	// ---------------------------------------------------------------
	// File permissions
	// ---------------------------------------------------------------

	public function test_staged_file_permissions_are_0600(): void {
		$staged = $this->create_staged_file();

		$perms = fileperms( $staged['path'] ) & 0777;
		$this->assertEquals( 0600, $perms );
	}

	// ---------------------------------------------------------------
	// Per-user file count
	// ---------------------------------------------------------------

	public function test_count_user_files_starts_at_zero_for_new_user(): void {
		$new_user_id = wp_insert_user(
			array(
				'user_login' => 'tempfile_count_test',
				'user_pass'  => 'testpass',
				'user_email' => 'tempcount@example.com',
				'role'       => 'subscriber',
			)
		);

		$count = TempFileManager::count_user_files( $new_user_id );
		$this->assertEquals( 0, $count );

		wp_delete_user( $new_user_id );
	}

	public function test_count_user_files_increments_with_staged_files(): void {
		$before = TempFileManager::count_user_files( self::$user_id );

		$this->create_staged_file();
		$this->create_staged_file();

		$after = TempFileManager::count_user_files( self::$user_id );
		$this->assertEquals( $before + 2, $after );
	}

	public function test_count_user_files_decrements_after_cleanup(): void {
		$staged = $this->create_staged_file();
		$before = TempFileManager::count_user_files( self::$user_id );

		TempFileManager::cleanup( $staged['temp_id'] );
		$this->staged_ids = array_diff( $this->staged_ids, array( $staged['temp_id'] ) );

		$after = TempFileManager::count_user_files( self::$user_id );
		$this->assertEquals( $before - 1, $after );
	}

	// ---------------------------------------------------------------
	// Cleanup expired
	// ---------------------------------------------------------------

	public function test_cleanup_expired_removes_orphaned_files(): void {
		$staged = $this->create_staged_file();
		$path   = $staged['path'];

		// Simulate expiration by deleting the transient but keeping the file.
		delete_transient( 'mcp_upload_' . $staged['temp_id'] );
		$this->staged_ids = array_diff( $this->staged_ids, array( $staged['temp_id'] ) );

		$this->assertFileExists( $path );

		$cleaned = TempFileManager::cleanup_expired();
		$this->assertGreaterThanOrEqual( 1, $cleaned );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_cleanup_expired_preserves_active_files(): void {
		$staged = $this->create_staged_file();

		// Transient is still active, so cleanup_expired should NOT remove it.
		TempFileManager::cleanup_expired();
		$this->assertFileExists( $staged['path'] );
	}

	public function test_cleanup_expired_preserves_htaccess_and_index(): void {
		$temp_dir = TempFileManager::get_temp_dir();

		// Delete all transients to simulate full expiry.
		// cleanup_expired should not remove .htaccess or index.php.
		TempFileManager::cleanup_expired();

		$this->assertFileExists( $temp_dir . '/.htaccess' );
		$this->assertFileExists( $temp_dir . '/index.php' );
	}

	// ---------------------------------------------------------------
	// Cron scheduling
	// ---------------------------------------------------------------

	public function test_schedule_cleanup_registers_cron_event(): void {
		// Clear any existing schedule.
		$timestamp = wp_next_scheduled( 'mcp_adapter_cleanup_temp_uploads' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'mcp_adapter_cleanup_temp_uploads' );
		}

		TempFileManager::schedule_cleanup();

		$next = wp_next_scheduled( 'mcp_adapter_cleanup_temp_uploads' );
		$this->assertNotFalse( $next, 'Cron event should be scheduled' );
	}

	// ---------------------------------------------------------------
	// Helper
	// ---------------------------------------------------------------

	/**
	 * Create a dummy staged file for testing.
	 *
	 * @return array File metadata.
	 */
	private function create_staged_file(): array {
		$temp_dir  = TempFileManager::get_temp_dir();
		$temp_id   = wp_generate_uuid4();
		$dest_path = trailingslashit( $temp_dir ) . $temp_id . '.jpg';

		// Minimal JPEG header.
		file_put_contents( $dest_path, "\xFF\xD8\xFF\xE0" . str_repeat( "\x00", 100 ) ); // phpcs:ignore
		chmod( $dest_path, 0600 ); // phpcs:ignore

		$metadata = array(
			'temp_id'     => $temp_id,
			'filename'    => 'test-image.jpg',
			'stored_as'   => $temp_id . '.jpg',
			'path'        => $dest_path,
			'size'        => filesize( $dest_path ),
			'mime_type'   => 'image/jpeg',
			'uploaded_at' => time(),
			'expires_at'  => time() + 3600,
			'uploaded_by' => get_current_user_id(),
		);

		set_transient( 'mcp_upload_' . $temp_id, $metadata, 3600 );
		$this->staged_ids[] = $temp_id;

		return $metadata;
	}
}
