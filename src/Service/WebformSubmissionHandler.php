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
 * Service for handling webform submissions to Dataverse.
 */
class WebformSubmissionHandler {

  public const QUEUE_NAME = 'dataverse_webform_submissions';
  public const MEMORY_THRESHOLD = 134217728; // 128MB
  public const TIME_THRESHOLD = 25;
  public const MAX_SERIALIZED_SIZE = 50000;
  public const MAX_ENTITY_COUNT = 3;

  protected DataverseClientInterface $dataverseClient;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected ConfigFactoryInterface $configFactory;
  protected StateInterface $state;
  protected QueueFactory $queueFactory;
  protected AccountProxyInterface $currentUser;
  protected DataverseCacheManager $cacheManager;

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

  public function processSubmission(WebformSubmissionInterface $webform_submission): void {
    $webform = $webform_submission->getWebform();
    $dataverse_config = $webform->getThirdPartySetting('dataverse_webform', 'dataverse_config');
    
    if (empty($dataverse_config['enabled'])) {
      return;
    }

    $this->recordSubmissionAttempt($webform_submission);

    if ($this->shouldProcessImmediately($webform_submission, $dataverse_config)) {
      $this->processSubmissionImmediate($webform_submission, $dataverse_config);
    } else {
      $this->queueSubmissionForProcessing($webform_submission, $dataverse_config);
    }
  }

  public function processSubmissionImmediate(WebformSubmissionInterface $webform_submission, array $dataverse_config): void {
    $start_time = microtime(true);

    try {
      $this->validateSubmissionPreProcessing($webform_submission, $dataverse_config);
      $results = $this->dataverseClient->submitToDataverse($webform_submission, $dataverse_config);
      
      $this->handleSubmissionSuccess($webform_submission, $results, $start_time);
      
    } catch (DataverseException $e) {
      $this->handleSubmissionError($webform_submission, $dataverse_config, $e, $start_time);
    } catch (\Exception $e) {
      $this->handleUnexpectedError($webform_submission, $e, $start_time);
    }
  }

  public function queueSubmissionForProcessing(WebformSubmissionInterface $webform_submission, array $dataverse_config): void {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    
    $queue_item = $this->buildQueueItem($webform_submission, $dataverse_config);
    $queue->createItem($queue_item);
    
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'queued_submissions', 1);
    
