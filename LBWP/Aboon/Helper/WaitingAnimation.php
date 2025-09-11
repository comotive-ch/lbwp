<?php

namespace LBWP\Aboon\Helper;

use LBWP\Module\SimpleSingleton;

/**
 * Class WaitingAnimation
 */
class WaitingAnimation extends SimpleSingleton
{
  /**
   * @var string the template string to be integrated as waiting animation
   */
  protected $template = '<div class="blockUI blockOverlay" style="z-index: 1000;border: none;margin: 0px;padding: 0px;width: 100%;height: 100%;top: 0px;left: 0px;background: rgb(255, 255, 255);opacity: 0.0;cursor: default;position: absolute;"></div>';

  /**
   * Can be used on init or after_setup_theme or later
   */
  public function run()
  {
    add_action('wp_footer', array($this, 'printAnimationFunctions'));
  }

  /**
   * Print the animation functions
   */
  public function printAnimationFunctions()
  {
    ?>
    <script type="text/javascript">
      var aboon_waitingTemplate = "<?php echo addslashes($this->template); ?>";
      function aboon_waitingAnimationStart(selector)
      {
        jQuery(selector).append(aboon_waitingTemplate);
        jQuery(selector + ' .blockOverlay').animate({opacity:  0.75});
      }
      function aboon_waitingAnimationStop(selector)
      {
        jQuery(selector + ' .blockOverlay').remove();
      }
    </script>
    <?php
  }
}