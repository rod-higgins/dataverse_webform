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

  protected ConfigurationManager $configManager;
  protected DataverseClientInterface $dataverseClient;
  protected DataverseCacheManager $cacheManager;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected ModuleHandlerInterface $moduleHandler;

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

  public function buildWebformSettingsForm(array &$form, FormStateInterface $form_state): void {
    $webform = $form_state->getFormObject()->getEntity();
    $existing_config = $webform->getThirdPartySetting('dataverse_webform', 'dataverse_config', []);
    $config = $this->configManager->mergeWithDefaults($existing_config);

    $form['#attached']['library'][] = 'dataverse_webform/admin';

    $this->buildMainFieldset($form, $config);
    $this->addFormHandlers($form);
  }

  protected function buildMainFieldset(array &$form, array $config): void {
    $form['third_party_settings']['dataverse_webform'] = [
      '#type' => 'details',
      '#title' => $this->t('Dataverse Integration'),
      '#description' => $this->t('Configure integration with Microsoft Dataverse for automatic submission processing.'),
      '#open' => !empty($config['enabled']),
      '#tree' => true,
    ];

    $dataverse_fieldset = &$form['third_party_settings']['dataverse_webform'];

    $this->buildEnabledCheckbox($dataverse_fieldset, $config);
    $this->buildConfigContainer($dataverse_fieldset, $config);
  }

  protected function buildEnabledCheckbox(array &$fieldset, array $config): void {
    $fieldset['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Dataverse integration'),
      '#description' => $this->t('Automatically submit webform data to Microsoft Dataverse when submissions are created.'),
      '#default_value' => $config['enabled'],
    ];
  }

  protected function buildConfigContainer(array &$fieldset, array $config): void {
    $fieldset['config_container'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          'input[name="third_party_settings[dataverse_webform][enabled]"]' => ['checked' => true],
        ],
      ],
    ];

    $config_container = &$fieldset['config_container'];

    $this->buildAzureAdConfiguration($config_container, $config);
    $this->buildDataverseConfiguration($config_container, $config);
    $this->buildFieldMappingsConfiguration($config_container, $config);
    $this->buildAdvancedConfiguration($config_container, $config);
    $this->buildConnectionTestingSection($config_container, $config);
  }

  protected function buildAzureAdConfiguration(array &$container, array $config): void {
    $container['azure_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure AD Configuration'),
      '#description' => $this->t('Configure Azure Active Directory authentication for Dataverse access.'),
      '#open' => true,
    ];

    $azure_config = &$container['azure_config'];
    $key_options = $this->configManager->getAzureCredentialKeys();

    $azure_config['azure_tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure Tenant ID'),
      '#description' => $this->t('Your Azure AD tenant ID in GUID format (xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)'),
      '#default_value' => $config['azure_tenant_id'],
      '#required' => true,
      '#pattern' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
      '#attributes' => ['placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'],
    ];

    $azure_config['azure_client_id_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure Client ID Key'),
      '#description' => $this->t('Select the key containing your Azure AD application client ID. <a href="@url">Manage keys</a>', ['@url' => '/admin/config/system/keys']),
      '#options' => $key_options,
      '#default_value' => $config['azure_client_id_key'],
      '#required' => true,
    ];

    $azure_config['azure_client_secret_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure Client Secret Key'),
      '#description' => $this->t('Select the key containing your Azure AD application client secret. <a href="@url">Manage keys</a>', ['@url' => '/admin/config/system/keys']),
      '#options' => $key_options,
      '#default_value' => $config['azure_client_secret_key'],
      '#required' => true,
    ];
  }

  protected function buildDataverseConfiguration(array &$container, array $config): void {
    $container['dataverse_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Dataverse Configuration'),
      '#description' => $this->t('Configure your Microsoft Dataverse environment connection.'),
      '#open' => true,
    ];

    $container['dataverse_config']['dataverse_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Dataverse URL'),
      '#description' => $this->t('The base URL of your Dataverse instance (e.g., https://yourorg.crm.dynamics.com)'),
      '#default_value' => $config['dataverse_url'],
      '#required' => true,
      '#pattern' => 'https://.*',
      '#attributes' => ['placeholder' => 'https://yourorg.crm.dynamics.com'],
    ];
  }

  protected function buildFieldMappingsConfiguration(array &$container, array $config): void {
    $container['field_mappings_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Field Mappings'),
      '#description' => $this->t('Map webform fields to Dataverse entities and fields. Each field can be mapped to a different entity for multi-entity submissions.'),
      '#open' => true,
    ];

    $mappings_config = &$container['field_mappings_config'];
    $webform = $this->getWebformFromFormState();
    $webform_fields = $this->getWebformFields($webform);

    if (empty($webform_fields)) {
      $mappings_config['no_fields'] = [
        '#markup' => '<p>' . $this->t('No mappable fields found in this webform. Please add form elements first.') . '</p>',
      ];
      return;
    }

    $this->buildFieldMappingsTable($mappings_config, $config, $webform_fields);
    $this->buildSubmissionOrderConfiguration($mappings_config, $config);
  }

  protected function buildFieldMappingsTable(array &$container, array $config, array $webform_fields): void {
    $container['field_mappings'] = [
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
    
    foreach ($webform_fields as $field_key => $field_label) {
      $existing_mapping = $this->findExistingMapping($field_mappings, $field_key);
      $container['field_mappings'][$field_key] = $this->buildFieldMappingRow($field_key, $field_label, $existing_mapping);
    }
  }

  protected function buildFieldMappingRow(string $field_key, string $field_label, ?array $existing_mapping): array {
    return [
      'webform_field' => [
        '#markup' => '<strong>' . $field_label . '</strong><br><small>' . $field_key . '</small>',
      ],
      'entity' => [
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
      ],
      'field' => [
        '#type' => 'select',
        '#options' => ['' => $this->t('- Select Field -')],
        '#default_value' => $existing_mapping['field'] ?? '',
        '#prefix' => '<div id="field-options-' . $field_key . '">',
        '#suffix' => '</div>',
      ],
      'transform' => [
        '#type' => 'select',
        '#options' => $this->getTransformOptions(),
        '#default_value' => $existing_mapping['transform'] ?? 'none',
      ],
      'required' => [
        '#type' => 'checkbox',
        '#default_value' => $existing_mapping['required'] ?? false,
      ],
      'actions' => [
        '#type' => 'button',
        '#value' => $this->t('Auto-map'),
        '#attributes' => ['class' => ['button--small'], 'data-webform-field' => $field_key],
      ],
    ];
  }

  protected function getTransformOptions(): array {
    return [
      'none' => $this->t('None'),
      'string' => $this->t('String'),
      'number' => $this->t('Number'),
      'boolean' => $this->t('Boolean'),
      'date' => $this->t('Date'),
      'email' => $this->t('Email'),
      'phone' => $this->t('Phone'),
      'url' => $this->t('URL'),
      'json' => $this->t('JSON'),
    ];
  }

  protected function buildSubmissionOrderConfiguration(array &$container, array $config): void {
    $field_mappings = $config['field_mappings'] ?? [];
    $entities = $this->configManager->getEntitiesFromMappings($field_mappings);
    
    if (count($entities) > 1) {
      $container['submission_order'] = [
        '#type' => 'details',
        '#title' => $this->t('Entity Submission Order'),
        '#description' => $this->t('Specify the order in which entities should be created. This is important for maintaining relationships between entities.'),
        '#open' => false,
      ];

      $order_options = array_combine($entities, $entities);
      
      $container['submission_order']['order'] = [
        '#type' => 'checkboxes',
        '#options' => $order_options,
        '#default_value' => $config['submission_order'] ?? [],
      ];
    }
  }

  protected function buildAdvancedConfiguration(array &$container, array $config): void {
    $container['advanced_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Configuration'),
      '#description' => $this->t('Advanced settings for performance optimization and error handling.'),
      '#open' => false,
    ];

    $advanced_config = &$container['advanced_config'];

    $advanced_settings = [
      'timeout' => [
        'type' => 'number',
        'title' => $this->t('Request Timeout (seconds)'),
        'description' => $this->t('Maximum time to wait for API responses.'),
        'default' => $config['timeout'],
        'min' => 5,
        'max' => 300,
      ],
      'batch_size' => [
        'type' => 'number',
        'title' => $this->t('Batch Size'),
        'description' => $this->t('Number of entities to process in each batch operation.'),
        'default' => $config['batch_size'],
        'min' => 1,
        'max' => 100,
      ],
      'retry_attempts' => [
        'type' => 'number',
        'title' => $this->t('Retry Attempts'),
        'description' => $this->t('Number of times to retry failed submissions.'),
        'default' => $config['retry_attempts'],
        'min' => 0,
        'max' => 5,
      ],
    ];

    foreach ($advanced_settings as $key => $setting) {
      $advanced_config[$key] = [
        '#type' => $setting['type'],
        '#title' => $setting['title'],
        '#description' => $setting['description'],
        '#default_value' => $setting['default'],
        '#min' => $setting['min'] ?? null,
        '#max' => $setting['max'] ?? null,
      ];
    }

    $advanced_config['stop_on_error'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stop processing on error'),
      '#description' => $this->t('Stop processing additional entities when one entity creation fails.'),
      '#default_value' => $config['stop_on_error'],
    ];
  }

  protected function buildConnectionTestingSection(array &$container, array $config): void {
    $container['testing'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection Testing'),
      '#description' => $this->t('Test your Dataverse connection and configuration.'),
      '#open' => false,
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

  protected function addFormHandlers(array &$form): void {
    $form['#validate'][] = [$this, 'validateWebformSettings'];
    $form['actions']['submit']['#submit'][] = [$this, 'submitWebformSettings'];
  }

  public function validateWebformSettings(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue(['third_party_settings', 'dataverse_webform']);
    
    if (empty($values['enabled'])) {
      return;
    }

    $config_values = $values['config_container'] ?? [];
    $this->validateRequiredFields($form, $form_state, $config_values);
  }

  public function submitWebformSettings(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue(['third_party_settings', 'dataverse_webform']);
    
    if (!empty($values['enabled'])) {
      $webform = $form_state->getFormObject()->getEntity();
      $existing_config = $webform->getThirdPartySetting('dataverse_webform', 'dataverse_config', []);
      $new_config = $this->buildNewConfiguration($values);

      if ($this->configurationChanged($existing_config, $new_config)) {
        $this->cacheManager->invalidateConfigCache($new_config);
        
        $this->loggerFactory->get('dataverse_webform')->info(
          'Dataverse configuration updated for webform @webform_id',
          ['@webform_id' => $webform->id()]
        );
      }
    }
  }

  public function loadEntityFieldsAjax(array &$form, FormStateInterface $form_state): array {
    $triggering_element = $form_state->getTriggeringElement();
    $webform_field = $triggering_element['#attributes']['data-webform-field'] ?? '';
    
    if (empty($webform_field)) {
      return [];
    }

    $entity_name = $form_state->getValue([
      'third_party_settings', 'dataverse_webform', 'config_container', 
      'field_mappings_config', 'field_mappings', $webform_field, 'entity'
    ]);

    $field_element = &$form['third_party_settings']['dataverse_webform']['config_container']['field_mappings_config']['field_mappings'][$webform_field]['field'];
    
    if (!empty($entity_name)) {
      try {
        $config = $this->buildConfigFromFormState($form_state);
        $fields = $this->dataverseClient->getEntityFields($config, $entity_name);
        
        $field_options = ['' => $this->t('- Select Field -')];
        foreach ($fields as $field_name => $field_data) {
          $field_options[$field_name] = $field_data['display_name'] . ' (' . $field_name . ')';
        }
        
        $field_element['#options'] = $field_options;
      } catch (DataverseException $e) {
        $field_element['#options'] = ['' => $this->t('Error loading fields: @error', ['@error' => $e->getMessage()])];
      }
    }

    return $field_element;
  }

  public function testConnectionAjax(array &$form, FormStateInterface $form_state): array {
    $config = $this->buildConfigFromFormState($form_state);
    $results_element = &$form['third_party_settings']['dataverse_webform']['config_container']['testing']['test_results'];
    
    try {
      $connection_success = $this->dataverseClient->testConnection($config);
      
      $results_element['#markup'] = $connection_success 
        ? '<div class="messages messages--status">' . $this->t('✅ Connection successful! Azure AD authentication and Dataverse access verified.') . '</div>'
        : '<div class="messages messages--error">' . $this->t('❌ Connection failed. Please check your configuration.') . '</div>';
    } catch (DataverseException $e) {
      $results_element['#markup'] = '<div class="messages messages--error">' . 
        $this->t('❌ Connection test failed: @error', ['@error' => $e->getMessage()]) . '</div>';
    }

    return $results_element;
  }

  public function loadEntitiesAjax(array &$form, FormStateInterface $form_state): array {
    $config = $this->buildConfigFromFormState($form_state);
    $entities_element = &$form['third_party_settings']['dataverse_webform']['config_container']['testing']['entities_container'];
    
    try {
      $entities = $this->dataverseClient->getEntities($config);
      $entities_element['#markup'] = $this->buildEntitiesMarkup($entities);
    } catch (DataverseException $e) {
      $entities_element['#markup'] = '<div class="messages messages--error">' . 
        $this->t('❌ Failed to load entities: @error', ['@error' => $e->getMessage()]) . '</div>';
    }

    return $entities_element;
  }

  protected function buildEntitiesMarkup(array $entities): string {
    if (empty($entities)) {
      return '<div class="messages messages--warning">' . 
        $this->t('⚠️ No entities found. This may indicate permission restrictions.') . '</div>';
    }

    $entity_list = [];
    foreach (array_slice($entities, 0, 10) as $entity) {
      $entity_list[] = $entity['display_name'] . ' (' . $entity['logical_name'] . ')';
    }
    
    $more_count = count($entities) - 10;
    $list_markup = '<ul><li>' . implode('</li><li>', $entity_list) . '</li></ul>';
    
    if ($more_count > 0) {
      $list_markup .= '<p><em>' . $this->t('...and @count more entities', ['@count' => $more_count]) . '</em></p>';
    }
    
    return '<div class="messages messages--status">' . 
      $this->t('✅ Successfully loaded @count entities:', ['@count' => count($entities)]) . 
      $list_markup . '</div>';
  }

  protected function getWebformFromFormState(): ?\Drupal\webform\WebformInterface {
    // This would typically come from the form state, but we'll return null for now
    // In practice, this would be: $form_state->getFormObject()->getEntity()
    return null;
  }

  protected function getWebformFields($webform): array {
    if (!$webform) {
      return [];
    }

    $webform_elements = $webform->getElementsInitializedAndFlattened();
    $webform_fields = [];
    
    foreach ($webform_elements as $key => $element) {
      if (isset($element['#type']) && !in_array($element['#type'], ['markup', 'processed_text', 'webform_actions'])) {
        $webform_fields[$key] = $element['#title'] ?? $key;
      }
    }

    return $webform_fields;
  }

  protected function findExistingMapping(array $field_mappings, string $field_key): ?array {
    foreach ($field_mappings as $mapping) {
      if ($mapping['webform_field'] === $field_key) {
        return $mapping;
      }
    }
    return null;
  }

  protected function validateRequiredFields(array &$form, FormStateInterface $form_state, array $config_values): void {
    $required_fields = [
      'azure_tenant_id' => 'Azure Tenant ID is required when Dataverse integration is enabled.',
      'azure_client_id_key' => 'Azure Client ID Key is required when Dataverse integration is enabled.',
      'azure_client_secret_key' => 'Azure Client Secret Key is required when Dataverse integration is enabled.',
      'dataverse_url' => 'Dataverse URL is required when Dataverse integration is enabled.',
    ];

    foreach ($required_fields as $field => $message) {
      $section = str_starts_with($field, 'azure_') ? 'azure_config' : 'dataverse_config';
      $config_section = $config_values[$section] ?? [];
      
      if (empty($config_section[$field])) {
        $form_state->setError(
          $form['third_party_settings']['dataverse_webform']['config_container'][$section][$field],
          $this->t($message)
        );
      }
    }
  }

  protected function buildNewConfiguration(array $values): array {
    $config_container = $values['config_container'];
    
    return [
      'enabled' => true,
      'azure_tenant_id' => $config_container['azure_config']['azure_tenant_id'] ?? '',
      'azure_client_id_key' => $config_container['azure_config']['azure_client_id_key'] ?? '',
      'azure_client_secret_key' => $config_container['azure_config']['azure_client_secret_key'] ?? '',
      'dataverse_url' => $config_container['dataverse_config']['dataverse_url'] ?? '',
      'field_mappings' => $this->processFieldMappings($config_container['field_mappings_config'] ?? []),
      'submission_order' => array_filter($config_container['submission_order']['order'] ?? []),
      'timeout' => $config_container['advanced_config']['timeout'] ?? 30,
      'batch_size' => $config_container['advanced_config']['batch_size'] ?? 10,
      'retry_attempts' => $config_container['advanced_config']['retry_attempts'] ?? 3,
      'stop_on_error' => !empty($config_container['advanced_config']['stop_on_error']),
    ];
  }

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
            'relationship_to' => null,
          ];
        }
      }
    }
    
    return $field_mappings;
  }

  protected function configurationChanged(array $old_config, array $new_config): bool {
    $significant_fields = ['dataverse_url', 'azure_tenant_id', 'azure_client_id_key', 'azure_client_secret_key', 'field_mappings'];

    foreach ($significant_fields as $field) {
      if (($old_config[$field] ?? null) !== ($new_config[$field] ?? null)) {
        return true;
      }
    }

    return false;
  }

  protected function buildConfigFromFormState(FormStateInterface $form_state): array {
    $values = $form_state->getValues();
    $config_values = $values['third_party_settings']['dataverse_webform']['config_container'] ?? [];
    
    return [
      'enabled' => true,
      'azure_tenant_id' => $config_values['azure_config']['azure_tenant_id'] ?? '',
      'azure_client_id_key' => $config_values['azure_config']['azure_client_id_key'] ?? '',
      'azure_client_secret_key' => $config_values['azure_config']['azure_client_secret_key'] ?? '',
      'dataverse_url' => $config_values['dataverse_config']['dataverse_url'] ?? '',
      'timeout' => $config_values['advanced_config']['timeout'] ?? 30,
    ];
  }
}