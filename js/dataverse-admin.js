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

      // Initialize tooltips
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
      var $row = $('[data-row-index="' + rowIndex + '"]');
      var entitySelect = $row.find('select[name*="[entity]"]');
      var fieldSelect = $row.find('select[name*="[field]"]');
      var transformSelect = $row.find('select[name*="[transform]"]');
      
      var entityName = entitySelect.val();
      if (!entityName) {
        Drupal.dataverseAdmin.showMessage('Please select an entity first.', 'warning');
        return;
      }

      var $button = $('[data-webform-field="' + webformField + '"][data-row-index="' + rowIndex + '"]');
      var originalText = $button.val();
      $button.val('Mapping...').prop('disabled', true);

      var suggestions = Drupal.dataverseAdmin.getFieldSuggestions(webformField, entityName);
      
      if (suggestions.length > 0) {
        var bestMatch = suggestions[0];
        fieldSelect.val(bestMatch.field);
        
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

      setTimeout(function () {
        $button.val(originalText).prop('disabled', false);
      }, 1000);
    },

    /**
     * Load field suggestions for a specific entity.
     */
    loadFieldSuggestions: function (entityName, webformField, rowIndex) {
      console.log('Loading field suggestions for entity:', entityName, 'field:', webformField);
    },

    /**
     * Get field mapping suggestions based on field names.
     */
    getFieldSuggestions: function (webformField, entityName) {
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

      var transformMappings = [
        {pattern: 'email', transform: 'email'},
        {pattern: 'phone|telephone', transform: 'phone'},
        {pattern: 'date', transform: 'date'},
        {pattern: 'url|website', transform: 'url'},
        {pattern: '^(is|has|do)', transform: 'boolean'}
      ];

      for (var i = 0; i < transformMappings.length; i++) {
        var mapping = transformMappings[i];
        var regex = new RegExp(mapping.pattern);
        if (regex.test(webformLower) || regex.test(dataverseLower)) {
          return mapping.transform;
        }
      }

      return 'string';
    },

    /**
     * Initialize configuration validation.
     */
    initConfigValidation: function (context) {
      var $form = $('#webform-admin-form', context);
      var validationTimer;

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

      var errors = [];
      var warnings = [];

      var validationRules = [
        {field: 'azure_tenant_id', required: true, pattern: /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i, message: 'Azure Tenant ID must be in GUID format'},
        {field: 'dataverse_url', required: true, pattern: /^https:\/\//, message: 'Dataverse URL should use HTTPS'},
        {field: 'azure_client_id_key', required: true, message: 'Azure Client ID Key is required'},
        {field: 'azure_client_secret_key', required: true, message: 'Azure Client Secret Key is required'}
      ];

      validationRules.forEach(function(rule) {
        if (rule.required && !config[rule.field]) {
          errors.push(rule.message || rule.field + ' is required');
        } else if (config[rule.field] && rule.pattern && !rule.pattern.test(config[rule.field])) {
          if (rule.field === 'dataverse_url') {
            warnings.push(rule.message);
          } else {
            errors.push(rule.message);
          }
        }
      });

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