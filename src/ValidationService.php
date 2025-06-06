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
  public const GUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
  public const MAX_STRING_LENGTH = 4000;
  public const MAX_IDENTIFIER_LENGTH = 100;
  public const MAX_ENTITY_NAME_LENGTH = 64;
  
  public const RESERVED_FIELDS = [
    'ownerid', 'statecode', 'statuscode', 'createdby', 'createdon',
    'modifiedby', 'modifiedon', 'versionnumber',
  ];
  
  public const ALLOWED_TRANSFORMS = [
    'none', 'string', 'number', 'boolean', 'date', 'email', 'phone', 'url', 'json'
  ];

  public const NUMERIC_SETTINGS = [
    'batch_size' => [1, 100],
    'retry_attempts' => [0, 5],
    'timeout' => [5, 300],
  ];

  protected LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
  }

  public function validateConfig(array $config): void {
    if (empty($config['enabled'])) {
      throw DataverseException::configurationError('Dataverse integration is not enabled');
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
      throw DataverseException::validationError('Entity name cannot be empty');
    }

    if (!preg_match(self::ENTITY_NAME_PATTERN, $entity_name) || strlen($entity_name) > self::MAX_ENTITY_NAME_LENGTH) {
      throw DataverseException::validationError("Entity name '{$entity_name}' contains invalid characters or exceeds " . self::MAX_ENTITY_NAME_LENGTH . " characters");
    }
  }

  public function validateFieldName(string $field_name): void {
    if (empty($field_name)) {
      throw DataverseException::validationError('Field name cannot be empty');
    }

    if (!preg_match(self::FIELD_NAME_PATTERN, $field_name) || strlen($field_name) > self::MAX_IDENTIFIER_LENGTH) {
      throw DataverseException::validationError("Field name '{$field_name}' contains invalid characters or exceeds " . self::MAX_IDENTIFIER_LENGTH . " characters");
    }

    if (in_array(strtolower($field_name), self::RESERVED_FIELDS)) {
      throw DataverseException::validationError("Field name '{$field_name}' is reserved by Dataverse");
    }
  }

  public function validateEntityData(array $data): void {
    if (empty($data)) {
      throw DataverseException::validationError('Entity data cannot be empty');
    }

    foreach ($data as $field_name => $value) {
      $this->validateFieldName($field_name);
      $this->validateFieldValue($field_name, $value);
    }
  }

  public function isValidGuid(string $guid): bool {
    return (bool) preg_match(self::GUID_PATTERN, $guid);
  }

  public function isValidUrl(string $url, bool $require_https = true): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return false;
    }

    return !$require_https || str_starts_with($url, 'https://');
  }

  public function validateBatchConfigurationSet(array $configs): array {
    $errors = [];
    
    foreach ($configs as $index => $config) {
      try {
        $this->validateConfig($config);
      } catch (DataverseException $e) {
        $errors["config_{$index}"] = $e->getMessage();
      }
    }

    return $errors;
  }

  protected function validateRequiredFields(array $config): void {
    $required_fields = ['azure_tenant_id', 'azure_client_id_key', 'azure_client_secret_key', 'dataverse_url'];

    $missing_fields = array_filter($required_fields, fn($field) => empty($config[$field]));
    
    if (!empty($missing_fields)) {
      throw DataverseException::configurationError('Missing required configuration: ' . implode(', ', $missing_fields));
    }
  }

  protected function validateFieldFormats(array $config): void {
    $this->validateAzureTenantId($config['azure_tenant_id']);
    $this->validateDataverseUrl($config['dataverse_url']);
  }

  protected function validateAzureTenantId(string $tenant_id): void {
    if (!$this->isValidGuid($tenant_id)) {
      throw DataverseException::validationError('Azure Tenant ID must be a valid GUID format');
    }
  }

  protected function validateDataverseUrl(string $url): void {
    if (!$this->isValidUrl($url, true)) {
      throw DataverseException::validationError('Dataverse URL must be a valid HTTPS URL');
    }
  }

  protected function validateOptionalSettings(array $config): void {
    foreach (self::NUMERIC_SETTINGS as $field => [$min, $max]) {
      if (isset($config[$field])) {
        $this->validateNumericRange($field, (int) $config[$field], $min, $max);
      }
    }
  }

  protected function validateNumericRange(string $field, int $value, int $min, int $max): void {
    if ($value < $min || $value > $max) {
      throw DataverseException::validationError("{$field} must be between {$min} and {$max}, got {$value}");
    }
  }

  protected function validateFieldMappings(array $field_mappings): void {
    if (empty($field_mappings)) {
      return;
    }

    $entities_used = [];
    
    foreach ($field_mappings as $index => $mapping) {
      $this->validateSingleMapping($index, $mapping);
      $entities_used[] = $mapping['entity'];
    }

    $this->checkDuplicateMappings($field_mappings);
  }

  protected function validateSingleMapping(int $index, $mapping): void {
    if (!is_array($mapping)) {
      throw DataverseException::validationError("Invalid mapping configuration at index {$index}");
    }

    $this->validateMappingStructure($index, $mapping);
    $this->validateEntityName($mapping['entity']);
    $this->validateFieldName($mapping['field']);

    if (!empty($mapping['transform'])) {
      $this->validateTransform($mapping['transform']);
    }
  }

  protected function validateMappingStructure(int $index, array $mapping): void {
    $required_fields = ['webform_field', 'entity', 'field'];
    $missing_fields = array_filter($required_fields, fn($field) => empty($mapping[$field]));
    
    if (!empty($missing_fields)) {
      throw DataverseException::validationError("Missing required fields in mapping at index {$index}: " . implode(', ', $missing_fields));
    }
  }

  protected function checkDuplicateMappings(array $field_mappings): void {
    $seen_mappings = [];
    
    foreach ($field_mappings as $index => $mapping) {
      $mapping_key = $mapping['webform_field'] . '->' . $mapping['entity'] . '.' . $mapping['field'];
      
      if (isset($seen_mappings[$mapping_key])) {
        $this->loggerFactory->get('dataverse_webform')->warning(
          'Duplicate field mapping detected: @mapping',
          ['@mapping' => $mapping_key]
        );
      } else {
        $seen_mappings[$mapping_key] = $index;
      }
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
        throw DataverseException::validationError("Entity '{$entity_name}' in submission order is not present in field mappings");
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
      throw DataverseException::validationError("Value for field '{$field_name}' exceeds maximum length of " . self::MAX_STRING_LENGTH . " characters");
    }

    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
      throw DataverseException::validationError("Value for field '{$field_name}' contains invalid control characters");
    }
  }

  protected function validateTransform(string $transform): void {
    if (!in_array($transform, self::ALLOWED_TRANSFORMS)) {
      throw DataverseException::validationError("Invalid transform type '{$transform}'. Allowed values: " . implode(', ', self::ALLOWED_TRANSFORMS));
    }
  }

}