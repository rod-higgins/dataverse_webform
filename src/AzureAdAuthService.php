<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\dataverse_webform\Exception\DataverseException;
use GuzzleHttp\Exception\RequestException;

/**
 * Azure AD authentication service for Dataverse with enhanced security.
 */
class AzureAdAuthService {

  /**
   * Azure AD OAuth 2.0 endpoint template.
   */
  public const OAUTH_ENDPOINT_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

  /**
   * Default token expiration buffer in seconds.
   */
  public const TOKEN_EXPIRATION_BUFFER = 60;

  /**
   * Maximum token lifetime in seconds (24 hours).
   */
  public const MAX_TOKEN_LIFETIME = 86400;

  /**
   * Token refresh lock timeout in seconds.
   */
  public const LOCK_TIMEOUT = 30;

  /**
   * The HTTP client factory.
   */
  protected ClientFactory $httpClientFactory;

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The state service.
   */
  protected StateInterface $state;

  /**
   * The key repository service.
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * The config factory service.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The lock service.
   */
  protected LockBackendInterface $lock;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock service.
   */
  public function __construct(
    ClientFactory $http_client_factory,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    KeyRepositoryInterface $key_repository,
    ConfigFactoryInterface $config_factory,
    LockBackendInterface $lock
  ) {
    $this->httpClientFactory = $http_client_factory;
    $this->loggerFactory = $logger_factory;
    $this->state = $state;
    $this->keyRepository = $key_repository;
    $this->configFactory = $config_factory;
    $this->lock = $lock;
  }

  /**
   * Get access token for Dataverse API.
   *
   * @param array $config
   *   Configuration containing key references.
   *
   * @return string|null
   *   The access token or NULL on failure.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When authentication fails.
   */
  public function getAccessToken(array $config): ?string {
    // Validate configuration
    $this->validateAuthConfig($config);

    // Check if we have a cached valid token with locking
    $cached_token = $this->getCachedTokenWithLock($config);
    if ($cached_token) {
      return $cached_token;
    }

    // Get new token with locking to prevent race conditions
    return $this->getNewTokenWithLock($config);
  }

