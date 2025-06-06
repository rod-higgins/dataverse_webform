<?php

namespace Drupal\dataverse_webform\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for managing Dataverse-related caching.
 */
class DataverseCacheManager {

  public const CACHE_BIN = 'dataverse_webform';
  public const DEFAULT_TTL = 3600; // 1 hour
  public const SHORT_TTL = 300;    // 5 minutes
  public const LONG_TTL = 14400;   // 4 hours
  public const MAX_CACHE_ENTRIES = 1000;
  public const CACHE_KEY_MAX_LENGTH = 255;

  protected CacheBackendInterface $cache;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;

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
   * Get cached entities.
   */
  public function getCachedEntities(array $config): ?array {
    return $this->getCachedData($this->buildEntitiesCacheKey($config));
  }

  /**
   * Set cached entities.
   */
  public function setCachedEntities(array $config, array $entities, ?int $ttl = null): void {
    $this->setCachedData(
      $this->buildEntitiesCacheKey($config),
      $entities,
      $this->buildEntitiesCacheTags($config),
      $ttl ?? self::LONG_TTL
    );
  }

  /**
   * Get cached entity fields.
   */
  public function getCachedEntityFields(array $config, string $entity_name): ?array {
    return $this->getCachedData($this->buildFieldsCacheKey($config, $entity_name));
  }

  /**
   * Set cached entity fields.
   */
  public function setCachedEntityFields(array $config, string $entity_name, array $fields, ?int $ttl = null): void {
    $this->setCachedData(
      $this->buildFieldsCacheKey($config, $entity_name),
      $fields,
      $this->buildFieldsCacheTags($config, $entity_name),
      $ttl ?? self::DEFAULT_TTL
    );
  }

  /**
   * Get cached token validation.
   */
  public function getCachedTokenValidation(array $config): ?bool {
    return $this->getCachedData($this->buildTokenValidationCacheKey($config));
  }

  /**
   * Set cached token validation.
   */
  public function setCachedTokenValidation(array $config, bool $is_valid): void {
    $this->setCachedData(
      $this->buildTokenValidationCacheKey($config),
      $is_valid,
      $this->buildTokenValidationCacheTags($config),
      self::SHORT_TTL
    );
  }

  /**
   * Get cached configuration validation.
   */
  public function getCachedConfigValidation(array $config): ?array {
    return $this->getCachedData($this->buildConfigValidationCacheKey($config));
  }

  /**
   * Set cached configuration validation.
   */
  public function setCachedConfigValidation(array $config, array $validation_results): void {
    $this->setCachedData(
      $this->buildConfigValidationCacheKey($config),
      $validation_results,
      $this->buildConfigValidationCacheTags($config),
      self::SHORT_TTL
    );
  }

  /**
   * Invalidate configuration cache.
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
   * Invalidate entity cache.
   */
  public function invalidateEntityCache(array $config, string $entity_name): void {
    $tags = [
      'dataverse_webform:fields:' . $entity_name,
      'dataverse_webform:config:' . $this->generateConfigHash(['url' => $config['dataverse_url'] ?? '']),
    ];
    
    Cache::invalidateTags($tags);
    
    $this->loggerFactory->get('dataverse_webform')->info(
      'Invalidated cache for entity: @entity',
      ['@entity' => $entity_name]
    );
  }

  /**
   * Invalidate all caches.
   */
  public function invalidateAllCaches(): void {
    Cache::invalidateTags(['dataverse_webform']);
    
    $this->loggerFactory->get('dataverse_webform')->info('Invalidated all Dataverse caches');
  }

  /**
   * Clear expired entries.
   */
  public function clearExpiredEntries(): void {
    $this->cache->garbageCollection();
  }

  /**
   * Get cache statistics.
   */
  public function getCacheStatistics(): array {
    return [
      'cache_bin' => self::CACHE_BIN,
      'default_ttl' => self::DEFAULT_TTL,
      'timestamp' => time(),
      'cache_hits' => $this->getCacheHitCount(),
      'cache_misses' => $this->getCacheMissCount(),
    ];
  }

  /**
   * Warmup cache with commonly accessed data.
   */
  public function warmupCache(array $config): void {
    try {
      $this->loggerFactory->get('dataverse_webform')->info(
        'Cache warmup initiated for configuration'
      );
      
      // Implementation would depend on specific caching strategy.
    } catch (\Exception $e) {
      $this->loggerFactory->get('dataverse_webform')->error(
        'Cache warmup failed: @error',
        ['@error' => $e->getMessage()]
      );
    }
  }

