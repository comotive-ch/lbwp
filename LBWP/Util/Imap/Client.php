<?php

namespace LBWP\Util\Imap;

/**
 * Minimal IMAP4rev1 client over a plain PHP socket, replacing the unbundled ext-imap.
 * Covers what LBWP needs: login, select, fetch full messages, flag deleted, copy/move, append, expunge.
 * Moving is done as COPY + \Deleted (like imap_mail_move), so sequence numbers stay stable until expunge().
 * @package LBWP\Util\Imap
 */
class Client
{
  /**
   * Socket read/write timeout in seconds
   */
  const int DEFAULT_TIMEOUT = 30;
  /**
   * Encryption modes: implicit TLS, STARTTLS upgrade, or none
   */
  const string ENCRYPTION_SSL = 'ssl';
  const string ENCRYPTION_STARTTLS = 'tls';
  const string ENCRYPTION_NONE = 'none';

  /**
   * @var resource|null the socket stream
   */
  protected $stream = null;
  /**
   * @var int counter for command tags
   */
  protected int $tagCounter = 0;
  /**
   * @var int number of messages in the selected mailbox
   */
  protected int $exists = 0;
  /**
   * @var bool true if the selected mailbox was opened read-only (EXAMINE)
   */
  protected bool $readOnly = false;

  /**
   * @param string $host the IMAP server host name
   * @param int $port the port, usually 993 (ssl) or 143 (tls/none)
   * @param string $encryption one of the ENCRYPTION_* constants
   * @param bool $validateCertificate false to accept self-signed certificates (like /novalidate-cert)
   * @param int $timeout socket timeout in seconds
   */
  public function __construct(
    protected string $host,
    protected int $port = 993,
    protected string $encryption = self::ENCRYPTION_SSL,
    protected bool $validateCertificate = true,
    protected int $timeout = self::DEFAULT_TIMEOUT
  ) {
  }

  /**
   * Drop-in for imap_open(): connects, logs in and selects the mailbox of a c-client string
   * @param string $mailbox c-client mailbox string, e.g. "{imap.example.com:993/imap/ssl}INBOX"
   * @param string $user the login user
   * @param string $password the login password
   * @param bool $readOnly open the mailbox read-only (like OP_READONLY)
   * @return static the connected client with the mailbox selected
   * @throws ImapException on any connection, login or select error
   */
  public static function open(string $mailbox, string $user, string $password, bool $readOnly = false): static
  {
    $config = self::parseMailboxString($mailbox);
    $client = new static($config['host'], $config['port'], $config['encryption'], $config['validateCertificate']);
    $client->connect();
    $client->login($user, $password);
    $client->select($config['folder'], $readOnly || $config['readOnly']);
    return $client;
  }

  /**
   * Parses a c-client mailbox string like "{host:port/imap/ssl/novalidate-cert}Folder"
   * @param string $mailbox the mailbox string
   * @return array{host: string, port: int, encryption: string, validateCertificate: bool, readOnly: bool, folder: string}
   * @throws ImapException if the string has no {host} part
   */
  public static function parseMailboxString(string $mailbox): array
  {
    if (!preg_match('/^\{([^}:\/]+)(?::(\d+))?([^}]*)\}(.*)$/s', $mailbox, $match)) {
      throw new ImapException('Invalid mailbox string, expected "{host:port/flags}folder"');
    }

    $flags = array_filter(explode('/', strtolower($match[3])));
    $encryption = match (true) {
      in_array('ssl', $flags, true) => self::ENCRYPTION_SSL,
      in_array('tls', $flags, true) => self::ENCRYPTION_STARTTLS,
      default => self::ENCRYPTION_NONE,
    };

    return [
      'host' => $match[1],
      'port' => $match[2] !== '' ? intval($match[2]) : ($encryption === self::ENCRYPTION_SSL ? 993 : 143),
      'encryption' => $encryption,
      'validateCertificate' => !in_array('novalidate-cert', $flags, true),
      'readOnly' => in_array('readonly', $flags, true),
      'folder' => $match[4] !== '' ? $match[4] : 'INBOX',
    ];
  }

