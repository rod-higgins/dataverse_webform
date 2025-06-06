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

  public const DEFAULT_TIMEOUT = 30;
  public const DEFAULT_BATCH_SIZE = 5;
  public const MIN_TIMEOUT = 5;
  public const MAX_TIMEOUT = 300;
  public const MIN_BATCH_SIZE = 1;
  public const MAX_BATCH_SIZE = 20;
  public const TEST_ENTITIES = ['contacts', 'accounts', 'leads', 'opportunities'];

  protected DataverseClientInterface $dataverseClient;
  protected ConfigurationManager $configManager;

  public function __construct(DataverseClientInterface $dataverse_client, ConfigurationManager $config_manager) {
    $this->dataverseClient = $dataverse_client;
    $this->configManager = $config_manager;
  }

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
      '#markup' => '<p>' . $this->t('Use this form to test your Dataverse connection and Azure AD authentication.') . '</p>',
    ];

    $this->buildAzureAdSection($form);
    $this->buildDataverseSection($form);
    $this->buildTestSection($form);
    $this->buildActionsSection($form);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Default submit handler - not used for AJAX operations.
  }

  /**
   * Test connection handler.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    $this->runTest(function($config) {
      $result = $this->dataverseClient->testConnection($config);
      return $result ? 'Connection successful!' : 'Connection failed.';
    }, 'Connection', $form_state);
  }

  /**
   * Test entities handler.
   */
  public function testEntities(array &$form, FormStateInterface $form_state): void {
    $this->runTest(function($config) {
      $entities = $this->dataverseClient->getEntities($config);
      return $this->formatEntitiesResult($entities);
    }, 'Entity retrieval', $form_state);
  }

  /**
   * Test fields handler.
   */
  public function testFields(array &$form, FormStateInterface $form_state): void {
    $this->runTest(function($config) {
      return $this->testFieldsForEntities($config, self::TEST_ENTITIES);
    }, 'Field retrieval', $form_state);
  }

  /**
   * Test multi-entity operations handler.
   */
  public function testMultiEntity(array &$form, FormStateInterface $form_state): void {
    $this->runTest(function($config) {
      $test_mappings = $this->getTestMappings();
      $validation_results = $this->dataverseClient->validateFieldMappings($config, $test_mappings);
      return $this->formatValidationResults($validation_results, count($test_mappings));
    }, 'Multi-entity test', $form_state);
  }

  /**
   * Validate configuration handler.
   */
  public function validateConfiguration(array &$form, FormStateInterface $form_state): void {
    $config = $this->buildConfig($form_state);

    try {
      $results = $this->configManager->validateConfiguration($config);
      $this->displayValidationResults($results);
    } catch (DataverseException $e) {
      $this->messenger()->addError($this->t('❌ Configuration validation failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * AJAX callback for test results.
   */
  public function ajaxTestCallback(array &$form, FormStateInterface $form_state): array {
    return $form['test_results'];
  }

  /**
   * Build Azure AD configuration section.
   */
  protected function buildAzureAdSection(array &$form): void {
    $form['azure_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure AD Configuration'),
      '#open' => true,
    ];

    $key_options = $this->configManager->getAzureCredentialKeys();

    $form['azure_config']['azure_tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure Tenant ID'),
      '#pattern' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
      '#attributes' => ['placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'],
      '#required' => true,
    ];

    $form['azure_config']['azure_client_id_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure Client ID Key'),
      '#options' => $key_options,
      '#required' => true,
    ];

    $form['azure_config']['azure_client_secret_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure Client Secret Key'),
      '#options' => $key_options,
      '#required' => true,
    ];
  }

  /**
   * Build Dataverse configuration section.
   */
  protected function buildDataverseSection(array &$form): void {
    $form['dataverse_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Dataverse Configuration'),
      '#open' => true,
    ];

    $form['dataverse_config']['dataverse_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Dataverse URL'),
      '#required' => true,
      '#pattern' => 'https://.*',
      '#attributes' => ['placeholder' => 'https://yourorg.crm.dynamics.com'],
    ];
  }

  /**
   * Build test configuration section.
   */
  protected function buildTestSection(array &$form): void {
    $form['test_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Configuration'),
      '#open' => true,
    ];

    $form['test_config']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request Timeout (seconds)'),
      '#default_value' => self::DEFAULT_TIMEOUT,
      '#min' => self::MIN_TIMEOUT,
      '#max' => self::MAX_TIMEOUT,
    ];

    $form['test_config']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => self::DEFAULT_BATCH_SIZE,
      '#min' => self::MIN_BATCH_SIZE,
      '#max' => self::MAX_BATCH_SIZE,
    ];

    $form['test_results'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'test-results-wrapper'],
    ];
  }

  /**
   * Build actions section with test buttons.
   */
  protected function buildActionsSection(array &$form): void {
    $form['actions'] = ['#type' => 'actions'];

    $test_actions = [
      'test_connection' => 'Test Connection',
      'test_entities' => 'Test Entity Retrieval',
      'test_fields' => 'Test Field Retrieval',
      'test_multi_entity' => 'Test Multi-Entity Operations',
      'validate_config' => 'Validate Configuration',
    ];

    foreach ($test_actions as $action => $label) {
      $form['actions'][$action] = [
        '#type' => 'submit',
        '#value' => $this->t($label),
        '#submit' => [[$this, $this->convertActionToMethod($action)]],
        '#ajax' => [
          'callback' => '::ajaxTestCallback',
          'wrapper' => 'test-results-wrapper',
          'progress' => ['type' => 'throbber', 'message' => $this->t('Testing...')],
        ],
      ];
    }
  }

  /**
   * Run test function with error handling.
   */
  protected function runTest(callable $test_function, string $test_name, FormStateInterface $form_state): void {
    $config = $this->buildConfig($form_state);

    try {
      $result = $test_function($config);
      $this->messenger()->addStatus($this->t('✅ @test_name: @result', [
        '@test_name' => $test_name,
        '@result' => $result
      ]));
    } catch (DataverseException $e) {
      $this->messenger()->addError($this->t('❌ @test_name failed: @error', [
        '@test_name' => $test_name,
        '@error' => $e->getMessage()
      ]));
    }
  }

  /**
   * Format entities retrieval result.
   */
  protected function formatEntitiesResult(array $entities): string {
    $count = count($entities);
    
    if ($count > 0) {
      $examples = array_slice(array_column($entities, 'display_name'), 0, 5);
      $entity_list = implode(', ', $examples);
      if ($count > 5) {
        $entity_list .= $this->t(' and @more more', ['@more' => $count - 5]);
      }
      return "Successfully retrieved {$count} entities. Examples: {$entity_list}";
    }
    
    return 'No entities retrieved. This may indicate permission restrictions.';
  }

  /**
   * Test fields for multiple entities.
   */
  protected function testFieldsForEntities(array $config, array $test_entities): string {
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
        \Drupal::logger('dataverse_webform')->warning(
          'Failed to retrieve fields for @entity: @error',
          ['@entity' => $entity_name, '@error' => $e->getMessage()]
        );
      }
    }

    if ($success_count > 0) {
      return "Successfully retrieved fields from {$success_count}/" . count($test_entities) . " entities (total {$total_fields} fields)";
    }
    
    return 'Failed to retrieve fields from any test entities. Please check entity permissions.';
  }

  /**
   * Get test field mappings.
   */
  protected function getTestMappings(): array {
    return [
      ['webform_field' => 'test_field_1', 'entity' => 'contacts', 'field' => 'firstname', 'transform' => 'string', 'required' => false],
      ['webform_field' => 'test_field_2', 'entity' => 'accounts', 'field' => 'name', 'transform' => 'string', 'required' => false],
    ];
  }

  /**
   * Format validation results.
   */
  protected function formatValidationResults(array $validation_results, int $total_mappings): string {
    $valid_mappings = array_reduce($validation_results, function($count, $result) {
      return $count + ($result['entity_exists'] && $result['field_exists'] ? 1 : 0);
    }, 0);

    if ($valid_mappings === $total_mappings) {
      return 'Multi-entity validation successful! All test entities and fields are accessible.';
    }
    
    return "Multi-entity validation partially successful. {$valid_mappings}/{$total_mappings} test mappings are valid.";
  }

  /**
   * Display validation results.
   */
  protected function displayValidationResults(array $results): void {
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

    foreach ($results['key_validation'] as $key_type => $key_result) {
      $status = $key_result['exists'] && $key_result['has_value'] ? 'valid' : 'invalid';
      $icon = $status === 'valid' ? '✅' : '❌';
      $message = $this->t('@icon Key for @type is @status', [
        '@icon' => $icon,
        '@type' => $key_type,
        '@status' => $status
      ]);
      
      if ($status === 'valid') {
        $this->messenger()->addStatus($message);
      } else {
        $this->messenger()->addError($message);
      }
    }
  }

  /**
   * Build configuration from form state.
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

  /**
   * Convert action name to method name.
   */
  protected function convertActionToMethod(string $action): string {
    // Convert snake_case to camelCase for method names.
    $parts = explode('_', $action);
    $method = array_shift($parts);
    foreach ($parts as $part) {
      $method .= ucfirst($part);
    }
    return $method;
  }

}