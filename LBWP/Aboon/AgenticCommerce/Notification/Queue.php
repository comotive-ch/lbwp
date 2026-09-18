<?php

namespace LBWP\Aboon\AgenticCommerce\Notification;

use LBWP\Aboon\AgenticCommerce\Log;
use LBWP\Helper\Cronjob;
use LBWP\Util\LbwpData;

/**
 * Durable outbound notification queue (lbwp_data rows). Jobs are delivered right away (after the
 * response when inside a REST request), retried hourly on failure and dead-lettered after the
 * attempt limit. Channels sign their payloads at delivery time so no secret is stored.
 * @package LBWP\Aboon\AgenticCommerce\Notification
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Queue
{
  public const string ROW_KEY = 'agenticnotify';
  public const string CHANNEL_ACP = 'acp_webhook';
  public const string CHANNEL_UCP = 'ucp_events';
  public const int IMMEDIATE_ATTEMPTS = 3;
  public const int MAX_ATTEMPTS = 24;
  public const int RETRY_DELAY = 3600;
  public const string CRON_JOB = 'agentic_notification_retry';
  /**
   * @var array row ids to dispatch after the response
   */
  protected static array $deferred = [];
  /**
   * @var bool whether the shutdown hook is registered
   */
  protected static bool $shutdownRegistered = false;

  /**
   * Registers the hourly retry processing.
   * @return void
   */
  public function register(): void
  {
    if (has_action('cron_job_' . self::CRON_JOB)) {
      return;
    }
    add_action('cron_hourly', [$this, 'processDue']);
    add_action('cron_job_' . self::CRON_JOB, [$this, 'processDue']);
  }

  /**
   * Returns the channel class map.
   * @return array channel => class with static deliver(array $data): array
   */
  public static function channels(): array
  {
    return (array) apply_filters('aboon_agentic_notification_channels', [
      self::CHANNEL_ACP => AcpOrderWebhook::class,
      self::CHANNEL_UCP => UcpOrderEvents::class,
    ]);
  }

  /**
   * Stores a job and returns its row id.
   * @param string $channel channel key
   * @param int $orderId order id
   * @param string $event event name
   * @param array $payload payload (JSON encoded on delivery)
   * @param array $meta extra data the channel needs (no secrets)
   * @return string row id
   */
  public static function enqueue(string $channel, int $orderId, string $event, array $payload, array $meta = []): string
  {
    $rowId = 'n' . substr(md5($channel . $orderId . $event . microtime(true) . wp_rand()), 0, 24);
    self::table()->updateRow($rowId, [
      'channel' => $channel,
      'order_id' => $orderId,
      'event' => $event,
      'payload' => $payload,
      'meta' => $meta,
      'attempts' => 0,
      'next_attempt' => time(),
      'created' => time(),
      'last_error' => '',
    ]);
    return $rowId;
  }

  /**
   * Enqueues and delivers immediately, or after the response inside REST requests.
   * @param string $channel channel key
   * @param int $orderId order id
   * @param string $event event name
   * @param array $payload payload
   * @param array $meta extra data
   * @return string row id
   */
  public static function send(string $channel, int $orderId, string $event, array $payload, array $meta = []): string
  {
    $rowId = self::enqueue($channel, $orderId, $event, $payload, $meta);
    if (defined('REST_REQUEST') && REST_REQUEST) {
      self::sendAfterResponse($rowId);
    } else {
      self::dispatchNow($rowId);
    }
    return $rowId;
  }

  /**
   * Schedules a row for dispatch on shutdown (after the response is flushed).
   * @param string $rowId row id
   * @return void
   */
  public static function sendAfterResponse(string $rowId): void
  {
    self::$deferred[] = $rowId;
    if (self::$shutdownRegistered) {
      return;
    }
    self::$shutdownRegistered = true;
    add_action('shutdown', [self::class, 'flushDeferred'], 999);
  }

  /**
   * Dispatches deferred rows after finishing the client response.
   * @return void
   */
  public static function flushDeferred(): void
  {
    if (count(self::$deferred) === 0) {
      return;
    }
    if (function_exists('fastcgi_finish_request')) {
      fastcgi_finish_request();
    }
    foreach (self::$deferred as $rowId) {
      self::dispatchNow($rowId);
    }
    self::$deferred = [];
  }

  /**
   * Tries to deliver a row a few times right away.
   * @param string $rowId row id
   * @return bool true when delivered
   */
  public static function dispatchNow(string $rowId): bool
  {
    $table = self::table();
    for ($i = 0; $i < self::IMMEDIATE_ATTEMPTS; $i++) {
      $row = $table->getRow($rowId);
      if (!is_array($row) || !isset($row['data'])) {
        return false;
      }
      if (self::attempt($row['id'], $row['data'])) {
        return true;
      }
    }
    return false;
  }

  /**
   * Processes all rows whose retry time has come.
   * @return void
   */
  public function processDue(): void
  {
    $table = self::table();
    if (!$table->hasRows()) {
      return;
    }
    $now = time();
    foreach ($table->getRows('pid', 'ASC', 200) as $row) {
      $data = (array) ($row['data'] ?? []);
      if ((int) ($data['next_attempt'] ?? 0) > $now) {
        continue;
      }
      self::attempt($row['id'], $data);
    }
  }

  /**
   * Runs one delivery attempt and updates or deletes the row.
   * @param string $rowId row id
   * @param array $data row data
   * @return bool true when delivered
   */
  protected static function attempt(string $rowId, array $data): bool
  {
    $table = self::table();
    $channels = self::channels();
    $class = $channels[$data['channel'] ?? ''] ?? null;
    if ($class === null || !method_exists($class, 'deliver')) {
      $table->deleteRow($rowId);
      return false;
    }
    $result = (array) $class::deliver($data);
    if (!empty($result['ok'])) {
      $table->deleteRow($rowId);
      return true;
    }
    $data['attempts'] = (int) ($data['attempts'] ?? 0) + 1;
    $data['last_error'] = substr((string) ($result['error'] ?? 'unknown'), 0, 500);
    $data['next_attempt'] = time() + self::RETRY_DELAY;
    Log::notificationFailure((string) $data['channel'], $data['last_error'], ['order' => $data['order_id'] ?? 0, 'event' => $data['event'] ?? '', 'attempt' => $data['attempts']]);
    if ($data['attempts'] >= self::MAX_ATTEMPTS) {
      Log::notificationFailure((string) $data['channel'], 'Giving up after ' . $data['attempts'] . ' attempts', ['order' => $data['order_id'] ?? 0, 'event' => $data['event'] ?? '']);
      $table->deleteRow($rowId);
      return false;
    }
    $table->updateRow($rowId, $data);
    self::scheduleRetry();
    return false;
  }

  /**
   * Registers a best-effort retry job on the cron master (not available locally).
   * @return void
   */
  protected static function scheduleRetry(): void
  {
    if (defined('LOCAL_DEVELOPMENT') || !defined('MASTER_HOST')) {
      return;
    }
    Cronjob::register([time() + 600 => self::CRON_JOB], 1);
  }

  /**
   * @return LbwpData the queue table
   */
  protected static function table(): LbwpData
  {
    return new LbwpData(self::ROW_KEY, 1);
  }
}
