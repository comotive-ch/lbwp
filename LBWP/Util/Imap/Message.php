<?php

namespace LBWP\Util\Imap;

/**
 * A complete message fetched from an IMAP mailbox, with convenience accessors for the common headers
 * @package LBWP\Util\Imap
 */
class Message extends MimePart
{
  /**
   * @param string $raw the full RFC 5322 message
   * @param int $sequenceNumber the message sequence number within the selected mailbox
   * @param int $uid the IMAP UID of the message
   * @param string[] $flags the IMAP flags, e.g. "\Seen"
   */
  public function __construct(
    protected string $raw,
    protected int $sequenceNumber = 0,
    protected int $uid = 0,
    protected array $flags = []
  ) {
    parent::__construct($raw);
  }

  /**
   * @return string the full raw message
   */
  public function getRaw(): string
  {
    return $this->raw;
  }

  /**
   * @return int the message sequence number within the selected mailbox
   */
  public function getSequenceNumber(): int
  {
    return $this->sequenceNumber;
  }

  /**
   * @return int the IMAP UID of the message
   */
  public function getUid(): int
  {
    return $this->uid;
  }

  /**
   * @return string[] the IMAP flags, e.g. "\Seen", "\Deleted"
   */
  public function getFlags(): array
  {
    return $this->flags;
  }

  /**
   * @param string $flag case-insensitive flag, e.g. "\Seen"
   * @return bool true if the message has the flag
   */
  public function hasFlag(string $flag): bool
  {
    return in_array(strtolower($flag), array_map('strtolower', $this->flags), true);
  }

  /**
   * @return bool true if the message is already marked for deletion
   */
  public function isDeleted(): bool
  {
    return $this->hasFlag('\Deleted');
  }

  /**
   * @return string the decoded UTF-8 subject, empty if missing
   */
  public function getSubject(): string
  {
    return Mime::decodeHeader($this->getHeader('subject'));
  }

  /**
   * @param string $header case-insensitive address header, e.g. "to", "cc", "reply-to", "envelope-to"
   * @return Address[] all addresses of all occurrences of the header
   */
  public function getAddresses(string $header): array
  {
    $addresses = [];
    foreach ($this->getHeaders($header) as $value) {
      array_push($addresses, ...Mime::parseAddressList($value));
    }
    return $addresses;
  }

  /**
   * @return Address|null the first sender address
   */
  public function getFrom(): ?Address
  {
    return $this->getAddresses('from')[0] ?? null;
  }

  /**
   * @return string the Message-ID header including angle brackets, empty if missing
   */
  public function getMessageId(): string
  {
    return trim($this->getHeader('message-id') ?? '');
  }

  /**
   * The weekday and trailing zone comments are stripped, as strtotime() treats a weekday that
   * doesn't match the date as "next <weekday>"
   * @return int|null unix timestamp of the Date header, null if missing or unparsable
   */
  public function getTimestamp(): ?int
  {
    $date = preg_replace(['/^\s*[a-z]+,\s*/i', '/\s*\([^)]*\)\s*$/'], '', $this->getHeader('date') ?? '');
    if ($date === '') {
      return null;
    }

    $timestamp = strtotime($date);
    return $timestamp !== false ? $timestamp : null;
  }

  /**
   * Finds the part holding the readable body, skipping attachments
   * @param bool $preferPlain prefer text/plain over text/html when both exist
   * @return MimePart|null the body part, null if the message has no readable text
   */
  public function findBodyPart(bool $preferPlain = true): ?MimePart
  {
    $plain = $this->findTextLeaf('text/plain');
    $html = $this->findTextLeaf('text/html');

    return $preferPlain ? ($plain ?? $html) : ($html ?? $plain);
  }

  /**
   * @return MimePart[] all attachment leaf parts in document order
   */
  public function getAttachments(): array
  {
    return array_values(array_filter($this->getLeafParts(), fn(MimePart $part) => $part->isAttachment()));
  }

  /**
   * @param string $contentType lowercase text content type, e.g. "text/plain"
   * @return MimePart|null the first non-attachment leaf with that content type
   */
  protected function findTextLeaf(string $contentType): ?MimePart
  {
    foreach ($this->getLeafParts() as $part) {
      if ($part->getContentType() === $contentType && !$part->isAttachment()) {
        return $part;
      }
    }
    return null;
  }
}
