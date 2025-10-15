<?php

/**
 * FileUtils Class
 *
 * A robust utility class designed for managing server-side file operations, validation,
 * and path manipulation, specifically optimized for use within a WordPress environment.
 * It encapsulates configuration for target upload directories, complex filename patterns,
 * allowed MIME types, and file listing logic.
 */
class FileUtils {

    /**
     * @var array The merged configuration array containing all settings for file utilities.
     */
    private $config;

    /**
     * @var array The default configuration settings.
     */
    private $default_config = [
        // Pattern for the subdirectory inside wp-content/uploads/ (e.g., 'Y/m' for year/month, or a fixed name).
        'target_subdir_pattern'        => null,
        // If set, the 'filename_keyword_suffix' will be appended if this keyword is missing (case-insensitive).
        'filename_keyword_ensure'      => null,
        // The suffix to append if the 'ensure' keyword is missing (e.g., '_document').
        'filename_keyword_suffix'      => null,
        // Regex for the required filename prefix (e.g., '/^\d{6}/' for YYMMDD).
        'name_pattern_prefix_regex'    => '/.*/',
        // Regex for the required keyword or structure within the middle of the filename.
        'name_pattern_keyword_regex'   => '/.*/',
        // Regex to validate the file extension (default: checks for any alphanumeric extension after a dot).
        'name_pattern_extension_regex' => '/\.[a-zA-Z0-9]+$/i',
        // Array of allowed MIME types for uploads (e.g., ['application/pdf']).
        'allowed_mime_types'           => [],
        // Glob pattern used to list files in the target directory (e.g., '*.pdf').
        'list_files_glob_pattern'      => '*.pdf', 
        // Boolean: Should files retrieved for listing also be strictly validated against the regex patterns?
        'validate_filename_on_list'    => false,   
    ];

    /**
     * Constructor. Merges custom configuration settings with the defaults.
     * @param array $custom_config Optional array of custom configuration settings.
     */
    public function __construct(array $custom_config = []) {
        $this->config = array_merge($this->default_config, $custom_config);
    }

    /**
     * Ensures a specific keyword is present in the filename.
     * If the keyword is missing (case-insensitive check), the configured suffix is appended 
     * immediately before the file extension. This helps enforce specific naming conventions.
     * @param string $file_name The original filename.
     * @return string The potentially modified or original filename.
     */
    public function ensure_keyword_in_filename(string $file_name): string {
        $keyword = $this->config['filename_keyword_ensure'];
        $suffix  = $this->config['filename_keyword_suffix'];

        // Skip operation if configuration is incomplete.
        if ($keyword === null || $suffix === null) {
            return $file_name;
        }

        // Check if the keyword is already present (case-insensitive).
        if (strpos(strtolower($file_name), strtolower($keyword)) === false) {
            $filename_no_ext = pathinfo($file_name, PATHINFO_FILENAME);
            $extension       = pathinfo($file_name, PATHINFO_EXTENSION);
            
            // Reconstruct the filename with the suffix before the extension.
            if (!empty($extension)) {
                return $filename_no_ext . $suffix . '.' . $extension;
            }
            return $filename_no_ext . $suffix;
        }
        return $file_name;
    }

    /**
     * Validates the properties of an uploaded file (based on $_FILES data).
     * Checks for server-side PHP upload errors and verifies the file's MIME type 
     * against the 'allowed_mime_types' configuration.
     * Calls {@see self::validate_filename_structure()} for final name validation.
     * * @param array $file_upload_data The file properties array (typically an element from the $_FILES superglobal).
     * @param string $file_name_to_validate The name of the file to validate.
     * @return array An array of error messages (empty if the file properties and name are valid).
     */
    public function validate_file_properties(array $file_upload_data, string $file_name_to_validate): array {
        $errors     = [];
        $file_type  = $file_upload_data['type'] ?? ''; 
        $file_error = $file_upload_data['error'] ?? UPLOAD_ERR_NO_FILE; 

        // 1. Check for server-side PHP/Upload errors.
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
            return $errors; // Halt validation on server upload error.
        }

        // 2. Check for allowed MIME types.
        if (isset($file_upload_data['type']) && !empty($this->config['allowed_mime_types']) && !in_array($file_type, $this->config['allowed_mime_types'], true)) {
            $errors[] = sprintf(
                /* translators: %s is a comma-separated list of allowed file types (e.g., "image/jpeg, application/pdf"). */
                __('Invalid file type. Allowed: %s', 'dropzone-manager'),
                implode(', ', $this->config['allowed_mime_types'])
            );
        }
        
