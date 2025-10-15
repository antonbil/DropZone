/**
 * OVD File Manager and Custom Uploader Script
 * This IIFE ensures all variables are scoped locally and uses the $ alias for jQuery.
 * * @param {jQuery} $ The jQuery object passed as an argument.
 */
(function ($) {
    'use strict';

    // === Script Load Protection ===
    // This global flag prevents the entire script from executing more than once,
    // which is a common issue in CMS environments where scripts are accidentally enqueued multiple times.
    if (window.OVD_MANAGER_LOADED) {
        console.warn("OVD Manager script was loaded twice. Execution halted.");
        return; // Stop the execution of the entire IIFE
    }

    // Set the flag after the check to signal that the script has initialized successfully.
    window.OVD_MANAGER_LOADED = true;

    // A simple flag to enable/disable console logging for development and debugging purposes.
    let debugging = false;

    /**
     * Helper function to escape HTML characters for safe display.
     * This is crucial to prevent Cross-Site Scripting (XSS) vulnerabilities 
     * when displaying user-controllable data (like filenames) back into the HTML.
     * * @param {string} unsafe The potentially unsafe string containing HTML characters.
     * @returns {string} The escaped, safe string.
     */
    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') return '';
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // --------------------------------------------------------------------------------
    
    // === File Manager Component Initialization ===
    /**
     * Initializes the File Action Manager, handling file deletion and renaming.
     * This section manages user selections, prepares an action summary, and executes
     * the requested actions via an AJAX call.
     */
    function initializeManager() {
        if (debugging)  console.log("start: Initializing File Manager.");
        
        // Caching key DOM elements for the File Manager section
        const container = $('#file-manager-container');
        if (!container.length) return; // Exit if the main container is not found

        const form = $('#file-actions-form');
        const fileList = $('#file-list');
        const prepareBtn = $('#prepare-actions-btn');
        const confirmationArea = $('#confirmation-area');
        const actionsSummaryDiv = $('#actions-summary');
        const executeBtn = $('#execute-actions-btn');
        const cancelBtn = $('#cancel-actions-btn');
        const feedbackArea = $('#feedback-area');

        // Retrieve localized parameters and translation strings (assumed from a CMS, e.g., WordPress)
        const wp_params = window.file_manager_params || {};
        const i18n = window.manager_i18n_params || {};

        /**
         * Event handler for the 'Rename' toggle button.
         * Toggles the visibility of the rename input field and focuses on it for user input.
         * The event listener is delegated to 'fileList' for efficiency and to support dynamically added files.
         */
        fileList.on('click', '.rename-toggle-btn', function () {
            // Note: If double-triggering occurs here, a debounce mechanism should be implemented.
            if (debugging)  console.log('Click rename: Toggling rename field.');
            
            const wrapper = $(this).siblings('.rename-field-wrapper');
            const inputField = wrapper.find('.new-name-input');
            
            wrapper.toggle(); // Toggle the visibility of the wrapper
            
            if (debugging)  console.log('Click rename after toggle state:', wrapper.is(':visible') ? 'Visible' : 'Hidden');
            
            if (wrapper.is(':visible')) {
                // When visible, focus the input and pre-fill it with the current filename
                inputField.focus();
                const currentFilename = $(this).closest('.file-item').data('filename');
                inputField.val(currentFilename);
                if (debugging)  console.log('Rename field focused and pre-filled with:', currentFilename);
            }
        });

        /**
         * Handler for the 'Prepare Actions' button.
         * Gathers all selected delete and rename actions from the form and generates a summary for confirmation.
         */
        prepareBtn.on('click', function () {
            if (debugging) console.log('Prepare Actions clicked. Generating summary.');
            
            feedbackArea.empty().hide();
            actionsSummaryDiv.empty();
            confirmationArea.hide();

            let actionsHtml = '<ul>';
            let hasActions = false;

            // 1. Gather files marked for deletion
            form.find('input[name="delete_files[]"]:checked').each(function () {
                const filename = $(this).val();
                actionsHtml += '<li><strong>' + (i18n.delete_label || 'Delete') + '</strong> ' + escapeHtml(filename) + '</li>';
                hasActions = true;
            });

            // 2. Gather files marked for renaming
            form.find('.new-name-input').each(function () {
                const originalFilename = $(this).closest('.file-item').data('filename');
                const newFilename = $(this).val().trim();
                
                // Only include if a new name is provided and it differs from the original
                if (newFilename !== '' && newFilename !== originalFilename) {
                    actionsHtml += '<li><strong>' + (i18n.rename_label || 'Rename') + '</strong> "' + escapeHtml(originalFilename) + '" &rarr; "' + escapeHtml(newFilename) + '"</li>';
                    hasActions = true;
                }
            });
            actionsHtml += '</ul>';

            // Display the summary or a 'no actions' message
            if (hasActions) {
                actionsSummaryDiv.html(actionsHtml);
                confirmationArea.show();
                if (debugging) console.log('Actions found. Confirmation area shown.');
            } else {
                feedbackArea.html('<p>' + (i18n.no_actions_selected || 'No actions selected') + '</p>').show();
                if (debugging) console.log('No actions selected. Feedback shown.');
            }
        });

        /**
         * Handler for the 'Cancel' button in the confirmation area.
         * Hides the confirmation summary.
         */
        cancelBtn.on('click', function () {
            if (debugging) console.log('Cancel button clicked. Hiding confirmation.');
            confirmationArea.hide();
            actionsSummaryDiv.empty();
        });

        /**
         * Handler for the 'Execute Actions' button.
         * Disables the buttons and sends the aggregated action data to the server via AJAX.
         */
        executeBtn.on('click', function () {
            if (debugging) console.log('Execute Actions clicked. Starting AJAX process.');
            
            // Disable buttons and update text to provide visual feedback during processing
            $(this).prop('disabled', true).text(i18n.processing_text || 'Processing...');
            cancelBtn.prop('disabled', true);
            feedbackArea.empty().hide();

            // Structure the data payload for the server-side action
            const dataToSend = {
                action: wp_params.action || 'process_file_actions', // Default action name
                nonce: wp_params.nonce || '', // Security nonce
                delete_files: [],
                rename_files: {} // Use an object for renames: { 'oldName': 'newName' }
            };

            // Populate delete array
            form.find('input[name="delete_files[]"]:checked').each(function () {
                dataToSend.delete_files.push($(this).val());
            });

            // Populate rename object
            form.find('.new-name-input').each(function () {
                const originalFilename = $(this).closest('.file-item').data('filename');
                const newFilename = $(this).val().trim();
                if (newFilename !== '' && newFilename !== originalFilename) {
                    dataToSend.rename_files[originalFilename] = newFilename;
                }
            });

            // Perform the AJAX request
            $.ajax({
                url: wp_params.ajax_url, // URL for AJAX handling
                type: 'POST',
                data: dataToSend,
                dataType: 'json',
                success: function (response) {
                    if (debugging) console.log('AJAX Success Response:', response);
                    
                    let feedbackHtml = '<h4>' + (i18n.processing_result_title || 'Processing result') + '</h4><ul>';
                    let hasSuccessMessages = false;

                    // Process successful response
                    if (response.success && response.data) {
                        // Display deleted files
                        if (response.data.deleted && response.data.deleted.length > 0) {
                            response.data.deleted.forEach(function (file) {
                                feedbackHtml += '<li style="color:green;">' + (i18n.deleted_feedback || 'Deleted') + ' ' + escapeHtml(file) + '</li>';
                                hasSuccessMessages = true;
                            });
                        }
                        // Display renamed files
                        if (response.data.renamed && response.data.renamed.length > 0) {
                            response.data.renamed.forEach(function (item) {
                                feedbackHtml += '<li style="color:green;">' + (i18n.renamed_feedback || 'Renamed') + ' "' + escapeHtml(item.old) + '" &rarr; "' + escapeHtml(item.new) + '"</li>';
                                hasSuccessMessages = true;
                            });
                        }
                        // Display errors encountered during processing
                        if (response.data.errors && response.data.errors.length > 0) {
                            response.data.errors.forEach(function (error) {
                                feedbackHtml += '<li style="color:red;">' + (i18n.error_feedback || 'Error') + ' ' + escapeHtml(error) + '</li>';
                            });
                        }
                        // Display a general success message if no specific deleted/renamed messages exist
                        if (response.data.message && !hasSuccessMessages && (!response.data.errors || response.data.errors.length === 0)) {
                            feedbackHtml += '<li>' + escapeHtml(response.data.message) + '</li>';
                        }
                    } else {
                        // Handle server-side failure (response.success is false)
                        const errorMessage = (response.data && response.data.message) || i18n.unknown_server_error;
                        feedbackHtml += '<li style="color:red;">' + (i18n.error_feedback || 'Error') + ' ' + escapeHtml(errorMessage) + '</li>';
                    }
                    feedbackHtml += '</ul>';

                    // Reload the page after a successful operation to show the updated file list
                    if (hasSuccessMessages) {
                        feedbackHtml += '<p><strong>' + (i18n.reload_message || 'Reloading...') + '</strong></p>';
                        setTimeout(function () {
                            location.reload();
                        }, 5000); // 5-second delay before reload
                    }

                    feedbackArea.html(feedbackHtml).show();
                    confirmationArea.hide();
                },
                error: function (jqXHR) {
                    // Handle low-level AJAX or HTTP errors
                    if (debugging)  console.log("Manager AJAX Error:", jqXHR);
                    let errorMessage = i18n.ajax_error_message || 'Ajax error';
                    
                    // Attempt to extract a detailed server error message
                    if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                        errorMessage = (i18n.server_error_prefix || 'Server error') + ' ' + escapeHtml(jqXHR.responseJSON.data.message);
                    }
                    feedbackArea.html('<p style="color:red;">' + errorMessage + '</p>').show();
                    confirmationArea.hide();
                },
                complete: function () {
                    // Re-enable buttons regardless of success or failure
                    executeBtn.prop('disabled', false).text(i18n.execute_button_text || 'Execute');
                    cancelBtn.prop('disabled', false);
                    if (debugging) console.log('AJAX request for Manager actions completed.');
                }
            });
        });
    }

    // --------------------------------------------------------------------------------

    // === Custom Uploader Component Initialization ===
    /**
     * Initializes the custom file upload functionality, supporting both file input 
     * and drag-and-drop actions. It includes client-side file validation (PDF, filename format).
     */
    function initializeUploader() {
        if (debugging)  console.log('Initializing uploader component.');
        
        // Caching key DOM elements for the Uploader section
        const dropzone = $('#custom-dropzone');
        if (!dropzone.length) {
            if (debugging)  console.log('Uploader dropzone not found, skipping initialization.');
            return;
        }
        
        const fileInput = $('#custom-file-input');
        const browseButton = $('#custom-browse-button');
        const feedbackDiv = $('#upload-feedback');

        // Retrieve localized parameters and translation strings for the uploader
        const uploader_params = window.custom_uploader_params || {};
        const i18n = window.uploader_i18n_params || {};
        
        if (debugging)  console.log('Uploader params:', uploader_params);

        /**
         * Simulates a click on the hidden file input when the custom browse button is clicked.
         */
        browseButton.on('click', function () {
            if (debugging)  console.log('Browse button clicked. Triggering file input.');
            fileInput.click();
        });

        /**
         * Handles files selected via the standard file input dialog.
         */
        fileInput.on('change', function () {
            if (debugging)  console.log('File input change event detected.');
            if (this.files.length > 0) {
                handleFile(this.files[0]); // Process the first selected file
            }
        });

        // --- Drag and Drop Handlers ---
        
        /** Prevents default browser drag behavior and highlights the dropzone. */
        dropzone.on('dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.css('border-color', '#0073aa'); // Visual feedback: highlight border
        });

        /** Resets the dropzone border color when the dragged item leaves. */
        dropzone.on('dragleave', function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.css('border-color', '#ccc'); // Visual feedback: normal border
        });

        /** Captures the dropped file(s) and passes the first file to the handler. */
        dropzone.on('drop', function (e) {
            if (debugging)  console.log('File dropped onto dropzone.');
            e.preventDefault();
            e.stopPropagation();
            dropzone.css('border-color', '#ccc'); // Reset border color
            
            // Access the files dropped via the native dataTransfer object
            const files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                handleFile(files[0]);
            }
        });
        
        // --- Core File Handling and AJAX Upload ---

        /**
         * Performs client-side validation and initiates the file upload via AJAX.
         * @param {File} file The file object to process and upload.
         */
        function handleFile(file) {
            if (debugging)  console.log('handleFile started for file:', file.name);
            
            // Initial feedback message
            feedbackDiv.html('<p>' + (i18n.uploading_message || 'Uploading:') + ' ' + escapeHtml(file.name) + '</p>').css('color', 'inherit');

            const fileName = file.name;
            // Regex for filename validation: must start with 6 digits (YYMMDD format)
            const namePatternYymmdd = /^\d{6}/;
            // Regex for file extension validation: must end with .pdf (case-insensitive)
            const namePatternPdf = /\.pdf$/i;

            // 1. Validation: Check MIME type and file extension for PDF
            if (file.type !== "application/pdf" || !namePatternPdf.test(fileName)) {
                if (debugging)  console.log('Validation failed: not a PDF file.');
                feedbackDiv.html('<p style="color:red;">' + (i18n.error_prefix || 'Error:') + ' ' + (i18n.pdf_only_error || 'PDF only') + '</p>');
                return;
            }
            
            // 2. Validation: Check filename pattern (starts with YYMMDD)
            if (!namePatternYymmdd.test(fileName)) {
                if (debugging)  console.log('Validation failed: does not start with yymmdd format.');
                feedbackDiv.html('<p style="color:red;">' + (i18n.error_prefix || 'Error:') + ' ' + (i18n.yymmdd_error || 'Filename must start with yymmdd') + '</p>');
                return;
            }
            if (debugging)  console.log('File validation passed successfully.');

            // Create FormData object to send the file and other parameters
            const formData = new FormData();
            formData.append('uploaded_file', file);
            formData.append('action', uploader_params.action || 'handle_custom_upload');
            formData.append('nonce', uploader_params.nonce);
            
            if (debugging)  console.log('Starting upload AJAX request.');
            
            // Perform the AJAX upload
            $.ajax({
                url: uploader_params.ajax_url,
                type: 'POST',
                data: formData,
                // These two settings are essential for uploading binary file data
                contentType: false, 
                processData: false, 
                success: function (response) {
                    if (debugging)  console.log('Upload AJAX success response:', response);
                    
                    if (response.success) {
                        feedbackDiv.html('<p style="color:green;">' + escapeHtml(response.data.message) + '</p>');
                        
                        // Display the URL if provided by the server
                        if (response.data.file_url) {
                            feedbackDiv.append('<p>' + (i18n.file_url_label || 'File URL:') + ' <a href="' + escapeHtml(response.data.file_url) + '" target="_blank">' + escapeHtml(response.data.file_url) + '</a></p>');
                        }
                        
                        // Initiate page reload after successful upload
                        feedbackDiv.append( '<p><strong>' + (i18n.reload_message || 'Reloading...') + '</strong></p>');
                        setTimeout(function () {
                            location.reload();
                        }, 5000);
                    } else {
                        // Handle server-side errors
                        const errorMessage = response.data.message || i18n.unknown_error;
                        feedbackDiv.html('<p style="color:red;">' + (i18n.error_prefix || 'Error:') + ' ' + escapeHtml(errorMessage) + '</p>');
                    }
                },
                error: function (jqXHR) {
                    // Handle low-level AJAX or HTTP errors during upload
                    if (debugging)  console.log("Uploader AJAX Error:", jqXHR);
                    let errorMessage = (i18n.ajax_error_prefix || 'AJAX Error:') + ' ' + jqXHR.statusText;
                     
                    // Attempt to extract a detailed server error message
                    if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                        errorMessage = (i18n.error_prefix || 'Error:') + ' ' + escapeHtml(jqXHR.responseJSON.data.message);
                    }
                    feedbackDiv.html('<p style="color:red;">' + errorMessage + '</p>');
                },
                complete: function () {
                    // Reset the file input value to allow the same file to be selected again (if needed)
                    fileInput.val('');
                    if (debugging)  console.log('Upload AJAX request complete.');
                }
            });
        }
    }

    // --------------------------------------------------------------------------------
    
    // === Initialization Trigger ===
    
    /**
     * Executes both the File Manager and the Custom Uploader initialization 
     * functions once the entire Document Object Model (DOM) is ready.
     */
    $(document).ready(function () {
        initializeManager();
        initializeUploader();
        if (debugging) console.log('All components initialized and ready.');
    });

})(jQuery);