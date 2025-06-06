<?php

namespace Drupal\dataverse_webform;

/**
 * OData query builder for safe query construction.
 */
class ODataQueryBuilder {

  /**
   * The entity set name.
   *
   * @var string
   */
  protected $entitySet;

  /**
   * Select fields.
   *
   * @var array
   */
  protected $select = [];

  /**
   * Filter conditions.
   *
   * @var array
   */
  protected $filters = [];

  /**
   * Order by clauses.
   *
   * @var array
   */
  protected $orderBy = [];

  /**
   * Top limit.
   *
   * @var int|null
   */
  protected $top;

  /**
   * Skip offset.
   *
   * @var int|null
   */
  protected $skip;

  /**
   * Expand relationships.
   *
   * @var array
   */
  protected $expand = [];

  /**
   * Constructs an ODataQueryBuilder.
   *
   * @param string $entity_set
   *   The entity set name.
   */
  public function __construct($entity_set) {
    $this->entitySet = $this->sanitizeIdentifier($entity_set);
  }

  /**
   * Add select fields.
   *
   * @param array $fields
   *   Array of field names to select.
   *
   * @return $this
   */
  public function select(array $fields) {
    foreach ($fields as $field) {
      $this->select[] = $this->sanitizeIdentifier($field);
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
   */
  public function filter($field, $operator, $value) {
    $sanitized_field = $this->sanitizeIdentifier($field);
    $sanitized_operator = $this->sanitizeOperator($operator);
    $sanitized_value = $this->sanitizeValue($value);
    
    $this->filters[] = "{$sanitized_field} {$sanitized_operator} {$sanitized_value}";
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
   */
  public function orderBy($field, $direction = 'asc') {
    $sanitized_field = $this->sanitizeIdentifier($field);
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
   */
  public function top($limit) {
    $this->top = (int) $limit;
    return $this;
  }

  /**
   * Set the skip offset.
   *
   * @param int $offset
   *   The number of records to skip.
   *
   * @return $this
   */
  public function skip($offset) {
    $this->skip = (int) $offset;
    return $this;
  }

  /**
   * Add expand relationships.
   *
   * @param array $relationships
   *   Array of relationship names to expand.
   *
   * @return $this
   */
  public function expand(array $relationships) {
    foreach ($relationships as $relationship) {
      $this->expand[] = $this->sanitizeIdentifier($relationship);
    }
    return $this;
  }

  /**
   * Build the OData query URL.
   *
   * @return string
   *   The complete OData query URL.
   */
  public function build() {
    $url = $this->entitySet;
    $query_params = [];

    if (!empty($this->select)) {
      $query_params['$select'] = implode(',', $this->select);
    }

    if (!empty($this->filters)) {
      $query_params['$filter'] = implode(' and ', $this->filters);
    }

    if (!empty($this->orderBy)) {
      $query_params['$orderby'] = implode(',', $this->orderBy);
    }

    if ($this->top !== NULL) {
      $query_params['$top'] = $this->top;
    }

    if ($this->skip !== NULL) {
      $query_params['$skip'] = $this->skip;
    }

    if (!empty($this->expand)) {
      $query_params['$expand'] = implode(',', $this->expand);
    }

    if (!empty($query_params)) {
      $url .= '?' . http_build_query($query_params);
    }

    return $url;
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
  protected function sanitizeIdentifier($identifier) {
    // Allow only alphanumeric characters, underscores, and periods
    return preg_replace('/[^a-zA-Z0-9_.]/', '', $identifier);
  }

  /**
   * Sanitize comparison operators.
   *
   * @param string $operator
   *   The operator to sanitize.
   *
   * @return string
   *   The sanitized operator.
   */
  protected function sanitizeOperator($operator) {
    $allowed_operators = [
      'eq', 'ne', 'gt', 'ge', 'lt', 'le',
      'contains', 'startswith', 'endswith'
    ];
    
    $operator = strtolower(trim($operator));
    return in_array($operator, $allowed_operators) ? $operator : 'eq';
  }

  /**
   * Sanitize values for OData queries.
   *
   * @param mixed $value
   *   The value to sanitize.
   *
   * @return string
   *   The sanitized value.
   */
  protected function sanitizeValue($value) {
    if (is_string($value)) {
      // Escape single quotes and wrap in quotes
      return "'" . str_replace("'", "''", $value) . "'";
    }
    elseif (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    elseif (is_numeric($value)) {
      return (string) $value;
    }
    elseif ($value === NULL) {
      return 'null';
    }
    else {
      // Convert to string and treat as string
      return "'" . str_replace("'", "''", (string) $value) . "'";
    }
  }

}