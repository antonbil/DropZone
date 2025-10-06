<?php

class FileUtils {

    private $config;
    private $default_config = [
        'target_subdir_pattern'     => null,
        'filename_keyword_ensure'   => null,
        'filename_keyword_suffix'   => null,
        'name_pattern_prefix_regex' => '/.*/',
        'name_pattern_keyword_regex'=> '/.*/',
        'name_pattern_extension_regex' => '/\.[a-zA-Z0-9]+$/i',
        'allowed_mime_types'        => [],
        'list_files_glob_pattern'   => '*.pdf', // For listing files
        'validate_filename_on_list' => false, // Whether files in the list should also be strictly validated with regexes
    ];

    public function __construct(array $custom_config = []) {
        $this->config = array_merge($this->default_config, $custom_config);
    }

    public function ensure_keyword_in_filename(string $file_name): string {
        $keyword = $this->config['filename_keyword_ensure'];
        $suffix  = $this->config['filename_keyword_suffix'];

        if ($keyword === null || $suffix === null) {
            return $file_name;
        }
        if (strpos(strtolower($file_name), strtolower($keyword)) === false) {
            $filename_no_ext = pathinfo($file_name, PATHINFO_FILENAME);
            $extension       = pathinfo($file_name, PATHINFO_EXTENSION);
            if (!empty($extension)) {
                return $filename_no_ext . $suffix . '.' . $extension;
            }
            return $filename_no_ext . $suffix;
        }
        return $file_name;
    }

    public function validate_file_properties(array $file_upload_data, string $file_name_to_validate): array {
        $errors     = [];
        $file_type  = $file_upload_data['type'] ?? ''; // For uploads
        $file_error = $file_upload_data['error'] ?? UPLOAD_ERR_NO_FILE; // For uploads

        if (isset($file_upload_data['error']) && $file_error !== UPLOAD_ERR_OK) {
            // ... (upload error handling as before)
            switch ($file_error) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errors[] = 'The uploaded file is too large.';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errors[] = 'The file was only partially uploaded.';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errors[] = 'No file was uploaded.';
                    break;
                default:
                    $errors[] = 'Unknown upload error (server code: ' . $file_error . ').';
            }
            return $errors;
        }

