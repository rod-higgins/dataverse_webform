<?php

namespace Drupal\dataverse_webform\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dataverse_webform\DataverseClientInterface;
use Drupal\dataverse_webform\ConfigurationManager;
use Drupal\dataverse_webform\Exception\DataverseException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for testing Dataverse connection with multi-entity support.
 */
class DataverseTestForm extends FormBase {

  /**
   * The Dataverse client service.
   */
  protected DataverseClientInterface $dataverseClient;

  /**
   * The configuration manager service.
   */
  protected ConfigurationManager $configManager;

  /**
   * Constructs a DataverseTestForm object.
   *
   * @param \Drupal\dataverse_webform\DataverseClientInterface $dataverse_client
   *   The Dataverse client service.
   * @param \Drupal\dataverse_webform\ConfigurationManager $config_manager
   *   The configuration manager service.
   */
  public function __construct(
    DataverseClientInterface $dataverse_client,
    ConfigurationManager $config_manager
  ) {
    $this->dataverseClient = $dataverse_client;
    $this->configManager = $config_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('dataverse_webform.dataverse_client'),
      $container->get('dataverse_webform.config_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dataverse_webform_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Use this form to test your Dataverse connection and Azure AD authentication. This enhanced version supports multi-entity operations.') . '</p>',
    ];

