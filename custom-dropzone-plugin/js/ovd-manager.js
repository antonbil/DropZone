// js/inhoud-ovd.js
jQuery(document).ready(function ($) {
    // ---- NIEUWE, GENERIEKE SELECTORS ----
    const container = $('#file-manager-container');
    const form = $('#file-actions-form');
    const fileList = $('#file-list');
    const prepareBtn = $('#prepare-actions-btn');
    const confirmationArea = $('#confirmation-area');
    const actionsSummaryDiv = $('#actions-summary');
    const executeBtn = $('#execute-actions-btn');
    const cancelBtn = $('#cancel-actions-btn');
    const feedbackArea = $('#feedback-area');

    // Lokaliseer de parameters van WordPress. De naam 'file_manager_params' moet overeenkomen met wat in de PHP is ingesteld.
    // We gebruiken een check om te zorgen dat de code niet breekt als de params ontbreken.
    const wp_params = window.file_manager_params || {};

    if (!container.length) {
        return; // Doe niets als de container niet op de pagina is
    }

    // Toon/verberg hernoem-veld
    fileList.on('click', '.rename-toggle-btn', function () {
        const wrapper = $(this).siblings('.rename-field-wrapper');
        const inputField = wrapper.find('.new-name-input'); // Selecteer op klasse, ID's kunnen dupliceren

        wrapper.toggle();
        if (wrapper.is(':visible')) {
            inputField.focus();
            // Kopieer de bestandsnaam naar de input bij openen
            const currentFilename = $(this).closest('.file-item').data('filename');
            inputField.val(currentFilename);
        }
    });

    // Knop "Review Proposed Actions"
    prepareBtn.on('click', function () {
        feedbackArea.empty().hide();
        actionsSummaryDiv.empty();
        confirmationArea.hide();

        let actionsHtml = '<ul>';
        let hasActions = false;

        // Verzamel bestanden om te verwijderen
        form.find('input[name="delete_files[]"]:checked').each(function () {
            const filename = $(this).val();
            actionsHtml += '<li><strong>Delete:</strong> ' + escapeHtml(filename) + '</li>';
            hasActions = true;
        });

        // Verzamel bestanden om te hernoemen
        form.find('.new-name-input').each(function () {
            const originalFilename = $(this).closest('.file-item').data('filename');
            const newFilename = $(this).val().trim();
            if (newFilename !== '' && newFilename !== originalFilename) {
                actionsHtml += '<li><strong>Rename:</strong> "' + escapeHtml(originalFilename) + '" &rarr; "' + escapeHtml(newFilename) + '"</li>';
                hasActions = true;
            }
        });

        actionsHtml += '</ul>';

        if (hasActions) {
            actionsSummaryDiv.html(actionsHtml);
            confirmationArea.show();
        } else {
            feedbackArea.html('<p>No actions selected or changes specified.</p>').show();
        }
    });

    // Knop "Cancel" in het bevestigingsscherm
    cancelBtn.on('click', function () {
        confirmationArea.hide();
        actionsSummaryDiv.empty();
    });

    // Knop "Yes, Execute Actions"
    executeBtn.on('click', function () {
        $(this).prop('disabled', true).text('Processing...');
        cancelBtn.prop('disabled', true);
        feedbackArea.empty().hide();

        // Haal de juiste AJAX actie en nonce uit de gelokaliseerde parameters
        // Dit maakt de JS herbruikbaar voor verschillende slugs
        const ajax_action = wp_params.action || 'process_file_actions'; // Fallback naar een default
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
                let feedbackHtml = '<h4>Processing Result:</h4><ul>';
                let hasSuccessMessages = false;

                if (response.success && response.data) {
                    if (response.data.deleted && response.data.deleted.length > 0) {
                        response.data.deleted.forEach(function (file) {
                            feedbackHtml += '<li style="color:green;">Deleted: ' + escapeHtml(file) + '</li>';
                            hasSuccessMessages = true;
                        });
                    }
                    if (response.data.renamed && response.data.renamed.length > 0) {
                        response.data.renamed.forEach(function (item) {
                            feedbackHtml += '<li style="color:green;">Renamed: "' + escapeHtml(item.old) + '" &rarr; "' + escapeHtml(item.new) + '"</li>';
                            hasSuccessMessages = true;
                        });
                    }
                    if (response.data.errors && response.data.errors.length > 0) {
                        response.data.errors.forEach(function (error) {
                            feedbackHtml += '<li style="color:red;">Error: ' + escapeHtml(error) + '</li>';
                        });
                    }
                    if (response.data.message && !hasSuccessMessages && (!response.data.errors || response.data.errors.length === 0)) {
                         feedbackHtml += '<li>' + escapeHtml(response.data.message) + '</li>';
                    }

                } else {
                    feedbackHtml += '<li style="color:red;">Error: ' + escapeHtml((response.data && response.data.message) || 'Unknown server error or invalid response.') + '</li>';
                }
                feedbackHtml += '</ul>';

                if (hasSuccessMessages) {
                    feedbackHtml += '<p><strong>The page will reload in 5 seconds to reflect the changes.</strong></p>';
                    setTimeout(function () {
                        location.reload();
                    }, 5000);
                }

                feedbackArea.html(feedbackHtml).show();
                confirmationArea.hide();
            },
            error: function (jqXHR) {
                let errorMessage = 'An unexpected AJAX error occurred. Check the browser console for more details.';
                 if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    errorMessage = 'Server Error: ' + escapeHtml(jqXHR.responseJSON.data.message);
                }
                feedbackArea.html('<p style="color:red;">' + errorMessage + '</p>').show();
                confirmationArea.hide();
            },
            complete: function () {
                executeBtn.prop('disabled', false).text('Yes, Execute Actions');
                cancelBtn.prop('disabled', false);
            }
        });
    });

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