        if (isset($file_upload_data['type']) && !empty($this->config['allowed_mime_types']) && !in_array($file_type, $this->config['allowed_mime_types'], true)) {
            $errors[] = 'Invalid file type. Allowed: ' . implode(', ', $this->config['allowed_mime_types']);
        }
        return $this->validate_filename_structure($file_name_to_validate, $errors);
    }

    public function validate_filename_structure(string $file_name_to_validate, array $existing_errors = []): array {
        $errors = $existing_errors;
        if ($this->config['name_pattern_prefix_regex'] && !preg_match($this->config['name_pattern_prefix_regex'], $file_name_to_validate)) {
            $errors[] = 'Filename does not meet the required prefix pattern.';
        }
        if ($this->config['name_pattern_keyword_regex'] && !preg_match($this->config['name_pattern_keyword_regex'], $file_name_to_validate)) {
            $errors[] = 'Filename does not meet the required keyword pattern.';
        }
        if ($this->config['name_pattern_extension_regex'] && !preg_match($this->config['name_pattern_extension_regex'], $file_name_to_validate)) {
            $errors[] = 'Filename does not meet the required extension pattern (or is missing an extension).';
        }
        return $errors;
    }


    public function get_target_path_info() {
        $upload_dir_info = wp_upload_dir();
        if (!$upload_dir_info || !empty($upload_dir_info['error'])) {
             return new WP_Error('upload_dir_error', $upload_dir_info['error'] ?? 'Could not retrieve upload directory information.');
        }

        $target_subdir = '';
        if (!empty($this->config['target_subdir_pattern'])) {
            $pattern = trim($this->config['target_subdir_pattern'], '/');
            if (preg_match('/[YymFdDgGhHisua]/', $pattern)) {
                $target_subdir = '/' . date_i18n($pattern) . '/';
            } else {
                $target_subdir = '/' . $pattern . '/';
            }
        } else {
            return new WP_Error('target_dir_not_configured', 'Target directory (target_subdir_pattern or target_year/month) is not specified.');
        }

        $target_subdir = str_replace('//', '/', $target_subdir);
        $target_path = $upload_dir_info['basedir'] . $target_subdir;

        if (!file_exists($target_path)) {
            if (!wp_mkdir_p($target_path)) {
                return new WP_Error('dir_creation_failed', 'Could not create target directory on server: ' . $target_path);
            }
        }
         if (!is_dir($target_path)) { // Extra check
            return new WP_Error('target_not_dir', 'Target path is not a directory: ' . $target_path);
        }

        return [
            'path'    => trailingslashit($target_path), // Absolute server path to the target directory
            'url'     => trailingslashit($upload_dir_info['baseurl'] . $target_subdir), // URL to the target directory
            'baseurl' => $upload_dir_info['baseurl'],
            'subdir'  => $target_subdir
        ];
    }

   /**
     * Lists files in the configured target directory based on the glob pattern.
     * @return array An array of filenames. Can be empty if no files are found or the directory does not exist/is not readable.
     */
    public function list_files_in_target_dir(): array {
        $path_info = $this->get_target_path_info();
        if (is_wp_error($path_info)) {
            // Log the error and return an empty array to not break the flow elsewhere,
            // or the WP_Error can be passed on and handled elsewhere.
            error_log("FileUtils Error in list_files_in_target_dir: " . $path_info->get_error_message());
            return [];
        }

        $target_dir_path = $path_info['path'];
        $glob_pattern = $this->config['list_files_glob_pattern'] ?? '*.*'; // Default to all files if not specified

        if (!is_readable($target_dir_path)) {
             error_log("FileUtils Error: Target directory not readable: " . $target_dir_path);
            return [];
        }

        $found_files_paths = glob($target_dir_path . $glob_pattern, GLOB_BRACE | GLOB_ERR);

        if ($found_files_paths === false) { // glob can return false on error, not just an empty array
            error_log("FileUtils Error: glob() failed for pattern: " . $target_dir_path . $glob_pattern);
            return [];
        }

        $filenames = [];
        foreach ($found_files_paths as $file_path) {
            $filename = basename($file_path);
            // Optional extra validation on the filename itself
            if ($this->config['validate_filename_on_list']) {
                $validation_errors = $this->validate_filename_structure($filename);
                if (!empty($validation_errors)) {
                    continue; // Skip files that do not meet the strict naming structure
                }
            }
            $filenames[] = $filename;
        }

        sort($filenames); // Sort alphabetically
        return $filenames;
    }

    /**
     * Sanitizes and validates a filename for use in file operations.
     * Combines WordPress' sanitize_file_name with basename to prevent directory traversal.
     * @param string $raw_filename The raw filename.
     * @return string The sanitized filename.
     */
    public function sanitize_and_prepare_filename(string $raw_filename): string {
        // First, remove any path information with basename
        // and stripslashes in case magic quotes are on (though deprecated)
        $filename_only = basename(stripslashes($raw_filename));
        // Then use WordPress' own function for further sanitization
        return sanitize_file_name($filename_only);
    }

    /**
     * Helper to construct a full URL to a file in the target directory.
     * @param string $filename The filename.
     * @return string|WP_Error The URL or a WP_Error.
     */
    public function get_file_url(string $filename) {
        $path_info = $this->get_target_path_info();
        if (is_wp_error($path_info)) {
            return $path_info;
        }
        return $path_info['url'] . rawurlencode($filename);
    }

} // End of the FileUtils class

class DropZoneManager {

    private $shortcode_tag = 'file_manager'; // Can be made configurable
    private $ajax_action   = 'process_file_actions';
    private $nonce_action  = 'file_actions_nonce';
    private $js_handle     = 'file-manager-js';
    private $js_path       = '/dropzone/js/file-manager.js'; // Relative to stylesheet directory

    /** @var FileUtils */
    private $file_utils;

    /** @var array Configuration specific to this manager instance */
    private $manager_config;

    /**
    * Constructor.
    * @param array $manager_config Configuration specific to this manager.
    * @param FileUtils $file_utils_instance A pre-configured instance of FileUtils.
    */
    public function __construct(array $manager_config, FileUtils $file_utils_instance) {
        $this->manager_config = $manager_config; // Store the manager-specific config
        $this->file_utils = $file_utils_instance; // Assign the instance directly
        // Override defaults with values from manager_config if provided
        $this->shortcode_tag = $manager_config['shortcode_tag'] ?? $this->shortcode_tag;
        $this->ajax_action   = $manager_config['ajax_action']   ?? $this->ajax_action;
        $this->nonce_action  = $manager_config['nonce_action']  ?? $this->nonce_action;
        $this->js_handle     = $manager_config['js_handle']     ?? $this->js_handle;
        $this->js_path       = $manager_config['js_path']       ?? $this->js_path;


        $this->register_shortcode();
        $this->register_ajax_handler();
    }

