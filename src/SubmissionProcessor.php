<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for processing webform submission data for Dataverse.
 */
class SubmissionProcessor {

  public const ARRAY_SEPARATOR = '; ';
  public const MAX_PHONE_LENGTH = 50;
  public const TRUE_VALUES = ['true', 'yes', 'on', '1', 'enabled', 'active'];
  public const FALSE_VALUES = ['false', 'no', 'off', '0', 'disabled', 'inactive'];

  protected LoggerChannelFactoryInterface $loggerFactory;
  protected ValidationService $validator;

  public function __construct(LoggerChannelFactoryInterface $logger_factory, ValidationService $validator) {
    $this->loggerFactory = $logger_factory;
    $this->validator = $validator;
  }

  /**
   * Process field mappings into entity data.
   */
  public function processFieldMappings(array $submission_data, array $field_mappings): array {
    $entities_data = [];

    foreach ($field_mappings as $mapping) {
      if (!$this->isValidMapping($mapping, $submission_data)) {
        continue;
      }

      $processed_value = $this->processMapping($mapping, $submission_data);
      if ($processed_value !== null) {
        $entity_name = $mapping['entity'];
        if (!isset($entities_data[$entity_name])) {
          $entities_data[$entity_name] = [];
        }
        $entities_data[$entity_name][$mapping['field']] = $processed_value;
      }
    }

    return $entities_data;
  }

  /**
   * Sanitize entity data for API submission.
   */
  public function sanitizeEntityData(array $data): array {
    $sanitized_data = [];

    foreach ($data as $field_name => $value) {
      $sanitized_value = $this->sanitizeValue($value);
      if ($sanitized_value !== null) {
        $sanitized_data[$field_name] = $sanitized_value;
      }
    }

    return $sanitized_data;
  }

  /**
   * Process individual mapping.
   */
  protected function processMapping(array $mapping, array $submission_data) {
    $webform_field = $mapping['webform_field'];
    $transform = $mapping['transform'] ?? 'none';
    $value = $submission_data[$webform_field];

    $transformed_value = $this->transformValue($value, $transform);

    if ($this->shouldSkipEmptyValue($transformed_value, $mapping)) {
      return null;
    }

    return $transformed_value;
  }

  /**
   * Check if mapping is valid.
   */
  protected function isValidMapping(array $mapping, array $submission_data): bool {
    if (!is_array($mapping)) {
      return false;
    }

    $required_fields = ['webform_field', 'entity', 'field'];
    foreach ($required_fields as $field) {
      if (empty($mapping[$field])) {
        return false;
      }
    }

    return isset($submission_data[$mapping['webform_field']]);
  }

  /**
   * Transform value based on transform type.
   */
  protected function transformValue($value, string $transform) {
    if ($value === null || $value === '') {
      return null;
    }

    return match($transform) {
      'string' => $this->transformToString($value),
      'number' => $this->transformToNumber($value),
      'boolean' => $this->transformToBoolean($value),
      'date' => $this->transformToDate($value),
      'email' => $this->transformToEmail($value),
      'phone' => $this->transformToPhone($value),
      'url' => $this->transformToUrl($value),
      'json' => $this->transformToJson($value),
      default => $this->sanitizeValue($value),
    };
  }

