(function ($) {
    'use strict';

    // Global flag to ensure the script runs only once.
    if (window.OVD_MANAGER_LOADED) {
        console.warn("OVD Manager script was loaded twice. Execution halted.");
        return; // Stop the execution of the entire IIFE
    }

    // Set the flag after the check
    window.OVD_MANAGER_LOADED = true;

    let debugging = false;

    /**
     * Helper function to escape HTML characters for safe display.
     * @param {string} unsafe The potentially unsafe string.
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

    // --- Plugin Manager ---
    function initializeManager() {
        if (debugging)  console.log("start");
        const container = $('#file-manager-container');
        if (!container.length) return;

        const form = $('#file-actions-form');
        const fileList = $('#file-list');
        const prepareBtn = $('#prepare-actions-btn');
        const confirmationArea = $('#confirmation-area');
        const actionsSummaryDiv = $('#actions-summary');
        const executeBtn = $('#execute-actions-btn');
        const cancelBtn = $('#cancel-actions-btn');
        const feedbackArea = $('#feedback-area');

        const wp_params = window.file_manager_params || {};
        const i18n = window.manager_i18n_params || {};

        fileList.on('click', '.rename-toggle-btn', function () {
            if (debugging)  console.log('Click rename.');
            const wrapper = $(this).siblings('.rename-field-wrapper');
            const inputField = wrapper.find('.new-name-input');
            wrapper.toggle();
            if (debugging)  console.log('Click rename afer toggle.', wrapper);
            if (wrapper.is(':visible')) {
                inputField.focus();
                const currentFilename = $(this).closest('.file-item').data('filename');
                inputField.val(currentFilename);
                if (debugging)  console.log('Rename.',inputField);
            }
        });

        prepareBtn.on('click', function () {
            feedbackArea.empty().hide();
            actionsSummaryDiv.empty();
            confirmationArea.hide();

            let actionsHtml = '<ul>';
            let hasActions = false;

            form.find('input[name="delete_files[]"]:checked').each(function () {
                const filename = $(this).val();
                actionsHtml += '<li><strong>' + (i18n.delete_label || 'Delete') + '</strong> ' + escapeHtml(filename) + '</li>';
                hasActions = true;
            });

            form.find('.new-name-input').each(function () {
                const originalFilename = $(this).closest('.file-item').data('filename');
                const newFilename = $(this).val().trim();
                if (newFilename !== '' && newFilename !== originalFilename) {
                    actionsHtml += '<li><strong>' + (i18n.rename_label || 'Rename') + '</strong> "' + escapeHtml(originalFilename) + '" &rarr; "' + escapeHtml(newFilename) + '"</li>';
                    hasActions = true;
                }
            });
            actionsHtml += '</ul>';

            if (hasActions) {
                actionsSummaryDiv.html(actionsHtml);
                confirmationArea.show();
            } else {
                feedbackArea.html('<p>' + (i18n.no_actions_selected || 'No actions selected') + '</p>').show();
            }
        });

        cancelBtn.on('click', function () {
            confirmationArea.hide();
            actionsSummaryDiv.empty();
        });

        executeBtn.on('click', function () {
            $(this).prop('disabled', true).text(i18n.processing_text || 'Processing...');
            cancelBtn.prop('disabled', true);
            feedbackArea.empty().hide();

            const dataToSend = {
                action: wp_params.action || 'process_file_actions',
                nonce: wp_params.nonce || '',
                delete_files: [],
                rename_files: {}
            };

            form.find('input[name="delete_files[]"]:checked').each(function () {
                dataToSend.delete_files.push($(this).val());
            });

            form.find('.new-name-input').each(function () {
                const originalFilename = $(this).closest('.file-item').data('filename');
                const newFilename = $(this).val().trim();
                if (newFilename !== '' && newFilename !== originalFilename) {
                    dataToSend.rename_files[originalFilename] = newFilename;
                }
            });

            $.ajax({
                url: wp_params.ajax_url,
                type: 'POST',
                data: dataToSend,
                dataType: 'json',
                success: function (response) {
                    let feedbackHtml = '<h4>' + (i18n.processing_result_title || 'Processing result') + '</h4><ul>';
                    let hasSuccessMessages = false;

                    if (response.success && response.data) {
                        if (response.data.deleted && response.data.deleted.length > 0) {
                            response.data.deleted.forEach(function (file) {
                                feedbackHtml += '<li style="color:green;">' + (i18n.deleted_feedback || 'Deleted') + ' ' + escapeHtml(file) + '</li>';
                                hasSuccessMessages = true;
                            });
                        }
                        if (response.data.renamed && response.data.renamed.length > 0) {
                            response.data.renamed.forEach(function (item) {
                                feedbackHtml += '<li style="color:green;">' + (i18n.renamed_feedback || 'Renamed') + ' "' + escapeHtml(item.old) + '" &rarr; "' + escapeHtml(item.new) + '"</li>';
                                hasSuccessMessages = true;
                            });
                        }
                        if (response.data.errors && response.data.errors.length > 0) {
                            response.data.errors.forEach(function (error) {
                                feedbackHtml += '<li style="color:red;">' + (i18n.error_feedback || 'Error') + ' ' + escapeHtml(error) + '</li>';
                            });
                        }
                        if (response.data.message && !hasSuccessMessages && (!response.data.errors || response.data.errors.length === 0)) {
                            feedbackHtml += '<li>' + escapeHtml(response.data.message) + '</li>';
                        }
                    } else {
                        const errorMessage = (response.data && response.data.message) || i18n.unknown_server_error;
                        feedbackHtml += '<li style="color:red;">' + (i18n.error_feedback || 'Error') + ' ' + escapeHtml(errorMessage) + '</li>';
                    }
                    feedbackHtml += '</ul>';

                    if (hasSuccessMessages) {
                        feedbackHtml += '<p><strong>' + (i18n.reload_message || 'Reloading...') + '</strong></p>';
                        setTimeout(function () {
                            location.reload();
                        }, 5000);
                    }

                    feedbackArea.html(feedbackHtml).show();
                    confirmationArea.hide();
                },
                error: function (jqXHR) {
                    if (debugging)  console.log("Manager AJAX Error:", jqXHR);
                    let errorMessage = i18n.ajax_error_message || 'Ajax error';
                    if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                        errorMessage = (i18n.server_error_prefix || 'Server error') + ' ' + escapeHtml(jqXHR.responseJSON.data.message);
                    }
                    feedbackArea.html('<p style="color:red;">' + errorMessage + '</p>').show();
                    confirmationArea.hide();
                },
                complete: function () {
                    executeBtn.prop('disabled', false).text(i18n.execute_button_text || 'Execute');
                    cancelBtn.prop('disabled', false);
                }
            });
        });
    }

    // --- Custom Uploader ---
    function initializeUploader() {
        if (debugging)  console.log('Initializing uploader...');
        const dropzone = $('#custom-dropzone');
        if (!dropzone.length) {
            if (debugging)  console.log('Uploader dropzone not found.');
            return;
        }
        if (debugging)  console.log('Uploader dropzone found.');

        const fileInput = $('#custom-file-input');
        const browseButton = $('#custom-browse-button');
        const feedbackDiv = $('#upload-feedback');
        if (debugging)  console.log('Uploader elements:', { fileInput, browseButton, feedbackDiv });

        const uploader_params = window.custom_uploader_params || {};
        const i18n = window.uploader_i18n_params || {};
        if (debugging)  console.log('Uploader params:', uploader_params);
        if (debugging)  console.log('Uploader i18n:', i18n);

        browseButton.on('click', function () {
            if (debugging)  console.log('Browse button clicked.');
            fileInput.click();
        });

        fileInput.on('change', function () {
            if (debugging)  console.log('File input changed.');
            if (this.files.length > 0) {
                if (debugging)  console.log('File selected:', this.files[0]);
                handleFile(this.files[0]);
            }
        });

        dropzone.on('dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.css('border-color', '#0073aa');
        });

        dropzone.on('dragleave', function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.css('border-color', '#ccc');
        });

        dropzone.on('drop', function (e) {
            if (debugging)  console.log('File dropped.');
            e.preventDefault();
            e.stopPropagation();
            dropzone.css('border-color', '#ccc');
            const files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                if (debugging)  console.log('Dropped file:', files[0]);
                handleFile(files[0]);
            }
        });

        function handleFile(file) {
            if (debugging)  console.log('handleFile called with file:', file);
            feedbackDiv.html('<p>' + (i18n.uploading_message || 'Uploading:') + ' ' + escapeHtml(file.name) + '</p>').css('color', 'inherit');

            const fileName = file.name;
            const namePatternYymmdd = /^\d{6}/;
            const namePatternPdf = /\.pdf$/i;

            if (debugging)  console.log('Validating file:', fileName);
            if (file.type !== "application/pdf" || !namePatternPdf.test(fileName)) {
                if (debugging)  console.log('Validation failed: not a PDF.');
                feedbackDiv.html('<p style="color:red;">' + (i18n.error_prefix || 'Error:') + ' ' + (i18n.pdf_only_error || 'PDF only') + '</p>');
                return;
            }
            if (!namePatternYymmdd.test(fileName)) {
                if (debugging)  console.log('Validation failed: does not start with yymmdd.');
                feedbackDiv.html('<p style="color:red;">' + (i18n.error_prefix || 'Error:') + ' ' + (i18n.yymmdd_error || 'Filename must start with yymmdd') + '</p>');
                return;
            }
            if (debugging)  console.log('File validation passed.');

            const formData = new FormData();
            formData.append('uploaded_file', file);
            formData.append('action', uploader_params.action || 'handle_custom_upload');
            formData.append('nonce', uploader_params.nonce);
            if (debugging)  console.log('FormData created:', {
                action: uploader_params.action || 'handle_custom_upload',
                nonce: uploader_params.nonce
            });

            if (debugging)  console.log('Starting AJAX request...');
            $.ajax({
                url: uploader_params.ajax_url,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function (response) {
                    if (debugging)  console.log('AJAX success:', response);
                    if (response.success) {
                        feedbackDiv.html('<p style="color:green;">' + escapeHtml(response.data.message) + '</p>');
                        if (response.data.file_url) {
                            feedbackDiv.append('<p>' + (i18n.file_url_label || 'File URL:') + ' <a href="' + escapeHtml(response.data.file_url) + '" target="_blank">' + escapeHtml(response.data.file_url) + '</a></p>');
                        }
                        feedbackDiv.append( '<p><strong>' + (i18n.reload_message || 'Reloading...') + '</strong></p>');
                        setTimeout(function () {
                            location.reload();
                        }, 5000);
                    } else {
                        const errorMessage = response.data.message || i18n.unknown_error;
                        feedbackDiv.html('<p style="color:red;">' + (i18n.error_prefix || 'Error:') + ' ' + escapeHtml(errorMessage) + '</p>');
                    }
                },
                error: function (jqXHR) {
                    if (debugging)  console.log("Uploader AJAX Error:", jqXHR);
                    let errorMessage = (i18n.ajax_error_prefix || 'AJAX Error:') + ' ' + jqXHR.statusText;
                     if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                        errorMessage = (i18n.error_prefix || 'Error:') + ' ' + escapeHtml(jqXHR.responseJSON.data.message);
                    }
                    feedbackDiv.html('<p style="color:red;">' + errorMessage + '</p>');
                },
                complete: function () {
                    if (debugging)  console.log('AJAX request complete.');
                    fileInput.val('');
                }
            });
        }
    }

    // Initialize both components on document ready
    $(document).ready(function () {
        initializeManager();
        initializeUploader();
    });

})(jQuery);
