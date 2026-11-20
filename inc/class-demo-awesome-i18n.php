<?php
/**
 * Define the internationalization functionality
 *
 * @link       https://theme4press.com/demo-awesome-the-data-importer/
 * @since      1.0.0
 * @package    Demo Awesome
 * @author     Theme4Press
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Demo_Awesome_i18n' ) ) {
	class Demo_Awesome_i18n {

		/**
		 * @since    1.0.0
		 */
		public function load_plugin_textdomain() {

			// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Retained so bundled translations also load on non-wordpress.org installs.
			load_plugin_textdomain(
				'demo-awesome',
				false,
				dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
			);
		}
	}
}