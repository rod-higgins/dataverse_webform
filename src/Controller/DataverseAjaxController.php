<?php

namespace Drupal\dataverse_webform\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dataverse_webform\DataverseClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Dataverse AJAX operations.
 */
class DataverseAjaxController extends ControllerBase {

  /**
   * The Dataverse client service.
   *
   * @var \Drupal\dataverse_webform\DataverseClientInterface
   */
  protected $dataverseClient;

  /**
   * Constructs a DataverseAjaxController object.
   *
   * @param \Drupal\dataverse_webform\DataverseClientInterface $dataverse_client
   *   The Dataverse client service.
   */
  public function __construct(DataverseClientInterface $dataverse_client) {
    $this->dataverseClient = $dataverse_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('dataverse_webform.dataverse_client')
    );
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
  public function getEntityFields(Request $request) {
    $entity_name = $request->query->get('entity');
    $config = [
      'dataverse_url' => $request->query->get('dataverse_url'),
      'azure_tenant_id' => $request->query->get('azure_tenant_id'),
      'azure_client_id_key' => $request->query->get('azure_client_id_key'),
      'azure_client_secret_key' => $request->query->get('azure_client_secret_key'),
    ];

    if (empty($entity_name) || empty($config['dataverse_url'])) {
      return new JsonResponse(['error' => 'Missing required parameters'], 400);
    }

    try {
      $fields = $this->dataverseClient->getEntityFields($config, $entity_name);
      
      $field_options = [];
      foreach ($fields as $field) {
        $field_options[$field['logical_name']] = [
          'label' => $field['display_name'] . ' (' . $field['logical_name'] . ')',
          'type' => $field['attribute_type'],
          'required' => $field['is_required'],
          'description' => $field['description'],
        ];
      }

      return new JsonResponse(['fields' => $field_options]);

    } catch (\Exception $e) {
      return new JsonResponse(['error' => 'Failed to retrieve entity fields'], 500);
    }
  }

}