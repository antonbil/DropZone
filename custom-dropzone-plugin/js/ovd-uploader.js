// js/custom-uploader.js
jQuery(document).ready(function ($) {
    // --- Element Selectors ---
    const dropzone = $('#custom-dropzone');
    const fileInput = $('#custom-file-input');
    const browseButton = $('#custom-browse-button');
    const feedbackDiv = $('#upload-feedback');

    // --- Parameter Localization ---
    // Localize the main parameters from WordPress (AJAX URL, nonce).
    const uploader_params = window.custom_uploader_params || {};
    // **NEW**: Localize the internationalization (i18n) strings passed from PHP.
    const i18n = window.uploader_i18n_params || {};

    // Do nothing if the dropzone is not present on the page.
    if (!dropzone.length) {
        return;
    }

    // --- Event Handlers ---
    // Open the file dialog when the browse button is clicked.
    browseButton.on('click', function () {
        fileInput.click();
    });

    // Handle the file selection from the dialog.
    fileInput.on('change', function (e) {
        if (this.files.length > 0) {
            handleFile(this.files[0]);
        }
    });

    // Provide visual feedback when a file is dragged over the dropzone.
    dropzone.on('dragover', function (e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.css('border-color', '#0073aa'); // Visual feedback
    });

    // Reset visual feedback when the dragged file leaves the dropzone.
    dropzone.on('dragleave', function (e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.css('border-color', '#ccc'); // Reset visual feedback
    });

    // Handle the file drop event.
    dropzone.on('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.css('border-color', '#ccc');

        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            handleFile(files[0]); // Process only the first file.
        }
    });

    /**
     * Core function to handle file processing and AJAX upload.
     * @param {File} file The file to be uploaded.
     */
    function handleFile(file) {
        // Display initial "uploading" message.
        feedbackDiv.html('<p>' + i18n.uploading_message + ' ' + escapeHtml(file.name) + '</p>');
        feedbackDiv.css('color', 'inherit');

        // Client-side validation (optional, but good for immediate feedback).
        // This is an *extra* check; server-side validation is the most important one.
        const fileName = file.name;
        const namePatternYymmdd = /^\d{6}/;
        const namePatternPdf = /\.pdf$/i;

        if (file.type !== "application/pdf" || !namePatternPdf.test(fileName)) {
            feedbackDiv.html('<p style="color:red;">' + i18n.error_prefix + ' ' + i18n.pdf_only_error + '</p>');
            return;
        }
        if (!namePatternYymmdd.test(fileName)) {
            feedbackDiv.html('<p style="color:red;">' + i18n.error_prefix + ' ' + i18n.yymmdd_error + '</p>');
            return;
        }

        // Create FormData to send the file.
        const formData = new FormData();
        formData.append('uploaded_file', file); // 'uploaded_file' must match the key in $_FILES on the server side.
        formData.append('action', uploader_params.action || 'handle_custom_upload'); // WordPress AJAX action.
        formData.append('nonce', uploader_params.nonce); // Nonce for security.

        // AJAX request with jQuery.
        $.ajax({
            url: uploader_params.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false, // Important: let jQuery not set the contentType.
            processData: false, // Important: let jQuery not process the data.
            success: function (response) {
                if (response.success) {
                    feedbackDiv.html('<p style="color:green;">' + escapeHtml(response.data.message) + '</p>');
                    if (response.data.file_url) {
                        feedbackDiv.append('<p>' + i18n.file_url_label + ' <a href="' + escapeHtml(response.data.file_url) + '" target="_blank">' + escapeHtml(response.data.file_url) + '</a></p>');
                    }
                } else {
                    const errorMessage = response.data.message || i18n.unknown_error;
                    feedbackDiv.html('<p style="color:red;">' + i18n.error_prefix + ' ' + escapeHtml(errorMessage) + '</p>');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                let errorMessage = i18n.ajax_error_prefix + ' ' + textStatus + ' - ' + errorThrown;
                if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    errorMessage = i18n.error_prefix + ' ' + escapeHtml(jqXHR.responseJSON.data.message);
                } else if (jqXHR.responseText) {
                    // Try to parse the response text if it wasn't JSON but contains text.
                    try {
                        const errResponse = JSON.parse(jqXHR.responseText);
                        if (errResponse.data && errResponse.data.message) {
                            errorMessage = i18n.error_prefix + ' ' + escapeHtml(errResponse.data.message);
                        }
                    } catch (e) {
                        // Do nothing if parsing fails, use the generic error.
                    }
                }
                feedbackDiv.html('<p style="color:red;">' + errorMessage + '</p>');
            },
            complete: function () {
                // Restore the file input so the same file can be selected again.
                fileInput.val('');
            }
        });
    }

    /**
     * Helper function to escape HTML for display.
     * @param {string} unsafe The potentially unsafe string.
     * @returns {string} The sanitized, safe string.
     */
    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') {
            return '';
        }
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});
