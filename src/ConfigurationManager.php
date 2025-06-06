<?php

namespace Drupal\dataverse_webform;

use Drupal\key\KeyRepositoryInterface;

/**
 * Service for managing Dataverse configuration.
 */
class ConfigurationManager {

  /**
   * The key repository service.
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * The validation service.
   */
  protected ValidationService $validator;

  /**
   * Constructs a ConfigurationManager object.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   * @param \Drupal\dataverse_webform\ValidationService $validator
   *   The validation service.
   */
  public function __construct(
    KeyRepositoryInterface $key_repository,
    ValidationService $validator
  ) {
    $this->keyRepository = $key_repository;
    $this->validator = $validator;
  }

  /**
   * Get available Azure credential keys.
   *
   * @return array
   *   Array of key options for form elements.
   */
  public function getAzureCredentialKeys(): array {
    $key_options = ['' => t('- Select a key -')];
    $keys = $this->keyRepository->getKeys();
    
    foreach ($keys as $key_id => $key) {
      $key_options[$key_id] = $key->label() . ' (' . $key_id . ')';
    }

    return $key_options;
  }

  public function getKeyValue(string $key_id): ?string {
    if (empty($key_id)) {
        return NULL;
    }

    if (!$this->validateKeyAccess($key_id)) {
        throw new DataverseException("Access denied for key: {$key_id}");
    }

    try {
        $key = $this->keyRepository->getKey($key_id);
        if (!$key) {
        return NULL;
        }

        $value = $key->getKeyValue();
        return !empty($value) ? $value : NULL;
        
    } catch (\Exception $e) {
        $this->loggerFactory->get('dataverse_webform')->error(
        'Failed to retrieve key @key: @error',
        ['@key' => $key_id, '@error' => $e->getMessage()]
        );
        return NULL;
    }
  }


  /**
   * Validate that required keys exist and have values.
   *
   * @param array $config
   *   The configuration to validate.
   *
   * @return array
   *   Array of validation results.
   */
  public function validateRequiredKeys(array $config): array {
    $results = [
      'client_id_key' => ['exists' => false, 'has_value' => false],
      'client_secret_key' => ['exists' => false, 'has_value' => false],
    ];

    // Validate client ID key
    if (!empty($config['azure_client_id_key'])) {
      $client_id_key = $this->keyRepository->getKey($config['azure_client_id_key']);
      $results['client_id_key']['exists'] = $client_id_key !== null;
      
      if ($client_id_key) {
        $client_id_value = $client_id_key->getKeyValue();
        $results['client_id_key']['has_value'] = !empty($client_id_value);
      }
    }

    // Validate client secret key
    if (!empty($config['azure_client_secret_key'])) {
      $client_secret_key = $this->keyRepository->getKey($config['azure_client_secret_key']);
      $results['client_secret_key']['exists'] = $client_secret_key !== null;
      
      if ($client_secret_key) {
        $client_secret_value = $client_secret_key->getKeyValue();
        $results['client_secret_key']['has_value'] = !empty($client_secret_value);
      }
    }

    return $results;
  }

  /**
   * Get default configuration values.
   *
   * @return array
   *   Default configuration array.
   */
  public function getDefaultConfiguration(): array {
    return [
      'enabled' => false,
      'azure_tenant_id' => '',
      'azure_client_id_key' => '',
      'azure_client_secret_key' => '',
      'dataverse_url' => '',
      'field_mappings' => [],
      'submission_order' => [],
      'batch_size' => 10,
      'retry_attempts' => 3,
      'timeout' => 30,
      'stop_on_error' => true,
    ];
  }

  /**
   * Merge user configuration with defaults.
   *
   * @param array $user_config
   *   User-provided configuration.
   *
   * @return array
   *   Merged configuration with defaults.
   */
  public function mergeWithDefaults(array $user_config): array {
    return array_merge($this->getDefaultConfiguration(), $user_config);
  }

