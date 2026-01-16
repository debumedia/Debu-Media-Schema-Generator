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
     * Debug logging - outputs to browser console when enabled
     * Enable via: wpAiSchemaMetabox.debug = true (set in PHP when debug_logging is on)
     */
    var debugLog = function(label, data) {
        if (wpAiSchemaMetabox.debug) {
            console.group('%c[AI Schema Debug] ' + label, 'color: #0073aa; font-weight: bold;');
            if (typeof data === 'object') {
                console.log(JSON.parse(JSON.stringify(data))); // Deep clone for clean output
            } else {
                console.log(data);
            }
            console.groupEnd();
        }
    };

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
     * Start two-pass progress indicator (fallback when not streaming)
     */
    function startTwoPassProgress() {
        var $button = $('#wp_ai_schema_generate');
        progressStep = 0;

        // Clear any existing timer
        if (progressTimer) {
            clearTimeout(progressTimer);
        }

        // Show first step (Pass 1: Analyzing)
        $button.text(wpAiSchemaMetabox.i18n.deep_analysis_pass1 || 'Pass 1: Analyzing content...');

        // Schedule Pass 2 indicator
        progressTimer = setTimeout(function() {
            $button.text(wpAiSchemaMetabox.i18n.deep_analysis_pass2 || 'Pass 2: Generating schema...');
        }, 8000); // Switch to Pass 2 after ~8 seconds
    }

    /**
     * Update button with streaming status
     */
    function updateStreamingStatus(message) {
        var $button = $('#wp_ai_schema_generate');
        $button.text(message);
    }

    /**
     * Progress steps for two-pass generation
     */
    var twoPassSteps = [
        { text: 'deep_analysis_pass1', delay: 0 },
        { text: 'deep_analysis_pass2', delay: 8000 }
    ];

    /**
     * Active EventSource for streaming
     */
    var activeEventSource = null;

    /**
     * Generate schema via AJAX or Streaming
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
        var deepAnalysis = $('#wp_ai_schema_deep_analysis').is(':checked');

        // Disable button and show loading state
        $button.prop('disabled', true).addClass('generating');
        $spinner.addClass('is-active');
        $message.removeClass('success error info').addClass('hidden');

        // Debug: Log request
        debugLog('Request Parameters', {
            postId: wpAiSchemaMetabox.post_id,
            typeHint: typeHint,
            forceRegenerate: forceRegenerate,
            fetchFrontend: fetchFrontend,
            deepAnalysis: deepAnalysis,
            streaming: deepAnalysis && wpAiSchemaMetabox.rest_url
        });

        // Use streaming for deep analysis if REST URL is available
        if (deepAnalysis && wpAiSchemaMetabox.rest_url) {
            generateSchemaStreaming();
        } else {
            generateSchemaAjax(deepAnalysis);
        }
    }

    /**
     * Generate schema with streaming (real-time progress)
     */
    function generateSchemaStreaming() {
        var $button = $('#wp_ai_schema_generate');
        var $spinner = $('.ai-jsonld-spinner');
        var $preview = $('#wp_ai_schema_schema_preview');
        var startTime = Date.now();

        // Close any existing connection
        if (activeEventSource) {
            activeEventSource.close();
        }

        // Build the streaming URL
        var streamUrl = wpAiSchemaMetabox.rest_url + 'wp-ai-schema/v1/stream';

        debugLog('Starting streaming request', { url: streamUrl });

        // Show initial status
        updateStreamingStatus('Connecting...');

        // Use fetch with POST for the streaming request
        fetch(streamUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpAiSchemaMetabox.rest_nonce
            },
            body: JSON.stringify({
                post_id: wpAiSchemaMetabox.post_id,
                nonce: wpAiSchemaMetabox.nonce
            })
        }).then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';

            function processStream() {
                return reader.read().then(function(result) {
                    if (result.done) {
                        debugLog('Stream complete', { elapsed: (Date.now() - startTime) / 1000 + 's' });
                        return;
                    }

                    buffer += decoder.decode(result.value, { stream: true });

                    // Process complete events in buffer
                    var lines = buffer.split('\n');
                    buffer = lines.pop(); // Keep incomplete line in buffer

                    lines.forEach(function(line) {
                        line = line.trim();
                        if (!line) return;

                        if (line.startsWith('event: ')) {
                            // Store event type for next data line
                            window._sseEventType = line.substring(7);
                        } else if (line.startsWith('data: ')) {
                            var eventType = window._sseEventType || 'message';
                            var data = line.substring(6);

                            try {
                                var parsed = JSON.parse(data);
                                handleStreamEvent(eventType, parsed, startTime);
                            } catch (e) {
                                debugLog('Parse error', { data: data, error: e });
                            }
                        }
                    });

                    return processStream();
                });
            }

            return processStream();
        }).catch(function(error) {
            debugLog('Streaming error', error);
            showMessage('error', 'Streaming failed: ' + error.message);
            finishGeneration();
        });
    }

    /**
     * Handle a streaming event
     */
    function handleStreamEvent(eventType, data, startTime) {
        var $button = $('#wp_ai_schema_generate');
        var $preview = $('#wp_ai_schema_schema_preview');
        var elapsed = ((Date.now() - startTime) / 1000).toFixed(1);

        debugLog('Stream event: ' + eventType, data);

        switch (eventType) {
            case 'status':
                // Update button with current status
                var statusText = data.message || 'Processing...';
                updateStreamingStatus(statusText + ' (' + elapsed + 's)');

                // Log findings if present
                if (data.findings) {
                    console.log('%c[AI Schema] Content found:', 'color: #28a745; font-weight: bold;', data.findings);
                }
                break;

            case 'content':
                // Show that content is being generated
                if (data.phase === 'pass1') {
                    updateStreamingStatus('AI analyzing... (' + elapsed + 's)');
                } else if (data.phase === 'pass2') {
                    updateStreamingStatus('Generating schema... (' + elapsed + 's)');
                }
                break;

            case 'complete':
                // Success! Update the preview
                var schema = data.schema;
                try {
                    var formatted = JSON.stringify(JSON.parse(schema), null, 2);
                    $preview.val(formatted);
                } catch (e) {
                    $preview.val(schema);
                }

                showMessage('success', data.message || 'Schema generated with streaming!');
                updateStatus(true, Math.floor(Date.now() / 1000));

                // Enable copy and validate buttons
                $('#wp_ai_schema_copy, #wp_ai_schema_validate').prop('disabled', false);
                $('#wp_ai_schema_force_regenerate').prop('checked', false);

                console.log('%c[AI Schema] Complete!', 'color: #28a745; font-weight: bold; font-size: 14px;');
                console.log('  Total time: ' + elapsed + 's');

                finishGeneration();

                // Auto-run diagnostics
                setTimeout(function() {
                    runDiagnostics();
                }, 500);
                break;

            case 'error':
                showMessage('error', data.message || 'An error occurred');
                finishGeneration();
                break;
        }
    }

    /**
     * Finish generation (cleanup)
     */
    function finishGeneration() {
        var $button = $('#wp_ai_schema_generate');
        var $spinner = $('.ai-jsonld-spinner');

        stopProgress();
        $spinner.removeClass('is-active');
        $button.removeClass('generating').text(wpAiSchemaMetabox.i18n.generate);
        $button.prop('disabled', false);

        if (activeEventSource) {
            activeEventSource.close();
            activeEventSource = null;
        }

        startCooldown();
    }

    /**
     * Generate schema via traditional AJAX (fallback)
     */
    function generateSchemaAjax(deepAnalysis) {
        var $button = $('#wp_ai_schema_generate');
        var $spinner = $('.ai-jsonld-spinner');
        var $preview = $('#wp_ai_schema_schema_preview');

        var typeHint = $('#wp_ai_schema_type_hint').val();
        var forceRegenerate = $('#wp_ai_schema_force_regenerate').is(':checked');
        var fetchFrontend = $('#wp_ai_schema_fetch_frontend').is(':checked');

        // Start progress indicator (use different steps for two-pass)
        if (deepAnalysis) {
            startTwoPassProgress();
        } else {
            startProgress();
        }

        // Build request data
        var requestData = {
            action: 'wp_ai_schema_generate',
            nonce: wpAiSchemaMetabox.nonce,
            post_id: wpAiSchemaMetabox.post_id,
            type_hint: typeHint,
            force: forceRegenerate ? 1 : 0,
            fetch_frontend: fetchFrontend ? 1 : 0,
            deep_analysis: deepAnalysis ? 1 : 0
        };

        // Make AJAX request
        $.ajax({
            url: wpAiSchemaMetabox.ajax_url,
            type: 'POST',
            timeout: deepAnalysis ? 300000 : 150000, // 5 min for two-pass, 2.5 min for single pass
            data: requestData,
            success: function(response) {
                // Debug: Log full response
                debugLog('Full Response', response);

                // Debug: Log timing info prominently if available
                if (response.data && response.data.debug && response.data.debug.timing) {
                    var t = response.data.debug.timing;
                    console.log('%c[AI Schema Timing]', 'color: #28a745; font-weight: bold; font-size: 14px;');
                    console.log('  Pass 1 (Content Analysis): ' + (t.pass1_seconds || '?') + 's');
                    console.log('  Pass 2 (Schema Generation): ' + (t.pass2_seconds || '?') + 's');
                    console.log('  Pass 2 Payload Size: ' + (t.pass2_payload_kb || '?') + ' KB');
                    console.log('  Total Time: ' + (t.total_seconds || '?') + 's');
                }
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
                    var messageText;
                    if (response.data.cached) {
                        messageText = response.data.message;
                    } else if (response.data.two_pass) {
                        messageText = wpAiSchemaMetabox.i18n.deep_analysis_success || 'Schema generated with deep analysis!';
                    } else {
                        messageText = wpAiSchemaMetabox.i18n.success;
                    }
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
