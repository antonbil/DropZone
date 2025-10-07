<?php

class FileUtils {

    private $config;
    private $default_config = [
        'target_subdir_pattern'        => null,
        'filename_keyword_ensure'      => null,
        'filename_keyword_suffix'      => null,
        'name_pattern_prefix_regex'    => '/.*/',
        'name_pattern_keyword_regex'   => '/.*/',
        'name_pattern_extension_regex' => '/\.[a-zA-Z0-9]+$/i',
        'allowed_mime_types'           => [],
        'list_files_glob_pattern'      => '*.pdf', // For listing files
        'validate_filename_on_list'    => false,   // Whether files in the list should also be strictly validated with regexes
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
            switch ($file_error) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errors[] = __('The uploaded file is too large.', 'dropzone-manager');
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errors[] = __('The file was only partially uploaded.', 'dropzone-manager');
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errors[] = __('No file was uploaded.', 'dropzone-manager');
                    break;
                default:
                    $errors[] = sprintf(
                        /* translators: %d is the server's integer error code for an upload failure. */
                        __('Unknown upload error (server code: %d).', 'dropzone-manager'),
                        $file_error
                    );
            }
            return $errors;
        }

        if (isset($file_upload_data['type']) && !empty($this->config['allowed_mime_types']) && !in_array($file_type, $this->config['allowed_mime_types'], true)) {
            $errors[] = sprintf(
                /* translators: %s is a comma-separated list of allowed file types (e.g., "image/jpeg, application/pdf"). */
                __('Invalid file type. Allowed: %s', 'dropzone-manager'),
                implode(', ', $this->config['allowed_mime_types'])
            );
        }
        return $this->validate_filename_structure($file_name_to_validate, $errors);
    }

    public function validate_filename_structure(string $file_name_to_validate, array $existing_errors = []): array {
        $errors = $existing_errors;
        if ($this->config['name_pattern_prefix_regex'] && !preg_match($this->config['name_pattern_prefix_regex'], $file_name_to_validate)) {
            $errors[] = __('Filename does not meet the required prefix pattern.', 'dropzone-manager');
        }
        if ($this->config['name_pattern_keyword_regex'] && !preg_match($this->config['name_pattern_keyword_regex'], $file_name_to_validate)) {
            $errors[] = __('Filename does not meet the required keyword pattern.', 'dropzone-manager');
        }
        if ($this->config['name_pattern_extension_regex'] && !preg_match($this->config['name_pattern_extension_regex'], $file_name_to_validate)) {
            $errors[] = __('Filename does not meet the required extension pattern (or is missing an extension).', 'dropzone-manager');
        }
        return $errors;
    }

    public function get_target_path_info() {
        $upload_dir_info = wp_upload_dir();
        if (!$upload_dir_info || !empty($upload_dir_info['error'])) {
            return new WP_Error('upload_dir_error', $upload_dir_info['error'] ?? __('Could not retrieve upload directory information.', 'dropzone-manager'));
        }

        $target_subdir = '';
        if (!empty($this->config['target_subdir_pattern'])) {
            $pattern = trim($this->config['target_subdir_pattern'], '/');
            $target_subdir = (preg_match('/[YymFdDgGhHisua]/', $pattern)) ? '/' . date_i18n($pattern) . '/' : '/' . $pattern . '/';
        } else {
            return new WP_Error('target_dir_not_configured', __('Target directory (target_subdir_pattern or target_year/month) is not specified.', 'dropzone-manager'));
        }

        $target_subdir = str_replace('//', '/', $target_subdir);
        $target_path   = $upload_dir_info['basedir'] . $target_subdir;

        if (!file_exists($target_path)) {
            if (!wp_mkdir_p($target_path)) {
                return new WP_Error('dir_creation_failed', sprintf(
                    /* translators: %s is the server file path that could not be created. */
                    __('Could not create target directory on server: %s', 'dropzone-manager'),
                    $target_path
                ));
            }
        }
        if (!is_dir($target_path)) { // Extra check
            return new WP_Error('target_not_dir', sprintf(
                /* translators: %s is the server file path that was expected to be a directory. */
                __('Target path is not a directory: %s', 'dropzone-manager'),
                $target_path
            ));
        }

        return [
            'path'    => trailingslashit($target_path), // Absolute server path to the target directory
            'url'     => trailingslashit($upload_dir_info['baseurl'] . $target_subdir), // URL to the target directory
            'baseurl' => $upload_dir_info['baseurl'],
            'subdir'  => $target_subdir,
        ];
    }

    /**
     * Lists files in the configured target directory based on the glob pattern.
     * @return array An array of filenames. Can be empty if no files are found or the directory does not exist/is not readable.
     */
    public function list_files_in_target_dir(): array {
        $path_info = $this->get_target_path_info();
        if (is_wp_error($path_info)) {
            // Log the error and return an empty array to not break the flow elsewhere.
            error_log("FileUtils Error in list_files_in_target_dir: " . $path_info->get_error_message());
            return [];
        }

        $target_dir_path = $path_info['path'];
        $glob_pattern    = $this->config['list_files_glob_pattern'] ?? '*.*'; // Default to all files if not specified

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

    private $shortcode_tag = 'file_manager';
    private $ajax_action   = 'process_file_actions';
    private $nonce_action  = 'file_actions_nonce';
    private $js_handle     = 'file-manager-js';
    private $js_path       = '/dropzone/js/file-manager.js';

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
        $this->manager_config = $manager_config;
        $this->file_utils     = $file_utils_instance;
        // Override defaults with values from manager_config if provided
        $this->shortcode_tag = $manager_config['shortcode_tag'] ?? $this->shortcode_tag;
        $this->ajax_action   = $manager_config['ajax_action'] ?? $this->ajax_action;
        $this->nonce_action  = $manager_config['nonce_action'] ?? $this->nonce_action;
        $this->js_handle     = $manager_config['js_handle'] ?? $this->js_handle;
        $this->js_path       = $manager_config['js_path'] ?? $this->js_path;

        $this->register_shortcode();
        $this->register_ajax_handler();
                // Hook de enqueue-methode voor de stijlen.
        add_action('wp_enqueue_scripts', [$this, 'dropzone_manager_enqueue_styles']);
    }

    /**
     * Enqueues the main stylesheet for the Custom DropZone plugin.
     */
    public function dropzone_manager_enqueue_styles() {
        // ... (de inhoud van je functie blijft hetzelfde) ...
        global $post;
        // Gebruik $this->shortcode_tag om de check dynamisch te maken
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, $this->shortcode_tag ) ) {
            wp_enqueue_style(
                'dropzone-manager-style',
                CUSTOM_DROPZONE_PLUGIN_URL . 'css/style.css',
                [],
                '1.0.1'
            );
        }
    }

    /**
     * Registers the shortcode.
     */
    public function register_shortcode() {
        if (DROPZONE_DEBUG_ON) {
            error_log('DEBUG: Shortcode is being registered with tag: ' . $this->shortcode_tag);
        }
        add_shortcode($this->shortcode_tag, [$this, 'render_shortcode_content']);
    }


    /**
     * Renders the HTML for the shortcode.
     * @return string HTML output.
     */
    public function render_shortcode_content(): string {
        if (DROPZONE_DEBUG_ON) {
            error_log('DEBUG: render_shortcode_content() is executing for tag: ' . $this->shortcode_tag);
            error_log('DEBUG: Path to JS file: ' . $this->js_path);
        }

        wp_localize_script($this->js_handle, 'manager_i18n_params', [
            'delete_label'                  => __('Delete:', 'dropzone-manager'),
            'rename_label'                  => __('Rename:', 'dropzone-manager'),
            'no_actions_selected'           => __('No actions selected or changes specified.', 'dropzone-manager'),
            'processing_text'               => __('Processing...', 'dropzone-manager'),
            'processing_result_title'       => __('Processing Result:', 'dropzone-manager'),
            'deleted_feedback'              => __('Deleted:', 'dropzone-manager'),
            'renamed_feedback'              => __('Renamed:', 'dropzone-manager'),
            'error_feedback'                => __('Error:', 'dropzone-manager'),
            'unknown_server_error'          => __('Unknown server error or invalid response.', 'dropzone-manager'),
            'reload_message'                => __('The page will reload in 5 seconds to reflect the changes.', 'dropzone-manager'),
            'ajax_error_message'            => __('An unexpected AJAX error occurred. Check the browser console for more details.', 'dropzone-manager'),
            'server_error_prefix'           => __('Server Error:', 'dropzone-manager'),
            'execute_button_text'           => __('Yes, Execute Actions', 'dropzone-manager'),
        ]);

        // First, load the helpers
        wp_enqueue_script(
            'custom-dropzone-helpers', // Handle for the helpers
            plugin_dir_url(__FILE__) . 'js/plugin-helpers.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_enqueue_script(
            $this->js_handle,
            $this->js_path,
            ['jquery', 'custom-dropzone-helpers'],
            '1.0.2-' . time(), // DYNAMIC NEW VERSION (cache buster)
            true
        );

        wp_localize_script($this->js_handle, 'file_manager_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce($this->nonce_action),
            'action'   => $this->ajax_action, // ADD THIS LINE!
        ]);

        // Optional: Enqueue CSS
        if (!empty($this->manager_config['css_path'])) {
            wp_enqueue_style(
                $this->manager_config['css_handle'] ?? ($this->js_handle . '-css'), // Generate a css handle
                $this->manager_config['css_path'],
                [],
                $this->manager_config['css_version'] ?? '1.0.0'
            );
        }

        $path_info = $this->file_utils->get_target_path_info();
        if (is_wp_error($path_info)) {
            return sprintf(
                '<p>%s</p>',
                esc_html(sprintf(
                /* translators: %s is the specific error message from the server. */
                    __('Error initializing file manager: %s', 'dropzone-manager'),
                    $path_info->get_error_message()
                ))
            );
        }

        $files = $this->file_utils->list_files_in_target_dir();

        // Title for the section, can also come from config
        $section_title = $this->manager_config['section_title'] ?? __('File Overview', 'dropzone-manager');
        if (strpos($section_title, '%s') !== false && isset($path_info['subdir'])) {
            // Replace placeholder with the subdirectory name (e.g., 2025/01)
            $subdir_display = trim($path_info['subdir'], '/');
            $section_title  = sprintf($section_title, esc_html($subdir_display));
        }

        ob_start();
        ?>
        <div id="file-manager-container"> <?php // ID can also come from config ?>
            <h2><?php echo esc_html($section_title); ?></h2>

            <?php if (is_wp_error($files)) : ?>
                <p><?php
                    echo esc_html(sprintf(
                    /* translators: %s is the specific error message from the server. */
                        __('Error retrieving files: %s', 'dropzone-manager'),
                        $files->get_error_message()
                    ));
                    ?></p>
            <?php elseif (!empty($files)) : ?>
                <form id="file-actions-form"> <?php // ID can also come from config ?>
                    <p><?php _e('Select files to delete or provide a new name.', 'dropzone-manager'); ?></p>
                    <ul id="file-list" style="list-style-type: none; padding-left: 0;"> <?php // ID can also come from config ?>
                        <?php foreach ($files as $index => $filename) :
                            $file_id                = 'file-' . $index . '-' . sanitize_title($filename);
                            $file_url_path_or_error = $this->file_utils->get_file_url($filename);
                            $file_url_path          = is_wp_error($file_url_path_or_error) ? '#' : $file_url_path_or_error;
                            ?>
                            <li class="file-item" data-filename="<?php echo esc_attr($filename); ?>">
                                <input type="checkbox" name="delete_files[]" value="<?php echo esc_attr($filename); ?>" id="delete-<?php echo esc_attr($file_id); ?>">
                                <label for="delete-<?php echo esc_attr($file_id); ?>" style="margin-right: 10px;"><?php _e('Delete', 'dropzone-manager'); ?></label>

                                <a href="<?php echo esc_url($file_url_path); ?>" target="_blank"><?php echo esc_html($filename); ?></a>

                                <button type="button" class="rename-toggle-btn" data-target="rename-<?php echo esc_attr($file_id); ?>" style="margin-left:10px; font-size:0.8em;"><?php _e('Rename', 'dropzone-manager'); ?></button>
                                <span class="rename-field-wrapper" style="display:none; margin-left:5px;">
                                    <input type="text" name="rename_files[<?php echo esc_attr($filename); ?>]" class="new-name-input" id="rename-<?php echo esc_attr($file_id); ?>" placeholder="<?php esc_attr_e('New name...', 'dropzone-manager'); ?>" value="<?php echo esc_attr($filename); ?>" style="width:auto; font-size:0.9em; padding:2px;">
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" id="prepare-actions-btn" class="button button-primary" style="margin-top: 20px;"><?php _e('Review Proposed Actions', 'dropzone-manager'); ?></button>
                </form>

                <div id="confirmation-area" style="margin-top: 20px; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; display: none;"> <?php // ID can also come from config ?>
                    <h4><?php _e('Confirm the following actions:', 'dropzone-manager'); ?></h4>
                    <div id="actions-summary">
                        <!-- Summary of actions is inserted here by JavaScript -->
                    </div>
                    <button type="button" id="execute-actions-btn" class="button button-danger" style="margin-right: 10px;"><?php _e('Yes, Execute Actions', 'dropzone-manager'); ?></button>
                    <button type="button" id="cancel-actions-btn" class="button"><?php _e('Cancel', 'dropzone-manager'); ?></button>
                </div>

                <div id="feedback-area" style="margin-top: 15px;"> <?php // ID can also come from config ?>
                    <!-- Feedback from the server is shown here -->
                </div>

            <?php else : // No files found ?>
                <?php
                $no_files_message           = $this->manager_config['no_files_message'] ?? __('No files found in the directory %s that match the pattern.', 'dropzone-manager');
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
        add_action('wp_ajax_' . $this->ajax_action, [$this, 'handle_file_actions_callback']);
        // add_action('wp_ajax_nopriv_' . $this->ajax_action, array($this, 'handle_file_actions_callback')); // If needed
    }

    /**
     * AJAX handler for processing file actions (delete, rename).
     */
    public function handle_file_actions_callback() {
        check_ajax_referer($this->nonce_action, 'nonce');

        $capability_manage = $this->manager_config['capability_manage'] ?? 'manage_options';
        if (!current_user_can($capability_manage)) {
            wp_send_json_error(['message' => __('Insufficient permissions to perform these actions.', 'dropzone-manager')], 403);
            return;
        }

        $files_to_delete     = isset($_POST['delete_files']) ? (array)$_POST['delete_files'] : [];
        $files_to_rename_raw = isset($_POST['rename_files']) ? (array)$_POST['rename_files'] : [];

        $results = [
            'deleted' => [],
            'renamed' => [],
            'errors'  => [],
        ];

        $path_info = $this->file_utils->get_target_path_info();
        if (is_wp_error($path_info)) {
            wp_send_json_error(['message' => sprintf(__('Error accessing target directory: %s', 'dropzone-manager'), $path_info->get_error_message())], 500);
            return;
        }
        $target_dir_path = $path_info['path'];

        // --- Process deletions ---
        foreach ($files_to_delete as $filename_raw) {
            $filename = $this->file_utils->sanitize_and_prepare_filename($filename_raw);
            if (empty($filename)) {
                $results['errors'][] = sprintf(__('Invalid filename for deletion (empty after sanitization): %s', 'dropzone-manager'), esc_html($filename_raw));
                continue;
            }

            $validation_errors_delete = $this->file_utils->validate_filename_structure($filename);
            if (!empty($validation_errors_delete)) {
                $results['errors'][] = sprintf(__('File \'%s\' does not meet the structural requirements for deletion: %s', 'dropzone-manager'), esc_html($filename), implode(', ', $validation_errors_delete));
                continue;
            }

            $file_path = $target_dir_path . $filename;

            if (file_exists($file_path) && is_file($file_path)) {
                if (is_writable($file_path)) {
                    if (unlink($file_path)) {
                        $results['deleted'][] = esc_html($filename);
                    } else {
                        $results['errors'][] = sprintf(__('Could not delete file: %s', 'dropzone-manager'), esc_html($filename));
                    }
                } else {
                    $results['errors'][] = sprintf(__('File not writable (permissions?): %s', 'dropzone-manager'), esc_html($filename));
                }
            } else {
                $results['errors'][] = sprintf(__('File not found for deletion: %s', 'dropzone-manager'), esc_html($filename));
            }
        }

        // --- Process renames ---
        foreach ($files_to_rename_raw as $old_filename_raw => $new_filename_raw) {
            $old_filename = $this->file_utils->sanitize_and_prepare_filename($old_filename_raw);
            $new_filename = $this->file_utils->sanitize_and_prepare_filename($new_filename_raw);

            if (empty($old_filename) || empty($new_filename)) {
                $results['errors'][] = __('Invalid old or new filename for rename (empty after sanitization).', 'dropzone-manager');
                continue;
            }
            if ($old_filename === $new_filename) {
                continue; // No change
            }

            $validation_errors_old = $this->file_utils->validate_filename_structure($old_filename);
            if (!empty($validation_errors_old)) {
                $results['errors'][] = sprintf(__('Old filename \'%s\' is not valid: %s', 'dropzone-manager'), esc_html($old_filename), implode(', ', $validation_errors_old));
                continue;
            }
            if ($this->manager_config['ensure_keyword_on_rename'] ?? false) {
                $new_filename = $this->file_utils->ensure_keyword_in_filename($new_filename);
            }
            $validation_errors_new = $this->file_utils->validate_filename_structure($new_filename);
            if (!empty($validation_errors_new)) {
                $results['errors'][] = sprintf(__('New filename \'%s\' (to \'%s\') is not valid: %s', 'dropzone-manager'), esc_html($new_filename_raw), esc_html($new_filename), implode(', ', $validation_errors_new));
                continue;
            }

            $old_file_path = $target_dir_path . $old_filename;
            $new_file_path = $target_dir_path . $new_filename;

            if (!file_exists($old_file_path) || !is_file($old_file_path)) {
                $results['errors'][] = sprintf(__('Old file not found: %s', 'dropzone-manager'), esc_html($old_filename));
                continue;
            }
            if (file_exists($new_file_path)) {
                $results['errors'][] = sprintf(__('Target file \'%s\' already exists. Cannot rename \'%s\'.', 'dropzone-manager'), esc_html($new_filename), esc_html($old_filename));
                continue;
            }
            if (!file_exists($old_file_path) || !is_file($old_file_path)) {
                $results['errors'][] = sprintf(__('Old file \'%s\' not found or is not a file.', 'dropzone-manager'), esc_html($old_filename));
                continue;
            }

            if (is_writable($target_dir_path) && is_writable($old_file_path)) {
                if (rename($old_file_path, $new_file_path)) {
                    $results['renamed'][] = ['old' => esc_html($old_filename), 'new' => esc_html($new_filename)];
                } else {
                    $results['errors'][] = sprintf(__('Could not rename \'%s\' to \'%s\'. Check server logs for details.', 'dropzone-manager'), esc_html($old_filename), esc_html($new_filename));
                }
            } else {
                $error_message = sprintf(__('Insufficient permissions to rename \'%s\'.', 'dropzone-manager'), esc_html($old_filename)) . ' ';
                if (!is_writable($target_dir_path)) {
                    $error_message .= sprintf(__('Target directory \'%s\' is not writable.', 'dropzone-manager'), esc_html(basename($target_dir_path))) . ' ';
                }
                if (!is_writable($old_file_path)) {
                    $error_message .= sprintf(__('Old file \'%s\' is not writable.', 'dropzone-manager'), esc_html($old_filename));
                }
                $results['errors'][] = trim($error_message);
            }
        } // End foreach loop for renames

        // --- Send the results back as JSON ---
        if (empty($results['errors']) && empty($results['deleted']) && empty($results['renamed'])) {
            wp_send_json_success(array_merge($results, ['message' => __('No actions performed (perhaps nothing was selected or to be changed).', 'dropzone-manager')]));
        } elseif (!empty($results['errors']) && empty($results['deleted']) && empty($results['renamed'])) {
            wp_send_json_error(array_merge($results, ['message' => __('Errors occurred while processing the actions.', 'dropzone-manager')]));
        } else {
            wp_send_json_success($results);
        }
    }

} // End of the DropZoneManager class

