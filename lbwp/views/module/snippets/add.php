<style type="text/css">
  html { padding: 0 !important; margin: 0 !important; background: #FFFFFF; }
  body { padding: 0; margin: 0; }
  html, body { height: auto; min-height: 100%; }
  .media-upload-form { margin-top: 0 !important; }
  .wrap { margin: 0 !important; padding: 0 10px !important; }
  .wrap h2 { margin-bottom:20px !important; }
  .wrap *, .wrap *:before, .wrap *:after { -moz-box-sizing: border-box; -webkit-box-sizing: border-box; box-sizing: border-box; }
  .multiple-sidebar-box { border: 1px solid #DFDFDF; padding: 10px; margin-bottom: 20px; }
  .multiple-sidebar-box table { border: none !important; }
  table { border: 1px solid #E1E1E1 !important; border-width: 1px 1px 0 1px !important; }
  .widefat td,
  .widefat th { border-bottom: 1px solid #E1E1E1; }
  #media-upload input[type=radio] { height: 16px; width: 16px; }
  #media-upload input[type=radio]:checked:before { margin: 4px; width: 7px; height: 7px; }
  .media-item .describe td { padding: 3px 0 4px 0 !important; }
</style>
<?php
global $body_id;
$body_id = 'media-upload';

require_once('../../../../../../wp-admin/admin.php');

use LBWP\Util\Date;

wp_enqueue_style('media');
wp_iframe('form_iframe');

function form_iframe() {
  ?>
  <div class="wrap">
    <form class="form-insert">
      <h2>Snippet einfügen</h2>
        <?php
        global $wpdb;
        ?>
        <table class="widefat fixed" cellspacing="0">
        <thead>
          <tr class="thead">
            <th scope="col" class="manage-column column-name"><strong>Snippet-Name</strong></th>
            <th scope="col" class="manage-column column-modified"><strong>Letzte Änderung</strong></th>
          </tr>
        </thead>
        <tbody>
        <?php
        // Load all persistent forms for this page
        $snippets = get_posts(array(
          'post_type' => 'lbwp-snippet',
          'posts_per_page' => -1,
          'orderby' => 'post_modified'
        ));
        // Display the snippets
        foreach ($snippets as $snippet) {
          // Print the table row
          echo '
          <tr>
            <td class="column-name">
              <input type="radio" value="' . $snippet->ID . '" id="form_' . $snippet->ID . '" name="snippet" />
              <label for="form_' . $snippet->ID . '"><strong>' . $snippet->post_title . '</strong></label>
              <textarea style="display:none;" id="data_form_' . $snippet->ID . '">' . $snippet->post_content . '</textarea>
            </td>
            <td class="column-modified">' . Date::toHumanReadable($snippet->post_modified) . '</td>
          </tr>
          ';
        }
        // If nothing is shown, display a message row
        if (count($snippets) == 0) {
          echo '
          <tr>
            <td colspan="2">Es sind noch keine Snippets vorhanden.</td>
          </tr>
          ';
        }
        ?>
        <tr>
          <td colspan="2">
            <input type="button" value="Einfügen" id="go_button" class="button">
          </td>
        </tr>
        </tbody>
        </table>
      </div>
    </form>
    <script>
      jQuery(function() {
        jQuery('#go_button').click(function() {
          var win = window.dialogArguments || opener || parent || top;

          // Check if something is selected
          if (jQuery('input[type="radio"]:checked').length == 0) {
            alert('Bitte wählen Sie ein Snippet aus.');
            return false;
          }

          // Create the code and insert it
          var id = jQuery('input[type="radio"]:checked').val();
          var contentId = '#data_' + jQuery('input[type="radio"]:checked').attr('id');
          var code = jQuery(contentId).val();
          
          win.send_to_editor(code);

          return false;
        });
      });
    </script>
  </div>
  <?php
}
?>