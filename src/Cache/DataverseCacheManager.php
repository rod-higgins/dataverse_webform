<?php

namespace Drupal\dataverse_webform;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Cache;

/**
 * Service for managing Dataverse-related caching.
 */
class DataverseCacheManager {

  /**
   * The cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Cache bin for Dataverse metadata.
   */
  public const CACHE_BIN = 'dataverse_webform';

  /**
   * Default cache TTL (1 hour).
   */
  public const DEFAULT_TTL = 3600;

  /**
   * Constructs a DataverseCacheManager object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(CacheBackendInterface $cache) {
    $this->cache = $cache;
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
    
    return $cached ? $cached->data : NULL;
  }

  /**
   * Set cached entities for a Dataverse configuration.
   *
   * @param array $config
   *   The Dataverse configuration.
   * @param array $entities
   *   The entities to cache.
   */
  public function setCachedEntities(array $config, array $entities): void {
    $cache_key = $this->buildEntitiesCacheKey($config);
    $cache_tags = $this->buildEntitiesCacheTags($config);
    
    $this->cache->set(
      $cache_key,
      $entities,
      $this->getDefaultExpiration(),
      $cache_tags
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
    
    return $cached ? $cached->data : NULL;
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
   */
  public function setCachedEntityFields(array $config, string $entity_name, array $fields): void {
    $cache_key = $this->buildFieldsCacheKey($config, $entity_name);
    $cache_tags = $this->buildFieldsCacheTags($config, $entity_name);
    
    $this->cache->set(
      $cache_key,
      $fields,
      $this->getDefaultExpiration(),
      $cache_tags
    );
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
  }

  /**
   * Invalidate all Dataverse caches.
   */
  public function invalidateAllCaches(): void {
    Cache::invalidateTags(['dataverse_webform']);
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
    return 'dataverse_webform:entities:' . hash('sha256', serialize($cache_config));
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
    return 'dataverse_webform:fields:' . $entity_name . ':' . hash('sha256', serialize($cache_config));
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
      ['dataverse_webform:entities']
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
      ]
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
      'dataverse_webform:config:' . hash('sha256', $config['dataverse_url'] ?? ''),
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
   * Get default cache expiration time.
   *
   * @return int
   *   The expiration timestamp.
   */
  protected function getDefaultExpiration(): int {
    return time() + self::DEFAULT_TTL;
  }

}