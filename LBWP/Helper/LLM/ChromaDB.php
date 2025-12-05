<?php

namespace LBWP\Helper\LLM;

use LBWP\Util\WordPress;

/**
 * ChromaDB client for product synchronization
 * Handles embedding generation and vector storage
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comoive.ch>
 */
class ChromaDB
{
  private string $baseUrl;
  private string $authToken;
  private string $tenant;
  private string $database;
  private string $collectionName;
  private string $collectionId;
  private string $prefix = '';

  public function __construct(string $baseUrl = '', string $authToken = null, string $collectionName = 'default', string $tenant = 't_default', string $database = 'd_default') {
    $this->prefix = CUSTOMER_KEY . '_' . WordPress::getDb()->prefix;
    $this->baseUrl = rtrim($baseUrl, '/');
    $this->authToken = $authToken;
    $this->tenant = $tenant;
    $this->database = $database;
    $this->collectionName = $this->prefix . $collectionName;
    $this->collectionId = '';
  }

  /**
   * @return \LBWP\Helper\LLM\ChromaDB
   */
  public static function getInstance()
  {
    return new ChromaDB(LBWP_CHROMADB_ENDPOINT_URL, LBWP_CHROMADB_AUTH_TOKEN);
  }

  /**
   * @param string $collectionName
   * @param boolean $init
   * @return void
   */
  public function setCollectionName(string $collectionName, bool $init)
  {
    $this->collectionName = $this->prefix . $collectionName;
    $this->collectionId = ''; // Reset collection ID when changing name
    if ($init) {
      $this->initCollection();
    }
  }

  /**
   * @return string
   */
  public function getCollectionId(): string
  {
    return $this->collectionId;
  }

  /**
   * @return string
   */
  public function getCollectionName(): string
  {
    return $this->collectionName;
  }

  /**
   * Initialize collection (run once during setup)
   * Caches collection name->ID mapping for 1 hour
   */
  public function initCollection(): void
  {
    $cacheKey = 'chromadb_collections_' . md5($this->tenant . '_' . $this->database . '_' . $this->baseUrl);
    $cachedCollections = wp_cache_get($cacheKey);

    // Try to get collection ID from cache first
    if ($cachedCollections !== false && isset($cachedCollections[$this->collectionName])) {
      $this->collectionId = $cachedCollections[$this->collectionName];
      return;
    }

    // Fetch all collections from API
    $collections = $this->request('GET', "/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections");
    
    // Build cache mapping and check if our collection exists
    $collectionMap = [];
    $collectionExists = false;
    foreach ($collections as $collection) {
      $collectionMap[$collection['name']] = $collection['id'];
      if ($collection['name'] === $this->collectionName) {
        $this->collectionId = $collection['id'];
        $collectionExists = true;
      }
    }

    // Create collection if it doesn't exist
    if (!$collectionExists) {
      $response = $this->request('POST', "/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections", [
        'name' => $this->collectionName
      ]);
      $this->collectionId = $response['id'];
      $collectionMap[$this->collectionName] = $this->collectionId;
      
      // Invalidate cache when creating new collection
      wp_cache_delete($cacheKey);
    }

    // Cache the collection mapping for 1 hour
    wp_cache_set($cacheKey, $collectionMap, '', 3600);
  }

  /**
   * Ensure collection is initialized and ID is available
   */
  private function ensureCollectionId(): void
  {
    if (empty($this->collectionId)) {
      $this->initCollection();
    }
  }

  /**
   * Sync a single item to collection
   * @param array $item with id, text, meta
   */
  public function syncItem(array $item): void
  {
    $this->ensureCollectionId();
    
    $itemId = (string) $item['id'];
    // Make sure meta is an empty array if not given
    if (!is_array($item['meta'])) {
      $item['meta'] = [];
    }

    // Add to ChromaDB
    $this->request('POST', "/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections/{$this->collectionId}/add", [
      'ids' => [$itemId],
      'documents' => [$item['text']],
      'metadatas' => [$item['meta']],
      'embeddings' => [$item['embeddings']]
    ]);
  }

  /**
   * Sync multiple products in batch (more efficient)
   * @param array $batch Array of product arrays
   */
  public function syncBatch(array $batch): void
  {
    if (empty($batch)) {
      return;
    }

    $this->ensureCollectionId();

    $ids = [];
    $documents = [];
    $metadatas = [];
    $embeddings = [];

    foreach ($batch as $item) {
      $ids[] = (string) $item['id'];
      $documents[] = $item['text'];
      $embeddings[] = $item['embeddings'];
      $metadatas[] = is_array($item['meta']) ? $item['meta'] : [];
    }

    // Batch insert (max 1000 at once recommended)
    $chunks = array_chunk($ids, 1000);

    foreach ($chunks as $index => $chunkIds) {
      $chunkDocs = array_slice($documents, $index * 1000, 1000);
      $chunkEmbeddings = array_slice($embeddings, $index * 1000, 1000);
      $chunkMeta = array_slice($metadatas, $index * 1000, 1000);

      $this->request('POST', "/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections/{$this->collectionId}/upsert", [
        'ids' => $chunkIds,
        'documents' => $chunkDocs,
        'metadatas' => $chunkMeta,
        'embeddings' => $chunkEmbeddings
      ]);
    }
  }

