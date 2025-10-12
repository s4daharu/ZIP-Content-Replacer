jQuery(document).ready(function ($) {
    const form = $('#zip-replacer-form');
    const submitButton = $('#submit-zip-form');
    const processingArea = $('#zip-processing-area');
    const logContainer = $('#zip-process-log');
    const progressFill = $('#zip-progress-fill');
    const progressText = $('#zip-progress-text');
    const processingTitle = $('#processing-title');

    form.on('submit', function (e) {
        e.preventDefault();

        // Basic validation
        if ($('#zip_file')[0].files.length === 0) {
            alert('Please select a ZIP file to upload.');
            return;
        }

        submitButton.prop('disabled', true).val('Uploading...');
        processingArea.show();
        logContainer.html(''); // Clear previous logs
        progressFill.css('width', '0%');
        progressText.text('Uploading and preparing file...');

        const formData = new FormData(this);
        formData.append('action', 'handle_zip_upload_ajax');
        formData.append('nonce', zipReplacerAjax.uploadNonce);

        // 1. Initial AJAX call to upload the file and set up transients
        $.ajax({
            url: zipReplacerAjax.ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    logContainer.append('<div>' + response.data.message + '</div>');
                    startProcessing(0); // 2. Start the batch processing
                } else {
                    handleFatalError('Upload Error: ' + response.data.message);
                }
            },
            error: function (xhr, status, error) {
                handleFatalError('Upload AJAX Error: ' + error);
            }
        });
    });

    function startProcessing(offset, retryCount = 0) {
        $.ajax({
            url: zipReplacerAjax.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'process_zip_batch',
                nonce: zipReplacerAjax.processNonce,
                offset: offset
            },
            success: function (response) {
                if (response.success) {
                    let total = response.data.total;
                    let processed = response.data.processed;
                    let percent = total > 0 ? Math.floor((processed / total) * 100) : 100;
                    
                    if (response.data.is_dry_run) {
                        processingTitle.text('Processing (Dry Run)...');
                        progressFill.css('background-color', '#337ab7'); // Blue for dry run
                    } else {
                        processingTitle.text('Processing (Live)...');
                        progressFill.css('background-color', '#46b450'); // Green for live
                    }

                    progressFill.css('width', percent + '%');
                    progressText.text('Processed ' + processed + ' of ' + total + ' files...');

                    // Append logs to the container
                    if (response.data.logs && response.data.logs.length > 0) {
                        response.data.logs.forEach(function(log) {
                            logContainer.append('<div>' + log.replace(/</g, "<").replace(/>/g, ">") + '</div>');
                        });
                        logContainer.scrollTop(logContainer[0].scrollHeight); // Auto-scroll
                    }

                    if (response.data.remaining > 0) {
                        startProcessing(response.data.next_offset);
                    } else {
                        let finalMessage = response.data.is_dry_run ? 'Dry run complete!' : 'Processing complete!';
                        progressText.text(finalMessage);
                        processingTitle.text('Finished!');
                        submitButton.prop('disabled', false).val('Upload and Process');
                    }
                } else {
                    handleFatalError('Error: ' + response.data.message);
                }
            },
            error: function (xhr, status, error) {
                if (retryCount < 3) {
                    progressText.text(`Network Error. Retrying in 5s... (Attempt ${retryCount + 1}/3)`);
                    setTimeout(function() {
                        startProcessing(offset, retryCount + 1);
                    }, 5000);
                } else {
                    handleFatalError('AJAX Error after 3 retries: ' + error);
                }
            }
        });
    }
    
    function handleFatalError(message) {
        progressText.text(message).css('color', 'red');
        logContainer.append('<div style="color:red; font-weight:bold;">' + message.replace(/</g, "<").replace(/>/g, ">") + '</div>');
        submitButton.prop('disabled', false).val('Try Again');
    }
});