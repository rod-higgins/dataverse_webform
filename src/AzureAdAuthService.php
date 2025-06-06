<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\dataverse_webform\Exception\DataverseException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Azure AD authentication service for Dataverse.
 */
class AzureAdAuthService {

  public const OAUTH_ENDPOINT_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
  public const TOKEN_EXPIRATION_BUFFER = 60;
  public const MAX_TOKEN_LIFETIME = 86400;
  public const LOCK_TIMEOUT = 30;
  public const LOCK_WAIT_TIME = 500000; // 500ms in microseconds
  public const DEFAULT_REQUEST_TIMEOUT = 30;

  protected ClientFactory $httpClientFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected StateInterface $state;
  protected KeyRepositoryInterface $keyRepository;
  protected LockBackendInterface $lock;

  public function __construct(
    ClientFactory $http_client_factory,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    KeyRepositoryInterface $key_repository,
    LockBackendInterface $lock
  ) {
    $this->httpClientFactory = $http_client_factory;
    $this->loggerFactory = $logger_factory;
    $this->state = $state;
    $this->keyRepository = $key_repository;
    $this->lock = $lock;
  }

  /**
   * Get access token for Dataverse API.
   */
  public function getAccessToken(array $config): ?string {
    $this->validateAuthConfig($config);

    $cached_token = $this->getCachedTokenSafely($config);
    if ($cached_token) {
      return $cached_token;
    }

    return $this->refreshTokenSafely($config);
  }

  /**
   * Invalidate cached token.
   */
  public function invalidateToken(array $config): void {
    $cache_key = $this->buildCacheKey($config);
    $lock_key = $cache_key . ':lock';
    
    if ($this->lock->acquire($lock_key, self::LOCK_TIMEOUT)) {
      try {
        $this->state->delete($cache_key);
        $this->loggerFactory->get('dataverse_webform')->info('Invalidated cached Azure AD token');
      } finally {
        $this->lock->release($lock_key);
      }
    } else {
      $this->state->delete($cache_key);
    }
  }

  /**
   * Check if valid token exists.
   */
  public function hasValidToken(array $config): bool {
    return $this->getCachedToken($config) !== null;
  }

  /**
   * Get cached token safely with locking.
   */
  protected function getCachedTokenSafely(array $config): ?string {
    $cache_key = $this->buildCacheKey($config);
    $lock_key = $cache_key . ':lock';
    
    if ($this->lock->acquire($lock_key, 5)) {
      try {
        return $this->getCachedToken($config);
      } finally {
        $this->lock->release($lock_key);
      }
    }
    
    return $this->getCachedToken($config);
  }

  /**
   * Get cached token from state.
   */
  protected function getCachedToken(array $config): ?string {
    $cache_key = $this->buildCacheKey($config);
    $cached_data = $this->state->get($cache_key);

    if (!$this->isValidCacheData($cached_data)) {
      return null;
    }

    if ($cached_data['expires'] > time()) {
      return $cached_data['token'];
    }

    return null;
  }

  /**
   * Refresh token safely with locking.
   */
  protected function refreshTokenSafely(array $config): ?string {
    $cache_key = $this->buildCacheKey($config);
    $lock_key = $cache_key . ':refresh';
    
    if ($this->lock->acquire($lock_key, self::LOCK_TIMEOUT)) {
      try {
        return $this->handleTokenRefresh($config);
      } finally {
        $this->lock->release($lock_key);
      }
    }
    
    return $this->waitAndRetryToken($config);
  }

  /**
   * Handle token refresh with double-check.
   */
  protected function handleTokenRefresh(array $config): ?string {
    // Check again in case another process refreshed it.
    $cached_token = $this->getCachedToken($config);
    if ($cached_token) {
      return $cached_token;
    }

    return $this->refreshAccessToken($config);
  }

  /**
   * Wait and retry token retrieval.
   */
  protected function waitAndRetryToken(array $config): ?string {
    usleep(self::LOCK_WAIT_TIME);
    
    $cached_token = $this->getCachedToken($config);
    if ($cached_token) {
      return $cached_token;
    }
    
    // Fallback to direct refresh.
    return $this->refreshAccessToken($config);
  }

  /**
   * Refresh access token from Azure AD.
   */
  protected function refreshAccessToken(array $config): ?string {
    $credentials = $this->getAzureCredentials($config);
    
    try {
      $response = $this->makeTokenRequest($config, $credentials);
      $body = $this->parseTokenResponse($response);
      
      if (isset($body['access_token'])) {
        return $this->processTokenResponse($config, $body);
      }

      $this->handleTokenError($body);
      
    } catch (RequestException $e) {
      $this->handleRequestException($e);
    }

    return null;
  }

  /**
   * Process successful token response.
   */
  protected function processTokenResponse(array $config, array $body): string {
    $token = $body['access_token'];
    $expires_in = min((int) ($body['expires_in'] ?? 3600), self::MAX_TOKEN_LIFETIME);
    
    $this->cacheToken($config, $token, $expires_in);
    $this->logSuccessfulTokenRefresh($expires_in);
    
    return $token;
  }

