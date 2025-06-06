<?php

namespace Drupal\dataverse_webform;

use Drupal\dataverse_webform\Exception\DataverseException;

/**
 * OData query builder for safe query construction with enhanced security.
 */
class ODataQueryBuilder {

  /**
   * Maximum number of records that can be requested.
   */
  public const MAX_TOP_LIMIT = 5000;

  /**
   * Maximum length for string values in filters.
   */
  public const MAX_STRING_LENGTH = 1000;

  /**
   * The entity set name.
   */
  protected string $entitySet;

  /**
   * Select fields.
   */
  protected array $select = [];

  /**
   * Filter conditions.
   */
  protected array $filters = [];

  /**
   * Order by clauses.
   */
  protected array $orderBy = [];

  /**
   * Top limit.
   */
  protected ?int $top = null;

  /**
   * Skip offset.
   */
  protected ?int $skip = null;

  /**
   * Expand relationships.
   */
  protected array $expand = [];

  /**
   * Count flag.
   */
  protected bool $count = false;

  /**
   * Constructs an ODataQueryBuilder.
   *
   * @param string $entity_set
   *   The entity set name.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When entity set name is invalid.
   */
  public function __construct(string $entity_set) {
    $this->entitySet = $this->sanitizeIdentifier($entity_set);
    if (empty($this->entitySet)) {
      throw new DataverseException('Entity set name cannot be empty or invalid');
    }
  }

  /**
   * Add select fields.
   *
   * @param array $fields
   *   Array of field names to select.
   *
   * @return $this
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When field names are invalid.
   */
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

  /**
   * Add a filter condition.
   *
   * @param string $field
   *   The field name.
   * @param string $operator
   *   The comparison operator.
   * @param mixed $value
   *   The value to compare against.
   *
   * @return $this
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When filter parameters are invalid.
   */
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

  /**
   * Add a raw filter condition (use with caution).
   *
   * @param string $filter
   *   The raw filter string.
   *
   * @return $this
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When filter contains dangerous content.
   */
  public function rawFilter(string $filter): self {
    // Basic validation to prevent obvious injection attempts
    if (preg_match('/[<>\'";]/', $filter)) {
      throw new DataverseException('Raw filter contains potentially dangerous characters');
    }
    
    $this->filters[] = $filter;
    return $this;
  }

  /**
   * Add multiple filter conditions with AND logic.
   *
   * @param array $conditions
   *   Array of filter conditions, each with 'field', 'operator', 'value'.
   *
   * @return $this
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When filter conditions are invalid.
   */
  public function andWhere(array $conditions): self {
    foreach ($conditions as $condition) {
      if (!isset($condition['field'], $condition['operator'], $condition['value'])) {
        throw new DataverseException('Each filter condition must have field, operator, and value');
      }
      $this->filter($condition['field'], $condition['operator'], $condition['value']);
    }
    return $this;
  }

  /**
   * Add multiple filter conditions with OR logic.
   *
   * @param array $conditions
   *   Array of filter conditions, each with 'field', 'operator', 'value'.
   *
   * @return $this
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When filter conditions are invalid.
   */
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

  /**
   * Add an order by clause.
   *
   * @param string $field
   *   The field name.
   * @param string $direction
   *   The sort direction (asc or desc).
   *
   * @return $this
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When field name is invalid.
   */
  public function orderBy(string $field, string $direction = 'asc'): self {
    $sanitized_field = $this->sanitizeIdentifier($field);
    if (empty($sanitized_field)) {
      throw new DataverseException("Invalid field name in orderBy: {$field}");
    }
    
    $sanitized_direction = in_array(strtolower($direction), ['asc', 'desc']) ? strtolower($direction) : 'asc';
    
    $this->orderBy[] = "{$sanitized_field} {$sanitized_direction}";
    return $this;
  }

  /**
   * Set the top limit.
   *
   * @param int $limit
   *   The maximum number of records to return.
   *
   * @return $this
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When limit is invalid.
   */
  public function top(int $limit): self {
    if ($limit < 1 || $limit > self::MAX_TOP_LIMIT) {
      throw new DataverseException("Top limit must be between 1 and " . self::MAX_TOP_LIMIT);
    }
    
    $this->top = $limit;
    return $this;
  }

  /**
   * Set the skip offset.
   *
   * @param int $offset
   *   The number of records to skip.
   *
   * @return $this
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When offset is invalid.
   */
  public function skip(int $offset): self {
    if ($offset < 0) {
      throw new DataverseException('Skip offset cannot be negative');
    }
    
    $this->skip = $offset;
    return $this;
  }

  /**
   * Add expand relationships.
   *
   * @param array $relationships
   *   Array of relationship names to expand.
   *
   * @return $this
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When relationship names are invalid.
   */
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

  /**
   * Enable count in response.
   *
   * @param bool $include_count
   *   Whether to include count.
   *
   * @return $this
   */
  public function count(bool $include_count = true): self {
    $this->count = $include_count;
    return $this;
  }

  /**
   * Build the OData query URL.
   *
   * @return string
   *   The complete OData query URL.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When query building fails.
   */
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

  /**
   * Reset all query parameters.
   *
   * @return $this
   */
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

  /**
   * Create a copy of this query builder.
   *
   * @return static
   *   A cloned instance.
   */
  public function clone(): self {
    return clone $this;
  }

  /**
   * Sanitize field/entity identifiers.
   *
   * @param string $identifier
   *   The identifier to sanitize.
   *
   * @return string
   *   The sanitized identifier.
   */
  protected function sanitizeIdentifier(string $identifier): string {
    // Allow only alphanumeric characters, underscores, periods, and forward slashes (for navigation properties)
    $sanitized = preg_replace('/[^a-zA-Z0-9_.\/_]/', '', $identifier);
    
    // Ensure it starts with a letter
    if (!empty($sanitized) && !preg_match('/^[a-zA-Z]/', $sanitized)) {
      return '';
    }
    
    return $sanitized;
  }

  /**
   * Sanitize comparison operators.
   *
   * @param string $operator
   *   The operator to sanitize.
   *
   * @return string
   *   The sanitized operator.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When operator is invalid.
   */
  protected function sanitizeOperator(string $operator): string {
    $allowed_operators = [
      'eq', 'ne', 'gt', 'ge', 'lt', 'le',
      'contains', 'startswith', 'endswith',
      'in', 'not',
    ];
    
    $operator = strtolower(trim($operator));
    
    if (!in_array($operator, $allowed_operators)) {
      throw new DataverseException("Invalid operator: {$operator}");
    }
    
    return $operator;
  }

  /**
   * Sanitize values for OData queries.
   *
   * @param mixed $value
   *   The value to sanitize.
   *
   * @return string
   *   The sanitized value.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When value is invalid.
   */
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
      // Check for maximum length
      if (strlen($value) > self::MAX_STRING_LENGTH) {
        throw new DataverseException('String value exceeds maximum length of ' . self::MAX_STRING_LENGTH . ' characters');
      }
      
      // Escape single quotes and wrap in quotes
      $escaped = str_replace("'", "''", $value);
      return "'{$escaped}'";
    }
    
    if (is_array($value)) {
      // For arrays, create an 'in' clause format
      $sanitized_items = [];
      foreach ($value as $item) {
        $sanitized_items[] = $this->sanitizeValue($item);
      }
      return '(' . implode(',', $sanitized_items) . ')';
    }
    
    // Convert objects to string if possible
    if (is_object($value) && method_exists($value, '__toString')) {
      return $this->sanitizeValue((string) $value);
    }
    
    throw new DataverseException('Unsupported value type for OData query: ' . gettype($value));
  }

}