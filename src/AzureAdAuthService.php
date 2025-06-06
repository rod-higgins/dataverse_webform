<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\dataverse_webform\Exception\DataverseException;
use GuzzleHttp\Exception\RequestException;

/**
 * Azure AD authentication service for Dataverse.
 */
class AzureAdAuthService {

  public const OAUTH_ENDPOINT_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
  public const TOKEN_EXPIRATION_BUFFER = 60;
  public const MAX_TOKEN_LIFETIME = 86400;
  public const LOCK_TIMEOUT = 30;

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

  public function getAccessToken(array $config): ?string {
    $this->validateAuthConfig($config);

    $cached_token = $this->getCachedTokenWithLock($config);
    if ($cached_token) {
      return $cached_token;
    }

    return $this->getNewTokenWithLock($config);
  }

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

  public function hasValidToken(array $config): bool {
    return $this->getCachedToken($config) !== null;
  }

  protected function getCachedTokenWithLock(array $config): ?string {
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

  protected function getNewTokenWithLock(array $config): ?string {
    $cache_key = $this->buildCacheKey($config);
    $lock_key = $cache_key . ':refresh';
    
    if ($this->lock->acquire($lock_key, self::LOCK_TIMEOUT)) {
      try {
        $cached_token = $this->getCachedToken($config);
        if ($cached_token) {
          return $cached_token;
        }

        return $this->refreshAccessToken($config);
      } finally {
        $this->lock->release($lock_key);
      }
    }
    
    usleep(500000); // Wait 500ms for other process
    $cached_token = $this->getCachedToken($config);
    if ($cached_token) {
      return $cached_token;
    }
    
    return $this->refreshAccessToken($config);
  }

  protected function refreshAccessToken(array $config): ?string {
    $credentials = $this->getAzureCredentials($config);
    
    try {
      $client = $this->httpClientFactory->fromOptions(['timeout' => 30]);
      $oauth_url = sprintf(self::OAUTH_ENDPOINT_TEMPLATE, $credentials['tenant_id']);
      
      $response = $client->post($oauth_url, [
        'form_params' => [
          'client_id' => $credentials['client_id'],
          'client_secret' => $credentials['client_secret'],
          'scope' => $config['dataverse_url'] . '/.default',
          'grant_type' => 'client_credentials',
        ],
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
      ]);

      $body = $this->parseTokenResponse($response);
      
      if (isset($body['access_token'])) {
        $expires_in = min((int) ($body['expires_in'] ?? 3600), self::MAX_TOKEN_LIFETIME);
        $this->cacheToken($config, $body['access_token'], $expires_in);
        
        $this->loggerFactory->get('dataverse_webform')->info(
          'Successfully obtained Azure AD access token, expires in @expires seconds',
          ['@expires' => $expires_in]
        );
        
        return $body['access_token'];
      }

      $error = $body['error'] ?? 'unknown_error';
      $error_description = $body['error_description'] ?? 'No error description provided';
      
      throw new DataverseException("Azure AD authentication failed: {$error} - {$error_description}");

    } catch (RequestException $e) {
      $error_message = 'Azure AD authentication request failed: ' . $e->getMessage();
      $this->loggerFactory->get('dataverse_webform')->error($error_message);
      throw new DataverseException($error_message, 0, $e);
    }
  }

  protected function getAzureCredentials(array $config): array {
    $client_id = $this->getKeyValue($config['azure_client_id_key'] ?? '');
    $client_secret = $this->getKeyValue($config['azure_client_secret_key'] ?? '');
    $tenant_id = $config['azure_tenant_id'] ?? '';

    if (empty($client_id) || empty($client_secret) || empty($tenant_id)) {
      throw new DataverseException('Missing Azure AD configuration');
    }

    return [
      'client_id' => $client_id,
      'client_secret' => $client_secret,
      'tenant_id' => $tenant_id,
    ];
  }

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

  protected function parseTokenResponse($response): array {
    $content = $response->getBody()->getContents();
    $body = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new DataverseException('Invalid JSON response from Azure AD');
    }
    
    return $body;
  }

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

  protected function buildCacheKey(array $config): string {
    $cache_config = [
      'azure_tenant_id' => $config['azure_tenant_id'] ?? '',
      'azure_client_id_key' => $config['azure_client_id_key'] ?? '',
      'dataverse_url' => $config['dataverse_url'] ?? '',
    ];
    
    return 'dataverse_webform.token.' . hash('sha256', serialize($cache_config));
  }

  protected function validateAuthConfig(array $config): void {
    $required_fields = ['azure_tenant_id', 'azure_client_id_key', 'azure_client_secret_key', 'dataverse_url'];

    foreach ($required_fields as $field) {
      if (empty($config[$field])) {
        throw new DataverseException("Missing required Azure AD configuration: {$field}");
      }
    }

    $tenant_id = $config['azure_tenant_id'];
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenant_id)) {
      throw new DataverseException('Azure Tenant ID must be a valid GUID format');
    }

    $dataverse_url = $config['dataverse_url'];
    if (!filter_var($dataverse_url, FILTER_VALIDATE_URL) || strpos($dataverse_url, 'https://') !== 0) {
      throw new DataverseException('Dataverse URL must be a valid HTTPS URL');
    }
  }
}