  /**
   * Convert old single-entity field mapping to new multi-entity format.
   *
   * @param array $old_config
   *   Old configuration format.
   *
   * @return array
   *   New configuration format.
   */
  public function convertLegacyConfiguration(array $old_config): array {
    $new_config = $this->getDefaultConfiguration();

    // Copy basic settings
    $basic_settings = [
      'enabled',
      'azure_tenant_id',
      'azure_client_id_key',
      'azure_client_secret_key',
      'dataverse_url',
    ];

    foreach ($basic_settings as $setting) {
      if (isset($old_config[$setting])) {
        $new_config[$setting] = $old_config[$setting];
      }
    }

    // Convert old field mapping format
    if (!empty($old_config['field_mapping']) && !empty($old_config['target_entity'])) {
      $target_entity = $old_config['target_entity'];
      $field_mappings = [];

      foreach ($old_config['field_mapping'] as $webform_field => $dataverse_field) {
        if (!empty($dataverse_field)) {
          $field_mappings[] = [
            'webform_field' => $webform_field,
            'entity' => $target_entity,
            'field' => $dataverse_field,
            'transform' => 'none',
            'required' => false,
            'relationship_to' => null,
          ];
        }
      }

      $new_config['field_mappings'] = $field_mappings;
      $new_config['submission_order'] = [$target_entity];
    }

    return $new_config;
  }

  /**
   * Extract unique entities from field mappings.
   *
   * @param array $field_mappings
   *   The field mappings array.
   *
   * @return array
   *   Array of unique entity names.
   */
  public function getEntitiesFromMappings(array $field_mappings): array {
    $entities = [];
    
    foreach ($field_mappings as $mapping) {
      if (!empty($mapping['entity'])) {
        $entities[] = $mapping['entity'];
      }
    }

    return array_unique($entities);
  }

  /**
   * Group field mappings by entity.
   *
   * @param array $field_mappings
   *   The field mappings array.
   *
   * @return array
   *   Field mappings grouped by entity name.
   */
  public function groupMappingsByEntity(array $field_mappings): array {
    $grouped = [];
    
    foreach ($field_mappings as $mapping) {
      $entity = $mapping['entity'] ?? '';
      if (!empty($entity)) {
        $grouped[$entity][] = $mapping;
      }
    }

    return $grouped;
  }

  protected function validateKeyAccess(string $key_id): bool {
    if (empty($key_id)) {
        return FALSE;
    }
    
    $current_user = \Drupal::currentUser();
    if (!$current_user->hasPermission('administer dataverse webform')) {
        return FALSE;
    }
    
    $key = $this->keyRepository->getKey($key_id);
    return $key !== NULL;
  }

  protected function performValidation(array $config): array {
    $results = [
        'valid' => TRUE,
        'errors' => [],
        'warnings' => [],
        'key_validation' => [],
    ];

    try {
        $this->validator->validateConfig($config);
    } catch (\Exception $e) {
        $results['valid'] = FALSE;
        $results['errors'][] = $e->getMessage();
    }

    // Validate keys exist and have values
    $key_results = $this->validateRequiredKeys($config);
    $results['key_validation'] = $key_results;

    foreach ($key_results as $key_type => $key_result) {
        if (!$key_result['exists']) {
        $results['errors'][] = "Key for {$key_type} does not exist";
        $results['valid'] = FALSE;
        } elseif (!$key_result['has_value']) {
        $results['errors'][] = "Key for {$key_type} has no value";
        $results['valid'] = FALSE;
        }
    }

    return $results;
  }

  /**
   * Validate configuration and return detailed results.
   *
   * @param array $config
   *   The configuration to validate.
   *
   * @return array
   *   Detailed validation results.
   */
  public function validateConfiguration(array $config): array {
    $results = [
      'valid' => true,
      'errors' => [],
      'warnings' => [],
      'key_validation' => [],
    ];

    try {
      $this->validator->validateConfig($config);
    } catch (\Exception $e) {
      $results['valid'] = false;
      $results['errors'][] = $e->getMessage();
    }

    // Validate keys
    $key_results = $this->validateRequiredKeys($config);
    $results['key_validation'] = $key_results;

    foreach ($key_results as $key_type => $key_result) {
      if (!$key_result['exists']) {
        $results['errors'][] = "Key for {$key_type} does not exist";
        $results['valid'] = false;
      } elseif (!$key_result['has_value']) {
        $results['errors'][] = "Key for {$key_type} has no value";
        $results['valid'] = false;
      }
    }

    // Check for potential issues
    if (!empty($config['field_mappings'])) {
      $entities = $this->getEntitiesFromMappings($config['field_mappings']);
      
      if (empty($config['submission_order'])) {
        $results['warnings'][] = 'No submission order specified. Entities will be processed in random order.';
      } elseif (count($entities) !== count($config['submission_order'])) {
        $results['warnings'][] = 'Submission order does not include all entities from field mappings.';
      }
    }

    return $results;
  }

}