  /**
   * Invalidate cached token for specific configuration.
   *
   * @param array $config
   *   Configuration array.
   */
  public function invalidateToken(array $config): void {
    $cache_key = $this->buildCacheKey($config);
    $lock_key = $cache_key . ':lock';
    
    // Acquire lock before invalidating to prevent race conditions
    if ($this->lock->acquire($lock_key, self::LOCK_TIMEOUT)) {
      try {
        $this->state->delete($cache_key);
        $this->loggerFactory->get('dataverse_webform')->info('Invalidated cached Azure AD token');
      } finally {
        $this->lock->release($lock_key);
      }
    } else {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Could not acquire lock to invalidate token, proceeding anyway'
      );
      $this->state->delete($cache_key);
    }
  }

  /**
   * Check if current configuration has a valid cached token.
   *
   * @param array $config
   *   Configuration array.
   *
   * @return bool
   *   TRUE if valid token exists, FALSE otherwise.
   */
  public function hasValidToken(array $config): bool {
    return $this->getCachedToken($config) !== null;
  }

  /**
   * Get a value from the Key module.
   *
   * @param string $key_id
   *   The key ID.
   *
   * @return string|null
   *   The key value or NULL if not found.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When key cannot be retrieved.
   */
  protected function getKeyValue(string $key_id): ?string {
    if (empty($key_id)) {
      return null;
    }

    try {
      $key = $this->keyRepository->getKey($key_id);
      if (!$key) {
        throw new DataverseException("Key '{$key_id}' not found");
      }

      $value = $key->getKeyValue();
      if (empty($value)) {
        throw new DataverseException("Key '{$key_id}' has no value");
      }

      return $value;
      
    } catch (\Exception $e) {
      throw new DataverseException("Failed to retrieve key '{$key_id}': " . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Get cached access token with locking mechanism.
   *
   * @param array $config
   *   Configuration array.
   *
   * @return string|null
   *   The cached token or NULL if expired/not found.
   */
  protected function getCachedTokenWithLock(array $config): ?string {
    $cache_key = $this->buildCacheKey($config);
    $lock_key = $cache_key . ':lock';
    
    // Try to acquire lock for reading token
    if ($this->lock->acquire($lock_key, 5)) {
      try {
        return $this->getCachedToken($config);
      } finally {
        $this->lock->release($lock_key);
      }
    } else {
      // If we can't get a lock quickly, just read without lock
      // as this is likely a read operation and the token is probably valid
      return $this->getCachedToken($config);
    }
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
  protected function getCachedToken(array $config): ?string {
    $cache_key = $this->buildCacheKey($config);
    $cached_data = $this->state->get($cache_key);

    if ($cached_data && is_array($cached_data)) {
      $expires = $cached_data['expires'] ?? 0;
      $token = $cached_data['token'] ?? '';
      
      if (!empty($token) && $expires > time()) {
        return $token;
      }
    }

    return null;
  }

  /**
   * Get new token with locking mechanism to prevent race conditions.
   *
   * @param array $config
   *   Configuration array.
   *
   * @return string|null
   *   The new access token or NULL on failure.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When authentication fails.
   */
  protected function getNewTokenWithLock(array $config): ?string {
    $cache_key = $this->buildCacheKey($config);
    $lock_key = $cache_key . ':refresh';
    
    // Try to acquire lock for token refresh
    if ($this->lock->acquire($lock_key, self::LOCK_TIMEOUT)) {
      try {
        // Double-check if token was refreshed by another process while waiting for lock
        $cached_token = $this->getCachedToken($config);
        if ($cached_token) {
          return $cached_token;
        }

        // Proceed with token refresh
        return $this->refreshAccessToken($config);
        
      } finally {
        $this->lock->release($lock_key);
      }
    } else {
      // If we can't acquire the lock, another process is likely refreshing the token
      // Wait a bit and check if token is now available
      usleep(500000); // Wait 500ms
      
      $cached_token = $this->getCachedToken($config);
      if ($cached_token) {
        return $cached_token;
      }
      
      // If still no token, try to refresh without lock (last resort)
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Could not acquire lock for token refresh, proceeding without lock'
      );
      return $this->refreshAccessToken($config);
    }
  }

  /**
   * Refresh access token from Azure AD.
   *
   * @param array $config
   *   Configuration array.
   *
   * @return string|null
   *   The new access token or NULL on failure.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When authentication fails.
   */
  protected function refreshAccessToken(array $config): ?string {
    // Get Azure AD credentials from Key module
    $client_id = $this->getKeyValue($config['azure_client_id_key'] ?? '');
    $client_secret = $this->getKeyValue($config['azure_client_secret_key'] ?? '');
    $tenant_id = $config['azure_tenant_id'] ?? '';

    if (empty($client_id) || empty($client_secret) || empty($tenant_id)) {
      throw new DataverseException('Missing Azure AD configuration');
    }

    try {
      $client = $this->httpClientFactory->fromOptions(['timeout' => 30]);
      
      $oauth_url = sprintf(self::OAUTH_ENDPOINT_TEMPLATE, $tenant_id);
      
      $response = $client->post($oauth_url, [
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

      $content = $response->getBody()->getContents();
      $body = json_decode($content, true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new DataverseException('Invalid JSON response from Azure AD');
      }
      
      if (isset($body['access_token'])) {
        $expires_in = (int) ($body['expires_in'] ?? 3600);
        
        // Validate token expiration is reasonable
        if ($expires_in > self::MAX_TOKEN_LIFETIME) {
          $expires_in = self::MAX_TOKEN_LIFETIME;
        }
        
        // Cache the token with expiration
        $this->cacheToken($config, $body['access_token'], $expires_in);
        
        $this->loggerFactory->get('dataverse_webform')->info(
          'Successfully obtained Azure AD access token, expires in @expires seconds',
          ['@expires' => $expires_in]
        );
        
        return $body['access_token'];
      }

      // Handle Azure AD error response
      $error = $body['error'] ?? 'unknown_error';
      $error_description = $body['error_description'] ?? 'No error description provided';
      
      throw new DataverseException("Azure AD authentication failed: {$error} - {$error_description}");

    } catch (RequestException $e) {
      $error_message = 'Azure AD authentication request failed: ' . $e->getMessage();
      $this->loggerFactory->get('dataverse_webform')->error($error_message);
      throw new DataverseException($error_message, 0, $e);
    }
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
  protected function cacheToken(array $config, string $token, int $expires_in): void {
    $cache_key = $this->buildCacheKey($config);
    
    $cache_data = [
      'token' => $token,
      'expires' => time() + $expires_in - self::TOKEN_EXPIRATION_BUFFER,
      'created' => time(),
      'expires_in' => $expires_in,
    ];
    
    $this->state->set($cache_key, $cache_data);
  }

  /**
   * Build cache key for token storage.
   *
   * @param array $config
   *   Configuration array.
   *
   * @return string
   *   The cache key.
   */
  protected function buildCacheKey(array $config): string {
    // Use only relevant config for cache key
    $cache_config = [
      'azure_tenant_id' => $config['azure_tenant_id'] ?? '',
      'azure_client_id_key' => $config['azure_client_id_key'] ?? '',
      'dataverse_url' => $config['dataverse_url'] ?? '',
    ];
    
    return 'dataverse_webform.token.' . hash('sha256', serialize($cache_config));
  }

  /**
   * Validate Azure AD authentication configuration.
   *
   * @param array $config
   *   Configuration array to validate.
   *
   * @throws \Drupal\dataverse_webform\Exception\DataverseException
   *   When configuration is invalid.
   */
  protected function validateAuthConfig(array $config): void {
    $required_fields = [
      'azure_tenant_id',
      'azure_client_id_key',
      'azure_client_secret_key',
      'dataverse_url',
    ];

    foreach ($required_fields as $field) {
      if (empty($config[$field])) {
        throw new DataverseException("Missing required Azure AD configuration: {$field}");
      }
    }

    // Validate tenant ID format (GUID)
    $tenant_id = $config['azure_tenant_id'];
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenant_id)) {
      throw new DataverseException('Azure Tenant ID must be a valid GUID format');
    }

    // Validate URL format
    $dataverse_url = $config['dataverse_url'];
    if (!filter_var($dataverse_url, FILTER_VALIDATE_URL)) {
      throw new DataverseException('Dataverse URL must be a valid URL');
    }

    // Ensure URL is HTTPS for security
    if (strpos($dataverse_url, 'https://') !== 0) {
      throw new DataverseException('Dataverse URL must use HTTPS');
    }
  }

}