<?php
/**
 * Plugin Name:       Custom DropZone Uploader
 * Plugin URI:        https://github.com/antonbil/DropZone
 * Description:       A flexible plugin to create and manage file upload zones via shortcodes.
 * Version:           1.1.0
 * Author:            antonbil
 * Author URI:        https://github.com/antonbil
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dropzone-manager
 * Domain Path:       /languages
 */

// Prevent direct access
if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Loads the plugin's text domain for translations.
 */
function dropzone_manager_load_textdomain() {
    load_plugin_textdomain(
        'dropzone-manager',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('plugins_loaded', 'dropzone_manager_load_textdomain');

// --- Load Classes and Functions ---
require_once plugin_dir_path(__FILE__) . 'class-dropzone.php';

// --- Define Plugin Constants ---
define('CUSTOM_DROPZONE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CUSTOM_DROPZONE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('DROPZONE_DEBUG_ON', false);

/**
 * The core function that initializes the plugin.
 * It loads the JSON configuration and creates the dropzone instances.
 */
function initialize_dropzone_plugin_from_json() {
    // --- DEBUGGING ---
    if ( DROPZONE_DEBUG_ON ) {
        error_log(
            // This string is for developers and does not need to be translated.
            'DEBUG: initialize_dropzone_plugin_from_json() has started!'
        );
    }
    // --- END DEBUGGING ---

    $json_file_path = CUSTOM_DROPZONE_PLUGIN_PATH . 'config/dropzones.json';

    if ( ! file_exists($json_file_path) || ! is_readable($json_file_path) ) {
        error_log(
            sprintf(
                /* translators: %s is the file path that could not be found. */
                __('Custom DropZone Plugin Error: dropzones.json not found or not readable at: %s', 'dropzone-manager'),
                $json_file_path
            )
        );
        return;
    }

    $json_content   = file_get_contents($json_file_path);
    $dropzone_configs = json_decode($json_content, true);

    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array($dropzone_configs) ) {
        error_log(
            sprintf(
                /* translators: %s is the specific JSON error message. */
                __('Custom DropZone Plugin Error: Could not correctly parse dropzones.json. Error: %s', 'dropzone-manager'),
                json_last_error_msg()
            )
        );
        return;
    }

    foreach ( $dropzone_configs as $config ) {
        if ( empty($config['slug']) || empty($config['file_rules']) ) {
            error_log(
                __('Custom DropZone Plugin Error: A configuration in dropzones.json is missing a "slug" or "file_rules".', 'dropzone-manager')
            );
            continue;
        }

        // Call the helper function to instantiate the uploader and manager.
        create_dropzone_instance(
            $config['slug'],
            $config['file_rules'],
            $config['ui_texts'] ?? [],
            $config['override_config'] ?? []
        );
    }
}
add_action('init', 'initialize_dropzone_plugin_from_json');

// The 'correct_dropzone_script_paths' function and its corresponding filter have been removed.
// This logic is now better handled within the OOP structure of the classes themselves,
// where paths are constructed during object initialization. This significantly simplifies the main plugin file.
