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

/**
 * Dataverse client service for OData API integration.
 */
class DataverseClient implements DataverseClientInterface {

  public const API_VERSION = 'v9.2';
  public const MAX_BATCH_SIZE = 100;
  public const DEFAULT_TIMEOUT = 30;
  public const DEFAULT_RATE_LIMIT = 100;

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

  public function testConnection(array $config): bool {
    $cached_result = $this->cacheManager->getCachedTokenValidation($config);
    if ($cached_result !== NULL) {
      return $cached_result;
    }

    $this->checkRateLimit();
    $this->validator->validateConfig($config);
    
    $access_token = $this->azureAuth->getAccessToken($config);
    if (!$access_token) {
      throw new DataverseException('Failed to obtain access token');
    }

    $client = $this->createHttpClient($config);
    $response = $client->get('$metadata', [
      'headers' => $this->buildHeaders($access_token, ['Accept' => 'application/xml']),
      'timeout' => 10,
    ]);

    $success = $response->getStatusCode() === 200;
    $this->cacheManager->setCachedTokenValidation($config, $success);
    
    if ($success) {
      $this->recordApiCall();
    }

    return $success;
  }

  public function getEntities(array $config): array {
    $cached_entities = $this->cacheManager->getCachedEntities($config);
    if ($cached_entities !== NULL) {
      return $cached_entities;
    }

    $this->checkRateLimit();
    $this->validator->validateConfig($config);
    
    $access_token = $this->azureAuth->getAccessToken($config);
    if (!$access_token) {
      throw new DataverseException('Failed to get access token');
    }

    $query_builder = new ODataQueryBuilder('EntityDefinitions');
    $query_builder
      ->select(['LogicalName', 'DisplayName', 'SchemaName', 'EntitySetName', 'Description'])
      ->filter('IsCustomizable/Value', 'eq', true)
      ->filter('IsValidForAdvancedFind/Value', 'eq', true)
      ->orderBy('DisplayName.UserLocalizedLabel.Label');

    $response = $this->createHttpClient($config)->get($query_builder->build(), [
      'headers' => $this->buildHeaders($access_token),
    ]);

    $entities = $this->parseEntitiesResponse($response);
    $this->cacheManager->setCachedEntities($config, $entities, DataverseCacheManager::LONG_TTL);
    $this->recordApiCall();
    
    return $entities;
  }

  public function getEntityFields(array $config, string $entity_name): array {
    $this->validator->validateEntityName($entity_name);
    
    $cached_fields = $this->cacheManager->getCachedEntityFields($config, $entity_name);
    if ($cached_fields !== NULL) {
      return $cached_fields;
    }

    $this->checkRateLimit();
    $this->validator->validateConfig($config);
    
    $access_token = $this->azureAuth->getAccessToken($config);
    if (!$access_token) {
      throw new DataverseException('Failed to get access token');
    }

    $query_builder = new ODataQueryBuilder('EntityDefinitions');
    $query_builder
      ->filter('LogicalName', 'eq', $entity_name)
      ->expand(['Attributes'])
      ->select(['LogicalName', 'Attributes']);

    $response = $this->createHttpClient($config)->get($query_builder->build(), [
      'headers' => $this->buildHeaders($access_token),
    ]);

    $fields = $this->parseFieldsResponse($response);
    $this->cacheManager->setCachedEntityFields($config, $entity_name, $fields);
    $this->recordApiCall();
    
    return $fields;
  }

  public function createEntity(array $config, string $entity_name, array $data): array {
    $this->validator->validateEntityName($entity_name);
    $this->validator->validateEntityData($data);
    $this->checkRateLimit();

    $access_token = $this->azureAuth->getAccessToken($config);
    if (!$access_token) {
      throw new DataverseException('Failed to get access token');
    }

    $sanitized_data = $this->submissionProcessor->sanitizeEntityData($data);
    $response = $this->createHttpClient($config)->post($entity_name, [
      'headers' => $this->buildHeaders($access_token, ['Content-Type' => 'application/json']),
      'json' => $sanitized_data,
      'timeout' => $config['timeout'] ?? self::DEFAULT_TIMEOUT,
    ]);

    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      throw new DataverseException('Entity creation failed: HTTP ' . $response->getStatusCode());
    }

