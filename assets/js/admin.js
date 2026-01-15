/**
 * AI JSON-LD Generator - Admin Settings JS
 */

(function($) {
    'use strict';

    /**
     * Initialize admin functionality
     */
    function init() {
        bindTestConnection();
        bindApiKeyToggle();
    }

    /**
     * Bind test connection button
     */
    function bindTestConnection() {
        $('#ai_jsonld_test_connection').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $status = $('#ai_jsonld_connection_status');
            var $apiKeyField = $('#ai_jsonld_api_key');
            var apiKey = $apiKeyField.val();

            // Disable button and show loading state
            $button.prop('disabled', true).text(aiJsonldAdmin.i18n.testing);
            $status.removeClass('success error testing').addClass('testing').text(aiJsonldAdmin.i18n.testing);

            // Make AJAX request
            $.ajax({
                url: aiJsonldAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'ai_jsonld_test_connection',
                    nonce: aiJsonldAdmin.nonce,
                    api_key: apiKey
                },
                success: function(response) {
                    if (response.success) {
                        $status.removeClass('testing error').addClass('success').text(response.data.message);
                    } else {
                        $status.removeClass('testing success').addClass('error').text(response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    $status.removeClass('testing success').addClass('error').text(aiJsonldAdmin.i18n.error + ': ' + error);
                },
                complete: function() {
                    $button.prop('disabled', false).text(aiJsonldAdmin.i18n.test);
                }
            });
        });
    }

    /**
     * Bind API key field toggle visibility
     */
    function bindApiKeyToggle() {
        var $field = $('#ai_jsonld_api_key');
        var $wrapper = $field.parent();

        // Add toggle button if not already present
        if ($wrapper.find('.toggle-password').length === 0) {
            var $toggle = $('<button type="button" class="button button-secondary toggle-password" style="margin-left: 5px;"><span class="dashicons dashicons-visibility" style="line-height: 1.4;"></span></button>');

            $toggle.insertAfter($field);

            $toggle.on('click', function(e) {
                e.preventDefault();
                var $icon = $(this).find('.dashicons');

                if ($field.attr('type') === 'password') {
                    $field.attr('type', 'text');
                    $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                } else {
                    $field.attr('type', 'password');
                    $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                }
            });
        }
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
