<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Dataverse client service for OData API integration.
 */
class DataverseClient implements DataverseClientInterface {
  
  use StringTranslationTrait;

  /**
   * The HTTP client factory.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected $httpClientFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The Azure AD authentication service.
   *
   * @var \Drupal\dataverse_webform\AzureAdAuthService
   */
  protected $azureAuth;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

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
   */
  public function __construct(
    ClientFactory $http_client_factory,
    LoggerChannelFactoryInterface $logger_factory,
    AzureAdAuthService $azure_auth,
    CacheBackendInterface $cache
  ) {
    $this->httpClientFactory = $http_client_factory;
    $this->loggerFactory = $logger_factory;
    $this->azureAuth = $azure_auth;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public function submitToDataverse(WebformSubmissionInterface $submission, array $config) {
    try {
      $access_token = $this->azureAuth->getAccessToken($config);
      if (!$access_token) {
        $this->loggerFactory->get('dataverse_webform')->error('Failed to get access token for Dataverse submission');
        return FALSE;
      }

      $client = $this->httpClientFactory->fromOptions([
        'base_uri' => rtrim($config['dataverse_url'], '/') . '/api/data/v9.2/',
        'timeout' => 30,
      ]);

      $submission_data = $submission->getData();
      $mapped_data = $this->mapFields($submission_data, $config['field_mapping'] ?? []);

      // Use OData query builder to construct safe entity creation
      $entity_name = $config['target_entity'] ?? 'contacts';
      
      // Add submission metadata
      $mapped_data['dataverse_webform_submission_id'] = $submission->id();
      $mapped_data['dataverse_webform_submitted_at'] = date('c', $submission->getCreatedTime());

      $response = $client->post($entity_name, [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'OData-MaxVersion' => '4.0',
          'OData-Version' => '4.0',
        ],
        'json' => $mapped_data,
      ]);

      if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
        $this->loggerFactory->get('dataverse_webform')->info(
          'Successfully submitted webform @id to Dataverse entity @entity',
          [
            '@id' => $submission->id(),
            '@entity' => $entity_name,
          ]
        );
        return TRUE;
      }

      return FALSE;

    } catch (RequestException $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Failed to submit webform @id to Dataverse: @error',
        [
          '@id' => $submission->id(),
          '@error' => $e->getMessage(),
        ]
      );
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(array $config) {
    try {
      $access_token = $this->azureAuth->getAccessToken($config);
      if (!$access_token) {
        return FALSE;
      }

      $client = $this->httpClientFactory->fromOptions([
        'base_uri' => rtrim($config['dataverse_url'], '/') . '/api/data/v9.2/',
        'timeout' => 10,
      ]);

      // Test with a simple metadata request
      $response = $client->get('$metadata', [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Accept' => 'application/xml',
        ],
      ]);

      return $response->getStatusCode() === 200;

    } catch (RequestException $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Dataverse connection test failed: @error',
        ['@error' => $e->getMessage()]
      );
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEntities(array $config) {
    $cache_key = 'dataverse_webform:entities:' . md5(serialize($config));
    $cached = $this->cache->get($cache_key);
    
    if ($cached && $cached->data) {
      return $cached->data;
    }

    try {
      $access_token = $this->azureAuth->getAccessToken($config);
      if (!$access_token) {
        return [];
      }

      $client = $this->httpClientFactory->fromOptions([
        'base_uri' => rtrim($config['dataverse_url'], '/') . '/api/data/v9.2/',
        'timeout' => 30,
      ]);

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
        ->filter('IsCustomizable/Value', 'eq', TRUE)
        ->filter('IsValidForAdvancedFind/Value', 'eq', TRUE)
        ->orderBy('DisplayName.UserLocalizedLabel.Label');

      $response = $client->get($query_builder->build(), [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Accept' => 'application/json',
          'OData-MaxVersion' => '4.0',
          'OData-Version' => '4.0',
        ],
      ]);

      if ($response->getStatusCode() === 200) {
        $data = json_decode($response->getBody()->getContents(), TRUE);
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

        // Cache for 1 hour
        $this->cache->set($cache_key, $entities, time() + 3600);
        return $entities;
      }

    } catch (RequestException $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Failed to fetch Dataverse entities: @error',
        ['@error' => $e->getMessage()]
      );
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityFields(array $config, $entity_name) {
    $cache_key = 'dataverse_webform:fields:' . $entity_name . ':' . md5(serialize($config));
    $cached = $this->cache->get($cache_key);
    
    if ($cached && $cached->data) {
      return $cached->data;
    }

    try {
      $access_token = $this->azureAuth->getAccessToken($config);
      if (!$access_token) {
        return [];
      }

      $client = $this->httpClientFactory->fromOptions([
        'base_uri' => rtrim($config['dataverse_url'], '/') . '/api/data/v9.2/',
        'timeout' => 30,
      ]);

      // Use OData query builder for safe field metadata query
      $query_builder = new ODataQueryBuilder('EntityDefinitions');
      $query_builder
        ->filter('LogicalName', 'eq', $entity_name)
        ->expand(['Attributes'])
        ->select([
          'LogicalName',
          'Attributes'
        ]);

      $response = $client->get($query_builder->build(), [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Accept' => 'application/json',
          'OData-MaxVersion' => '4.0',
          'OData-Version' => '4.0',
        ],
      ]);

      if ($response->getStatusCode() === 200) {
        $data = json_decode($response->getBody()->getContents(), TRUE);
        $fields = [];

        if (isset($data['value'][0]['Attributes'])) {
          foreach ($data['value'][0]['Attributes'] as $attribute) {
            // Skip system fields and complex types
            if (
              !empty($attribute['IsValidForCreate']['Value']) &&
              !empty($attribute['IsCustomizable']['Value']) &&
              !in_array($attribute['AttributeType'], ['Virtual', 'EntityName'])
            ) {
              $display_name = $attribute['DisplayName']['UserLocalizedLabel']['Label'] ?? $attribute['LogicalName'];
              $fields[$attribute['LogicalName']] = [
                'logical_name' => $attribute['LogicalName'],
                'display_name' => $display_name,
                'attribute_type' => $attribute['AttributeType'] ?? '',
                'description' => $attribute['Description']['UserLocalizedLabel']['Label'] ?? '',
                'is_required' => $attribute['RequiredLevel']['Value'] ?? 'None',
                'max_length' => $attribute['MaxLength'] ?? NULL,
              ];
            }
          }
        }

        // Sort by display name
        uasort($fields, function ($a, $b) {
          return strcmp($a['display_name'], $b['display_name']);
        });

        // Cache for 1 hour
        $this->cache->set($cache_key, $fields, time() + 3600);
        return $fields;
      }

    } catch (RequestException $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Failed to fetch Dataverse entity fields for @entity: @error',
        [
          '@entity' => $entity_name,
          '@error' => $e->getMessage()
        ]
      );
    }

    return [];
  }

  /**
   * Map webform fields to Dataverse fields.
   *
   * @param array $submission_data
   *   The webform submission data.
   * @param array $field_mapping
   *   The field mapping configuration.
   *
   * @return array
   *   The mapped data array.
   */
  protected function mapFields(array $submission_data, array $field_mapping) {
    $mapped_data = [];

    foreach ($field_mapping as $webform_field => $dataverse_field) {
      if (!empty($dataverse_field) && isset($submission_data[$webform_field])) {
        $value = $submission_data[$webform_field];
        
        // Handle different field types
        if (is_array($value)) {
          // For multi-value fields, join with semicolons
          $mapped_data[$dataverse_field] = implode('; ', array_filter($value));
        } elseif (is_bool($value)) {
          $mapped_data[$dataverse_field] = $value;
        } elseif (is_numeric($value)) {
          $mapped_data[$dataverse_field] = $value;
        } else {
          // Sanitize string values
          $mapped_data[$dataverse_field] = $this->sanitizeStringValue($value);
        }
      }
    }

    return $mapped_data;
  }

  /**
   * Sanitize string values for Dataverse.
   *
   * @param string $value
   *   The value to sanitize.
   *
   * @return string
   *   The sanitized value.
   */
  protected function sanitizeStringValue($value) {
    // Remove null bytes and control characters
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    
    // Trim whitespace
    return trim($value);
  }

}