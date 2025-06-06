<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\dataverse_webform\Exception\DataverseException;

/**
 * Service for validating Dataverse configurations and data.
 */
class ValidationService {

  /**
   * Allowed entity name characters (alphanumeric, underscore, period).
   */
  public const ENTITY_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

  /**
   * Allowed field name characters (alphanumeric, underscore, period).
   */
  public const FIELD_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_.]*$/';

  /**
   * Maximum length for string values.
   */
  public const MAX_STRING_LENGTH = 4000;

  /**
   * Reserved Dataverse field names that cannot be used.
   */
  public const RESERVED_FIELDS = [
    'ownerid',
    'statecode',
    'statuscode',
    'createdby',
    'createdon',
    'modifiedby',
    'modifiedon',
    'versionnumber',
  ];

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs a ValidationService object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Validate Dataverse configuration.
   *
   * @param array $config
   *   The configuration to validate.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When configuration is invalid.
   */
  public function validateConfig(array $config): void {
    if (empty($config['enabled'])) {
      throw new DataverseException('Dataverse integration is not enabled');
    }

    // Validate required fields
    $required_fields = [
      'azure_tenant_id',
      'azure_client_id_key',
      'azure_client_secret_key',
      'dataverse_url',
    ];

    foreach ($required_fields as $field) {
      if (empty($config[$field])) {
        throw new DataverseException("Missing required configuration: {$field}");
      }
    }

    // Validate Azure tenant ID format
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $config['azure_tenant_id'])) {
      throw new DataverseException('Azure Tenant ID must be a valid GUID format');
    }

    // Validate Dataverse URL
    if (!filter_var($config['dataverse_url'], FILTER_VALIDATE_URL)) {
      throw new DataverseException('Dataverse URL must be a valid URL');
    }

    if (strpos($config['dataverse_url'], 'https://') !== 0) {
      throw new DataverseException('Dataverse URL must use HTTPS');
    }

    // Validate field mappings if present
    if (!empty($config['field_mappings'])) {
      $this->validateFieldMappings($config['field_mappings']);
    }

    // Validate submission order if present
    if (!empty($config['submission_order'])) {
      $this->validateSubmissionOrder($config['submission_order'], $config['field_mappings'] ?? []);
    }

    // Validate optional numeric settings
    $numeric_fields = [
      'batch_size' => [1, 100],
      'retry_attempts' => [0, 5],
      'timeout' => [5, 300],
    ];

    foreach ($numeric_fields as $field => $range) {
      if (isset($config[$field])) {
        $value = (int) $config[$field];
        if ($value < $range[0] || $value > $range[1]) {
          throw new DataverseException("{$field} must be between {$range[0]} and {$range[1]}");
        }
      }
    }
  }

  /**
   * Validate entity name format.
   *
   * @param string $entity_name
   *   The entity name to validate.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When entity name is invalid.
   */
  public function validateEntityName(string $entity_name): void {
    if (empty($entity_name)) {
      throw new DataverseException('Entity name cannot be empty');
    }

    if (!preg_match(self::ENTITY_NAME_PATTERN, $entity_name)) {
      throw new DataverseException('Entity name contains invalid characters. Use only letters, numbers, and underscores, starting with a letter.');
    }

    if (strlen($entity_name) > 64) {
      throw new DataverseException('Entity name cannot exceed 64 characters');
    }
  }

  /**
   * Validate field name format.
   *
   * @param string $field_name
   *   The field name to validate.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When field name is invalid.
   */
  public function validateFieldName(string $field_name): void {
    if (empty($field_name)) {
      throw new DataverseException('Field name cannot be empty');
    }

    if (!preg_match(self::FIELD_NAME_PATTERN, $field_name)) {
      throw new DataverseException('Field name contains invalid characters. Use only letters, numbers, underscores, and periods, starting with a letter.');
    }

    if (strlen($field_name) > 100) {
      throw new DataverseException('Field name cannot exceed 100 characters');
    }

    if (in_array(strtolower($field_name), self::RESERVED_FIELDS)) {
      throw new DataverseException("Field name '{$field_name}' is reserved by Dataverse");
    }
  }

  /**
   * Validate entity data for submission.
   *
   * @param array $data
   *   The entity data to validate.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When entity data is invalid.
   */
  public function validateEntityData(array $data): void {
    if (empty($data)) {
      throw new DataverseException('Entity data cannot be empty');
    }

    foreach ($data as $field_name => $value) {
      $this->validateFieldName($field_name);
      $this->validateFieldValue($field_name, $value);
    }
  }

  /**
   * Validate field mappings configuration.
   *
   * @param array $field_mappings
   *   The field mappings to validate.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When field mappings are invalid.
   */
  public function validateFieldMappings(array $field_mappings): void {
    if (empty($field_mappings)) {
      return;
    }

    $entities_used = [];
    
    foreach ($field_mappings as $webform_field => $mapping) {
      if (!is_array($mapping)) {
        throw new DataverseException("Invalid mapping configuration for field '{$webform_field}'");
      }

      // Validate required mapping fields
      $required_mapping_fields = ['entity', 'field'];
      foreach ($required_mapping_fields as $required_field) {
        if (empty($mapping[$required_field])) {
          throw new DataverseException("Missing '{$required_field}' in mapping for webform field '{$webform_field}'");
        }
      }

      // Validate entity and field names
      $this->validateEntityName($mapping['entity']);
      $this->validateFieldName($mapping['field']);

      // Track entities used
      $entities_used[] = $mapping['entity'];

      // Validate transform if specified
      if (!empty($mapping['transform'])) {
        $this->validateTransform($mapping['transform']);
      }

      // Validate relationship_to if specified
      if (!empty($mapping['relationship_to']) && !in_array($mapping['relationship_to'], $entities_used)) {
        $this->loggerFactory->get('dataverse_webform')->warning(
          'Field mapping for @field references relationship_to @entity which is not mapped by any previous field',
          [
            '@field' => $webform_field,
            '@entity' => $mapping['relationship_to'],
          ]
        );
      }
    }
  }

  /**
   * Validate submission order configuration.
   *
   * @param array $submission_order
   *   The submission order to validate.
   * @param array $field_mappings
   *   The field mappings to validate against.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When submission order is invalid.
   */
  public function validateSubmissionOrder(array $submission_order, array $field_mappings): void {
    if (empty($submission_order)) {
      return;
    }

    // Get entities from field mappings
    $entities_in_mappings = [];
    foreach ($field_mappings as $mapping) {
      if (!empty($mapping['entity'])) {
        $entities_in_mappings[] = $mapping['entity'];
      }
    }
    $entities_in_mappings = array_unique($entities_in_mappings);

    // Validate each entity in submission order
    foreach ($submission_order as $entity_name) {
      $this->validateEntityName($entity_name);
      
      if (!in_array($entity_name, $entities_in_mappings)) {
        throw new DataverseException("Entity '{$entity_name}' in submission order is not present in field mappings");
      }
    }

    // Check for missing entities
    $missing_entities = array_diff($entities_in_mappings, $submission_order);
    if (!empty($missing_entities)) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Entities @entities are in field mappings but not in submission order',
        ['@entities' => implode(', ', $missing_entities)]
      );
    }
  }

  /**
   * Validate field value.
   *
   * @param string $field_name
   *   The field name.
   * @param mixed $value
   *   The field value.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When field value is invalid.
   */
  protected function validateFieldValue(string $field_name, $value): void {
    // Null values are generally acceptable
    if ($value === null) {
      return;
    }

    // Validate string length
    if (is_string($value) && strlen($value) > self::MAX_STRING_LENGTH) {
      throw new DataverseException("Value for field '{$field_name}' exceeds maximum length of " . self::MAX_STRING_LENGTH . " characters");
    }

    // Check for null bytes and control characters in strings
    if (is_string($value) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
      throw new DataverseException("Value for field '{$field_name}' contains invalid control characters");
    }

    // Validate arrays (for multi-value fields)
    if (is_array($value)) {
      foreach ($value as $item) {
        $this->validateFieldValue($field_name, $item);
      }
    }
  }

  /**
   * Validate transform type.
   *
   * @param string $transform
   *   The transform type to validate.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When transform is invalid.
   */
  protected function validateTransform(string $transform): void {
    $allowed_transforms = [
      'none',
      'string',
      'number',
      'boolean',
      'date',
      'email',
      'phone',
      'url',
      'json',
    ];

    if (!in_array($transform, $allowed_transforms)) {
      throw new DataverseException("Invalid transform type '{$transform}'. Allowed values: " . implode(', ', $allowed_transforms));
    }
  }

}