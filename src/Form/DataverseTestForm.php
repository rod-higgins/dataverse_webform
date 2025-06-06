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

  public function getFormId(): string {
    return 'dataverse_webform_test_form';
  }

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

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Default submit handler - not used
  }

  public function testConnection(array &$form, FormStateInterface $form_state): void {
    $this->runTest(function($config) {
      $result = $this->dataverseClient->testConnection($config);
      return $result ? 'Connection successful!' : 'Connection failed.';
    }, 'Connection', $form_state);
  }

  public function testEntities(array &$form, FormStateInterface $form_state): void {
    $this->runTest(function($config) {
      $entities = $this->dataverseClient->getEntities($config);
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
    }, 'Entity retrieval', $form_state);
  }

  public function testFields(array &$form, FormStateInterface $form_state): void {
    $this->runTest(function($config) {
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
    }, 'Field retrieval', $form_state);
  }

  public function testMultiEntity(array &$form, FormStateInterface $form_state): void {
    $this->runTest(function($config) {
      $test_mappings = [
        ['webform_field' => 'test_field_1', 'entity' => 'contacts', 'field' => 'firstname', 'transform' => 'string', 'required' => false],
        ['webform_field' => 'test_field_2', 'entity' => 'accounts', 'field' => 'name', 'transform' => 'string', 'required' => false],
      ];

      $validation_results = $this->dataverseClient->validateFieldMappings($config, $test_mappings);
      
      $valid_mappings = array_reduce($validation_results, function($count, $result) {
        return $count + ($result['entity_exists'] && $result['field_exists'] ? 1 : 0);
      }, 0);

      $total_mappings = count($test_mappings);
      
      if ($valid_mappings === $total_mappings) {
        return 'Multi-entity validation successful! All test entities and fields are accessible.';
      }
      
      return "Multi-entity validation partially successful. {$valid_mappings}/{$total_mappings} test mappings are valid.";
    }, 'Multi-entity test', $form_state);
  }

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

      foreach ($results['key_validation'] as $key_type => $key_result) {
        $status = $key_result['exists'] && $key_result['has_value'] ? 'valid' : 'invalid';
        $icon = $status === 'valid' ? '✅' : '❌';
        $message = $this->t('@icon Key for @type is @status', ['@icon' => $icon, '@type' => $key_type, '@status' => $status]);
        
        if ($status === 'valid') {
          $this->messenger()->addStatus($message);
        } else {
          $this->messenger()->addError($message);
        }
      }
    } catch (DataverseException $e) {
      $this->messenger()->addError($this->t('❌ Configuration validation failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  public function ajaxTestCallback(array &$form, FormStateInterface $form_state): array {
    return $form['test_results'];
  }

  protected function buildAzureAdSection(array &$form): void {
    $form['azure_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure AD Configuration'),
      '#open' => true,
    ];

    $key_options = $this->configManager->getAzureCredentialKeys();

    $azure_fields = [
      'azure_tenant_id' => [
        '#type' => 'textfield',
        '#title' => $this->t('Azure Tenant ID'),
        '#pattern' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        '#attributes' => ['placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'],
      ],
      'azure_client_id_key' => [
        '#type' => 'select',
        '#title' => $this->t('Azure Client ID Key'),
        '#options' => $key_options,
      ],
      'azure_client_secret_key' => [
        '#type' => 'select',
        '#title' => $this->t('Azure Client Secret Key'),
        '#options' => $key_options,
      ],
    ];

    foreach ($azure_fields as $key => $field) {
      $form['azure_config'][$key] = $field + ['#required' => TRUE];
    }
  }

  protected function buildDataverseSection(array &$form): void {
    $form['dataverse_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Dataverse Configuration'),
      '#open' => true,
    ];

    $form['dataverse_config']['dataverse_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Dataverse URL'),
      '#required' => TRUE,
      '#pattern' => 'https://.*',
      '#attributes' => ['placeholder' => 'https://yourorg.crm.dynamics.com'],
    ];
  }

  protected function buildTestSection(array &$form): void {
    $form['test_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Configuration'),
      '#open' => true,
    ];

    $test_fields = [
      'timeout' => ['#type' => 'number', '#title' => $this->t('Request Timeout (seconds)'), '#default_value' => 30, '#min' => 5, '#max' => 300],
      'batch_size' => ['#type' => 'number', '#title' => $this->t('Batch Size'), '#default_value' => 5, '#min' => 1, '#max' => 20],
    ];

    foreach ($test_fields as $key => $field) {
      $form['test_config'][$key] = $field;
    }

    $form['test_results'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'test-results-wrapper'],
    ];
  }

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
        '#submit' => [[$this, str_replace('_', '', ucwords($action, '_'))]],
        '#ajax' => [
          'callback' => '::ajaxTestCallback',
          'wrapper' => 'test-results-wrapper',
          'progress' => ['type' => 'throbber', 'message' => $this->t('Testing...')],
        ],
      ];
    }
  }

  protected function runTest(callable $test_function, string $test_name, FormStateInterface $form_state): void {
    $config = $this->buildConfig($form_state);

    try {
      $result = $test_function($config);
      $this->messenger()->addStatus($this->t('✅ @test_name: @result', ['@test_name' => $test_name, '@result' => $result]));
    } catch (DataverseException $e) {
      $this->messenger()->addError($this->t('❌ @test_name failed: @error', ['@test_name' => $test_name, '@error' => $e->getMessage()]));
    }
  }

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