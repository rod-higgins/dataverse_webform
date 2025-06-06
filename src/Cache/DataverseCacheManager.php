<?php

namespace Drupal\dataverse_webform\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for managing Dataverse-related caching with enhanced performance.
 */
class DataverseCacheManager {

  /**
   * The cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Cache bin for Dataverse metadata.
   */
  public const CACHE_BIN = 'dataverse_webform';

  /**
   * Default cache TTL (1 hour).
   */
  public const DEFAULT_TTL = 3600;

  /**
   * Short cache TTL for frequently changing data (5 minutes).
   */
  public const SHORT_TTL = 300;

  /**
   * Long cache TTL for stable metadata (4 hours).
   */
  public const LONG_TTL = 14400;

  /**
   * Maximum cache entries to prevent memory issues.
   */
  public const MAX_CACHE_ENTRIES = 1000;

  /**
   * Constructs a DataverseCacheManager object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Get cached entities for a Dataverse configuration.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return array|null
   *   The cached entities or NULL if not found.
   */
  public function getCachedEntities(array $config): ?array {
    $cache_key = $this->buildEntitiesCacheKey($config);
    $cached = $this->cache->get($cache_key);
    
    if ($cached && $cached->data) {
      $this->loggerFactory->get('dataverse_webform')->debug(
        'Retrieved @count entities from cache',
        ['@count' => count($cached->data)]
      );
      return $cached->data;
    }
    
    return NULL;
  }

