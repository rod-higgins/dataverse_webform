<?php

namespace Drupal\dataverse_webform\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\dataverse_webform\DataverseClientInterface;
use Drupal\dataverse_webform\Exception\DataverseException;
use Drupal\dataverse_webform\Cache\DataverseCacheManager;

/**
 * Service for handling webform submissions to Dataverse with enhanced processing.
 */
class WebformSubmissionHandler {

  /**
   * Queue name for deferred processing.
   */
  public const QUEUE_NAME = 'dataverse_webform_submissions';

  /**
   * Maximum memory threshold for immediate processing (128MB).
   */
  public const MEMORY_THRESHOLD = 134217728;

  /**
   * Maximum processing time threshold in seconds.
   */
  public const TIME_THRESHOLD = 25;

  /**
   * The Dataverse client service.
   */
  protected DataverseClientInterface $dataverseClient;

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The state service.
   */
  protected StateInterface $state;

  /**
   * The queue factory.
   */
  protected QueueFactory $queueFactory;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The cache manager.
   */
  protected DataverseCacheManager $cacheManager;

  /**
   * Constructs a WebformSubmissionHandler object.
   *
   * @param \Drupal\dataverse_webform\DataverseClientInterface $dataverse_client
   *   The Dataverse client service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\dataverse_webform\Cache\DataverseCacheManager $cache_manager
   *   The cache manager.
   */
  public function __construct(
    DataverseClientInterface $dataverse_client,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    QueueFactory $queue_factory,
    AccountProxyInterface $current_user,
    DataverseCacheManager $cache_manager
  ) {
    $this->dataverseClient = $dataverse_client;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->queueFactory = $queue_factory;
    $this->currentUser = $current_user;
    $this->cacheManager = $cache_manager;
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

    // Record submission attempt
    $this->recordSubmissionAttempt($webform_submission);

    // Check processing mode (immediate vs queued)
    $processing_mode = $this->determineProcessingMode($webform_submission, $dataverse_config);

    if ($processing_mode === 'immediate') {
      $this->processSubmissionImmediate($webform_submission, $dataverse_config);
    } else {
      $this->queueSubmissionForProcessing($webform_submission, $dataverse_config);
    }
  }

  /**
   * Process submission immediately.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param array $dataverse_config
   *   The Dataverse configuration.
   */
  public function processSubmissionImmediate(WebformSubmissionInterface $webform_submission, array $dataverse_config): void {
    $start_time = microtime(true);
    $webform = $webform_submission->getWebform();

    try {
      // Pre-processing validation
      $this->validateSubmissionPreProcessing($webform_submission, $dataverse_config);

      // Process the submission
      $results = $this->dataverseClient->submitToDataverse($webform_submission, $dataverse_config);
      
      // Record successful processing
      $this->recordSubmissionSuccess($webform_submission, $results, $start_time);
      $this->logSubmissionResults($webform, $webform_submission, $results);
      
    } catch (DataverseException $e) {
      // Record failed processing
      $this->recordSubmissionFailure($webform_submission, $e, $start_time);
      
      $this->loggerFactory->get('dataverse_webform')->error(
        'Failed to process Dataverse submission for webform @webform_id (submission @submission_id): @error',
        [
          '@webform_id' => $webform->id(),
          '@submission_id' => $webform_submission->id(),
          '@error' => $e->getMessage(),
        ]
      );

      // Handle retry logic for retryable errors
      if ($e->isRetryable() && $this->shouldRetrySubmission($webform_submission, $dataverse_config)) {
        $this->scheduleRetry($webform_submission, $dataverse_config, $e);
      }
    } catch (\Exception $e) {
      // Handle unexpected errors
      $this->recordSubmissionFailure($webform_submission, $e, $start_time);
      
      $this->loggerFactory->get('dataverse_webform')->error(
        'Unexpected error processing Dataverse submission for webform @webform_id (submission @submission_id): @error',
        [
          '@webform_id' => $webform->id(),
          '@submission_id' => $webform_submission->id(),
          '@error' => $e->getMessage(),
        ]
      );
    }
  }

  /**
   * Queue submission for deferred processing.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param array $dataverse_config
   *   The Dataverse configuration.
   */
  public function queueSubmissionForProcessing(WebformSubmissionInterface $webform_submission, array $dataverse_config): void {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    
    $queue_item = [
      'submission_id' => $webform_submission->id(),
      'webform_id' => $webform_submission->getWebform()->id(),
      'config' => $dataverse_config,
      'created' => time(),
      'user_id' => $this->currentUser->id(),
      'retry_count' => 0,
    ];

    $queue->createItem($queue_item);
    
    $this->loggerFactory->get('dataverse_webform')->info(
      'Queued Dataverse submission for webform @webform_id (submission @submission_id)',
      [
        '@webform_id' => $webform_submission->getWebform()->id(),
        '@submission_id' => $webform_submission->id(),
      ]
    );
  }

