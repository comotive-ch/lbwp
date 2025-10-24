<?php

namespace LBWP\Theme\Feature;

use LBWP\Module\General\Cms\SystemLog;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush as WebPushLib;

require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/Minishlink/WebPush/vendor/autoload.php';

/**
 * Webpush class
 * Note: to get the VAPID keys, run the following command in the terminal.
 *  php -r "require '/var/www/lbwp/wp-content/plugins/lbwp/resources/libraries/Minishlink/WebPush/vendor/autoload.php'; echo \"\n\" . json_encode(Minishlink\WebPush\VAPID::createVapidKeys()) . \"\n\";"
 * Then save the keys in the code
 */
class WebPush extends WebPushLib
{
  /**
   * @var Subscription|null the use push subscription
   */
  public ?Subscription $subscription = null;

  /**
   * @param array $auth
   * @param array $defaultOptions
   * @param int|null $timeout
   * @param array $clientOptions
   * @throws \ErrorException
   */
  public function __construct(array $auth = [], array $defaultOptions = [], ?int $timeout = 30, array $clientOptions = [])
  {
    parent::__construct($auth, $defaultOptions, $timeout, $clientOptions);
  }

  /**
   * @param $subscriptionData array with the subscription data:
   *  endpoint => the oush URL
   *  publicKey => the user token (?)
   *  authToken => the user token (?)
   *  contentEncoding => 'aesgmc' (optional)
   * @return void
   * @throws \ErrorException
   */
  public function createSubscription($subscriptionData)
  {
    try{
      if(!is_array($subscriptionData) || $subscriptionData['endpoint'] === null){
        SystemLog::add('WebPush', 'debug', 'subscription failed', array(
          'error' => 'subscription data is not an array or endpoint is null'
        ));
        return;
      }

      $this->subscription = Subscription::create($subscriptionData);
    }catch (\Exception $e){
      SystemLog::add('WebPush', 'debug', 'subscription failed', array(
        'error' => $e->getMessage()
      ));
    }
  }

  /**
   * The notification call itself
   * @param $message
   * @param $title
   * @param $icon
   * @return bool|string true on success, false if subscription is null else the authtoken (the auth token is used to store the subscription in the DB)
   * @throws \ErrorException
   */
  public function notify($message, $title, $url, $icon)
  {
    if ($this->subscription !== null) {
      $result = $this->sendOneNotification($this->subscription, json_encode([
        'message' => $message,
        'title' => $title,
        'url' => $url,
        'icon' => $icon
      ]));

      /*SystemLog::add('WebPush', 'debug', 'response', array(
        'type' => $title,
        'subscription' => $this->subscription,
        'result' => $result->getResponse()
      ));*/

      if ($result->isSuccess()) {
        return true;
      } else {
        return $this->subscription->getAuthToken();
      }
    }

    return false;
  }
}