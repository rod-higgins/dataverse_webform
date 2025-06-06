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
      this.initializeAutoMapping(context);
      this.initializeEntityFieldLoading(context);
      this.initializeConfigValidation(context);
      this.initializeTooltips(context);
    },

    initializeAutoMapping: function (context) {
      $('.button[data-webform-field]', context)
        .once('dataverse-auto-map')
        .on('click', this.handleAutoMapClick);
    },

    initializeEntityFieldLoading: function (context) {
      $('.field-mapping-entity-select', context)
        .once('dataverse-entity-change')
        .on('change', this.handleEntityChange);
    },

    initializeConfigValidation: function (context) {
      if ($('#edit-third-party-settings-dataverse-webform-enabled', context).length) {
        Drupal.dataverseAdmin.initConfigValidation(context);
      }
    },

    initializeTooltips: function (context) {
      $('.field-info-tooltip', context)
        .once('dataverse-tooltip')
        .tooltip({
          placement: 'top',
          trigger: 'hover'
        });
    },

    handleAutoMapClick: function (e) {
      e.preventDefault();
      const $button = $(this);
      const webformField = $button.data('webform-field');
      const rowIndex = $button.data('row-index');
      
      Drupal.dataverseAdmin.autoMapField(webformField, rowIndex);
    },

    handleEntityChange: function () {
      const $select = $(this);
      const entityName = $select.val();
      const webformField = $select.data('webform-field');
      const rowIndex = $select.data('row-index');
      
      if (entityName && webformField) {
        Drupal.dataverseAdmin.loadFieldSuggestions(entityName, webformField, rowIndex);
      }
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
      const $row = this.getRowElement(rowIndex);
      const entitySelect = $row.find('select[name*="[entity]"]');
      const fieldSelect = $row.find('select[name*="[field]"]');
      const transformSelect = $row.find('select[name*="[transform]"]');
      
      const entityName = entitySelect.val();
      if (!entityName) {
        this.showMessage('Please select an entity first.', 'warning');
        return;
      }

      this.setButtonLoadingState(webformField, rowIndex, true);

      const suggestions = this.getFieldSuggestions(webformField, entityName);
      
      if (suggestions.length > 0) {
        this.applyBestSuggestion(suggestions[0], fieldSelect, transformSelect, webformField);
      } else {
        this.showMessage(`No suitable mapping found for "${webformField}"`, 'warning');
      }

      this.setButtonLoadingState(webformField, rowIndex, false);
    },

    /**
     * Get row element by index or webform field.
     */
    getRowElement: function (identifier) {
      return typeof identifier === 'number' 
        ? $('[data-row-index="' + identifier + '"]')
        : $('[data-webform-field="' + identifier + '"]').closest('tr');
    },

    /**
     * Set loading state for auto-map button.
     */
    setButtonLoadingState: function (webformField, rowIndex, isLoading) {
      const $button = $('[data-webform-field="' + webformField + '"]');
      const originalText = $button.data('original-text') || $button.val();
      
      if (isLoading) {
        $button.data('original-text', originalText)
               .val('Mapping...')
               .prop('disabled', true);
      } else {
        setTimeout(() => {
          $button.val(originalText).prop('disabled', false);
        }, 1000);
      }
    },

    /**
     * Apply the best field suggestion.
     */
    applyBestSuggestion: function (suggestion, fieldSelect, transformSelect, webformField) {
      fieldSelect.val(suggestion.field);
      
      const suggestedTransform = this.suggestTransform(webformField, suggestion.field);
      if (suggestedTransform) {
        transformSelect.val(suggestedTransform);
      }
      
      this.showMessage(
        `Auto-mapped "${webformField}" to "${suggestion.field}" (${suggestion.confidence}% confidence)`,
        'status'
      );
    },

    /**
     * Load field suggestions for a specific entity.
     */
    loadFieldSuggestions: function (entityName, webformField, rowIndex) {
      console.log('Loading field suggestions for entity:', entityName, 'field:', webformField);
      // This would typically make an AJAX request to load suggestions
      // For now, we'll just log the action
    },

    /**
     * Get field mapping suggestions based on field names.
     */
    getFieldSuggestions: function (webformField, entityName) {
      const commonMappings = this.getCommonMappings();
      const webformLower = webformField.toLowerCase();
      const suggestions = [];

      // Check for exact mappings
      if (commonMappings[webformLower]) {
        suggestions.push(...this.createSuggestionsFromMapping(commonMappings[webformLower], 'Common mapping pattern'));
      }

      // Check for partial matches
      Object.keys(commonMappings).forEach(pattern => {
        if (webformLower.indexOf(pattern) !== -1 && !commonMappings[webformLower]) {
          const mapping = commonMappings[pattern];
          suggestions.push(...this.createSuggestionsFromMapping(mapping, 'Partial name match', -20));
        }
      });

      return suggestions.sort((a, b) => b.confidence - a.confidence);
    },

    /**
     * Get common field mappings configuration.
     */
    getCommonMappings: function () {
      return {
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
    },

    /**
     * Create suggestions from mapping configuration.
     */
    createSuggestionsFromMapping: function (mapping, reason, confidenceAdjustment = 0) {
      return mapping.targets.map(target => ({
        field: target,
        confidence: mapping.confidence + confidenceAdjustment,
        reason: reason
      }));
    },

    /**
     * Suggest appropriate transform type based on field names.
     */
    suggestTransform: function (webformField, dataverseField) {
      const webformLower = webformField.toLowerCase();
      const dataverseLower = dataverseField.toLowerCase();

      const transformMappings = [
        { pattern: 'email', transform: 'email' },
        { pattern: 'phone|telephone', transform: 'phone' },
        { pattern: 'date', transform: 'date' },
        { pattern: 'url|website', transform: 'url' },
        { pattern: '^(is|has|do)', transform: 'boolean' }
      ];

      for (const mapping of transformMappings) {
        const regex = new RegExp(mapping.pattern);
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
      const $form = $('#webform-admin-form', context);
      let validationTimer;

      $form.find('input, select', context)
           .once('dataverse-validation')
           .on('change blur', () => {
             clearTimeout(validationTimer);
             validationTimer = setTimeout(() => this.validateConfiguration(), 1000);
           });
    },

    /**
     * Validate current configuration.
     */
    validateConfiguration: function () {
      const config = this.gatherConfiguration();
      
      if (!config.enabled) {
        return;
      }

      const { errors, warnings } = this.runValidationRules(config);
      this.displayValidationResults(errors, warnings);
    },

    /**
     * Run validation rules against configuration.
     */
    runValidationRules: function (config) {
      const errors = [];
      const warnings = [];

      const validationRules = [
        {
          field: 'azure_tenant_id',
          required: true,
          pattern: /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
          message: 'Azure Tenant ID must be in GUID format'
        },
        {
          field: 'dataverse_url',
          required: true,
          pattern: /^https:\/\//,
          message: 'Dataverse URL should use HTTPS'
        },
        {
          field: 'azure_client_id_key',
          required: true,
          message: 'Azure Client ID Key is required'
        },
        {
          field: 'azure_client_secret_key',
          required: true,
          message: 'Azure Client Secret Key is required'
        }
      ];

      validationRules.forEach(rule => {
        if (rule.required && !config[rule.field]) {
          errors.push(rule.message || `${rule.field} is required`);
        } else if (config[rule.field] && rule.pattern && !rule.pattern.test(config[rule.field])) {
          if (rule.field === 'dataverse_url') {
            warnings.push(rule.message);
          } else {
            errors.push(rule.message);
          }
        }
      });

      return { errors, warnings };
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
      this.clearValidationMessages();

      const $container = $('#edit-third-party-settings-dataverse-webform');
      
      if (errors.length > 0) {
        $container.prepend(this.createValidationMessage('error', 'Configuration Errors:', errors));
      }

      if (warnings.length > 0) {
        $container.prepend(this.createValidationMessage('warning', 'Configuration Warnings:', warnings));
      }

      if (errors.length === 0 && warnings.length === 0) {
        $container.prepend(this.createValidationMessage('status', '', ['✅ Configuration appears valid']));
      }
    },

    /**
     * Create validation message element.
     */
    createValidationMessage: function (type, title, messages) {
      const $message = $('<div class="messages messages--' + type + ' dataverse-validation-message">');
      
      if (title) {
        $message.append('<h3>' + title + '</h3>');
      }
      
      if (messages.length > 1) {
        $message.append('<ul><li>' + messages.join('</li><li>') + '</li></ul>');
      } else {
        $message.append(messages[0]);
      }
      
      return $message;
    },

    /**
     * Clear existing validation messages.
     */
    clearValidationMessages: function () {
      $('.dataverse-validation-message').remove();
    },

    /**
     * Show a temporary message.
     */
    showMessage: function (message, type) {
      const $message = $('<div class="messages messages--' + type + ' dataverse-temp-message">')
        .text(message)
        .hide()
        .fadeIn();

      $('#field-mapping-wrapper').prepend($message);

      setTimeout(() => {
        $message.fadeOut(() => $message.remove());
      }, 5000);
    },

    /**
     * Highlight form elements with validation issues.
     */
    highlightValidationIssues: function (issues) {
      $('.form-item').removeClass('has-error has-warning');

      issues.forEach(issue => {
        const $element = $('#' + issue.field_id);
        const $formItem = $element.closest('.form-item');
        
        $formItem.addClass(issue.severity === 'error' ? 'has-error' : 'has-warning');
      });
    }
  };

  /**
   * AJAX command to update field suggestions.
   */
  Drupal.AjaxCommands.prototype.dataverseUpdateSuggestions = function (ajax, response, status) {
    const $target = $(response.selector);
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