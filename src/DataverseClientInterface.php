<?php

namespace Drupal\dataverse_webform;

use Drupal\webform\WebformSubmissionInterface;

/**
 * Interface for Dataverse client service.
 */
interface DataverseClientInterface {

  /**
   * Submit webform data to multiple Dataverse entities.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The webform submission.
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return array
   *   Array of submission results keyed by entity name.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When submission fails.
   */
  public function submitToDataverse(WebformSubmissionInterface $submission, array $config): array;

  /**
   * Test connection to Dataverse.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return bool
   *   TRUE if connection is successful, FALSE otherwise.
   */
  public function testConnection(array $config): bool;

  /**
   * Get list of available entities from Dataverse.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return array
   *   Array of entities with their metadata.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When entity retrieval fails.
   */
  public function getEntities(array $config): array;

  /**
   * Get fields for a specific entity.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param string $entity_name
   *   The entity logical name.
   *
   * @return array
   *   Array of fields with their metadata.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When field retrieval fails.
   */
  public function getEntityFields(array $config, string $entity_name): array;

  /**
   * Create a single entity record.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param string $entity_name
   *   The entity logical name.
   * @param array $data
   *   The entity data.
   *
   * @return array
   *   The created entity response data.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When entity creation fails.
   */
  public function createEntity(array $config, string $entity_name, array $data): array;

  /**
   * Batch create multiple entities.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param array $entities_data
   *   Array of entity data keyed by entity name.
   *
   * @return array
   *   Array of creation results.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When batch creation fails.
   */
  public function batchCreateEntities(array $config, array $entities_data): array;

  /**
   * Validate entity and field names exist in Dataverse.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param array $field_mappings
   *   The field mappings to validate.
   *
   * @return array
   *   Array of validation results.
   */
  public function validateFieldMappings(array $config, array $field_mappings): array;

}