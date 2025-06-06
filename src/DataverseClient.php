<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\dataverse_webform\Exception\DataverseException;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Dataverse client service for OData API integration with multi-entity support.
 */
class DataverseClient implements DataverseClientInterface {
  
  use StringTranslationTrait;

  /**
   * The API version for Dataverse OData endpoints.
   */
  public const API_VERSION = 'v9.2';

  /**
   * Maximum number of entities to process in a single batch.
   */
  public const MAX_BATCH_SIZE = 100;

  /**
   * Default request timeout in seconds.
   */
  public const DEFAULT_TIMEOUT = 30;

  /**
   * Default rate limit requests per hour.
   */
  public const DEFAULT_RATE_LIMIT = 100;

  /**
   * Memory usage threshold for large form processing (128MB).
   */
  public const MEMORY_THRESHOLD = 134217728;

  /**
   * The HTTP client factory.
   */
  protected ClientFactory $httpClientFactory;

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The Azure AD authentication service.
   */
  protected AzureAdAuthService $azureAuth;

  /**
   * The cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * The validation service.
   */
  protected ValidationService $validator;

  /**
   * The submission processor service.
   */
  protected SubmissionProcessor $submissionProcessor;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The state service.
   */
  protected StateInterface $state;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a DataverseClient object.
   *
   * @param \Drupal\Core\Http\ClientFactory $http_client_factory
   *   The HTTP client factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\dataverse_webform\AzureAdAuthService $azure_auth
   *   The Azure AD authentication service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\dataverse_webform\ValidationService $validator
   *   The validation service.
   * @param \Drupal\dataverse_webform\SubmissionProcessor $submission_processor
   *   The submission processor service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    ClientFactory $http_client_factory,
    LoggerChannelFactoryInterface $logger_factory,
    AzureAdAuthService $azure_auth,
    CacheBackendInterface $cache,
    ValidationService $validator,
    SubmissionProcessor $submission_processor,
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    AccountProxyInterface $current_user
  ) {
    $this->httpClientFactory = $http_client_factory;
    $this->loggerFactory = $logger_factory;
    $this->azureAuth = $azure_auth;
    $this->cache = $cache;
    $this->validator = $validator;
    $this->submissionProcessor = $submission_processor;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public function submitToDataverse(WebformSubmissionInterface $submission, array $config): array {
    try {
      // Check rate limit before processing
      $this->checkRateLimit();

      // Validate configuration
      $this->validator->validateConfig($config);

      // Get submission data
      $submission_data = $submission->getData();
      
      // Process field mappings to group by entity with memory monitoring
      $entities_data = $this->processFieldMappingsWithMemoryCheck(
        $submission_data, 
        $config['field_mappings'] ?? []
      );

      if (empty($entities_data)) {
        $this->loggerFactory->get('dataverse_webform')->warning(
          'No entity data to submit for webform submission @id',
          ['@id' => $submission->id()]
        );
        return [];
      }

      // Add submission metadata to all entities
      $metadata = [
        'dataverse_webform_submission_id' => $submission->id(),
        'dataverse_webform_submitted_at' => date('c', $submission->getCreatedTime()),
        'dataverse_webform_source' => $submission->getWebform()->id(),
      ];

      foreach ($entities_data as $entity_name => $entity_data) {
        $entities_data[$entity_name] = array_merge($entity_data, $metadata);
      }

      // Submit entities in the configured order or default order
      $submission_order = $config['submission_order'] ?? array_keys($entities_data);
      $results = [];

      foreach ($submission_order as $entity_name) {
        if (isset($entities_data[$entity_name])) {
          try {
            $result = $this->createEntity($config, $entity_name, $entities_data[$entity_name]);
            $results[$entity_name] = [
              'success' => true,
              'id' => $result['id'] ?? null,
              'data' => $result,
            ];

            $this->loggerFactory->get('dataverse_webform')->info(
              'Successfully created @entity entity for webform submission @submission_id',
              [
                '@entity' => $entity_name,
                '@submission_id' => $submission->id(),
              ]
            );

          } catch (DataverseException $e) {
            $results[$entity_name] = [
              'success' => false,
              'error' => $e->getMessage(),
            ];

            $this->loggerFactory->get('dataverse_webform')->error(
              'Failed to create @entity entity for webform submission @submission_id: @error',
              [
                '@entity' => $entity_name,
                '@submission_id' => $submission->id(),
                '@error' => $e->getMessage(),
              ]
            );

            // If entity creation fails and it's required, stop processing
            if ($config['stop_on_error'] ?? true) {
              break;
            }
          }
        }
      }

      // Record successful API call for rate limiting
      $this->recordApiCall();

      return $results;

    } catch (\Exception $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Failed to submit webform @id to Dataverse: @error',
        [
          '@id' => $submission->id(),
          '@error' => $e->getMessage(),
        ]
      );
      throw new DataverseException('Dataverse submission failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(array $config): bool {
    try {
      // Check rate limit
      $this->checkRateLimit();

      $this->validator->validateConfig($config);
      
      $access_token = $this->azureAuth->getAccessToken($config);
      if (!$access_token) {
        throw new DataverseException('Failed to obtain access token');
      }

      $client = $this->createHttpClient($config);

      // Test with a simple metadata request
      $response = $client->get('$metadata', [
        'headers' => $this->buildHeaders($access_token, ['Accept' => 'application/xml']),
        'timeout' => 10,
      ]);

      $success = $response->getStatusCode() === 200;
      
      if ($success) {
        $this->recordApiCall();
      }

      return $success;

    } catch (\Exception $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Dataverse connection test failed: @error',
        ['@error' => $e->getMessage()]
      );
      throw new DataverseException('Connection test failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEntities(array $config): array {
    // Generate cache key based on relevant config only
    $cache_config = array_intersect_key($config, array_flip([
      'dataverse_url', 
      'azure_tenant_id'
    ]));
    $cache_key = 'dataverse_webform:entities:' . hash('sha256', json_encode($cache_config));
    
    $cached = $this->cache->get($cache_key);
    if ($cached && $cached->data) {
      return $cached->data;
    }

    try {
      // Check rate limit
      $this->checkRateLimit();

      $this->validator->validateConfig($config);
      
      $access_token = $this->azureAuth->getAccessToken($config);
      if (!$access_token) {
        throw new DataverseException('Failed to get access token');
      }

      $client = $this->createHttpClient($config);

      // Use OData query builder for safe metadata query
      $query_builder = new ODataQueryBuilder('EntityDefinitions');
      $query_builder
        ->select([
          'LogicalName',
          'DisplayName',
          'SchemaName',
          'EntitySetName',
          'Description'
        ])
        ->filter('IsCustomizable/Value', 'eq', true)
        ->filter('IsValidForAdvancedFind/Value', 'eq', true)
        ->orderBy('DisplayName.UserLocalizedLabel.Label');

      $response = $client->get($query_builder->build(), [
        'headers' => $this->buildHeaders($access_token),
      ]);

      if ($response->getStatusCode() === 200) {
        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
          throw new DataverseException('Invalid JSON response from Dataverse');
        }

        $entities = [];
        if (isset($data['value'])) {
          foreach ($data['value'] as $entity) {
            $display_name = $entity['DisplayName']['UserLocalizedLabel']['Label'] ?? $entity['LogicalName'];
            $entities[$entity['LogicalName']] = [
              'logical_name' => $entity['LogicalName'],
              'display_name' => $display_name,
              'schema_name' => $entity['SchemaName'] ?? '',
              'entity_set_name' => $entity['EntitySetName'] ?? '',
              'description' => $entity['Description']['UserLocalizedLabel']['Label'] ?? '',
            ];
          }
        }

        // Cache with proper tags for selective invalidation
        $cache_tags = [
          'dataverse_webform:entities',
          'dataverse_webform:config:' . hash('sha256', $config['dataverse_url']),
          'dataverse_webform:metadata',
        ];
        
        $this->cache->set($cache_key, $entities, time() + 3600, $cache_tags);
        
        // Record successful API call
        $this->recordApiCall();
        
        return $entities;
      }

      throw new DataverseException('Failed to retrieve entities: HTTP ' . $response->getStatusCode());

    } catch (RequestException $e) {
      $error_message = 'Failed to fetch Dataverse entities: ' . $e->getMessage();
      $this->loggerFactory->get('dataverse_webform')->error($error_message);
      throw new DataverseException($error_message, 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityFields(array $config, string $entity_name): array {
    $this->validator->validateEntityName($entity_name);
    
    // Generate cache key with entity-specific information
    $cache_config = array_intersect_key($config, array_flip([
      'dataverse_url', 
      'azure_tenant_id'
    ]));
    $cache_key = 'dataverse_webform:fields:' . $entity_name . ':' . hash('sha256', json_encode($cache_config));
    
    $cached = $this->cache->get($cache_key);
    if ($cached && $cached->data) {
      return $cached->data;
    }

    try {
      // Check rate limit
      $this->checkRateLimit();

      $this->validator->validateConfig($config);
      
      $access_token = $this->azureAuth->getAccessToken($config);
      if (!$access_token) {
        throw new DataverseException('Failed to get access token');
      }

      $client = $this->createHttpClient($config);

      // Use OData query builder for safe field metadata query
      $query_builder = new ODataQueryBuilder('EntityDefinitions');
      $query_builder
        ->filter('LogicalName', 'eq', $entity_name)
        ->expand(['Attributes'])
        ->select(['LogicalName', 'Attributes']);

      $response = $client->get($query_builder->build(), [
        'headers' => $this->buildHeaders($access_token),
      ]);

      if ($response->getStatusCode() === 200) {
        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
          throw new DataverseException('Invalid JSON response from Dataverse');
        }

        $fields = [];
        if (isset($data['value'][0]['Attributes'])) {
          foreach ($data['value'][0]['Attributes'] as $attribute) {
            // Skip system fields and complex types
            if (
              !empty($attribute['IsValidForCreate']['Value']) &&
              !empty($attribute['IsCustomizable']['Value']) &&
              !in_array($attribute['AttributeType'], ['Virtual', 'EntityName', 'PartyList'])
            ) {
              $display_name = $attribute['DisplayName']['UserLocalizedLabel']['Label'] ?? $attribute['LogicalName'];
              $fields[$attribute['LogicalName']] = [
                'logical_name' => $attribute['LogicalName'],
                'display_name' => $display_name,
                'attribute_type' => $attribute['AttributeType'] ?? '',
                'description' => $attribute['Description']['UserLocalizedLabel']['Label'] ?? '',
                'is_required' => $attribute['RequiredLevel']['Value'] ?? 'None',
                'max_length' => $attribute['MaxLength'] ?? null,
                'is_primary_id' => $attribute['IsPrimaryId']['Value'] ?? false,
                'is_primary_name' => $attribute['IsPrimaryName']['Value'] ?? false,
              ];
            }
          }
        }

        // Sort by display name
        uasort($fields, fn($a, $b) => strcmp($a['display_name'], $b['display_name']));

        // Cache with proper tags
        $cache_tags = [
          'dataverse_webform:fields',
          'dataverse_webform:fields:' . $entity_name,
          'dataverse_webform:config:' . hash('sha256', $config['dataverse_url']),
          'dataverse_webform:metadata',
        ];
        
        $this->cache->set($cache_key, $fields, time() + 3600, $cache_tags);
        
        // Record successful API call
        $this->recordApiCall();
        
        return $fields;
      }

      throw new DataverseException('Failed to retrieve entity fields: HTTP ' . $response->getStatusCode());

    } catch (RequestException $e) {
      $error_message = "Failed to fetch Dataverse entity fields for {$entity_name}: " . $e->getMessage();
      $this->loggerFactory->get('dataverse_webform')->error($error_message);
      throw new DataverseException($error_message, 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createEntity(array $config, string $entity_name, array $data): array {
    $this->validator->validateEntityName($entity_name);
    $this->validator->validateEntityData($data);

    try {
      // Check rate limit
      $this->checkRateLimit();

      $access_token = $this->azureAuth->getAccessToken($config);
      if (!$access_token) {
        throw new DataverseException('Failed to get access token');
      }

      $client = $this->createHttpClient($config);

      // Sanitize and validate the entity data
      $sanitized_data = $this->submissionProcessor->sanitizeEntityData($data);

      $response = $client->post($entity_name, [
        'headers' => $this->buildHeaders($access_token, ['Content-Type' => 'application/json']),
        'json' => $sanitized_data,
        'timeout' => $config['timeout'] ?? self::DEFAULT_TIMEOUT,
      ]);

      if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
        $location = $response->getHeader('OData-EntityId')[0] ?? '';
        $entity_id = null;
        
        if (preg_match('/\(([^)]+)\)$/', $location, $matches)) {
          $entity_id = $matches[1];
        }

        // Record successful API call
        $this->recordApiCall();

        return [
          'id' => $entity_id,
          'location' => $location,
          'status_code' => $response->getStatusCode(),
        ];
      }

      throw new DataverseException('Entity creation failed: HTTP ' . $response->getStatusCode());

    } catch (RequestException $e) {
      $error_message = "Failed to create {$entity_name} entity: " . $e->getMessage();
      $this->loggerFactory->get('dataverse_webform')->error($error_message);
      throw new DataverseException($error_message, 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function batchCreateEntities(array $config, array $entities_data): array {
    $batch_size = min($config['batch_size'] ?? 10, self::MAX_BATCH_SIZE);
    $results = [];

    // Split entities into batches
    $batches = array_chunk($entities_data, $batch_size, true);

    foreach ($batches as $batch) {
      foreach ($batch as $entity_name => $entity_data) {
        try {
          $result = $this->createEntity($config, $entity_name, $entity_data);
          $results[$entity_name] = ['success' => true, 'data' => $result];
        } catch (DataverseException $e) {
          $results[$entity_name] = ['success' => false, 'error' => $e->getMessage()];
        }
      }

      // Add delay between batches to avoid rate limiting
      if (count($batches) > 1) {
        usleep(100000); // 100ms delay
      }
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function validateFieldMappings(array $config, array $field_mappings): array {
    $validation_results = [];

    try {
      $entities = $this->getEntities($config);
      
      foreach ($field_mappings as $mapping) {
        $entity_name = $mapping['entity'] ?? '';
        $field_name = $mapping['field'] ?? '';
        
        $result = [
          'entity_exists' => isset($entities[$entity_name]),
          'field_exists' => false,
          'field_valid' => false,
        ];

        if ($result['entity_exists']) {
          try {
            $fields = $this->getEntityFields($config, $entity_name);
            $result['field_exists'] = isset($fields[$field_name]);
            $result['field_valid'] = $result['field_exists'] && !empty($fields[$field_name]['is_valid']);
          } catch (DataverseException $e) {
            $result['error'] = $e->getMessage();
          }
        }

        $validation_results[] = $result;
      }

    } catch (DataverseException $e) {
      throw new DataverseException('Field mapping validation failed: ' . $e->getMessage(), 0, $e);
    }

    return $validation_results;
  }

  /**
   * Check rate limit for current user.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When rate limit is exceeded.
   */
  protected function checkRateLimit(): void {
    $config = $this->configFactory->get('dataverse_webform.settings');
    $rate_limit_enabled = $config->get('rate_limit_enabled');
    
    if (!$rate_limit_enabled) {
      return;
    }

    $max_requests = $config->get('rate_limit_requests_per_hour') ?? self::DEFAULT_RATE_LIMIT;
    $rate_limit_key = 'dataverse_webform:rate_limit:' . $this->currentUser->id();
    $requests = $this->state->get($rate_limit_key, []);
    
    // Clean old requests (older than 1 hour)
    $current_time = time();
    $requests = array_filter($requests, fn($time) => $time > ($current_time - 3600));
    
    if (count($requests) >= $max_requests) {
      throw DataverseException::validationError(
        "Rate limit exceeded: {$max_requests} requests per hour",
        ['user_id' => $this->currentUser->id(), 'requests_count' => count($requests)]
      );
    }
  }

  /**
   * Record an API call for rate limiting purposes.
   */
  protected function recordApiCall(): void {
    $config = $this->configFactory->get('dataverse_webform.settings');
    $rate_limit_enabled = $config->get('rate_limit_enabled');
    
    if (!$rate_limit_enabled) {
      return;
    }

    $rate_limit_key = 'dataverse_webform:rate_limit:' . $this->currentUser->id();
    $requests = $this->state->get($rate_limit_key, []);
    
    // Clean old requests and add current one
    $current_time = time();
    $requests = array_filter($requests, fn($time) => $time > ($current_time - 3600));
    $requests[] = $current_time;
    
    $this->state->set($rate_limit_key, $requests);
  }

  /**
   * Process field mappings with memory monitoring.
   *
   * @param array $submission_data
   *   The webform submission data.
   * @param array $field_mappings
   *   The field mapping configuration.
   *
   * @return array
   *   Array of entity data grouped by entity name.
   */
  protected function processFieldMappingsWithMemoryCheck(array $submission_data, array $field_mappings): array {
    // Check memory usage before processing large datasets
    if (memory_get_usage() > self::MEMORY_THRESHOLD) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'High memory usage detected before field mapping processing: @memory',
        ['@memory' => memory_get_usage(true)]
      );
    }

    $entities_data = $this->submissionProcessor->processFieldMappings($submission_data, $field_mappings);

    // Monitor memory after processing
    if (memory_get_usage() > self::MEMORY_THRESHOLD) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'High memory usage detected after field mapping processing: @memory',
        ['@memory' => memory_get_usage(true)]
      );
    }

    return $entities_data;
  }

  /**
   * Create HTTP client with base configuration.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return \GuzzleHttp\ClientInterface
   *   The configured HTTP client.
   */
  protected function createHttpClient(array $config): \GuzzleHttp\ClientInterface {
    return $this->httpClientFactory->fromOptions([
      'base_uri' => rtrim($config['dataverse_url'], '/') . '/api/data/' . self::API_VERSION . '/',
      'timeout' => $config['timeout'] ?? self::DEFAULT_TIMEOUT,
    ]);
  }

  /**
   * Build standard headers for Dataverse requests.
   *
   * @param string $access_token
   *   The access token.
   * @param array $additional_headers
   *   Additional headers to include.
   *
   * @return array
   *   The headers array.
   */
  protected function buildHeaders(string $access_token, array $additional_headers = []): array {
    $headers = [
      'Authorization' => 'Bearer ' . $access_token,
      'Accept' => 'application/json',
      'OData-MaxVersion' => '4.0',
      'OData-Version' => '4.0',
    ];

    return array_merge($headers, $additional_headers);
  }

}