    // Azure AD Configuration
    $form['azure_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure AD Configuration'),
      '#open' => true,
    ];

    $form['azure_config']['azure_tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure Tenant ID'),
      '#description' => $this->t('Your Azure AD tenant ID (GUID format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)'),
      '#required' => true,
      '#pattern' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
      '#attributes' => [
        'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
      ],
    ];

    // Get available keys
    $key_options = $this->configManager->getAzureCredentialKeys();

    $form['azure_config']['azure_client_id_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure Client ID Key'),
      '#description' => $this->t('Select the key containing your Azure AD application client ID'),
      '#options' => $key_options,
      '#required' => true,
    ];

    $form['azure_config']['azure_client_secret_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure Client Secret Key'),
      '#description' => $this->t('Select the key containing your Azure AD application client secret'),
      '#options' => $key_options,
      '#required' => true,
    ];

    // Dataverse Configuration
    $form['dataverse_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Dataverse Configuration'),
      '#open' => true,
    ];

    $form['dataverse_config']['dataverse_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Dataverse URL'),
      '#description' => $this->t('The base URL of your Dataverse instance (e.g., https://yourorg.crm.dynamics.com)'),
      '#required' => true,
      '#pattern' => 'https://.*',
      '#attributes' => [
        'placeholder' => 'https://yourorg.crm.dynamics.com',
      ],
    ];

    // Test Configuration
    $form['test_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Configuration'),
      '#open' => true,
    ];

    $form['test_config']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request Timeout (seconds)'),
      '#description' => $this->t('Maximum time to wait for API responses during testing'),
      '#default_value' => 30,
      '#min' => 5,
      '#max' => 300,
    ];

    $form['test_config']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#description' => $this->t('Number of entities to process in batch tests'),
      '#default_value' => 5,
      '#min' => 1,
      '#max' => 20,
    ];

    // Test Results Container
    $form['test_results'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'test-results-wrapper'],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Connection'),
      '#submit' => ['::testConnection'],
      '#ajax' => [
        'callback' => '::ajaxTestCallback',
        'wrapper' => 'test-results-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Testing connection...'),
        ],
      ],
    ];

    $form['actions']['test_entities'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Entity Retrieval'),
      '#submit' => ['::testEntities'],
      '#ajax' => [
        'callback' => '::ajaxTestCallback',
        'wrapper' => 'test-results-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Retrieving entities...'),
        ],
      ],
    ];

    $form['actions']['test_fields'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Field Retrieval'),
      '#submit' => ['::testFields'],
      '#ajax' => [
        'callback' => '::ajaxTestCallback',
        'wrapper' => 'test-results-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Retrieving fields...'),
        ],
      ],
    ];

    $form['actions']['test_multi_entity'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Multi-Entity Operations'),
      '#submit' => ['::testMultiEntity'],
      '#ajax' => [
        'callback' => '::ajaxTestCallback',
        'wrapper' => 'test-results-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Testing multi-entity operations...'),
        ],
      ],
    ];

    $form['actions']['validate_config'] = [
      '#type' => 'submit',
      '#value' => $this->t('Validate Configuration'),
      '#submit' => ['::validateConfiguration'],
      '#ajax' => [
        'callback' => '::ajaxTestCallback',
        'wrapper' => 'test-results-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Validating configuration...'),
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Default submit handler - not used
  }

  /**
   * Test connection submit handler.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    $config = $this->buildConfig($form_state);

    try {
      $result = $this->dataverseClient->testConnection($config);
      
      if ($result) {
        $this->messenger()->addStatus($this->t('✅ Connection to Dataverse successful! Azure AD authentication is working properly.'));
      } else {
        $this->messenger()->addError($this->t('❌ Failed to connect to Dataverse. Please check your configuration and credentials.'));
      }
    } catch (DataverseException $e) {
      $this->messenger()->addError($this->t('❌ Connection test failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Test entities retrieval submit handler.
   */
  public function testEntities(array &$form, FormStateInterface $form_state): void {
    $config = $this->buildConfig($form_state);

    try {
      $entities = $this->dataverseClient->getEntities($config);
      
      if (!empty($entities)) {
        $count = count($entities);
        $entity_names = array_slice(array_column($entities, 'display_name'), 0, 5);
        $entity_list = implode(', ', $entity_names);
        if ($count > 5) {
          $entity_list .= $this->t(' and @more more', ['@more' => $count - 5]);
        }
        
        $this->messenger()->addStatus($this->t('✅ Successfully retrieved @count entities from Dataverse. Examples: @entities', [
          '@count' => $count,
          '@entities' => $entity_list,
        ]));
      } else {
        $this->messenger()->addWarning($this->t('⚠️ No entities retrieved from Dataverse. This may indicate permission restrictions or configuration issues.'));
      }
    } catch (DataverseException $e) {
      $this->messenger()->addError($this->t('❌ Entity retrieval failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Test field retrieval submit handler.
   */
  public function testFields(array &$form, FormStateInterface $form_state): void {
    $config = $this->buildConfig($form_state);

    try {
      // Test field retrieval for common entities
      $test_entities = ['contacts', 'accounts', 'leads', 'opportunities'];
      $success_count = 0;
      $total_fields = 0;

      foreach ($test_entities as $entity_name) {
        try {
          $fields = $this->dataverseClient->getEntityFields($config, $entity_name);
          if (!empty($fields)) {
            $success_count++;
            $total_fields += count($fields);
          }
        } catch (DataverseException $e) {
          // Log but continue with other entities
          $this->getLogger('dataverse_webform')->warning(
            'Failed to retrieve fields for @entity: @error',
            ['@entity' => $entity_name, '@error' => $e->getMessage()]
          );
        }
      }

      if ($success_count > 0) {
        $this->messenger()->addStatus($this->t('✅ Successfully retrieved fields from @count/@total entities (total @fields fields)', [
          '@count' => $success_count,
          '@total' => count($test_entities),
          '@fields' => $total_fields,
        ]));
      } else {
        $this->messenger()->addError($this->t('❌ Failed to retrieve fields from any test entities. Please check entity permissions.'));
      }
    } catch (DataverseException $e) {
      $this->messenger()->addError($this->t('❌ Field retrieval test failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Test multi-entity operations submit handler.
   */
  public function testMultiEntity(array &$form, FormStateInterface $form_state): void {
    $config = $this->buildConfig($form_state);

    try {
      // Test validation of field mappings
      $test_mappings = [
        [
          'webform_field' => 'test_field_1',
          'entity' => 'contacts',
          'field' => 'firstname',
          'transform' => 'string',
          'required' => false,
        ],
        [
          'webform_field' => 'test_field_2',
          'entity' => 'accounts',
          'field' => 'name',
          'transform' => 'string',
          'required' => false,
        ],
      ];

      $validation_results = $this->dataverseClient->validateFieldMappings($config, $test_mappings);
      
      $valid_mappings = 0;
      foreach ($validation_results as $result) {
        if ($result['entity_exists'] && $result['field_exists']) {
          $valid_mappings++;
        }
      }

      if ($valid_mappings === count($test_mappings)) {
        $this->messenger()->addStatus($this->t('✅ Multi-entity validation successful! All test entities and fields are accessible.'));
      } else {
        $this->messenger()->addWarning($this->t('⚠️ Multi-entity validation partially successful. @valid/@total test mappings are valid.', [
          '@valid' => $valid_mappings,
          '@total' => count($test_mappings),
        ]));
      }

      // Test configuration validation
      $config_results = $this->configManager->validateConfiguration($config);
      if ($config_results['valid']) {
        $this->messenger()->addStatus($this->t('✅ Configuration validation passed.'));
      } else {
        $this->messenger()->addError($this->t('❌ Configuration validation failed: @errors', [
          '@errors' => implode(', ', $config_results['errors']),
        ]));
      }

    } catch (DataverseException $e) {
      $this->messenger()->addError($this->t('❌ Multi-entity test failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Validate configuration submit handler.
   */
  public function validateConfiguration(array &$form, FormStateInterface $form_state): void {
    $config = $this->buildConfig($form_state);

    try {
      $results = $this->configManager->validateConfiguration($config);

      if ($results['valid']) {
        $this->messenger()->addStatus($this->t('✅ Configuration is valid and ready for use.'));
      } else {
        foreach ($results['errors'] as $error) {
          $this->messenger()->addError($this->t('❌ @error', ['@error' => $error]));
        }
      }

      foreach ($results['warnings'] as $warning) {
        $this->messenger()->addWarning($this->t('⚠️ @warning', ['@warning' => $warning]));
      }

      // Key validation results
      foreach ($results['key_validation'] as $key_type => $key_result) {
        if ($key_result['exists'] && $key_result['has_value']) {
          $this->messenger()->addStatus($this->t('✅ Key for @type is valid', ['@type' => $key_type]));
        } elseif ($key_result['exists'] && !$key_result['has_value']) {
          $this->messenger()->addError($this->t('❌ Key for @type exists but has no value', ['@type' => $key_type]));
        } else {
          $this->messenger()->addError($this->t('❌ Key for @type does not exist', ['@type' => $key_type]));
        }
      }

    } catch (DataverseException $e) {
      $this->messenger()->addError($this->t('❌ Configuration validation failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Ajax callback for test operations.
   */
  public function ajaxTestCallback(array &$form, FormStateInterface $form_state): array {
    return $form['test_results'];
  }

  /**
   * Build configuration array from form values.
   */
  protected function buildConfig(FormStateInterface $form_state): array {
    return [
      'enabled' => true,
      'dataverse_url' => $form_state->getValue('dataverse_url'),
      'azure_tenant_id' => $form_state->getValue('azure_tenant_id'),
      'azure_client_id_key' => $form_state->getValue('azure_client_id_key'),
      'azure_client_secret_key' => $form_state->getValue('azure_client_secret_key'),
      'timeout' => (int) $form_state->getValue('timeout'),
      'batch_size' => (int) $form_state->getValue('batch_size'),
    ];
  }

}