        // 3. Proceed to filename structure validation.
        return $this->validate_filename_structure($file_name_to_validate, $errors);
    }

    /**
     * Validates the structure of a filename using configured Regular Expressions (regex).
     * Checks the prefix, keyword/middle, and extension patterns.
     * @param string $file_name_to_validate The filename string to check.
     * @param array $existing_errors Any previous errors to append to.
     * @return array An array of error messages (empty if the structure is valid).
     */
    public function validate_filename_structure(string $file_name_to_validate, array $existing_errors = []): array {
        $errors = $existing_errors;
        
        // Check for required prefix pattern
        if ($this->config['name_pattern_prefix_regex'] && !preg_match($this->config['name_pattern_prefix_regex'], $file_name_to_validate)) {
            $errors[] = __('Filename does not meet the required prefix pattern.', 'dropzone-manager');
        }
        
        // Check for required keyword/middle pattern
        if ($this->config['name_pattern_keyword_regex'] && !preg_match($this->config['name_pattern_keyword_regex'], $file_name_to_validate)) {
            $errors[] = __('Filename does not meet the required keyword pattern.', 'dropzone-manager');
        }
        
        // Check for required extension pattern
        if ($this->config['name_pattern_extension_regex'] && !preg_match($this->config['name_pattern_extension_regex'], $file_name_to_validate)) {
            $errors[] = __('Filename does not meet the required extension pattern (or is missing an extension).', 'dropzone-manager');
        }
        return $errors;
    }

    /**
     * Determines the absolute server path and URL to the target upload directory.
     * Handles directory creation using `wp_mkdir_p()` if the path does not exist.
     * The subdirectory is determined by 'target_subdir_pattern' (supporting PHP date formats).
     * * @return array|WP_Error An array containing 'path', 'url', 'baseurl', and 'subdir' keys, 
     * or a WP_Error object if the path cannot be retrieved or created.
     */
    public function get_target_path_info() {
        // Use the WordPress function to retrieve the base upload directory information.
        $upload_dir_info = wp_upload_dir();
        if (!$upload_dir_info || !empty($upload_dir_info['error'])) {
            return new WP_Error('upload_dir_error', $upload_dir_info['error'] ?? __('Could not retrieve upload directory information.', 'dropzone-manager'));
        }

        $target_subdir = '';
        if (!empty($this->config['target_subdir_pattern'])) {
            $pattern = trim($this->config['target_subdir_pattern'], '/');
            // Check if the pattern contains PHP date format characters (e.g., Y, m, d).
            // If so, use date_i18n for localization; otherwise, use the pattern as a static folder name.
            $target_subdir = (preg_match('/[YymFdDgGhHisua]/', $pattern)) ? '/' . date_i18n($pattern) . '/' : '/' . $pattern . '/';
        } else {
            return new WP_Error('target_dir_not_configured', __('Target directory (target_subdir_pattern or target_year/month) is not specified.', 'dropzone-manager'));
        }

        // Clean up path separators and determine the absolute path.
        $target_subdir = str_replace('//', '/', $target_subdir);
        $target_path   = $upload_dir_info['basedir'] . $target_subdir;

        // 1. Create the directory if it does not exist using the WordPress safe function.
        if (!file_exists($target_path)) {
            if (!wp_mkdir_p($target_path)) {
                return new WP_Error('dir_creation_failed', sprintf(
                    /* translators: %s is the server file path that could not be created. */
                    __('Could not create target directory on server: %s', 'dropzone-manager'),
                    $target_path
                ));
            }
        }
        
        // 2. Final check to ensure the path is actually a directory.
        if (!is_dir($target_path)) { 
            return new WP_Error('target_not_dir', sprintf(
                /* translators: %s is the server file path that was expected to be a directory. */
                __('Target path is not a directory: %s', 'dropzone-manager'),
                $target_path
            ));
        }

        /**
         * @var array Target path information structure.
         */
        return [
            'path'    => trailingslashit($target_path), // Absolute server path (trailing slash ensured)
            'url'     => trailingslashit($upload_dir_info['baseurl'] . $target_subdir), // Absolute URL
            'baseurl' => $upload_dir_info['baseurl'],
            'subdir'  => $target_subdir, // Relative path within the uploads base directory
        ];
    }

    /**
     * Lists files in the configured target directory based on the glob pattern.
     * Optionally performs strict filename validation on listed files if configured.
     * @return array A sorted array of filenames (name only, no path). Returns an empty array on error.
     */
    public function list_files_in_target_dir(): array {
        $path_info = $this->get_target_path_info();
        if (is_wp_error($path_info)) {
            // Log the error and return an empty array to maintain smooth application flow.
            error_log("FileUtils Error in list_files_in_target_dir: " . $path_info->get_error_message());
            return [];
        }

        $target_dir_path = $path_info['path'];
        $glob_pattern    = $this->config['list_files_glob_pattern'] ?? '*.*'; // Default to all files if pattern is not specified

        // Check if the directory is readable.
        if (!is_readable($target_dir_path)) {
            error_log("FileUtils Error: Target directory not readable: " . $target_dir_path);
            return [];
        }

        // Use glob() to search for files matching the pattern. GLOB_BRACE allows comma-separated patterns.
        $found_files_paths = glob($target_dir_path . $glob_pattern, GLOB_BRACE | GLOB_ERR);

        if ($found_files_paths === false) { // glob() can return 'false' on a server error.
            error_log("FileUtils Error: glob() failed for pattern: " . $target_dir_path . $glob_pattern);
            return [];
        }

        $filenames = [];
        foreach ($found_files_paths as $file_path) {
            $filename = basename($file_path);
            
            // Optional: Strict filename structure validation for files being listed.
            if ($this->config['validate_filename_on_list']) {
                $validation_errors = $this->validate_filename_structure($filename);
                if (!empty($validation_errors)) {
                    // Skip files that do not meet the strict naming structure.
                    continue; 
                }
            }
            $filenames[] = $filename;
        }

        sort($filenames); // Sort alphabetically for consistent UI presentation.
        return $filenames;
    }

    /**
     * Sanitizes and prepares a filename for safe file operations.
     * This prevents Directory Traversal attacks (e.g., via "../") by stripping path information 
     * first, and then applies WordPress' built-in filename sanitization rules.
     * * @param string $raw_filename The raw filename (may include path attempts).
     * @return string The safe, sanitized filename ready for use.
     */
    public function sanitize_and_prepare_filename(string $raw_filename): string {
        // 1. Strip path information (e.g., "../", "./") using basename and stripslashes.
        $filename_only = basename(stripslashes($raw_filename));
        
        // 2. Use WordPress' function for further sanitization (replacing spaces, special characters).
        return sanitize_file_name($filename_only);
    }

    /**
     * Constructs the full public URL to a specific file located within the target directory.
     * @param string $filename The filename.
     * @return string|WP_Error The full, URL-encoded file URL, or a WP_Error if path info fails.
     */
    public function get_file_url(string $filename) {
        $path_info = $this->get_target_path_info();
        if (is_wp_error($path_info)) {
            return $path_info;
        }
        // Use rawurlencode() to safely encode the filename component of the URL.
        return $path_info['url'] . rawurlencode($filename);
    }

} // End of the FileUtils class


