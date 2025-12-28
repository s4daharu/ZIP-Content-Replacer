/**
 * ZIP Content Replacer - Modern Admin JavaScript
 * Version: 3.1.2
 * 
 * Enhanced with drag-and-drop, log filtering, and mobile support
 */
jQuery(document).ready(function ($) {
    const form = $('#zip-replacer-form');
    const submitButton = $('#submit-zip-form');
    const mobileSubmitButton = $('#submit-zip-form-mobile');
    const processingArea = $('#zip-processing-area');
    const logContainer = $('#zip-process-log');
    const progressFill = $('#zip-progress-fill');
    const progressText = $('#zip-progress-text');
    const etaText = $('#zip-eta-text');
    const processingTitle = $('#processing-title');
    const exportLogBtn = $('#export-log-btn');
    const resumeBtn = $('#resume-processing-btn');
    const cancelResumeBtn = $('#cancel-resume-btn');
    const uploadZone = $('#upload-zone');
    const fileInput = $('#zip_file');
    const selectedFileName = $('#selected-file-name');
    const dryRunBadge = $('#dry-run-badge');

    let startTime = null;
    let processedItems = 0;

    // =============================================
    // DRAG & DROP FUNCTIONALITY
    // =============================================

    uploadZone.on('dragover dragenter', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('zcr-drag-over');
    });

    uploadZone.on('dragleave dragend drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('zcr-drag-over');
    });

    uploadZone.on('drop', function (e) {
        e.preventDefault();
        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0 && files[0].name.toLowerCase().endsWith('.zip')) {
            fileInput[0].files = files;
            handleFileSelection(files[0]);
        } else {
            alert('Please drop a valid ZIP file.');
        }
    });

    fileInput.on('change', function () {
        if (this.files.length > 0) {
            handleFileSelection(this.files[0]);
        }
    });

    function handleFileSelection(file) {
        uploadZone.addClass('zcr-has-file');
        selectedFileName.text(file.name + ' (' + formatFileSize(file.size) + ')');
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    // =============================================
    // RESUME SESSION HANDLING
    // =============================================

    if (resumeBtn.length > 0) {
        resumeBtn.on('click', function () {
            const originalHtml = $(this).html();
            $(this).prop('disabled', true).html('<span class="zcr-spinner"></span> Resuming...');

            $.ajax({
                url: zipReplacerAjax.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'resume_processing',
                    nonce: zipReplacerAjax.resumeNonce
                },
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        $('.zcr-notice').slideUp();
                        processingArea.addClass('zcr-active');
                        startTime = Date.now();
                        processedItems = response.data.offset;

                        addLogEntry('Resuming previous session...', 'info');
                        processBatch(response.data.offset, response.data.total);
                    } else {
                        alert('Failed to resume: ' + response.data.message);
                        resumeBtn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function () {
                    alert('Error attempting to resume session.');
                    resumeBtn.prop('disabled', false).html(originalHtml);
                }
            });
        });

        cancelResumeBtn.on('click', function () {
            $('.zcr-notice').slideUp();
        });
    }

    // =============================================
    // FORM SUBMISSION
    // =============================================

    // Mobile button triggers form submission
    mobileSubmitButton.on('click', function () {
        form.trigger('submit');
    });

    form.on('submit', function (e) {
        e.preventDefault();

        if (fileInput[0].files.length === 0) {
            alert('Please select a ZIP file to upload.');
            uploadZone.addClass('zcr-error');
            setTimeout(() => uploadZone.removeClass('zcr-error'), 500);
            return;
        }

        if ($('#fictioneer_story_id').val() === '') {
            alert('Please select a Fictioneer Story.');
            return;
        }

        const isDryRun = $('#dry_run').is(':checked');

        submitButton.prop('disabled', true).html('<span class="zcr-spinner"></span> Uploading...');
        mobileSubmitButton.prop('disabled', true).html('<span class="zcr-spinner"></span> Uploading...');

        processingArea.addClass('zcr-active');
        logContainer.html('');
        progressFill.css('width', '0%').removeClass('zcr-complete zcr-dry-run');
        progressText.text('Uploading and preparing file...');
        etaText.text('');
        exportLogBtn.hide();

        if (isDryRun) {
            dryRunBadge.show();
            progressFill.addClass('zcr-dry-run');
            processingTitle.html('<span class="zcr-spinner"></span> [DRY RUN] Processing...');
        } else {
            dryRunBadge.hide();
            processingTitle.html('<span class="zcr-spinner"></span> Processing...');
        }

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
                    addLogEntry(response.data.message, 'success');
                    processBatch(0, null);
                } else {
                    addLogEntry('Error: ' + response.data.message, 'error');
                    resetButtons();
                }
            },
            error: function (xhr, status, error) {
                addLogEntry('Upload Error: ' + error, 'error');
                resetButtons();
            }
        });
    });

    // =============================================
    // BATCH PROCESSING
    // =============================================

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
                    progressText.text(percentage + '% complete (' + processed + ' / ' + total + ' files processed)');

                    if (processed > 0 && remaining > 0) {
                        const elapsed = (Date.now() - startTime) / 1000;
                        const rate = processed / elapsed;
                        const remainingTime = remaining / rate;
                        const minutes = Math.floor(remainingTime / 60);
                        const seconds = Math.round(remainingTime % 60);

                        let etaDisplay = '⏱️ ';
                        if (minutes > 0) {
                            etaDisplay += minutes + 'm ' + seconds + 's remaining';
                        } else {
                            etaDisplay += seconds + 's remaining';
                        }
                        etaText.text(etaDisplay);
                    } else if (remaining === 0) {
                        etaText.text('');
                    }

                    // Parse and add log entries
                    if (data.logs && data.logs.length > 0) {
                        data.logs.forEach(function (log) {
                            const logType = getLogType(log);
                            addLogEntry(log, logType);
                        });
                    }

                    if (remaining > 0) {
                        processBatch(data.next_offset, total);
                    } else {
                        // Complete
                        progressFill.addClass('zcr-complete').removeClass('zcr-dry-run');
                        progressText.text('✅ Processing complete! 100% (' + total + ' files processed)');

                        if (isDryRun) {
                            processingTitle.html('✅ [DRY RUN COMPLETE]');
                        } else {
                            processingTitle.html('✅ Processing Complete!');
                        }

                        resetButtons();
                        exportLogBtn.show();
                        etaText.text('');
                    }
                } else {
                    addLogEntry('Error: ' + response.data.message, 'error');
                    resetButtons();
                    exportLogBtn.show();
                }
            },
            error: function (xhr, status, error) {
                addLogEntry('Processing Error: ' + error + '. You can try resuming if you reload the page.', 'error');
                resetButtons();
                exportLogBtn.show();
            }
        });
    }

    // =============================================
    // LOG CONSOLE FUNCTIONS
    // =============================================

    function addLogEntry(message, type) {
        // Strip HTML tags but preserve emoji indicators
        const cleanMessage = message.replace(/<[^>]*>/g, '');

        const entry = $('<div>')
            .addClass('zcr-log-entry')
            .addClass('zcr-' + type)
            .attr('data-type', type)
            .text(cleanMessage);

        logContainer.append(entry);
        logContainer.scrollTop(logContainer[0].scrollHeight);
    }

    function getLogType(logHtml) {
        if (logHtml.includes('SUCCESS') || logHtml.includes('✅')) return 'success';
        if (logHtml.includes('ERROR') || logHtml.includes('❌')) return 'error';
        if (logHtml.includes('WARNING') || logHtml.includes('⚠️')) return 'warning';
        if (logHtml.includes('DRY RUN') || logHtml.includes('🔍')) return 'info';
        if (logHtml.includes('Skipping') || logHtml.includes('⏭️') || logHtml.includes('INFO')) return 'skip';
        return 'info';
    }

    // Log filtering
    $('.zcr-console-filter').on('click', function () {
        const filter = $(this).data('filter');

        // Update active state
        $('.zcr-console-filter').removeClass('zcr-active');
        $(this).addClass('zcr-active');

        // Filter log entries
        const entries = logContainer.find('.zcr-log-entry');

        if (filter === 'all') {
            entries.removeClass('zcr-hidden');
        } else {
            entries.each(function () {
                const entryType = $(this).data('type');
                if (entryType === filter) {
                    $(this).removeClass('zcr-hidden');
                } else {
                    $(this).addClass('zcr-hidden');
                }
            });
        }
    });

    // =============================================
    // EXPORT LOG
    // =============================================

    exportLogBtn.on('click', function () {
        let logText = 'ZIP Content Replacer - Processing Report\n';
        logText += '========================================\n';
        logText += 'Generated: ' + new Date().toLocaleString() + '\n\n';

        logContainer.find('.zcr-log-entry').each(function () {
            logText += $(this).text() + '\n';
        });

        const timestamp = new Date().toISOString().replace(/:/g, '-').split('.')[0];
        const filename = 'zip-replacer-report-' + timestamp + '.txt';

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

    // =============================================
    // UTILITY FUNCTIONS
    // =============================================

    function resetButtons() {
        submitButton.prop('disabled', false).html('<span>🚀</span> Upload and Process');
        mobileSubmitButton.prop('disabled', false).html('<span>🚀</span> Upload and Process');
    }

    // Add smooth scroll behavior for log container
    logContainer.on('mouseenter', function () {
        $(this).css('scroll-behavior', 'auto');
    }).on('mouseleave', function () {
        $(this).css('scroll-behavior', 'smooth');
    });
});
