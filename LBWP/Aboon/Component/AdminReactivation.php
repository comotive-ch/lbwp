<?php

namespace LBWP\Aboon\Component;

use LBWP\Theme\Base\Component;
use LBWP\Util\WordPress;

/**
 * Allows admins and shop managers to re-activate cancelled subscriptions
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class AdminReactivation extends Component
{
  /**
   * Initialize the component
   */
  public function init()
  {
    // Adds a button, if the subscription has cancelled status
    add_action('wcs_subscription_schedule_after_billing_schedule', array($this, 'addReactivationButton'));
    add_action('wp_ajax_adminReactivateCancelledSubscription', array($this, 'reactivateSubscription'));
  }

  /**
   * @param \WC_Subscription $subscription
   */
  public function addReactivationButton($subscription)
  {
    if ($subscription->get_status() == 'cancelled' || $subscription->get_status() == 'expired')  {
      echo '
        <p><a class="button reactivate-subscription" data-id="' . $subscription->get_id() . '">Abonnement wieder aktivieren</a></p>
        <script type="text/javascript">
          jQuery(function() {
            // Move the button up, as it can be placed only in the middle by the filter
            var button = jQuery(".reactivate-subscription");
            button.parent().after(jQuery("#billing-schedule"));
            // Make Confirm and ajax request when the subscription should be activated
            button.on("click", function() {
              if (confirm("Bitte stellen Sie sicher, dass sie zur Reaktivierung dieses Abonnements die Einwilligung des Kunden eingeholt haben. Ist dies der Fall, kann das Abonnement wieder reaktiviert werden. Bitte beachten Sie, dass sie womöglich das Datum der nächsten Zahlung anpassen müssen")) {
                jQuery.post("/wp-admin/admin-ajax.php?action=adminReactivateCancelledSubscription&id=" + button.data("id"), function(response) {
                  if (response.success && response.id > 0) {
                    document.location.reload();
                  } else {
                    alert("Die Reaktivierung könnte nicht durchgeführt werden, bitte wenden Sie sich an den Administrator.");
                  }
                });
              }
            });
          });
        </script>
      ';
    }
  }

  /**
   * Reactivate the given subscription
   */
  public function reactivateSubscription()
  {
    $response = array('success' => false);
    $id = intval($_GET['id']);
    $subscription = wcs_get_subscription($id);
    if (wcs_is_subscription($subscription) && ($subscription->get_status() == 'cancelled' || $subscription->get_status() == 'expired')) {
      // Set active again and remove cancel and end information
      $subscription->set_status('active');
      $response['id'] = $subscription->save();
      delete_post_meta($id, '_schedule_cancelled');
      delete_post_meta($id, '_schedule_end');
      $response['success'] = true;
    }

    WordPress::sendJsonResponse($response);
  }
}