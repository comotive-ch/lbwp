<?php

namespace LBWP\Helper\LLM;

/**
 * Common contract of embedding services used to fill and query chroma collections.
 * Vectors of different embedders are not compatible, so every embedder needs its own collection.
 * @package LBWP\Helper\LLM
 * @author Michael Sebel <michael@comotive.ch>
 */
interface Embedder
{
  /**
   * Checks if the embedding service responds, result is cached for a few minutes
   * @return bool true if the service is usable
   */
  public function isWorking(): bool;

  /**
   * Generates the embedding of a single text
   * @param string $text the text to embed
   * @param bool $queryMode true for search queries, false for documents
   * @return array the embedding vector
   */
  public function embed(string $text, bool $queryMode = false): array;

  /**
   * Generates the embeddings of multiple document texts in as few requests as possible
   * @param array $texts the texts to embed
   * @return array embedding vectors in the same order as the texts
   */
  public function embedMultiple(array $texts): array;

  /**
   * Returns the suffix appended to chroma collection names, so each embedder writes to its own collection
   * @return string the suffix, empty for the legacy default embedder
   */
  public function getCollectionSuffix(): string;
}
