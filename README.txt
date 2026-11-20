=== Demo Awesome ===
Contributors: evgenyviner
Tags: data importer, one-click demo import, theme demo, demos, importer
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Import the Theme4Press theme demo content including theme settings, menus, widgets, sliders, and much more with just one click. Awesome!

== Description ==

Demo Awesome - The Data Importer is a plugin designed especially for [Theme4Press](https://theme4press.com/) themes users to help start building outstanding websites with one click. The unique predefined demos will set your website with modern theme settings, demo posts and pages, widgets, menus, and much more. Create blogs, magazines, or e-shops within a few minutes. Awesome!

== Installation ==

* a) Install the plugin through the WordPress plugins page by searching the **Demo Awesome** term and clicking the **Install Now** button
  b) If you downloaded the plugin manually, upload the **demo-awesome** plugin folder (unzipped) to the **/wp-content/plugins/** directory of your WordPress installation

* Activate the plugin through the **Plugins** menu in WordPress

== Frequently Asked Questions ==

= What themes is this plugin compatible with? =

The Demo Awesome plugin is designed only for themes by Theme4Press

= What website niche I can import with this plugin? =

The plugin with a free Theme4Press theme lets you import demos for a business website, magazine, or an e-shop

= Do demos require additional plugins? =

Yes, some demos, for example, e-shops require plugins. The importer will always show you the list of required plugins for the particular demo which you can install instantly

= Are these demos recommended for existing websites? =

We strongly recommend using these demos for newly created websites, development websites, etc. as the plugin will change or remove much existing content of your website like posts, pages, widgets, theme settings, etc.

== External services ==

This plugin connects to the Theme4Press demo server, **demo.theme4press.com**, operated by Theme4Press. The demos are stored there, so the plugin cannot work without it.

What is requested and when:

* **Opening the demo browser** (Appearance > Demo Awesome): the plugin downloads the list of available demos (`get-list-demos.json`). Your browser loads each demo's screenshot image from the demo server.
* **Live preview** of a demo: the demo website on demo.theme4press.com is shown in a frame, so it is loaded by your browser.
* **Importing a demo**: the plugin downloads that demo's content, widget and theme-settings files (and slider files for demos that have them), and WordPress downloads the demo's images.

No personal data, account data or content from your site is sent. As with any web request, the demo server receives the requesting IP address and browser or server details; image downloads made by WordPress also include your site's address in the standard WordPress user-agent header.

Premium demos may require premium plugins. With the evolve Plus theme, these are downloaded by the theme from Theme4Press using the theme's license key; Demo Awesome itself does not send the key. Free required plugins are installed from WordPress.org.

Theme4Press privacy policy: https://theme4press.com/privacy-policy/

== Screenshots ==

1. Main page of the demo importer - demo browser
2. Live preview of a demo directly on the admin page
3. The pop up will inform about required plugins for a particular demo
4. Once the import is finished, you can start to customize

== Changelog ==

= 1.0.6 =
* Fixed the demo import doing nothing but showing "requires additional plugins" (a stray duplicate AJAX action introduced by an earlier release)
* Removed the over-aggressive request rate limit that blocked legitimate importing/plugin installation
* Allowed required-plugin installation for any valid plugin slug (was limited to a small hardcoded list); hardened the activation match against regex injection
* Fixed every import failing with "Invalid template name" (demo folders such as "business/1" were rejected)
* Fixed 12 of the 20 demos being rejected because their content contains ordinary embed scripts; downloads are now validated by file format and rejected only if they contain PHP code
* Security: the demo to import is now looked up by its index in the demo list from the demo server; folder, options and required plugins are no longer taken from the browser request
* Fixed a premium demo, or a failed download, removing the site's theme settings, menus and widgets before the import was refused; nothing is removed until the demo content has been downloaded and validated
* Fixed the download cache: cached files of some demos (App 1, App 2, Blog 1, Portfolio 1) could never be read back, so any repeat import within an hour failed; a missing or invalid cached copy is now downloaded again
* Import errors are now shown in the import window instead of failing silently
* Fixed LayerSlider data never being imported (demo list key mismatch) and PHP warnings for demos without slider data

= 1.0.5 =
* Security: prevented PHP object injection when importing customizer (.dat) data
* Security: hardened XML validation against XXE / entity-expansion attacks
* Security: blocked demo data from overwriting sensitive WordPress options
* Updated the bundled WordPress Importer to 0.8.3 (security & bug fixes, incl. safer attachment downloads and object-injection-safe meta handling)
* Fixed a bug that disabled the remote demo list cache (every admin page load re-fetched it)
* Replaced the deprecated get_page_by_title() with a WP_Query-based helper
* Prevented a fatal error in the plugin install/activate handlers when the theme helper is unavailable
* Various WordPress coding-standard fixes (text domains, output escaping, script/style enqueue)

= 1.0.2 =
* Fixed some security vulnerablities

= 1.0.1 =
* Corrected a bug, which caused the message "Missing plugins" to appear, when all required plugins are actually installed.

= 1.0.0 =
* Initial Release