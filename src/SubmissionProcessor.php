<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for processing webform submission data for Dataverse.
 */
class SubmissionProcessor {

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The validation service.
   */
  protected ValidationService $validator;

  /**
   * Constructs a SubmissionProcessor object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\dataverse_webform\ValidationService $validator
   *   The validation service.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    ValidationService $validator
  ) {
    $this->loggerFactory = $logger_factory;
    $this->validator = $validator;
  }

  /**
   * Process field mappings to group data by entity.
   *
   * @param array $submission_data
   *   The webform submission data.
   * @param array $field_mappings
   *   The field mapping configuration.
   *
   * @return array
   *   Array of entity data grouped by entity name.
   */
  public function processFieldMappings(array $submission_data, array $field_mappings): array {
    $entities_data = [];

    foreach ($field_mappings as $webform_field => $mapping) {
      if (!isset($submission_data[$webform_field]) || !is_array($mapping)) {
        continue;
      }

      $entity_name = $mapping['entity'] ?? '';
      $dataverse_field = $mapping['field'] ?? '';
      $transform = $mapping['transform'] ?? 'none';

      if (empty($entity_name) || empty($dataverse_field)) {
        continue;
      }

      $value = $submission_data[$webform_field];

      // Apply transformation
      $transformed_value = $this->transformValue($value, $transform);

      // Skip empty values unless explicitly configured to include them
      if ($this->shouldSkipEmptyValue($transformed_value, $mapping)) {
        continue;
      }

      // Initialize entity data array if not exists
      if (!isset($entities_data[$entity_name])) {
        $entities_data[$entity_name] = [];
      }

      $entities_data[$entity_name][$dataverse_field] = $transformed_value;
    }

    return $entities_data;
  }

  /**
   * Sanitize entity data for safe submission to Dataverse.
   *
   * @param array $data
   *   The entity data to sanitize.
   *
   * @return array
   *   The sanitized entity data.
   */
  public function sanitizeEntityData(array $data): array {
    $sanitized_data = [];

    foreach ($data as $field_name => $value) {
      $sanitized_value = $this->sanitizeValue($value);
      
      // Only include non-null values
      if ($sanitized_value !== null) {
        $sanitized_data[$field_name] = $sanitized_value;
      }
    }

    return $sanitized_data;
  }

  /**
   * Transform a value based on the specified transform type.
   *
   * @param mixed $value
   *   The value to transform.
   * @param string $transform
   *   The transform type.
   *
   * @return mixed
   *   The transformed value.
   */
  protected function transformValue($value, string $transform) {
    if ($value === null || $value === '') {
      return null;
    }

    switch ($transform) {
      case 'string':
        return $this->transformToString($value);

      case 'number':
        return $this->transformToNumber($value);

      case 'boolean':
        return $this->transformToBoolean($value);

      case 'date':
        return $this->transformToDate($value);

      case 'email':
        return $this->transformToEmail($value);

      case 'phone':
        return $this->transformToPhone($value);

      case 'url':
        return $this->transformToUrl($value);

      case 'json':
        return $this->transformToJson($value);

      case 'none':
      default:
        return $this->sanitizeValue($value);
    }
  }

  /**
   * Transform value to string.
   *
   * @param mixed $value
   *   The value to transform.
   *
   * @return string|null
   *   The string value or null.
   */
  protected function transformToString($value): ?string {
    if (is_array($value)) {
      // For multi-value fields, join with semicolons
      $filtered_values = array_filter($value, fn($item) => $item !== null && $item !== '');
      return !empty($filtered_values) ? implode('; ', $filtered_values) : null;
    }

    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }

    if (is_object($value) && method_exists($value, '__toString')) {
      return (string) $value;
    }