/**
 * DropZoneManager Class
 *
 * Handles the file management interface (listing, delete, rename) on the frontend
 * via a shortcode, and processes the corresponding AJAX requests on the backend.
 * It relies on the FileUtils class for all file system and validation logic.
 */
class DropZoneManager {

    /** @var string The WordPress shortcode tag used to render the manager interface. */
    private $shortcode_tag = 'file_manager';
    /** @var string The specific AJAX action name used for processing delete/rename requests. */
    private $ajax_action   = 'process_file_actions';
    /** @var string The action name used to create and verify the security nonce. */
    private $nonce_action  = 'file_actions_nonce';
    /** @var string The handle used when enqueueing the main JavaScript file. */
    private $js_handle     = 'file-manager-js';
    /** @var string The URL path to the main JavaScript file. */
    private $js_path       = '/dropzone/js/file-manager.js';

    /** @var FileUtils The utility instance responsible for file system operations and validation. */
    private $file_utils;

    /** @var array Configuration specific to this manager instance (e.g., UI texts, capabilities). */
    private $manager_config;

    /**
     * Constructor. Initializes the configuration and hooks the shortcode and AJAX handler.
     * @param array $manager_config Configuration specific to this manager.
     * @param FileUtils $file_utils_instance A pre-configured instance of FileUtils.
     */
    public function __construct(array $manager_config, FileUtils $file_utils_instance) {
        $this->manager_config = $manager_config;
        $this->file_utils     = $file_utils_instance;
        
        // Override default properties with values from manager_config if provided
        $this->shortcode_tag = $manager_config['shortcode_tag'] ?? $this->shortcode_tag;
        $this->ajax_action   = $manager_config['ajax_action'] ?? $this->ajax_action;
        $this->nonce_action  = $manager_config['nonce_action'] ?? $this->nonce_action;
        $this->js_handle     = $manager_config['js_handle'] ?? $this->js_handle;
        $this->js_path       = $manager_config['js_path'] ?? $this->js_path;

        $this->register_shortcode();
        $this->register_ajax_handler();
        // Hook the enqueue method for styles.
        add_action('wp_enqueue_scripts', [$this, 'dropzone_manager_enqueue_styles']);
    }

