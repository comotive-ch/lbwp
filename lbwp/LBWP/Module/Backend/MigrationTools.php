<?php

namespace LBWP\Module\Backend;

use LBWP\Core as LbwpCore;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;
use LBWP\Util\File;
use LBWP\Util\WordPress;

/**
 * Migration Tools upon migration from dev to live mode
 * @author Michael Sebel <michael@comotive.ch>
 */
class MigrationTools extends \LBWP\Module\Base
{
  /**
   * @var array the tables that are convertible
   */
  protected $tables = array();

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
    if (LbwpCore::isSuperlogin()) {
      add_action('admin_menu', array($this, 'registerMenus'));
      add_action('wp_ajax_migrationToolsAttachmentFixing', array($this, 'processAttachmentFixing'));
    }
  }

  /**
   * Set the table configurations
   */
  protected function setTableConfigurations()
  {
    $this->tables = array(
      $this->wpdb->posts => array(
        'id' => 'ID',
        'fields' => array(
          'post_title' => 'text',
          'post_content' => 'text',
          'post_excerpt' => 'text'
        )
      ),
      $this->wpdb->postmeta => array(
        'id' => 'meta_id',
        'fields' => array(
          'meta_value' => 'serialized'
        )
      ),
      $this->wpdb->options => array(
        'id' => 'option_id',
        'fields' => array(
          'option_value' => 'serialized'
        )
      ),
      $this->wpdb->usermeta => array(
        'id' => 'umeta_id',
        'fields' => array(
          'meta_value' => 'serialized'
        )
      )
    );
  }

  /**
   * Register the superlogin menu page
   */
  public function registerMenus()
  {
    add_submenu_page(
      'tools.php',
      'Migration Tools',
      'Migration Tools',
      'administrator',
      'migration-tools',
      array($this, 'displayBackend')
    );
  }

  /**
   * Provides the backend page
   */
  public function displayBackend()
  {
    $this->setTableConfigurations();

    echo '
      <div class="wrap">
				<h2>Migration Tools</h2>
        <p>
          Diese Tools erlauben es, Teile der Datenbank-Inhalte für Migrationen von Domains zu prüfen und anzupassen.
          Für den Moment können zwei Strings eingegeben werden, die in der Datenbank ersetzt werden um z.B. falsche
          URLs (Resultierend aus dem DEV Modus) zu ersetzen.
        </p>
        ' . $this->displayTestForm() . '<br />
        ' . $this->displayExecutionForm() . '<br />
        ' . $this->displaySSLForm() . '<br />
        ' . $this->displayAttachmentFixForm() . '<br />
        ' . $this->displayMetaAddForm() . '<br />
		  </div>
    ';
  }

  protected function displayAttachmentFixForm()
  {
    return '
      <form method="post" action="?page=' . $_GET['page'] . '&runSslInfo">
        <h3>Attachment Fixing</h3>
        <p>
          Startet einen Prozess um alle Attachments mit inkompatiblen Dateinamen zu finden.
          Es werden alle umbenannten Attachments in der Datenbank auf dem Storage und in allen Feldern ersetzt.
        </p>
        <p>
          <input type="button" name="startAttachmentFixing" value="Prozess starten" class="button-primary" />
        </p>
        <ul class="attachment-fixing-results"></ul>
        <script type="text/javascript">
          var attachmentFixPage = 1;
          var attachmentsFixed = 0;
          jQuery("input[name=startAttachmentFixing]").on("click", function() {
            attachmentFixRun(attachmentFixPage);
          });
          
          function attachmentFixRun(nr) {
            jQuery.ajax(ajaxurl + "?action=migrationToolsAttachmentFixing&page=" + nr, { complete : function(response) {
              var container = jQuery(".attachment-fixing-results");
              response = response.responseJSON;
              // Print for the developer to see what happens
              console.log(response);
              if (typeof(response) == "object") {
                jQuery.each(response.files, function(key, info) {
                  container.append("<li>" + (++attachmentsFixed) + ": " + info + "</li>");
                });
              }
              // Call the next run now
              attachmentFixRun(++attachmentFixPage);
            }});
          }
        </script>
      </form>
    ';
  }

  /**
   * SSL information form
   */
  protected function displaySSLForm()
  {
    $results = '';

    // Run the info
    if (isset($_GET['runSslInfo'])) {
      foreach (array('src="http://') as $search) {
        $results .= $this->searchData($search);
      }
    }

    return '
      <form method="post" action="?page=' . $_GET['page'] . '&runSslInfo">
        <h3>SSL /Asset Migration</h3>
        <p>Zeigt info, wo sich externe HTTP Ressourcen befinden etc.</p>
        ' . $results . '
        <p>
          <input type="submit" name="cmdSslInfo" value="Unsicheren Content suchen" class="button-primary" />
          <input type="button" name="cmdUrlMigration" value="Interne URLs migrieren" class="button" />
          <input type="button" name="cmdCdnUrlMigration" value="CDN URLs migrieren" class="button" />
          <input type="button" name="cmdCdnExoMigration" value="Exoscale: Native > Cached" class="button" />
          <input type="hidden" id="migratedHostName" value="' . getLbwpHost() . '" />
          <input type="hidden" id="cdnType" value="' . CDN_TYPE . '" />
          <input type="hidden" id="cdnHttpUrl" value="http://lbwp-cdn.sdd1.ch/' . ASSET_KEY . '/files/" />
          <input type="hidden" id="cdnHttpsUrl" value="https://s3-eu-west-1.amazonaws.com/lbwp-cdn.sdd1.ch/' . ASSET_KEY . '/files/" />
          <input type="hidden" id="cdnExoNative" value="https://assets01.sdd1.ch/assets/lbwp-cdn/' . ASSET_KEY . '/files/" />
          <input type="hidden" id="cdnExoCached" value="https://' . LBWP_HOST . '/assets/lbwp-cdn/' . ASSET_KEY . '/files/" />
        </p>
        <script type="text/javascript">
          jQuery("input[name=cmdUrlMigration]").click(function() {
            var host = jQuery("#migratedHostName").val();
            var from = "http://" + host + "/";
            var to = "https://" + host + "/";
            jQuery("input[name=searchValue]").val(from);
            jQuery("input[name=replaceValue]").val(to);
          });
          
          jQuery("input[name=cmdCdnUrlMigration]").click(function() {
            jQuery("input[name=searchValue]").val(jQuery("#cdnHttpUrl").val());
            jQuery("input[name=replaceValue]").val(jQuery("#cdnHttpsUrl").val());
          });
          
          jQuery("input[name=cmdCdnExoMigration]").click(function() {
            jQuery("input[name=searchValue]").val(jQuery("#cdnExoNative").val());
            jQuery("input[name=replaceValue]").val(jQuery("#cdnExoCached").val());
          });
          
          if (jQuery("#cdnType").val() != ' . CDN_TYPE_AMAZONS3_EU . ') {
            jQuery("input[name=cmdCdnUrlMigration]").hide();
          }
        </script>
      </form>
    ';
  }

  /**
   * This display the tester form, and will display possible changes that need to be made.
   * It also does test that the execution/migration can't handle yet.
   */
  protected function displayTestForm()
  {
    if (isset($_GET['runTest'])) {
      // Run the test and show results
      return $this->testData();
    } else {
      // Display the submission form
      return $this->displayForm(
        'Vor dem ausführen kann ein Test gestartet werden, wo zu migrierende Daten liegen.',
        'Test ausführen',
        'runTest'
      );
    }
  }

  /**
   * This provides possibilities to execute a migration on certain tables
   */
  protected function displayExecutionForm()
  {
    if (isset($_GET['runMigration'])) {
      // Migrate and display the migration results
      return $this->migrateData($_POST['searchValue'], $_POST['replaceValue']);
    } else {
      return $this->displayForm(
        'Daten können Migriert werden, jedoch nur Tabellen ohne serialisierte Werte. Bitte führe einen Test aus, bevor du den Migrieren Knopf drückst und erstelle ein Backup.',
        'Migration ausführen',
        'runMigration'
      );
    }
  }

  /**
   * Form to add new meta info to all posts
   */
  protected function displayMetaAddForm()
  {
    $html = '';

    if (isset($_GET['runMetaAdd'])) {
      $html .= $this->saveMetaAdd();
    }

    $html .= '
      <form method="post" action="?page=' . $_GET['page'] . '&runMetaAdd">
        <h3>Meta Daten erstellen</h3>
        <p>Erstellt ein Metafeld auf allen wpX_posts Datensätzen die dem Filter entsprechen</p>
        <table width="600">
          <tr>
            <td width="150">Post-Type</td>
            <td><input type="text" name="postType" value="post" style="width:100%;" /></td>
          </tr>
          <tr>
            <td width="150">Post-Status</td>
            <td><input type="text" name="postStatus" value="publish" style="width:100%;" /></td>
          </tr>
          <tr>
            <td width="150">Meta-Key</td>
            <td><input type="text" name="metaKey" value="" style="width:100%;" /></td>
          </tr>
          <tr>
            <td width="150">Meta-Value</td>
            <td><input type="text" name="metaValue" value="" style="width:100%;" /></td>
          </tr>
        </table>
        <p><input type="submit" name="cmdMetaAdd" value="Meta-Daten erstellen" class="button-primary" /></p>
      </form>
    ';

    return $html;
  }

  /**
   * Add meta data to datasets
   */
  protected function saveMetaAdd()
  {
    $posts = 0;
    $sql = 'SELECT ID FROM {sql:postTable} WHERE post_type = {postType} AND post_status = {postStatus}';

    $postIds = $this->wpdb->get_col(Strings::prepareSql($sql, array(
      'postTable' => $this->wpdb->posts,
      'postType' => $_POST['postType'],
      'postStatus' => $_POST['postStatus']
    )));

    // Add metadata for those posts
    foreach ($postIds as $postId) {
      ++$posts;
      update_post_meta($postId, $_POST['metaKey'], $_POST['metaValue']);
    }

    return '<p>' . $posts . ' Posts have got ' . $_POST['metaKey'] . '=' . $_POST['metaValue'] . ' added.</p>';
  }

  /**
   * @param string $infoText
   * @param string $buttonText
   * @param string $command
   * @return string
   */
  protected function displayForm($infoText, $buttonText, $command)
  {
    // Display the submission form
    return '
      <form method="post" action="?page=' . $_GET['page'] . '&' . $command . '">
        <h3>' . $buttonText . '</h3>
        <p>' . $infoText . '</p>
        <table width="600">
          <tr>
            <td width="150">Suchwert</td>
            <td><input type="text" name="searchValue" style="width:100%;" /></td>
          </tr>
          <tr>
            <td width="150">Ersetzen mit</td>
            <td><input type="text" name="replaceValue" style="width:100%;" /></td>
          </tr>
        </table>
        <p><input type="submit" name="cmd' . $command . '" value="' . $buttonText . '" class="button-primary" /></p>
      </form>
    ';
  }

  /**
   * Process fixing of attachments
   */
  public function processAttachmentFixing()
  {
    $result = array();
    $page = intval($_REQUEST['page']);
    $storage = LbwpCore::getModule('S3Upload');
    // Set table configs to make data migrations but only do posts
    $this->tables = array(
      $this->wpdb->posts => array(
        'id' => 'ID',
        'fields' => array(
          'post_content' => 'text'
        )
      )
    );
    // Get attachments to be transformed
    $attachments = get_posts(array(
      'post_type' => 'attachment',
      'posts_per_page' => 50,
      'paged' => $page
    ));

    foreach ($attachments as $attachment) {
      $file = $fixed = File::getFileOnly($attachment->guid);
      $extension = File::getExtension($file);
      if (strlen($file) > 0 && (strlen($extension) == 4 || strlen($extension) == 5)) {
        Strings::alphaNumLowFiles($fixed);
      }
      // Only if the fixed file is different we need to make changes
      if ($file != $fixed) {
        // Get the list of files to be renamed (before/after array
        $list = $this->getFileList($attachment->ID);
        // If the list has no entries, escape this loop
        if (count($list) == 0) continue;
        // Rename on storage, if possible
        $this->attFixRenameOnStorage($list, $storage);
        // Rename local attachment data when given
        $this->attFixRenameInDb($attachment->ID);

        // Make sure to search replace the full db for the path
        // Then, print all files into the output
        foreach ($list as $file) {
          if ($file['before'] != $file['after']) {
            $this->migrateData($file['before'], $file['after']);
          }
          $result[] = $file['before'] . ' > ' . $file['after'];
        }
      }
    }

    WordPress::sendJsonResponse(array(
      'files' => $result,
      'checked' => count($attachments)
    ));
  }

  /**
   * @param array $files before/after files to rename on storage
   * @param S3Upload $storage
   */
  protected function attFixRenameOnStorage($files, $storage)
  {
    foreach ($files as $file) {
      $before = ASSET_KEY . '/files/' . $file['before'];
      $after = ASSET_KEY . '/files/' . $file['after'];
      // Test if the before key exists
      if ($file['before'] != $file['after'] && $storage->fileExists($before)) {
        $storage->renameFile($before, $after, S3Upload::ACL_PUBLIC);
      }
    }
  }

  /**
   * @param array $files before/after files to rename in local db
   */
  protected function attFixRenameInDb($id)
  {
    // Read meta informations and replace them with fix function
    $data = wp_get_attachment_metadata($id);

    if (is_array($data) && isset($data['file'])) {
      Strings::alphaNumLowFiles($data['file']);
      foreach ($data['sizes'] as $key => $size) {
        Strings::alphaNumLowFiles($data['sizes'][$key]['file']);
      }
      wp_update_attachment_metadata($id, $data);
      update_post_meta($id, '_wp_attached_file', $data['file']);
    } else {
      // Normal file, just get the file name and key
      $file = get_post_meta($id, '_wp_attached_file', true);
      Strings::alphaNumLowFiles($file);
      update_post_meta($id, '_wp_attached_file', $file);
    }
  }

  /**
   * @param int $id the attachment id
   * @return array the result
   */
  protected function getFileList($id)
  {
    $files = $result = array();
    $data = wp_get_attachment_metadata($id);
    if (is_array($data) && isset($data['file'])) {
      $files[] = $data['file'];
      $folder = substr($data['file'], 0, strrpos($data['file'], '/') + 1);
      foreach ($data['sizes'] as $size) {
        $files[] = $folder . $size['file'];
      }
    } else {
      // Normal file, just get the file name and key
      $files[] = get_post_meta($id, '_wp_attached_file', true);
    }

    // Make a before and after array
    foreach ($files as $file) {
      $after = $file;
      Strings::alphaNumLowFiles($after);
      $result[] = array(
        'before' => $file,
        'after' => $after
      );
    }

    return $result;
  }

  /**
   * Tests data and prints a table with results for each field and table.
   * This shows every field, altough only text fields are migrateable now.
   */
  protected function testData()
  {
    $search = $_POST['searchValue'];
    $replace = $_POST['replaceValue'];

    // Define the SQL template
    $sql = '
      SELECT {sql:idField} AS id, {sql:searchField} AS searchField
      FROM {sql:tableName} WHERE {sql:searchField} LIKE {searchValue}
    ';

    $html = '';
    foreach ($this->tables as $table => $config) {
      // Begin the table
      $html .= '
        <p>
        <h3>Tabelle: ' . $table . '</h3>
        <table width="100%" class="widefat fixed">
          <tr>
            <td width="80"><strong>' . $config['id'] . '</strong></td>
            <td width="150"><strong>Feld</strong></td>
            <td><strong>Inhalt</strong></td>
          </tr>
      ';

      // Search the tables
      foreach ($config['fields'] as $searchField => $type) {
        $results = $this->wpdb->get_results(Strings::prepareSql($sql, array(
          'idField' => $config['id'],
          'searchField' => $searchField,
          'tableName' => $table,
          'searchValue' => '%' . $search . '%',
        )));

        // Display the resultset
        foreach ($results as $result) {
          $html .= '
            <tr>
              <td>' . $this->linkResult($result->id, $table) . '</td>
              <td>' . $searchField . '</td>
              <td>' . Strings::chopString(htmlentities($result->searchField), 500, true) . '</td>
            </tr>
          ';
        }

        if (count($results) == 0) {
          $html .= '
            <tr>
              <td colspan="3">Keine betroffenen Datensätze gefunden</td>
            </tr>
          ';
        }
      }

      // Close the table
      $html .= '</table></p>';
    }

    return $html;
  }

  /**
   * Tests data and prints a table with results for each field and table.
   * This shows every field, altough only text fields are migrateable now.
   */
  protected function searchData($search)
  {
    // Define the SQL template
    $sql = '
      SELECT {sql:idField} AS id, {sql:searchField} AS searchField
      FROM {sql:tableName} WHERE {sql:searchField} LIKE {searchValue}
    ';

    $html = '';
    foreach ($this->tables as $table => $config) {
      // Begin the table
      $html .= '
        <p>
        <h3>Tabelle: ' . $table . '</h3>
        <table width="100%" class="widefat fixed">
          <tr>
            <td width="80"><strong>' . $config['id'] . '</strong></td>
            <td width="150"><strong>Feld</strong></td>
            <td><strong>Inhalt</strong></td>
          </tr>
      ';

      // Search the tables
      foreach ($config['fields'] as $searchField => $type) {
        $results = $this->wpdb->get_results(Strings::prepareSql($sql, array(
          'idField' => $config['id'],
          'searchField' => $searchField,
          'tableName' => $table,
          'searchValue' => '%' . $search . '%',
        )));

        // Display the resultset
        foreach ($results as $result) {
          $html .= '
            <tr>
              <td>' . $this->linkResult($result->id, $table) . '</td>
              <td>' . $searchField . '</td>
              <td>' . Strings::chopString(htmlentities($result->searchField), 500, true) . '</td>
            </tr>
          ';
        }

        if (count($results) == 0) {
          $html .= '
            <tr>
              <td colspan="3">Keine betroffenen Datensätze gefunden</td>
            </tr>
          ';
        }
      }

      // Close the table
      $html .= '</table></p>';
    }

    return $html;
  }

  /**
   * Migrate the data (this only supports text fields as of now)
   */
  protected function migrateData($search, $replace)
  {
    $html = '<p><strong>Ersetze "' . $search . '" mit "' . $replace . '".</strong></p>';

    // Run test trough every table
    foreach ($this->tables as $table => $config) {

      // Search the tables
      foreach ($config['fields'] as $searchField => $type) {
        switch ($type) {
          case 'text':
            $html .= $this->migrateTextField($table, $searchField, $search, $replace);
            break;
          case 'serialized':
            $html .= $this->migrateSerializedField($table, $config['id'], $searchField, $search, $replace);
            break;
        }
      }
    }

    return $html;
  }

  /**
   * @param string $table the table to search
   * @param string $field the field to look into
   * @param string $search the value to find
   * @param string $replace the value replacing the found values
   * @return string success or error message(s)
   */
  protected function migrateTextField($table, $field, $search, $replace)
  {
    // Simply replace this directly on the DB server
    $sql = 'UPDATE {sql:tableName} SET {sql:searchField} = REPLACE({sql:searchField},{searchValue},{replaceValue})';

    $this->wpdb->query(Strings::prepareSql($sql, array(
      'tableName' => $table,
      'searchField' => $field,
      'searchValue' => $search,
      'replaceValue' => $replace,
    )));

    $message = '<p>' . $this->wpdb->rows_affected . ' rows have been migrated in ' . $table . '.' . $field . '</p>';

    return $message;
  }

  /**
   * @param string $table the table to search
   * @param string $keyField the id field
   * @param string $replaceField the field to look into
   * @param string $search the value to find
   * @param string $replace the value replacing the found values
   * @return string success or error message(s)
   */
  protected function migrateSerializedField($table, $keyField, $replaceField, $search, $replace)
  {
    $counter = 0;
    $sql = '
      SELECT {sql:keyField}, {sql:replaceField} FROM {sql:tableName}
      WHERE {sql:replaceField} LIKE "%{raw:searchValue}%"
    ';
    $results = $this->wpdb->get_results(Strings::prepareSql($sql, array(
      'tableName' => $table,
      'keyField' => $keyField,
      'replaceField' => $replaceField,
      'searchValue' => $search
    )), ARRAY_A);

    // Loop trough all items of the dataset
    foreach ($results as $item) {
      $value = $item[$replaceField];
      $id = $item[$keyField];
      $changed = false;
      if (is_serialized($value)) {
        $value = unserialize($value);
        if (is_array($value)) {
          $value = ArrayManipulation::deepReplace($search, $replace, $value);
          $value = serialize($value);
          $changed = true;
        }
      } else {
        $value = str_replace($search, $replace, $value);
        $changed = true;
      }

      // Save data back to database
      if ($changed) {
        // Simply replace this directly on the DB server
        $sql = 'UPDATE {sql:tableName} SET {sql:replaceField} = {newValue} WHERE {sql:keyField} = {keyValue}';
        $this->wpdb->query(Strings::prepareSql($sql, array(
          'tableName' => $table,
          'replaceField' => $replaceField,
          'newValue' => $value,
          'keyField' => $keyField,
          'keyValue' => $id
        )));
        ++$counter;
      }
    }

    $message = '<p>' . $counter . ' rows have been migrated in ' . $table . '.' . $keyField . '</p>';

    return $message;
  }

  /**
   * @param $id
   * @param $table
   * @return string
   */
  protected function linkResult($id, $table)
  {
    $db = WordPress::getDb();
    switch ($table) {
      case $db->posts:
        return '<a href="/wp-admin/post.php?post=' . $id . '&action=edit">' . $id . '</a>';
        break;
    }

    return $id;
  }
}