    /**
     * Registers the shortcode.
     */
    public function register_shortcode() {
        // --- DEBUGGING ---
        if(DROPZONE_DEBUG_ON)
            error_log('DEBUG: Shortcode wordt geregistreerd met tag: ' . $this->shortcode_tag);
        // --- EINDE DEBUGGING ---
        add_shortcode($this->shortcode_tag, array($this, 'render_shortcode_content'));
    }

    /**
     * Renders the HTML for the shortcode.
     * @return string HTML output.
     */
    public function render_shortcode_content() {
        // --- DEBUGGING ---
        if(DROPZONE_DEBUG_ON)
            error_log('DEBUG: render_shortcode_content() wordt uitgevoerd voor tag: ' . $this->shortcode_tag);
        if(DROPZONE_DEBUG_ON)
            error_log('DEBUG: Pad naar JS-bestand: ' . $this->js_path);
        // --- EINDE DEBUGGING ---

        wp_enqueue_script(
            $this->js_handle,
            $this->js_path,
            array('jquery'),
            '1.0.2-' . time(), // DYNAMISCHE NIEUWE VERSIE (cache buster)
            true
        );

        wp_localize_script($this->js_handle, 'file_manager_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce($this->nonce_action),
            'action'   => $this->ajax_action // VOEG DEZE REGEL TOE!
        ));

        // Optional: Enqueue CSS
        if (!empty($this->manager_config['css_path'])) {
             wp_enqueue_style(
                $this->manager_config['css_handle'] ?? ($this->js_handle . '-css'), // Generate a css handle
                $this->manager_config['css_path'],
                array(),
                $this->manager_config['css_version'] ?? '1.0.0'
            );
        }


        $path_info = $this->file_utils->get_target_path_info();
        if (is_wp_error($path_info)) {
            return "<p>Error initializing file manager: " . esc_html($path_info->get_error_message()) . "</p>";
        }

        $files = $this->file_utils->list_files_in_target_dir();
        // $dir_url = $path_info['url']; // URL to the target directory

        // Title for the section, can also come from config
        $section_title = $this->manager_config['section_title'] ?? 'File Overview';
        if (strpos($section_title, '%s') !== false && isset($path_info['subdir'])) {
            // Replace placeholder with the subdirectory name (e.g., 2025/01)
            $subdir_display = trim($path_info['subdir'], '/');
            $section_title = sprintf($section_title, esc_html($subdir_display));
        }


        ob_start();
        ?>
        <div id="file-manager-container"> <?php // ID can also come from config ?>
            <h2><?php echo esc_html($section_title); ?></h2>

            <?php if (is_wp_error($files)) : ?>
                 <p>Error retrieving files: <?php echo esc_html($files->get_error_message()); ?></p>
            <?php elseif (!empty($files)) : ?>
                <form id="file-actions-form"> <?php // ID can also come from config ?>
                    <p>Select files to delete or provide a new name.</p>
                    <ul id="file-list" style="list-style-type: none; padding-left: 0;"> <?php // ID can also come from config ?>
                        <?php foreach ($files as $index => $filename) :
                            $file_id = 'file-' . $index . '-' . sanitize_title($filename);
                            $file_url_path_or_error = $this->file_utils->get_file_url($filename);
                            $file_url_path = is_wp_error($file_url_path_or_error) ? '#' : $file_url_path_or_error;
                        ?>
                            <li class="file-item" data-filename="<?php echo esc_attr($filename); ?>">
                                <input type="checkbox" name="delete_files[]" value="<?php echo esc_attr($filename); ?>" id="delete-<?php echo esc_attr($file_id); ?>">
                                <label for="delete-<?php echo esc_attr($file_id); ?>" style="margin-right: 10px;">Delete</label>

                                <a href="<?php echo esc_url($file_url_path); ?>" target="_blank"><?php echo esc_html($filename); ?></a>

                                <button type="button" class="rename-toggle-btn" data-target="rename-<?php echo esc_attr($file_id); ?>" style="margin-left:10px; font-size:0.8em;">Rename</button>
                                <span class="rename-field-wrapper" style="display:none; margin-left:5px;">
                                    <input type="text" name="rename_files[<?php echo esc_attr($filename); ?>]" class="new-name-input" id="rename-<?php echo esc_attr($file_id); ?>" placeholder="New name..." value="<?php echo esc_attr($filename); ?>" style="width:auto; font-size:0.9em; padding:2px;">
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" id="prepare-actions-btn" class="button button-primary" style="margin-top: 20px;">Review Proposed Actions</button>
                </form>