  /**
   * Transform value to string.
   */
  protected function transformToString($value): ?string {
    if (is_array($value)) {
      return $this->transformArrayToString($value);
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
   * Transform array to string representation.
   */
  protected function transformArrayToString(array $value): ?string {
    $filtered_values = array_filter($value, fn($item) => $item !== null && $item !== '');
    return !empty($filtered_values) ? implode(self::ARRAY_SEPARATOR, $filtered_values) : null;
  }

  /**
   * Transform value to number.
   */
  protected function transformToNumber($value) {
    if (is_numeric($value)) {
      return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }

    if (is_string($value)) {
      $cleaned = preg_replace('/[^0-9.-]/', '', $value);
      if (is_numeric($cleaned)) {
        return str_contains($cleaned, '.') ? (float) $cleaned : (int) $cleaned;
      }
    }

    return null;
  }

  /**
   * Transform value to boolean.
   */
  protected function transformToBoolean($value): ?bool {
    if (is_bool($value)) {
      return $value;
    }

    if (is_numeric($value)) {
      return (bool) $value;
    }

    if (is_string($value)) {
      return $this->parseStringToBoolean($value);
    }

    return null;
  }

  /**
   * Parse string to boolean value.
   */
  protected function parseStringToBoolean(string $value): ?bool {
    $value = strtolower(trim($value));
    
    if (in_array($value, self::TRUE_VALUES)) {
      return true;
    }
    
    if (in_array($value, self::FALSE_VALUES)) {
      return false;
    }

    return null;
  }

  /**
   * Transform value to date format.
   */
  protected function transformToDate($value): ?string {
    if (empty($value)) {
      return null;
    }

    try {
      $date = is_numeric($value) ? 
        new \DateTime('@' . $value) : 
        new \DateTime($value);

      return $date->format('c');
    } catch (\Exception $e) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Failed to parse date value: @value - @error',
        ['@value' => $value, '@error' => $e->getMessage()]
      );
      return null;
    }
  }

  /**
   * Transform value to email format.
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
   */
  protected function transformToPhone($value): ?string {
    $phone = $this->transformToString($value);
    
    if ($phone) {
      $cleaned = preg_replace('/[^0-9+\s()-]/', '', $phone);
      // Limit phone number length for database constraints.
      if (!empty($cleaned) && strlen($cleaned) <= self::MAX_PHONE_LENGTH) {
        return $cleaned;
      }
    }

    return null;
  }

  /**
   * Transform value to URL format.
   */
  protected function transformToUrl($value): ?string {
    $url = $this->transformToString($value);
    
    if (!$url) {
      return null;
    }

    // Add protocol if missing.
    if (!preg_match('/^https?:\/\//', $url)) {
      $url = 'https://' . $url;
    }
    
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
  }

  /**
   * Transform value to JSON format.
   */
  protected function transformToJson($value): ?string {
    if (is_string($value)) {
      // Check if already valid JSON.
      json_decode($value);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $value;
      }
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json !== false ? $json : null;
  }

  /**
   * Sanitize any value type.
   */
  protected function sanitizeValue($value) {
    if ($value === null || $value === '') {
      return null;
    }

    if (is_string($value)) {
      return $this->sanitizeStringValue($value);
    }

    if (is_array($value)) {
      return $this->sanitizeArrayValue($value);
    }

    if (is_bool($value) || is_numeric($value)) {
      return $value;
    }

    if (is_object($value) && method_exists($value, '__toString')) {
      return $this->sanitizeValue((string) $value);
    }

    return null;
  }

  /**
   * Sanitize string value.
   */
  protected function sanitizeStringValue(string $value): ?string {
    // Remove control characters except tabs and newlines.
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    $value = trim($value);
    
    return !empty($value) ? $value : null;
  }

  /**
   * Sanitize array value.
   */
  protected function sanitizeArrayValue(array $value): ?array {
    $sanitized_array = [];
    
    foreach ($value as $key => $item) {
      $sanitized_item = $this->sanitizeValue($item);
      if ($sanitized_item !== null) {
        $sanitized_array[$key] = $sanitized_item;
      }
    }
    
    return !empty($sanitized_array) ? $sanitized_array : null;
  }

  /**
   * Check if empty value should be skipped.
   */
  protected function shouldSkipEmptyValue($value, array $mapping): bool {
    // Don't skip if field is required.
    if (!empty($mapping['required'])) {
      return false;
    }

    // Skip null, empty string, or empty array.
    return $value === null || $value === '' || (is_array($value) && empty($value));
  }

}