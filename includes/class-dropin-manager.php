<?php
/**
 * Drop-in Manager for Fatal Plugin Auto Deactivator
 *
 * This class handles the creation and removal of the fatal-error-handler.php drop-in.
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class FPAD_Dropin_Manager
 */
class FPAD_Dropin_Manager {

	/**
	 * The path to the drop-in file in the wp-content directory
	 *
	 * @var string
	 */
	protected $dropin_path;

	/**
	 * The path to the source file for the drop-in
	 *
	 * @var string
	 */
	protected $source_path;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->dropin_path = WP_CONTENT_DIR . '/fatal-error-handler.php';
		$this->source_path = FPAD_PLUGIN_DIR . 'includes/fatal-error-handler-dropin.php';
	}

	/**
	 * Install the drop-in file
	 *
	 * @return bool True on success, false on failure
	 */
	public function install_dropin() {
		// Create the drop-in source file if it doesn't exist
		if ( ! file_exists( $this->source_path ) ) {
			$this->create_dropin_source();
		}

		// Check if we can write to the wp-content directory
		if ( ! is_writable( WP_CONTENT_DIR ) ) {
			error_log( 'Fatal Plugin Auto Deactivator: Cannot write to wp-content directory' );

			return false;
		}

		// Copy the drop-in file
		$result = copy( $this->source_path, $this->dropin_path );

		if ( $result ) {
			// Set the same permissions as the source file
			$perms = fileperms( $this->source_path );
			chmod( $this->dropin_path, $perms );
		}

		return $result;
	}

	/**
	 * Remove the drop-in file
	 *
	 * @return bool True on success, false on failure
	 */
	public function remove_dropin() {
		if ( file_exists( $this->dropin_path ) ) {
			// Check if the drop-in is ours before removing it
			$content = file_get_contents( $this->dropin_path );
			if ( strpos( $content, 'FPAD_Fatal_Error_Handler' ) !== false ) {
				return wp_delete_file( $this->dropin_path );
			}
		}

		return true;
	}

	/**
	 * Check if the drop-in is installed and up-to-date
	 *
	 * @return bool True if installed and up-to-date, false otherwise
	 */
	public function is_dropin_installed() {
		if ( ! file_exists( $this->dropin_path ) ) {
			return false;
		}

		// Check if the drop-in is ours
		$content = file_get_contents( $this->dropin_path );

		return strpos( $content, 'FPAD_Fatal_Error_Handler' ) !== false;
	}

	/**
	 * Create the drop-in source file
	 */
	protected function create_dropin_source() {
		// Make sure the includes directory exists
		if ( ! is_dir( FPAD_PLUGIN_DIR . 'includes' ) ) {
			mkdir( FPAD_PLUGIN_DIR . 'includes', 0755, true );
		}

		// Get the content of the fatal error handler class
		$handler_path = FPAD_PLUGIN_DIR . 'includes/class-fatal-error-handler.php';
		if ( ! file_exists( $handler_path ) ) {
			error_log( 'Fatal Plugin Auto Deactivator: Fatal error handler class not found' );

			return false;
		}

		// Create the drop-in source file
		$dropin_content = '<?php
/**
 * WordPress Fatal Error Handler
 *
 * This drop-in is part of the Fatal Plugin Auto Deactivator plugin.
 * It automatically deactivates plugins that cause fatal errors.
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// Include the fatal error handler class
if ( ! class_exists( \'FPAD_Fatal_Error_Handler\' ) ) {
	require_once \'' . FPAD_PLUGIN_DIR . 'includes/class-fatal-error-handler.php\';
}

// Return an instance of our custom error handler
return new FPAD_Fatal_Error_Handler();
';

		file_put_contents( $this->source_path, $dropin_content );
		chmod( $this->source_path, 0644 );

		return true;
	}
}