    /**
     * Enqueues the main stylesheet for the file manager.
     * Only enqueues if the shortcode is present on the current page to optimize loading.
     */
    public function dropzone_manager_enqueue_styles() {
        global $post;
        
        // Check if the current post object exists and contains the shortcode tag.
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, $this->shortcode_tag ) ) {
            wp_enqueue_style(
                'dropzone-manager-style',
                CUSTOM_DROPZONE_PLUGIN_URL . 'css/style.css',
                [],
                '1.0.1' // Versioning for cache control
            );
        }
    }

    /**
     * Registers the WordPress shortcode using the configured tag.
     */
    public function register_shortcode() {
        if (defined('DROPZONE_DEBUG_ON') && DROPZONE_DEBUG_ON) {
            error_log('DEBUG: Shortcode is being registered with tag: ' . $this->shortcode_tag);
        }
        add_shortcode($this->shortcode_tag, [$this, 'render_shortcode_content']);
    }

    /**
     * Renders the HTML output for the file manager shortcode.
     * This method enqueues the necessary scripts and localization data, checks for file system errors,
     * lists the files, and generates the UI for actions (delete/rename).
     * @return string HTML output containing the file manager interface.
     */
    public function render_shortcode_content(): string {
        if (defined('DROPZONE_DEBUG_ON') && DROPZONE_DEBUG_ON) {
            error_log('DEBUG: render_shortcode_content() is executing for tag: ' . $this->shortcode_tag);
            error_log('DEBUG: Path to JS file: ' . $this->js_path);
        }

        // --- 1. Enqueue Scripts and Localize Data ---

        // Localize translation strings for the frontend JavaScript.
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

        // Enqueue helper scripts (if applicable, assuming a helper file exists).
        wp_enqueue_script(
            'custom-dropzone-helpers', 
            plugin_dir_url(__FILE__) . 'js/plugin-helpers.js',
            ['jquery'],
            '1.0.0',
            true
        );

        // Enqueue the main file manager script.
        wp_enqueue_script(
            $this->js_handle,
            $this->js_path,
            ['jquery', 'custom-dropzone-helpers'],
            '1.0.2-' . time(), // Dynamic version for aggressive caching prevention during development.
            true
        );

        // Localize the necessary configuration parameters for the AJAX requests.
        wp_localize_script($this->js_handle, 'file_manager_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce($this->nonce_action),
            'action'   => $this->ajax_action, 
        ]);

        // Optional: Enqueue custom CSS specified in the manager configuration.
        if (!empty($this->manager_config['css_path'])) {
            wp_enqueue_style(
                $this->manager_config['css_handle'] ?? ($this->js_handle . '-css'), 
                $this->manager_config['css_path'],
                [],
                $this->manager_config['css_version'] ?? '1.0.0'
            );
        }

        // --- 2. Retrieve Path Info and File List ---

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

        // Prepare section title, potentially including the subdirectory name.
        $section_title = $this->manager_config['section_title'] ?? __('File Overview', 'dropzone-manager');
        if (strpos($section_title, '%s') !== false && isset($path_info['subdir'])) {
            $subdir_display = trim($path_info['subdir'], '/');
            $section_title  = sprintf($section_title, esc_html($subdir_display));
        }

        // --- 3. Render HTML Output ---
        ob_start();
        ?>
        <div id="file-manager-container">
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
                <form id="file-actions-form">
                    <p><?php _e('Select files to delete or provide a new name.', 'dropzone-manager'); ?></p>
                    <ul id="file-list" style="list-style-type: none; padding-left: 0;">
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

                <div id="confirmation-area" style="margin-top: 20px; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; display: none;">
                    <h4><?php _e('Confirm the following actions:', 'dropzone-manager'); ?></h4>
                    <div id="actions-summary">
                        </div>
                    <button type="button" id="execute-actions-btn" class="button button-danger" style="margin-right: 10px;"><?php _e('Yes, Execute Actions', 'dropzone-manager'); ?></button>
                    <button type="button" id="cancel-actions-btn" class="button"><?php _e('Cancel', 'dropzone-manager'); ?></button>
                </div>

                <div id="feedback-area" style="margin-top: 15px;">
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
            <?php endif; // End of file list conditional rendering ?>
        </div><?php
        return ob_get_clean();
    }

    /**
     * Registers the WordPress AJAX handler for authenticated users.
     */
    public function register_ajax_handler() {
        add_action('wp_ajax_' . $this->ajax_action, [$this, 'handle_file_actions_callback']);
        // Uncomment the line below if unauthenticated users should also be allowed to manage files.
        // add_action('wp_ajax_nopriv_' . $this->ajax_action, array($this, 'handle_file_actions_callback')); 
    }

    /**
     * AJAX handler for processing file actions (delete, rename) received from the frontend.
     * Performs security checks, validation, and executes file system operations.
     */
    public function handle_file_actions_callback() {
        // Enforce nonce check for security.
        check_ajax_referer($this->nonce_action, 'nonce');

        $capability_manage = $this->manager_config['capability_manage'] ?? 'manage_options';
        // Enforce user capabilities check.
        if (!current_user_can($capability_manage)) {
            wp_send_json_error(['message' => __('Insufficient permissions to perform these actions.', 'dropzone-manager')], 403);
            return;
        }

        // Sanitize input arrays (they contain raw filenames from the user).
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
            // Sanitize the raw filename to prevent directory traversal attacks.
            $filename = $this->file_utils->sanitize_and_prepare_filename($filename_raw);
            if (empty($filename)) {
                $results['errors'][] = sprintf(__('Invalid filename for deletion (empty after sanitization): %s', 'dropzone-manager'), esc_html($filename_raw));
                continue;
            }

            // Validate the filename structure using FileUtils configuration.
            $validation_errors_delete = $this->file_utils->validate_filename_structure($filename);
            if (!empty($validation_errors_delete)) {
                $results['errors'][] = sprintf(__('File \'%s\' does not meet the structural requirements for deletion: %s', 'dropzone-manager'), esc_html($filename), implode(', ', $validation_errors_delete));
                continue;
            }

            $file_path = $target_dir_path . $filename;

            if (file_exists($file_path) && is_file($file_path)) {
                // Perform permission checks before attempting unlink.
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
            // Sanitize both old and new filenames.
            $old_filename = $this->file_utils->sanitize_and_prepare_filename($old_filename_raw);
            $new_filename = $this->file_utils->sanitize_and_prepare_filename($new_filename_raw);

            if (empty($old_filename) || empty($new_filename)) {
                $results['errors'][] = __('Invalid old or new filename for rename (empty after sanitization).', 'dropzone-manager');
                continue;
            }
            if ($old_filename === $new_filename) {
                continue; // Skip if no actual change occurred.
            }

            // Validate old filename structure.
            $validation_errors_old = $this->file_utils->validate_filename_structure($old_filename);
            if (!empty($validation_errors_old)) {
                $results['errors'][] = sprintf(__('Old filename \'%s\' is not valid: %s', 'dropzone-manager'), esc_html($old_filename), implode(', ', $validation_errors_old));
                continue;
            }
            
            // Optionally ensure the configured keyword is present in the new filename.
            if ($this->manager_config['ensure_keyword_on_rename'] ?? false) {
                $new_filename = $this->file_utils->ensure_keyword_in_filename($new_filename);
            }
            // Validate new filename structure.
            $validation_errors_new = $this->file_utils->validate_filename_structure($new_filename);
            if (!empty($validation_errors_new)) {
                $results['errors'][] = sprintf(__('New filename \'%s\' (to \'%s\') is not valid: %s', 'dropzone-manager'), esc_html($new_filename_raw), esc_html($new_filename), implode(', ', $validation_errors_new));
                continue;
            }

            $old_file_path = $target_dir_path . $old_filename;
            $new_file_path = $target_dir_path . $new_filename;

            // Check for file existence and target conflicts.
            if (!file_exists($old_file_path) || !is_file($old_file_path)) {
                $results['errors'][] = sprintf(__('Old file not found: %s', 'dropzone-manager'), esc_html($old_filename));
                continue;
            }
            if (file_exists($new_file_path)) {
                $results['errors'][] = sprintf(__('Target file \'%s\' already exists. Cannot rename \'%s\'.', 'dropzone-manager'), esc_html($new_filename), esc_html($old_filename));
                continue;
            }

            // Check directory and file writability before attempting rename.
            if (is_writable($target_dir_path) && is_writable($old_file_path)) {
                if (rename($old_file_path, $new_file_path)) {
                    $results['renamed'][] = ['old' => esc_html($old_filename), 'new' => esc_html($new_filename)];
                } else {
                    $results['errors'][] = sprintf(__('Could not rename \'%s\' to \'%s\'. Check server logs for details.', 'dropzone-manager'), esc_html($old_filename), esc_html($new_filename));
                }
            } else {
                // Detailed permission error message.
                $error_message = sprintf(__('Insufficient permissions to rename \'%s\'.', 'dropzone-manager'), esc_html($old_filename)) . ' ';
                if (!is_writable($target_dir_path)) {
                    $error_message .= sprintf(__('Target directory \'%s\' is not writable.', 'dropzone-manager'), esc_html(basename($target_dir_path))) . ' ';
                }
                if (!is_writable($old_file_path)) {
                    $error_message .= sprintf(__('Old file \'%s\' is not writable.', 'dropzone-manager'), esc_html($old_filename));
                }
                $results['errors'][] = trim($error_message);
            }
        } 

        // --- Send the results back as JSON ---
        if (empty($results['errors']) && empty($results['deleted']) && empty($results['renamed'])) {
            // Success response for no action taken (e.g., user clicked execute with no changes).
            wp_send_json_success(array_merge($results, ['message' => __('No actions performed (perhaps nothing was selected or to be changed).', 'dropzone-manager')]));
        } elseif (!empty($results['errors']) && empty($results['deleted']) && empty($results['renamed'])) {
            // Error response if *only* errors occurred.
            wp_send_json_error(array_merge($results, ['message' => __('Errors occurred while processing the actions.', 'dropzone-manager')]));
        } else {
            // Success response for at least one successful action (even if mixed with errors).
            wp_send_json_success($results);
        }
    }

} 

