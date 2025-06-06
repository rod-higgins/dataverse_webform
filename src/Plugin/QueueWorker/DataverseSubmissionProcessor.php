<?php

namespace Drupal\dataverse_webform\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\dataverse_webform\Service\WebformSubmissionHandler;
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

  /**
   * The webform submission handler.
   */
  protected WebformSubmissionHandler $submissionHandler;

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs a DataverseSubmissionProcessor object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\dataverse_webform\Service\WebformSubmissionHandler $submission_handler
   *   The webform submission handler.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
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

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('dataverse_webform.submission_handler'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    try {
      $success = $this->submissionHandler->processQueuedSubmission($data);
      
      if (!$success) {
        // Re-queue failed items with exponential backoff
        $retry_count = $data['retry_count'] ?? 0;
        $max_retries = 3;
        
        if ($retry_count < $max_retries) {
          $data['retry_count'] = $retry_count + 1;
          $delay = min(300, pow(2, $retry_count) * 30); // Max 5 minutes
          
          $queue = \Drupal::queue('dataverse_webform_submissions');
          $queue->createItem($data);
          
          $this->loggerFactory->get('dataverse_webform')->info(
            'Re-queued failed submission @submission_id for retry @retry (delay: @delay seconds)',
            [
              '@submission_id' => $data['submission_id'] ?? 'unknown',
              '@retry' => $data['retry_count'],
              '@delay' => $delay,
            ]
          );
        } else {
          $this->loggerFactory->get('dataverse_webform')->error(
            'Permanently failed to process submission @submission_id after @retries retries',
            [
              '@submission_id' => $data['submission_id'] ?? 'unknown',
              '@retries' => $max_retries,
            ]
          );
        }
      }
      
    } catch (\Exception $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Exception in queue worker processing submission @submission_id: @error',
        [
          '@submission_id' => $data['submission_id'] ?? 'unknown',
          '@error' => $e->getMessage(),
        ]
      );
      
      // Re-throw to let Drupal handle the retry logic
      throw $e;
    }
  }

}