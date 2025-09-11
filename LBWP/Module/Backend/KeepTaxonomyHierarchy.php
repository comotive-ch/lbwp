<?php

namespace LBWP\Module\Backend;


/**
 * Fixing taxonomy hierarchy
 * @author Michael Sebel <michael@comotive.ch>
 */
class KeepTaxonomyHierarchy extends \LBWP\Module\Base
{
  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Registers all the actions and filters
   */
  public function initialize()
  {
    add_filter('wp_terms_checklist_args', array($this, 'filterTaxonomyArgs'));
  }

  /**
   * @param array $args checkbox list arguments
   * @return array the arguments for the checkbox list
   */
  public function filterTaxonomyArgs($args)
  {
    // Add a jquery that fixes the hierarchy
    add_action( 'admin_footer', array($this, 'printScripts'));

    // Change wordpress' default args and return
		$args['checked_ontop'] = false;
		return $args;
  }

  /**
   * Print simple jquery to fix hierarchy and scroll to first selected box
   */
  public function printScripts()
  {
    echo '
      <script type="text/javascript">
        jQuery(function() {
          jQuery(\'[id$="-all"] > ul.categorychecklist\').each(function() {
            var $list = jQuery(this);
            var $firstChecked = $list.find(":checkbox:checked").first();

            if (!$firstChecked.length) {
              return;
            }

            var posFirst = $list.find(":checkbox").position().top;
            var posChecked = $firstChecked.position().top;

            $list.closest(".tabs-panel").scrollTop(posChecked - posFirst + 5);
          });
        });
      </script>
    ';
  }
}