/**
 * @file
 * Dataverse Webform Integration admin interface JavaScript.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * Dataverse admin interface behaviors.
   */
  Drupal.behaviors.dataverseAdmin = {
    attach: function (context, settings) {
      // Initialize auto-mapping functionality
      $('.button[data-webform-field]', context).once('dataverse-auto-map').on('click', function (e) {
        e.preventDefault();
        var $button = $(this);
        var webformField = $button.data('webform-field');
        var rowIndex = $button.data('row-index');
        
        Drupal.dataverseAdmin.autoMapField(webformField, rowIndex);
      });

      // Initialize field mapping suggestions
      $('.field-mapping-entity-select', context).once('dataverse-entity-change').on('change', function () {
        var $select = $(this);
        var entityName = $select.val();
        var webformField = $select.data('webform-field');
        var rowIndex = $select.data('row-index');
        
        if (entityName && webformField) {
          Drupal.dataverseAdmin.loadFieldSuggestions(entityName, webformField, rowIndex);
        }
      });

      // Initialize configuration validation
      if ($('#edit-third-party-settings-dataverse-webform-enabled', context).length) {
        Drupal.dataverseAdmin.initConfigValidation(context);
      }

      // Initialize tooltips for field information
      $('.field-info-tooltip', context).once('dataverse-tooltip').tooltip({
        placement: 'top',
        trigger: 'hover'
      });
    }
  };

  /**
   * Dataverse admin utility functions.
   */
  Drupal.dataverseAdmin = {
    
    /**
     * Auto-map a webform field to suggested Dataverse fields.
     */
    autoMapField: function (webformField, rowIndex) {
      var entitySelect = $('[data-row-index="' + rowIndex + '"] select[name*="[entity]"]');
      var fieldSelect = $('[data-row-index="' + rowIndex + '"] select[name*="[field]"]');
      var transformSelect = $('[data-row-index="' + rowIndex + '"] select[name*="[transform]"]');
      
      var entityName = entitySelect.val();
      if (!entityName) {
        Drupal.dataverseAdmin.showMessage('Please select an entity first.', 'warning');
        return;
      }

      // Show loading state
      var $button = $('[data-webform-field="' + webformField + '"][data-row-index="' + rowIndex + '"]');
      var originalText = $button.val();
      $button.val('Mapping...').prop('disabled', true);

      // Get field suggestions
      var suggestions = Drupal.dataverseAdmin.getFieldSuggestions(webformField, entityName);
      
      if (suggestions.length > 0) {
        var bestMatch = suggestions[0];
        fieldSelect.val(bestMatch.field);
        
        // Auto-select appropriate transform
        var suggestedTransform = Drupal.dataverseAdmin.suggestTransform(webformField, bestMatch.field);
        if (suggestedTransform) {
          transformSelect.val(suggestedTransform);
        }
        
        Drupal.dataverseAdmin.showMessage(
          'Auto-mapped "' + webformField + '" to "' + bestMatch.field + '" (' + bestMatch.confidence + '% confidence)',
          'status'
        );
      } else {
        Drupal.dataverseAdmin.showMessage(
          'No suitable mapping found for "' + webformField + '"',
          'warning'
        );
      }

      // Restore button state
      setTimeout(function () {
        $button.val(originalText).prop('disabled', false);
      }, 1000);
    },

    /**
     * Load field suggestions for a specific entity.
     */
    loadFieldSuggestions: function (entityName, webformField, rowIndex) {
      // This would typically make an AJAX call to get suggestions
      // For now, we'll use client-side logic
      console.log('Loading field suggestions for entity:', entityName, 'field:', webformField);
    },

    /**
     * Get field mapping suggestions based on field names.
     */
    getFieldSuggestions: function (webformField, entityName) {
      // Common field mappings
      var commonMappings = {
        'first_name': { targets: ['firstname', 'fname'], confidence: 90 },
        'last_name': { targets: ['lastname', 'lname', 'surname'], confidence: 90 },
        'email': { targets: ['emailaddress1', 'email'], confidence: 95 },
        'phone': { targets: ['telephone1', 'mobilephone'], confidence: 85 },
        'company': { targets: ['company', 'accountname'], confidence: 80 },
        'name': { targets: ['name', 'fullname'], confidence: 75 },
        'address': { targets: ['address1_line1'], confidence: 80 },
        'city': { targets: ['address1_city'], confidence: 90 },
        'state': { targets: ['address1_stateorprovince'], confidence: 90 },
        'zip': { targets: ['address1_postalcode'], confidence: 90 },
        'country': { targets: ['address1_country'], confidence: 90 },
        'website': { targets: ['websiteurl'], confidence: 85 },
        'description': { targets: ['description'], confidence: 70 }
      };

      var webformLower = webformField.toLowerCase();
      var suggestions = [];

      // Check for exact mappings
      if (commonMappings[webformLower]) {
        var mapping = commonMappings[webformLower];
        mapping.targets.forEach(function (target) {
          suggestions.push({
            field: target,
            confidence: mapping.confidence,
            reason: 'Common mapping pattern'
          });
        });
      }

      // Check for partial matches
      for (var pattern in commonMappings) {
        if (webformLower.indexOf(pattern) !== -1 && !commonMappings[webformLower]) {
          var mapping = commonMappings[pattern];
          mapping.targets.forEach(function (target) {
            suggestions.push({
              field: target,
              confidence: mapping.confidence - 20,
              reason: 'Partial name match'
            });
          });
        }
      }

      // Sort by confidence
      suggestions.sort(function (a, b) {
        return b.confidence - a.confidence;
      });

      return suggestions;
    },

    /**
     * Suggest appropriate transform type based on field names.
     */
    suggestTransform: function (webformField, dataverseField) {
      var webformLower = webformField.toLowerCase();
      var dataverseLower = dataverseField.toLowerCase();

      // Email fields
      if (webformLower.indexOf('email') !== -1 || dataverseLower.indexOf('email') !== -1) {
        return 'email';
      }

      // Phone fields
      if (webformLower.indexOf('phone') !== -1 || dataverseLower.indexOf('telephone') !== -1) {
        return 'phone';
      }

      // Date fields
      if (webformLower.indexOf('date') !== -1 || dataverseLower.indexOf('date') !== -1) {
        return 'date';
      }

      // URL fields
      if (webformLower.indexOf('url') !== -1 || webformLower.indexOf('website') !== -1 || 
          dataverseLower.indexOf('url') !== -1 || dataverseLower.indexOf('website') !== -1) {
        return 'url';
      }

      // Boolean fields
      if (webformLower.indexOf('is') === 0 || webformLower.indexOf('has') === 0 || 
          webformLower.indexOf('do') === 0 || dataverseLower.indexOf('donot') !== -1) {
        return 'boolean';
      }

      // Default to string
      return 'string';
    },

    /**
     * Initialize configuration validation.
     */
    initConfigValidation: function (context) {
      var $form = $('#webform-admin-form', context);
      var validationTimer;

      // Add real-time validation
      $form.find('input, select', context).once('dataverse-validation').on('change blur', function () {
        clearTimeout(validationTimer);
        validationTimer = setTimeout(function () {
          Drupal.dataverseAdmin.validateConfiguration();
        }, 1000);
      });
    },

    /**
     * Validate current configuration.
     */
    validateConfiguration: function () {
      var config = Drupal.dataverseAdmin.gatherConfiguration();
      
      if (!config.enabled) {
        return;
      }

      // Basic validation
      var errors = [];
      var warnings = [];

      if (!config.azure_tenant_id) {
        errors.push('Azure Tenant ID is required');
      } else if (!Drupal.dataverseAdmin.isValidGuid(config.azure_tenant_id)) {
        errors.push('Azure Tenant ID must be in GUID format');
      }

      if (!config.dataverse_url) {
        errors.push('Dataverse URL is required');
      } else if (!config.dataverse_url.startsWith('https://')) {
        warnings.push('Dataverse URL should use HTTPS');
      }

      if (!config.azure_client_id_key) {
        errors.push('Azure Client ID Key is required');
      }

      if (!config.azure_client_secret_key) {
        errors.push('Azure Client Secret Key is required');
      }

      // Display validation results
      Drupal.dataverseAdmin.displayValidationResults(errors, warnings);
    },

    /**
     * Gather current configuration from form.
     */
    gatherConfiguration: function () {
      return {
        enabled: $('#edit-third-party-settings-dataverse-webform-enabled').is(':checked'),
        azure_tenant_id: $('#edit-third-party-settings-dataverse-webform-azure-tenant-id').val(),
        dataverse_url: $('#edit-third-party-settings-dataverse-webform-dataverse-url').val(),
        azure_client_id_key: $('#edit-third-party-settings-dataverse-webform-azure-client-id-key').val(),
        azure_client_secret_key: $('#edit-third-party-settings-dataverse-webform-azure-client-secret-key').val()
      };
    },

    /**
     * Display validation results.
     */
    displayValidationResults: function (errors, warnings) {
      // Remove existing validation messages
      $('.dataverse-validation-message').remove();

      var $container = $('#edit-third-party-settings-dataverse-webform');
      
      if (errors.length > 0) {
        var $errorDiv = $('<div class="messages messages--error dataverse-validation-message">')
          .append('<h3>Configuration Errors:</h3>')
          .append('<ul><li>' + errors.join('</li><li>') + '</li></ul>');
        $container.prepend($errorDiv);
      }

      if (warnings.length > 0) {
        var $warningDiv = $('<div class="messages messages--warning dataverse-validation-message">')
          .append('<h3>Configuration Warnings:</h3>')
          .append('<ul><li>' + warnings.join('</li><li>') + '</li></ul>');
        $container.prepend($warningDiv);
      }

      if (errors.length === 0 && warnings.length === 0) {
        var $successDiv = $('<div class="messages messages--status dataverse-validation-message">')
          .append('✅ Configuration appears valid');
        $container.prepend($successDiv);
      }
    },

    /**
     * Check if a string is a valid GUID.
     */
    isValidGuid: function (guid) {
      var guidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
      return guidRegex.test(guid);
    },

    /**
     * Show a temporary message.
     */
    showMessage: function (message, type) {
      var $message = $('<div class="messages messages--' + type + ' dataverse-temp-message">')
        .text(message)
        .hide()
        .fadeIn();

      $('#field-mapping-wrapper').prepend($message);

      setTimeout(function () {
        $message.fadeOut(function () {
          $message.remove();
        });
      }, 5000);
    },

    /**
     * Highlight form elements with validation issues.
     */
    highlightValidationIssues: function (issues) {
      // Remove existing highlights
      $('.form-item').removeClass('has-error has-warning');

      issues.forEach(function (issue) {
        var $element = $('#' + issue.field_id);
        var $formItem = $element.closest('.form-item');
        
        if (issue.severity === 'error') {
          $formItem.addClass('has-error');
        } else if (issue.severity === 'warning') {
          $formItem.addClass('has-warning');
        }
      });
    }
  };

  /**
   * AJAX command to update field suggestions.
   */
  Drupal.AjaxCommands.prototype.dataverseUpdateSuggestions = function (ajax, response, status) {
    var $target = $(response.selector);
    $target.html(response.data);
    
    // Re-attach behaviors
    Drupal.attachBehaviors($target[0]);
  };

  /**
   * AJAX command to show validation results.
   */
  Drupal.AjaxCommands.prototype.dataverseShowValidation = function (ajax, response, status) {
    if (response.validation_results) {
      Drupal.dataverseAdmin.displayValidationResults(
        response.validation_results.errors || [],
        response.validation_results.warnings || []
      );
    }
  };

})(jQuery, Drupal, drupalSettings);