                 <div id="confirmation-area" style="margin-top: 20px; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; display: none;"> <?php // ID can also come from config ?>
                    <h4>Confirm the following actions:</h4>
                    <div id="actions-summary">
                        <!-- Summary of actions is inserted here by JavaScript -->
                    </div>
                    <button type="button" id="execute-actions-btn" class="button button-danger" style="margin-right: 10px;">Yes, Execute Actions</button>
                    <button type="button" id="cancel-actions-btn" class="button">Cancel</button>
                </div>

                <div id="feedback-area" style="margin-top: 15px;"> <?php // ID can also come from config ?>
                    <!-- Feedback from the server is shown here -->
                </div>

            <?php else : // No files found ?>
                <?php
                    $no_files_message = $this->manager_config['no_files_message'] ?? 'No files found in the directory %s that match the pattern.';
                    $subdir_display_for_message = '';
                    if (isset($path_info['subdir'])) {
                        $subdir_display_for_message = trim($path_info['subdir'], '/');
                    }
                    if (strpos($no_files_message, '%s') !== false) {
                         $no_files_message = sprintf($no_files_message, esc_html($subdir_display_for_message));
                    }
                ?>
                <p><?php echo esc_html($no_files_message); ?></p>
            <?php endif; // End of if (!empty($files)) ?>
        </div><!-- #file-manager-container -->
        <?php
        return ob_get_clean();
    }

    /**
     * Registers the AJAX handler.
     */
    public function register_ajax_handler() {
        add_action('wp_ajax_' . $this->ajax_action, array($this, 'handle_file_actions_callback'));
        // add_action('wp_ajax_nopriv_' . $this->ajax_action, array($this, 'handle_file_actions_callback')); // If needed
    }

    /**
     * AJAX handler for processing file actions (delete, rename).
     */
    public function handle_file_actions_callback() {
        check_ajax_referer($this->nonce_action, 'nonce');

        $capability_manage = $this->manager_config['capability_manage'] ?? 'manage_options';
        if (!current_user_can($capability_manage)) {
            wp_send_json_error(array('message' => 'Insufficient permissions to perform these actions.'), 403);
            return;
        }

        $files_to_delete     = isset($_POST['delete_files']) ? (array) $_POST['delete_files'] : array();
        $files_to_rename_raw = isset($_POST['rename_files']) ? (array) $_POST['rename_files'] : array();

        $results = array(
            'deleted' => array(),
            'renamed' => array(),
            'errors'  => array()
        );

        $path_info = $this->file_utils->get_target_path_info();
        if (is_wp_error($path_info)) {
            wp_send_json_error(array('message' => 'Error accessing target directory: ' . $path_info->get_error_message()), 500);
            return;
        }
        $target_dir_path = $path_info['path'];

        // --- Process deletions ---
        foreach ($files_to_delete as $filename_raw) {
            $filename = $this->file_utils->sanitize_and_prepare_filename($filename_raw);
            if (empty($filename)) {
                $results['errors'][] = "Invalid filename for deletion (empty after sanitization): " . esc_html($filename_raw);
                continue;
            }

            // Validation of the filename to be deleted (optional, but provides extra security)
            $validation_errors_delete = $this->file_utils->validate_filename_structure($filename);
            if (!empty($validation_errors_delete)) {
                 $results['errors'][] = "File '" . esc_html($filename) . "' does not meet the structural requirements for deletion: " . implode(', ', $validation_errors_delete);
                continue;
            }

            $file_path = $target_dir_path . $filename;

            if (file_exists($file_path) && is_file($file_path)) {
                if (is_writable($file_path)) {
                    if (unlink($file_path)) {
                        $results['deleted'][] = esc_html($filename);
                    } else {
                        $results['errors'][] = "Could not delete file: " . esc_html($filename);
                    }
                } else {
                    $results['errors'][] = "File not writable (permissions?): " . esc_html($filename);
                }
            } else {
                $results['errors'][] = "File not found for deletion: " . esc_html($filename);
            }
        }

        // --- Process renames ---
        foreach ($files_to_rename_raw as $old_filename_raw => $new_filename_raw) {
            $old_filename = $this->file_utils->sanitize_and_prepare_filename($old_filename_raw);
            $new_filename = $this->file_utils->sanitize_and_prepare_filename($new_filename_raw);

            if (empty($old_filename) || empty($new_filename)) {
                $results['errors'][] = "Invalid old or new filename for rename (empty after sanitization).";
                continue;
            }
            if ($old_filename === $new_filename) {
                continue; // No change
            }

            // Validation for old and new filename
            $validation_errors_old = $this->file_utils->validate_filename_structure($old_filename);
            if (!empty($validation_errors_old)) {
                $results['errors'][] = "Old filename '" . esc_html($old_filename) . "' is not valid: " . implode(', ', $validation_errors_old);
                continue;
            }
            $validation_errors_new = $this->file_utils->validate_filename_structure($new_filename);
             // Optional: force keyword in new name if required
            if ($this->manager_config['ensure_keyword_on_rename'] ?? false) {
                 $new_filename = $this->file_utils->ensure_keyword_in_filename($new_filename);
                 // Re-validate after possible modification
                 $validation_errors_new = $this->file_utils->validate_filename_structure($new_filename);
            }

            if (!empty($validation_errors_new)) {
                $results['errors'][] = "New filename '" . esc_html($new_filename_raw) . "' (to '" . esc_html($new_filename) . "') is not valid: " . implode(', ', $validation_errors_new);
                continue;
            }


            $old_file_path = $target_dir_path . $old_filename;
            $new_file_path = $target_dir_path . $new_filename;

            if (!file_exists($old_file_path) || !is_file($old_file_path)) {
                $results['errors'][] = "Old file not found: " . esc_html($old_filename);
                continue;
            }
            if (file_exists($new_file_path)) {
                $results['errors'][] = "Target file '" . esc_html($new_filename) . "' already exists. Cannot rename '" . esc_html($old_filename) . "'.";
                continue;
            }

            // Check if the old file actually exists and is a file
            if (!file_exists($old_file_path) || !is_file($old_file_path)) {
                $results['errors'][] = "Old file '" . esc_html($old_filename) . "' not found or is not a file.";
                continue;
            }


            if (is_writable($target_dir_path) && is_writable($old_file_path)) {
                if (rename($old_file_path, $new_file_path)) {
                    $results['renamed'][] = array('old' => esc_html($old_filename), 'new' => esc_html($new_filename));
                } else {
                    $results['errors'][] = "Could not rename '" . esc_html($old_filename) . "' to '" . esc_html($new_filename) . "'. Check server logs for details.";
                }
            } else {
                $error_message = "Insufficient permissions to rename '" . esc_html($old_filename) . "'. ";
                if (!is_writable($target_dir_path)) {
                    $error_message .= "Target directory '" . esc_html(basename($target_dir_path)) . "' is not writable. ";
                }
                if (!is_writable($old_file_path)) {
                    $error_message .= "Old file '" . esc_html($old_filename) . "' is not writable.";
                }
                $results['errors'][] = trim($error_message);
            }
        } // End foreach loop for renames

        // --- Send the results back as JSON ---
        if (empty($results['errors']) && (empty($results['deleted']) && empty($results['renamed']))) {
            // No errors, but nothing done either
            wp_send_json_success(array(
                'deleted' => $results['deleted'], // will be empty
                'renamed' => $results['renamed'], // will be empty
                'errors'  => $results['errors'],  // will be empty
                'message' => 'No actions performed (perhaps nothing was selected or to be changed).'
            ));
        } elseif (!empty($results['errors']) && empty($results['deleted']) && empty($results['renamed'])) {
            // Only errors, nothing successful
             wp_send_json_error(array( // Send as error if there are *only* errors and no successes
                'deleted' => $results['deleted'],
                'renamed' => $results['renamed'],
                'errors'  => $results['errors'],
                'message' => 'Errors occurred while processing the actions.'
            ));
        }
        else {
            // Mix of successes and/or errors, or only successes
            wp_send_json_success(array(
                'deleted' => $results['deleted'],
                'renamed' => $results['renamed'],
                'errors'  => $results['errors']
                // A general message could also be added here, depending on the JS handling
            ));
        }
        // wp_die(); is implicitly called by wp_send_json_success() and wp_send_json_error()
    }

} // End of the DropZoneManager class

