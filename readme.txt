=== Fatal Plugin Auto Deactivator - Never let a plugin break your site ===
Contributors: rudlinkon
Tags: fatal error, plugin deactivation, error handling, site protection, crash prevention
Requires at least: 5.2
Tested up to: 6.8
Stable tag: 1.0.1
Requires PHP: 7.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically deactivates plugins that cause fatal errors to prevent site crashes and keep your WordPress site running smoothly.

== Description ==

The Fatal Plugin Auto Deactivator plugin is a powerful tool designed to enhance the stability and reliability of your WordPress website. It automatically detects and deactivates plugins that cause fatal errors, preventing your entire site from crashing and becoming inaccessible.

### Key Features

* **Automatic Error Detection**: Monitors for fatal PHP errors in real-time using WordPress drop-in technology
* **Smart Plugin Identification**: Identifies which plugin is causing the fatal error through stack trace analysis
* **Instant Deactivation**: Automatically deactivates the problematic plugin during the shutdown phase
* **Detailed Admin Notifications**: Provides clear notifications about which plugin was deactivated and why
* **Persistent Error Logging**: Records detailed information about errors in a permanent log for troubleshooting
* **Zero Configuration**: Works right out of the box with no setup required
* **Custom Error Page**: Displays a user-friendly error page with a reload button instead of the white screen of death
* **Debug-Aware Display**: Shows detailed error information only when WP_DEBUG_DISPLAY is enabled for security
* **Drop-in Management**: Automatically installs and manages WordPress fatal-error-handler.php drop-in

### How It Works

This plugin uses WordPress's built-in drop-in system to provide the most reliable error handling possible. When activated, it:

1. **Installs a Drop-in**: Creates a `fatal-error-handler.php` file in your wp-content directory
2. **Monitors for Errors**: WordPress automatically uses this drop-in when fatal errors occur
3. **Captures Error Details**: Records the error message, file, and line number during the shutdown phase
4. **Identifies the Plugin**: Analyzes the error stack trace to determine which plugin caused the issue
5. **Deactivates Safely**: Automatically deactivates only the problematic plugin
6. **Logs Everything**: Stores detailed error information in a permanent log for troubleshooting
7. **Notifies Admins**: Displays clear admin notices with error details when you next log in
8. **Shows User-Friendly Pages**: Displays a custom error page with reload button instead of the white screen of death

The drop-in approach ensures maximum reliability since it operates at the WordPress core level, even when other plugins fail.

### Use Cases

* **Development Environments**: Test new plugins without worrying about site crashes
* **Production Sites**: Add an extra layer of protection against unexpected plugin conflicts
* **Managed WordPress**: Essential tool for agencies and freelancers managing multiple client sites
* **WooCommerce Stores**: Prevent revenue loss from site downtime due to plugin errors

### Why You Need This Plugin

WordPress fatal errors can make your entire site inaccessible, requiring FTP or hosting panel access to fix. With Fatal Plugin Auto Deactivator, your site remains operational even when a plugin causes a critical error, giving you time to address the issue without emergency measures.

== Installation ==

1. Upload the `fatal-plugin-auto-deactivator` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The plugin will automatically install the required drop-in file (`fatal-error-handler.php`) in your wp-content directory
4. That's it! The plugin works automatically with no configuration needed

**Note**: The plugin requires write permissions to your wp-content directory to install the drop-in file. If activation fails, check your file permissions.

== Frequently Asked Questions ==

= Does this plugin require any configuration? =

No, Fatal Plugin Auto Deactivator works automatically right after activation with no configuration required.

= Will this plugin slow down my website? =

No, the plugin only activates its core functionality during the PHP shutdown phase and only takes action when a fatal error is detected.

= What types of errors does this plugin catch? =

The plugin catches fatal PHP errors, parse errors, and other critical errors that would normally crash your site.

= Will I be notified when a plugin is deactivated? =

Yes, an admin notice will be displayed in your WordPress dashboard showing which plugin was deactivated and the specific error that caused it.

= Can I reactivate a deactivated plugin? =

Yes, you can reactivate the plugin through the normal WordPress plugins page. However, be aware that if the issue hasn't been fixed, the plugin will be deactivated again if it causes another fatal error.

= Does this work with multisite installations? =

The current version is designed for standard WordPress installations. Multisite support may be added in future updates.

= How does the plugin detect which plugin caused a fatal error? =

The plugin analyzes the error stack trace to identify which plugin file triggered the fatal error, then deactivates only that specific plugin.

= Will this plugin prevent all types of errors? =

This plugin specifically targets fatal PHP errors that would normally make your site inaccessible. It doesn't handle warnings, notices, or other non-fatal errors.

= What is a drop-in and why does this plugin use one? =

A drop-in is a special type of WordPress file that replaces core functionality. This plugin uses the `fatal-error-handler.php` drop-in to ensure it can handle errors even when other plugins fail. The drop-in is automatically installed when you activate the plugin and removed when you deactivate it.

= Will the drop-in conflict with other plugins? =

No, the drop-in is specifically designed for fatal error handling and won't conflict with other plugins. If another plugin tries to install its own fatal error handler drop-in, this plugin will detect it and avoid overwriting it.

= Why do I see detailed error information sometimes but not others? =

For security reasons, detailed error information (file paths, line numbers, error messages) is only displayed when WP_DEBUG_DISPLAY is enabled in your WordPress configuration. When disabled, visitors see a generic error message while administrators still receive detailed notifications in the dashboard.

= Where are the error logs stored? =

Error logs are stored in your WordPress database as options. The plugin maintains both temporary logs (for admin notifications) and permanent logs (for troubleshooting history). You can view these through your WordPress admin dashboard.

== Screenshots ==

1. Fatal error detected. Problematic plugin auto-deactivated (requires WP_DEBUG true).
2. Fatal error detected. Problematic plugin auto-deactivated (WP_DEBUG is false).
3. Admin notification showing a deactivated plugin and error details
2. Plugin causing fatal error was auto-deactivated for site safety.

== Changelog ==

= 1.0.1 - 25/05/2025 =
- Improved: Security Enhancement
- Few minor bug fixes & improvements

= 1.0.0 - 22/05/2025 =
- Initial release

== Upgrade Notice ==

= 1.0.1 =
We have improved security and fixed few bugs. Please update to the latest version.
