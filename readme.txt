=== Fatal Plugin Auto Deactivator ===
Contributors: rudlinkon
Tags: fatal error, plugin deactivation, error handling, site protection, crash prevention
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically deactivates plugins that cause fatal errors to prevent site crashes and keep your WordPress site running smoothly.

== Description ==

The Fatal Plugin Deactivator plugin is a powerful tool designed to enhance the stability and reliability of your WordPress website. It automatically detects and deactivates plugins that cause fatal errors, preventing your entire site from crashing and becoming inaccessible.

### Key Features

* **Automatic Error Detection**: Monitors for fatal PHP errors in real-time
* **Smart Plugin Identification**: Identifies which plugin is causing the fatal error
* **Instant Deactivation**: Automatically deactivates the problematic plugin
* **Detailed Admin Notifications**: Provides clear notifications about which plugin was deactivated and why
* **Error Logging**: Records detailed information about the error for troubleshooting
* **Zero Configuration**: Works right out of the box with no setup required

### How It Works

When a fatal error occurs on your WordPress site, this plugin:

1. Captures the error details during the shutdown phase
2. Identifies which plugin is responsible for the error
3. Automatically deactivates only that specific plugin
4. Logs the action for reference
5. Displays an admin notice with details when you next log in

This prevents the dreaded "white screen of death" and keeps your site operational while you address the underlying issue.

### Use Cases

* **Development Environments**: Test new plugins without worrying about site crashes
* **Production Sites**: Add an extra layer of protection against unexpected plugin conflicts
* **Managed WordPress**: Essential tool for agencies and freelancers managing multiple client sites
* **WooCommerce Stores**: Prevent revenue loss from site downtime due to plugin errors

### Why You Need This Plugin

WordPress fatal errors can make your entire site inaccessible, requiring FTP or hosting panel access to fix. With Fatal Plugin Deactivator, your site remains operational even when a plugin causes a critical error, giving you time to address the issue without emergency measures.

== Installation ==

1. Upload the `fatal-plugin-deactivator` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. That's it! The plugin works automatically with no configuration needed

== Frequently Asked Questions ==

= Does this plugin require any configuration? =

No, Fatal Plugin Deactivator works automatically right after activation with no configuration required.

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

== Screenshots ==

1. Admin notification showing a deactivated plugin and error details

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of Fatal Plugin Deactivator.