  /**
   * Delete a product from ChromaDB
   */
  public function deleteItem(string $itemId): void
  {
    $this->ensureCollectionId();
    
    $this->request('POST', "/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections/{$this->collectionId}/delete", [
      'ids' => [$itemId]
    ]);
  }

  /**
   * Delete multiple items
   */
  public function deleteItems(array $itemIds): void
  {
    if (empty($itemIds)) {
      return;
    }

    $this->ensureCollectionId();

    $this->request('POST', "/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections/{$this->collectionId}/delete", [
      'ids' => array_map('strval', $itemIds)
    ]);
  }

  /**
   * Search/query the collection
   * @param array $embeddings embeddings to search for (created from the query by an embedder)
   * @param int $nResults Number of results to return (default: 10)
   * @param bool $returnFullResponse Return full response including metadata (default: false)
   * @param float $maxDistance Maximum distance threshold - results with higher distance will be filtered out (default: null = no filtering)
   * @return array Either array of IDs or full response with documents/metadata
   */
  public function searchCollection(array $embeddings, int $nResults = 10, bool $returnFullResponse = false, ?float $maxDistance = null): array
  {
    $this->ensureCollectionId();
    
    $response = $this->request('POST', "/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections/{$this->collectionId}/query", [
      'query_embeddings' => [$embeddings],
      'n_results' => $nResults
    ]);

    // Filter by distance if threshold is provided
    if ($maxDistance !== null && isset($response['distances'][0])) {
      $filteredResponse = [
        'ids' => [[]],
        'distances' => [[]],
        'documents' => [[]],
        'metadatas' => [[]]
      ];
      
      foreach ($response['distances'][0] as $index => $distance) {
        if ($distance <= $maxDistance) {
          $filteredResponse['ids'][0][] = $response['ids'][0][$index] ?? null;
          $filteredResponse['distances'][0][] = $distance;
          if (isset($response['documents'][0][$index])) {
            $filteredResponse['documents'][0][] = $response['documents'][0][$index];
          }
          if (isset($response['metadatas'][0][$index])) {
            $filteredResponse['metadatas'][0][] = $response['metadatas'][0][$index];
          }
        }
      }
      
      $response = $filteredResponse;
    }

    if ($returnFullResponse) {
      return $response;
    }

    // Return just the IDs
    return $response['ids'][0] ?? [];
  }

  /**
   * Check if ChromaDB is working by calling heartbeat endpoint
   * Uses WordPress cache for 5 minutes to avoid frequent calls
   * @return bool True if ChromaDB is responding, false otherwise
   */
  public function isWorking(): bool
  {
    $cacheKey = 'chromadb_heartbeat_' . md5($this->baseUrl);
    $cached = wp_cache_get($cacheKey);
    
    if ($cached !== false) {
      return $cached;
    }

    try {
      $this->request('GET', '/api/v2/heartbeat');
      $isWorking = true;
    } catch (\RuntimeException $e) {
      $isWorking = false;
    }

    wp_cache_set($cacheKey, $isWorking, '', 300); // Cache for 5 minutes (300 seconds)
    return $isWorking;
  }

  /**
   * Delete all records in the current collection
   * Note: This clears all documents but keeps the collection itself
   * @return void
   */
  public function deleteCollection(): void
  {
    $this->ensureCollectionId();

    // First, get all document IDs from the collection
    // Pass empty object {} instead of array [] - use stdClass to ensure it encodes as {}
    $response = $this->request('POST', "/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections/{$this->collectionId}/get", [
      'include' => []
    ]);
    // If there are IDs, delete them
    if (!empty($response['ids'])) {
      $this->deleteItems($response['ids']);
    }
  }

  /**
   * Create a tenant
   * @return void
   */
  public function createTenant(): void
  {
    $this->request('POST', '/api/v2/tenants', [
      'name' => $this->tenant
    ]);
  }

  /**
   * Create a database within the tenant
   * @return void
   */
  public function createDatabase(): void
  {
    $this->request('POST', "/api/v2/tenants/{$this->tenant}/databases", [
      'name' => $this->database
    ]);
  }

  /**
   * Set tenant name
   * @param string $tenant
   * @return void
   */
  public function setTenant(string $tenant): void
  {
    $this->tenant = $tenant;
  }

  /**
   * Set database name
   * @param string $database
   * @return void
   */
  public function setDatabase(string $database): void
  {
    $this->database = $database;
  }

  /**
   * Make HTTP request to ChromaDB
   */
  private function request(string $method, string $path, ?array $data = null): array
  {
    $url = $this->baseUrl . $path;

    $headers = [
      'Content-Type: application/json',
    ];

    if ($this->authToken !== null) {
      $headers[] = "X-Chroma-Token: {$this->authToken}";
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    // On local dev go trough our orpxy
    if (defined('LOCAL_DEVELOPMENT')) {
      curl_setopt($ch, CURLOPT_PROXY, 'http://194.182.165.126');
      curl_setopt($ch, CURLOPT_PROXYPORT, '3128');
      curl_setopt($ch, CURLOPT_PROXYUSERPWD, 'comotive:Kv8gnr9qd5erSquid');
    }

    if ($data !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
      throw new \RuntimeException("ChromaDB request failed: {$error}");
    }

    if ($httpCode >= 400) {
      throw new \RuntimeException("ChromaDB returned error {$httpCode}: {$response}");
    }

    return json_decode($response, true) ?? [];
  }
}