/**
 * AI JSON-LD Generator - Metabox JS
 */

(function($) {
    'use strict';

    var cooldownTimer = null;
    var cooldownRemaining = 0;

    /**
     * Initialize metabox functionality
     */
    function init() {
        bindGenerateButton();
        bindCopyButton();
        bindValidateButton();
    }

    /**
     * Bind generate button click
     */
    function bindGenerateButton() {
        $('#ai_jsonld_generate').on('click', function(e) {
            e.preventDefault();
            generateSchema();
        });
    }

    /**
     * Generate schema via AJAX
     */
    function generateSchema() {
        var $button = $('#ai_jsonld_generate');
        var $spinner = $('.ai-jsonld-spinner');
        var $preview = $('#ai_jsonld_schema_preview');
        var $message = $('#ai_jsonld_message');
        var $status = $('.ai-jsonld-status');

        var typeHint = $('#ai_jsonld_type_hint').val();
        var forceRegenerate = $('#ai_jsonld_force_regenerate').is(':checked');

        // Disable button and show loading state
        $button.prop('disabled', true).addClass('generating').text(aiJsonldMetabox.i18n.generating);
        $spinner.addClass('is-active');
        $message.removeClass('success error info').addClass('hidden');

        // Make AJAX request
        $.ajax({
            url: aiJsonldMetabox.ajax_url,
            type: 'POST',
            data: {
                action: 'ai_jsonld_generate',
                nonce: aiJsonldMetabox.nonce,
                post_id: aiJsonldMetabox.post_id,
                type_hint: typeHint,
                force: forceRegenerate ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
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
                        : aiJsonldMetabox.i18n.success;
                    showMessage('success', messageText);

                    // Update status
                    updateStatus(true, response.data.generated_at);

                    // Enable copy and validate buttons
                    $('#ai_jsonld_copy, #ai_jsonld_validate').prop('disabled', false);

                    // Reset force regenerate checkbox
                    $('#ai_jsonld_force_regenerate').prop('checked', false);
                } else {
                    handleError(response.data);
                }
            },
            error: function(xhr, status, error) {
                showMessage('error', aiJsonldMetabox.i18n.error + ': ' + error);
            },
            complete: function() {
                $button.removeClass('generating').text(aiJsonldMetabox.i18n.generate);
                $spinner.removeClass('is-active');

                // Re-enable button after cooldown
                startCooldown();
            }
        });
    }

    /**
     * Handle error response
     */
    function handleError(data) {
        var message = data.message || aiJsonldMetabox.i18n.error;

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

        var $button = $('#ai_jsonld_generate');

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
                $button.prop('disabled', false).text(aiJsonldMetabox.i18n.generate);
            } else {
                updateCooldownText();
            }
        }, 1000);
    }

    /**
     * Update cooldown button text
     */
    function updateCooldownText() {
        var $button = $('#ai_jsonld_generate');
        var text = aiJsonldMetabox.i18n.cooldown.replace('%d', cooldownRemaining);
        $button.prop('disabled', true).text(text);
    }

    /**
     * Show message
     */
    function showMessage(type, text) {
        var $message = $('#ai_jsonld_message');
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
                aiJsonldMetabox.i18n.schema_current + '</span>';
        } else {
            statusHtml += '<span class="ai-jsonld-status-label ai-jsonld-status-outdated">' +
                aiJsonldMetabox.i18n.schema_outdated + '</span>';
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
        $('#ai_jsonld_copy').on('click', function(e) {
            e.preventDefault();

            var $preview = $('#ai_jsonld_schema_preview');
            var schema = $preview.val();

            if (!schema) {
                return;
            }

            // Copy to clipboard
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(schema).then(function() {
                    showTemporaryMessage(aiJsonldMetabox.i18n.copied, 'success');
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
            showTemporaryMessage(aiJsonldMetabox.i18n.copied, 'success');
        } catch (e) {
            showTemporaryMessage(aiJsonldMetabox.i18n.copy_failed, 'error');
        }
    }

    /**
     * Show temporary message
     */
    function showTemporaryMessage(text, type) {
        var $message = $('#ai_jsonld_message');
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
        $('#ai_jsonld_validate').on('click', function(e) {
            e.preventDefault();

            var $preview = $('#ai_jsonld_schema_preview');
            var schema = $preview.val();

            if (!schema) {
                return;
            }

            try {
                JSON.parse(schema);
                showTemporaryMessage(aiJsonldMetabox.i18n.valid_json, 'success');
            } catch (e) {
                showTemporaryMessage(aiJsonldMetabox.i18n.invalid_json + ': ' + e.message, 'error');
            }
        });
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