/**
 * Class DropZoneUploader
 * Handles the shortcode and AJAX upload, configurable via a FileUtils instance.
 */
class DropZoneUploader {

    private $shortcode_tag = 'my_upload_dropzone'; // Default, can be overridden
    private $ajax_action   = 'handle_custom_upload'; // Default
    private $nonce_action  = 'custom_upload_nonce';  // Default
    private $js_handle     = 'custom-uploader-js';   // Default
    private $js_path = '/js/default-uploader.js'; // Default

    /** @var array Configuration specific to this uploader instance */
    private $uploader_config;

    /** @var FileUtils */
    private $file_utils_instance;

    /**
     * Constructor.
     * @param array     $uploader_config     Configuration specific to this uploader instance.
     * @param FileUtils $file_utils_instance A pre-configured instance of FileUtils.
     */
    public function __construct(array $uploader_config, FileUtils $file_utils_instance) {
        $this->uploader_config     = $uploader_config;
        $this->js_path = $uploader_config['js_path'] ?? $this->js_path;
        $this->file_utils_instance = $file_utils_instance;

        // Override defaults with values from $uploader_config if present
        $this->shortcode_tag = $uploader_config['shortcode_tag'] ?? $this->shortcode_tag;
        $this->ajax_action   = $uploader_config['ajax_action']   ?? $this->ajax_action;
        $this->nonce_action  = $uploader_config['nonce_action']  ?? $this->nonce_action;
        $this->js_handle     = $uploader_config['js_handle']     ?? $this->js_handle;
        $this->js_path       = $uploader_config['js_path']       ?? $this->js_path;
        // Add other config options specific to the uploader UI/operation

        $this->register_shortcode();
        $this->register_ajax_handler();
    }