/**
 * Class DropZoneUploader
 * Handles the shortcode and AJAX upload, configurable via a FileUtils instance.
 */
class DropZoneUploader {

    private $shortcode_tag = 'my_upload_dropzone';
    private $ajax_action   = 'handle_custom_upload';
    private $nonce_action  = 'custom_upload_nonce';
    private $js_handle     = 'custom-uploader-js';
    private $js_path       = '/js/default-uploader.js';

    /** @var array Configuration specific to this uploader instance */
    private $uploader_config;

    /** @var FileUtils */
    private $file_utils_instance;

    /**
     * Constructor.
     * @param array $uploader_config Configuration specific to this uploader instance.
     * @param FileUtils $file_utils_instance A pre-configured instance of FileUtils.
     */
    public function __construct(array $uploader_config, FileUtils $file_utils_instance) {
        $this->uploader_config     = $uploader_config;
        $this->file_utils_instance = $file_utils_instance;

        // Override defaults with values from $uploader_config if present
        $this->shortcode_tag = $uploader_config['shortcode_tag'] ?? $this->shortcode_tag;
        $this->ajax_action   = $uploader_config['ajax_action'] ?? $this->ajax_action;
        $this->nonce_action  = $uploader_config['nonce_action'] ?? $this->nonce_action;
        $this->js_handle     = $uploader_config['js_handle'] ?? $this->js_handle;
        $this->js_path       = $uploader_config['js_path'] ?? $this->js_path;

        $this->register_shortcode();
        $this->register_ajax_handler();
    }

