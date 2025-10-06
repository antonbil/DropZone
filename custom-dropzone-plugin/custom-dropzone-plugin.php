<?php
/**
 * Plugin Name:       Custom DropZone Uploader
 * Plugin URI:        https://familiebil.nl/anton
 * Description:       Provides configurable upload and management dropzones via shortcodes, based on a JSON configuration.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Anton Bil
 * Author URI:        https://familiebil.nl/anton
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       custom-dropzone-plugin
 * Domain Path:       /languages
 */
//custom-dropzone-plugin.php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// /wp-content/plugins/
// └── custom-dropzone-plugin/
//     ├── custom-dropzone-plugin.php   (Het hoofdbestand met de plugin header)
//     ├── class-dropzone.php           (Bevat de 3 PHP-klassen)
//     ├── config/
//     │   └── dropzones.json
//     └── js/
//         ├── custom-uploader.js
//         └── inhoud-ovd.js
//         └── (en je andere JS-bestanden)
// --- Load Classes and Functions ---
// This ensures all the classes and helper functions are available.
require_once plugin_dir_path( __FILE__ ) . 'class-dropzone.php';

// --- Define Plugin Constants ---
define( 'CUSTOM_DROPZONE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CUSTOM_DROPZONE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define('DROPZONE_DEBUG_ON', false);
/**
 * The core plugin initialization function.
 * This will load the JSON configuration and create the dropzone instances.
 */
function initialize_dropzone_plugin_from_json() {
    // --- DEBUGGING ---
    if(DROPZONE_DEBUG_ON)
    error_log('DEBUG: initialize_dropzone_plugin_from_json() is gestart!');
    // --- EINDE DEBUGGING ---
    // Determine the path to the JSON file within the plugin.
    $json_file_path = CUSTOM_DROPZONE_PLUGIN_PATH . 'config/dropzones.json';

    // Check if the file exists and is readable.
    if ( ! file_exists( $json_file_path ) || ! is_readable( $json_file_path ) ) {
        error_log( 'Custom DropZone Plugin Error: dropzones.json not found or not readable at: ' . $json_file_path );
        return;
    }

    // Read the file's contents.
    $json_content = file_get_contents( $json_file_path );
    $dropzone_configs = json_decode( $json_content, true );

    // Check if decoding was successful.
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $dropzone_configs ) ) {
        error_log( 'Custom DropZone Plugin Error: Could not correctly parse dropzones.json. Error: ' . json_last_error_msg() );
        return;
    }

    // Loop through each configuration.
    foreach ( $dropzone_configs as $config ) {
        if ( empty( $config['slug'] ) || empty( $config['file_rules'] ) ) {
            error_log( 'Custom DropZone Plugin Error: A configuration in dropzones.json is missing a "slug" or "file_rules".' );
            continue;
        }

        // Call the helper function (which is in class-dropzone.php).
        create_dropzone_instance(
            $config['slug'],
            $config['file_rules'],
            $config['ui_texts'] ?? [],
            $config['override_config'] ?? []
        );
    }
}
add_action( 'init', 'initialize_dropzone_plugin_from_json' );


/**
 * Overrides the default script loading to use plugin-specific paths.
 * We need to adjust the paths for our JS files now that they are in a plugin.
 *
 * This function hooks into the configuration of each DropZone instance
 * to correct the JS paths before they are enqueued.
 */
function correct_dropzone_script_paths( string $slug, array $file_rules, array $ui_texts = [], array $override_config = [] ) {
    // If an override path is already set, we assume it's correct and don't touch it.
    // If not, we generate the correct URL to the JS file inside our plugin.
    if ( ! isset( $override_config['uploader']['js_path'] ) ) {
        $override_config['uploader']['js_path'] = CUSTOM_DROPZONE_PLUGIN_URL . 'js/' . $slug . '-uploader.js';
    }
    if ( ! isset( $override_config['manager']['js_path'] ) ) {
        $override_config['manager']['js_path'] = CUSTOM_DROPZONE_PLUGIN_URL . 'js/' . $slug . '-manager.js';
    }

    // Re-call the original create_dropzone_instance with the corrected paths.
    // We remove the filter temporarily to prevent an infinite loop.
    remove_filter( 'create_dropzone_instance_config', 'correct_dropzone_script_paths', 10, 4 );
    create_dropzone_instance( $slug, $file_rules, $ui_texts, $override_config );
    add_filter( 'create_dropzone_instance_config', 'correct_dropzone_script_paths', 10, 4 );
}

// We need to modify `create_dropzone_instance` slightly to allow this filtering.
// In `class-dropzone.php`, change the first line of `create_dropzone_instance` to:
// list($slug, $file_rules, $ui_texts, $override_config) = apply_filters('create_dropzone_instance_config', [$slug, $file_rules, $ui_texts, $override_config]);
