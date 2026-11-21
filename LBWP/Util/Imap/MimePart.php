<?php

namespace LBWP\Util\Imap;

/**
 * A parsed MIME entity: either a leaf part with content or a container (multipart, message/rfc822) with children
 * @package LBWP\Util\Imap
 */
class MimePart
{
  /**
   * @var array<string, string[]> header values by lowercase name
   */
  protected array $headers;
  /**
   * @var string lowercase content type, e.g. "text/plain"
   */
  protected string $contentType = 'text/plain';
  /**
   * @var array<string, string> content type parameters by lowercase name
   */
  protected array $contentTypeParameters = [];
  /**
   * @var string lowercase disposition ("inline", "attachment") or empty
   */
  protected string $disposition = '';
  /**
   * @var array<string, string> disposition parameters by lowercase name
   */
  protected array $dispositionParameters = [];
  /**
   * @var string the still transfer-encoded body
   */
  protected string $body;
  /**
   * @var MimePart[] child parts of a multipart or the encapsulated message of message/rfc822
   */
  protected array $children = [];

  /**
   * @param string $raw the raw entity including its headers
   */
  public function __construct(string $raw)
  {
    [$headerBlock, $this->body] = Mime::splitHeaderAndBody($raw);
    $this->headers = Mime::parseHeaders($headerBlock);
    $this->parseContentHeaders();
    $this->parseChildren();
  }

  /**
   * @param string $name case-insensitive header name
   * @return string|null the first raw (undecoded) value of the header, null if missing
   */
  public function getHeader(string $name): ?string
  {
    return $this->headers[strtolower($name)][0] ?? null;
  }

  /**
   * @param string $name case-insensitive header name
   * @return string[] all raw values of the header
   */
  public function getHeaders(string $name): array
  {
    return $this->headers[strtolower($name)] ?? [];
  }

  /**
   * @return string lowercase content type, e.g. "text/html"
   */
  public function getContentType(): string
  {
    return $this->contentType;
  }

  /**
   * @param string $type lowercase type to compare with, e.g. "text/plain" or just "text"
   * @return bool true if the content type (or its primary type) matches
   */
  public function isType(string $type): bool
  {
    return str_contains($type, '/') ? $this->contentType === $type : str_starts_with($this->contentType, $type . '/');
  }

  /**
   * @param string $name case-insensitive content type parameter, e.g. "charset"
   * @return string|null the decoded parameter value
   */
  public function getParameter(string $name): ?string
  {
    return $this->contentTypeParameters[strtolower($name)] ?? null;
  }

  /**
   * @return string the declared charset, defaulting to "us-ascii" as of RFC 2045
   */
  public function getCharset(): string
  {
    return $this->getParameter('charset') ?? 'us-ascii';
  }

  /**
   * @return string lowercase Content-Transfer-Encoding, defaulting to "7bit"
   */
  public function getTransferEncoding(): string
  {
    return strtolower(trim($this->getHeader('content-transfer-encoding') ?? '7bit'));
  }

  /**
   * @return string lowercase disposition ("inline", "attachment") or empty if not declared
   */
  public function getDisposition(): string
  {
    return $this->disposition;
  }

  /**
   * @return string the declared file name (disposition "filename" before content type "name"), empty if none
   */
  public function getFilename(): string
  {
    return $this->dispositionParameters['filename'] ?? $this->contentTypeParameters['name'] ?? '';
  }

  /**
   * @return bool true if this part has child parts
   */
  public function hasChildren(): bool
  {
    return count($this->children) > 0;
  }

  /**
   * @return MimePart[] direct children (sub parts of a multipart, or the encapsulated message)
   */
  public function getChildren(): array
  {
    return $this->children;
  }

  /**
   * @return MimePart[] all leaf parts below (and including) this part, depth-first in document order
   */
  public function getLeafParts(): array
  {
    if (!$this->hasChildren()) {
      return [$this];
    }

    $leaves = [];
    foreach ($this->children as $child) {
      array_push($leaves, ...$child->getLeafParts());
    }
    return $leaves;
  }

  /**
   * Leaf parts count as attachment if they declare a file name or disposition "attachment",
   * or if they are neither text nor container (e.g. unnamed inline images)
   * @return bool true if this part is an attachment
   */
  public function isAttachment(): bool
  {
    if ($this->hasChildren()) {
      return false;
    }

    return $this->getFilename() !== '' || $this->disposition === 'attachment' || !$this->isType('text');
  }

  /**
   * @return string the body with its transfer encoding decoded (raw bytes for binaries)
   */
  public function getContent(): string
  {
    return Mime::decodeTransferEncoding($this->body, $this->getTransferEncoding());
  }

  /**
   * @return string the decoded body converted from its charset to UTF-8
   */
  public function getText(): string
  {
    return Mime::toUtf8($this->getContent(), $this->getCharset());
  }

  /**
   * Parses Content-Type and Content-Disposition into their values and parameters
   * @return void
   */
  protected function parseContentHeaders(): void
  {
    $contentType = $this->getHeader('content-type');
    if ($contentType !== null) {
      [$type, $this->contentTypeParameters] = Mime::parseValueWithParameters($contentType);
      if (str_contains($type, '/')) {
        $this->contentType = $type;
      }
    }

    $disposition = $this->getHeader('content-disposition');
    if ($disposition !== null) {
      [$this->disposition, $this->dispositionParameters] = Mime::parseValueWithParameters($disposition);
    }
  }

  /**
   * Creates the child parts for multipart bodies and encapsulated messages
   * @return void
   */
  protected function parseChildren(): void
  {
    if ($this->isType('multipart')) {
      $this->children = $this->parseMultipartBody();
      return;
    }

    if ($this->contentType === 'message/rfc822' && $this->disposition !== 'attachment') {
      $this->children = [new MimePart($this->getContent())];
    }
  }

  /**
   * Splits a multipart body at its boundary delimiter lines
   * A missing closing delimiter (truncated mail) ends the last part at the end of the body.
   * @return MimePart[] the parsed sub parts, preamble and epilogue are dropped
   */
  protected function parseMultipartBody(): array
  {
    $boundary = $this->getParameter('boundary') ?? '';
    if ($boundary === '') {
      return [];
    }

    $pattern = '/(?:^|\r?\n)--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?:\r?\n|$)/';
    if (!preg_match_all($pattern, $this->body, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
      return [];
    }

    $parts = [];
    foreach ($matches as $index => $match) {
      if (!empty($match[1][0])) {
        break;
      }
      $start = $match[0][1] + strlen($match[0][0]);
      $end = $matches[$index + 1][0][1] ?? strlen($this->body);
      $parts[] = new MimePart(substr($this->body, $start, $end - $start));
    }

    return $parts;
  }
}