    $this->loggerFactory->get('dataverse_webform')->info(
      'Queued Dataverse submission for webform @webform_id (submission @submission_id)',
      [
        '@webform_id' => $webform_submission->getWebform()->id(),
        '@submission_id' => $webform_submission->id(),
      ]
    );
  }

  public function processQueuedSubmission(array $queue_item_data): bool {
    $submission_id = $queue_item_data['submission_id'];
    $config = $queue_item_data['config'];

    try {
      $webform_submission = $this->loadSubmission($submission_id);
      if (!$webform_submission) {
        return false;
      }

      $this->processSubmissionImmediate($webform_submission, $config);
      return true;
    } catch (\Exception $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Failed to process queued Dataverse submission @submission_id: @error',
        ['@submission_id' => $submission_id, '@error' => $e->getMessage()]
      );
      return false;
    }
  }

  public function getSubmissionStatistics(?string $webform_id = null): array {
    $stats = $this->getSubmissionStats($webform_id);
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $stats['current_queue_size'] = $queue->numberOfItems();

    return $stats;
  }

  public function clearSubmissionStatistics(?string $webform_id = null): void {
    $stats_key = $this->buildStatsKey($webform_id);
    $this->state->delete($stats_key);
    
    $this->loggerFactory->get('dataverse_webform')->info(
      'Cleared submission statistics for @scope',
      ['@scope' => $webform_id ? "webform {$webform_id}" : 'all webforms']
    );
  }

  protected function shouldProcessImmediately(WebformSubmissionInterface $webform_submission, array $dataverse_config): bool {
    $global_config = $this->configFactory->get('dataverse_webform.settings');
    
    return !$global_config->get('force_queue_processing') &&
           $this->hasAvailableResources() &&
           $this->isSubmissionSimple($webform_submission, $dataverse_config);
  }

  protected function hasAvailableResources(): bool {
    // Check memory usage
    if (memory_get_usage() > self::MEMORY_THRESHOLD) {
      return false;
    }

    // Check execution time limits
    $max_execution_time = ini_get('max_execution_time');
    return $max_execution_time <= 0 || $max_execution_time >= self::TIME_THRESHOLD;
  }

  protected function isSubmissionSimple(WebformSubmissionInterface $webform_submission, array $dataverse_config): bool {
    $field_mappings = $dataverse_config['field_mappings'] ?? [];
    $entity_count = count(array_unique(array_column($field_mappings, 'entity')));
    
    if ($entity_count > self::MAX_ENTITY_COUNT) {
      return false;
    }

    $submission_data = $webform_submission->getData();
    return strlen(serialize($submission_data)) <= self::MAX_SERIALIZED_SIZE;
  }

  protected function validateSubmissionPreProcessing(WebformSubmissionInterface $webform_submission, array $dataverse_config): void {
    $cached_validation = $this->cacheManager->getCachedConfigValidation($dataverse_config);
    if ($cached_validation === null || !$cached_validation['valid']) {
      throw new DataverseException('Configuration validation failed or expired');
    }

    $submission_data = $webform_submission->getData();
    if (empty($submission_data)) {
      throw new DataverseException('Submission data is empty');
    }

    if (empty($dataverse_config['field_mappings'])) {
      throw new DataverseException('No field mappings configured');
    }
  }

  protected function handleSubmissionSuccess(WebformSubmissionInterface $webform_submission, array $results, float $start_time): void {
    $this->recordSubmissionSuccess($webform_submission, $results, $start_time);
    $this->logSubmissionResults($webform_submission, $results);
  }

  protected function handleSubmissionError(WebformSubmissionInterface $webform_submission, array $dataverse_config, DataverseException $e, float $start_time): void {
    $this->recordSubmissionFailure($webform_submission, $e, $start_time);
    
    $this->loggerFactory->get('dataverse_webform')->error(
      'Failed to process Dataverse submission for webform @webform_id (submission @submission_id): @error',
      [
        '@webform_id' => $webform_submission->getWebform()->id(),
        '@submission_id' => $webform_submission->id(),
        '@error' => $e->getMessage(),
        'error_type' => $e->getDataverseErrorCode(),
        'severity' => $e->getSeverity(),
      ]
    );

    if ($e->isRetryable() && $this->shouldRetrySubmission($webform_submission, $dataverse_config)) {
      $this->scheduleRetry($webform_submission, $dataverse_config, $e);
    }
  }

  protected function handleUnexpectedError(WebformSubmissionInterface $webform_submission, \Exception $e, float $start_time): void {
    $this->recordSubmissionFailure($webform_submission, $e, $start_time);
    
    $this->loggerFactory->get('dataverse_webform')->error(
      'Unexpected error processing Dataverse submission for webform @webform_id (submission @submission_id): @error',
      [
        '@webform_id' => $webform_submission->getWebform()->id(),
        '@submission_id' => $webform_submission->id(),
        '@error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]
    );
  }

  protected function logSubmissionResults(WebformSubmissionInterface $webform_submission, array $results): void {
    $logger = $this->loggerFactory->get('dataverse_webform');
    $successful_entities = [];
    $failed_entities = [];

    foreach ($results as $entity_name => $result) {
      if ($result['success']) {
        $successful_entities[] = $entity_name;
      } else {
        $failed_entities[] = $entity_name;
      }
    }

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

  protected function shouldRetrySubmission(WebformSubmissionInterface $webform_submission, array $dataverse_config): bool {
    $max_retries = $dataverse_config['retry_attempts'] ?? 3;
    $retry_key = 'dataverse_webform:retry:' . $webform_submission->id();
    $retry_count = $this->state->get($retry_key, 0);
    
    return $retry_count < $max_retries;
  }

  protected function scheduleRetry(WebformSubmissionInterface $webform_submission, array $dataverse_config, \Exception $exception): void {
    $retry_key = 'dataverse_webform:retry:' . $webform_submission->id();
    $retry_count = $this->state->get($retry_key, 0) + 1;
    $this->state->set($retry_key, $retry_count);
    
    $delay = min(300, pow(2, $retry_count) * 30); // Exponential backoff with 5min cap
    
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $queue_item = array_merge(
      $this->buildQueueItem($webform_submission, $dataverse_config),
      [
        'created' => time() + $delay,
        'retry_count' => $retry_count,
        'last_error' => $exception->getMessage(),
      ]
    );

    $queue->createItem($queue_item);
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'retry_submissions', 1);
  }

  protected function buildQueueItem(WebformSubmissionInterface $webform_submission, array $dataverse_config): array {
    return [
      'submission_id' => $webform_submission->id(),
      'webform_id' => $webform_submission->getWebform()->id(),
      'config' => $dataverse_config,
      'created' => time(),
      'user_id' => $this->currentUser->id(),
      'retry_count' => 0,
    ];
  }

  protected function loadSubmission(string $submission_id): ?WebformSubmissionInterface {
    $submission_storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
    $webform_submission = $submission_storage->load($submission_id);

    if (!$webform_submission) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Webform submission @submission_id not found for queued processing',
        ['@submission_id' => $submission_id]
      );
    }

    return $webform_submission;
  }

  protected function recordSubmissionAttempt(WebformSubmissionInterface $webform_submission): void {
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'total_submissions', 1);
  }

  protected function recordSubmissionSuccess(WebformSubmissionInterface $webform_submission, array $results, float $start_time): void {
    $processing_time = microtime(true) - $start_time;
    $webform_id = $webform_submission->getWebform()->id();
    
    $this->updateSubmissionStats($webform_id, 'successful_submissions', 1);
    $this->updateSubmissionStats($webform_id, 'total_processing_time', $processing_time);
  }

  protected function recordSubmissionFailure(WebformSubmissionInterface $webform_submission, \Exception $exception, float $start_time): void {
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'failed_submissions', 1);
  }

  protected function updateSubmissionStats(string $webform_id, string $stat_name, $increment): void {
    $current_time = time();
    
    // Update global stats
    $global_stats = $this->getSubmissionStats();
    $global_stats[$stat_name] = ($global_stats[$stat_name] ?? 0) + $increment;
    $global_stats['last_updated'] = $current_time;
    $this->state->set('dataverse_webform:stats', $global_stats);

    // Update webform-specific stats
    $webform_stats = $this->getSubmissionStats($webform_id);
    $webform_stats[$stat_name] = ($webform_stats[$stat_name] ?? 0) + $increment;
    $webform_stats['last_updated'] = $current_time;
    $this->state->set('dataverse_webform:stats:' . $webform_id, $webform_stats);
  }

  protected function getSubmissionStats(?string $webform_id = null): array {
    $stats_key = $this->buildStatsKey($webform_id);
    return $this->state->get($stats_key, $this->getDefaultStats());
  }

  protected function buildStatsKey(?string $webform_id): string {
    return 'dataverse_webform:stats' . ($webform_id ? ':' . $webform_id : '');
  }

  protected function getDefaultStats(): array {
    return [
      'total_submissions' => 0,
      'successful_submissions' => 0,
      'failed_submissions' => 0,
      'queued_submissions' => 0,
      'retry_submissions' => 0,
      'total_processing_time' => 0,
      'last_updated' => time(),
    ];
  }

}