  /**
   * Opens the socket, reads the greeting and upgrades to TLS if configured
   * @return void
   * @throws ImapException if the server can't be reached or rejects the connection
   */
  public function connect(): void
  {
    $scheme = $this->encryption === self::ENCRYPTION_SSL ? 'ssl' : 'tcp';
    $context = stream_context_create(['ssl' => [
      'peer_name' => $this->host,
      'verify_peer' => $this->validateCertificate,
      'verify_peer_name' => $this->validateCertificate,
      'allow_self_signed' => !$this->validateCertificate,
    ]]);

    $stream = @stream_socket_client($scheme . '://' . $this->host . ':' . $this->port, $errorCode, $errorMessage, $this->timeout, STREAM_CLIENT_CONNECT, $context);
    if ($stream === false) {
      throw new ImapException('Could not connect to ' . $this->host . ':' . $this->port . ': ' . $errorMessage . ' (' . $errorCode . ')');
    }

    $this->stream = $stream;
    stream_set_timeout($this->stream, $this->timeout);
    $this->readGreeting();

    if ($this->encryption === self::ENCRYPTION_STARTTLS) {
      $this->startTls();
    }
  }

  /**
   * @param string $user the login user
   * @param string $password the login password
   * @return void
   * @throws ImapException if the credentials are rejected
   */
  public function login(string $user, string $password): void
  {
    $this->command(['LOGIN', [$user], [$password]]);
  }

  /**
   * Selects a mailbox, resetting the message count
   * @param string $folder the UTF-8 mailbox name, e.g. "INBOX" or "INBOX.Archiv"
   * @param bool $readOnly use EXAMINE so nothing (not even \Seen) can be changed
   * @return int number of messages in the mailbox
   * @throws ImapException if the mailbox doesn't exist
   */
  public function select(string $folder, bool $readOnly = false): int
  {
    $this->exists = 0;
    $this->readOnly = $readOnly;
    $this->command([$readOnly ? 'EXAMINE' : 'SELECT', [$this->encodeFolder($folder)]]);
    return $this->exists;
  }

  /**
   * Drop-in for imap_num_msg()
   * @return int number of messages in the selected mailbox
   */
  public function count(): int
  {
    return $this->exists;
  }

  /**
   * Fetches and parses a complete message
   * @param int $sequenceNumber 1-based message sequence number
   * @param bool $markSeen set the \Seen flag (false fetches with BODY.PEEK, like FT_PEEK)
   * @return Message the parsed message
   * @throws ImapException if the message doesn't exist
   */
  public function fetchMessage(int $sequenceNumber, bool $markSeen = false): Message
  {
    $section = $markSeen ? 'BODY[]' : 'BODY.PEEK[]';
    $responses = $this->command(['FETCH', (string) $sequenceNumber, '(UID FLAGS ' . $section . ')']);

    $uid = 0;
    $flags = [];
    $raw = null;
    foreach ($responses as $response) {
      if (!preg_match('/^\* ' . $sequenceNumber . ' FETCH /', $response['line'])) {
        continue;
      }
      $uid = preg_match('/\bUID (\d+)/', $response['line'], $match) ? intval($match[1]) : $uid;
      $flags = preg_match('/\bFLAGS \(([^)]*)\)/', $response['line'], $match) ? array_filter(explode(' ', $match[1])) : $flags;
      $raw ??= $this->extractBody($response);
    }

    if ($raw === null) {
      throw new ImapException('Message ' . $sequenceNumber . ' not found');
    }

    return new Message($raw, $sequenceNumber, $uid, array_values($flags));
  }

  /**
   * Drop-in for imap_delete(): marks a message as deleted, removed on expunge()
   * @param int $sequenceNumber 1-based message sequence number
   * @return void
   */
  public function delete(int $sequenceNumber): void
  {
    $this->command(['STORE', (string) $sequenceNumber, '+FLAGS.SILENT (\Deleted)']);
  }

  /**
   * Copies a message into another mailbox
   * @param int $sequenceNumber 1-based message sequence number
   * @param string $folder the UTF-8 target mailbox name
   * @return void
   */
  public function copy(int $sequenceNumber, string $folder): void
  {
    $this->command(['COPY', (string) $sequenceNumber, [$this->encodeFolder($folder)]]);
  }

  /**
   * Drop-in for imap_mail_move(): copies the message and marks the original as deleted
   * @param int $sequenceNumber 1-based message sequence number
   * @param string $folder the UTF-8 target mailbox name
   * @return void
   */
  public function move(int $sequenceNumber, string $folder): void
  {
    $this->copy($sequenceNumber, $folder);
    $this->delete($sequenceNumber);
  }

