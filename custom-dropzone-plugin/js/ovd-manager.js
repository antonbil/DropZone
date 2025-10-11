// js/plugin-manager.js

jQuery(document).ready(function ($) {
    'use strict';

    // --- NEW, GENERIC SELECTORS ---
    const container = $('#file-manager-container');
    const form = $('#file-actions-form');
    const fileList = $('#file-list');
    const prepareBtn = $('#prepare-actions-btn');
    const confirmationArea = $('#confirmation-area');
    const actionsSummaryDiv = $('#actions-summary');
    const executeBtn = $('#execute-actions-btn');
    const cancelBtn = $('#cancel-actions-btn');
    const feedbackArea = $('#feedback-area');

    // Localize the parameters from WordPress. The name 'file_manager_params' must match what is set in PHP.
    // We use a check to ensure the code doesn't break if the params are missing.
    const wp_params = window.file_manager_params || {};

    // **NEW**: Localize the internationalization (i18n) strings from WordPress.
    const i18n = window.manager_i18n_params || {};

    // Do nothing if the main container is not on the page.
    if (!container.length) {
        return;
    }

    // Toggle the display of the rename field.
    fileList.on('click', '.rename-toggle-btn', function () {
        const wrapper = $(this).siblings('.rename-field-wrapper');
        const inputField = wrapper.find('.new-name-input'); // Select by class, as IDs can duplicate.

        wrapper.toggle();
        if (wrapper.is(':visible')) {
            inputField.focus();
            // Copy the current filename to the input when it's opened.
            const currentFilename = $(this).closest('.file-item').data('filename');
            inputField.val(currentFilename);
        }
    });

    // "Review Proposed Actions" button handler.
    prepareBtn.on('click', function () {
        feedbackArea.empty().hide();
        actionsSummaryDiv.empty();
        confirmationArea.hide();

        let actionsHtml = '<ul>';
        let hasActions = false;

        // Collect files marked for deletion.
        form.find('input[name="delete_files[]"]:checked').each(function () {
            const filename = $(this).val();
            actionsHtml += '<li><strong>' + i18n.delete_label + '</strong> ' + escapeHtml(filename) + '</li>';
            hasActions = true;
        });

        // Collect files to be renamed.
        form.find('.new-name-input').each(function () {
            const originalFilename = $(this).closest('.file-item').data('filename');
            const newFilename = $(this).val().trim();
            if (newFilename !== '' && newFilename !== originalFilename) {
                actionsHtml += '<li><strong>' + "Rename" + '</strong> "' + escapeHtml(originalFilename) + '" &rarr; "' + escapeHtml(newFilename) + '"</li>';
                hasActions = true;
            }
        });
        actionsHtml += '</ul>';

        if (hasActions) {
            actionsSummaryDiv.html(actionsHtml);
            confirmationArea.show();
        } else {
            feedbackArea.html('<p>' + "No actions selected" + '</p>').show();
        }
    });

    // "Cancel" button in the confirmation screen.
    cancelBtn.on('click', function () {
        confirmationArea.hide();
        actionsSummaryDiv.empty();
    });

    // "Yes, Execute Actions" button handler.
    executeBtn.on('click', function () {
        // Disable buttons to prevent multiple clicks and provide feedback.
        $(this).prop('disabled', true).text("Processing");
        cancelBtn.prop('disabled', true);
        feedbackArea.empty().hide();

        // Get the correct AJAX action and nonce from the localized parameters.
        // This makes the JavaScript reusable for different slugs.
        const ajax_action = wp_params.action || 'process_file_actions'; // Fallback to a default.
        const nonce = wp_params.nonce || '';

        const dataToSend = {
            action: ajax_action,
            nonce: nonce,
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
                let feedbackHtml = '<h4>' + "Processing result" + '</h4><ul>';
                let hasSuccessMessages = false;

                if (response.success && response.data) {
                    if (response.data.deleted && response.data.deleted.length > 0) {
                        response.data.deleted.forEach(function (file) {
                            feedbackHtml += '<li style="color:green;">' + "Deleted" + ' ' + escapeHtml(file) + '</li>';
                            hasSuccessMessages = true;
                        });
                    }
                    if (response.data.renamed && response.data.renamed.length > 0) {
                        response.data.renamed.forEach(function (item) {
                            feedbackHtml += '<li style="color:green;">' + "Renamed" + ' "' + escapeHtml(item.old) + '" &rarr; "' + escapeHtml(item.new) + '"</li>';
                            hasSuccessMessages = true;
                        });
                    }
                    if (response.data.errors && response.data.errors.length > 0) {
                        response.data.errors.forEach(function (error) {
                            feedbackHtml += '<li style="color:red;">' + "Error" + ' ' + escapeHtml(error) + '</li>';
                        });
                    }
                    if (response.data.message && !hasSuccessMessages && (!response.data.errors || response.data.errors.length === 0)) {
                         feedbackHtml += '<li>' + escapeHtml(response.data.message) + '</li>';
                    }

                } else {
                    const errorMessage = (response.data && response.data.message) || i18n.unknown_server_error;
                    feedbackHtml += '<li style="color:red;">' + "error feedback" + ' ' + escapeHtml(errorMessage) + '</li>';
                }
                feedbackHtml += '</ul>';

                if (hasSuccessMessages) {
                    feedbackHtml += '<p><strong>' + "Reload" + '</strong></p>';
                    setTimeout(function () {
                        location.reload();
                    }, 5000);
                }

                feedbackArea.html(feedbackHtml).show();
                confirmationArea.hide();
            },
            error: function (jqXHR) {
                let errorMessage = "Ajax error message";
                 if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    errorMessage = "server error prefix" + ' ' + escapeHtml(jqXHR.responseJSON.data.message);
                }
                feedbackArea.html('<p style="color:red;">' + errorMessage + '</p>').show();
                confirmationArea.hide();
            },
            complete: function () {
                // Re-enable buttons after the process is finished.
                executeBtn.prop('disabled', false).text("Execute");
                cancelBtn.prop('disabled', false);
            }
        });
    });

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
});
