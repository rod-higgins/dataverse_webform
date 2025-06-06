<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Azure AD authentication service for Dataverse.
 */
class AzureAdAuthService {

  /**
   * The HTTP client factory.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected $httpClientFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Constructs an AzureAdAuthService object.
   *
   * @param \Drupal\Core\Http\ClientFactory $http_client_factory
   *   The HTTP client factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   */
  public function __construct(
    ClientFactory $http_client_factory,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    KeyRepositoryInterface $key_repository
  ) {
    $this->httpClientFactory = $http_client_factory;
    $this->loggerFactory = $logger_factory;
    $this->state = $state;
    $this->keyRepository = $key_repository;
  }

  /**
   * Get access token for Dataverse API.
   *
   * @param array $config
   *   Configuration containing key references.
   *
   * @return string|null
   *   The access token or NULL on failure.
   */
  public function getAccessToken(array $config) {
    // Check if we have a cached valid token
    $cached_token = $this->getCachedToken($config);
    if ($cached_token) {
      return $cached_token;
    }

    // Get Azure AD credentials from Key module
    $client_id = $this->getKeyValue($config['azure_client_id_key'] ?? '');
    $client_secret = $this->getKeyValue($config['azure_client_secret_key'] ?? '');
    $tenant_id = $config['azure_tenant_id'] ?? '';

    if (empty($client_id) || empty($client_secret) || empty($tenant_id)) {
      $this->loggerFactory->get('dataverse_webform')->error('Missing Azure AD configuration');
      return NULL;
    }

    try {
      $client = $this->httpClientFactory->fromOptions(['timeout' => 30]);
      
      $response = $client->post("https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token", [
        'form_params' => [
          'client_id' => $client_id,
          'client_secret' => $client_secret,
          'scope' => $config['dataverse_url'] . '/.default',
          'grant_type' => 'client_credentials',
        ],
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
      ]);

      $body = json_decode($response->getBody()->getContents(), TRUE);
      
      if (isset($body['access_token'])) {
        // Cache the token with expiration
        $this->cacheToken($config, $body['access_token'], $body['expires_in'] ?? 3600);
        return $body['access_token'];
      }

    } catch (RequestException $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Azure AD authentication failed: @error',
        ['@error' => $e->getMessage()]
      );
    }

    return NULL;
  }

  /**
   * Get a value from the Key module.
   *
   * @param string $key_id
   *   The key ID.
   *
   * @return string|null
   *   The key value or NULL if not found.
   */
  protected function getKeyValue($key_id) {
    if (empty($key_id)) {
      return NULL;
    }

    $key = $this->keyRepository->getKey($key_id);
    return $key ? $key->getKeyValue() : NULL;
  }

  /**
   * Get cached access token if still valid.
   *
   * @param array $config
   *   Configuration array.
   *
   * @return string|null
   *   The cached token or NULL if expired/not found.
   */
  protected function getCachedToken(array $config) {
    $cache_key = 'dataverse_webform.token.' . md5(serialize($config));
    $cached_data = $this->state->get($cache_key);

    if ($cached_data && $cached_data['expires'] > time()) {
      return $cached_data['token'];
    }

    return NULL;
  }

  /**
   * Cache an access token.
   *
   * @param array $config
   *   Configuration array.
   * @param string $token
   *   The access token.
   * @param int $expires_in
   *   Token lifetime in seconds.
   */
  protected function cacheToken(array $config, $token, $expires_in) {
    $cache_key = 'dataverse_webform.token.' . md5(serialize($config));
    
    $this->state->set($cache_key, [
      'token' => $token,
      'expires' => time() + $expires_in - 60, // Expire 60 seconds early for safety
    ]);
  }

}