// --- DropZoneUploader Class ---

/**
 * Class DropZoneUploader
 *
 * Handles the custom file upload interface (dropzone and button) on the frontend
 * via a shortcode, and processes the file upload via AJAX on the backend.
 */
class DropZoneUploader {

    /** @var string The WordPress shortcode tag used to render the uploader interface. */
    private $shortcode_tag = 'my_upload_dropzone';
    /** @var string The specific AJAX action name used for processing the upload request. */
    private $ajax_action   = 'handle_custom_upload';
    /** @var string The action name used to create and verify the security nonce. */
    private $nonce_action  = 'custom_upload_nonce';
    /** @var string The handle used when enqueueing the main JavaScript file. */
    private $js_handle     = 'custom-uploader-js';
    /** @var string The URL path to the main JavaScript file. */
    private $js_path       = '/js/default-uploader.js';

    /** @var array Configuration specific to this uploader instance (e.g., UI texts, capabilities). */
    private $uploader_config;

    /** @var FileUtils The utility instance responsible for file system operations and validation. */
    private $file_utils_instance;

    /**
     * Constructor. Initializes the configuration and hooks the shortcode and AJAX handler.
     * @param array $uploader_config Configuration specific to this uploader instance.
     * @param FileUtils $file_utils_instance A pre-configured instance of FileUtils.
     */
    public function __construct(array $uploader_config, FileUtils $file_utils_instance) {
        $this->uploader_config     = $uploader_config;
        $this->file_utils_instance = $file_utils_instance;

        // Override default properties with values from $uploader_config if present.
        $this->shortcode_tag = $uploader_config['shortcode_tag'] ?? $this->shortcode_tag;
        $this->ajax_action   = $uploader_config['ajax_action'] ?? $this->ajax_action;
        $this->nonce_action  = $uploader_config['nonce_action'] ?? $this->nonce_action;
        $this->js_handle     = $uploader_config['js_handle'] ?? $this->js_handle;
        $this->js_path       = $uploader_config['js_path'] ?? $this->js_path;

        $this->register_shortcode();
        $this->register_ajax_handler();
    }