    $this->recordApiCall();
    return $this->parseEntityCreationResponse($response);
  }

  public function batchCreateEntities(array $config, array $entities_data): array {
    $batch_size = min($config['batch_size'] ?? 10, self::MAX_BATCH_SIZE);
    $results = [];

    foreach (array_chunk($entities_data, $batch_size, true) as $batch) {
      foreach ($batch as $entity_name => $entity_data) {
        try {
          $result = $this->createEntity($config, $entity_name, $entity_data);
          $results[$entity_name] = ['success' => true, 'data' => $result];
        } catch (DataverseException $e) {
          $results[$entity_name] = ['success' => false, 'error' => $e->getMessage()];
        }
      }
      
      if (count($entities_data) > $batch_size) {
        usleep(100000); // 100ms delay between batches
      }
    }

    return $results;
  }

  public function validateFieldMappings(array $config, array $field_mappings): array {
    $validation_results = [];
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
          $result['field_valid'] = $result['field_exists'];
        } catch (DataverseException $e) {
          $result['error'] = $e->getMessage();
        }
      }

      $validation_results[] = $result;
    }

    return $validation_results;
  }

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

  protected function submitEntitiesInOrder(array $entities_data, array $config): array {
    $submission_order = $config['submission_order'] ?? array_keys($entities_data);
    $results = [];

    foreach ($submission_order as $entity_name) {
      if (!isset($entities_data[$entity_name])) {
        continue;
      }

      try {
        $result = $this->createEntity($config, $entity_name, $entities_data[$entity_name]);
        $results[$entity_name] = ['success' => true, 'id' => $result['id'] ?? null, 'data' => $result];
      } catch (DataverseException $e) {
        $results[$entity_name] = ['success' => false, 'error' => $e->getMessage()];
        
        if ($config['stop_on_error'] ?? true) {
          break;
        }
      }
    }

    return $results;
  }

  protected function parseEntitiesResponse($response): array {
    if ($response->getStatusCode() !== 200) {
      throw new DataverseException('Failed to retrieve entities: HTTP ' . $response->getStatusCode());
    }

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

    return $entities;
  }

  protected function parseFieldsResponse($response): array {
    if ($response->getStatusCode() !== 200) {
      throw new DataverseException('Failed to retrieve entity fields: HTTP ' . $response->getStatusCode());
    }

    $content = $response->getBody()->getContents();
    $data = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new DataverseException('Invalid JSON response from Dataverse');
    }

    $fields = [];
    if (isset($data['value'][0]['Attributes'])) {
      foreach ($data['value'][0]['Attributes'] as $attribute) {
        if ($this->isValidFieldAttribute($attribute)) {
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

    uasort($fields, fn($a, $b) => strcmp($a['display_name'], $b['display_name']));
    return $fields;
  }

  protected function isValidFieldAttribute(array $attribute): bool {
    return !empty($attribute['IsValidForCreate']['Value']) &&
           !empty($attribute['IsCustomizable']['Value']) &&
           !in_array($attribute['AttributeType'], ['Virtual', 'EntityName', 'PartyList']);
  }

  protected function parseEntityCreationResponse($response): array {
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

  protected function checkRateLimit(): void {
    $config = $this->configFactory->get('dataverse_webform.settings');
    if (!$config->get('rate_limit_enabled')) {
      return;
    }

    $max_requests = $config->get('rate_limit_requests_per_hour') ?? self::DEFAULT_RATE_LIMIT;
    $rate_limit_key = 'dataverse_webform:rate_limit:' . $this->currentUser->id();
    $requests = $this->state->get($rate_limit_key, []);
    
    $current_time = time();
    $requests = array_filter($requests, fn($time) => $time > ($current_time - 3600));
    
    if (count($requests) >= $max_requests) {
      throw DataverseException::validationError(
        "Rate limit exceeded: {$max_requests} requests per hour",
        ['user_id' => $this->currentUser->id(), 'requests_count' => count($requests)]
      );
    }
  }

  protected function recordApiCall(): void {
    $config = $this->configFactory->get('dataverse_webform.settings');
    if (!$config->get('rate_limit_enabled')) {
      return;
    }

    $rate_limit_key = 'dataverse_webform:rate_limit:' . $this->currentUser->id();
    $requests = $this->state->get($rate_limit_key, []);
    
    $current_time = time();
    $requests = array_filter($requests, fn($time) => $time > ($current_time - 3600));
    $requests[] = $current_time;
    
    $this->state->set($rate_limit_key, $requests);
  }

  protected function createHttpClient(array $config): \GuzzleHttp\ClientInterface {
    return $this->httpClientFactory->fromOptions([
      'base_uri' => rtrim($config['dataverse_url'], '/') . '/api/data/' . self::API_VERSION . '/',
      'timeout' => $config['timeout'] ?? self::DEFAULT_TIMEOUT,
    ]);
  }

  protected function buildHeaders(string $access_token, array $additional_headers = []): array {
    return array_merge([
      'Authorization' => 'Bearer ' . $access_token,
      'Accept' => 'application/json',
      'OData-MaxVersion' => '4.0',
      'OData-Version' => '4.0',
    ], $additional_headers);
  }

  public function invalidateConfigurationCache(array $config): void {
    $this->cacheManager->invalidateConfigCache($config);
    $this->azureAuth->invalidateToken($config);
  }

  public function getCacheManager(): DataverseCacheManager {
    return $this->cacheManager;
  }
}