  /**
   * Process a queued submission item.
   *
   * @param array $queue_item_data
   *   The queue item data.
   *
   * @return bool
   *   TRUE if processing was successful, FALSE otherwise.
   */
  public function processQueuedSubmission(array $queue_item_data): bool {
    $submission_id = $queue_item_data['submission_id'];
    $webform_id = $queue_item_data['webform_id'];
    $config = $queue_item_data['config'];

    try {
      // Load the submission
      $submission_storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
      $webform_submission = $submission_storage->load($submission_id);

      if (!$webform_submission) {
        $this->loggerFactory->get('dataverse_webform')->warning(
          'Webform submission @submission_id not found for queued processing',
          ['@submission_id' => $submission_id]
        );
        return FALSE;
      }

      // Process the submission
      $this->processSubmissionImmediate($webform_submission, $config);
      return TRUE;

    } catch (\Exception $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Failed to process queued Dataverse submission @submission_id: @error',
        ['@submission_id' => $submission_id, '@error' => $e->getMessage()]
      );
      return FALSE;
    }
  }

  /**
   * Get submission statistics for monitoring.
   *
   * @param string|null $webform_id
   *   Optional webform ID to filter statistics.
   * @param int $days
   *   Number of days to look back.
   *
   * @return array
   *   Array of submission statistics.
   */
  public function getSubmissionStatistics(?string $webform_id = null, int $days = 7): array {
    $stats_key = 'dataverse_webform:stats';
    if ($webform_id) {
      $stats_key .= ':' . $webform_id;
    }
    
    $stats = $this->state->get($stats_key, [
      'total_submissions' => 0,
      'successful_submissions' => 0,
      'failed_submissions' => 0,
      'queued_submissions' => 0,
      'retry_submissions' => 0,
      'last_updated' => time(),
    ]);

    // Add queue status
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $stats['current_queue_size'] = $queue->numberOfItems();

    return $stats;
  }

  /**
   * Clear submission statistics.
   *
   * @param string|null $webform_id
   *   Optional webform ID to clear specific statistics.
   */
  public function clearSubmissionStatistics(?string $webform_id = null): void {
    $stats_key = 'dataverse_webform:stats';
    if ($webform_id) {
      $stats_key .= ':' . $webform_id;
    }
    
    $this->state->delete($stats_key);
    
    $this->loggerFactory->get('dataverse_webform')->info(
      'Cleared submission statistics for @scope',
      ['@scope' => $webform_id ? "webform {$webform_id}" : 'all webforms']
    );
  }

  /**
   * Determine processing mode (immediate vs queued).
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param array $dataverse_config
   *   The Dataverse configuration.
   *
   * @return string
   *   Either 'immediate' or 'queued'.
   */
  protected function determineProcessingMode(WebformSubmissionInterface $webform_submission, array $dataverse_config): string {
    // Check global configuration
    $global_config = $this->configFactory->get('dataverse_webform.settings');
    $force_queue = $global_config->get('force_queue_processing') ?? FALSE;
    
    if ($force_queue) {
      return 'queued';
    }

    // Check memory usage
    if (memory_get_usage() > self::MEMORY_THRESHOLD) {
      $this->loggerFactory->get('dataverse_webform')->debug(
        'High memory usage detected, queueing submission for later processing'
      );
      return 'queued';
    }

    // Check current execution time
    $max_execution_time = ini_get('max_execution_time');
    if ($max_execution_time > 0 && $max_execution_time < self::TIME_THRESHOLD) {
      return 'queued';
    }

    // Check if this is a large multi-entity submission
    $field_mappings = $dataverse_config['field_mappings'] ?? [];
    $entity_count = count(array_unique(array_column($field_mappings, 'entity')));
    
    if ($entity_count > 3) {
      return 'queued';
    }

    // Check submission data size
    $submission_data = $webform_submission->getData();
    if (strlen(serialize($submission_data)) > 50000) { // ~50KB
      return 'queued';
    }

    return 'immediate';
  }

  /**
   * Validate submission before processing.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param array $dataverse_config
   *   The Dataverse configuration.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When validation fails.
   */
  protected function validateSubmissionPreProcessing(WebformSubmissionInterface $webform_submission, array $dataverse_config): void {
    // Check if configuration is cached as valid
    $cached_validation = $this->cacheManager->getCachedConfigValidation($dataverse_config);
    if ($cached_validation === NULL || !$cached_validation['valid']) {
      throw new DataverseException('Configuration validation failed or expired');
    }

    // Validate submission data is not empty
    $submission_data = $webform_submission->getData();
    if (empty($submission_data)) {
      throw new DataverseException('Submission data is empty');
    }

    // Check if we have field mappings
    if (empty($dataverse_config['field_mappings'])) {
      throw new DataverseException('No field mappings configured');
    }
  }

  /**
   * Log submission results with detailed information.
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
    
    $successful_entities = [];
    $failed_entities = [];

    foreach ($results as $entity_name => $result) {
      if ($result['success']) {
        $successful_entities[] = $entity_name;
        $logger->info(
          'Successfully submitted webform @webform_id submission @submission_id to Dataverse entity @entity (ID: @entity_id)',
          [
            '@webform_id' => $webform->id(),
            '@submission_id' => $webform_submission->id(),
            '@entity' => $entity_name,
            '@entity_id' => $result['id'] ?? 'unknown',
          ]
        );
      } else {
        $failed_entities[] = $entity_name;
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

    // Log summary
    if (!empty($successful_entities) && empty($failed_entities)) {
      $logger->info(
        'Successfully processed all entities for submission @submission_id: @entities',
        [
          '@submission_id' => $webform_submission->id(),
          '@entities' => implode(', ', $successful_entities),
        ]
      );
    } elseif (!empty($failed_entities)) {
      $logger->warning(
        'Partial success for submission @submission_id. Successful: @successful, Failed: @failed',
        [
          '@submission_id' => $webform_submission->id(),
          '@successful' => implode(', ', $successful_entities),
          '@failed' => implode(', ', $failed_entities),
        ]
      );
    }
  }

  /**
   * Record submission attempt in statistics.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   */
  protected function recordSubmissionAttempt(WebformSubmissionInterface $webform_submission): void {
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'total_submissions', 1);
  }

  /**
   * Record successful submission in statistics.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param array $results
   *   The submission results.
   * @param float $start_time
   *   The start time of processing.
   */
  protected function recordSubmissionSuccess(WebformSubmissionInterface $webform_submission, array $results, float $start_time): void {
    $processing_time = microtime(true) - $start_time;
    
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'successful_submissions', 1);
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'total_processing_time', $processing_time);
    
    $this->loggerFactory->get('dataverse_webform')->debug(
      'Submission @submission_id processed successfully in @time seconds',
      [
        '@submission_id' => $webform_submission->id(),
        '@time' => round($processing_time, 3),
      ]
    );
  }

  /**
   * Record failed submission in statistics.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param \Exception $exception
   *   The exception that caused the failure.
   * @param float $start_time
   *   The start time of processing.
   */
  protected function recordSubmissionFailure(WebformSubmissionInterface $webform_submission, \Exception $exception, float $start_time): void {
    $processing_time = microtime(true) - $start_time;
    
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'failed_submissions', 1);
    
    $this->loggerFactory->get('dataverse_webform')->debug(
      'Submission @submission_id failed after @time seconds: @error',
      [
        '@submission_id' => $webform_submission->id(),
        '@time' => round($processing_time, 3),
        '@error' => $exception->getMessage(),
      ]
    );
  }

  /**
   * Check if submission should be retried.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param array $dataverse_config
   *   The Dataverse configuration.
   *
   * @return bool
   *   TRUE if submission should be retried.
   */
  protected function shouldRetrySubmission(WebformSubmissionInterface $webform_submission, array $dataverse_config): bool {
    $max_retries = $dataverse_config['retry_attempts'] ?? 3;
    $retry_key = 'dataverse_webform:retry:' . $webform_submission->id();
    $retry_count = $this->state->get($retry_key, 0);
    
    return $retry_count < $max_retries;
  }

  /**
   * Schedule a retry for a failed submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param array $dataverse_config
   *   The Dataverse configuration.
   * @param \Exception $exception
   *   The exception that caused the failure.
   */
  protected function scheduleRetry(WebformSubmissionInterface $webform_submission, array $dataverse_config, \Exception $exception): void {
    $retry_key = 'dataverse_webform:retry:' . $webform_submission->id();
    $retry_count = $this->state->get($retry_key, 0) + 1;
    $this->state->set($retry_key, $retry_count);
    
    // Calculate delay (exponential backoff)
    $delay = min(300, pow(2, $retry_count) * 30); // Max 5 minutes
    
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $queue_item = [
      'submission_id' => $webform_submission->id(),
      'webform_id' => $webform_submission->getWebform()->id(),
      'config' => $dataverse_config,
      'created' => time() + $delay,
      'user_id' => $this->currentUser->id(),
      'retry_count' => $retry_count,
      'last_error' => $exception->getMessage(),
    ];

    $queue->createItem($queue_item);
    
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'retry_submissions', 1);
    
    $this->loggerFactory->get('dataverse_webform')->info(
      'Scheduled retry @retry_count for submission @submission_id with @delay second delay',
      [
        '@retry_count' => $retry_count,
        '@submission_id' => $webform_submission->id(),
        '@delay' => $delay,
      ]
    );
  }

  /**
   * Update submission statistics.
   *
   * @param string $webform_id
   *   The webform ID.
   * @param string $stat_name
   *   The statistic name.
   * @param int|float $increment
   *   The amount to increment.
   */
  protected function updateSubmissionStats(string $webform_id, string $stat_name, $increment): void {
    // Update global stats
    $global_stats_key = 'dataverse_webform:stats';
    $global_stats = $this->state->get($global_stats_key, []);
    $global_stats[$stat_name] = ($global_stats[$stat_name] ?? 0) + $increment;
    $global_stats['last_updated'] = time();
    $this->state->set($global_stats_key, $global_stats);

    // Update webform-specific stats
    $webform_stats_key = 'dataverse_webform:stats:' . $webform_id;
    $webform_stats = $this->state->get($webform_stats_key, []);
    $webform_stats[$stat_name] = ($webform_stats[$stat_name] ?? 0) + $increment;
    $webform_stats['last_updated'] = time();
    $this->state->set($webform_stats_key, $webform_stats);
  }

}