  /**
   * Drop-in for imap_append(): stores a raw message in a mailbox
   * @param string $folder the UTF-8 target mailbox name
   * @param string $message the full RFC 5322 message, line endings are normalized to CRLF
   * @param string[] $flags flags to set, e.g. ["\Draft"]
   * @return void
   */
  public function append(string $folder, string $message, array $flags = []): void
  {
    $tokens = ['APPEND', [$this->encodeFolder($folder)]];
    if (count($flags) > 0) {
      $tokens[] = '(' . implode(' ', $flags) . ')';
    }
    $tokens[] = [preg_replace('/\r?\n/', "\r\n", $message), true];
    $this->command($tokens);
  }

  /**
   * Drop-in for imap_expunge(): permanently removes messages marked as deleted
   * @return void
   */
  public function expunge(): void
  {
    if (!$this->readOnly) {
      $this->command(['EXPUNGE']);
    }
  }

  /**
   * Drop-in for imap_close(): logs out and closes the socket, safe to call multiple times
   * @return void
   */
  public function close(): void
  {
    if ($this->stream === null) {
      return;
    }

    try {
      $this->command(['LOGOUT']);
    } catch (ImapException) {
    }

    fclose($this->stream);
    $this->stream = null;
  }

  /**
   * Makes sure the connection is closed cleanly
   */
  public function __destruct()
  {
    $this->close();
  }

  /**
   * Reads the server greeting
   * @return void
   * @throws ImapException if the server refuses the connection
   */
  protected function readGreeting(): void
  {
    $greeting = $this->readResponseLine()['line'];
    if (!preg_match('/^\* (OK|PREAUTH)\b/i', $greeting)) {
      throw new ImapException('Unexpected server greeting: ' . $greeting);
    }
  }

  /**
   * Upgrades the plain connection to TLS
   * @return void
   * @throws ImapException if the server or the TLS handshake fails
   */
  protected function startTls(): void
  {
    $this->command(['STARTTLS']);
    if (!@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
      throw new ImapException('STARTTLS handshake with ' . $this->host . ' failed');
    }
  }

  /**
   * Sends a tagged command and reads until its completion
   * @param array<string|array{0: string, 1?: bool}> $tokens strings are sent verbatim, [value] is sent as quoted string
   *   or literal as needed, [value, true] is always sent as literal
   * @return array<array{line: string, literals: string[]}> the untagged responses
   * @throws ImapException on NO/BAD or connection errors
   */
  protected function command(array $tokens): array
  {
    if ($this->stream === null) {
      throw new ImapException('Not connected');
    }

    $tag = sprintf('A%04d', ++$this->tagCounter);
    $buffer = $tag;
    foreach ($tokens as $token) {
      if (is_string($token)) {
        $buffer .= ' ' . $token;
        continue;
      }
      if (!($token[1] ?? false) && !preg_match('/[\r\n\x00\x80-\xff]/', $token[0]) && strlen($token[0]) < 1024) {
        $buffer .= ' "' . addcslashes($token[0], '"\\') . '"';
        continue;
      }
      $this->sendLiteralAnnouncement($buffer . ' {' . strlen($token[0]) . '}', $tag);
      $buffer = $token[0];
    }

    $this->write($buffer . "\r\n");
    return $this->readUntilTagged($tag);
  }

  /**
   * Sends everything up to a literal and waits for the server's continuation request
   * @param string $buffer the command part ending in "{size}"
   * @param string $tag the command tag
   * @return void
   * @throws ImapException if the server rejects the command instead of continuing
   */
  protected function sendLiteralAnnouncement(string $buffer, string $tag): void
  {
    $this->write($buffer . "\r\n");
    while (true) {
      $response = $this->readResponseLine();
      if (str_starts_with($response['line'], '+')) {
        return;
      }
      if (str_starts_with($response['line'], $tag . ' ')) {
        throw new ImapException('Command rejected: ' . $response['line']);
      }
    }
  }

