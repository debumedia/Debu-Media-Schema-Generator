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
        bindLocationRepeater();
    }

    /**
     * Bind test connection button
     */
    function bindTestConnection() {
        $('#wp_ai_schema_test_connection').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $status = $('#wp_ai_schema_connection_status');
            var $apiKeyField = $('#wp_ai_schema_api_key');
            var apiKey = $apiKeyField.val();

            // Disable button and show loading state
            $button.prop('disabled', true).text(wpAiSchemaAdmin.i18n.testing);
            $status.removeClass('success error testing').addClass('testing').text(wpAiSchemaAdmin.i18n.testing);

            // Make AJAX request
            $.ajax({
                url: wpAiSchemaAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wp_ai_schema_test_connection',
                    nonce: wpAiSchemaAdmin.nonce,
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
                    $status.removeClass('testing success').addClass('error').text(wpAiSchemaAdmin.i18n.error + ': ' + error);
                },
                complete: function() {
                    $button.prop('disabled', false).text(wpAiSchemaAdmin.i18n.test);
                }
            });
        });
    }

    /**
     * Bind API key field toggle visibility
     */
    function bindApiKeyToggle() {
        var $field = $('#wp_ai_schema_api_key');
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

    /**
     * Bind location repeater functionality
     */
    function bindLocationRepeater() {
        var $container = $('#wp-ai-schema-locations');
        var $addButton = $('#wp-ai-schema-add-location');

        if ($container.length === 0) {
            return;
        }

        // Add new location
        $addButton.on('click', function(e) {
            e.preventDefault();
            addLocation();
        });

        // Remove location (delegated)
        $container.on('click', '.wp-ai-schema-remove-location', function(e) {
            e.preventDefault();
            removeLocation($(this).closest('.wp-ai-schema-location'));
        });
    }

    /**
     * Add a new location block
     */
    function addLocation() {
        var $container = $('#wp-ai-schema-locations');
        var $locations = $container.find('.wp-ai-schema-location');
        var newIndex = $locations.length;

        // Clone the first location as template
        var $template = $locations.first().clone();

        // Update index in all field names and clear values
        $template.attr('data-index', newIndex);
        $template.find('input, textarea').each(function() {
            var $field = $(this);
            var name = $field.attr('name');

            if (name) {
                // Replace the index in the name attribute
                name = name.replace(/\[business_locations\]\[\d+\]/, '[business_locations][' + newIndex + ']');
                $field.attr('name', name);
            }

            // Clear the value
            $field.val('');
        });

        // Update location number
        $template.find('.location-number').text(newIndex + 1);

        // Show remove button
        $template.find('.wp-ai-schema-remove-location').show();

        // Also show remove button on existing locations
        $container.find('.wp-ai-schema-remove-location').show();

        // Append to container
        $container.append($template);

        // Scroll to new location
        $template[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /**
     * Remove a location block
     */
    function removeLocation($location) {
        var $container = $('#wp-ai-schema-locations');
        var $locations = $container.find('.wp-ai-schema-location');

        // Don't remove if it's the last one
        if ($locations.length <= 1) {
            return;
        }

        // Remove the location
        $location.slideUp(300, function() {
            $(this).remove();
            reindexLocations();
        });
    }

    /**
     * Reindex all locations after removal
     */
    function reindexLocations() {
        var $container = $('#wp-ai-schema-locations');
        var $locations = $container.find('.wp-ai-schema-location');

        $locations.each(function(index) {
            var $location = $(this);
            $location.attr('data-index', index);
            $location.find('.location-number').text(index + 1);

            // Update all field names
            $location.find('input, textarea').each(function() {
                var $field = $(this);
                var name = $field.attr('name');

                if (name) {
                    name = name.replace(/\[business_locations\]\[\d+\]/, '[business_locations][' + index + ']');
                    $field.attr('name', name);
                }
            });
        });

        // Hide remove button if only one location remains
        if ($locations.length <= 1) {
            $locations.find('.wp-ai-schema-remove-location').hide();
        }
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
