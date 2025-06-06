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
    $webform = $webform_submission->getWebform();

    try {
      $this->validateSubmissionPreProcessing($webform_submission, $dataverse_config);
      $results = $this->dataverseClient->submitToDataverse($webform_submission, $dataverse_config);
      
      $this->recordSubmissionSuccess($webform_submission, $results, $start_time);
      $this->logSubmissionResults($webform, $webform_submission, $results);
      
    } catch (DataverseException $e) {
      $this->recordSubmissionFailure($webform_submission, $e, $start_time);
      $this->handleSubmissionError($webform_submission, $dataverse_config, $e);
    } catch (\Exception $e) {
      $this->recordSubmissionFailure($webform_submission, $e, $start_time);
      $this->loggerFactory->get('dataverse_webform')->error(
        'Unexpected error processing Dataverse submission for webform @webform_id (submission @submission_id): @error',
        ['@webform_id' => $webform->id(), '@submission_id' => $webform_submission->id(), '@error' => $e->getMessage()]
      );
    }
  }

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
      ['@webform_id' => $webform_submission->getWebform()->id(), '@submission_id' => $webform_submission->id()]
    );
  }

  public function processQueuedSubmission(array $queue_item_data): bool {
    $submission_id = $queue_item_data['submission_id'];
    $config = $queue_item_data['config'];

    try {
      $submission_storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
      $webform_submission = $submission_storage->load($submission_id);

      if (!$webform_submission) {
        $this->loggerFactory->get('dataverse_webform')->warning(
          'Webform submission @submission_id not found for queued processing',
          ['@submission_id' => $submission_id]
        );
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

  public function getSubmissionStatistics(?string $webform_id = null, int $days = 7): array {
    $stats_key = 'dataverse_webform:stats' . ($webform_id ? ':' . $webform_id : '');
    
    $stats = $this->state->get($stats_key, [
      'total_submissions' => 0,
      'successful_submissions' => 0,
      'failed_submissions' => 0,
      'queued_submissions' => 0,
      'retry_submissions' => 0,
      'last_updated' => time(),
    ]);

    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $stats['current_queue_size'] = $queue->numberOfItems();

    return $stats;
  }

  public function clearSubmissionStatistics(?string $webform_id = null): void {
    $stats_key = 'dataverse_webform:stats' . ($webform_id ? ':' . $webform_id : '');
    $this->state->delete($stats_key);
    
    $this->loggerFactory->get('dataverse_webform')->info(
      'Cleared submission statistics for @scope',
      ['@scope' => $webform_id ? "webform {$webform_id}" : 'all webforms']
    );
  }

  protected function shouldProcessImmediately(WebformSubmissionInterface $webform_submission, array $dataverse_config): bool {
    $global_config = $this->configFactory->get('dataverse_webform.settings');
    
    if ($global_config->get('force_queue_processing')) {
      return false;
    }

    if (memory_get_usage() > self::MEMORY_THRESHOLD) {
      return false;
    }

    $max_execution_time = ini_get('max_execution_time');
    if ($max_execution_time > 0 && $max_execution_time < self::TIME_THRESHOLD) {
      return false;
    }

    $field_mappings = $dataverse_config['field_mappings'] ?? [];
    $entity_count = count(array_unique(array_column($field_mappings, 'entity')));
    
    if ($entity_count > 3) {
      return false;
    }

    $submission_data = $webform_submission->getData();
    if (strlen(serialize($submission_data)) > 50000) {
      return false;
    }

    return true;
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

  protected function handleSubmissionError(WebformSubmissionInterface $webform_submission, array $dataverse_config, DataverseException $e): void {
    $this->loggerFactory->get('dataverse_webform')->error(
      'Failed to process Dataverse submission for webform @webform_id (submission @submission_id): @error',
      [
        '@webform_id' => $webform_submission->getWebform()->id(),
        '@submission_id' => $webform_submission->id(),
        '@error' => $e->getMessage(),
      ]
    );

    if ($e->isRetryable() && $this->shouldRetrySubmission($webform_submission, $dataverse_config)) {
      $this->scheduleRetry($webform_submission, $dataverse_config, $e);
    }
  }

  protected function logSubmissionResults($webform, $webform_submission, array $results): void {
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
        ['@submission_id' => $webform_submission->id(), '@entities' => implode(', ', $successful_entities)]
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
    
    $delay = min(300, pow(2, $retry_count) * 30);
    
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
  }

  protected function recordSubmissionAttempt(WebformSubmissionInterface $webform_submission): void {
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'total_submissions', 1);
  }

  protected function recordSubmissionSuccess(WebformSubmissionInterface $webform_submission, array $results, float $start_time): void {
    $processing_time = microtime(true) - $start_time;
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'successful_submissions', 1);
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'total_processing_time', $processing_time);
  }

  protected function recordSubmissionFailure(WebformSubmissionInterface $webform_submission, \Exception $exception, float $start_time): void {
    $this->updateSubmissionStats($webform_submission->getWebform()->id(), 'failed_submissions', 1);
  }

  protected function updateSubmissionStats(string $webform_id, string $stat_name, $increment): void {
    $global_stats_key = 'dataverse_webform:stats';
    $global_stats = $this->state->get($global_stats_key, []);
    $global_stats[$stat_name] = ($global_stats[$stat_name] ?? 0) + $increment;
    $global_stats['last_updated'] = time();
    $this->state->set($global_stats_key, $global_stats);

    $webform_stats_key = 'dataverse_webform:stats:' . $webform_id;
    $webform_stats = $this->state->get($webform_stats_key, []);
    $webform_stats[$stat_name] = ($webform_stats[$stat_name] ?? 0) + $increment;
    $webform_stats['last_updated'] = time();
    $this->state->set($webform_stats_key, $webform_stats);
  }
}