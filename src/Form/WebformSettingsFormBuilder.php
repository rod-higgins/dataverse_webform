<?php

namespace Drupal\dataverse_webform\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dataverse_webform\ConfigurationManager;
use Drupal\dataverse_webform\DataverseClientInterface;
use Drupal\dataverse_webform\Cache\DataverseCacheManager;
use Drupal\dataverse_webform\Exception\DataverseException;

/**
 * Service for building webform settings form elements for Dataverse integration.
 */
class WebformSettingsFormBuilder {

  use StringTranslationTrait;

  /**
   * The configuration manager.
   */
  protected ConfigurationManager $configManager;

  /**
   * The Dataverse client.
   */
  protected DataverseClientInterface $dataverseClient;

  /**
   * The cache manager.
   */
  protected DataverseCacheManager $cacheManager;

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs a WebformSettingsFormBuilder object.
   *
   * @param \Drupal\dataverse_webform\ConfigurationManager $config_manager
   *   The configuration manager.
   * @param \Drupal\dataverse_webform\DataverseClientInterface $dataverse_client
   *   The Dataverse client.
   * @param \Drupal\dataverse_webform\Cache\DataverseCacheManager $cache_manager
   *   The cache manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    ConfigurationManager $config_manager,
    DataverseClientInterface $dataverse_client,
    DataverseCacheManager $cache_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ModuleHandlerInterface $module_handler
  ) {
    $this->configManager = $config_manager;
    $this->dataverseClient = $dataverse_client;
    $this->cacheManager = $cache_manager;
    $this->loggerFactory = $logger_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Build the webform settings form for Dataverse integration.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function buildWebformSettingsForm(array &$form, FormStateInterface $form_state): void {
    // Get the webform entity
    $webform = $form_state->getFormObject()->getEntity();
    $existing_config = $webform->getThirdPartySetting('dataverse_webform', 'dataverse_config', []);
    $config = $this->configManager->mergeWithDefaults($existing_config);

    // Add library for JavaScript enhancements
    $form['#attached']['library'][] = 'dataverse_webform/admin';

    // Main Dataverse integration fieldset
    $form['third_party_settings']['dataverse_webform'] = [
      '#type' => 'details',
      '#title' => $this->t('Dataverse Integration'),
      '#description' => $this->t('Configure integration with Microsoft Dataverse for automatic submission processing.'),
      '#open' => !empty($config['enabled']),
      '#tree' => TRUE,
    ];

    $dataverse_fieldset = &$form['third_party_settings']['dataverse_webform'];

    // Enable integration checkbox
    $dataverse_fieldset['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Dataverse integration'),
      '#description' => $this->t('Automatically submit webform data to Microsoft Dataverse when submissions are created.'),
      '#default_value' => $config['enabled'],
    ];

    // Configuration container (visible when enabled)
    $dataverse_fieldset['config_container'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          'input[name="third_party_settings[dataverse_webform][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $config_container = &$dataverse_fieldset['config_container'];

    // Azure AD Configuration
    $this->buildAzureAdConfiguration($config_container, $config);

    // Dataverse Configuration  
    $this->buildDataverseConfiguration($config_container, $config);

    // Field Mappings
    $this->buildFieldMappingsConfiguration($config_container, $config, $webform);

    // Advanced Configuration
    $this->buildAdvancedConfiguration($config_container, $config);

    // Connection Testing
    $this->buildConnectionTestingSection($config_container, $config);

    // Add custom validation and submit handlers
    $form['#validate'][] = [$this, 'validateWebformSettings'];
    $form['actions']['submit']['#submit'][] = [$this, 'submitWebformSettings'];
  }

  /**
   * Build Azure AD configuration section.
   *
   * @param array $container
   *   The container element.
   * @param array $config
   *   The current configuration.
   */
  protected function buildAzureAdConfiguration(array &$container, array $config): void {
    $container['azure_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure AD Configuration'),
      '#description' => $this->t('Configure Azure Active Directory authentication for Dataverse access.'),
      '#open' => TRUE,
    ];

    $azure_config = &$container['azure_config'];

    $azure_config['azure_tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure Tenant ID'),
      '#description' => $this->t('Your Azure AD tenant ID in GUID format (xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)'),
      '#default_value' => $config['azure_tenant_id'],
      '#required' => TRUE,
      '#pattern' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
      '#attributes' => [
        'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
      ],
    ];

    // Get available keys
    $key_options = $this->configManager->getAzureCredentialKeys();

    $azure_config['azure_client_id_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure Client ID Key'),
      '#description' => $this->t('Select the key containing your Azure AD application client ID. <a href="@url">Manage keys</a>', [
        '@url' => '/admin/config/system/keys',
      ]),
      '#options' => $key_options,
      '#default_value' => $config['azure_client_id_key'],
      '#required' => TRUE,
    ];

    $azure_config['azure_client_secret_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure Client Secret Key'),
      '#description' => $this->t('Select the key containing your Azure AD application client secret. <a href="@url">Manage keys</a>', [
        '@url' => '/admin/config/system/keys',
      ]),
      '#options' => $key_options,
      '#default_value' => $config['azure_client_secret_key'],
      '#required' => TRUE,
    ];
  }

  /**
   * Build Dataverse configuration section.
   *
   * @param array $container
   *   The container element.
   * @param array $config
   *   The current configuration.
   */
  protected function buildDataverseConfiguration(array &$container, array $config): void {
    $container['dataverse_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Dataverse Configuration'),
      '#description' => $this->t('Configure your Microsoft Dataverse environment connection.'),
      '#open' => TRUE,
    ];

    $dataverse_config = &$container['dataverse_config'];

    $dataverse_config['dataverse_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Dataverse URL'),
      '#description' => $this->t('The base URL of your Dataverse instance (e.g., https://yourorg.crm.dynamics.com)'),
      '#default_value' => $config['dataverse_url'],
      '#required' => TRUE,
      '#pattern' => 'https://.*',
      '#attributes' => [
        'placeholder' => 'https://yourorg.crm.dynamics.com',
      ],
    ];
  }

  /**
   * Build field mappings configuration section.
   *
   * @param array $container
   *   The container element.
   * @param array $config
   *   The current configuration.
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform entity.
   */
  protected function buildFieldMappingsConfiguration(array &$container, array $config, $webform): void {
    $container['field_mappings_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Field Mappings'),
      '#description' => $this->t('Map webform fields to Dataverse entities and fields. Each field can be mapped to a different entity for multi-entity submissions.'),
      '#open' => TRUE,
    ];

    $mappings_config = &$container['field_mappings_config'];

    // Get webform elements
    $webform_elements = $webform->getElementsInitializedAndFlattened();
    $webform_fields = [];
    
    foreach ($webform_elements as $key => $element) {
      if (isset($element['#type']) && !in_array($element['#type'], ['markup', 'processed_text', 'webform_actions'])) {
        $webform_fields[$key] = $element['#title'] ?? $key;
      }
    }

    if (empty($webform_fields)) {
      $mappings_config['no_fields'] = [
        '#markup' => '<p>' . $this->t('No mappable fields found in this webform. Please add form elements first.') . '</p>',
      ];
      return;
    }

    // Field mappings table
    $mappings_config['field_mappings'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Webform Field'),
        $this->t('Target Entity'),
        $this->t('Target Field'),
        $this->t('Transform'),
        $this->t('Required'),
        $this->t('Actions'),
      ],
      '#empty' => $this->t('No field mappings configured.'),
    ];

    $field_mappings = $config['field_mappings'] ?? [];
    
    // Add existing mappings
    foreach ($webform_fields as $field_key => $field_label) {
      $existing_mapping = NULL;
      foreach ($field_mappings as $mapping) {
        if ($mapping['webform_field'] === $field_key) {
          $existing_mapping = $mapping;
          break;
        }
      }

      $mappings_config['field_mappings'][$field_key] = $this->buildFieldMappingRow(
        $field_key,
        $field_label,
        $existing_mapping
      );
    }

    // Submission order configuration
    $this->buildSubmissionOrderConfiguration($mappings_config, $config, $field_mappings);
  }

  /**
   * Build a field mapping row.
   *
   * @param string $field_key
   *   The webform field key.
   * @param string $field_label
   *   The webform field label.
   * @param array|null $existing_mapping
   *   Existing mapping configuration.
   *
   * @return array
   *   The form row.
   */
  protected function buildFieldMappingRow(string $field_key, string $field_label, ?array $existing_mapping): array {
    $row = [];

    $row['webform_field'] = [
      '#markup' => '<strong>' . $field_label . '</strong><br><small>' . $field_key . '</small>',
    ];

    $row['entity'] = [
      '#type' => 'select',
      '#options' => ['' => $this->t('- Select Entity -')],
      '#default_value' => $existing_mapping['entity'] ?? '',
      '#attributes' => [
        'class' => ['field-mapping-entity-select'],
        'data-webform-field' => $field_key,
      ],
      '#ajax' => [
        'callback' => '::loadEntityFieldsAjax',
        'wrapper' => 'field-options-' . $field_key,
        'progress' => ['type' => 'throbber', 'message' => $this->t('Loading fields...')],
      ],
    ];

    $row['field'] = [
      '#type' => 'select',
      '#options' => ['' => $this->t('- Select Field -')],
      '#default_value' => $existing_mapping['field'] ?? '',
      '#prefix' => '<div id="field-options-' . $field_key . '">',
      '#suffix' => '</div>',
    ];

    $row['transform'] = [
      '#type' => 'select',
      '#options' => [
        'none' => $this->t('None'),
        'string' => $this->t('String'),
        'number' => $this->t('Number'),
        'boolean' => $this->t('Boolean'),
        'date' => $this->t('Date'),
        'email' => $this->t('Email'),
        'phone' => $this->t('Phone'),
        'url' => $this->t('URL'),
        'json' => $this->t('JSON'),
      ],
      '#default_value' => $existing_mapping['transform'] ?? 'none',
    ];

    $row['required'] = [
      '#type' => 'checkbox',
      '#default_value' => $existing_mapping['required'] ?? FALSE,
    ];

    $row['actions'] = [
      '#type' => 'button',
      '#value' => $this->t('Auto-map'),
      '#attributes' => [
        'class' => ['button--small'],
        'data-webform-field' => $field_key,
      ],
    ];

    return $row;
  }

  /**
   * Build submission order configuration.
   *
   * @param array $container
   *   The container element.
   * @param array $config
   *   The current configuration.
   * @param array $field_mappings
   *   The field mappings.
   */
  protected function buildSubmissionOrderConfiguration(array &$container, array $config, array $field_mappings): void {
    $entities = $this->configManager->getEntitiesFromMappings($field_mappings);
    
    if (count($entities) > 1) {
      $container['submission_order'] = [
        '#type' => 'details',
        '#title' => $this->t('Entity Submission Order'),
        '#description' => $this->t('Specify the order in which entities should be created. This is important for maintaining relationships between entities.'),
        '#open' => FALSE,
      ];

      $order_options = array_combine($entities, $entities);
      
      $container['submission_order']['order'] = [
        '#type' => 'checkboxes',
        '#options' => $order_options,
        '#default_value' => $config['submission_order'] ?? [],
      ];
    }
  }

  /**
   * Build advanced configuration section.
   *
   * @param array $container
   *   The container element.
   * @param array $config
   *   The current configuration.
   */
  protected function buildAdvancedConfiguration(array &$container, array $config): void {
    $container['advanced_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Configuration'),
      '#description' => $this->t('Advanced settings for performance optimization and error handling.'),
      '#open' => FALSE,
    ];

    $advanced_config = &$container['advanced_config'];

    $advanced_config['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request Timeout (seconds)'),
      '#description' => $this->t('Maximum time to wait for API responses.'),
      '#default_value' => $config['timeout'],
      '#min' => 5,
      '#max' => 300,
    ];

    $advanced_config['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#description' => $this->t('Number of entities to process in each batch operation.'),
      '#default_value' => $config['batch_size'],
      '#min' => 1,
      '#max' => 100,
    ];

    $advanced_config['retry_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Retry Attempts'),
      '#description' => $this->t('Number of times to retry failed submissions.'),
      '#default_value' => $config['retry_attempts'],
      '#min' => 0,
      '#max' => 5,
    ];

    $advanced_config['stop_on_error'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stop processing on error'),
      '#description' => $this->t('Stop processing additional entities when one entity creation fails.'),
      '#default_value' => $config['stop_on_error'],
    ];
  }

  /**
   * Build connection testing section.
   *
   * @param array $container
   *   The container element.
   * @param array $config
   *   The current configuration.
   */
  protected function buildConnectionTestingSection(array &$container, array $config): void {
    $container['testing'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection Testing'),
      '#description' => $this->t('Test your Dataverse connection and configuration.'),
      '#open' => FALSE,
    ];

    $testing = &$container['testing'];

    $testing['test_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Connection'),
      '#ajax' => [
        'callback' => '::testConnectionAjax',
        'wrapper' => 'connection-test-results',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Testing connection...')],
      ],
    ];

    $testing['load_entities'] = [
      '#type' => 'button',
      '#value' => $this->t('Load Available Entities'),
      '#ajax' => [
        'callback' => '::loadEntitiesAjax',
        'wrapper' => 'entities-list',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Loading entities...')],
      ],
    ];

    $testing['test_results'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'connection-test-results'],
    ];

    $testing['entities_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'entities-list'],
    ];
  }

  /**
   * Validate webform settings form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateWebformSettings(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue(['third_party_settings', 'dataverse_webform']);
    
    if (empty($values['enabled'])) {
      return;
    }

    $config_values = $values['config_container'] ?? [];

    try {
      // Validate Azure AD configuration
      $azure_config = $config_values['azure_config'] ?? [];
      
      if (empty($azure_config['azure_tenant_id'])) {
        $form_state->setError(
          $form['third_party_settings']['dataverse_webform']['config_container']['azure_config']['azure_tenant_id'],
          $this->t('Azure Tenant ID is required when Dataverse integration is enabled.')
        );
      }

      if (empty($azure_config['azure_client_id_key'])) {
        $form_state->setError(
          $form['third_party_settings']['dataverse_webform']['config_container']['azure_config']['azure_client_id_key'],
          $this->t('Azure Client ID Key is required when Dataverse integration is enabled.')
        );
      }

      if (empty($azure_config['azure_client_secret_key'])) {
        $form_state->setError(
          $form['third_party_settings']['dataverse_webform']['config_container']['azure_config']['azure_client_secret_key'],
          $this->t('Azure Client Secret Key is required when Dataverse integration is enabled.')
        );
      }

      // Validate Dataverse configuration
      $dataverse_config = $config_values['dataverse_config'] ?? [];
      
      if (empty($dataverse_config['dataverse_url'])) {
        $form_state->setError(
          $form['third_party_settings']['dataverse_webform']['config_container']['dataverse_config']['dataverse_url'],
          $this->t('Dataverse URL is required when Dataverse integration is enabled.')
        );
      }

      // Additional validation would go here...

    } catch (DataverseException $e) {
      $form_state->setErrorByName('third_party_settings][dataverse_webform', $this->t('Configuration validation failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Submit handler for webform settings form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitWebformSettings(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue(['third_party_settings', 'dataverse_webform']);
    
    if (!empty($values['enabled'])) {
      // Clear cache when configuration changes
      $webform = $form_state->getFormObject()->getEntity();
      $existing_config = $webform->getThirdPartySetting('dataverse_webform', 'dataverse_config', []);
      
      // Build new configuration
      $new_config = [
        'enabled' => TRUE,
        'azure_tenant_id' => $values['config_container']['azure_config']['azure_tenant_id'] ?? '',
        'azure_client_id_key' => $values['config_container']['azure_config']['azure_client_id_key'] ?? '',
        'azure_client_secret_key' => $values['config_container']['azure_config']['azure_client_secret_key'] ?? '',
        'dataverse_url' => $values['config_container']['dataverse_config']['dataverse_url'] ?? '',
        'field_mappings' => $this->processFieldMappings($values['config_container']['field_mappings_config'] ?? []),
        'submission_order' => array_filter($values['config_container']['submission_order']['order'] ?? []),
        'timeout' => $values['config_container']['advanced_config']['timeout'] ?? 30,
        'batch_size' => $values['config_container']['advanced_config']['batch_size'] ?? 10,
        'retry_attempts' => $values['config_container']['advanced_config']['retry_attempts'] ?? 3,
        'stop_on_error' => !empty($values['config_container']['advanced_config']['stop_on_error']),
      ];

      // Check if configuration changed significantly
      if ($this->configurationChanged($existing_config, $new_config)) {
        $this->cacheManager->invalidateConfigCache($new_config);
        
        $this->loggerFactory->get('dataverse_webform')->info(
          'Dataverse configuration updated for webform @webform_id',
          ['@webform_id' => $webform->id()]
        );
      }
    }
  }

  /**
   * Process field mappings from form values.
   *
   * @param array $mappings_data
   *   The field mappings form data.
   *
   * @return array
   *   Processed field mappings.
   */
  protected function processFieldMappings(array $mappings_data): array {
    $field_mappings = [];
    
    if (isset($mappings_data['field_mappings'])) {
      foreach ($mappings_data['field_mappings'] as $webform_field => $mapping) {
        if (!empty($mapping['entity']) && !empty($mapping['field'])) {
          $field_mappings[] = [
            'webform_field' => $webform_field,
            'entity' => $mapping['entity'],
            'field' => $mapping['field'],
            'transform' => $mapping['transform'] ?? 'none',
            'required' => !empty($mapping['required']),
            'relationship_to' => NULL, // Could be enhanced later
          ];
        }
      }
    }
    
    return $field_mappings;
  }

  /**
   * Check if configuration has changed significantly.
   *
   * @param array $old_config
   *   The old configuration.
   * @param array $new_config
   *   The new configuration.
   *
   * @return bool
   *   TRUE if configuration has changed significantly.
   */
  protected function configurationChanged(array $old_config, array $new_config): bool {
    $significant_fields = [
      'dataverse_url',
      'azure_tenant_id',
      'azure_client_id_key',
      'azure_client_secret_key',
      'field_mappings',
    ];

    foreach ($significant_fields as $field) {
      if (($old_config[$field] ?? NULL) !== ($new_config[$field] ?? NULL)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}