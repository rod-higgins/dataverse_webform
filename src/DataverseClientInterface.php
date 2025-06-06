<?php

namespace Drupal\dataverse_webform;

use Drupal\webform\WebformSubmissionInterface;

/**
 * Interface for Dataverse client service.
 */
interface DataverseClientInterface {

  /**
   * Submit webform data to Dataverse.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The webform submission.
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return bool
   *   TRUE if submission was successful, FALSE otherwise.
   */
  public function submitToDataverse(WebformSubmissionInterface $submission, array $config);

  /**
   * Test connection to Dataverse.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return bool
   *   TRUE if connection is successful, FALSE otherwise.
   */
  public function testConnection(array $config);

  /**
   * Get list of available entities from Dataverse.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return array
   *   Array of entities with their metadata.
   */
  public function getEntities(array $config);

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
   */
  public function getEntityFields(array $config, $entity_name);

}