  /**
   * Get cached data with safety checks.
   */
  protected function getCachedData(string $cache_key) {
    if (strlen($cache_key) > self::CACHE_KEY_MAX_LENGTH) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Cache key exceeds maximum length: @key',
        ['@key' => substr($cache_key, 0, 50) . '...']
      );
      return null;
    }

    $cached = $this->cache->get($cache_key);
    
    if ($cached) {
      $this->incrementCacheHitCount();
      return $cached->data;
    }
    
    $this->incrementCacheMissCount();
    return null;
  }

  /**
   * Set cached data with safety checks.
   */
  protected function setCachedData(string $cache_key, $data, array $cache_tags, int $ttl): void {
    if (strlen($cache_key) > self::CACHE_KEY_MAX_LENGTH) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Cannot cache data - key exceeds maximum length: @key',
        ['@key' => substr($cache_key, 0, 50) . '...']
      );
      return;
    }

    if (is_array($data) && count($data) > self::MAX_CACHE_ENTRIES) {
      $this->loggerFactory->get('dataverse_webform')->warning(
        'Large cache entry (@count items) may impact performance',
        ['@count' => count($data)]
      );
    }
    
    $expiration = time() + $ttl;
    $this->cache->set($cache_key, $data, $expiration, $cache_tags);
  }

  /**
   * Build entities cache key.
   */
  protected function buildEntitiesCacheKey(array $config): string {
    $cache_config = $this->extractCacheableConfig($config);
    return 'dataverse_webform:entities:' . $this->generateConfigHash($cache_config);
  }

  /**
   * Build fields cache key.
   */
  protected function buildFieldsCacheKey(array $config, string $entity_name): string {
    $cache_config = $this->extractCacheableConfig($config);
    return 'dataverse_webform:fields:' . $entity_name . ':' . $this->generateConfigHash($cache_config);
  }

  /**
   * Build token validation cache key.
   */
  protected function buildTokenValidationCacheKey(array $config): string {
    $auth_config = $this->extractAuthConfig($config);
    return 'dataverse_webform:token_validation:' . $this->generateConfigHash($auth_config);
  }

  /**
   * Build configuration validation cache key.
   */
  protected function buildConfigValidationCacheKey(array $config): string {
    $validation_config = $this->extractValidationConfig($config);
    return 'dataverse_webform:config_validation:' . $this->generateConfigHash($validation_config);
  }

  /**
   * Build entities cache tags.
   */
  protected function buildEntitiesCacheTags(array $config): array {
    return array_merge(
      $this->buildBaseCacheTags($config),
      ['dataverse_webform:entities', 'dataverse_webform:metadata']
    );
  }

  /**
   * Build fields cache tags.
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
   * Build token validation cache tags.
   */
  protected function buildTokenValidationCacheTags(array $config): array {
    return array_merge($this->buildBaseCacheTags($config), ['dataverse_webform:token_validation']);
  }

  /**
   * Build configuration validation cache tags.
   */
  protected function buildConfigValidationCacheTags(array $config): array {
    return array_merge($this->buildBaseCacheTags($config), ['dataverse_webform:config_validation']);
  }

  /**
   * Build base cache tags.
   */
  protected function buildBaseCacheTags(array $config): array {
    return [
      'dataverse_webform',
      'dataverse_webform:config:' . $this->generateConfigHash(['url' => $config['dataverse_url'] ?? '']),
    ];
  }

  /**
   * Extract cacheable configuration.
   */
  protected function extractCacheableConfig(array $config): array {
    return array_intersect_key($config, array_flip(['dataverse_url', 'azure_tenant_id']));
  }

  /**
   * Extract authentication configuration.
   */
  protected function extractAuthConfig(array $config): array {
    return array_intersect_key($config, array_flip([
      'dataverse_url', 'azure_tenant_id', 'azure_client_id_key', 'azure_client_secret_key'
    ]));
  }

  /**
   * Extract validation configuration.
   */
  protected function extractValidationConfig(array $config): array {
    return array_intersect_key($config, array_flip([
      'dataverse_url', 'azure_tenant_id', 'azure_client_id_key', 'azure_client_secret_key',
      'field_mappings', 'timeout', 'batch_size'
    ]));
  }

  /**
   * Generate configuration hash.
   */
  protected function generateConfigHash(array $config): string {
    ksort($config); // Ensure consistent ordering for hashing.
    return hash('sha256', serialize($config));
  }

  /**
   * Get cache hit count.
   */
  protected function getCacheHitCount(): int {
    return \Drupal::state()->get('dataverse_webform:cache_hits', 0);
  }

  /**
   * Get cache miss count.
   */
  protected function getCacheMissCount(): int {
    return \Drupal::state()->get('dataverse_webform:cache_misses', 0);
  }

  /**
   * Increment cache hit count.
   */
  protected function incrementCacheHitCount(): void {
    $count = $this->getCacheHitCount() + 1;
    \Drupal::state()->set('dataverse_webform:cache_hits', $count);
  }

  /**
   * Increment cache miss count.
   */
  protected function incrementCacheMissCount(): void {
    $count = $this->getCacheMissCount() + 1;
    \Drupal::state()->set('dataverse_webform:cache_misses', $count);
  }

}