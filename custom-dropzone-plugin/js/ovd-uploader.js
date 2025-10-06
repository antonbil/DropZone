// js/custom-uploader.js
jQuery(document).ready(function ($) {
    const dropzone = $('#custom-dropzone');
    const fileInput = $('#custom-file-input');
    const browseButton = $('#custom-browse-button');
    const feedbackDiv = $('#upload-feedback');

    if (!dropzone.length) {
        return; // Doe niets als de dropzone niet op de pagina is
    }

    browseButton.on('click', function () {
        fileInput.click(); // Open de bestandsdialog wanneer op de knop wordt geklikt
    });

    fileInput.on('change', function (e) {
        if (this.files.length > 0) {
            handleFile(this.files[0]);
        }
    });

    dropzone.on('dragover', function (e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.css('border-color', '#0073aa'); // Visuele feedback
    });

    dropzone.on('dragleave', function (e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.css('border-color', '#ccc'); // Reset visuele feedback
    });

    dropzone.on('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.css('border-color', '#ccc');

        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            handleFile(files[0]); // Verwerk alleen het eerste bestand
        }
    });

    function handleFile(file) {
        feedbackDiv.html('<p>Bezig met uploaden: ' + escapeHtml(file.name) + '</p>');
        feedbackDiv.css('color', 'inherit');


        // Client-side validatie (optioneel, maar goed voor directe feedback)
        // Dit is een *extra* check; de server-side validatie is de belangrijkste.
        const fileName = file.name;
        const namePatternYymmdd = /^\d{6}/;
        const namePatternOvd = /ovd/i;
        const namePatternPdf = /\.pdf$/i;

        if (file.type !== "application/pdf" || !namePatternPdf.test(fileName)) {
            feedbackDiv.html('<p style="color:red;">Fout: Alleen PDF-bestanden zijn toegestaan en de naam moet eindigen op .pdf.</p>');
            return;
        }
        if (!namePatternYymmdd.test(fileName)) {
            feedbackDiv.html('<p style="color:red;">Fout: Bestandsnaam moet beginnen met 6 cijfers (yymmdd).</p>');
            return;
        }
        //if (!namePatternOvd.test(fileName)) {
        //    feedbackDiv.html('<p style="color:red;">Fout: Bestandsnaam moet "ovd" bevatten.</p>');
        //    return;
        //}

        // Maak FormData aan om het bestand te versturen
        const formData = new FormData();
        formData.append('uploaded_file', file); // 'uploaded_file' moet overeenkomen met de key in $_FILES aan de serverkant
        formData.append('action', 'handle_custom_upload'); // WordPress AJAX actie
        formData.append('nonce', custom_uploader_params.nonce); // Nonce voor beveiliging

        // AJAX request met jQuery
        $.ajax({
            url: custom_uploader_params.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false, // Belangrijk: laat jQuery de contentType niet instellen
            processData: false, // Belangrijk: laat jQuery de data niet verwerken
            success: function (response) {
                if (response.success) {
                    feedbackDiv.html('<p style="color:green;">' + escapeHtml(response.data.message) + '</p>');
                    if (response.data.file_url) {
                        feedbackDiv.append('<p>Bestand URL: <a href="' + escapeHtml(response.data.file_url) + '" target="_blank">' + escapeHtml(response.data.file_url) + '</a></p>');
                    }
                } else {
                    feedbackDiv.html('<p style="color:red;">Fout: ' + escapeHtml(response.data.message || 'Onbekende fout opgetreden.') + '</p>');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                let errorMessage = 'AJAX Fout: ' + textStatus + ' - ' + errorThrown;
                if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    errorMessage = 'Fout: ' + escapeHtml(jqXHR.responseJSON.data.message);
                } else if (jqXHR.responseText) {
                    // Probeer de response tekst te parsen als het geen JSON was maar wel tekst bevat
                    try {
                        const errResponse = JSON.parse(jqXHR.responseText);
                        if (errResponse.data && errResponse.data.message) {
                            errorMessage = 'Fout: ' + escapeHtml(errResponse.data.message);
                        }
                    } catch (e) {
                        // Doe niets als parsen mislukt, gebruik de algemene error
                    }
                }
                feedbackDiv.html('<p style="color:red;">' + errorMessage + '</p>');
            },
            complete: function () {
                // Herstel de file input zodat dezelfde file opnieuw geselecteerd kan worden
                fileInput.val('');
            }
        });
    }

    // Helper functie om HTML te escapen voor weergave
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
