<?php
/**
 * The admin functionality of the plugin
 *
 * @link       https://theme4press.com/demo-awesome-the-data-importer/
 * @since      1.0.0
 * @package    Demo Awesome
 * @author     Theme4Press
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DEMO_AWESOME_IMPORTER_FOLDER')) {
    define('DEMO_AWESOME_IMPORTER_FOLDER', '/demo-awesome-import/');
}
if (!defined('DEMO_AWESOME_IMPORTER_SOURCE_URL')) {
    define('DEMO_AWESOME_IMPORTER_SOURCE_URL', esc_url('https://demo.theme4press.com/demo-import/'));
}

if (!class_exists('Demo_Awesome_Admin')) {
    class Demo_Awesome_Admin
    {

        /**
         * @since    1.0.0
         */
        private $plugin_name;

        /**
         * @since    1.0.0
         */
        private $version;

        /**
         * @since    1.0.0
         */
        public function __construct($plugin_name, $version)
        {

            $this->plugin_name = $plugin_name;
            $this->version = $version;

            add_action('admin_menu', array($this, 'importer_page'));
            add_action('load-plugins.php', array($this, 'admin_notice'));
            add_action('wp_ajax_call_import_function_from_ajax', array($this, 'call_import_function_from_ajax'));
            add_action('wp_ajax_required_plugins', array($this, 'required_plugins'));
            add_action('wp_ajax_evole_install_plugin', array($this, 'install_plugin'));
            add_action('wp_ajax_evole_activate_plugin', array($this, 'evole_activate_plugin'));
            add_action('demo_awesome_remove_old_posts_pages', array($this, 'remove_old_posts_pages'));
            add_action('wp_loaded', array($this, 'hide_notice'));
            add_filter(
                'demo_awesome_customizer_import_settings',
                array(
                    $this,
                    'update_customizer_data',
                ),
                10,
                2
            );
            add_filter('demo_awesome_widget_import_settings', array($this, 'update_widget_data'), 10, 4);

            if (isset($_GET['hide-notice'])
                && 'demo_awesome_no_theme4press_theme_notice' === sanitize_text_field(wp_unslash($_GET['hide-notice']))
                && isset($_GET['_demo_awesome_notice'])
                && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_demo_awesome_notice'])), 'demo_awesome_hide_notice')
                && current_user_can('edit_theme_options')) {
                update_option('demo_awesome_no_theme4press_theme_notice', 0);
            }
            if (get_option('demo_awesome_no_theme4press_theme_notice', 1)) {
                add_action('admin_notices', array($this, 'no_theme4press_theme_notice'));
            }

        }

        /**
         * @since    1.0.0
         */
        public function admin_notice()
        {
            if (!get_option('demo_awesome_activation_notice')) {
                add_action('admin_notices', array($this, 'activation_notice'));
                update_option('demo_awesome_activation_notice', 0);
            } elseif (get_option('demo_awesome_activation_notice') == '1') {
                // Don't show any notice
            }
        }

        /**
         * @since    1.0.0
         */
        public static function hide_notice()
        {
            if (isset($_GET['demo-awesome-hide-notice']) && 'activation_notice' === sanitize_text_field(wp_unslash($_GET['demo-awesome-hide-notice']))) {
                $notice_nonce = isset($_GET['_demo_awesome_notice']) ? sanitize_text_field(wp_unslash($_GET['_demo_awesome_notice'])) : '';
                if (!wp_verify_nonce($notice_nonce, 'demo_awesome_hide_notice')) {
                    wp_die(esc_html__('Action failed. Please refresh the page and retry.', 'demo-awesome'));
                }

                if (!current_user_can('manage_options')) {
                    wp_die(esc_html__('Cheatin&#8217; huh?', 'demo-awesome'));
                }

                $hide_notice = sanitize_text_field(wp_unslash($_GET['demo-awesome-hide-notice']));
                update_option('demo_awesome_'.$hide_notice, 1);
            }
        }

        /**
         * @since    1.0.0
         */
        function get_list_demos()
        {
            return $this->get_demo_packages(DEMO_AWESOME_IMPORTER_SOURCE_URL.'get-list-demos.json', 'get_list_demos');
        }

        /**
         * @since    1.0.0
         */
        function get_demo_packages($url, $template_name = '', $save_cache = true)
        {
            // Security: Validate URL
            if (!$this->validate_external_url($url)) {
                return false;
            }

            // Cache file name and transient key are derived from an md5 of the URL. (They used to
            // be base64, whose "=" padding was stripped when writing the file but not when reading
            // it, so every cached read of such a URL failed.)
            $cache_key = md5($url);
            $transient = 'demo_awesome_importer_packages_'.$cache_key;
            $cache_file = $cache_key.'.txt';

            // Use the cached copy if it is fresh and still valid; otherwise download again.
            if (false !== get_transient($transient)) {
                $file_path = wp_upload_dir()['basedir'].DEMO_AWESOME_IMPORTER_FOLDER.$cache_file;
                if (file_exists($file_path) && is_readable($file_path)) {
                    $packages = file_get_contents($file_path);
                    if ($packages && $this->validate_response_content($packages)) {
                        return $packages;
                    }
                }
            }

            // Security: Use wp_safe_remote_get with proper args
            $raw_packages = wp_safe_remote_get($url, array(
                'timeout' => 30,
                'redirection' => 3,
                'user-agent' => 'Demo Awesome Plugin',
                'sslverify' => true,
                'headers' => array(
                    'Accept' => 'application/json, text/plain, */*',
                )
            ));

            if (is_wp_error($raw_packages) || wp_remote_retrieve_response_code($raw_packages) !== 200) {
                return false;
            }

            $packages = wp_remote_retrieve_body($raw_packages);

            // Security: Validate response content
            if (!$packages || !$this->validate_response_content($packages)) {
                return false;
            }

            // Only mark the cache as fresh if the copy was actually written.
            if ($this->write_file_to_local($packages, $cache_file)) {
                set_transient($transient, time(), HOUR_IN_SECONDS);
            }

            return $packages;
        }

        /**
         * Validate external URL to prevent SSRF attacks
         * @since 1.0.3
         */
        private function validate_external_url($url) {
            // Check if URL is valid
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return false;
            }
            
            $parsed_url = wp_parse_url($url);
            
            // Check if URL has required components
            if (!isset($parsed_url['scheme']) || !isset($parsed_url['host'])) {
                return false;
            }
            
            // Only allow HTTPS
            if ($parsed_url['scheme'] !== 'https') {
                return false;
            }
            
            // Whitelist allowed hosts
            $allowed_hosts = ['demo.theme4press.com'];
            if (!in_array($parsed_url['host'], $allowed_hosts)) {
                return false;
            }
            
            // Check for localhost/private IP attempts
            $ip = gethostbyname($parsed_url['host']);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
            
            return true;
        }

        /**
         * Validate response content from external requests
         * @since 1.0.3
         */
        private function validate_response_content($content) {
            // Check for empty content
            if (empty($content)) {
                return false;
            }
            
            // Check content length (prevent DoS). Same limit as validate_file_content().
            if (strlen($content) > 50 * 1024 * 1024) {
                return false;
            }

            // No keyword filtering here: every response is cached and written through
            // write_file_to_local(), which validates it per file type (XML/JSON/serialized/ZIP)
            // and rejects PHP code. Blocking "<script", "javascript:" or "data:" rejected
            // legitimate demo content (e.g. cached Twitter/YouTube embeds in post meta),
            // which WordPress itself stores and imports as normal content.
            return true;
        }

        /**
         * Whether a downloaded file contains a PHP opening tag.
         * Demo data never legitimately contains PHP code.
         * @since 1.0.6
         */
        private function contains_php_code($content) {
            return (bool) preg_match('/<\?php|<\?=/i', $content);
        }

        /**
         * @since    1.0.0
         */
        function get_import_file_content($template_name)
        {
            return $this->get_demo_packages(
                DEMO_AWESOME_IMPORTER_SOURCE_URL.$template_name."/content.xml",
                $template_name
            );
        }

        /**
         * @since    1.0.0
         */
        function get_import_file_path($template_name)
        {
            return $this->write_file_to_local($this->get_import_file_content($template_name));
        }

        /**
         * @since    1.0.0
         */
        function write_xml_file_to_local($file_content)
        {
            return $this->write_file_to_local($file_content);
        }

        /**
         * @since    1.0.0
         */
        function write_file_to_local($file_content, $file_name = 'content.xml')
        {
            // Security: Validate and sanitize filename
            $file_name = $this->validate_and_sanitize_filename($file_name);
            if (!$file_name) {
                return false;
            }

            // Security: Validate file content
            if (!$this->validate_file_content($file_content, $file_name)) {
                return false;
            }

            global $wp_filesystem;
            // Initialize the WP filesystem, no more using 'file-put-contents' function
            if (empty($wp_filesystem)) {
                require_once wp_normalize_path(ABSPATH.'/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            
            $upload_dir = wp_upload_dir()['basedir'].DEMO_AWESOME_IMPORTER_FOLDER;
            if (!is_dir($upload_dir)) {
                // wp_mkdir_p() applies the correct, WordPress-managed directory permissions (FS_CHMOD_DIR).
                wp_mkdir_p($upload_dir);
            }
            
            $file_path = $upload_dir.$file_name;
            
            // Security: Additional path validation
            $real_upload_dir = realpath($upload_dir);
            $real_file_path = realpath(dirname($file_path));
            if ($real_file_path === false || strpos($real_file_path, $real_upload_dir) !== 0) {
                return false;
            }
            
            $result = $wp_filesystem->put_contents(
                $file_path,
                $file_content,
                FS_CHMOD_FILE // predefined mode settings for WP files
            );

            return $result ? $file_path : false;
        }

        /**
         * Validate and sanitize filename to prevent path traversal and malicious files
         * @since 1.0.3
         */
        private function validate_and_sanitize_filename($filename) {
            // Remove any path components
            $filename = basename($filename);
            
            // Check for empty filename
            if (empty($filename)) {
                return false;
            }
            
            // Check for path traversal attempts
            if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
                return false;
            }
            
            // Check for null bytes
            if (strpos($filename, "\0") !== false) {
                return false;
            }
            
            // Sanitize filename
            $filename = sanitize_file_name($filename);
            
            // Validate file extension
            $allowed_extensions = array('xml', 'dat', 'wie', 'zip', 'txt', 'json');
            $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (!in_array($file_extension, $allowed_extensions)) {
                return false;
            }
            
            // Check filename length
            if (strlen($filename) > 255) {
                return false;
            }
            
            return $filename;
        }

        /**
         * Validate file content to prevent malicious uploads
         * @since 1.0.3
         */
        private function validate_file_content($content, $filename) {
            // Check for empty content
            if (empty($content)) {
                return false;
            }
            
            // Check content length (prevent DoS)
            if (strlen($content) > 50 * 1024 * 1024) { // 50MB limit
                return false;
            }
            
            $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // Content validation based on file type
            switch ($file_extension) {
                case 'xml':
                    return $this->validate_xml_content($content);
                case 'json':
                    return $this->validate_json_content($content);
                case 'dat':
                    return $this->validate_dat_content($content);
                case 'wie':
                    return $this->validate_wie_content($content);
                case 'zip':
                    return $this->validate_zip_content($content);
                case 'txt':
                    return $this->validate_txt_content($content);
                default:
                    return false;
            }
        }

        /**
         * Validate XML content
         * @since 1.0.3
         */
        private function validate_xml_content($content) {
            // Check for PHP code injection
            if ($this->contains_php_code($content)) {
                return false;
            }
            
            // Basic XML structure validation.
            // Security: disable network access and external entity loading to
            // prevent XXE and entity-expansion (billion laughs) attacks. Never
            // use LIBXML_NOENT/LIBXML_DTDLOAD here, they enable the very attacks
            // this check is meant to guard against.
            if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
                $previous_loader = libxml_disable_entity_loader(true);
            }
            $previous_errors = libxml_use_internal_errors(true);

            $dom    = new DOMDocument();
            $loaded = $dom->loadXML($content, LIBXML_NONET);

            libxml_clear_errors();
            libxml_use_internal_errors($previous_errors);
            if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader') && isset($previous_loader)) {
                libxml_disable_entity_loader($previous_loader);
            }

            return $loaded !== false;
        }

        /**
         * Validate JSON content
         * @since 1.0.3
         */
        private function validate_json_content($content) {
            // Check for PHP code injection
            if ($this->contains_php_code($content)) {
                return false;
            }
            
            $decoded = json_decode($content, true);
            return json_last_error() === JSON_ERROR_NONE;
        }

        /**
         * Validate DAT content (serialized data)
         * @since 1.0.3
         */
        private function validate_dat_content($content) {
            // Check for PHP code injection
            if ($this->contains_php_code($content)) {
                return false;
            }
            
            // Basic validation for serialized data. Disable object instantiation
            // so the validation step itself cannot trigger PHP object injection.
            if (!is_serialized($content)) {
                return false;
            }
            $unserialized = unserialize(trim($content), array('allowed_classes' => false));
            return $unserialized !== false;
        }

        /**
         * Validate WIE content (widget import/export)
         * @since 1.0.3
         */
        private function validate_wie_content($content) {
            // Check for PHP code injection
            if ($this->contains_php_code($content)) {
                return false;
            }
            
            $decoded = json_decode($content, true);
            return json_last_error() === JSON_ERROR_NONE;
        }

        /**
         * Validate ZIP content
         * @since 1.0.3
         */
        private function validate_zip_content($content) {
            // Check ZIP file signature
            if (substr($content, 0, 2) !== 'PK') {
                return false;
            }
            
            // Additional ZIP validation could be added here
            return true;
        }

        /**
         * Validate TXT content
         * @since 1.0.3
         */
        private function validate_txt_content($content) {
            // Check for PHP code injection
            if ($this->contains_php_code($content)) {
                return false;
            }
            
            return true;
        }

        /**
         * @since    1.0.0
         */
        function remove_old_posts_pages()
        {
            $list_posts_pages = get_posts(
                array(
                    'posts_per_page' => -1,
                    'post_type' => array('post', 'page', 'product', 'slide', 'evolve_portfolio'),
                )
            );
            if ($list_posts_pages) {
                foreach ($list_posts_pages as $post_item) {
                    wp_delete_post($post_item->ID, true);
                }
            }
        }

        function sanitize_nested_array($array)
        {
            $sanitized_array = array();
            foreach ($array as $key => $value) {
                if (is_array($value)) {
                    $sanitized_array[$key] = $this->sanitize_nested_array($value);
                } else {
                    $sanitized_array[$key] = sanitize_text_field($value);
                }
            }
            return $sanitized_array;
        }

        /**
         * Enhanced validation and sanitization for demo data
         * @since 1.0.3
         */
        private function validate_and_sanitize_demo_data($data) {
            if (!is_array($data)) {
                return false;
            }

            $allowed_keys = array(
                'folder_path', 'name', 'premium_demo', 'has_theme4press_slider_data',
                'has_slider_revolution_data', 'has_layerslider_data', 'plugins',
                'option_update', 'update_galleries', 'customizer_data_update',
                'widgets_data_update', 'require_ver_premium', 'require_ver_free'
            );

            $sanitized_data = array();
            
            foreach ($data as $key => $value) {
                // Only allow whitelisted keys
                if (!in_array($key, $allowed_keys)) {
                    continue;
                }

                if (is_array($value)) {
                    $sanitized_data[$key] = $this->sanitize_nested_array($value);
                } else {
                    $sanitized_data[$key] = sanitize_text_field($value);
                }
            }

            return $sanitized_data;
        }

        /**
         * Validate template name to prevent directory traversal
         * @since 1.0.3
         */
        private function validate_template_name($template_name) {
            if (empty($template_name)) {
                return false;
            }

            // Check length
            if (strlen($template_name) > 100) {
                return false;
            }

            // Demo folders are "<category>/<number>" (e.g. "business/1"), so one "/" between
            // two safe segments is allowed. The whitelist excludes "..", backslashes, null
            // bytes, leading/trailing slashes and anything else that could leave the demo folder.
            return (bool) preg_match('#^[a-zA-Z0-9_-]+(/[a-zA-Z0-9_-]+)?$#', $template_name);
        }

        /**
         * Look up the demo the user picked in the demo list downloaded from the demo server.
         *
         * The browser only identifies the demo by its "index". Everything else (folder, required
         * plugins, options to update, slider flags) comes from the server-side list, never from
         * the request, so a forged request cannot change what gets downloaded or which options
         * get written.
         *
         * @since 1.0.6
         * @return array|false Normalized demo data, or false if the demo is not in the list.
         */
        private function get_requested_demo() {
            $index = '';
            if (isset($_POST['demo_index'])) {
                $index = sanitize_text_field(wp_unslash($_POST['demo_index']));
            } elseif (isset($_POST['data_demo']['index'])) {
                // Older admin.js sends the whole demo object; only its index is used.
                $index = sanitize_text_field(wp_unslash($_POST['data_demo']['index']));
            }
            if ($index === '' || !preg_match('/^[0-9]+$/', $index)) {
                return false;
            }

            $list = json_decode((string) $this->get_list_demos(), true);
            if (!is_array($list)) {
                return false;
            }
            foreach ($list as $category) {
                if (empty($category['items']) || !is_array($category['items'])) {
                    continue;
                }
                foreach ($category['items'] as $item) {
                    if (isset($item['index']) && (string) $item['index'] === $index) {
                        return $this->normalize_demo_data($item);
                    }
                }
            }

            return false;
        }

        /**
         * Fill in optional demo fields so later code can rely on them being set, and map the
         * demo list's "has_LayerSlider WP_data" key to the "has_layerslider_data" key used here.
         *
         * @since 1.0.6
         */
        private function normalize_demo_data($demo) {
            if (isset($demo['has_LayerSlider WP_data']) && !isset($demo['has_layerslider_data'])) {
                $demo['has_layerslider_data'] = $demo['has_LayerSlider WP_data'];
            }
            $defaults = array(
                'premium_demo' => false,
                'has_theme4press_slider_data' => false,
                'has_slider_revolution_data' => false,
                'has_layerslider_data' => false,
                'plugins' => array(),
                'option_update' => array(),
                'update_galleries' => array(),
                'customizer_data_update' => array(),
                'widgets_data_update' => array(),
            );

            return $this->validate_and_sanitize_demo_data(array_merge($defaults, $demo));
        }

        /**
         * Validate plugin name to prevent malicious plugin installation
         * @since 1.0.3
         */
        private function validate_plugin_name($plugin_name) {
            if (empty($plugin_name)) {
                return false;
            }

            // Check for path traversal attempts
            if (strpos($plugin_name, '..') !== false || 
                strpos($plugin_name, '/') !== false || 
                strpos($plugin_name, '\\') !== false) {
                return false;
            }

            // Check for null bytes
            if (strpos($plugin_name, "\0") !== false) {
                return false;
            }

            // Check length
            if (strlen($plugin_name) > 100) {
                return false;
            }

            // Only allow alphanumeric, hyphens, underscores, and spaces (covers both
            // plugin slugs and display names). The capability check
            // (install_plugins / activate_plugins) is the real authorization gate;
            // this only rejects path traversal, null bytes and regex-unsafe input.
            if (!preg_match('/^[a-zA-Z0-9_\- ]+$/', $plugin_name)) {
                return false;
            }

            return true;
        }

        /**
         * @since    1.0.0
         */
        function call_import_function_from_ajax()
        {
            // Security: Enhanced nonce verification
            if ( !wp_verify_nonce( isset($_POST['_nonce']) ? sanitize_text_field(wp_unslash($_POST['_nonce'])) : '', 'demo-awesome' ) ) {
                wp_send_json_error( array(
                    'message' => 'Security check failed. Please refresh the page and try again.',
                    'code' => 'invalid_nonce'
                ));
                wp_die();
            }

            // Security: Enhanced capability check
            if ( !current_user_can('manage_options') ) {
                wp_send_json_error( array(
                    'message' => 'You do not have sufficient permissions to access this feature.',
                    'code' => 'insufficient_permissions'
                ));
                wp_die();
            }

            // Security: the demo is looked up by index in the server-side demo list; no other
            // request data is used.
            $data_demo = $this->get_requested_demo();
            if ($data_demo === false) {
                wp_send_json_error( array(
                    'message' => esc_html__('The selected demo could not be found in the demo list. Please reload the page and try again. If it keeps happening, the demo server may be unreachable.', 'demo-awesome'),
                    'code' => 'demo_not_found'
                ));
                wp_die();
            }

            $template_name = isset($data_demo['folder_path']) ? $data_demo['folder_path'] : '';

            // Security: Validate template name
            if (!$this->validate_template_name($template_name)) {
                wp_send_json_error( array(
                    'message' => esc_html__('Invalid demo folder.', 'demo-awesome'),
                    'code' => 'invalid_template'
                ));
                wp_die();
            }

            // Check this before remove_old_datas(): refusing a premium demo must not
            // first wipe the site's theme settings, menus and widgets.
            if (Demo_Awesome_Admin::is_premium_theme() == false && $data_demo['premium_demo']) {
                wp_send_json_error(
                    array(
                        'message' => esc_html__('The premium demo requires the premium theme version', 'demo-awesome'),
                        'code' => 'premium_required'
                    )
                );
                wp_die();
            }

            // Download and validate the demo content before removing anything, so a failed
            // download leaves the site untouched. The file is cached, so the import step below
            // reuses it instead of downloading it again.
            if (!$this->get_import_file_path($template_name)) {
                wp_send_json_error( array(
                    'message' => esc_html__('The demo content could not be downloaded from the demo server, or it failed validation. Nothing on your site was changed. Please try again later.', 'demo-awesome'),
                    'code' => 'download_failed'
                ));
                wp_die();
            }

            $this->remove_old_datas($data_demo, $template_name);
            // Import content data
            $this->import_content_theme($data_demo, $template_name);
            // Import customizer
            $this->import_customizer_data($data_demo, $template_name);
            // Fix menu
            $this->update_nav_menu_items($data_demo, $template_name);
            // Fix option
            $this->update_option_data($data_demo, $template_name);
            // Import widget
            $this->import_widget_settings($data_demo, $template_name);
            // Import Theme4Press slider
            $this->import_theme4press_slider($data_demo, $template_name);
            // Import Slider Revolution slider
            $this->import_slider_revolution($data_demo, $template_name);
            // Import LayerSlider slider
            $this->import_layerslider($data_demo, $template_name);
            // Fix galleries data
            $this->update_galleries_data($data_demo, $template_name);

            wp_send_json_success(
                array(
                    'success' => true,
                    'message' => sprintf(
                        '<div>%1$s<span class="mr-1">%2$s</span>%3$s%4$s%5$s</div>',
                        '<h3>',
                        Demo_Awesome_Admin::get_svg('check'),
                        esc_html__('Import finished', 'demo-awesome'),
                        '</h3>',
                        esc_html__('The demo has been imported successfully', 'demo-awesome')
                    ),
                )
            );

            wp_die(); // this is required to terminate immediately and return a proper response
        }

        /**
         * @since    1.0.0
         */
        function remove_old_datas($data_demo, $template_name)
        {

            do_action('demo_awesome_begin_remove_old_datas');

            delete_option('theme_mods_evolve-plus');
            delete_option('theme_mods_evolve');

            // remove all of old nav menus
            $list_menus = get_posts(
                array(
                    'post_type' => 'nav_menu_item',
                    'posts_per_page' => -1,
                )
            );
            if ($list_menus) {
                foreach ($list_menus as $menu) {
                    wp_delete_post($menu->ID, true);
                }
            }

            // remove all widgets data
            $list_widgets = wp_load_alloptions();
            if ($list_widgets) {
                delete_option('sidebars_widgets');
                foreach ($list_widgets as $option => $value) {
                    if (strpos($option, 'widget_') === 0) {
                        delete_option($option);
                    }
                }
            }
            do_action('demo_awesome_remove_old_posts_pages');

            // fix data for premium theme version need to import free data demo version
            if (Demo_Awesome_Admin::is_premium_theme() == true && !$data_demo['premium_demo']) {
                update_option('check_updated_to_new_bootstrap_slider_data_', false);
                update_option('check_updated_to_new_parallax_slider_data_', false);
                update_option('check_updated_to_new_content_boxes_data_', false);
                update_option('check_updated_to_new_testimonials_data_', false);
                update_option('check_updated_to_new_counter_circle_data_', false);
            }

        }

        /**
         * @since    1.0.0
         */
        function required_plugins()
        {
            // Security: Enhanced nonce verification
            if (!wp_verify_nonce(isset($_POST['_nonce']) ? sanitize_text_field(wp_unslash($_POST['_nonce'])) : '', 'demo-awesome')) {
                wp_die(esc_html__('Security check failed.', 'demo-awesome'));
            }
            
            // Security: Enhanced capability check
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have sufficient permissions.', 'demo-awesome'));
            }

            
            // Include the required plugins list
            require dirname(__FILE__).'/required-plugins.php';
            // Security: the demo (and so its required-plugin list) comes from the server-side
            // demo list, looked up by index; no other request data is used.
            $data_demo = $this->get_requested_demo();
            if ($data_demo === false) {
                wp_die(esc_html__('The selected demo could not be found in the demo list. Please reload the page and try again.', 'demo-awesome'));
            }

            demo_awesome_required_plugins($data_demo);
            wp_die(); // this is required to terminate immediately and return a proper response
        }

        function evole_activate_plugin()
        {
            // Security: Enhanced nonce verification
            if (!wp_verify_nonce(isset($_POST['_nonce']) ? sanitize_text_field(wp_unslash($_POST['_nonce'])) : '', 'demo-awesome')) {
                wp_die(esc_html__('Security check failed.', 'demo-awesome'));
            }
    
            // Security: Enhanced capability check
            if (!current_user_can('activate_plugins')) {
                wp_die(esc_html__('Sorry, you are not allowed to activate plugins on this site.', 'demo-awesome'));
            }

            
            // Security: Validate and sanitize plugin name
            $plugin = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
            if (!$this->validate_plugin_name($plugin)) {
                wp_die(esc_html__('Invalid plugin name provided.', 'demo-awesome'));
            }
            
            $activate = isset($_POST['activate']) ? true : false;

            if ($activate == false) {
                if ($plugin == 'LayerSlider WP') {
                    if (function_exists('evolve_get_update_plugin')) {
                        evolve_get_update_plugin('layerslider');
                    }
                    $plugin = 'LayerSlider';
                } elseif ($plugin == 'slider-revolution') {
                    $plugin = 'revslider';
                } else {
                    if (function_exists('evolve_get_update_plugin')) {
                        evolve_get_update_plugin($plugin);
                    }
                }

            }
            $html_raw = isset($_POST['html']) ? wp_kses_post(wp_unslash($_POST['html'])) : '';
            $html = str_replace('Not installed', 'Not active', $html_raw);
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'all_plugins' is a WordPress core filter.
            $all_plugins = apply_filters('all_plugins', get_plugins());
            foreach ($all_plugins as $k => $item) {

                if (preg_match('#'.preg_quote($plugin, '#').'#', $k)) {
                    activate_plugin($k);
                }
            }

            echo  wp_kses_post($html);

            die();

        }

        function install_plugin()
        {   
            // Security: Enhanced nonce verification
            if (!wp_verify_nonce(isset($_POST['_nonce']) ? sanitize_text_field(wp_unslash($_POST['_nonce'])) : '', 'demo-awesome')) {
                wp_die(esc_html__('Security check failed.', 'demo-awesome'));
            }

            // Security: Enhanced capability check
            if (!current_user_can('install_plugins')) {
                wp_die(esc_html__('Sorry, you are not allowed to install plugins on this site.', 'demo-awesome'));
            }


            // Security: Validate and sanitize plugin name
            $plugin = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
            if (!$this->validate_plugin_name($plugin)) {
                wp_die(esc_html__('Invalid plugin name provided.', 'demo-awesome'));
            }
            
            $activate = isset($_POST['activate']) ? true : false;

            if ($activate == false) {
                if ($plugin == 'LayerSlider WP') {
                    if (function_exists('evolve_get_update_plugin')) {
                        evolve_get_update_plugin('layerslider');
                    }
                    $plugin = 'LayerSlider';
                } elseif ($plugin == 'slider-revolution') {
                    $plugin = 'revslider';
                } else {
                    if (function_exists('evolve_get_update_plugin')) {
                        evolve_get_update_plugin($plugin);
                    }
                }

            }

            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'all_plugins' is a WordPress core filter.
            $all_plugins = apply_filters('all_plugins', get_plugins());

            foreach ($all_plugins as $k => $item) {

                if (preg_match('#'.preg_quote($plugin, '#').'#', $k)) {
                    activate_plugin($k);
                }
            }
            $html_raw = isset($_POST['html']) ? wp_kses_post(wp_unslash($_POST['html'])) : '';
            $html = str_replace('Not installed', 'Not active', $html_raw);

            echo wp_kses_post($html);


            wp_die(); // this is required to terminate immediately and return a proper response

        }

        /**
         * @since    1.0.0
         */
        function get_import_file_path_from_live_demo($template_name, $file_name)
        {
            return $this->write_file_to_local(
                $this->get_demo_packages(DEMO_AWESOME_IMPORTER_SOURCE_URL.$template_name.'/'.$file_name),
                $file_name
            );
        }

        /**
         * Determine whether an option name is too sensitive to be written from
         * remote demo data (prevents privilege escalation / takeover if the demo
         * source is ever compromised or spoofed).
         *
         * @since 1.0.5
         */
        private function is_protected_option($option_name)
        {
            $protected = array(
                'users_can_register', 'default_role', 'siteurl', 'home',
                'admin_email', 'new_admin_email', 'template', 'stylesheet',
                'active_plugins', 'mailserver_url', 'mailserver_login',
                'mailserver_pass', 'mailserver_port', 'cron', 'rewrite_rules',
                'ftp_credentials', 'auth_key', 'auth_salt', 'secret',
                'db_version', 'WPLANG', 'fileupload_url',
            );

            return in_array($option_name, $protected, true);
        }

        /**
         * Deprecation-safe replacement for get_page_by_title() (removed/deprecated
         * in modern WordPress). Returns the first matching post object or null.
         *
         * @since 1.0.5
         */
        private function get_page_by_title_compat($title, $post_type = 'page')
        {
            $args = array(
                'post_type'              => $post_type,
                'title'                  => $title,
                'posts_per_page'         => 1,
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
                'orderby'                => 'post_date ID',
                'order'                  => 'ASC',
            );

            if ('attachment' === $post_type) {
                $args['post_status'] = 'inherit';
            }

            $query = new WP_Query($args);

            return !empty($query->posts) ? $query->posts[0] : null;
        }

        /**
         * @since    1.0.0
         */
        function update_option_data($data_demo, $template_name = 'blog')
        {
            if (!empty($data_demo['option_update'])) {
                foreach ($data_demo['option_update'] as $data_type => $data_value) {
                    if ($data_type == 'update_pages' && $data_value && is_array($data_value) && count($data_value)) {
                        foreach ($data_value as $option_name => $option_value) {
                            // Security: never let demo data target a protected option.
                            if ($this->is_protected_option($option_name)) {
                                continue;
                            }
                            $page = $this->get_page_by_title_compat($option_value);
                            if (is_object($page) && $page->ID) {
                                update_option($option_name, $page->ID);
                            }
                        }
                    } else {
                        // Security: never let demo data target a protected option.
                        if ($this->is_protected_option($data_type)) {
                            continue;
                        }
                        update_option($data_type, $data_value);
                    }
                }
            }
        }

        /**
         * @since    1.0.0
         */
        function update_galleries_data($data_demo, $template_name = 'blog')
        {
            if (!empty($data_demo['update_galleries']) && !empty($data_demo['update_galleries']['pages'])) {
                foreach ($data_demo['update_galleries']['pages'] as $data_value) {
                    if (!empty($data_value['title'])) {
                        $page = $this->get_page_by_title_compat($data_value['title']);
                        if (is_object($page) && $page->ID) {
                            foreach ($data_value['items'] as $shortcode_values) {
                                $names = explode(',', $shortcode_values['names']);
                                $ids = explode(',', $shortcode_values['ids']);
                                $new_ids = array();
                                foreach ($ids as $id_key => $id) {
                                    $attach = $this->get_page_by_title_compat($names[$id_key], 'attachment');
                                    if (is_object($attach) && $attach->ID) {
                                        $new_ids[] = $attach->ID;
                                    }
                                }
                                if ($new_ids) {
                                    $new_ids_string = implode(',', $new_ids);
                                    $post_content_new = str_replace(
                                        'ids="'.$shortcode_values['ids'],
                                        'ids="'.$new_ids_string,
                                        $page->post_content
                                    );
                                    $my_post = array(
                                        'ID' => $page->ID,
                                        'post_content' => $post_content_new,
                                    );
                                    // Update the post into the database
                                    wp_update_post($my_post);
                                }
                            }
                        }

                    }
                }
            }
        }

        /**
         * @since    1.0.0
         */
        public function import_content_theme($data_demo, $template_name = 'blog')
        {

            $import_file = $this->get_import_file_path($template_name);

            // Load Importer API.
            require_once ABSPATH.'wp-admin/includes/import.php';

            if (!class_exists('WP_Importer')) {
                $class_wp_importer = ABSPATH.'wp-admin/includes/class-wp-importer.php';

                if (file_exists($class_wp_importer)) {
                    require $class_wp_importer;
                }
            }

            // Include Class Demo Awesome Importer
            require_once dirname(__FILE__).'/importer/class-demo-awesome-importer.php';

            if (is_file($import_file)) {
                $wp_import = new Demo_Awesome_Importer();
                $wp_import->fetch_attachments = true;

                ob_start();
                $wp_import->import($import_file);
                ob_end_clean();

                flush_rewrite_rules();
            } else {
                $status['errorMessage'] = esc_html__('The content data file (XML) is missing.', 'demo-awesome');
                wp_send_json_error($status);
            }

            return true;
        }

        /**
         * @since    1.0.0
         */
        function import_theme4press_slider($data_demo, $template_name = 'blog')
        {

            if ($data_demo['has_theme4press_slider_data']) {
                if (class_exists('Theme4Press_Slider')) {
                    $import_file = $this->get_import_file_path_from_live_demo($template_name, 'theme4press_slider.zip');

                    if (is_file($import_file)) {
                        $theme4press_slider = new Theme4Press_Slider();
                        $theme4press_slider->import_sliders($import_file);
                    } else {
                        $status['errorMessage'] = esc_html__(
                            'The Theme4Press Slider data file (ZIP) is missing.',
                            'demo-awesome'
                        );
                        wp_send_json_error($status);
                    }
                }
            }

            return true;
        }

        /**
         * @since    1.0.0
         */
        function import_slider_revolution($data_demo, $template_name = 'blog')
        {

            if ($data_demo['has_slider_revolution_data']) {
                if (Demo_Awesome_Admin::is_plugin_activated('Slider Revolution')) {
                    $import_file = $this->get_import_file_path_from_live_demo($template_name, 'slider_revolution.zip');

                    if (is_file($import_file)) {

                        $_FILES["import_file"]["tmp_name"] = $import_file;
                        $slider = new RevSlider();
                        $results = $slider->importSliderFromPost();

                        if (is_wp_error($results)) {
                            return false;
                        }
                    } else {
                        $status['errorMessage'] = esc_html__(
                            'The Slider Revolution data file (ZIP) is missing.',
                            'demo-awesome'
                        );
                        wp_send_json_error($status);
                    }
                }
            }

            return true;
        }

        /**
         * @since    1.0.0
         */
        function import_layerslider($data_demo, $template_name = 'blog')
        {

            if ($data_demo['has_layerslider_data']) {
                if (Demo_Awesome_Admin::is_plugin_activated('LayerSlider WP')) {
                    $import_file = $this->get_import_file_path_from_live_demo($template_name, 'layerslider.zip');

                    if (is_file($import_file)) {
                        include_once LS_ROOT_PATH.'/classes/class.ls.importutil.php';
                        $results = new LS_ImportUtil($import_file, 'layerslider.zip');

                        if (is_wp_error($results)) {
                            return false;
                        }
                    } else {
                        $status['errorMessage'] = esc_html__(
                            'The LayerSlider data file (ZIP) is missing.',
                            'demo-awesome'
                        );
                        wp_send_json_error($status);
                    }
                }
            }

            return true;
        }

        /**
         * @since    1.0.0
         */
        function import_widget_settings($data_demo, $template_name = 'blog')
        {

            require dirname(__FILE__).'/importer/class-demo-awesome-widget-importer.php';

            $import_file = $this->get_import_file_path_from_live_demo($template_name, 'widgets.wie');

            if (is_file($import_file)) {
                $results = Demo_Awesome_Widget_Importer::import($import_file, $data_demo);

                if (is_wp_error($results)) {
                    return false;
                }
            } else {
                $status['errorMessage'] = esc_html__('The widget data file (WIE) is missing.', 'demo-awesome');
                wp_send_json_error($status);
            }

            return true;
        }

        /**
         * @since    1.0.0
         */
        function import_customizer_data($data_demo, $template_name = 'blog')
        {
            require dirname(__FILE__).'/importer/class-demo-awesome-customizer-importer.php';

            $import_file = $this->get_import_file_path_from_live_demo($template_name, 'customizer.dat');

            if (is_file($import_file)) {
                $results = Demo_Awesome_Customizer_Importer::import($import_file, $data_demo);

                if (is_wp_error($results)) {
                    return false;
                }
            } else {
                $status['errorMessage'] = esc_html__('The customizer data file (DAT) is missing.', 'demo-awesome');
                wp_send_json_error($status);
            }

            return true;
        }

        /**
         * @since    1.0.0
         */
        function update_nav_menu_items()
        {
            $menu_locations = get_nav_menu_locations();

            foreach ($menu_locations as $location => $menu_id) {

                if (is_nav_menu($menu_id)) {
                    $menu_items = wp_get_nav_menu_items($menu_id, array('post_status' => 'any'));

                    if (!empty($menu_items)) {
                        foreach ($menu_items as $menu_item) {
                            if (isset($menu_item->url) && isset($menu_item->db_id) && 'custom' == $menu_item->type) {
                                $site_parts = wp_parse_url(home_url('/'));
                                $menu_parts = wp_parse_url($menu_item->url);

                                // Update existing custom nav menu item URL.
                                if (isset($menu_parts['path']) && isset($menu_parts['host']) && apply_filters(
                                        'demo_awesome_importer_nav_menu_item_url_hosts',
                                        in_array($menu_parts['host'], array('demo.theme4press.com'))
                                    )) {
                                    $menu_item->url = str_replace(
                                        array(
                                            $menu_parts['scheme'],
                                            $menu_parts['host'],
                                            $menu_parts['path'],
                                        ),
                                        array(
                                            $site_parts['scheme'],
                                            $site_parts['host'],
                                            trailingslashit($site_parts['path']),
                                        ),
                                        $menu_item->url
                                    );
                                    update_post_meta($menu_item->db_id, '_menu_item_url', esc_url_raw($menu_item->url));
                                }
                            }
                        }
                    }
                }
            }
        }

        /**
         * @since    1.0.0
         */
        function update_customizer_data($data, $demo_data = array())
        {
            if (empty($demo_data['customizer_data_update'])) {
                $demo_data['customizer_data_update']['nav_menu_locations'] = array(
                    'primary-menu' => 'Main menu',
                    'sticky_navigation' => 'Main menu',
                );
            }
            if (!empty($demo_data['customizer_data_update'])) {
                foreach ($demo_data['customizer_data_update'] as $data_type => $data_value) {
                    if (!in_array($data_type, array('pages', 'categories', 'nav_menu_locations'))) {
                        continue;
                    }

                    // Format the value based on data type.
                    switch ($data_type) {
                        case 'pages':
                            foreach ($data_value as $option_key => $option_value) {
                                if (!empty($data['mods'][$option_key])) {
                                    $page = $this->get_page_by_title_compat($option_value);

                                    if (is_object($page) && $page->ID) {
                                        $data['mods'][$option_key] = $page->ID;
                                    }
                                }
                            }
                            break;
                        case 'categories':
                            foreach ($data_value as $taxonomy => $taxonomy_data) {
                                if (!taxonomy_exists($taxonomy)) {
                                    continue;
                                }

                                foreach ($taxonomy_data as $option_key => $option_value) {
                                    if (!empty($data['mods'][$option_key])) {
                                        $term = get_term_by('name', $option_value, $taxonomy);

                                        if (is_object($term) && $term->term_id) {
                                            $data['mods'][$option_key] = $term->term_id;
                                        }
                                    }
                                }
                            }
                            break;
                        case 'nav_menu_locations':
                            $nav_menus = wp_get_nav_menus();

                            if (!empty($nav_menus)) {
                                foreach ($nav_menus as $nav_menu) {
                                    if (is_object($nav_menu)) {
                                        foreach ($data_value as $location => $location_name) {
                                            if ($nav_menu->name == $location_name) {
                                                $data['mods'][$data_type][$location] = $nav_menu->term_id;
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                    }
                }
            }

            return $data;
        }

        /**
         * @since    1.0.0
         */
        function update_widget_data($widget, $widget_type, $instance_id, $demo_data)
        {
            if ('nav_menu' == $widget_type) {
                $nav_menu = wp_get_nav_menu_object($widget['title']);

                if (is_object($nav_menu) && $nav_menu->term_id) {
                    $widget['nav_menu'] = $nav_menu->term_id;
                }
            } elseif (!empty($demo_data['widgets_data_update'])) {
                foreach ($demo_data['widgets_data_update'] as $dropdown_type => $dropdown_data) {
                    if (!in_array($dropdown_type, array('dropdown_pages', 'dropdown_categories'))) {
                        continue;
                    }

                    // Format the value based on dropdown type.
                    switch ($dropdown_type) {
                        case 'dropdown_pages':
                            foreach ($dropdown_data as $widget_id => $widget_data) {
                                if (!empty($widget_data[$instance_id]) && $widget_id == $widget_type) {
                                    foreach ($widget_data[$instance_id] as $widget_key => $widget_value) {
                                        $page = $this->get_page_by_title_compat($widget_value);

                                        if (is_object($page) && $page->ID) {
                                            $widget[$widget_key] = $page->ID;
                                        }
                                    }
                                }
                            }
                            break;
                        case 'dropdown_categories':
                            foreach ($dropdown_data as $taxonomy => $taxonomy_data) {
                                if (!taxonomy_exists($taxonomy)) {
                                    continue;
                                }

                                foreach ($taxonomy_data as $widget_id => $widget_data) {
                                    if (!empty($widget_data[$instance_id]) && $widget_id == $widget_type) {
                                        foreach ($widget_data[$instance_id] as $widget_key => $widget_value) {
                                            $term = get_term_by('name', $widget_value, $taxonomy);

                                            if (is_object($term) && $term->term_id) {
                                                $widget[$widget_key] = $term->term_id;
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                    }
                }
            }

            return $widget;
        }

        /**
         * @since    1.0.0
         */
        public static function is_theme4press_theme()
        {
            $demo_awesome_my_theme = wp_get_theme();
            if ($demo_awesome_my_theme->get('Name') == 'evolve' || $demo_awesome_my_theme->get(
                    'Name'
                ) == 'evolve Child' || $demo_awesome_my_theme->get(
                    'Name'
                ) == 'evolve Plus' || $demo_awesome_my_theme->get('Name') == 'evolve Plus Child') {
                return true;
            }

            return false;
        }

        /**
         * @since    1.0.0
         */
        public function activation_notice()
        {
            if (!Demo_Awesome_Admin::is_theme4press_theme()) {
                return;
            }

            wp_enqueue_style('demo-awesome-notice', plugin_dir_url(__FILE__).'css/notice.css', array(), $this->version); ?>

            <div class="notice demo-awesome-notice is-dismissible">
                <p>
                    <img src="<?php echo esc_url(plugin_dir_url(__FILE__)); ?>images/logo.png"/>
                    <?php
                    echo wp_kses_post( sprintf(
                        /* translators: 1: opening <strong> tag, 2: closing </strong> tag. */
                        esc_html__(
                            'Thank you for installing %1$sDemo Awesome%2$s plugin by Theme4Press. To start importing a demo content please visit the importer page',
                            'demo-awesome'
                        ),
                        '<strong>',
                        '</strong>'
                    ) ); ?>
                    <a class="button"
                       href="<?php echo esc_url(
                           admin_url('themes.php?page=demo-awesome-importer')
                       ); ?>"><?php esc_html_e('Let\'s Get Started', 'demo-awesome'); ?></a>
                    <a href="<?php echo esc_url(
                        wp_nonce_url(
                            remove_query_arg(
                                array('activated'),
                                add_query_arg('demo-awesome-hide-notice', 'activation_notice')
                            ),
                            'demo_awesome_hide_notice',
                            '_demo_awesome_notice'
                        )
                    ); ?>"><?php esc_html_e('Dismiss', 'demo-awesome'); ?></a>
                </p>
            </div>
            <?php
        }

        /**
         * @since    1.0.0
         */
        public function no_theme4press_theme_notice()
        {
            if (Demo_Awesome_Admin::is_theme4press_theme()) {
                return;
            }

            wp_enqueue_style('demo-awesome-notice', plugin_dir_url(__FILE__).'css/notice.css', array(), $this->version); ?>

            <div class="notice demo-awesome-notice is-dismissible">
                <p><?php echo wp_kses_post( Demo_Awesome_Admin::is_theme4press_theme_message()); ?><a
                            href="<?php echo esc_url(
                                wp_nonce_url(
                                    add_query_arg('hide-notice', 'demo_awesome_no_theme4press_theme_notice'),
                                    'demo_awesome_hide_notice',
                                    '_demo_awesome_notice'
                                )
                            ); ?>"><?php esc_html_e('Dismiss', 'demo-awesome'); ?></a>
                </p>
            </div>
            <?php
        }

        /**
         * @since    1.0.0
         */
        public static function is_theme4press_theme_message()
        {
            $message = '';
            if (!Demo_Awesome_Admin::is_theme4press_theme()) {
                $message = "<span><img src='".esc_url(plugin_dir_url(__FILE__))."images/logo.png' />".sprintf(
                        /* translators: 1,3: opening <strong> tag, 2,4: closing </strong> tag. */
                        esc_html__(
                            'The %1$sDemo Awesome%2$s plugin is designed only for %3$sTheme4Press%4$s themes',
                            'demo-awesome'
                        ),
                        '<strong>',
                        '</strong>',
                        '<strong>',
                        '</strong>'
                    )."</span><a class='button button-primary' target='_blank' href='".get_admin_url(
                           )."theme-install.php?search=theme4press"."'>".esc_html__(
                               'Install theme',
                               'demo-awesome'
                           )."</a>";
            }

            return $message;
        }

        /**
         * @since    1.0.0
         */
        public static function is_free_theme()
        {
            $demo_awesome_my_theme = wp_get_theme();
            if ($demo_awesome_my_theme->get('Name') == 'evolve' || $demo_awesome_my_theme->get(
                    'Name'
                ) == 'evolve Child') {
                return true;
            }

            return false;
        }

        /**
         * @since    1.0.0
         */
        public static function is_premium_theme()
        {
            $demo_awesome_my_theme = wp_get_theme();
            if ($demo_awesome_my_theme->get('Name') == 'evolve Plus' || $demo_awesome_my_theme->get(
                    'Name'
                ) == 'evolve Plus Child') {
                return true;
            }

            return false;
        }

        /**
         * @since    1.0.0
         */
        public static function get_svg($icon = null)
        {

            if (empty($icon)) {
                return '';
            }

            $icon = esc_attr($icon);

            // Constructing the SVG element
            $svg = '<svg class="demo-awesome-icon-' . $icon . '" aria-hidden="true" role="img">';
            $svg .= '<use xlink:href="' . esc_url(plugin_dir_url(__FILE__) . '/images/icons.svg#demo-awesome-icon-' . $icon) . '"></use>';
            $svg .= '</svg>';

            return $svg;
        }

        /**
         * Allowlist for escaping markup that may contain inline SVG icons (from
         * get_svg()). wp_kses_post() strips <svg>/<use>, so callers pass this list
         * to wp_kses() to permit the icon markup in addition to standard post tags.
         *
         * Usage: echo wp_kses( $html, Demo_Awesome_Admin::allowed_svg_tags() );
         *
         * @since 1.0.5
         * @return array Allowed HTML/SVG tags for wp_kses().
         */
        public static function allowed_svg_tags()
        {
            $svg_tags = array(
                'svg'   => array(
                    'class'           => true,
                    'aria-hidden'     => true,
                    'aria-labelledby' => true,
                    'role'            => true,
                    'xmlns'           => true,
                    'width'           => true,
                    'height'          => true,
                    'viewbox'         => true,
                    'fill'            => true,
                    'focusable'       => true,
                ),
                'g'     => array('fill' => true),
                'title' => array('title' => true),
                'path'  => array('d' => true, 'fill' => true),
                'use'   => array('href' => true, 'xlink:href' => true),
            );

            return array_merge(wp_kses_allowed_html('post'), $svg_tags);
        }

        /**
         * @since    1.0.0
         */
        public static function is_plugin_installed($plugin)
        {

            if (!function_exists('get_plugins')) {
                require_once ABSPATH.'wp-admin/includes/plugin.php';
            }

            $all_plugins = get_plugins();

            foreach ($all_plugins as $single_plugin) {
                if (isset($single_plugin['Name']) && $single_plugin['Name'] == $plugin) {
                    return true;
                }
            }

            return false;
        }

        /**
         * @since    1.0.0
         */
        public static function is_plugin_activated($plugin)
        {

            if (!function_exists('get_plugins')) {
                require_once ABSPATH.'wp-admin/includes/plugin.php';
            }

            $all_plugins = get_plugins();
            $active_plugins = get_option('active_plugins');

            foreach ($active_plugins as $item) {
                if (isset($all_plugins[$item]['Name']) && $all_plugins[$item]['Name'] == $plugin) {
                    return true;
                }
            }

            return false;
        }

        /**
         * @since    1.0.0
         */
        function importer_page()
        {
            if (!current_user_can('manage_options')) {
                return;
            }
            add_theme_page(
                esc_html__('Demo Awesome - The Data Importer', 'demo-awesome'),
                esc_html__('Demo Awesome', 'demo-awesome'),
                'edit_theme_options',
                'demo-awesome-importer',
                array(
                    $this,
                    'demo_browser',
                )
            );
        }

        /**
         * @since    1.0.0
         */
        function demo_browser()
        { ?>
            <div class="wrap">
                <h1 class="wp-heading-inline"><?php echo esc_html__(
                        'Demo Awesome - The Data Importer',
                        'demo-awesome'
                    ); ?></h1>

                <hr class="wp-header-end">
                <?php
                $demo_awesome_get_list_demos = $this->get_list_demos();
                require plugin_dir_path(__FILE__).'/demo-browser.php';
                ?>
            </div>

        <?php }

        /**
         * @since    1.0.0
         */
        public function enqueue_styles($hook)
        {

            if ('appearance_page_demo-awesome-importer' != $hook) {
                return;
            }

            wp_enqueue_style('demo-awesome', plugin_dir_url(__FILE__).'css/admin.css', array(), $this->version);
        }

        /**
         * @since    1.0.0
         */
        public function enqueue_scripts($hook)
        {

            if ('appearance_page_demo-awesome-importer' != $hook) {
                return;
            }

            wp_enqueue_script('demo-awesome', plugin_dir_url(__FILE__).'js/admin.js', array('jquery'), $this->version, true);
          
          
            $local_variables = array(
                'close_button' => esc_html__('Close', 'demo-awesome'),
                'back_button' => esc_html__('Back', 'demo-awesome'),
                'next_button' => esc_html__('Next', 'demo-awesome'),
                'import_button' => esc_html__('I Understand, Begin Import', 'demo-awesome'),
                'plugin_url' => plugin_dir_url(__FILE__),
                'website_url' => get_site_url(),
                'admin_url' => admin_url(),
                'plugin_home_url' => esc_url('https://theme4press.com/'),
                'is_premium_version' => Demo_Awesome_Admin::is_premium_theme(),
                'is_free_version' => Demo_Awesome_Admin::is_free_theme(),
                'is_theme4press_theme' => Demo_Awesome_Admin::is_theme4press_theme(),
                '_nonce'    => wp_create_nonce('demo-awesome')
            );

            wp_localize_script('demo-awesome', 'demo_awesome_js_local_vars', $local_variables);
        }
    }
}