  /**
   * Make token request to Azure AD.
   */
  protected function makeTokenRequest(array $config, array $credentials): ResponseInterface {
    $client = $this->httpClientFactory->fromOptions([
      'timeout' => self::DEFAULT_REQUEST_TIMEOUT,
      'connect_timeout' => 10,
    ]);
    
    $oauth_url = sprintf(self::OAUTH_ENDPOINT_TEMPLATE, $credentials['tenant_id']);
    
    return $client->post($oauth_url, [
      'form_params' => [
        'client_id' => $credentials['client_id'],
        'client_secret' => $credentials['client_secret'],
        'scope' => $config['dataverse_url'] . '/.default',
        'grant_type' => 'client_credentials',
      ],
      'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
    ]);
  }

  /**
   * Handle token error response.
   */
  protected function handleTokenError(array $body): void {
    $error = $body['error'] ?? 'unknown_error';
    $error_description = $body['error_description'] ?? 'No error description provided';
    
    throw DataverseException::authenticationError("{$error} - {$error_description}");
  }

  /**
   * Handle request exception.
   */
  protected function handleRequestException(RequestException $e): void {
    $error_message = 'Azure AD authentication request failed: ' . $e->getMessage();
    $this->loggerFactory->get('dataverse_webform')->error($error_message);
    throw DataverseException::networkError($error_message, ['previous' => $e]);
  }

  /**
   * Log successful token refresh.
   */
  protected function logSuccessfulTokenRefresh(int $expires_in): void {
    $this->loggerFactory->get('dataverse_webform')->info(
      'Successfully obtained Azure AD access token, expires in @expires seconds',
      ['@expires' => $expires_in]
    );
  }

  /**
   * Get Azure credentials from configuration.
   */
  protected function getAzureCredentials(array $config): array {
    $client_id = $this->getKeyValue($config['azure_client_id_key'] ?? '');
    $client_secret = $this->getKeyValue($config['azure_client_secret_key'] ?? '');
    $tenant_id = $config['azure_tenant_id'] ?? '';

    if (empty($client_id) || empty($client_secret) || empty($tenant_id)) {
      throw DataverseException::configurationError('Missing Azure AD configuration');
    }

    return [
      'client_id' => $client_id,
      'client_secret' => $client_secret,
      'tenant_id' => $tenant_id,
    ];
  }

  /**
   * Get key value from key repository.
   */
  protected function getKeyValue(string $key_id): ?string {
    if (empty($key_id)) {
      return null;
    }

    try {
      $key = $this->keyRepository->getKey($key_id);
      if (!$key) {
        throw DataverseException::configurationError("Key '{$key_id}' not found");
      }

      $value = $key->getKeyValue();
      if (empty($value)) {
        throw DataverseException::configurationError("Key '{$key_id}' has no value");
      }

      return $value;
    } catch (\Exception $e) {
      throw DataverseException::configurationError("Failed to retrieve key '{$key_id}': " . $e->getMessage(), ['previous' => $e]);
    }
  }

  /**
   * Parse token response from Azure AD.
   */
  protected function parseTokenResponse(ResponseInterface $response): array {
    $content = $response->getBody()->getContents();
    $body = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw DataverseException::authenticationError('Invalid JSON response from Azure AD: ' . json_last_error_msg());
    }
    
    return $body;
  }

  /**
   * Cache token in state storage.
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
   */
  protected function buildCacheKey(array $config): string {
    $cache_config = [
      'azure_tenant_id' => $config['azure_tenant_id'] ?? '',
      'azure_client_id_key' => $config['azure_client_id_key'] ?? '',
      'dataverse_url' => $config['dataverse_url'] ?? '',
    ];
    
    return 'dataverse_webform.token.' . hash('sha256', serialize($cache_config));
  }

  /**
   * Check if cached data is valid.
   */
  protected function isValidCacheData($cached_data): bool {
    return is_array($cached_data) && 
           !empty($cached_data['token']) && 
           !empty($cached_data['expires']);
  }

  /**
   * Validate authentication configuration.
   */
  protected function validateAuthConfig(array $config): void {
    $required_fields = ['azure_tenant_id', 'azure_client_id_key', 'azure_client_secret_key', 'dataverse_url'];

    foreach ($required_fields as $field) {
      if (empty($config[$field])) {
        throw DataverseException::configurationError("Missing required Azure AD configuration: {$field}");
      }
    }

    // Validate tenant ID format.
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $config['azure_tenant_id'])) {
      throw DataverseException::validationError('Azure Tenant ID must be a valid GUID format');
    }

    // Validate Dataverse URL.
    if (!filter_var($config['dataverse_url'], FILTER_VALIDATE_URL) || !str_starts_with($config['dataverse_url'], 'https://')) {
      throw DataverseException::validationError('Dataverse URL must be a valid HTTPS URL');
    }
  }

}