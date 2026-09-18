<?php

namespace LBWP\Aboon\AgenticCommerce;

use LBWP\Module\General\Cms\SystemLog;
use Throwable;

/**
 * SystemLog wrapper for the agentic commerce components. Auth failures, payment errors and
 * outbound delivery failures are always logged; request tracing only when enabled in settings.
 * Credentials and tokens are masked before they reach the log.
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Log
{
  /**
   * @var string component name used in the system log
   */
  public const string COMPONENT = 'AgenticCommerce';

  /**
   * Logs an incoming request when request logging is enabled.
   * @param string $protocol acp|ucp
   * @param string $method HTTP method
   * @param string $path request path
   * @param string $sessionId session id or empty
   * @param string $idempotencyKey idempotency key or empty
   * @param int $status HTTP status code of the response
   * @param float $started microtime(true) at request start
   * @return void
   */
  public static function request(string $protocol, string $method, string $path, string $sessionId, string $idempotencyKey, int $status, float $started): void
  {
    if (!Settings::logRequests()) {
      return;
    }
    SystemLog::add(self::COMPONENT, $status >= 500 ? 'error' : 'info', strtoupper($protocol) . ' ' . $method . ' ' . $path . ' → ' . $status, [
      'session' => $sessionId,
      'idempotency_key' => $idempotencyKey,
      'duration_ms' => (int) round((microtime(true) - $started) * 1000),
      'test_mode' => Settings::isTestMode(),
    ]);
  }

  /**
   * Logs an authentication or signature failure (always).
   * @param string $protocol acp|ucp
   * @param string $reason short reason
   * @param array $data additional data, secrets must be masked by the caller
   * @return void
   */
  public static function authFailure(string $protocol, string $reason, array $data = []): void
  {
    SystemLog::add(self::COMPONENT, 'error', strtoupper($protocol) . ' auth failure: ' . $reason, $data);
  }

  /**
   * Logs a payment error (always).
   * @param string $handlerId payment handler id
   * @param string $message error message
   * @param array $data additional data without credentials
   * @return void
   */
  public static function paymentError(string $handlerId, string $message, array $data = []): void
  {
    SystemLog::add(self::COMPONENT, 'error', 'Payment ' . $handlerId . ': ' . $message, $data);
  }

  /**
   * Logs a failed outbound notification (always).
   * @param string $channel acp_webhook|ucp_events
   * @param string $message error message
   * @param array $data additional data
   * @return void
   */
  public static function notificationFailure(string $channel, string $message, array $data = []): void
  {
    SystemLog::add(self::COMPONENT, 'error', 'Notification ' . $channel . ': ' . $message, $data);
  }

  /**
   * Logs an unexpected exception (always).
   * @param Throwable $e the exception
   * @param array $data request context
   * @return void
   */
  public static function exception(Throwable $e, array $data = []): void
  {
    $data['exception'] = get_class($e);
    $data['file'] = basename($e->getFile()) . ':' . $e->getLine();
    SystemLog::add(self::COMPONENT, 'critical', $e->getMessage(), $data);
  }

  /**
   * Logs an informational message (always).
   * @param string $message the message
   * @param array $data additional data
   * @return void
   */
  public static function info(string $message, array $data = []): void
  {
    SystemLog::add(self::COMPONENT, 'info', $message, $data);
  }

  /**
   * Masks a secret to its first and last four characters.
   * @param string $value the secret
   * @return string masked value
   */
  public static function mask(string $value): string
  {
    if (strlen($value) <= 8) {
      return str_repeat('*', strlen($value));
    }
    return substr($value, 0, 4) . '…' . substr($value, -4);
  }
}
