<?php

namespace LBWP\Aboon\Component;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Base\Component;
use LBWP\Util\External;
use LBWP\Util\Strings;

/**
 * Provide general checkout functions
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Refunds extends Component
{
  /**
   * @var bool internal var to prevent eventual double mails
   */
  protected $didNotify = false;

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    $this->registerPartialRefundState();
    // Change label of the refund order status and add the partial refund
    add_filter('wc_order_statuses', array($this, 'alterOrderStatuses'));
  }

  /**
   * @param $statuses
   * @return mixed
   */
  public function alterOrderStatuses($statuses)
  {
    $new = array();
    foreach ($statuses as $key => $status) {
      if ($key == 'wc-refunded') {
        // Add or new status before refunded, and rename refunded
        $new['wc-partial-refunded'] = __('Teilweise rückerstattet', 'lbwp');
        $status = __('Vollständig rückerstattet', 'lbwp');
      }
      $new[$key] = $status;
    }

    return $new;
  }

  /**
   * Create our custom state that doesn't do much
   */
  protected function registerPartialRefundState()
  {
    register_post_status('wc-partial-refunded', array(
      'label'                     => __('Teilweise rückerstattet', 'lbwp'),
      'public'                    => true,
      'exclude_from_search'       => false,
      'show_in_admin_all_list'    => true,
      'show_in_admin_status_list' => true,
      'label_count'               => _n_noop('Teilweise rückerstattet (%s)', 'Teilweise rückerstattet (%s)', 'lbwp')
    ));
  }
} 