    $string_value = (string) $value;
    return !empty($string_value) ? $string_value : null;
  }

  /**
   * Transform value to number.
   *
   * @param mixed $value
   *   The value to transform.
   *
   * @return int|float|null
   *   The numeric value or null.
   */
  protected function transformToNumber($value) {
    if (is_numeric($value)) {
      return strpos($value, '.') !== false ? (float) $value : (int) $value;
    }

    if (is_string($value)) {
      // Try to extract number from string
      $cleaned = preg_replace('/[^0-9.-]/', '', $value);
      if (is_numeric($cleaned)) {
        return strpos($cleaned, '.') !== false ? (float) $cleaned : (int) $cleaned;
      }
    }

    return null;
  }

  /**
   * Transform value to boolean.
   *
   * @param mixed $value
   *   The value to transform.
   *
   * @return bool|null
   *   The boolean value or null.
   */
  protected function transformToBoolean($value): ?bool {
    if (is_bool($value)) {
      return $value;
    }

    if (is_numeric($value)) {
      return (bool) $value;
    }

    if (is_string($value)) {
      $value = strtolower(trim($value));
      $true_values = ['true', 'yes', 'on', '1', 'enabled', 'active'];
      $false_values = ['false', 'no', 'off', '0', 'disabled', 'inactive'];

      if (in_array($value, $true_values)) {
        return true;
      }
      if (in_array($value, $false_values)) {
        return false;
      }
    }

    return null;
  }

  /**
   * Transform value to ISO date format.
   *
   * @param mixed $value
   *   The value to transform.
   *
   * @return string|null
   *   The ISO date string or null.
   */
  protected function transformToDate($value): ?string {
    if (empty($value)) {
      return null;
    }

    try {
      if (is_numeric($value)) {
        // Unix timestamp
        $date = new \DateTime('@' . $value);
      } else {
        // String date
        $date = new \DateTime($value);
      }

      return $date->format('c'); // ISO 8601 format
    } catch (\Exception $e) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Failed to parse date value: @value',
        ['@value' => $value]
      );
      return null;
    }
  }

  /**
   * Transform value to email format.
   *
   * @param mixed $value
   *   The value to transform.
   *
   * @return string|null
   *   The email address or null.
   */
  protected function transformToEmail($value): ?string {
    $email = $this->transformToString($value);
    
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return strtolower($email);
    }

    return null;
  }

  /**
   * Transform value to phone format.
   *
   * @param mixed $value
   *   The value to transform.
   *
   * @return string|null
   *   The formatted phone number or null.
   */
  protected function transformToPhone($value): ?string {
    $phone = $this->transformToString($value);
    
    if ($phone) {
      // Remove all non-numeric characters except + and spaces
      $cleaned = preg_replace('/[^0-9+\s]/', '', $phone);
      return !empty($cleaned) ? $cleaned : null;
    }

    return null;
  }

  /**
   * Transform value to URL format.
   *
   * @param mixed $value
   *   The value to transform.
   *
   * @return string|null
   *   The URL or null.
   */
  protected function transformToUrl($value): ?string {
    $url = $this->transformToString($value);
    
    if ($url) {
      // Add http:// if no protocol specified
      if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'http://' . $url;
      }
      
      if (filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
      }
    }

    return null;
  }

  /**
   * Transform value to JSON format.
   *
   * @param mixed $value
   *   The value to transform.
   *
   * @return string|null
   *   The JSON string or null.
   */
  protected function transformToJson($value): ?string {
    if (is_string($value)) {
      // Validate if already JSON
      json_decode($value);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $value;
      }
    }

    // Convert to JSON
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    return $json !== false ? $json : null;
  }

  /**
   * Sanitize a value for safe submission.
   *
   * @param mixed $value
   *   The value to sanitize.
   *
   * @return mixed
   *   The sanitized value.
   */
  protected function sanitizeValue($value) {
    if ($value === null || $value === '') {
      return null;
    }

    if (is_string($value)) {
      // Remove null bytes and control characters
      $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
      
      // Trim whitespace
      $value = trim($value);
      
      return !empty($value) ? $value : null;
    }

    if (is_array($value)) {
      $sanitized_array = [];
      foreach ($value as $key => $item) {
        $sanitized_item = $this->sanitizeValue($item);
        if ($sanitized_item !== null) {
          $sanitized_array[$key] = $sanitized_item;
        }
      }
      return !empty($sanitized_array) ? $sanitized_array : null;
    }

    if (is_bool($value) || is_numeric($value)) {
      return $value;
    }

    // For objects, try to convert to string
    if (is_object($value) && method_exists($value, '__toString')) {
      return $this->sanitizeValue((string) $value);
    }

    return null;
  }

  /**
   * Determine if an empty value should be skipped.
   *
   * @param mixed $value
   *   The value to check.
   * @param array $mapping
   *   The field mapping configuration.
   *
   * @return bool
   *   TRUE if the value should be skipped, FALSE otherwise.
   */
  protected function shouldSkipEmptyValue($value, array $mapping): bool {
    // Always include explicitly required fields, even if empty
    if (!empty($mapping['required'])) {
      return false;
    }

    // Skip null or empty values by default
    if ($value === null || $value === '') {
      return true;
    }

    // Skip empty arrays
    if (is_array($value) && empty($value)) {
      return true;
    }

    return false;
  }

}