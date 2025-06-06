<?php

namespace Drupal\dataverse_webform\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\dataverse_webform\Service\WebformSubmissionHandler;
use Drupal\dataverse_webform\Exception\DataverseException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for processing Dataverse webform submissions.
 *
 * @QueueWorker(
 *   id = "dataverse_webform_submissions",
 *   title = @Translation("Dataverse Webform Submissions"),
 *   cron = {"time" = 60}
 * )
 */
class DataverseSubmissionProcessor extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public const MAX_RETRIES = 3;
  public const RETRY_DELAY_BASE = 30; // Base delay in seconds

  protected WebformSubmissionHandler $submissionHandler;
  protected LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    WebformSubmissionHandler $submission_handler,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->submissionHandler = $submission_handler;
    $this->loggerFactory = $logger_factory;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('dataverse_webform.submission_handler'),
      $container->get('logger.factory')
    );
  }

  public function processItem($data) {
    $this->validateQueueItemData($data);
    
    try {
      $success = $this->submissionHandler->processQueuedSubmission($data);
      
      if (!$success) {
        $this->handleFailedSubmission($data);
      } else {
        $this->logSuccessfulProcessing($data);
      }
    } catch (DataverseException $e) {
      $this->handleDataverseException($data, $e);
    } catch (\Exception $e) {
      $this->handleUnexpectedException($data, $e);
    }
  }

  protected function validateQueueItemData(array $data): void {
    $required_fields = ['submission_id', 'webform_id', 'config'];
    
    foreach ($required_fields as $field) {
      if (!isset($data[$field])) {
        throw new \InvalidArgumentException("Missing required field '{$field}' in queue item data");
      }
    }
    
    if (!is_array($data['config'])) {
      throw new \InvalidArgumentException('Config must be an array');
    }
  }

  protected function handleFailedSubmission(array $data): void {
    $retry_count = $data['retry_count'] ?? 0;
    
    if ($retry_count < self::MAX_RETRIES) {
      $this->requeueWithDelay($data, $retry_count);
    } else {
      $this->logPermanentFailure($data);
    }
  }

  protected function handleDataverseException(array $data, DataverseException $e): void {
    $submission_id = $data['submission_id'] ?? 'unknown';
    
    $this->loggerFactory->get('dataverse_webform')->error(
      'Dataverse error processing queued submission @submission_id: @error',
      [
        '@submission_id' => $submission_id,
        '@error' => $e->getFormattedMessage(),
      ]
    );

    // Determine if we should retry based on the error type
    if ($e->isRetryable()) {
      $this->handleFailedSubmission($data);
    } else {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Non-retryable Dataverse error for submission @submission_id: @error',
        [
          '@submission_id' => $submission_id,
          '@error' => $e->getMessage(),
        ]
      );
    }
  }

  protected function handleUnexpectedException(array $data, \Exception $e): void {
    $submission_id = $data['submission_id'] ?? 'unknown';
    
    $this->loggerFactory->get('dataverse_webform')->error(
      'Unexpected exception in queue worker processing submission @submission_id: @error',
      [
        '@submission_id' => $submission_id,
        '@error' => $e->getMessage(),
      ]
    );
    
    // For unexpected exceptions, we'll retry up to the limit
    $this->handleFailedSubmission($data);
  }

  protected function requeueWithDelay(array $data, int $current_retry_count): void {
    $new_retry_count = $current_retry_count + 1;
    $delay = min(300, pow(2, $new_retry_count) * self::RETRY_DELAY_BASE); // Exponential backoff with cap
    
    $data['retry_count'] = $new_retry_count;
    $data['retry_scheduled'] = time() + $delay;
    
    // Add the item back to the queue
    $queue = \Drupal::queue('dataverse_webform_submissions');
    $queue->createItem($data);
    
    $this->loggerFactory->get('dataverse_webform')->info(
      'Re-queued failed submission @submission_id for retry @retry (delay: @delay seconds)',
      [
        '@submission_id' => $data['submission_id'] ?? 'unknown',
        '@retry' => $new_retry_count,
        '@delay' => $delay,
      ]
    );
  }

  protected function logSuccessfulProcessing(array $data): void {
    $this->loggerFactory->get('dataverse_webform')->info(
      'Successfully processed queued submission @submission_id',
      ['@submission_id' => $data['submission_id'] ?? 'unknown']
    );
  }

  protected function logPermanentFailure(array $data): void {
    $submission_id = $data['submission_id'] ?? 'unknown';
    $retry_count = $data['retry_count'] ?? 0;
    
    $this->loggerFactory->get('dataverse_webform')->error(
      'Permanently failed to process submission @submission_id after @retries retries',
      [
        '@submission_id' => $submission_id,
        '@retries' => $retry_count,
      ]
    );
    
    // Optionally, you could add the failed submission to a separate tracking system
    $this->trackPermanentFailure($data);
  }

  protected function trackPermanentFailure(array $data): void {
    // Store information about permanently failed submissions
    // This could be used for reporting or manual intervention
    $state = \Drupal::state();
    $failed_submissions = $state->get('dataverse_webform.failed_submissions', []);
    
    $failed_submissions[] = [
      'submission_id' => $data['submission_id'] ?? 'unknown',
      'webform_id' => $data['webform_id'] ?? 'unknown',
      'failed_at' => time(),
      'retry_count' => $data['retry_count'] ?? 0,
      'last_error' => $data['last_error'] ?? 'Unknown error',
    ];
    
    // Keep only the last 100 failed submissions to prevent unbounded growth
    if (count($failed_submissions) > 100) {
      $failed_submissions = array_slice($failed_submissions, -100);
    }
    
    $state->set('dataverse_webform.failed_submissions', $failed_submissions);
  }
}