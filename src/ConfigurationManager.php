<?php

namespace Drupal\dataverse_webform;

use Drupal\key\KeyRepositoryInterface;
use Drupal\dataverse_webform\Exception\DataverseException;

/**
 * Service for managing Dataverse configuration.
 */
class ConfigurationManager {

  protected KeyRepositoryInterface $keyRepository;
  protected ValidationService $validator;

  public function __construct(KeyRepositoryInterface $key_repository, ValidationService $validator) {
    $this->keyRepository = $key_repository;
    $this->validator = $validator;
  }

  public function getAzureCredentialKeys(): array {
    $key_options = ['' => t('- Select a key -')];
    $keys = $this->keyRepository->getKeys();
    
    foreach ($keys as $key_id => $key) {
      $key_options[$key_id] = $key->label() . ' (' . $key_id . ')';
    }

    return $key_options;
  }

  public function getKeyValue(string $key_id): ?string {
    if (empty($key_id) || !$this->validateKeyAccess($key_id)) {
      return null;
    }

    try {
      $key = $this->keyRepository->getKey($key_id);
      if (!$key) {
        return null;
      }

      $value = $key->getKeyValue();
      return !empty($value) ? $value : null;
    } catch (\Exception $e) {
      \Drupal::logger('dataverse_webform')->error(
        'Failed to retrieve key @key: @error',
        ['@key' => $key_id, '@error' => $e->getMessage()]
      );
      return null;
    }
  }

  public function validateRequiredKeys(array $config): array {
    $results = [
      'client_id_key' => ['exists' => false, 'has_value' => false],
      'client_secret_key' => ['exists' => false, 'has_value' => false],
    ];

    $this->validateKey($config['azure_client_id_key'] ?? '', $results['client_id_key']);
    $this->validateKey($config['azure_client_secret_key'] ?? '', $results['client_secret_key']);

    return $results;
  }

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

  public function mergeWithDefaults(array $user_config): array {
    return array_merge($this->getDefaultConfiguration(), $user_config);
  }

  public function convertLegacyConfiguration(array $old_config): array {
    $new_config = $this->getDefaultConfiguration();

    // Copy basic settings
    $this->copyBasicSettings($old_config, $new_config);
    
    // Convert field mappings if present
    if ($this->hasLegacyFieldMappings($old_config)) {
      $this->convertLegacyFieldMappings($old_config, $new_config);
    }

    return $new_config;
  }

  public function getEntitiesFromMappings(array $field_mappings): array {
    $entities = [];
    foreach ($field_mappings as $mapping) {
      if (!empty($mapping['entity'])) {
        $entities[] = $mapping['entity'];
      }
    }
    return array_unique($entities);
  }

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

    $this->validateKeys($config, $results);
    $this->addConfigurationWarnings($config, $results);

    return $results;
  }

  protected function copyBasicSettings(array $old_config, array &$new_config): void {
    $basic_settings = [
      'enabled', 'azure_tenant_id', 'azure_client_id_key', 
      'azure_client_secret_key', 'dataverse_url'
    ];
    
    foreach ($basic_settings as $setting) {
      if (isset($old_config[$setting])) {
        $new_config[$setting] = $old_config[$setting];
      }
    }
  }

  protected function hasLegacyFieldMappings(array $old_config): bool {
    return !empty($old_config['field_mapping']) && !empty($old_config['target_entity']);
  }

  protected function convertLegacyFieldMappings(array $old_config, array &$new_config): void {
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

  protected function validateKey(string $key_id, array &$result): void {
    if (!empty($key_id)) {
      $key = $this->keyRepository->getKey($key_id);
      $result['exists'] = $key !== null;
      
      if ($key) {
        $value = $key->getKeyValue();
        $result['has_value'] = !empty($value);
      }
    }
  }

  protected function validateKeys(array $config, array &$results): void {
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
  }

  protected function validateKeyAccess(string $key_id): bool {
    if (empty($key_id)) {
      return false;
    }
    
    $current_user = \Drupal::currentUser();
    if (!$current_user->hasPermission('administer dataverse webform')) {
      return false;
    }
    
    return $this->keyRepository->getKey($key_id) !== null;
  }

  protected function addConfigurationWarnings(array $config, array &$results): void {
    if (!empty($config['field_mappings'])) {
      $entities = $this->getEntitiesFromMappings($config['field_mappings']);
      
      if (empty($config['submission_order'])) {
        $results['warnings'][] = 'No submission order specified. Entities will be processed in random order.';
      } elseif (count($entities) !== count($config['submission_order'])) {
        $results['warnings'][] = 'Submission order does not include all entities from field mappings.';
      }
    }
  }
}