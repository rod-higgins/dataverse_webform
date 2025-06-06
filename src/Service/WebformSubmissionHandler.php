<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\dataverse_webform\Exception\DataverseException;

/**
 * Service for handling webform submissions to Dataverse.
 */
class WebformSubmissionHandler {

  /**
   * The Dataverse client service.
   */
  protected DataverseClientInterface $dataverseClient;

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs a WebformSubmissionHandler object.
   *
   * @param \Drupal\dataverse_webform\DataverseClientInterface $dataverse_client
   *   The Dataverse client service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    DataverseClientInterface $dataverse_client,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->dataverseClient = $dataverse_client;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Process a webform submission for Dataverse integration.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to process.
   */
  public function processSubmission(WebformSubmissionInterface $webform_submission): void {
    $webform = $webform_submission->getWebform();
    
    // Check if this webform has Dataverse integration enabled
    $dataverse_config = $webform->getThirdPartySetting('dataverse_webform', 'dataverse_config');
    
    if (empty($dataverse_config['enabled'])) {
      return;
    }

    try {
      $results = $this->dataverseClient->submitToDataverse($webform_submission, $dataverse_config);
      $this->logSubmissionResults($webform, $webform_submission, $results);
      
    } catch (DataverseException $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Failed to process Dataverse submission for webform @webform_id: @error',
        [
          '@webform_id' => $webform->id(),
          '@error' => $e->getMessage(),
        ]
      );
    }
  }

  /**
   * Log submission results with proper error handling.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform entity.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param array $results
   *   The submission results.
   */
  protected function logSubmissionResults($webform, $webform_submission, array $results): void {
    $logger = $this->loggerFactory->get('dataverse_webform');
    
    foreach ($results as $entity_name => $result) {
      if ($result['success']) {
        $logger->info(
          'Successfully submitted webform @webform_id submission @submission_id to Dataverse entity @entity',
          [
            '@webform_id' => $webform->id(),
            '@submission_id' => $webform_submission->id(),
            '@entity' => $entity_name,
          ]
        );
      } else {
        $logger->error(
          'Failed to submit webform @webform_id submission @submission_id to Dataverse entity @entity: @error',
          [
            '@webform_id' => $webform->id(),
            '@submission_id' => $webform_submission->id(),
            '@entity' => $entity_name,
            '@error' => $result['error'] ?? 'Unknown error',
          ]
        );
      }
    }
  }

}