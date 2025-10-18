jQuery(document).ready(function ($) {
    const form = $('#zip-replacer-form');
    const submitButton = $('#submit-zip-form');
    const processingArea = $('#zip-processing-area');
    const logContainer = $('#zip-process-log');
    const progressFill = $('#zip-progress-fill');
    const progressText = $('#zip-progress-text');
    const etaText = $('#zip-eta-text');
    const processingTitle = $('#processing-title');
    const exportLogBtn = $('#export-log-btn');
    const resumeBtn = $('#resume-processing-btn');
    const cancelResumeBtn = $('#cancel-resume-btn');

    let startTime = null;
    let processedItems = 0;

    if (resumeBtn.length > 0) {
        resumeBtn.on('click', function() {
            $.ajax({
                url: zipReplacerAjax.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'resume_processing',
                    nonce: zipReplacerAjax.resumeNonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('.notice').hide();
                        processingArea.show();
                        startTime = Date.now();
                        processedItems = response.data.offset;
                        
                        logContainer.html('<span style="color: blue;">Resuming previous session...</span>\n');
                        
                        processBatch(response.data.offset, response.data.total);
                    } else {
                        alert('Failed to resume: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('Error attempting to resume session.');
                }
            });
        });

        cancelResumeBtn.on('click', function() {
            $('.notice').hide();
        });
    }

    form.on('submit', function (e) {
        e.preventDefault();

        if ($('#zip_file')[0].files.length === 0) {
            alert('Please select a ZIP file to upload.');
            return;
        }

        if ($('#fictioneer_story_id').val() === '') {
            alert('Please select a Fictioneer Story.');
            return;
        }

        submitButton.prop('disabled', true).val('Uploading...');
        processingArea.show();
        logContainer.html('');
        progressFill.css('width', '0%');
        progressText.text('Uploading and preparing file...');
        etaText.text('');
        exportLogBtn.hide();

        const formData = new FormData(this);
        formData.append('action', 'handle_zip_upload_ajax');
        formData.append('nonce', zipReplacerAjax.uploadNonce);

        startTime = Date.now();
        processedItems = 0;

        $.ajax({
            url: zipReplacerAjax.ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    logContainer.append($('<div>').html('<span style="color: green;">✅ ' + response.data.message + '</span>'));
                    logContainer.append($('<div>').html(''));

                    processBatch(0, null);
                } else {
                    logContainer.append($('<div>').html('<span style="color: red;">❌ Error: ' + response.data.message + '</span>'));
                    submitButton.prop('disabled', false).val('Upload and Process');
                }
            },
            error: function (xhr, status, error) {
                logContainer.append($('<div>').html('<span style="color: red;">❌ Upload Error: ' + error + '</span>'));
                submitButton.prop('disabled', false).val('Upload and Process');
            }
        });
    });

    function processBatch(offset, totalFiles) {
        $.ajax({
            url: zipReplacerAjax.ajaxUrl,
            method: 'POST',
            data: {
                action: 'process_zip_batch',
                nonce: zipReplacerAjax.processNonce,
                offset: offset
            },
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    const data = response.data;
                    const processed = data.processed;
                    const remaining = data.remaining;
                    const total = data.total;
                    const isDryRun = data.is_dry_run;

                    processedItems = processed;

                    const percentage = total > 0 ? Math.round((processed / total) * 100) : 0;
                    progressFill.css('width', percentage + '%');
                    progressText.text(percentage + '% complete (' + processed + ' / ' + total + ' files processed, ' + remaining + ' remaining)');

                    if (processed > 0 && remaining > 0) {
                        const elapsed = (Date.now() - startTime) / 1000;
                        const rate = processed / elapsed;
                        const remainingTime = remaining / rate;
                        const minutes = Math.floor(remainingTime / 60);
                        const seconds = Math.round(remainingTime % 60);
                        
                        let etaDisplay = 'Estimated time remaining: ';
                        if (minutes > 0) {
                            etaDisplay += minutes + 'm ' + seconds + 's';
                        } else {
                            etaDisplay += seconds + 's';
                        }
                        etaText.text(etaDisplay);
                    } else if (remaining === 0) {
                        etaText.text('');
                    }

                    if (isDryRun) {
                        processingTitle.text('[DRY RUN MODE] Processing...');
                        progressFill.css('background', '#f0ad4e');
                    }

                    if (data.logs && data.logs.length > 0) {
                        data.logs.forEach(function (log) {
                            logContainer.append($('<div>').html(log));
                        });
                        logContainer.scrollTop(logContainer[0].scrollHeight);
                    }

                    if (remaining > 0) {
                        processBatch(data.next_offset, total);
                    } else {
                        progressText.text('✅ Processing complete! 100% (' + total + ' files processed)');
                        processingTitle.text(isDryRun ? '[DRY RUN COMPLETE]' : 'Processing Complete!');
                        submitButton.prop('disabled', false).val('Upload and Process');
                        exportLogBtn.show();
                        etaText.text('');
                    }
                } else {
                    logContainer.append($('<div>').html('<span style="color: red;">❌ Error: ' + response.data.message + '</span>'));
                    submitButton.prop('disabled', false).val('Upload and Process');
                    exportLogBtn.show();
                }
            },
            error: function (xhr, status, error) {
                logContainer.append($('<div>').html('<span style="color: red;">❌ Processing Error: ' + error + '. You can try resuming if you reload the page.</span>'));
                submitButton.prop('disabled', false).val('Upload and Process');
                exportLogBtn.show();
            }
        });
    }

    exportLogBtn.on('click', function() {
        const logText = logContainer.text();
        const timestamp = new Date().toISOString().replace(/:/g, '-').split('.')[0];
        const filename = 'zip-replacer-log-' + timestamp + '.txt';
        
        const blob = new Blob([logText], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
});
