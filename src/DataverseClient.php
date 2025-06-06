<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\dataverse_webform\Exception\DataverseException;
use Drupal\dataverse_webform\Cache\DataverseCacheManager;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Dataverse client service for OData API integration.
 */
class DataverseClient implements DataverseClientInterface {

  public const API_VERSION = 'v9.2';
  public const MAX_BATCH_SIZE = 100;
  public const DEFAULT_TIMEOUT = 30;
  public const DEFAULT_RATE_LIMIT = 100;
  public const RATE_LIMIT_WINDOW = 3600;
  public const SUCCESS_STATUS_MIN = 200;
  public const SUCCESS_STATUS_MAX = 299;

  protected ClientFactory $httpClientFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected AzureAdAuthService $azureAuth;
  protected DataverseCacheManager $cacheManager;
  protected ValidationService $validator;
  protected SubmissionProcessor $submissionProcessor;
  protected ConfigFactoryInterface $configFactory;
  protected StateInterface $state;
  protected AccountProxyInterface $currentUser;

  public function __construct(
    ClientFactory $http_client_factory,
    LoggerChannelFactoryInterface $logger_factory,
    AzureAdAuthService $azure_auth,
    DataverseCacheManager $cache_manager,
    ValidationService $validator,
    SubmissionProcessor $submission_processor,
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    AccountProxyInterface $current_user
  ) {
    $this->httpClientFactory = $http_client_factory;
    $this->loggerFactory = $logger_factory;
    $this->azureAuth = $azure_auth;
    $this->cacheManager = $cache_manager;
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
    $this->checkRateLimit();
    $this->validator->validateConfig($config);

    $entities_data = $this->processSubmissionData($submission, $config);
    if (empty($entities_data)) {
      return [];
    }

    $this->addSubmissionMetadata($entities_data, $submission);
    $results = $this->submitEntitiesInOrder($entities_data, $config);
    
    $this->recordApiCall();
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(array $config): bool {
    $cached_result = $this->cacheManager->getCachedTokenValidation($config);
    if ($cached_result !== null) {
      return $cached_result;
    }

    $this->checkRateLimit();
    $this->validator->validateConfig($config);
    
    try {
      $access_token = $this->getValidAccessToken($config);
      $response = $this->executeMetadataRequest($config, $access_token);
      $success = $this->isSuccessResponse($response);
      
      $this->cacheManager->setCachedTokenValidation($config, $success);
      
      if ($success) {
        $this->recordApiCall();
      }

      return $success;
    } catch (RequestException $e) {
      throw new DataverseException('Connection test failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEntities(array $config): array {
    $cached_entities = $this->cacheManager->getCachedEntities($config);
    if ($cached_entities !== null) {
      return $cached_entities;
    }

    $this->checkRateLimit();
    $this->validator->validateConfig($config);
    
    $access_token = $this->getValidAccessToken($config);
    $query_builder = $this->createEntitiesQuery();

    try {
      $response = $this->executeRequest($config, $query_builder->build(), $access_token);
      $entities = $this->parseEntitiesResponse($response);
      
      $this->cacheManager->setCachedEntities($config, $entities, DataverseCacheManager::LONG_TTL);
      $this->recordApiCall();
      
      return $entities;
    } catch (RequestException $e) {
      throw new DataverseException('Failed to retrieve entities: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityFields(array $config, string $entity_name): array {
    $this->validator->validateEntityName($entity_name);
    
    $cached_fields = $this->cacheManager->getCachedEntityFields($config, $entity_name);
    if ($cached_fields !== null) {
      return $cached_fields;
    }

    $this->checkRateLimit();
    $this->validator->validateConfig($config);
    
    $access_token = $this->getValidAccessToken($config);
    $query_builder = $this->createEntityFieldsQuery($entity_name);

    try {
      $response = $this->executeRequest($config, $query_builder->build(), $access_token);
      $fields = $this->parseFieldsResponse($response);
      
      $this->cacheManager->setCachedEntityFields($config, $entity_name, $fields);
      $this->recordApiCall();
      
      return $fields;
    } catch (RequestException $e) {
      throw new DataverseException('Failed to retrieve entity fields: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createEntity(array $config, string $entity_name, array $data): array {
    $this->validator->validateEntityName($entity_name);
    $this->validator->validateEntityData($data);
    $this->checkRateLimit();

    $access_token = $this->getValidAccessToken($config);
    $sanitized_data = $this->submissionProcessor->sanitizeEntityData($data);

    try {
      $response = $this->executeEntityCreationRequest($config, $entity_name, $sanitized_data, $access_token);
      $this->validateResponseStatus($response, 'Entity creation failed');
      $this->recordApiCall();
      
      return $this->parseEntityCreationResponse($response);
    } catch (RequestException $e) {
      throw new DataverseException('Entity creation request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function batchCreateEntities(array $config, array $entities_data): array {
    $batch_size = min($config['batch_size'] ?? 10, self::MAX_BATCH_SIZE);
    $results = [];

    foreach (array_chunk($entities_data, $batch_size, true) as $batch) {
      $results = array_merge($results, $this->processBatch($config, $batch));
      
      if (count($entities_data) > $batch_size) {
        usleep(100000); // 100ms delay between batches
      }
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function validateFieldMappings(array $config, array $field_mappings): array {
    $validation_results = [];
    $entities = $this->getEntities($config);
    
    foreach ($field_mappings as $mapping) {
      $validation_results[] = $this->validateSingleMapping($config, $mapping, $entities);
    }

    return $validation_results;
  }

  /**
   * Invalidate configuration cache.
   */
  public function invalidateConfigurationCache(array $config): void {
    $this->cacheManager->invalidateConfigCache($config);
    $this->azureAuth->invalidateToken($config);
  }

  /**
   * Get cache manager.
   */
  public function getCacheManager(): DataverseCacheManager {
    return $this->cacheManager;
  }

  /**
   * Process submission data into entities.
   */
  protected function processSubmissionData(WebformSubmissionInterface $submission, array $config): array {
    $submission_data = $submission->getData();
    $field_mappings = $config['field_mappings'] ?? [];
    
    $entities_data = $this->submissionProcessor->processFieldMappings($submission_data, $field_mappings);
    
    if (empty($entities_data)) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'No entity data to submit for webform submission @id',
        ['@id' => $submission->id()]
      );
    }
    
    return $entities_data;
  }

  /**
   * Add submission metadata to entities.
   */
  protected function addSubmissionMetadata(array &$entities_data, WebformSubmissionInterface $submission): void {
    $metadata = [
      'dataverse_webform_submission_id' => $submission->id(),
      'dataverse_webform_submitted_at' => date('c', $submission->getCreatedTime()),
      'dataverse_webform_source' => $submission->getWebform()->id(),
    ];

    foreach ($entities_data as $entity_name => $entity_data) {
      $entities_data[$entity_name] = array_merge($entity_data, $metadata);
    }
  }

  /**
   * Submit entities in specified order.
   */
  protected function submitEntitiesInOrder(array $entities_data, array $config): array {
    $submission_order = $config['submission_order'] ?? array_keys($entities_data);
    $results = [];

    foreach ($submission_order as $entity_name) {
      if (!isset($entities_data[$entity_name])) {
        continue;
      }

      $results[$entity_name] = $this->createSingleEntityWithErrorHandling(
        $config, 
        $entity_name, 
        $entities_data[$entity_name]
      );
      
      if (!$results[$entity_name]['success'] && ($config['stop_on_error'] ?? true)) {
        break;
      }
    }

    return $results;
  }

  /**
   * Create single entity with error handling.
   */
  protected function createSingleEntityWithErrorHandling(array $config, string $entity_name, array $entity_data): array {
    try {
      $result = $this->createEntity($config, $entity_name, $entity_data);
      return [
        'success' => true, 
        'id' => $result['id'] ?? null, 
        'data' => $result
      ];
    } catch (DataverseException $e) {
      return [
        'success' => false, 
        'error' => $e->getMessage(),
        'error_code' => $e->getDataverseErrorCode(),
      ];
    }
  }

  /**
   * Process a batch of entities.
   */
  protected function processBatch(array $config, array $batch): array {
    $results = [];
    
    foreach ($batch as $entity_name => $entity_data) {
      $results[$entity_name] = $this->createSingleEntityWithErrorHandling($config, $entity_name, $entity_data);
    }
    
    return $results;
  }

  /**
   * Validate single field mapping.
   */
  protected function validateSingleMapping(array $config, array $mapping, array $entities): array {
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
        $result['field_valid'] = $result['field_exists'];
      } catch (DataverseException $e) {
        $result['error'] = $e->getMessage();
      }
    }

    return $result;
  }

  /**
   * Get valid access token.
   */
  protected function getValidAccessToken(array $config): string {
    $access_token = $this->azureAuth->getAccessToken($config);
    if (!$access_token) {
      throw new DataverseException('Failed to get access token');
    }
    return $access_token;
  }

  /**
   * Execute metadata request for connection testing.
   */
  protected function executeMetadataRequest(array $config, string $access_token): ResponseInterface {
    $client = $this->createHttpClient($config);
    return $client->get('$metadata', [
      'headers' => $this->buildHeaders($access_token, ['Accept' => 'application/xml']),
      'timeout' => 10,
    ]);
  }

  /**
   * Execute entity creation request.
   */
  protected function executeEntityCreationRequest(array $config, string $entity_name, array $data, string $access_token): ResponseInterface {
    return $this->createHttpClient($config)->post($entity_name, [
      'headers' => $this->buildHeaders($access_token, ['Content-Type' => 'application/json']),
      'json' => $data,
      'timeout' => $config['timeout'] ?? self::DEFAULT_TIMEOUT,
    ]);
  }

  /**
   * Execute general request.
   */
  protected function executeRequest(array $config, string $endpoint, string $access_token): ResponseInterface {
    return $this->createHttpClient($config)->get($endpoint, [
      'headers' => $this->buildHeaders($access_token),
    ]);
  }

  /**
   * Check if response is successful.
   */
  protected function isSuccessResponse(ResponseInterface $response): bool {
    $status_code = $response->getStatusCode();
    return $status_code >= self::SUCCESS_STATUS_MIN && $status_code <= self::SUCCESS_STATUS_MAX;
  }

  /**
   * Validate response status.
   */
  protected function validateResponseStatus(ResponseInterface $response, string $error_context): void {
    if (!$this->isSuccessResponse($response)) {
      throw DataverseException::fromHttpResponse($response, $error_context);
    }
  }

  /**
   * Create entities query.
   */
  protected function createEntitiesQuery(): ODataQueryBuilder {
    return (new ODataQueryBuilder('EntityDefinitions'))
      ->select(['LogicalName', 'DisplayName', 'SchemaName', 'EntitySetName', 'Description'])
      ->filter('IsCustomizable/Value', 'eq', true)
      ->filter('IsValidForAdvancedFind/Value', 'eq', true)
      ->orderBy('DisplayName.UserLocalizedLabel.Label');
  }

  /**
   * Create entity fields query.
   */
  protected function createEntityFieldsQuery(string $entity_name): ODataQueryBuilder {
    return (new ODataQueryBuilder('EntityDefinitions'))
      ->filter('LogicalName', 'eq', $entity_name)
      ->expand(['Attributes'])
      ->select(['LogicalName', 'Attributes']);
  }

  /**
   * Parse entities response.
   */
  protected function parseEntitiesResponse(ResponseInterface $response): array {
    $this->validateResponseStatus($response, 'Failed to retrieve entities');
    $data = $this->parseJsonResponse($response);
    $entities = [];
    
    if (isset($data['value'])) {
      foreach ($data['value'] as $entity) {
        $entities[$entity['LogicalName']] = $this->buildEntityData($entity);
      }
    }

    return $entities;
  }

  /**
   * Build entity data structure.
   */
  protected function buildEntityData(array $entity): array {
    return [
      'logical_name' => $entity['LogicalName'],
      'display_name' => $entity['DisplayName']['UserLocalizedLabel']['Label'] ?? $entity['LogicalName'],
      'schema_name' => $entity['SchemaName'] ?? '',
      'entity_set_name' => $entity['EntitySetName'] ?? '',
      'description' => $entity['Description']['UserLocalizedLabel']['Label'] ?? '',
    ];
  }

  /**
   * Parse fields response.
   */
  protected function parseFieldsResponse(ResponseInterface $response): array {
    $this->validateResponseStatus($response, 'Failed to retrieve entity fields');
    $data = $this->parseJsonResponse($response);
    $fields = [];
    
    if (isset($data['value'][0]['Attributes'])) {
      foreach ($data['value'][0]['Attributes'] as $attribute) {
        if ($this->isValidFieldAttribute($attribute)) {
          $fields[$attribute['LogicalName']] = $this->buildFieldData($attribute);
        }
      }
    }

    uasort($fields, fn($a, $b) => strcmp($a['display_name'], $b['display_name']));
    return $fields;
  }

  /**
   * Build field data structure.
   */
  protected function buildFieldData(array $attribute): array {
    return [
      'logical_name' => $attribute['LogicalName'],
      'display_name' => $attribute['DisplayName']['UserLocalizedLabel']['Label'] ?? $attribute['LogicalName'],
      'attribute_type' => $attribute['AttributeType'] ?? '',
      'description' => $attribute['Description']['UserLocalizedLabel']['Label'] ?? '',
      'is_required' => $attribute['RequiredLevel']['Value'] ?? 'None',
      'max_length' => $attribute['MaxLength'] ?? null,
      'is_primary_id' => $attribute['IsPrimaryId']['Value'] ?? false,
      'is_primary_name' => $attribute['IsPrimaryName']['Value'] ?? false,
    ];
  }

  /**
   * Parse JSON response.
   */
  protected function parseJsonResponse(ResponseInterface $response): array {
    $content = $response->getBody()->getContents();
    $data = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new DataverseException('Invalid JSON response from Dataverse: ' . json_last_error_msg());
    }
    
    return $data;
  }

  /**
   * Check if field attribute is valid.
   */
  protected function isValidFieldAttribute(array $attribute): bool {
    $invalid_types = ['Virtual', 'EntityName', 'PartyList'];
    
    return !empty($attribute['IsValidForCreate']['Value']) &&
           !empty($attribute['IsCustomizable']['Value']) &&
           !in_array($attribute['AttributeType'], $invalid_types);
  }

  /**
   * Parse entity creation response.
   */
  protected function parseEntityCreationResponse(ResponseInterface $response): array {
    $location = $response->getHeader('OData-EntityId')[0] ?? '';
    $entity_id = null;
    
    if (preg_match('/\(([^)]+)\)$/', $location, $matches)) {
      $entity_id = $matches[1];
    }

    return [
      'id' => $entity_id,
      'location' => $location,
      'status_code' => $response->getStatusCode(),
    ];
  }

  /**
   * Check rate limit.
   */
  protected function checkRateLimit(): void {
    $config = $this->configFactory->get('dataverse_webform.settings');
    if (!$config->get('rate_limit_enabled')) {
      return;
    }

    $max_requests = $config->get('rate_limit_requests_per_hour') ?? self::DEFAULT_RATE_LIMIT;
    $rate_limit_key = 'dataverse_webform:rate_limit:' . $this->currentUser->id();
    $requests = $this->state->get($rate_limit_key, []);
    
    $current_time = time();
    $requests = array_filter($requests, fn($time) => $time > ($current_time - self::RATE_LIMIT_WINDOW));
    
    if (count($requests) >= $max_requests) {
      throw DataverseException::rateLimitError(
        "Rate limit exceeded: {$max_requests} requests per hour",
        ['user_id' => $this->currentUser->id(), 'requests_count' => count($requests)]
      );
    }
  }

  /**
   * Record API call for rate limiting.
   */
  protected function recordApiCall(): void {
    $config = $this->configFactory->get('dataverse_webform.settings');
    if (!$config->get('rate_limit_enabled')) {
      return;
    }

    $rate_limit_key = 'dataverse_webform:rate_limit:' . $this->currentUser->id();
    $requests = $this->state->get($rate_limit_key, []);
    
    $current_time = time();
    $requests = array_filter($requests, fn($time) => $time > ($current_time - self::RATE_LIMIT_WINDOW));
    $requests[] = $current_time;
    
    $this->state->set($rate_limit_key, $requests);
  }

  /**
   * Create HTTP client.
   */
  protected function createHttpClient(array $config): \GuzzleHttp\ClientInterface {
    return $this->httpClientFactory->fromOptions([
      'base_uri' => rtrim($config['dataverse_url'], '/') . '/api/data/' . self::API_VERSION . '/',
      'timeout' => $config['timeout'] ?? self::DEFAULT_TIMEOUT,
      'connect_timeout' => 10,
    ]);
  }

  /**
   * Build HTTP headers.
   */
  protected function buildHeaders(string $access_token, array $additional_headers = []): array {
    return array_merge([
      'Authorization' => 'Bearer ' . $access_token,
      'Accept' => 'application/json',
      'OData-MaxVersion' => '4.0',
      'OData-Version' => '4.0',
    ], $additional_headers);
  }

}