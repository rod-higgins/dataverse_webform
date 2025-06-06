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

  /**
   * The Dataverse client service.
   */
  protected DataverseClientInterface $dataverseClient;

  /**
   * The configuration manager service.
   */
  protected ConfigurationManager $configManager;

  /**
   * Constructs a DataverseAjaxController object.
   *
   * @param \Drupal\dataverse_webform\DataverseClientInterface $dataverse_client
   *   The Dataverse client service.
   * @param \Drupal\dataverse_webform\ConfigurationManager $config_manager
   *   The configuration manager service.
   */
  public function __construct(
    DataverseClientInterface $dataverse_client,
    ConfigurationManager $config_manager
  ) {
    $this->dataverseClient = $dataverse_client;
    $this->configManager = $config_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('dataverse_webform.dataverse_client'),
      $container->get('dataverse_webform.config_manager')
    );
  }

  /**
   * Get available entities via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with available entities.
   */
  public function getEntities(Request $request): JsonResponse {
    try {
      $config = $this->buildConfigFromRequest($request);
      $this->validateRequiredConfig($config, ['dataverse_url', 'azure_tenant_id']);

      $entities = $this->dataverseClient->getEntities($config);
      
      $entity_options = [];
      foreach ($entities as $entity) {
        $entity_options[$entity['logical_name']] = [
          'label' => $entity['display_name'] . ' (' . $entity['logical_name'] . ')',
          'logical_name' => $entity['logical_name'],
          'display_name' => $entity['display_name'],
          'description' => $entity['description'],
          'entity_set_name' => $entity['entity_set_name'],
        ];
      }

      return new JsonResponse([
        'success' => true,
        'entities' => $entity_options,
        'count' => count($entity_options),
      ]);

    } catch (DataverseException $e) {
      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
        'error_type' => 'dataverse_error',
      ], 400);
    } catch (\Exception $e) {
      $this->getLogger('dataverse_webform')->error(
        'Unexpected error in getEntities: @error',
        ['@error' => $e->getMessage()]
      );
      
      return new JsonResponse([
        'success' => false,
        'error' => 'An unexpected error occurred',
        'error_type' => 'system_error',
      ], 500);
    }
  }

  /**
   * Get entity fields via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with entity fields.
   */
  public function getEntityFields(Request $request): JsonResponse {
    try {
      $entity_name = $request->query->get('entity');
      if (empty($entity_name)) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Entity name is required',
          'error_type' => 'validation_error',
        ], 400);
      }

      $config = $this->buildConfigFromRequest($request);
      $this->validateRequiredConfig($config, ['dataverse_url', 'azure_tenant_id']);

      $fields = $this->dataverseClient->getEntityFields($config, $entity_name);
      
      $field_options = [];
      foreach ($fields as $field) {
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
      }

      return new JsonResponse([
        'success' => true,
        'fields' => $field_options,
        'entity' => $entity_name,
        'count' => count($field_options),
      ]);

    } catch (DataverseException $e) {
      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
        'error_type' => 'dataverse_error',
      ], 400);
    } catch (\Exception $e) {
      $this->getLogger('dataverse_webform')->error(
        'Unexpected error in getEntityFields: @error',
        ['@error' => $e->getMessage()]
      );
      
      return new JsonResponse([
        'success' => false,
        'error' => 'An unexpected error occurred',
        'error_type' => 'system_error',
      ], 500);
    }
  }

  /**
   * Validate configuration via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with validation results.
   */
  public function validateConfiguration(Request $request): JsonResponse {
    try {
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

      return new JsonResponse([
        'success' => true,
        'validation' => $validation_results,
        'connection_test' => $connection_test,
        'timestamp' => time(),
      ]);

    } catch (\Exception $e) {
      $this->getLogger('dataverse_webform')->error(
        'Unexpected error in validateConfiguration: @error',
        ['@error' => $e->getMessage()]
      );
      
      return new JsonResponse([
        'success' => false,
        'error' => 'Configuration validation failed',
        'error_type' => 'system_error',
      ], 500);
    }
  }

  /**
   * Get field mapping suggestions via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with field mapping suggestions.
   */
  public function getFieldMappingSuggestions(Request $request): JsonResponse {
    try {
      $webform_fields = $request->query->get('webform_fields', []);
      $target_entities = $request->query->get('entities', []);
      
      if (empty($webform_fields) || empty($target_entities)) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Webform fields and target entities are required',
          'error_type' => 'validation_error',
        ], 400);
      }

      $config = $this->buildConfigFromRequest($request);
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

      return new JsonResponse([
        'success' => true,
        'suggestions' => $suggestions,
        'webform_fields' => $webform_fields,
        'entities' => $target_entities,
      ]);

    } catch (\Exception $e) {
      $this->getLogger('dataverse_webform')->error(
        'Unexpected error in getFieldMappingSuggestions: @error',
        ['@error' => $e->getMessage()]
      );
      
      return new JsonResponse([
        'success' => false,
        'error' => 'Failed to generate field mapping suggestions',
        'error_type' => 'system_error',
      ], 500);
    }
  }

  /**
   * Build configuration array from request parameters.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   The configuration array.
   */
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

  /**
   * Validate that required configuration fields are present.
   *
   * @param array $config
   *   The configuration array.
   * @param array $required_fields
   *   Array of required field names.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When required fields are missing.
   */
  protected function validateRequiredConfig(array $config, array $required_fields): void {
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
      if (empty($config[$field])) {
        $missing_fields[] = $field;
      }
    }

    if (!empty($missing_fields)) {
      throw new DataverseException('Missing required configuration: ' . implode(', ', $missing_fields));
    }
  }

  /**
   * Generate field mapping suggestions based on field names.
   *
   * @param array $webform_fields
   *   Array of webform field names.
   * @param array $entity_fields
   *   Array of entity fields from Dataverse.
   *
   * @return array
   *   Array of suggested mappings.
   */
  protected function generateFieldMappingSuggestions(array $webform_fields, array $entity_fields): array {
    $suggestions = [];
    
    // Common field name mappings
    $common_mappings = [
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

    foreach ($webform_fields as $webform_field) {
      $webform_lower = strtolower($webform_field);
      $best_matches = [];

      // Check for exact matches first
      foreach ($entity_fields as $entity_field) {
        $entity_lower = strtolower($entity_field['logical_name']);
        
        if ($webform_lower === $entity_lower) {
          $best_matches[] = [
            'field' => $entity_field['logical_name'],
            'confidence' => 100,
            'reason' => 'Exact match',
          ];
        }
      }

      // Check common mappings
      if (empty($best_matches)) {
        foreach ($common_mappings as $pattern => $targets) {
          if (strpos($webform_lower, $pattern) !== false) {
            foreach ($targets as $target) {
              foreach ($entity_fields as $entity_field) {
                if (strtolower($entity_field['logical_name']) === $target) {
                  $best_matches[] = [
                    'field' => $entity_field['logical_name'],
                    'confidence' => 80,
                    'reason' => "Common mapping for '{$pattern}'",
                  ];
                }
              }
            }
          }
        }
      }

      // Check for partial matches
      if (empty($best_matches)) {
        foreach ($entity_fields as $entity_field) {
          $entity_lower = strtolower($entity_field['logical_name']);
          
          // Check if webform field is contained in entity field or vice versa
          if (strpos($entity_lower, $webform_lower) !== false || 
              strpos($webform_lower, $entity_lower) !== false) {
            $best_matches[] = [
              'field' => $entity_field['logical_name'],
              'confidence' => 60,
              'reason' => 'Partial name match',
            ];
          }
        }
      }

      // Sort by confidence and take top suggestions
      usort($best_matches, fn($a, $b) => $b['confidence'] - $a['confidence']);
      $suggestions[$webform_field] = array_slice($best_matches, 0, 3);
    }

    return $suggestions;
  }

}