    /**
     * Registers the WordPress shortcode using the configured tag.
     */
    public function register_shortcode() {
        if (defined('DROPZONE_DEBUG_ON') && DROPZONE_DEBUG_ON) {
            error_log('DEBUG: Shortcode is being registered with tag: ' . $this->shortcode_tag);
        }
        add_shortcode($this->shortcode_tag, [$this, 'render_shortcode']);
    }

    /**
     * Renders the HTML for the uploader shortcode and enqueues scripts/localization.
     * @return string HTML output containing the custom dropzone interface.
     */
    public function render_shortcode(): string {
        // Enqueue helper scripts (if applicable, assuming a helper file exists).
        wp_enqueue_script(
            'custom-dropzone-helpers',
            plugin_dir_url(__FILE__) . 'js/plugin-helpers.js',
            ['jquery'],
            '1.0.0',
            true
        );

        // Enqueue the main uploader script.
        wp_enqueue_script(
            $this->js_handle,
            $this->js_path,
            ['jquery', 'custom-dropzone-helpers'],
            $this->uploader_config['js_version'] ?? null, // Use configured version or none
            true
        );

        // Localize configuration parameters for the AJAX request.
        $localize_handle = $this->uploader_config['js_localize_handle'] ?? 'custom_uploader_params';
        wp_localize_script($this->js_handle, $localize_handle, [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce($this->nonce_action),
        ]);

        // Localize translation strings for the frontend JavaScript.
        wp_localize_script($this->js_handle, 'uploader_i18n_params', [
            'uploading_message' => __('Uploading:', 'dropzone-manager'),
            'error_prefix'      => __('Error:', 'dropzone-manager'),
            'pdf_only_error'    => __('Only PDF files are allowed and the name must end with .pdf.', 'dropzone-manager'),
            'yymmdd_error'      => __('Filename must start with 6 digits (yymmdd).', 'dropzone-manager'),
            'file_url_label'    => __('File URL:', 'dropzone-manager'),
            'unknown_error'     => __('Unknown error occurred.', 'dropzone-manager'),
            'ajax_error_prefix' => __('AJAX Error:', 'dropzone-manager'),
        ]);

        // Retrieve UI texts and IDs from the configuration.
        $main_instruction = $this->uploader_config['ui_main_instruction'] ?? __('Drag and drop a file here', 'dropzone-manager');
        $alt_instruction  = $this->uploader_config['ui_alt_instruction'] ?? __('or', 'dropzone-manager');
        $button_text      = $this->uploader_config['ui_button_text'] ?? __('Select File', 'dropzone-manager');
        $accept_types     = $this->uploader_config['ui_accept_types'] ?? '.pdf'; 

        $container_id     = $this->uploader_config['ui_container_id'] ?? 'custom-dropzone';
        $file_input_id    = $this->uploader_config['ui_file_input_id'] ?? 'custom-file-input';
        $browse_button_id = $this->uploader_config['ui_browse_button_id'] ?? 'custom-browse-button';
        $feedback_id      = $this->uploader_config['ui_feedback_id'] ?? 'upload-feedback';

        // Start output buffering to capture the HTML.
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
     * Registers the WordPress AJAX handler for the upload process.
     * Hooks both for authenticated users (`wp_ajax_`) and optionally for unauthenticated users (`wp_ajax_nopriv_`).
     */
    public function register_ajax_handler() {
        add_action('wp_ajax_' . $this->ajax_action, [$this, 'handle_upload_callback']);

        $allow_nopriv = $this->uploader_config['allow_nopriv_upload'] ?? false;
        if ($allow_nopriv) {
            add_action('wp_ajax_nopriv_' . $this->ajax_action, [$this, 'handle_upload_callback']);
        }
    }

    /**
     * AJAX handler for processing the file upload (`$_FILES`).
     * Performs security checks, file validation using FileUtils, and moves the uploaded file.
     */
    public function handle_upload_callback() {
        // Enforce nonce check for security.
        check_ajax_referer($this->nonce_action, 'nonce');

        $capability_upload = $this->uploader_config['capability_upload'] ?? 'upload_files';
        // Enforce capability check unless public upload is explicitly allowed.
        if (!current_user_can($capability_upload) && !($this->uploader_config['allow_nopriv_upload'] ?? false)) {
            wp_send_json_error(['message' => __('Insufficient permissions to upload.', 'dropzone-manager')], 403);
            return;
        }

        // Check for the uploaded file data. 'uploaded_file' is the expected name from the frontend script.
        if (empty($_FILES['uploaded_file'])) { 
            wp_send_json_error(['message' => __('No file received.', 'dropzone-manager')], 400);
            return;
        }

        $file_upload_data   = $_FILES['uploaded_file'];
        $original_file_name = $file_upload_data['name'];

        // --- 1. Validation and Preparation ---
        
        // Sanitize the raw filename.
        $prepared_file_name = $this->file_utils_instance->sanitize_and_prepare_filename($original_file_name);
        // Ensure required keywords/suffixes are present in the filename.
        $final_file_name    = $this->file_utils_instance->ensure_keyword_in_filename($prepared_file_name);
        // Perform all structural and file property validation (MIME, size, regex).
        $validation_errors  = $this->file_utils_instance->validate_file_properties($file_upload_data, $final_file_name);

        if (!empty($validation_errors)) {
            wp_send_json_error(['message' => implode(' ', $validation_errors)], 400);
            return;
        }

        // --- 2. Path Determination and Conflict Check ---

        $target_path_details = $this->file_utils_instance->get_target_path_info();
        if (is_wp_error($target_path_details)) {
            wp_send_json_error(['message' => sprintf(__('Error with target directory: %s', 'dropzone-manager'), $target_path_details->get_error_message())], 500);
            return;
        }

        $target_dir_path = $target_path_details['path'];
        $new_file_path   = $target_dir_path . $final_file_name;

        // Check for file existence and handle overwrite permission.
        if (file_exists($new_file_path)) {
            $allow_overwrite = $this->uploader_config['allow_overwrite'] ?? false; 
            if (!$allow_overwrite) {
                wp_send_json_error(['message' => sprintf(__('A file with the name "%s" already exists.', 'dropzone-manager'), esc_html($final_file_name))], 409); // 409 Conflict
                return;
            }
        }

        // --- 3. Final Move Operation ---

        // Use the native PHP function to move the file from the temporary location.
        if (move_uploaded_file($file_upload_data['tmp_name'], $new_file_path)) {
            $file_url_or_error = $this->file_utils_instance->get_file_url($final_file_name);
            $file_url          = is_wp_error($file_url_or_error) ? '#' : $file_url_or_error;

            // Successful upload response.
            wp_send_json_success([
                'message'   => sprintf(__('File "%s" uploaded successfully!', 'dropzone-manager'), esc_html($final_file_name)),
                'file_url'  => esc_url($file_url),
                'file_path' => $new_file_path,
            ]);
        } else {
            // Detailed error reporting for failed move.
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

} 

// --- Helper Function ---

/**
 * Helper function to quickly build and register a complete set of DropZone functionality
 * (both Uploader and Manager) based on a simple slug and a set of file rules.
 *
 * @param string $slug A unique, short name (slug) for this instance, e.g., 'ovd' or 'newsletter'.
 * @param array $file_rules The rules for the files, used to configure FileUtils (e.g., directory pattern, regex rules).
 * @param array $ui_texts (Optional) Custom localized texts for the user interface.
 * @param array $override_config (Optional) To override default generated configuration values (for 'uploader' or 'manager').
 * @return void
 */
function create_dropzone_instance(string $slug, array $file_rules, array $ui_texts = [], array $override_config = []): void {
    // Allows external code (plugins/themes) to filter and modify the configuration before instantiation.
    list($slug, $file_rules, $ui_texts, $override_config) = apply_filters('create_dropzone_instance_config', [$slug, $file_rules, $ui_texts, $override_config]);

    // --- 1. Build the FileUtils configuration and instance ---
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

    // --- 2. Build the Uploader configuration and instantiate DropZoneUploader ---
    $default_uploader_config = [
        'shortcode_tag'         => $slug . '_uploader',
        'ajax_action'           => 'handle_' . $slug . '_upload',
        'nonce_action'          => $slug . '_upload_nonce',
        'js_path'               => CUSTOM_DROPZONE_PLUGIN_URL . 'js/all-scripts.js',
        'js_handle'             => 'custom-dropzone-all-scripts',
        'js_localize_handle'    => 'custom_uploader_params',
        'capability_upload'     => 'upload_files',
        'allow_overwrite'       => false,
        'ui_main_instruction'   => $ui_texts['main_instruction'] ?? sprintf(__('Drag and drop a file (%s) here', 'dropzone-manager'), $slug),
        'ui_button_text'        => $ui_texts['button_text'] ?? __('Select file', 'dropzone-manager'),
    ];
    // Merge generated defaults with specific overrides from the 'uploader' key.
    $final_uploader_config = array_merge($default_uploader_config, ($override_config['uploader'] ?? []));

    if (class_exists('DropZoneUploader')) {
        new DropZoneUploader($final_uploader_config, $file_utils_instance);
    }

    // --- 3. Build the Manager configuration and instantiate DropZoneManager ---
    $default_manager_config = [
        'shortcode_tag'            => $slug . '_manager',
        'ajax_action'              => 'process_' . $slug . '_actions',
        'nonce_action'             => $slug . '_manager_nonce',
        'js_handle'                => 'custom-dropzone-all-scripts',
        'js_path'                  => CUSTOM_DROPZONE_PLUGIN_URL . 'js/all-scripts.js',
        'capability_manage'        => 'manage_options',
        'ensure_keyword_on_rename' => true,
        'section_title'            => $ui_texts['section_title'] ?? sprintf(__('Overview of %s Files', 'dropzone-manager'), ucfirst($slug)),
    ];
    // Merge generated defaults with specific overrides from the 'manager' key.
    $final_manager_config = array_merge($default_manager_config, ($override_config['manager'] ?? []));

    if (class_exists('DropZoneManager')) {
        new DropZoneManager($final_manager_config, $file_utils_instance);
    }
}