  /**
   * Collects untagged responses until the tagged completion of a command arrives
   * @param string $tag the command tag
   * @return array<array{line: string, literals: string[]}> the untagged responses
   * @throws ImapException if the command completed with NO or BAD
   */
  protected function readUntilTagged(string $tag): array
  {
    $responses = [];
    while (true) {
      $response = $this->readResponseLine();
      if (!str_starts_with($response['line'], $tag . ' ')) {
        $this->trackMailboxSize($response['line']);
        $responses[] = $response;
        continue;
      }
      if (!preg_match('/^' . $tag . ' OK\b/i', $response['line'])) {
        throw new ImapException('IMAP error: ' . substr($response['line'], strlen($tag) + 1));
      }
      return $responses;
    }
  }

  /**
   * Keeps the message count in sync with EXISTS/EXPUNGE responses
   * @param string $line an untagged response line
   * @return void
   */
  protected function trackMailboxSize(string $line): void
  {
    if (preg_match('/^\* (\d+) EXISTS\b/i', $line, $match)) {
      $this->exists = intval($match[1]);
    } elseif (preg_match('/^\* \d+ EXPUNGE\b/i', $line)) {
      $this->exists = max(0, $this->exists - 1);
    }
  }

  /**
   * Reads one logical response line; each literal "{n}" is read separately and replaced by a "{#index}" placeholder
   * @return array{line: string, literals: string[]} the line and its literals
   * @throws ImapException on timeout or closed connection
   */
  protected function readResponseLine(): array
  {
    $line = '';
    $literals = [];
    while (true) {
      $chunk = $this->readLine();
      if (!preg_match('/\{(\d+)\+?\}$/', $chunk, $match)) {
        return ['line' => $line . $chunk, 'literals' => $literals];
      }
      $line .= substr($chunk, 0, -strlen($match[0])) . '{#' . count($literals) . '}';
      $literals[] = $this->readBytes(intval($match[1]));
    }
  }

  /**
   * Extracts the message body from a FETCH response, sent as literal, quoted string or NIL
   * @param array{line: string, literals: string[]} $response one untagged FETCH response
   * @return string|null the raw message or null if the response holds no body
   */
  protected function extractBody(array $response): ?string
  {
    if (preg_match('/\bBODY\[\] \{#(\d+)\}/', $response['line'], $match)) {
      return $response['literals'][intval($match[1])];
    }
    if (preg_match('/\bBODY\[\] "((?:[^"\\\\]|\\\\.)*)"/', $response['line'], $match)) {
      return stripcslashes($match[1]);
    }
    return null;
  }

  /**
   * @return string one line from the socket without its CRLF
   * @throws ImapException on timeout or closed connection
   */
  protected function readLine(): string
  {
    $line = fgets($this->stream);
    if ($line === false) {
      $this->throwStreamError();
    }
    return rtrim($line, "\r\n");
  }

  /**
   * @param int $length number of bytes to read
   * @return string exactly $length bytes from the socket
   * @throws ImapException on timeout or closed connection
   */
  protected function readBytes(int $length): string
  {
    $data = '';
    while (strlen($data) < $length) {
      $chunk = fread($this->stream, $length - strlen($data));
      if ($chunk === false || ($chunk === '' && feof($this->stream))) {
        $this->throwStreamError();
      }
      if ($chunk === '' && stream_get_meta_data($this->stream)['timed_out']) {
        $this->throwStreamError();
      }
      $data .= $chunk;
    }
    return $data;
  }

  /**
   * @param string $data bytes to send
   * @return void
   * @throws ImapException if the socket can't be written
   */
  protected function write(string $data): void
  {
    $length = strlen($data);
    for ($written = 0; $written < $length; $written += $result) {
      $result = fwrite($this->stream, substr($data, $written));
      if ($result === false || $result === 0) {
        $this->throwStreamError();
      }
    }
  }

  /**
   * @return never
   * @throws ImapException describing whether the socket timed out or was closed
   */
  protected function throwStreamError(): never
  {
    $timedOut = $this->stream !== null && stream_get_meta_data($this->stream)['timed_out'];
    throw new ImapException($timedOut ? 'IMAP connection timed out' : 'IMAP connection closed unexpectedly');
  }

  /**
   * Encodes a UTF-8 mailbox name to IMAP modified UTF-7
   * @param string $folder the UTF-8 mailbox name
   * @return string the encoded name
   */
  protected function encodeFolder(string $folder): string
  {
    return mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
  }
}
