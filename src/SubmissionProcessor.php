<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for processing webform submission data for Dataverse.
 */
class SubmissionProcessor {

  protected LoggerChannelFactoryInterface $loggerFactory;
  protected ValidationService $validator;

  public function __construct(LoggerChannelFactoryInterface $logger_factory, ValidationService $validator) {
    $this->loggerFactory = $logger_factory;
    $this->validator = $validator;
  }

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
      $transformed_value = $this->transformValue($value, $transform);

      if ($this->shouldSkipEmptyValue($transformed_value, $mapping)) {
        continue;
      }

      if (!isset($entities_data[$entity_name])) {
        $entities_data[$entity_name] = [];
      }

      $entities_data[$entity_name][$dataverse_field] = $transformed_value;
    }

    return $entities_data;
  }

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

  protected function transformToString($value): ?string {
    if (is_array($value)) {
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

  protected function transformToNumber($value) {
    if (is_numeric($value)) {
      return strpos($value, '.') !== false ? (float) $value : (int) $value;
    }

    if (is_string($value)) {
      $cleaned = preg_replace('/[^0-9.-]/', '', $value);
      if (is_numeric($cleaned)) {
        return strpos($cleaned, '.') !== false ? (float) $cleaned : (int) $cleaned;
      }
    }

    return null;
  }

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

  protected function transformToDate($value): ?string {
    if (empty($value)) {
      return null;
    }

    try {
      if (is_numeric($value)) {
        $date = new \DateTime('@' . $value);
      } else {
        $date = new \DateTime($value);
      }

      return $date->format('c');
    } catch (\Exception $e) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Failed to parse date value: @value',
        ['@value' => $value]
      );
      return null;
    }
  }

  protected function transformToEmail($value): ?string {
    $email = $this->transformToString($value);
    
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return strtolower($email);
    }

    return null;
  }

  protected function transformToPhone($value): ?string {
    $phone = $this->transformToString($value);
    
    if ($phone) {
      $cleaned = preg_replace('/[^0-9+\s]/', '', $phone);
      return !empty($cleaned) ? $cleaned : null;
    }

    return null;
  }

  protected function transformToUrl($value): ?string {
    $url = $this->transformToString($value);
    
    if ($url) {
      if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'http://' . $url;
      }
      
      if (filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
      }
    }

    return null;
  }

  protected function transformToJson($value): ?string {
    if (is_string($value)) {
      json_decode($value);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $value;
      }
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    return $json !== false ? $json : null;
  }

  protected function sanitizeValue($value) {
    if ($value === null || $value === '') {
      return null;
    }

    if (is_string($value)) {
      $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
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

    if (is_object($value) && method_exists($value, '__toString')) {
      return $this->sanitizeValue((string) $value);
    }

    return null;
  }

  protected function shouldSkipEmptyValue($value, array $mapping): bool {
    if (!empty($mapping['required'])) {
      return false;
    }

    if ($value === null || $value === '') {
      return true;
    }

    if (is_array($value) && empty($value)) {
      return true;
    }

    return false;
  }
}