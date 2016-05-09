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
  #typeSearch { float:right; }
  .media-item .describe td { padding: 3px 0 4px 0 !important; }
</style>
<?php
global $body_id;
$body_id = 'media-upload';

require_once('../../../../../../wp-admin/admin.php');

wp_enqueue_style('media');
wp_iframe('form_iframe');

function form_iframe() {
  ?>
  <div class="wrap">
    <form class="form-insert">
      <h2>
        <?php echo $_GET['name']; ?> einfügen
        <input type="text" placeholder="Suche" id="typeSearch">
      </h2>
        <?php
        global $wpdb;
        ?>
        <table class="widefat fixed" cellspacing="0">
        <thead>
          <tr class="thead">
            <th scope="col" class="manage-column column-name"><?php echo $_GET['name']; ?></th>
          </tr>
        </thead>
        <tbody>
        <?php
        // Load all persistent forms for this page
        $items = get_posts(array(
          'post_type' => $_GET['posttype'],
          'orderby' => 'title',
          'order' => 'ASC',
          'posts_per_page' => -1
        ));

        // Display the item
        foreach ($items as $item) {
          // If empty title, use guid
          if (strlen($item->post_title) == 0) {
            $item->post_title = 'Kein Titel - ' . $_GET['name'] . ' ID ' . $item->ID;
          }
          // Print the table row
          echo '
          <tr>
            <td class="column-name">
              <input type="radio" value="' . $item->ID . '" id="form_' . $item->ID . '" name="form" data-title="' . $item->post_title . '" />
              <label for="form_' . $item->ID . '"><strong>' . $item->post_title . '</strong></label>
            </td>
          </tr>
          ';
        }
        // If nothing is shown, display a message row
        if (count($items) == 0) {
          echo '
          <tr>
            <td>Es sind noch keine ' . $_GET['plural'] . ' vorhanden.</td>
          </tr>
          ';
        }
        ?>
        </tbody>
        </table>

        <br />
        <input type="button" value="Einfügen" id="go_button" class="button">
      </div>
    </form>
    <script>
      jQuery(function() {
        jQuery('#go_button').click(function() {
          var win = window.dialogArguments || opener || parent || top;

          // Check if something is selected
          if (jQuery('input[type="radio"]:checked').length == 0) {
            alert('Bitte wählen Sie ein <?php echo $_GET['name']; ?> aus.');
            return false;
          }

          // Create the code and insert it
          var id = jQuery('input[type="radio"]:checked').val();
          var title = jQuery('input[type="radio"]:checked').data('title');
          var code = '[<?php echo $_GET['code']; ?> id="' + id + '" title="' + title + '"]';
          win.send_to_editor(code);

          return false;
        });

        jQuery('#typeSearch').keyup(function() {
          var search = jQuery(this).val().toLowerCase();
          var selector = '.widefat tbody tr';
          // Make all visible
          jQuery(selector).show();
          // Filter out matches
          jQuery(selector).each(function() {
            var itemName = jQuery(this).find('label').text().toLowerCase();
            if (itemName.indexOf(search) == -1) {
              jQuery(this).hide();
            }
          });
        })
      });
    </script>
  </div>
  <?php
}
?>