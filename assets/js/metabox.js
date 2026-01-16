/**
 * AI JSON-LD Generator - Metabox JS
 */

(function($) {
    'use strict';

    var cooldownTimer = null;
    var cooldownRemaining = 0;
    var progressTimer = null;
    var progressStep = 0;

    /**
     * Progress steps for visual feedback (while waiting for API)
     */
    var progressSteps = [
        { text: 'preparing', delay: 0 },
        { text: 'sending', delay: 1500 },
        { text: 'waiting', delay: 4000 }
    ];

    /**
     * Initialize metabox functionality
     */
    function init() {
        bindGenerateButton();
        bindCopyButton();
        bindValidateButton();
        bindDiagnosticsButton();
        bindVerifyFrontendButton();
    }

    /**
     * Bind generate button click
     */
    function bindGenerateButton() {
        $('#wp_ai_schema_generate').on('click', function(e) {
            e.preventDefault();
            generateSchema();
        });
    }

    /**
     * Start progress indicator
     */
    function startProgress() {
        var $button = $('#wp_ai_schema_generate');
        progressStep = 0;

        // Clear any existing timer
        if (progressTimer) {
            clearTimeout(progressTimer);
        }

        // Show first step immediately
        updateProgressText();

        // Schedule subsequent steps
        scheduleNextStep();
    }

    /**
     * Schedule the next progress step
     */
    function scheduleNextStep() {
        if (progressStep >= progressSteps.length - 1) {
            return; // Stay on last step until complete
        }

        var nextStep = progressSteps[progressStep + 1];
        var currentStep = progressSteps[progressStep];
        var delay = nextStep.delay - currentStep.delay;

        progressTimer = setTimeout(function() {
            progressStep++;
            updateProgressText();
            scheduleNextStep();
        }, delay);
    }

    /**
     * Update button text based on current progress step
     */
    function updateProgressText() {
        var $button = $('#wp_ai_schema_generate');
        var step = progressSteps[progressStep];
        var text = wpAiSchemaMetabox.i18n[step.text] || step.text;
        $button.text(text);
    }

    /**
     * Stop progress indicator
     */
    function stopProgress() {
        if (progressTimer) {
            clearTimeout(progressTimer);
            progressTimer = null;
        }
        progressStep = 0;
    }

    /**
     * Generate schema via AJAX
     */
    function generateSchema() {
        var $button = $('#wp_ai_schema_generate');
        var $spinner = $('.ai-jsonld-spinner');
        var $preview = $('#wp_ai_schema_schema_preview');
        var $message = $('#wp_ai_schema_message');
        var $status = $('.ai-jsonld-status');

        var typeHint = $('#wp_ai_schema_type_hint').val();
        var forceRegenerate = $('#wp_ai_schema_force_regenerate').is(':checked');
        var fetchFrontend = $('#wp_ai_schema_fetch_frontend').is(':checked');

        // Disable button and show loading state
        $button.prop('disabled', true).addClass('generating');
        $spinner.addClass('is-active');
        $message.removeClass('success error info').addClass('hidden');

        // Start progress indicator
        startProgress();

        // Make AJAX request
        $.ajax({
            url: wpAiSchemaMetabox.ajax_url,
            type: 'POST',
            timeout: 150000, // 150 second timeout (2.5 minutes)
            data: {
                action: 'wp_ai_schema_generate',
                nonce: wpAiSchemaMetabox.nonce,
                post_id: wpAiSchemaMetabox.post_id,
                type_hint: typeHint,
                force: forceRegenerate ? 1 : 0,
                fetch_frontend: fetchFrontend ? 1 : 0
            },
            success: function(response) {
                // Stop the waiting progress
                stopProgress();

                if (response.success) {
                    // Show "Processing schema..." while we update the UI
                    $button.text(wpAiSchemaMetabox.i18n.processing);

                    // Update preview
                    var schema = response.data.schema;
                    try {
                        var formatted = JSON.stringify(JSON.parse(schema), null, 2);
                        $preview.val(formatted);
                    } catch (e) {
                        $preview.val(schema);
                    }

                    // Show success message
                    var messageText = response.data.cached
                        ? response.data.message
                        : wpAiSchemaMetabox.i18n.success;
                    showMessage('success', messageText);

                    // Update status
                    updateStatus(true, response.data.generated_at);

                    // Enable copy and validate buttons
                    $('#wp_ai_schema_copy, #wp_ai_schema_validate').prop('disabled', false);

                    // Reset force regenerate checkbox
                    $('#wp_ai_schema_force_regenerate').prop('checked', false);

                    // Auto-run diagnostics after successful generation (not for cached results)
                    if (!response.data.cached) {
                        setTimeout(function() {
                            runDiagnostics();
                        }, 500); // Small delay to let UI settle
                    }
                } else {
                    handleError(response.data);
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = wpAiSchemaMetabox.i18n.error;
                if (status === 'timeout') {
                    errorMsg = wpAiSchemaMetabox.i18n.timeout || 'Request timed out. The AI may be busy - please try again.';
                } else if (error) {
                    errorMsg += ': ' + error;
                }
                showMessage('error', errorMsg);
            },
            complete: function() {
                // Ensure progress is stopped (in case of error)
                stopProgress();

                $spinner.removeClass('is-active');
                $button.removeClass('generating').text(wpAiSchemaMetabox.i18n.generate);

                // Re-enable button after cooldown
                startCooldown();
            }
        });
    }

    /**
     * Handle error response
     */
    function handleError(data) {
        var message = data.message || wpAiSchemaMetabox.i18n.error;

        if (data.cooldown) {
            showMessage('info', message);
        } else if (data.rate_limited) {
            showMessage('error', message);
            if (data.wait_time) {
                startCooldown(data.wait_time);
            }
        } else {
            showMessage('error', message);
        }
    }

    /**
     * Start cooldown timer
     */
    function startCooldown(seconds) {
        seconds = seconds || 30;
        cooldownRemaining = seconds;

        var $button = $('#wp_ai_schema_generate');

        // Clear any existing timer
        if (cooldownTimer) {
            clearInterval(cooldownTimer);
        }

        // Update button text with countdown
        updateCooldownText();

        cooldownTimer = setInterval(function() {
            cooldownRemaining--;

            if (cooldownRemaining <= 0) {
                clearInterval(cooldownTimer);
                cooldownTimer = null;
                $button.prop('disabled', false).text(wpAiSchemaMetabox.i18n.generate);
            } else {
                updateCooldownText();
            }
        }, 1000);
    }

    /**
     * Update cooldown button text
     */
    function updateCooldownText() {
        var $button = $('#wp_ai_schema_generate');
        var text = wpAiSchemaMetabox.i18n.cooldown.replace('%d', cooldownRemaining);
        $button.prop('disabled', true).text(text);
    }

    /**
     * Show message
     */
    function showMessage(type, text) {
        var $message = $('#wp_ai_schema_message');
        $message
            .removeClass('hidden success error info')
            .addClass(type)
            .text(text);
    }

    /**
     * Update status display
     */
    function updateStatus(isCurrent, generatedAt) {
        var $status = $('.ai-jsonld-status');
        var statusHtml = '';

        if (isCurrent) {
            statusHtml += '<span class="ai-jsonld-status-label ai-jsonld-status-current">' +
                wpAiSchemaMetabox.i18n.schema_current + '</span>';
        } else {
            statusHtml += '<span class="ai-jsonld-status-label ai-jsonld-status-outdated">' +
                wpAiSchemaMetabox.i18n.schema_outdated + '</span>';
        }

        if (generatedAt) {
            var date = new Date(generatedAt * 1000);
            var formattedDate = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
            statusHtml += '<span class="ai-jsonld-generated-time">Last generated: ' + formattedDate + '</span>';
        }

        $status.html(statusHtml);
    }

    /**
     * Bind copy button
     */
    function bindCopyButton() {
        $('#wp_ai_schema_copy').on('click', function(e) {
            e.preventDefault();

            var $preview = $('#wp_ai_schema_schema_preview');
            var schema = $preview.val();

            if (!schema) {
                return;
            }

            // Copy to clipboard
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(schema).then(function() {
                    showTemporaryMessage(wpAiSchemaMetabox.i18n.copied, 'success');
                }).catch(function() {
                    fallbackCopy($preview);
                });
            } else {
                fallbackCopy($preview);
            }
        });
    }

    /**
     * Fallback copy method
     */
    function fallbackCopy($textarea) {
        $textarea.select();
        try {
            document.execCommand('copy');
            showTemporaryMessage(wpAiSchemaMetabox.i18n.copied, 'success');
        } catch (e) {
            showTemporaryMessage(wpAiSchemaMetabox.i18n.copy_failed, 'error');
        }
    }

    /**
     * Show temporary message
     */
    function showTemporaryMessage(text, type) {
        var $message = $('#wp_ai_schema_message');
        $message
            .removeClass('hidden success error info')
            .addClass(type)
            .text(text);

        setTimeout(function() {
            $message.addClass('hidden');
        }, 2000);
    }

    /**
     * Bind validate button
     */
    function bindValidateButton() {
        $('#wp_ai_schema_validate').on('click', function(e) {
            e.preventDefault();

            var $preview = $('#wp_ai_schema_schema_preview');
            var schema = $preview.val();

            if (!schema) {
                return;
            }

            try {
                JSON.parse(schema);
                showTemporaryMessage(wpAiSchemaMetabox.i18n.valid_json, 'success');
            } catch (e) {
                showTemporaryMessage(wpAiSchemaMetabox.i18n.invalid_json + ': ' + e.message, 'error');
            }
        });
    }

    /**
     * Bind diagnostics button
     */
    function bindDiagnosticsButton() {
        $('#wp_ai_schema_run_diagnostics').on('click', function(e) {
            e.preventDefault();
            runDiagnostics();
        });
    }

    /**
     * Bind verify frontend button
     */
    function bindVerifyFrontendButton() {
        $('#wp_ai_schema_verify_frontend').on('click', function(e) {
            e.preventDefault();
            verifyFrontend();
        });
    }

    /**
     * Run diagnostics via AJAX
     */
    function runDiagnostics() {
        var $button = $('#wp_ai_schema_run_diagnostics');
        var $spinner = $('.ai-jsonld-diagnostic-spinner');
        var $panel = $('#wp_ai_schema_diagnostic_panel');

        // Disable button and show loading state
        $button.prop('disabled', true).text(wpAiSchemaMetabox.i18n.running_diagnostics);
        $spinner.addClass('is-active');

        $.ajax({
            url: wpAiSchemaMetabox.ajax_url,
            type: 'POST',
            timeout: 30000,
            data: {
                action: 'wp_ai_schema_diagnose',
                nonce: wpAiSchemaMetabox.nonce,
                post_id: wpAiSchemaMetabox.post_id
            },
            success: function(response) {
                if (response.success) {
                    updateDiagnosticPanel(response.data);
                } else {
                    $panel.html('<p class="ai-jsonld-diagnostic-error">' + 
                        (response.data.message || wpAiSchemaMetabox.i18n.diagnostic_error) + '</p>');
                }
            },
            error: function(xhr, status, error) {
                $panel.html('<p class="ai-jsonld-diagnostic-error">' + 
                    wpAiSchemaMetabox.i18n.diagnostic_error + ': ' + error + '</p>');
            },
            complete: function() {
                $spinner.removeClass('is-active');
                $button.prop('disabled', false).text(wpAiSchemaMetabox.i18n.run_diagnostics);
            }
        });
    }

    /**
     * Update diagnostic panel with check results
     */
    function updateDiagnosticPanel(data) {
        var $panel = $('#wp_ai_schema_diagnostic_panel');
        var html = '';

        // Summary
        var summaryClass = data.will_output ? 'ai-jsonld-summary-success' : 'ai-jsonld-summary-warning';
        html += '<div class="ai-jsonld-diagnostic-summary ' + summaryClass + '">';
        html += '<span class="ai-jsonld-summary-icon">' + (data.will_output ? '&#10003;' : '&#9888;') + '</span>';
        html += '<span class="ai-jsonld-summary-text">' + data.summary + '</span>';
        html += '</div>';

        // Individual checks
        html += '<ul class="ai-jsonld-diagnostic-checks">';
        
        for (var key in data.checks) {
            if (data.checks.hasOwnProperty(key)) {
                var check = data.checks[key];
                var checkClass = 'ai-jsonld-check-item';
                var icon = '&#10003;'; // checkmark
                
                if (!check.pass) {
                    if (check.warning) {
                        checkClass += ' ai-jsonld-check-warning';
                        icon = '&#9888;'; // warning
                    } else if (check.info) {
                        checkClass += ' ai-jsonld-check-info';
                        icon = '&#8505;'; // info
                    } else {
                        checkClass += ' ai-jsonld-check-fail';
                        icon = '&#10007;'; // X
                    }
                } else {
                    checkClass += ' ai-jsonld-check-pass';
                }

                html += '<li class="' + checkClass + '">';
                html += '<span class="ai-jsonld-check-icon">' + icon + '</span>';
                html += '<span class="ai-jsonld-check-label">' + check.label + '</span>';
                html += '<span class="ai-jsonld-check-message">' + check.message + '</span>';
                html += '</li>';
            }
        }
        
        html += '</ul>';

        $panel.html(html);

        // Enable/disable verify button based on schema existence
        var hasSchema = data.checks.schema_exists && data.checks.schema_exists.pass;
        $('#wp_ai_schema_verify_frontend').prop('disabled', !hasSchema);
    }

    /**
     * Verify frontend output
     * First tries JS fetch, then falls back to backend verification
     */
    function verifyFrontend() {
        var $button = $('#wp_ai_schema_verify_frontend');
        var $spinner = $('.ai-jsonld-diagnostic-spinner');
        var $result = $('#wp_ai_schema_verify_result');

        // Disable button and show loading state
        $button.prop('disabled', true).text(wpAiSchemaMetabox.i18n.verifying_frontend);
        $spinner.addClass('is-active');
        $result.removeClass('hidden success error warning').html('');

        // Check if post is published - if not, try JS verification only
        if (wpAiSchemaMetabox.post_status !== 'publish') {
            // Try JS-based verification for preview/draft
            $button.text(wpAiSchemaMetabox.i18n.checking_via_js);
            verifyViaJsFetch().then(function(jsResult) {
                showVerifyResult(jsResult);
            }).catch(function() {
                showVerifyResult({
                    success: false,
                    schema_found: false,
                    message: wpAiSchemaMetabox.i18n.preview_only
                });
            }).finally(function() {
                $spinner.removeClass('is-active');
                $button.prop('disabled', false).text(wpAiSchemaMetabox.i18n.verify_frontend);
            });
            return;
        }

        // For published posts, try JS first, then fall back to backend
        verifyViaJsFetch().then(function(jsResult) {
            if (jsResult.schema_found) {
                showVerifyResult(jsResult);
                $spinner.removeClass('is-active');
                $button.prop('disabled', false).text(wpAiSchemaMetabox.i18n.verify_frontend);
            } else {
                // JS didn't find it, try backend verification
                verifyViaBackend();
            }
        }).catch(function() {
            // JS fetch failed, try backend
            verifyViaBackend();
        });
    }

    /**
     * Verify via JavaScript fetch (for same-origin pages)
     */
    function verifyViaJsFetch() {
        return new Promise(function(resolve, reject) {
            var url = wpAiSchemaMetabox.post_url;
            
            if (!url) {
                reject(new Error('No URL'));
                return;
            }

            fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store'
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            })
            .then(function(html) {
                // Look for JSON-LD script tags
                var pattern = /<script\s+type=["']application\/ld\+json["']>([\s\S]*?)<\/script>/gi;
                var matches = [];
                var match;
                
                while ((match = pattern.exec(html)) !== null) {
                    matches.push(match[1].trim());
                }

                if (matches.length === 0) {
                    resolve({
                        success: true,
                        schema_found: false,
                        schema_match: false,
                        message: wpAiSchemaMetabox.i18n.js_verify_not_found,
                        via_js: true
                    });
                    return;
                }

                // Get stored schema from preview textarea for comparison
                var storedSchema = $('#wp_ai_schema_schema_preview').val();
                var storedParsed = null;
                
                try {
                    storedParsed = JSON.parse(storedSchema);
                } catch (e) {
                    // Can't parse stored schema
                }

                // Check if any found schema matches
                for (var i = 0; i < matches.length; i++) {
                    try {
                        var foundParsed = JSON.parse(matches[i]);
                        if (storedParsed && JSON.stringify(foundParsed) === JSON.stringify(storedParsed)) {
                            resolve({
                                success: true,
                                schema_found: true,
                                schema_match: true,
                                message: wpAiSchemaMetabox.i18n.js_verify_success,
                                via_js: true
                            });
                            return;
                        }
                    } catch (e) {
                        // Continue to next match
                    }
                }

                // Found schemas but none match
                resolve({
                    success: true,
                    schema_found: true,
                    schema_match: false,
                    message: wpAiSchemaMetabox.i18n.schema_mismatch,
                    via_js: true
                });
            })
            .catch(function(error) {
                reject(error);
            });
        });
    }

    /**
     * Verify via backend AJAX
     */
    function verifyViaBackend() {
        var $button = $('#wp_ai_schema_verify_frontend');
        var $spinner = $('.ai-jsonld-diagnostic-spinner');

        $.ajax({
            url: wpAiSchemaMetabox.ajax_url,
            type: 'POST',
            timeout: 30000,
            data: {
                action: 'wp_ai_schema_verify_frontend',
                nonce: wpAiSchemaMetabox.nonce,
                post_id: wpAiSchemaMetabox.post_id
            },
            success: function(response) {
                if (response.success) {
                    showVerifyResult(response.data);
                } else {
                    showVerifyResult({
                        success: false,
                        schema_found: false,
                        message: response.data.message || wpAiSchemaMetabox.i18n.verify_error
                    });
                }
            },
            error: function(xhr, status, error) {
                showVerifyResult({
                    success: false,
                    schema_found: false,
                    message: wpAiSchemaMetabox.i18n.verify_error + ': ' + error
                });
            },
            complete: function() {
                $spinner.removeClass('is-active');
                $button.prop('disabled', false).text(wpAiSchemaMetabox.i18n.verify_frontend);
            }
        });
    }

    /**
     * Show verification result
     */
    function showVerifyResult(result) {
        var $result = $('#wp_ai_schema_verify_result');
        var resultClass = '';
        var icon = '';

        if (result.schema_found && result.schema_match) {
            resultClass = 'success';
            icon = '&#10003;';
        } else if (result.schema_found && !result.schema_match) {
            resultClass = 'warning';
            icon = '&#9888;';
        } else {
            resultClass = 'error';
            icon = '&#10007;';
        }

        var html = '<span class="ai-jsonld-verify-icon">' + icon + '</span>';
        html += '<span class="ai-jsonld-verify-message">' + result.message + '</span>';
        
        if (result.use_js_verify) {
            html += '<br><small>' + wpAiSchemaMetabox.i18n.preview_only + '</small>';
        }

        $result.removeClass('hidden success error warning').addClass(resultClass).html(html);
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