    /**
     * Registers the shortcode.
     */
    public function register_shortcode() {
        if (DROPZONE_DEBUG_ON) {
            error_log('DEBUG: Shortcode is being registered with tag: ' . $this->shortcode_tag);
        }
        add_shortcode($this->shortcode_tag, [$this, 'render_shortcode']);
    }

    /**
     * Renders the HTML for the shortcode and enqueues scripts.
     * @return string HTML output.
     */
    public function render_shortcode(): string {
        // First load the helpers
        wp_enqueue_script(
            'custom-dropzone-helpers',
            plugin_dir_url(__FILE__) . 'js/plugin-helpers.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_enqueue_script(
            $this->js_handle,
            $this->js_path,
            ['jquery', 'custom-dropzone-helpers'],
            $this->uploader_config['js_version'] ?? null,
            true
        );

        $localize_handle = $this->uploader_config['js_localize_handle'] ?? 'custom_uploader_params';
        wp_localize_script($this->js_handle, $localize_handle, [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce($this->nonce_action),
        ]);

        wp_localize_script($this->js_handle, 'uploader_i18n_params', [
            'uploading_message' => __('Uploading:', 'dropzone-manager'),
            'error_prefix'      => __('Error:', 'dropzone-manager'),
            'pdf_only_error'    => __('Only PDF files are allowed and the name must end with .pdf.', 'dropzone-manager'),
            'yymmdd_error'      => __('Filename must start with 6 digits (yymmdd).', 'dropzone-manager'),
            'file_url_label'    => __('File URL:', 'dropzone-manager'),
            'unknown_error'     => __('Unknown error occurred.', 'dropzone-manager'),
            'ajax_error_prefix' => __('AJAX Error:', 'dropzone-manager'),
        ]);

        // Get UI texts from the configuration
        $main_instruction = $this->uploader_config['ui_main_instruction'] ?? __('Drag and drop a file here', 'dropzone-manager');
        $alt_instruction  = $this->uploader_config['ui_alt_instruction'] ?? __('or', 'dropzone-manager');
        $button_text      = $this->uploader_config['ui_button_text'] ?? __('Select File', 'dropzone-manager');
        $accept_types     = $this->uploader_config['ui_accept_types'] ?? '.pdf'; // For the file input

        // Container IDs can also come from config
        $container_id     = $this->uploader_config['ui_container_id'] ?? 'custom-dropzone';
        $file_input_id    = $this->uploader_config['ui_file_input_id'] ?? 'custom-file-input';
        $browse_button_id = $this->uploader_config['ui_browse_button_id'] ?? 'custom-browse-button';
        $feedback_id      = $this->uploader_config['ui_feedback_id'] ?? 'upload-feedback';

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
        add_action('wp_ajax_' . $this->ajax_action, [$this, 'handle_upload_callback']);

        $allow_nopriv = $this->uploader_config['allow_nopriv_upload'] ?? false;
        if ($allow_nopriv) {
            add_action('wp_ajax_nopriv_' . $this->ajax_action, [$this, 'handle_upload_callback']);
        }
    }