    /**
     * Registers the shortcode.
     */
    public function register_shortcode() {
        // --- DEBUGGING ---
        if(DROPZONE_DEBUG_ON)
            error_log('DEBUG: Shortcode wordt geregistreerd met tag: ' . $this->shortcode_tag);
        // --- EINDE DEBUGGING ---
        add_shortcode( $this->shortcode_tag, array( $this, 'render_shortcode' ) );
    }

    /**
     * Renders the HTML for the shortcode and enqueues scripts.
     * @return string HTML output.
     */
    public function render_shortcode() {
        wp_enqueue_script(
            $this->js_handle,
            $this->js_path, // Consider get_template_directory_uri() or plugin_dir_url() depending on context
            array('jquery'),
            $this->uploader_config['js_version'] ?? null,
            true
        );

        // The name 'custom_uploader_params' can also come from config
        $localize_handle = $this->uploader_config['js_localize_handle'] ?? 'custom_uploader_params';
        wp_localize_script( $this->js_handle, $localize_handle, array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce( $this->nonce_action )
        ));

        // Get UI texts from the configuration
        $main_instruction = $this->uploader_config['ui_main_instruction'] ?? 'Drag and drop a file here';
        $alt_instruction  = $this->uploader_config['ui_alt_instruction']  ?? 'or';
        $button_text      = $this->uploader_config['ui_button_text']      ?? 'Select File';
        $accept_types     = $this->uploader_config['ui_accept_types']     ?? '.pdf'; // For the file input

        // Container IDs can also come from config
        $container_id = $this->uploader_config['ui_container_id'] ?? 'custom-dropzone';
        $file_input_id = $this->uploader_config['ui_file_input_id'] ?? 'custom-file-input';
        $browse_button_id = $this->uploader_config['ui_browse_button_id'] ?? 'custom-browse-button';
        $feedback_id = $this->uploader_config['ui_feedback_id'] ?? 'upload-feedback';


        ob_start();
        ?>
        <div id="<?php echo esc_attr($container_id); ?>" style="border: 2px dashed #ccc; padding: 20px; text-align: center; margin-bottom:15px;">
            <p><?php echo esc_html($main_instruction); ?></p>
            <p><?php echo esc_html($alt_instruction); ?></p>
            <input type="file" id="<?php echo esc_attr($file_input_id); ?>" accept="<?php echo esc_attr($accept_types); ?>" style="display:none;">
            <button type="button" id="<?php echo esc_attr($browse_button_id); ?>"><?php echo esc_html($button_text); ?></button>
            <div id="<?php echo esc_attr($feedback_id); ?>" style="margin-top:10px;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Registers the AJAX handler.
     */
    public function register_ajax_handler() {
        add_action( 'wp_ajax_' . $this->ajax_action, array( $this, 'handle_upload_callback' ) );

        $allow_nopriv = $this->uploader_config['allow_nopriv_upload'] ?? false;
        if ($allow_nopriv) {
            add_action( 'wp_ajax_nopriv_' . $this->ajax_action, array( $this, 'handle_upload_callback' ) );
        }
    }

