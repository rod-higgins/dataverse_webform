<?php

namespace Drupal\dataverse_webform;

use Drupal\dataverse_webform\Exception\DataverseException;

/**
 * OData query builder for safe query construction.
 */
class ODataQueryBuilder {

  public const MAX_TOP_LIMIT = 5000;
  public const MAX_STRING_LENGTH = 1000;

  protected string $entitySet;
  protected array $select = [];
  protected array $filters = [];
  protected array $orderBy = [];
  protected ?int $top = null;
  protected ?int $skip = null;
  protected array $expand = [];
  protected bool $count = false;

  public function __construct(string $entity_set) {
    $this->entitySet = $this->sanitizeIdentifier($entity_set);
    if (empty($this->entitySet)) {
      throw new DataverseException('Entity set name cannot be empty or invalid');
    }
  }

  public function select(array $fields): self {
    foreach ($fields as $field) {
      $sanitized_field = $this->sanitizeIdentifier($field);
      if (empty($sanitized_field)) {
        throw new DataverseException("Invalid field name in select: {$field}");
      }
      $this->select[] = $sanitized_field;
    }
    return $this;
  }

  public function filter(string $field, string $operator, $value): self {
    $sanitized_field = $this->sanitizeIdentifier($field);
    if (empty($sanitized_field)) {
      throw new DataverseException("Invalid field name in filter: {$field}");
    }

    $sanitized_operator = $this->sanitizeOperator($operator);
    $sanitized_value = $this->sanitizeValue($value);
    
    $this->filters[] = "{$sanitized_field} {$sanitized_operator} {$sanitized_value}";
    return $this;
  }

  public function rawFilter(string $filter): self {
    if (preg_match('/[<>\'";]/', $filter)) {
      throw new DataverseException('Raw filter contains potentially dangerous characters');
    }
    
    $this->filters[] = $filter;
    return $this;
  }

  public function andWhere(array $conditions): self {
    foreach ($conditions as $condition) {
      if (!isset($condition['field'], $condition['operator'], $condition['value'])) {
        throw new DataverseException('Each filter condition must have field, operator, and value');
      }
      $this->filter($condition['field'], $condition['operator'], $condition['value']);
    }
    return $this;
  }

  public function orWhere(array $conditions): self {
    $or_filters = [];
    foreach ($conditions as $condition) {
      if (!isset($condition['field'], $condition['operator'], $condition['value'])) {
        throw new DataverseException('Each filter condition must have field, operator, and value');
      }
      
      $sanitized_field = $this->sanitizeIdentifier($condition['field']);
      $sanitized_operator = $this->sanitizeOperator($condition['operator']);
      $sanitized_value = $this->sanitizeValue($condition['value']);
      
      $or_filters[] = "{$sanitized_field} {$sanitized_operator} {$sanitized_value}";
    }
    
    if (!empty($or_filters)) {
      $this->filters[] = '(' . implode(' or ', $or_filters) . ')';
    }
    
    return $this;
  }

  public function orderBy(string $field, string $direction = 'asc'): self {
    $sanitized_field = $this->sanitizeIdentifier($field);
    if (empty($sanitized_field)) {
      throw new DataverseException("Invalid field name in orderBy: {$field}");
    }
    
    $sanitized_direction = in_array(strtolower($direction), ['asc', 'desc']) ? strtolower($direction) : 'asc';
    
    $this->orderBy[] = "{$sanitized_field} {$sanitized_direction}";
    return $this;
  }

  public function top(int $limit): self {
    if ($limit < 1 || $limit > self::MAX_TOP_LIMIT) {
      throw new DataverseException("Top limit must be between 1 and " . self::MAX_TOP_LIMIT);
    }
    
    $this->top = $limit;
    return $this;
  }

  public function skip(int $offset): self {
    if ($offset < 0) {
      throw new DataverseException('Skip offset cannot be negative');
    }
    
    $this->skip = $offset;
    return $this;
  }

  public function expand(array $relationships): self {
    foreach ($relationships as $relationship) {
      $sanitized_relationship = $this->sanitizeIdentifier($relationship);
      if (empty($sanitized_relationship)) {
        throw new DataverseException("Invalid relationship name in expand: {$relationship}");
      }
      $this->expand[] = $sanitized_relationship;
    }
    return $this;
  }

  public function count(bool $include_count = true): self {
    $this->count = $include_count;
    return $this;
  }

  public function build(): string {
    $url = $this->entitySet;
    $query_params = [];

    if (!empty($this->select)) {
      $query_params['$select'] = implode(',', array_unique($this->select));
    }

    if (!empty($this->filters)) {
      $query_params['$filter'] = implode(' and ', $this->filters);
    }

    if (!empty($this->orderBy)) {
      $query_params['$orderby'] = implode(',', $this->orderBy);
    }

    if ($this->top !== null) {
      $query_params['$top'] = $this->top;
    }

    if ($this->skip !== null) {
      $query_params['$skip'] = $this->skip;
    }

    if (!empty($this->expand)) {
      $query_params['$expand'] = implode(',', array_unique($this->expand));
    }

    if ($this->count) {
      $query_params['$count'] = 'true';
    }

    if (!empty($query_params)) {
      $query_string = http_build_query($query_params);
      if ($query_string === false) {
        throw new DataverseException('Failed to build query string');
      }
      $url .= '?' . $query_string;
    }

    return $url;
  }

  public function reset(): self {
    $this->select = [];
    $this->filters = [];
    $this->orderBy = [];
    $this->top = null;
    $this->skip = null;
    $this->expand = [];
    $this->count = false;
    return $this;
  }

  public function clone(): self {
    return clone $this;
  }

  protected function sanitizeIdentifier(string $identifier): string {
    $sanitized = preg_replace('/[^a-zA-Z0-9_.\/_]/', '', $identifier);
    
    if (!empty($sanitized) && !preg_match('/^[a-zA-Z]/', $sanitized)) {
      return '';
    }
    
    return $sanitized;
  }

  protected function sanitizeOperator(string $operator): string {
    $allowed_operators = ['eq', 'ne', 'gt', 'ge', 'lt', 'le', 'contains', 'startswith', 'endswith', 'in', 'not'];
    
    $operator = strtolower(trim($operator));
    
    if (!in_array($operator, $allowed_operators)) {
      throw new DataverseException("Invalid operator: {$operator}");
    }
    
    return $operator;
  }

  protected function sanitizeValue($value): string {
    if ($value === null) {
      return 'null';
    }
    
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    
    if (is_numeric($value)) {
      return (string) $value;
    }
    
    if (is_string($value)) {
      if (strlen($value) > self::MAX_STRING_LENGTH) {
        throw new DataverseException('String value exceeds maximum length of ' . self::MAX_STRING_LENGTH . ' characters');
      }
      
      $escaped = str_replace("'", "''", $value);
      return "'{$escaped}'";
    }
    
    if (is_array($value)) {
      $sanitized_items = [];
      foreach ($value as $item) {
        $sanitized_items[] = $this->sanitizeValue($item);
      }
      return '(' . implode(',', $sanitized_items) . ')';
    }
    
    if (is_object($value) && method_exists($value, '__toString')) {
      return $this->sanitizeValue((string) $value);
    }
    
    throw new DataverseException('Unsupported value type for OData query: ' . gettype($value));
  }
}