<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\dataverse_webform\Exception\DataverseException;

/**
 * Service for validating Dataverse configurations and data.
 */
class ValidationService {

  public const ENTITY_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';
  public const FIELD_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_.]*$/';
  public const MAX_STRING_LENGTH = 4000;
  public const RESERVED_FIELDS = [
    'ownerid', 'statecode', 'statuscode', 'createdby', 'createdon',
    'modifiedby', 'modifiedon', 'versionnumber',
  ];

  protected LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
  }

  public function validateConfig(array $config): void {
    if (empty($config['enabled'])) {
      throw new DataverseException('Dataverse integration is not enabled');
    }

    $this->validateRequiredFields($config);
    $this->validateFieldFormats($config);
    $this->validateOptionalSettings($config);

    if (!empty($config['field_mappings'])) {
      $this->validateFieldMappings($config['field_mappings']);
    }

    if (!empty($config['submission_order'])) {
      $this->validateSubmissionOrder($config['submission_order'], $config['field_mappings'] ?? []);
    }
  }

  public function validateEntityName(string $entity_name): void {
    if (empty($entity_name)) {
      throw new DataverseException('Entity name cannot be empty');
    }

    if (!preg_match(self::ENTITY_NAME_PATTERN, $entity_name) || strlen($entity_name) > 64) {
      throw new DataverseException('Entity name contains invalid characters or exceeds 64 characters');
    }
  }

  public function validateFieldName(string $field_name): void {
    if (empty($field_name)) {
      throw new DataverseException('Field name cannot be empty');
    }

    if (!preg_match(self::FIELD_NAME_PATTERN, $field_name) || strlen($field_name) > 100) {
      throw new DataverseException('Field name contains invalid characters or exceeds 100 characters');
    }

    if (in_array(strtolower($field_name), self::RESERVED_FIELDS)) {
      throw new DataverseException("Field name '{$field_name}' is reserved by Dataverse");
    }
  }

  public function validateEntityData(array $data): void {
    if (empty($data)) {
      throw new DataverseException('Entity data cannot be empty');
    }

    foreach ($data as $field_name => $value) {
      $this->validateFieldName($field_name);
      $this->validateFieldValue($field_name, $value);
    }
  }

  protected function validateRequiredFields(array $config): void {
    $required_fields = ['azure_tenant_id', 'azure_client_id_key', 'azure_client_secret_key', 'dataverse_url'];

    $missing_fields = array_filter($required_fields, fn($field) => empty($config[$field]));
    
    if (!empty($missing_fields)) {
      throw new DataverseException('Missing required configuration: ' . implode(', ', $missing_fields));
    }
  }

  protected function validateFieldFormats(array $config): void {
    $this->validateAzureTenantId($config['azure_tenant_id']);
    $this->validateDataverseUrl($config['dataverse_url']);
  }

  protected function validateAzureTenantId(string $tenant_id): void {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenant_id)) {
      throw new DataverseException('Azure Tenant ID must be a valid GUID format');
    }
  }

  protected function validateDataverseUrl(string $url): void {
    if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
      throw new DataverseException('Dataverse URL must be a valid HTTPS URL');
    }
  }

  protected function validateOptionalSettings(array $config): void {
    $numeric_settings = [
      'batch_size' => [1, 100],
      'retry_attempts' => [0, 5],
      'timeout' => [5, 300],
    ];

    foreach ($numeric_settings as $field => [$min, $max]) {
      if (isset($config[$field])) {
        $this->validateNumericRange($field, (int) $config[$field], $min, $max);
      }
    }
  }

  protected function validateNumericRange(string $field, int $value, int $min, int $max): void {
    if ($value < $min || $value > $max) {
      throw new DataverseException("{$field} must be between {$min} and {$max}");
    }
  }

  protected function validateFieldMappings(array $field_mappings): void {
    if (empty($field_mappings)) {
      return;
    }

    $entities_used = [];
    
    foreach ($field_mappings as $webform_field => $mapping) {
      $this->validateSingleMapping($webform_field, $mapping);
      $entities_used[] = $mapping['entity'];
    }
  }

  protected function validateSingleMapping(string $webform_field, $mapping): void {
    if (!is_array($mapping)) {
      throw new DataverseException("Invalid mapping configuration for field '{$webform_field}'");
    }

    $this->validateMappingStructure($webform_field, $mapping);
    $this->validateEntityName($mapping['entity']);
    $this->validateFieldName($mapping['field']);

    if (!empty($mapping['transform'])) {
      $this->validateTransform($mapping['transform']);
    }
  }

  protected function validateMappingStructure(string $webform_field, array $mapping): void {
    $required_fields = ['entity', 'field'];
    $missing_fields = array_filter($required_fields, fn($field) => empty($mapping[$field]));
    
    if (!empty($missing_fields)) {
      throw new DataverseException("Missing '" . implode(', ', $missing_fields) . "' in mapping for webform field '{$webform_field}'");
    }
  }

  protected function validateSubmissionOrder(array $submission_order, array $field_mappings): void {
    if (empty($submission_order)) {
      return;
    }

    $entities_in_mappings = $this->extractEntitiesFromMappings($field_mappings);

    foreach ($submission_order as $entity_name) {
      $this->validateEntityName($entity_name);
      
      if (!in_array($entity_name, $entities_in_mappings)) {
        throw new DataverseException("Entity '{$entity_name}' in submission order is not present in field mappings");
      }
    }

    $this->checkMissingEntitiesInOrder($entities_in_mappings, $submission_order);
  }

  protected function extractEntitiesFromMappings(array $field_mappings): array {
    $entities = [];
    foreach ($field_mappings as $mapping) {
      if (!empty($mapping['entity'])) {
        $entities[] = $mapping['entity'];
      }
    }
    return array_unique($entities);
  }

  protected function checkMissingEntitiesInOrder(array $entities_in_mappings, array $submission_order): void {
    $missing_entities = array_diff($entities_in_mappings, $submission_order);
    if (!empty($missing_entities)) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Entities @entities are in field mappings but not in submission order',
        ['@entities' => implode(', ', $missing_entities)]
      );
    }
  }

  protected function validateFieldValue(string $field_name, $value): void {
    if ($value === null) {
      return;
    }

    if (is_string($value)) {
      $this->validateStringValue($field_name, $value);
    } elseif (is_array($value)) {
      foreach ($value as $item) {
        $this->validateFieldValue($field_name, $item);
      }
    }
  }

  protected function validateStringValue(string $field_name, string $value): void {
    if (strlen($value) > self::MAX_STRING_LENGTH) {
      throw new DataverseException("Value for field '{$field_name}' exceeds maximum length of " . self::MAX_STRING_LENGTH . " characters");
    }

    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
      throw new DataverseException("Value for field '{$field_name}' contains invalid control characters");
    }
  }

  protected function validateTransform(string $transform): void {
    $allowed_transforms = ['none', 'string', 'number', 'boolean', 'date', 'email', 'phone', 'url', 'json'];

    if (!in_array($transform, $allowed_transforms)) {
      throw new DataverseException("Invalid transform type '{$transform}'. Allowed values: " . implode(', ', $allowed_transforms));
    }
  }
}