    /**
     * AJAX handler for processing the upload.
     */
    public function handle_upload_callback() {
        check_ajax_referer( $this->nonce_action, 'nonce' );

        $capability_upload = $this->uploader_config['capability_upload'] ?? 'upload_files';
        if ( !current_user_can($capability_upload) && !$this->uploader_config['allow_nopriv_upload'] ) { // Also check if nopriv is allowed
            wp_send_json_error( array('message' => 'Insufficient permissions to upload.'), 403 );
            return;
        }

        if ( empty($_FILES['uploaded_file']) ) { // The name 'uploaded_file' can also come from JS and thus be configurable
            wp_send_json_error( array('message' => 'No file received.'), 400 );
            return;
        }

        $file_upload_data = $_FILES['uploaded_file']; // This is the data from $_FILES
        $original_file_name = $file_upload_data['name'];

        // Use FileUtils to prepare/sanitize the filename
        $prepared_file_name = $this->file_utils_instance->sanitize_and_prepare_filename($original_file_name);

        // Use FileUtils to add a keyword if configured
        $final_file_name = $this->file_utils_instance->ensure_keyword_in_filename($prepared_file_name);

        // Use FileUtils for validation (MIME type, filename structure)
        // The 'validate_file_properties' method in FileUtils expects the $_FILES array ($file_upload_data)
        // and the filename to be validated ($final_file_name).
        $validation_errors = $this->file_utils_instance->validate_file_properties($file_upload_data, $final_file_name);

        if (!empty($validation_errors)) {
            wp_send_json_error(array('message' => implode(' ', $validation_errors)), 400); // 400 Bad Request or more specific
            return;
        }

        // Use FileUtils to get path information
        $target_path_details = $this->file_utils_instance->get_target_path_info();
        if (is_wp_error($target_path_details)) {
            wp_send_json_error(array('message' => 'Error with target directory: ' . $target_path_details->get_error_message()), 500);
            return;
        }

        $target_dir_path = $target_path_details['path']; // Path to the directory (with trailing slash from FileUtils)
        $new_file_path = $target_dir_path . $final_file_name;

        // Check if file already exists (could also become a FileUtils method)
        if ( file_exists($new_file_path) ) {
            // Check configuration if overwriting is allowed
            $allow_overwrite = $this->uploader_config['allow_overwrite'] ?? false; // New config option
            if (!$allow_overwrite) {
                wp_send_json_error( array('message' => 'A file with the name "' . esc_html($final_file_name) . '" already exists.'), 409 ); // 409 Conflict
                return;
            }
            // If allowed, nothing special happens here, move_uploaded_file will overwrite.
            // You could add logging here.
        }

        // Move the file
        if ( move_uploaded_file( $file_upload_data['tmp_name'], $new_file_path ) ) {
            // Generate the URL with FileUtils
            $file_url_or_error = $this->file_utils_instance->get_file_url($final_file_name);
            $file_url = is_wp_error($file_url_or_error) ? '#' : $file_url_or_error;

            wp_send_json_success( array(
                'message'   => 'File "' . esc_html($final_file_name) . '" uploaded successfully!',
                'file_url'  => esc_url($file_url), // URL to the file (from FileUtils)
                'file_path' => $new_file_path     // Server path (for debug or internal logic)
            ));
        } else {
            // Try to provide more specific error messages if possible
            $upload_error = $file_upload_data['error'] ?? null;
            $error_message = 'Error moving the uploaded file on the server.';
            if ($upload_error !== UPLOAD_ERR_OK && $upload_error !== null) {
                 $error_message .= ' (Upload error code: ' . $upload_error . ')';
            } elseif (!is_writable(dirname($new_file_path))) {
                $error_message = 'The target directory is not writable on the server.';
            }
            wp_send_json_error( array('message' => $error_message), 500 );
        }
        // wp_die(); is implicitly called after wp_send_json_success/error
    }

} // End of the DropZoneUploader class

/**
 * Helper function to quickly build a complete set of DropZone functionality (Uploader & Manager).
 *
 * @param string $slug A unique, short name (slug) for this instance, e.g., 'ovd' or 'newsletter'.
 * @param array $file_rules The rules for the files, such as directory and name patterns.
 * @param array $ui_texts (Optional) Custom texts for the user interface.
 * @param array $override_config (Optional) To override default generated config values.
 */