    /**
     * AJAX handler for processing the upload.
     */
    public function handle_upload_callback() {
        check_ajax_referer($this->nonce_action, 'nonce');

        $capability_upload = $this->uploader_config['capability_upload'] ?? 'upload_files';
        if (!current_user_can($capability_upload) && !($this->uploader_config['allow_nopriv_upload'] ?? false)) {
            wp_send_json_error(['message' => __('Insufficient permissions to upload.', 'dropzone-manager')], 403);
            return;
        }

        if (empty($_FILES['uploaded_file'])) { // The name 'uploaded_file' can also come from JS and thus be configurable
            wp_send_json_error(['message' => __('No file received.', 'dropzone-manager')], 400);
            return;
        }

        $file_upload_data   = $_FILES['uploaded_file'];
        $original_file_name = $file_upload_data['name'];

        $prepared_file_name = $this->file_utils_instance->sanitize_and_prepare_filename($original_file_name);
        $final_file_name    = $this->file_utils_instance->ensure_keyword_in_filename($prepared_file_name);
        $validation_errors  = $this->file_utils_instance->validate_file_properties($file_upload_data, $final_file_name);

        if (!empty($validation_errors)) {
            wp_send_json_error(['message' => implode(' ', $validation_errors)], 400);
            return;
        }

        $target_path_details = $this->file_utils_instance->get_target_path_info();
        if (is_wp_error($target_path_details)) {
            wp_send_json_error(['message' => sprintf(__('Error with target directory: %s', 'dropzone-manager'), $target_path_details->get_error_message())], 500);
            return;
        }

        $target_dir_path = $target_path_details['path'];
        $new_file_path   = $target_dir_path . $final_file_name;

        if (file_exists($new_file_path)) {
            $allow_overwrite = $this->uploader_config['allow_overwrite'] ?? false; // New config option
            if (!$allow_overwrite) {
                wp_send_json_error(['message' => sprintf(__('A file with the name "%s" already exists.', 'dropzone-manager'), esc_html($final_file_name))], 409); // 409 Conflict
                return;
            }
        }

        if (move_uploaded_file($file_upload_data['tmp_name'], $new_file_path)) {
            $file_url_or_error = $this->file_utils_instance->get_file_url($final_file_name);
            $file_url          = is_wp_error($file_url_or_error) ? '#' : $file_url_or_error;

            wp_send_json_success([
                'message'   => sprintf(__('File "%s" uploaded successfully!', 'dropzone-manager'), esc_html($final_file_name)),
                'file_url'  => esc_url($file_url),
                'file_path' => $new_file_path,
            ]);
        } else {
            $upload_error  = $file_upload_data['error'] ?? null;
            $error_message = __('Error moving the uploaded file on the server.', 'dropzone-manager');
            if ($upload_error !== UPLOAD_ERR_OK && $upload_error !== null) {
                $error_message .= sprintf(__(' (Upload error code: %d)', 'dropzone-manager'), $upload_error);
            } elseif (!is_writable(dirname($new_file_path))) {
                $error_message = __('The target directory is not writable on the server.', 'dropzone-manager');
            }
            wp_send_json_error(['message' => $error_message], 500);
        }
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
    $default_file_rules = [
        'target_subdir_pattern'        => $slug,
        'list_files_glob_pattern'      => '*' . $slug . '*.pdf',
        'filename_keyword_ensure'      => $slug,
        'filename_keyword_suffix'      => '_' . $slug,
        'name_pattern_prefix_regex'    => '/.*/',
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
        'shortcode_tag'         => $slug . '_uploader',
        'ajax_action'           => 'handle_' . $slug . '_upload',
        'nonce_action'          => $slug . '_upload_nonce',
        'js_path'               => CUSTOM_DROPZONE_PLUGIN_URL . 'js/' . $slug . '-uploader.js',
        'js_localize_handle'    => $slug . '_uploader_params',
        'capability_upload'     => 'upload_files',
        'allow_overwrite'       => false,
        'ui_main_instruction'   => $ui_texts['main_instruction'] ?? sprintf(__('Drag and drop a file (%s) here', 'dropzone-manager'), $slug),
        'ui_button_text'        => $ui_texts['button_text'] ?? __('Select file', 'dropzone-manager'),
    ];
    $final_uploader_config = array_merge($default_uploader_config, ($override_config['uploader'] ?? []));

    if (class_exists('DropZoneUploader')) {
        new DropZoneUploader($final_uploader_config, $file_utils_instance);
    }

    // --- 3. Build the Manager configuration ---
    $default_manager_config = [
        'shortcode_tag'            => $slug . '_manager',
        'ajax_action'              => 'process_' . $slug . '_actions',
        'nonce_action'             => $slug . '_manager_nonce',
        'js_handle'                => $slug . '-manager-js',
        'js_path'                  => CUSTOM_DROPZONE_PLUGIN_URL . 'js/' . $slug . '-manager.js',
        'capability_manage'        => 'manage_options',
        'ensure_keyword_on_rename' => true,
        'section_title'            => $ui_texts['section_title'] ?? sprintf(__('Overview of %s Files', 'dropzone-manager'), ucfirst($slug)),
    ];
    $final_manager_config = array_merge($default_manager_config, ($override_config['manager'] ?? []));

    if (class_exists('DropZoneManager')) {
        new DropZoneManager($final_manager_config, $file_utils_instance);
    }
}

// NOTE: This part seems to be duplicated from your main plugin file.
// It is recommended to keep this logic in the main file and not in the class file.
// For completeness, I have translated the strings here as well.
add_action('init', 'initialize_my_dropzone_functionality_from_json');
function initialize_my_dropzone_functionality_from_json() {
    $json_file_path = plugin_dir_path(__FILE__) . 'config/dropzones.json';
    if (!file_exists($json_file_path) || !is_readable($json_file_path)) {
        error_log(
            sprintf(
            /* translators: %s is the server file path to the missing configuration file. */
                __('DropZone Error: dropzones.json not found or not readable at: %s', 'dropzone-manager'),
                $json_file_path
            )
        );
        return;
    }

    $json_content = file_get_contents($json_file_path);
    $dropzone_configs = json_decode($json_content, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($dropzone_configs)) {
        error_log(
            sprintf(
            /* translators: %s is the specific JSON error message from the server. */
                __('DropZone Error: Could not correctly parse dropzones.json. Error: %s', 'dropzone-manager'),
                json_last_error_msg()
            )
        );
        return;
    }

    foreach ($dropzone_configs as $config) {
        if (empty($config['slug']) || empty($config['file_rules'])) {
            error_log(__('DropZone Error: A configuration in dropzones.json is missing a "slug" or "file_rules".', 'dropzone-manager'));
            continue;
        }

        create_dropzone_instance(
            $config['slug'],
            $config['file_rules'],
            $config['ui_texts'] ?? [],
            $config['override_config'] ?? []
        );
    }
}
