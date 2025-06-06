<?php

namespace Drupal\dataverse_webform\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dataverse_webform\DataverseClientInterface;
use Drupal\dataverse_webform\ConfigurationManager;
use Drupal\dataverse_webform\Exception\DataverseException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Dataverse AJAX operations with multi-entity support.
 */
class DataverseAjaxController extends ControllerBase {

  public const MAX_RESPONSE_ITEMS = 1000;
  public const RATE_LIMIT_REQUESTS = 100;
  public const RATE_LIMIT_WINDOW = 3600; // 1 hour

  protected DataverseClientInterface $dataverseClient;
  protected ConfigurationManager $configManager;

  public function __construct(
    DataverseClientInterface $dataverse_client,
    ConfigurationManager $config_manager
  ) {
    $this->dataverseClient = $dataverse_client;
    $this->configManager = $config_manager;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('dataverse_webform.dataverse_client'),
      $container->get('dataverse_webform.config_manager')
    );
  }

  public function getEntities(Request $request): JsonResponse {
    try {
      $this->checkRateLimit($request);
      
      $config = $this->buildConfigFromRequest($request);
      $this->validateRequiredConfig($config, ['dataverse_url', 'azure_tenant_id']);

      $entities = $this->dataverseClient->getEntities($config);
      $entity_options = $this->formatEntitiesForResponse($entities);

      return $this->createSuccessResponse([
        'entities' => $entity_options,
        'count' => count($entity_options),
      ]);

    } catch (DataverseException $e) {
      return $this->createErrorResponse($e->getMessage(), $e->getDataverseErrorCode() ?? 'dataverse_error', 400);
    } catch (\Exception $e) {
      $this->logUnexpectedError('getEntities', $e);
      return $this->createErrorResponse('An unexpected error occurred', 'system_error', 500);
    }
  }

  public function getEntityFields(Request $request): JsonResponse {
    try {
      $this->checkRateLimit($request);
      
      $entity_name = $request->query->get('entity');
      if (empty($entity_name)) {
        return $this->createErrorResponse('Entity name is required', 'validation_error', 400);
      }

      $config = $this->buildConfigFromRequest($request);
      $this->validateRequiredConfig($config, ['dataverse_url', 'azure_tenant_id']);

      $fields = $this->dataverseClient->getEntityFields($config, $entity_name);
      $field_options = $this->formatFieldsForResponse($fields);

      return $this->createSuccessResponse([
        'fields' => $field_options,
        'entity' => $entity_name,
        'count' => count($field_options),
      ]);

    } catch (DataverseException $e) {
      return $this->createErrorResponse($e->getMessage(), $e->getDataverseErrorCode() ?? 'dataverse_error', 400);
    } catch (\Exception $e) {
      $this->logUnexpectedError('getEntityFields', $e);
      return $this->createErrorResponse('An unexpected error occurred', 'system_error', 500);
    }
  }

  public function validateConfiguration(Request $request): JsonResponse {
    try {
      $this->checkRateLimit($request);
      
      $config = $this->extractConfigFromRequest($request);
      $validation_results = $this->configManager->validateConfiguration($config);

      // Test connection if basic validation passes
      $connection_test = false;
      if ($validation_results['valid']) {
        try {
          $connection_test = $this->dataverseClient->testConnection($config);
        } catch (DataverseException $e) {
          $validation_results['errors'][] = 'Connection test failed: ' . $e->getMessage();
          $validation_results['valid'] = false;
        }
      }

      return $this->createSuccessResponse([
        'validation' => $validation_results,
        'connection_test' => $connection_test,
      ]);

    } catch (\Exception $e) {
      $this->logUnexpectedError('validateConfiguration', $e);
      return $this->createErrorResponse('Configuration validation failed', 'system_error', 500);
    }
  }

  public function getFieldMappingSuggestions(Request $request): JsonResponse {
    try {
      $this->checkRateLimit($request);
      
      $webform_fields = $request->query->get('webform_fields', []);
      $target_entities = $request->query->get('entities', []);
      
      if (empty($webform_fields) || empty($target_entities)) {
        return $this->createErrorResponse(
          'Webform fields and target entities are required',
          'validation_error',
          400
        );
      }

      $config = $this->buildConfigFromRequest($request);
      $suggestions = $this->generateMappingSuggestions($config, $webform_fields, $target_entities);

      return $this->createSuccessResponse([
        'suggestions' => $suggestions,
        'webform_fields' => $webform_fields,
        'entities' => $target_entities,
      ]);

    } catch (\Exception $e) {
      $this->logUnexpectedError('getFieldMappingSuggestions', $e);
      return $this->createErrorResponse('Failed to generate field mapping suggestions', 'system_error', 500);
    }
  }

  protected function formatEntitiesForResponse(array $entities): array {
    $entity_options = [];
    $count = 0;
    
    foreach ($entities as $entity) {
      if ($count >= self::MAX_RESPONSE_ITEMS) {
        break;
      }
      
      $entity_options[$entity['logical_name']] = [
        'label' => $entity['display_name'] . ' (' . $entity['logical_name'] . ')',
        'logical_name' => $entity['logical_name'],
        'display_name' => $entity['display_name'],
        'description' => $entity['description'],
        'entity_set_name' => $entity['entity_set_name'],
      ];
      $count++;
    }

    return $entity_options;
  }

  protected function formatFieldsForResponse(array $fields): array {
    $field_options = [];
    $count = 0;
    
    foreach ($fields as $field) {
      if ($count >= self::MAX_RESPONSE_ITEMS) {
        break;
      }
      
      $field_options[$field['logical_name']] = [
        'label' => $field['display_name'] . ' (' . $field['logical_name'] . ')',
        'logical_name' => $field['logical_name'],
        'display_name' => $field['display_name'],
        'attribute_type' => $field['attribute_type'],
        'description' => $field['description'],
        'is_required' => $field['is_required'],
        'max_length' => $field['max_length'],
        'is_primary_id' => $field['is_primary_id'] ?? false,
        'is_primary_name' => $field['is_primary_name'] ?? false,
      ];
      $count++;
    }

    return $field_options;
  }

  protected function generateMappingSuggestions(array $config, array $webform_fields, array $target_entities): array {
    $suggestions = [];

    foreach ($target_entities as $entity_name) {
      try {
        $entity_fields = $this->dataverseClient->getEntityFields($config, $entity_name);
        $entity_suggestions = $this->generateFieldMappingSuggestions($webform_fields, $entity_fields);
        
        if (!empty($entity_suggestions)) {
          $suggestions[$entity_name] = $entity_suggestions;
        }
      } catch (DataverseException $e) {
        $this->getLogger('dataverse_webform')->warning(
          'Failed to get field suggestions for entity @entity: @error',
          ['@entity' => $entity_name, '@error' => $e->getMessage()]
        );
      }
    }

    return $suggestions;
  }

  protected function buildConfigFromRequest(Request $request): array {
    return [
      'enabled' => true,
      'dataverse_url' => $request->query->get('dataverse_url', ''),
      'azure_tenant_id' => $request->query->get('azure_tenant_id', ''),
      'azure_client_id_key' => $request->query->get('azure_client_id_key', ''),
      'azure_client_secret_key' => $request->query->get('azure_client_secret_key', ''),
      'timeout' => (int) $request->query->get('timeout', 30),
    ];
  }

  protected function extractConfigFromRequest(Request $request): array {
    $config = [];
    $content = $request->getContent();
    
    if (!empty($content)) {
      $data = json_decode($content, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        $config = $data;
      }
    }

    if (empty($config)) {
      $config = $this->buildConfigFromRequest($request);
    }

    return $config;
  }

  protected function validateRequiredConfig(array $config, array $required_fields): void {
    $missing_fields = array_filter($required_fields, fn($field) => empty($config[$field]));

    if (!empty($missing_fields)) {
      throw DataverseException::configurationError('Missing required configuration: ' . implode(', ', $missing_fields));
    }
  }

  protected function generateFieldMappingSuggestions(array $webform_fields, array $entity_fields): array {
    $suggestions = [];
    $common_mappings = $this->getCommonFieldMappings();

    foreach ($webform_fields as $webform_field) {
      $webform_lower = strtolower($webform_field);
      $best_matches = [];

      // Check for exact matches first
      $best_matches = $this->findExactMatches($webform_lower, $entity_fields);

      // Check common mappings if no exact matches
      if (empty($best_matches)) {
        $best_matches = $this->findCommonMappingMatches($webform_lower, $entity_fields, $common_mappings);
      }

      // Check for partial matches if still no matches
      if (empty($best_matches)) {
        $best_matches = $this->findPartialMatches($webform_lower, $entity_fields);
      }

      // Sort by confidence and take top suggestions
      usort($best_matches, fn($a, $b) => $b['confidence'] - $a['confidence']);
      $suggestions[$webform_field] = array_slice($best_matches, 0, 3);
    }

    return $suggestions;
  }

  protected function getCommonFieldMappings(): array {
    return [
      'first_name' => ['firstname', 'fname', 'givenname'],
      'last_name' => ['lastname', 'lname', 'surname', 'familyname'],
      'email' => ['emailaddress1', 'email', 'primaryemail'],
      'phone' => ['telephone1', 'phone', 'mobilephone'],
      'company' => ['company', 'accountname', 'organizationname'],
      'name' => ['name', 'fullname', 'displayname'],
      'address' => ['address1_line1', 'address', 'street'],
      'city' => ['address1_city', 'city'],
      'state' => ['address1_stateorprovince', 'state'],
      'zip' => ['address1_postalcode', 'zip', 'postalcode'],
      'country' => ['address1_country', 'country'],
      'website' => ['websiteurl', 'website', 'url'],
      'description' => ['description', 'notes', 'comments'],
    ];
  }

  protected function findExactMatches(string $webform_lower, array $entity_fields): array {
    $matches = [];
    
    foreach ($entity_fields as $entity_field) {
      $entity_lower = strtolower($entity_field['logical_name']);
      
      if ($webform_lower === $entity_lower) {
        $matches[] = [
          'field' => $entity_field['logical_name'],
          'confidence' => 100,
          'reason' => 'Exact match',
          'display_name' => $entity_field['display_name'],
        ];
      }
    }

    return $matches;
  }

  protected function findCommonMappingMatches(string $webform_lower, array $entity_fields, array $common_mappings): array {
    $matches = [];
    
    foreach ($common_mappings as $pattern => $targets) {
      if (strpos($webform_lower, $pattern) !== false) {
        foreach ($targets as $target) {
          foreach ($entity_fields as $entity_field) {
            if (strtolower($entity_field['logical_name']) === $target) {
              $matches[] = [
                'field' => $entity_field['logical_name'],
                'confidence' => 80,
                'reason' => "Common mapping for '{$pattern}'",
                'display_name' => $entity_field['display_name'],
              ];
            }
          }
        }
      }
    }

    return $matches;
  }

  protected function findPartialMatches(string $webform_lower, array $entity_fields): array {
    $matches = [];
    
    foreach ($entity_fields as $entity_field) {
      $entity_lower = strtolower($entity_field['logical_name']);
      
      // Check if webform field is contained in entity field or vice versa
      if (strpos($entity_lower, $webform_lower) !== false || 
          strpos($webform_lower, $entity_lower) !== false) {
        $matches[] = [
          'field' => $entity_field['logical_name'],
          'confidence' => 60,
          'reason' => 'Partial name match',
          'display_name' => $entity_field['display_name'],
        ];
      }
    }

    return $matches;
  }

  protected function createSuccessResponse(array $data): JsonResponse {
    return new JsonResponse(array_merge([
      'success' => true,
      'timestamp' => time(),
    ], $data));
  }

  protected function createErrorResponse(string $message, string $error_type, int $status_code): JsonResponse {
    return new JsonResponse([
      'success' => false,
      'error' => $message,
      'error_type' => $error_type,
      'timestamp' => time(),
    ], $status_code);
  }

  protected function logUnexpectedError(string $method, \Exception $e): void {
    $this->getLogger('dataverse_webform')->error(
      'Unexpected error in @method: @error',
      [
        '@method' => $method, 
        '@error' => $e->getMessage(), 
        'trace' => $e->getTraceAsString()
      ]
    );
  }

  protected function checkRateLimit(Request $request): void {
    $client_ip = $request->getClientIp();
    $rate_limit_key = 'dataverse_webform:ajax_rate_limit:' . hash('sha256', $client_ip);
    
    $state = \Drupal::state();
    $requests = $state->get($rate_limit_key, []);
    
    $current_time = time();
    $requests = array_filter($requests, fn($time) => $time > ($current_time - self::RATE_LIMIT_WINDOW));
    
    if (count($requests) >= self::RATE_LIMIT_REQUESTS) {
      throw new \Exception('Rate limit exceeded for AJAX requests');
    }
    
    $requests[] = $current_time;
    $state->set($rate_limit_key, $requests);
  }

}