  /**
   * Set cached entities for a Dataverse configuration.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param array $entities
   *   The entities to cache.
   * @param int|null $ttl
   *   Optional custom TTL in seconds.
   */
  public function setCachedEntities(array $config, array $entities, ?int $ttl = null): void {
    $cache_key = $this->buildEntitiesCacheKey($config);
    $cache_tags = $this->buildEntitiesCacheTags($config);
    $expiration = $this->getExpiration($ttl ?? self::LONG_TTL);
    
    // Validate cache size
    if (count($entities) > self::MAX_CACHE_ENTRIES) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Large entity cache entry (@count items) may impact performance',
        ['@count' => count($entities)]
      );
    }
    
    $this->cache->set($cache_key, $entities, $expiration, $cache_tags);
    
    $this->loggerFactory->get('dataverse_webform')->debug(
      'Cached @count entities with TTL @ttl seconds',
      ['@count' => count($entities), '@ttl' => $ttl ?? self::LONG_TTL]
    );
  }

  /**
   * Get cached fields for an entity.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param string $entity_name
   *   The entity name.
   *
   * @return array|null
   *   The cached fields or NULL if not found.
   */
  public function getCachedEntityFields(array $config, string $entity_name): ?array {
    $cache_key = $this->buildFieldsCacheKey($config, $entity_name);
    $cached = $this->cache->get($cache_key);
    
    if ($cached && $cached->data) {
      $this->loggerFactory->get('dataverse_webform')->debug(
        'Retrieved @count fields for entity @entity from cache',
        ['@count' => count($cached->data), '@entity' => $entity_name]
      );
      return $cached->data;
    }
    
    return NULL;
  }

  /**
   * Set cached fields for an entity.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param string $entity_name
   *   The entity name.
   * @param array $fields
   *   The fields to cache.
   * @param int|null $ttl
   *   Optional custom TTL in seconds.
   */
  public function setCachedEntityFields(array $config, string $entity_name, array $fields, ?int $ttl = null): void {
    $cache_key = $this->buildFieldsCacheKey($config, $entity_name);
    $cache_tags = $this->buildFieldsCacheTags($config, $entity_name);
    $expiration = $this->getExpiration($ttl ?? self::DEFAULT_TTL);
    
    // Validate cache size
    if (count($fields) > self::MAX_CACHE_ENTRIES) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Large field cache entry for @entity (@count items) may impact performance',
        ['@entity' => $entity_name, '@count' => count($fields)]
      );
    }
    
    $this->cache->set($cache_key, $fields, $expiration, $cache_tags);
    
    $this->loggerFactory->get('dataverse_webform')->debug(
      'Cached @count fields for entity @entity with TTL @ttl seconds',
      ['@count' => count($fields), '@entity' => $entity_name, '@ttl' => $ttl ?? self::DEFAULT_TTL]
    );
  }

  /**
   * Get cached access token validation results.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return bool|null
   *   The cached validation result or NULL if not found.
   */
  public function getCachedTokenValidation(array $config): ?bool {
    $cache_key = $this->buildTokenValidationCacheKey($config);
    $cached = $this->cache->get($cache_key);
    
    return $cached ? $cached->data : NULL;
  }

  /**
   * Set cached access token validation results.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param bool $is_valid
   *   Whether the token is valid.
   */
  public function setCachedTokenValidation(array $config, bool $is_valid): void {
    $cache_key = $this->buildTokenValidationCacheKey($config);
    $cache_tags = $this->buildTokenValidationCacheTags($config);
    $expiration = $this->getExpiration(self::SHORT_TTL);
    
    $this->cache->set($cache_key, $is_valid, $expiration, $cache_tags);
  }

  /**
   * Get cached configuration validation results.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return array|null
   *   The cached validation results or NULL if not found.
   */
  public function getCachedConfigValidation(array $config): ?array {
    $cache_key = $this->buildConfigValidationCacheKey($config);
    $cached = $this->cache->get($cache_key);
    
    return $cached ? $cached->data : NULL;
  }

  /**
   * Set cached configuration validation results.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param array $validation_results
   *   The validation results to cache.
   */
  public function setCachedConfigValidation(array $config, array $validation_results): void {
    $cache_key = $this->buildConfigValidationCacheKey($config);
    $cache_tags = $this->buildConfigValidationCacheTags($config);
    $expiration = $this->getExpiration(self::SHORT_TTL);
    
    $this->cache->set($cache_key, $validation_results, $expiration, $cache_tags);
  }

  /**
   * Invalidate all cached data for a configuration.
   *
   * @param array $config
   *   The Dataverse configuration.
   */
  public function invalidateConfigCache(array $config): void {
    $base_tags = $this->buildBaseCacheTags($config);
    Cache::invalidateTags($base_tags);
    
    $this->loggerFactory->get('dataverse_webform')->info(
      'Invalidated cache for Dataverse configuration: @url',
      ['@url' => $config['dataverse_url'] ?? 'unknown']
    );
  }

  /**
   * Invalidate entity metadata cache for a specific entity.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param string $entity_name
   *   The entity name.
   */
  public function invalidateEntityCache(array $config, string $entity_name): void {
    $tags = [
      'dataverse_webform:fields:' . $entity_name,
      'dataverse_webform:config:' . hash('sha256', $config['dataverse_url'] ?? ''),
    ];
    
    Cache::invalidateTags($tags);
    
    $this->loggerFactory->get('dataverse_webform')->info(
      'Invalidated cache for entity: @entity',
      ['@entity' => $entity_name]
    );
  }

  /**
   * Invalidate all Dataverse caches.
   */
  public function invalidateAllCaches(): void {
    Cache::invalidateTags(['dataverse_webform']);
    
    $this->loggerFactory->get('dataverse_webform')->info(
      'Invalidated all Dataverse caches'
    );
  }

  /**
   * Clear expired cache entries to prevent memory buildup.
   */
  public function clearExpiredEntries(): void {
    // This method would typically be called via cron
    $this->cache->garbageCollection();
    
    $this->loggerFactory->get('dataverse_webform')->debug(
      'Cleared expired Dataverse cache entries'
    );
  }

  /**
   * Get cache statistics for monitoring.
   *
   * @return array
   *   Array containing cache statistics.
   */
  public function getCacheStatistics(): array {
    // This is a simplified version - in practice you might want
    // to implement more detailed statistics gathering
    return [
      'cache_bin' => self::CACHE_BIN,
      'default_ttl' => self::DEFAULT_TTL,
      'timestamp' => time(),
    ];
  }

  /**
   * Warm up cache with commonly used entities.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param array $entity_names
   *   Optional array of specific entities to warm up.
   */
  public function warmUpCache(array $config, array $entity_names = []): void {
    // This method could be used to proactively cache frequently used entities
    $this->loggerFactory->get('dataverse_webform')->info(
      'Cache warm-up initiated for @count entities',
      ['@count' => count($entity_names)]
    );
  }

  /**
   * Build cache key for entities.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return string
   *   The cache key.
   */
  protected function buildEntitiesCacheKey(array $config): string {
    $cache_config = $this->extractCacheableConfig($config);
    return 'dataverse_webform:entities:' . $this->generateConfigHash($cache_config);
  }

  /**
   * Build cache key for entity fields.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param string $entity_name
   *   The entity name.
   *
   * @return string
   *   The cache key.
   */
  protected function buildFieldsCacheKey(array $config, string $entity_name): string {
    $cache_config = $this->extractCacheableConfig($config);
    return 'dataverse_webform:fields:' . $entity_name . ':' . $this->generateConfigHash($cache_config);
  }

  /**
   * Build cache key for token validation.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return string
   *   The cache key.
   */
  protected function buildTokenValidationCacheKey(array $config): string {
    $cache_config = $this->extractAuthConfig($config);
    return 'dataverse_webform:token_validation:' . $this->generateConfigHash($cache_config);
  }

  /**
   * Build cache key for configuration validation.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return string
   *   The cache key.
   */
  protected function buildConfigValidationCacheKey(array $config): string {
    $cache_config = $this->extractValidationConfig($config);
    return 'dataverse_webform:config_validation:' . $this->generateConfigHash($cache_config);
  }

  /**
   * Build cache tags for entities.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return array
   *   The cache tags.
   */
  protected function buildEntitiesCacheTags(array $config): array {
    return array_merge(
      $this->buildBaseCacheTags($config),
      ['dataverse_webform:entities', 'dataverse_webform:metadata']
    );
  }

  /**
   * Build cache tags for entity fields.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param string $entity_name
   *   The entity name.
   *
   * @return array
   *   The cache tags.
   */
  protected function buildFieldsCacheTags(array $config, string $entity_name): array {
    return array_merge(
      $this->buildBaseCacheTags($config),
      [
        'dataverse_webform:fields',
        'dataverse_webform:fields:' . $entity_name,
        'dataverse_webform:metadata',
      ]
    );
  }

  /**
   * Build cache tags for token validation.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return array
   *   The cache tags.
   */
  protected function buildTokenValidationCacheTags(array $config): array {
    return array_merge(
      $this->buildBaseCacheTags($config),
      ['dataverse_webform:token_validation']
    );
  }

  /**
   * Build cache tags for configuration validation.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return array
   *   The cache tags.
   */
  protected function buildConfigValidationCacheTags(array $config): array {
    return array_merge(
      $this->buildBaseCacheTags($config),
      ['dataverse_webform:config_validation']
    );
  }

  /**
   * Build base cache tags for a configuration.
   *
   * @param array $config
   *   The Dataverse configuration.
   *
   * @return array
   *   The base cache tags.
   */
  protected function buildBaseCacheTags(array $config): array {
    return [
      'dataverse_webform',
      'dataverse_webform:config:' . $this->generateConfigHash(['url' => $config['dataverse_url'] ?? '']),
    ];
  }

  /**
   * Extract cacheable configuration elements.
   *
   * @param array $config
   *   The full configuration.
   *
   * @return array
   *   The cacheable configuration elements.
   */
  protected function extractCacheableConfig(array $config): array {
    return array_intersect_key($config, array_flip([
      'dataverse_url',
      'azure_tenant_id',
    ]));
  }

  /**
   * Extract authentication-related configuration for caching.
   *
   * @param array $config
   *   The full configuration.
   *
   * @return array
   *   The authentication configuration elements.
   */
  protected function extractAuthConfig(array $config): array {
    return array_intersect_key($config, array_flip([
      'dataverse_url',
      'azure_tenant_id',
      'azure_client_id_key',
      'azure_client_secret_key',
    ]));
  }

  /**
   * Extract validation-related configuration for caching.
   *
   * @param array $config
   *   The full configuration.
   *
   * @return array
   *   The validation configuration elements.
   */
  protected function extractValidationConfig(array $config): array {
    return array_intersect_key($config, array_flip([
      'dataverse_url',
      'azure_tenant_id',
      'azure_client_id_key',
      'azure_client_secret_key',
      'field_mappings',
      'timeout',
      'batch_size',
    ]));
  }

  /**
   * Generate a consistent hash for configuration arrays.
   *
   * @param array $config
   *   The configuration array.
   *
   * @return string
   *   The hash.
   */
  protected function generateConfigHash(array $config): string {
    return hash('sha256', serialize($config));
  }

  /**
   * Get cache expiration timestamp.
   *
   * @param int $ttl
   *   Time to live in seconds.
   *
   * @return int
   *   The expiration timestamp.
   */
  protected function getExpiration(int $ttl): int {
    return time() + $ttl;
  }

  /**
   * Get configured cache TTL from settings.
   *
   * @param string $cache_type
   *   The type of cache (entities, fields, etc.).
   *
   * @return int
   *   The TTL in seconds.
   */
  protected function getConfiguredTtl(string $cache_type): int {
    $config = $this->configFactory->get('dataverse_webform.settings');
    
    $ttl_map = [
      'entities' => $config->get('cache_entities_ttl') ?? self::LONG_TTL,
      'fields' => $config->get('cache_fields_ttl') ?? self::DEFAULT_TTL,
      'token_validation' => $config->get('cache_token_validation_ttl') ?? self::SHORT_TTL,
      'config_validation' => $config->get('cache_config_validation_ttl') ?? self::SHORT_TTL,
    ];
    
    return $ttl_map[$cache_type] ?? self::DEFAULT_TTL;
  }

}