function create_dropzone_instance(string $slug, array $file_rules, array $ui_texts = [], array $override_config = []) {
    // Allow other code (like our plugin's path corrector) to filter the configuration.
    list($slug, $file_rules, $ui_texts, $override_config) = apply_filters('create_dropzone_instance_config', [$slug, $file_rules, $ui_texts, $override_config]);
    // --- 1. Build the FileUtils configuration ---
    // (This part remains the same)
    $default_file_rules = [
        'target_subdir_pattern'        => $slug, // By default, the directory name is the same as the slug
        'list_files_glob_pattern'      => '*' . $slug . '*.pdf', // Default: *slug*.pdf
        'filename_keyword_ensure'      => $slug,
        'filename_keyword_suffix'      => '_' . $slug,
        'name_pattern_prefix_regex'    => '/.*/', // Default: any prefix is allowed
        'name_pattern_keyword_regex'   => '/' . preg_quote($slug, '/') . '/i',
        'name_pattern_extension_regex' => '/\.pdf$/i',
        'allowed_mime_types'           => ['application/pdf'],
        'validate_filename_on_list'    => true,
    ];
    $final_file_rules = array_merge($default_file_rules, $file_rules);
    if (!class_exists('FileUtils')) return;
    $file_utils_instance = new FileUtils($final_file_rules);

    // --- 2. Build the Uploader configuration ---
    $default_uploader_config = [
        'shortcode_tag'         => $slug . '_uploader', // e.g., ovd_uploader
        'ajax_action'           => 'handle_' . $slug . '_upload',
        'nonce_action'          => $slug . '_upload_nonce',
        'js_path' => CUSTOM_DROPZONE_PLUGIN_URL . 'js/' . $slug . '-uploader.js', // Expects a standard location
        'js_localize_handle'    => $slug . '_uploader_params',
        'capability_upload'     => 'upload_files',
        'allow_overwrite'       => false,
        'ui_main_instruction'   => $ui_texts['main_instruction'] ?? 'Drag and drop a file (' . $slug . ') here',
        'ui_button_text'        => $ui_texts['button_text'] ?? 'Select file',
    ];
    // Use the override_config to override the defaults
    $final_uploader_config = array_merge($default_uploader_config, ($override_config['uploader'] ?? []));

    if (class_exists('DropZoneUploader')) {
        new DropZoneUploader($final_uploader_config, $file_utils_instance);
    }

    // --- 3. Build the Manager configuration ---
    $default_manager_config = [
        'shortcode_tag'     => $slug . '_manager', // e.g., ovd_manager
        'ajax_action'       => 'process_' . $slug . '_actions',
        'nonce_action'      => $slug . '_manager_nonce',
        'js_handle'         => $slug . '-manager-js',
        'js_path' => CUSTOM_DROPZONE_PLUGIN_URL . 'js/' . $slug . '-manager.js', // Expects a standard location
        'capability_manage' => 'manage_options',
        'ensure_keyword_on_rename' => true,
        'section_title'     => $ui_texts['section_title'] ?? 'Overview of ' . ucfirst($slug) . ' Files',
    ];
    // Use the override_config to override the defaults
    $final_manager_config = array_merge($default_manager_config, ($override_config['manager'] ?? []));

    if (class_exists('DropZoneManager')) {
        new DropZoneManager($final_manager_config, $file_utils_instance);
    }
}


add_action('init', 'initialize_my_dropzone_functionality_from_json');
function initialize_my_dropzone_functionality_from_json() {
    // Determine the path to the JSON file.
    // get_stylesheet_directory() is for themes. Use plugin_dir_path(__FILE__) if this is in a plugin.
    $json_file_path = plugin_dir_path( __FILE__ ) . 'config/dropzones.json';
    // Check if the file exists and is readable.
    if (!file_exists($json_file_path) || !is_readable($json_file_path)) {
        error_log('DropZone Error: dropzones.json not found or not readable at: ' . $json_file_path);
        // Optional: show an admin notice for site administrators.
        // add_action( 'admin_notices', function() { echo '<div class="notice notice-error"><p>DropZone configuration file (dropzones.json) is missing!</p></div>'; });
        return;
    }

    // Read the file's contents.
    $json_content = file_get_contents($json_file_path);

    // Decode the JSON into a PHP array. The 'true' parameter converts JSON objects to associative arrays.
    $dropzone_configs = json_decode($json_content, true);

    // Check if decoding was successful and if it is an array.
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($dropzone_configs)) {
        error_log('DropZone Error: Could not correctly parse dropzones.json. Error: ' . json_last_error_msg());
        return;
    }

    // Loop through each configuration from the JSON file.
    foreach ($dropzone_configs as $config) {
        // Ensure the essential 'slug' and 'file_rules' keys exist.
        if (empty($config['slug']) || empty($config['file_rules'])) {
            error_log('DropZone Error: A configuration in dropzones.json is missing a "slug" or "file_rules".');
            continue; // Skip this invalid configuration and move to the next.
        }

        // Call the helper function with the parameters from the configuration.
        // The '?? []' ensures that an empty array is passed if the key does not exist,
        // which the helper function expects.
        create_dropzone_instance(
            $config['slug'],
            $config['file_rules'],
            $config['ui_texts'] ?? [],
            $config['override_config'] ?? []
        );
    }
}
