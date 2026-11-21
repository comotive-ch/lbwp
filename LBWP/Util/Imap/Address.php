<?php

namespace LBWP\Util\Imap;

/**
 * A single e-mail address with an optional display name
 * @package LBWP\Util\Imap
 */
final readonly class Address
{
  /**
   * @param string $email the bare address, e.g. "info@example.com"
   * @param string $name the decoded display name, empty if none
   */
  public function __construct(
    public string $email,
    public string $name = ''
  ) {
  }

  /**
   * @return string the local part of the address (before the last "@")
   */
  public function getMailbox(): string
  {
    $position = strrpos($this->email, '@');
    return $position === false ? $this->email : substr($this->email, 0, $position);
  }

  /**
   * @return string the domain part of the address (after the last "@")
   */
  public function getHost(): string
  {
    $position = strrpos($this->email, '@');
    return $position === false ? '' : substr($this->email, $position + 1);
  }

  /**
   * @return string formatted as "Name <email>" or just the email if there is no name
   */
  public function toString(): string
  {
    return $this->name !== '' ? $this->name . ' <' . $this->email . '>' : $this->email;
  }
}
