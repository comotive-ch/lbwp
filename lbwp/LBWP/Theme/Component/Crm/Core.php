<?php

namespace LBWP\Theme\Component\Crm;

use LBWP\Core as LbwpCore;
use LBWP\Helper\Import\Csv;
use LBWP\Helper\Metabox;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Module\Backend\S3Upload;
use LBWP\Theme\Base\Component;
use LBWP\Theme\Feature\SortableTypes;
use LBWP\Util\External;
use LBWP\Util\Strings;
use LBWP\Util\Templating;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Util\WordPress;

/**
 * Component to provide vast backend features for members
 * @package LBWP\Theme\Component\Crm
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core extends Component
{
  /**
   * @var string slug for profile / member categories
   */
  const TYPE_PROFILE_CAT = 'crm-profile-category';
  /**
   * @var string slug for contact categories
   */
  const TYPE_CONTACT_CAT = 'crm-contact-category';
  /**
   * @var string slug for custom fields
   */
  const TYPE_FIELD = 'crm-custom-field';
  /**
   * @var string dynamic key for crm segments
   */
  const FILTER_REF_KEY = 'crm-segment';
  /**
   * @var array the configuration for the component
   */
  protected $configuration = array();
  /**
   * @var array the user admin data object
   */
  protected $userAdminData = array();
  /**
   * @var array of inactive user ids, if given
   */
  protected $inactiveUserIds = null;
  /**
   * @var int the edited user id
   */
  protected $editedUserId = 0;
  /**
   * @var \WP_User
   */
  protected $editingUser = null;
  /**
   * @var \WP_User
   */
  protected $editedUser = null;
  /**
   * @var string the field template
   */
  protected $fieldTemplate = '
    <table class="form-table custom-field-table" data-target-tab="{tabName}">
      <tbody>
        <tr>
          <th><label for="crmcf-{fieldName}">{fieldLabel}{fieldRequired}</label></th>
          <td>
            {fieldContent}
            <span class="description crmcf-description">
              <span class="dashicons dashicons-editor-help"></span>
              <label for="crmcf-{fieldName}">{fieldDescription}</label>
            </span>
            <span class="history crmcf-history">
              <span class="dashicons dashicons-share-alt2"></span>
            </span>
          </td>
        </tr>
      </tbody>
    </table>
  ';

  /**
   * Initialize the component
   */
  public function init()
  {
    // Create the data object that is used multiple times
    $this->userAdminData = $this->getUserAdminData();
    $this->setEditedUserId();
    // Register categorization post types and connections
    $this->addCategorizationPostTypes();

    // Invoke member admin scripts and menu stuff
    if (is_admin()) {
      // Various actions sorted by run time
      add_action('current_screen', array($this, 'preventUserOnDashboard'), 10);
      add_action('admin_init', array($this, 'addCategorizationMetaboxes'), 50);
      add_action('admin_menu', array($this, 'hideMenusFromMembers'), 50);
      add_action('admin_menu', array($this, 'addExportView'), 100);
      add_action('admin_head', array($this, 'invokeMemberAdminScripts'));
      add_action('pre_get_users', array($this, 'invokeUserTableQuery'));
      // Save user data
      add_action('profile_update', array($this, 'saveMemberData'));
      add_action('user_register', array($this, 'syncCoreToCustomFields'));
      add_action('save_post_' . self::TYPE_FIELD, array($this, 'invalidateCaches'));
      // XHR actions
      add_action('wp_ajax_getCrmFieldHistory', array($this, 'getCrmFieldHistory'));
      // Add custom fields as columns in admin tables
      $this->addAdminTableColumns();
    }

    // Filters to add segments containing of profile- and contact categories
    add_action('Lbwp_LMS_Metabox_' . self::FILTER_REF_KEY, array($this, 'addMemberMetabox'), 10, 3);
    add_filter('Lbwp_LMS_Data_' . self::FILTER_REF_KEY, array($this, 'getSegmentData'), 10, 2);
    add_filter('Lbwp_Autologin_Link_Validity', array($this, 'configureAutoLoginLink'));
    // Cron to automatically send changes from the last 24h
    add_action('cron_daily_7', array($this, 'sendTrackedUserChangeReport'));
    // Tell disabled users that they're not allowed anymore
    add_filter('authenticate', array($this, 'preventDisabledUserLogin'), 100, 1);

    if ($this->userAdminData['editedIsMember']) {
      // Set the editing user object
      $this->editingUser = wp_get_current_user();
      // Include the tabs navigation and empty containers
      add_action('show_user_profile', array($this, 'addTabContainers'));
      add_action('edit_user_profile', array($this, 'addTabContainers'));
      // Include custom fields as of configuration and callbacks
      add_action('show_user_profile', array($this, 'addCustomUserFields'));
      add_action('edit_user_profile', array($this, 'addCustomUserFields'));
      // Custom save functions
      add_action('profile_update', array($this, 'onMemberProfileUpdate'));
    }
  }

  /**
   * When a member profile is saved / updated
   */
  public function onMemberProfileUpdate()
  {
    // Save custom fields and contact data
    $this->saveCustomFieldData();
    $this->saveContactData();
    // If configured, override user email with main contact
    if (isset($this->configuration['mainContactMap'])) {
      $this->syncMainContactEmail();
    }
    // If configured, merge a specified custom field into the display_name
    if (isset($this->configuration['misc']['syncDisplayNameField'])) {
      $this->syncDisplayName();
    }

    // At last, make sure to flush user cache, as we may do database edits
    clean_user_cache($this->editedUserId);
  }

  /**
   * Sets the current edit user id
   */
  protected function setEditedUserId()
  {
    $this->editedUserId = intval($_REQUEST['user_id']);
    if ($this->editedUserId == 0) {
      $this->editedUserId = intval(get_current_user_id());
    }
    $this->editedUser = get_user_by('id', $this->editedUserId);
    if ($this->editedUser->ID > 0) {
      $this->editedUser->profileCategories = ArrayManipulation::forceArray(get_user_meta($this->editedUserId, 'profile-categories', true));
    }
  }

  /**
   * Add the tab containers as of config
   */
  public function addTabContainers()
  {
    $html = '<nav class="nav-tab-wrapper crm-navigation wp-clearfix">';
    // Open all the corresponding tabs and the navigation
    $activeClass = false;
    foreach ($this->configuration['tabs'] as $key => $name) {
      $classes = 'nav-tab crm-tab';
      if (!$activeClass) {
        $classes .= ' nav-tab-active';
        $activeClass = true;
      }
      // Add this to the navigation
      $html .= '<a href="javascript:void(0)" class="' . $classes . '" data-tab="' . $key . '">' . $name . '</a>';
    }
    // Close navigation
    $html .= '</nav>';

    // Print empty containers to be filled with JS
    foreach ($this->configuration['tabs'] as $key => $name) {
      $html .= '<div class="tab-container container-' . $key . '" data-tab-id="' . $key . '"></div>';
    }

    echo $html;
  }

  /**
   * Adds the output for custom user fields to be tabbed by JS
   */
  public function addCustomUserFields()
  {
    // Add disabling checkbox and profile categories view or edit field
    if ($this->userAdminData['userIsAdmin']) {
      echo $this->getDisableMemberEditor();
    }
    echo $this->getProfileCategoriesEditor();

    // Get all Custom fields to print their html
    $customFields = $this->getCustomFields($this->editedUser->profileCategories);
    // Print the fields
    foreach ($customFields as $field) {
      $title = $field['title'];
      // If there are versions, add the version to the title
      if ($field['history-active'] && count($field['versions']) > 0) {
        $version = array_values(array_slice($field['versions'], -1))[0];
        $title .= ' ' . $version;
        $field['title'] .= ' ' . $version;
      }
      // If checkbox, do not show the checkbox name as title (its shown in a label)
      if ($field['type'] == 'checkbox') {
        $title = '';
      }

      echo Templating::getBlock($this->fieldTemplate, array(
        '{fieldName}' => $field['id'],
        '{fieldLabel}' => $title,
        '{tabName}' => $field['tab'],
        '{fieldDescription}' => $field['description'],
        '{fieldRequired}' => ($field['required']) ? ' <span class="required">*</span>' : '',
        '{fieldContent}' => $this->getCustomFieldContent($field),
      ));
    }


    // Add the contact UIs
    echo $this->getProfileContactsEditor();
  }

  /**
   * @param array $field
   * @param string $key
   * @param bool $forceReadonly
   * @param bool $forceDisabled
   * @return string
   */
  protected function getCustomFieldContent($field, $key = '', $forceReadonly = false, $forceDisabled = false)
  {
    // Get the current field content, if given
    $html = '';
    if (strlen($key) == 0) $key = 'crmcf-' . $field['id'];
    $value = get_user_meta($this->editedUserId, $key, true);

    // Define attributes for the input field
    $attr = 'id="' . $key . '" name="' . $key . '" data-field-key="' . $key . '" class="crmcf-input regular-text"';
    if ($field['required'])
      $attr .= ' required="required"';
    if (($field['readonly'] && $this->userAdminData['userIsMember']) || $forceReadonly)
      $attr .= ' readonly="readonly"';
    if ($forceDisabled)
      $attr .= ' disabled="disabled"';
    if ($field['history-active'])
      $attr .= ' data-history="1"';

    // Display html for the field
    switch ($field['type']) {
      case 'textfield':
        $html .= '<input type="text" ' . $attr . ' value="' . esc_attr($value) . '" />';
        break;
      case 'textarea':
        $html .= '<textarea ' . $attr . '>' . $value . '</textarea>';
        break;
      case 'checkbox':
        $checked = checked($value, 1, false);
        $html .= '
            <label>
              <input type="checkbox" ' . $attr . ' value="1" ' . $checked . ' />
              ' . $field['title'] . '
            </label>
            ';
        break;
      case 'dropdown':
        $html .= '<select ' . $attr . '>';
        foreach ($field['field-values'] as $option) {
          $selected = selected($value, $option, false);
          $html .= '<option value="' . $option . '" ' . $selected . '>' . $option . '</option>';
        }
        $html .= '</select>';
        break;
      case 'table':
        $html .= $this->getCustomFieldTableHtml($field, $key, $value, $forceReadonly, $forceDisabled);
        break;
      case 'file':
        // Display upload field only if not readonly
        if (!$field['readonly']) {
          $html .= '<input type="file" ' . $attr . ' />';
        }
        // Display the download link, if the file available
        if (strlen($value) > 0) {
          $html .= '<p><a href="/wp-file-proxy.php?key=' . $value . '" target="_blank">' . sprintf(__('Datei "%s" herunterladen', 'lbwp'), File::getFileOnly($value)) . '</a></p>';
        }
        break;
    }

    return $html;
  }

  /**
   * @param array $field
   * @param string $key
   * @param bool $forceReadonly
   * @param bool $forceDisabled
   * @return string
   */
  protected function getCustomFieldTableHtml($field, $key, $value, $forceReadonly, $forceDisabled)
  {
    $html = '<table class="crmcf-table" data-key="' . $key . '" data-readonly="' . ($forceReadonly ? 1 : 0) . '" data-disabled="' . ($forceDisabled ? 1 : 0) . '">';

    // Get the field configuration of that table and print the columns
    $fields = $this->getTableColumnConfiguration($field);
    $html .= '<thead><tr>';
    foreach ($fields as $slug => $name) {
      $html .= '<td class="crmcf-head" data-slug="' . $slug . '">' . $name . '</td>';
    }
    $button = (!$forceReadonly) ? '<span class="dashicons dashicons-plus add-crmcf-row"></span>' : '';
    $html .= '<td class="crmcf-head">' . $button . '</td>';
    $html .= '</tr></thead><tbody>';

    // Print the current data if given
    if (is_array($value)) {
      reset($value);
      $first = key($value);
      for ($i = 0; $i < count($value[$first]); ++$i) {
        $html .= '<tr>';
        foreach ($fields as $slug => $name) {
          $attr = '';
          $attr .= $forceReadonly ? ' readonly="readonly"' : '';
          $attr .= $forceDisabled ? ' disabled="disabled"' : '';
          $html .= '<td><input type="text" name="' . $key . '[' . $slug . '][]" value="' . esc_attr($value[$slug][$i]) . '" ' . $attr . ' /></td>';
        }
        $html .= '<td class="crmcf-head"><span class="dashicons dashicons-trash delete-crmcf-row"></span></td></tr>';
      }
    }

    $html .= '</tbody></table>';
    return $html;
  }

  /**
   * @param $field
   * @return array
   */
  protected function getTableColumnConfiguration($field)
  {
    $config = array();
    foreach ($field['field-values'] as $colName) {
      $config[Strings::forceSlugString($colName)] = $colName;
    }
    return $config;
  }

  /**
   * Saves the custom user fields with loose validation
   */
  protected function saveCustomFieldData()
  {
    // Save the custom fields as given
    $customFields = $this->getCustomFields($this->editedUser->profileCategories);
    // Print the fields
    foreach ($customFields as $field) {
      $key = 'crmcf-' . $field['id'];
      // Need to be admin or have access to the field
      if ($this->userAdminData['userIsAdmin'] || (!$field['invisible'] && !$field['readonly'])) {
        // Get the previous value for determining a change
        $before = get_user_meta($this->editedUserId, $key, true);
        if ($field['type'] == 'file') {
          if ($_FILES[$key]['error'] == 0) {
            /** @var S3Upload $upload */
            $upload = LbwpCore::getModule('S3Upload');
            $url = $upload->uploadLocalFile($_FILES[$key], true);
            $file = $upload->getKeyFromUrl($url);
            $upload->setAccessControl($file, S3Upload::ACL_PRIVATE);
            // But actually save only the file name without asset key
            $after = str_replace(ASSET_KEY . '/files/', '', $file);
            update_user_meta($this->editedUserId, $key, $after);
          }
        } else {
          $after = $_POST[$key];
          if (isset($_POST[$key])) {
            update_user_meta($this->editedUserId, $key, $after);
          } else {
            delete_user_meta($this->editedUserId, $key);
          }
        }

        // If the value changed, track it
        if ($before != $after) {
          $tab = $this->configuration['tabs'][$field['tab']];
          $this->trackUserDataChange($field['title'], $tab, $before, $after);
        }
      }
    }
  }

  /**
   * Saves all the contacts of the profile
   */
  protected function saveContactData()
  {
    // No need to do anything, when there are no concats
    if (!isset($_POST['crm-contact-categories'])) {
      return;
    }

    // Save all given contacts
    $categories = array_map('intval', $_POST['crm-contact-categories']);

    // Go trough each category, validate inputs and save them
    foreach ($categories as $categoryId) {
      $key = 'crm-contacts-' . $categoryId;
      $oldContacts = get_user_meta($this->editedUserId, $key, true);
      $newContacts = $this->validateInputContacts($_POST[$key]);
      if (count($newContacts) > 0) {
        update_user_meta($this->editedUserId, $key, $newContacts);
      } else {
        delete_user_meta($this->editedUserId, $key);
      }

      // Now that they are saved, compare differences and track them
      $this->compareContactBlocks($categoryId, $oldContacts, $newContacts);
    }
  }

  /**
   * Syncs the user_email field with the email of the roles respective main contact
   */
  protected function syncMainContactEmail()
  {
    $role = $this->editedUser->roles[0];
    $key = 'crm-contacts-' . $this->configuration['mainContactMap'][$role];
    $contacts = get_user_meta($this->editedUserId, $key, true);

    // If there is an email, override the user object
    if (isset($contacts[0]) && Strings::checkEmail($contacts[0]['email'])) {
      // Need to update with DB, as we would create an endless loop with update_user functions
      $db = WordPress::getDb();
      $db->update(
        $db->users,
        array('user_email' => $contacts[0]['email']),
        array('ID' => $this->editedUserId)
      );
    }
  }

  /**
   * Sync a custom field with the user->display_name field
   */
  protected function syncDisplayName()
  {
    $key = $this->configuration['misc']['syncDisplayNameField'];
    if (strlen($key) > 0) {
      $db = WordPress::getDb();
      $db->update(
        $db->users,
        array('display_name' => get_user_meta($this->editedUserId, $key, true)),
        array('ID' => $this->editedUserId)
      );
    }
  }

  /**
   * Syncs some core fields to custom fields
   * @param int $userId
   */
  public function syncCoreToCustomFields($userId)
  {
    // Skip, if not an actual crm role
    if (!in_array($_POST['role'], $this->configuration['roles'])) {
      return;
    }

    if (isset($this->configuration['syncCoreFields'])) {
      // Map the post keys into the corresponding crm fields
      foreach ($this->configuration['syncCoreFields'] as $key => $field) {
        update_user_meta($userId, $field, $_POST[$key]);
      }
    }
  }

  /**
   * @paran int $category the category id
   * @param array $before contacts before
   * @param array $after contacts after save
   */
  protected function compareContactBlocks($category, $before, $after)
  {
    $category = self::getContactCategory($category);
    // Decide which array has more entries
    $c1 = count($before);
    $c2 = count($after);
    $max = ($c1 > $c2) ? $c1 : $c2;
    // Loop trough and compare each contact by stringifying them
    for ($i = 0; $i < $max;$i++) {
      $oldContact = $this->stringifyContact($before[$i]);
      $newContact = $this->stringifyContact($after[$i]);
      // If not the same, track the change (do compare without html)
      if (strip_tags($oldContact) != strip_tags($newContact)) {
        $this->trackUserDataChange($category['title'], __('Kontakte', 'lbwp'), $oldContact, $newContact);
      }
    }
  }

  /**
   * @param array $contact the contact information
   * @return string representation of the contact
   */
  protected function stringifyContact($contact)
  {
    // If the contact is invalid, return an empty string
    if (!is_array($contact) || count($contact) == 0) {
      return '';
    }

    // Translate the salutation if there is
    if (isset($contact['salutation'])) {
      $contact['salutation'] = $this->getSalutationByKey($contact['salutation']);
    }

    return implode('<br />', $contact);
  }

  /**
   * @param string $title the field/content that is being changed
   * @param string $category the category where data was saved
   * @param mixed $before the previous value before the change
   * @param mixed $after the new value after the change
   */
  protected function trackUserDataChange($title, $category, $before, $after)
  {
    $changes = ArrayManipulation::forceArray(get_option('crmLatestUserDataChanges'));

    // Create e new changes array for the user, if not given
    if (!isset($changes[$this->editedUserId])) {
      $changes[$this->editedUserId] = array();
    }

    // Add the change to the array
    $changes[$this->editedUserId][] = array(
      'field' => $title,
      'category' => $category,
      'time' => date('H:i', current_time('timestamp')),
      'before' => $before,
      'after' => $after,
      'author' => $this->editingUser->user_email
    );

    // Save back to our changes array
    update_option('crmLatestUserDataChanges', $changes);
  }

  /**
   * Send the changes report, if configured to do so
   */
  public function sendTrackedUserChangeReport()
  {
    $changes = ArrayManipulation::forceArray(get_option('crmLatestUserDataChanges'));
    $company = $this->configuration['misc']['titleOverrideField'];

    // If there's no report email to send to, just reset the option and leave
    if (count($this->configuration['misc']['dataReportEmails']) == 0) {
      update_option('crmLatestUserDataChanges', array());
      return false;
    }

    // Prepare the html for the report
    $html = '';
    foreach ($changes as $id => $items) {
      if (count($items) > 0) {
        // Print the member name
        $name = get_user_meta($id, $company, true);
        $html .= '<h4>' . sprintf(__('Änderungen bei %s', 'lbwp'), $name) . '</h4>';
        $html .= '
          <table style="width:100%;" width="100%">
            <tr>
              <td style="width:5%;border-bottom:2px solid #bbb" width="5%"><strong>' . __('Uhrzeit', 'lbwp') . '</strong></td>
              <td style="width:20%;border-bottom:2px solid #bbb" width="10%"><strong>' . __('Änderung in', 'lbwp') . '</strong></td>
              <td style="width:30%;border-bottom:2px solid #bbb" width="10%"><strong>' . __('Bisher', 'lbwp') . '</strong></td>
              <td style="width:30%;border-bottom:2px solid #bbb" width="10%"><strong>' . __('Neu', 'lbwp') . '</strong></td>
              <td style="width:15%;border-bottom:2px solid #bbb" width="15%"><strong>' . __('Autor', 'lbwp') . '</strong></td>
            </tr>
        ';
        // Print all the changes to the member
        foreach ($items as $change) {
          $html .= '
            <tr>
              <td style="border-bottom:1px solid #999">' . $change['time'] . '</td>
              <td style="border-bottom:1px solid #999">' . $change['category'] . ' > ' . $change['field'] . '</td>
              <td style="border-bottom:1px solid #999">' . $change['before'] . '</td>
              <td style="border-bottom:1px solid #999">' . $change['after'] . '</td>
              <td style="border-bottom:1px solid #999">' . $change['author'] . '</td>
            </tr>
          ';
        }
        $html .= '</table><br>';
      }
    }

    // Send the email
    if (strlen($html) > 0) {
      $mail = External::PhpMailer();
      $mail->Subject = __('Änderungen von Mitglieder in den letzten 24h - ' . LBWP_HOST, 'lbwp');
      $mail->Body = $html;
      // Add recipients
      foreach ($this->configuration['misc']['dataReportEmails'] as $email) {
        $mail->addAddress($email);
      }
      // Send the mail
      $mail->send();
    }

    // After sending, reset the array with en empty one
    update_option('crmLatestUserDataChanges', array());
  }

  /**
   * @param array $candidates list of contact candidates to be saved from POST
   * @return array validated (hence maybe empty) list of contacts
   */
  protected function validateInputContacts($candidates)
  {
    $contacts = array();
    $countKey = array_keys($candidates)[0];
    for ($i = 0; $i < count($candidates[$countKey]); ++$i) {
      $contact = array();
      foreach (array_keys($candidates) as $key) {
        $contact[$key] = $candidates[$key][$i];
      }
      $contacts[] = $contact;
    }

    return $contacts;
  }

  /**
   * @return string html for the profile categories editor
   */
  protected function getProfileCategoriesEditor()
  {
    // Get current profile categories
    $categories = self::getProfileCategoryList();
    $current = $this->editedUser->profileCategories;

    // Edit or readonly screens for members
    if ($this->userAdminData['userIsAdmin']) {
      $html = '<select name="profileCategories[]" id="profileCategories" multiple="multiple">';
      foreach ($categories as $category) {
        $selected = in_array($category->ID, $current) ? ' selected="selected"' : '';
        $html .= '<option value="' . $category->ID . '"' . $selected . '>' . $category->post_title . '</option>';
      }
      $html .= '</selected>';
    } else {
      $html = '<ul class="profile-category-list">';
      foreach ($categories as $category) {
        if (in_array($category->ID, $current)) {
          $html .= '<li>' . $category->post_title . '</li>';
        }
      }
      $html .= '</ul>';
    }

    // Print the output and UI
    return '
      <table class="form-table" data-target-tab="main">
	      <tbody>
	        <tr class="profile-categories-wrap">
            <th><label for="profile_categories">Zugewiesene Kategorien</label></th>
            <td>' . $html . '</td>
	        </tr>
        </tbody>
      </table>
    ';
  }

  /**
   * @return string html to disable a member
   */
  protected function getDisableMemberEditor()
  {
    $checked = checked(get_user_meta($this->editedUserId, 'member-disabled', true), 1, false);
    // Print the output and UI
    return '
      <table class="form-table" data-target-tab="main">
	      <tbody>
	        <tr class="disable-member-wrap">
            <th><label for="disable-member">Status</label></th>
            <td>
              <label>
                <input type="checkbox" id="disable-member" name="disableMember" value="1" ' . $checked . ' /> Mitglied ist deaktiviert
              </label>
            </td>
	        </tr>
        </tbody>
      </table>
    ';
  }

  /**
   * @return string the contacts editor html output
   */
  protected function getProfileContactsEditor()
  {
    $html = '';
    $contactCategories = array();
    $current = ArrayManipulation::forceArray(get_user_meta($this->editedUserId, 'profile-categories', true));
    foreach ($current as $profileCategoryId) {
      $categories = get_post_meta($profileCategoryId, 'contact-categories');
      $contactCategories = array_unique(array_merge($contactCategories, $categories));
    }

    // Make sure there are no empty values in it after merging
    $contactCategories = array_filter($contactCategories);
    // Sort by number so that topmost IDs are top
    sort($contactCategories, SORT_NUMERIC);

    $sortedCategories = array();
    foreach ($contactCategories as $categoryId) {
      $category = $this->getContactCategory($categoryId);
      if ($category['visible']) {
        $sortedCategories[] = $category;
      }
    }

    // Order by sort
    usort($sortedCategories, function($a, $b) {
      if ($a['sort'] > $b['sort']) {
        return 1;
      } else if ($a['sort'] < $b['sort']) {
        return -1;
      }
      return 0;
    });

    // Get the contact editing screen for all the categories
    $index = 0;
    foreach ($sortedCategories as $category) {
      $html .= $this->getContactsEditorHtml($category, ++$index);
    }

    return $html;
  }

  /**
   * @param array $category the category object array
   * @return string the html container for the contacts editor
   */
  protected function getContactsEditorHtml($category, $index)
  {
    $html = '';
    $key = 'crm-contacts-' . $category['id'];
    $contacts = ArrayManipulation::forceArray(get_user_meta($this->editedUserId, $key, true));

    // If no contacts and the user can't add any, don't show the editor at all
    if (count($contacts) == 0 && !$category['add']) {
      return '';
    }

    // Display the add button only, if adding is allowed
    $addBtn = $category['add'] ?
      '<a href="javascript:void(0)" class="button add-contact">Kontakt hinzufügen</a>' : '';
    $mainContactBtn = $this->userAdminData['userIsAdmin'] && $index > 1 ?
      '<a href="javascript:void(0)" class="button copy-main-contact">' . $this->configuration['misc']['copyMainContact'] . '</a>' : '';
    $delBtn = $category['delete'] ?
      '<a href="javascript:void(0)" class="dashicons dashicons-trash delete-contact"></a>' : '';
    // Some fields are only required if neutral is not allowed
    $required = $category['allow-neutral'] ? '' : ' required="required"';
    $emailRequired = $category['optional-email'] ? '' : ' required="required"';

    $cfHeadings = '';
    if (!in_array('salutation', $category['hiddenfields']))
      $cfHeadings .= '<th class="th-salutation">Anrede</th>';
    if (!in_array('firstname', $category['hiddenfields']))
      $cfHeadings .= '<th class="th-firstname">Vorname</th>';
    if (!in_array('lastname', $category['hiddenfields']))
      $cfHeadings .= '<th class="th-lastname">Nachname</th>';
    if (!in_array('email', $category['hiddenfields']))
      $cfHeadings .= '<th class="th-email">E-Mail-Adresse</th>';
    foreach ($category['fields'] as $field) {
      $cfKey = Strings::forceSlugString($field);
      $cfHeadings .= '<th class="contact-custom-field th-' . $cfKey . '" data-cfkey="' . $cfKey . '">' . $field . '</th>';
    }

    // Display available contacts
    if (count($contacts) > 0) {
      foreach ($contacts as $contact) {
        $html .= '<tr>';
        // See what core fields we actually need
        if (!in_array('salutation', $category['hiddenfields'])) {
          $html .= '<td><select name="' . $key . '[salutation][]">' . $this->getSalutationOptions($category['allow-neutral'], $contact['salutation']) . '</select></td>';
        }
        if (!in_array('firstname', $category['hiddenfields'])) {
          $html .= '<td><input type="text" name="' . $key . '[firstname][]" ' . $required . ' value="' . esc_attr($contact['firstname']) . '" /></td>';
        }
        if (!in_array('lastname', $category['hiddenfields'])) {
          $html .= '<td><input type="text" name="' . $key . '[lastname][]" ' . $required . ' value="' . esc_attr($contact['lastname']) . '" /></td>';
        }
        if (!in_array('email', $category['hiddenfields'])) {
          $html .= '<td><input type="text" name="' . $key . '[email][]" ' . $emailRequired . ' value="' . esc_attr($contact['email']) . '" /></td>';
        }

        // Additional fields if available
        foreach ($category['fields'] as $field) {
          $cfKey = Strings::forceSlugString($field);
          $html .= '<td><input type="text" name="' . $key . '[' . $cfKey . '][]" value="' . esc_attr($contact[$cfKey]) . '" /></td>';
        }

        // Delete button and close row
        $html .= '
            <td>' . $delBtn . '</td>
          </tr>
        ';
      }
    } else {
      // If no contacts yet, provide fields to add one directly without clicking
      $html .= '
        <tr class="no-contacts">
          <td colspan="5">' . __('Es sind noch keine Kontakte in dieser Kategorie vorhanden.', 'lbwp') . '</td>
        </tr>
      ';
    }

    // Return this within a little container and template
    return '
      <div class="contact-editor-container" 
        data-target-tab="' . $category['tab'] . '"
        data-input-key="' . $key . '"
        data-hidden-fields="' . esc_attr(json_encode($category['hiddenfields'])) . '"
        data-max-contacts="' . intval($category['max-contacts']) . '"
        data-min-contacts="' . intval($category['min-contacts']) . '"
        data-allow-delete="' . ($category['delete'] ? '1' : '0') . '"
        data-allow-neutral="' . ($category['allow-neutral'] ? '1' : '0') . '"
        data-optional-email="' . ($category['optional-email'] ? '1' : '0') . '"
        >
        <h4>
          ' . $category['title'] . '
          <span class="description contact-help">
            <span class="dashicons dashicons-editor-help"></span>
            <label>' . $category['description'] . '</label>
          </span>
        </h4>
        
        <div class="contact-table-container">
          <table class="widefat contact-table">
            <thead>
              <tr>
                ' . $cfHeadings . '
                <th class="th-buttons">&nbsp;</th>
              </tr>
            </thead>
            <tbody>
              ' . $html . '
            </tbody>
          </table>
          ' . $addBtn . ' ' . $mainContactBtn . '
          <input type="hidden" name="crm-contact-categories[]" value="' . $category['id'] . '" />
        </div>
      </div>
    ';
  }

  /**
   * Provides HTML block for the crm field history
   */
  public function getCrmFieldHistory()
  {
    $id = intval(str_replace('crmcf-', '', $_POST['key']));
    $field = $this->getCustomFieldById($id);
    // Check if the history can be displayed
    if (!$this->userAdminData['userIsAdmin'] || !$this->isHistoryField($field)) {
      WordPress::sendJsonResponse(array(
        'success' => false
      ));
    }

    // Initialize the html for the field
    $html = '<table class="crmcf-history-table">';
    // First, make sure the history is in reverse order (Starting with the latest
    $versions = array_reverse($field['versions']);
    $html .= '<thead><tr>';
    foreach ($versions as $version) {
      $html .= '<th>' . $version . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($versions as $i => $version) {
      $key = 'crmcf-' .  $id;
      $readonly = false;
      if ($i > 0) {
        $key .= '_' . $version;
        $readonly = true;
      }
      $html .= '<td>' . $this->getCustomFieldContent($field, $key, $readonly, $readonly) . '</td>';
    }

    $html .= '</tbody></table>';


    // Send the generated html that represents all versions of the field
    WordPress::sendJsonResponse(array(
      'success' => true,
      'html' => $html
    ));
  }

  /**
   * @param array $field the custom field
   * @return bool true if the field is a history field
   */
  public function isHistoryField($field)
  {
    return (is_array($field) && $field['history-active']);
  }

  /**
   * @param int $categoryId a category id
   * @return array the contact object
   */
  public function getContactCategory($categoryId)
  {
    $raw = get_post($categoryId);
    $admin = $this->userAdminData['userIsAdmin'];

    return array(
      'id' => $categoryId,
      'title' => $raw->post_title,
      'description' => get_post_meta($categoryId, 'description', true),
      'sort' => intval(get_post_meta($categoryId, 'sort', true)),
      'fields' => array_filter(get_post_meta($categoryId, 'custom-fields')),
      'tab' => get_post_meta($categoryId, 'tab', true),
      'visible' =>  $admin || get_post_meta($categoryId, 'cap-read', true) == 'on',
      'edit' => $admin || get_post_meta($categoryId, 'cap-edit', true) == 'on',
      'delete' => $admin || get_post_meta($categoryId, 'cap-delete', true) == 'on',
      'add' => $admin || get_post_meta($categoryId, 'cap-add', true) == 'on',
      'allow-neutral' => get_post_meta($categoryId, 'neutral-salutation', true) == 'on',
      'optional-email' => get_post_meta($categoryId, 'optional-email', true) == 'on',
      'hiddenfields' => array_filter(get_post_meta($categoryId, 'hidden-fields')),
      'max-contacts' => intval(get_post_meta($categoryId, 'max-contacts', true)),
      'min-contacts' => intval(get_post_meta($categoryId, 'min-contacts', true))
    );
  }

  /**
   * @param bool $allowNeutral allows neutral salutations
   * @param string $value preselect this value, if given
   * @return string html options
   */
  protected function getSalutationOptions($allowNeutral, $value)
  {
    $html = '';
    $options = array(
      'male' => __('Herr', 'lbwp'),
      'female' => __('Frau', 'lbwp'),
    );
    // Add neutral option, if needed
    if ($allowNeutral) {
      $options['neutral'] = __('Neutral', 'lbwp');
    }

    // Produce html dom elements
    foreach ($options as $key => $name) {
      $selected = selected($key, $value, false);
      $html .= '<option value="' . $key . '"' . $selected . '>' . $name . '</option>';
    }

    return $html;
  }

  /**
   * Save the member main data, contacts and custom fields
   */
  public function saveMemberData($userId)
  {
    // Save the user profile categories
    if (is_array($_POST['profileCategories'])) {
      $categories = array_map('intval', $_POST['profileCategories']);
      update_user_meta($userId, 'profile-categories', $categories);
    }

    // Member disablement
    if (isset($_POST['disableMember']) && $_POST['disableMember'] == 1) {
      update_user_meta($userId, 'member-disabled', 1);
    } else {
      delete_user_meta($userId, 'member-disabled');
    }

    // Make sure to flush segment caching
    $this->flushContactCache();
  }

  /**
   * Flushes the full contact cache list (esp. on saving contacts or their profile categories)
   */
  protected function flushContactCache()
  {
    wp_cache_delete('fullContactList', 'CrmCore');
  }

  /**
   * Invoke the scripts and data provision for member admin
   */
  public function invokeMemberAdminScripts()
  {
    $screen = get_current_screen();
    if ($screen->base == 'user-edit' || $screen->base == 'profile' || $screen->base == 'users_page_crm-export') {
      $uri = File::getResourceUri();
      // Include usage of chosen
      wp_enqueue_script('jquery-cookie');
      wp_enqueue_script('chosen-js');
      wp_enqueue_style('chosen-css');
      wp_enqueue_style('jquery-ui-theme-lbwp');
      // And some custom library outputs
      echo '
        <script type="text/javascript">
          var crmAdminData = ' . json_encode($this->userAdminData) . ';
        </script>
        <script src="' . $uri . '/js/lbwp-crm-backend.js?v1.1" type="text/javascript"></script>
        <link rel="stylesheet" href="' . $uri . '/css/lbwp-crm-backend.css?v1.1">
      ';
    }
  }

  /**
   * @param \WP_User_Query $query
   */
  public function invokeUserTableQuery($query)
  {
    if (!isset($_GET['order']) && !isset($_GET['orderby'])) {
      $query->set('orderby', 'display_name');
      $query->set('order', 'ASC');
    }
  }

  /**
   * Adds various columns to custom types tables
   */
  protected function addAdminTableColumns()
  {
    add_filter('manage_users_columns', array($this, 'addUserTableColumnHeader'));
    add_action('manage_users_custom_column', array($this, 'addUserTableColumnCell'), 10, 3);
    add_action('restrict_manage_users', array($this, 'restrictUserTableFilter'));
    add_filter('users_list_table_query_args', array($this, 'userTableFilterByStatus'));

    // For profile categories
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_PROFILE_CAT,
      'meta_key' => 'contact-categories',
      'column_key' => self::TYPE_PROFILE_CAT . '_contact-categories',
      'single' => false,
      'heading' => __('Verknüpfte Kontaktarten', 'lbwp'),
      'callback' => function($value, $postId) {
        $categories = array();
        foreach ($value as $categoryId) {
          $categories[] = get_post($categoryId)->post_title;
        }
        echo implode(', ', $categories);
      }
    ));

    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_PROFILE_CAT,
      'column_key' => self::TYPE_PROFILE_CAT . '_id',
      'single' => true,
      'heading' => __('ID', 'lbwp'),
      'callback' => function($key, $postId) {
        echo $postId;
      }
    ));

    // For contact categories
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_CONTACT_CAT,
      'meta_key' => 'max-contacts',
      'column_key' => self::TYPE_CONTACT_CAT . '_max-contacts',
      'single' => true,
      'heading' => __('Max. Anz. Kontakte', 'lbwp')
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_CONTACT_CAT,
      'meta_key' => 'neutral-salutation',
      'column_key' => self::TYPE_CONTACT_CAT . '_neutral-salutation',
      'single' => true,
      'heading' => __('Neutr. Anrede', 'lbwp'),
      'callback' => function($value, $postId) {
        echo ($value == 'on') ? __('Erlaubt', 'lbwp') : __('Nicht erlaubt', 'lbwp');
      }
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_CONTACT_CAT,
      'column_key' => self::TYPE_CONTACT_CAT . '_id',
      'single' => true,
      'heading' => __('ID', 'lbwp'),
      'callback' => function($key, $postId) {
        echo $postId;
      }
    ));

    // For custom fields
    $types = $this->getCustomFieldTypes();
    $categories = $this->getSelectableProfileCategories();
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_FIELD,
      'meta_key' => 'type',
      'column_key' => self::TYPE_FIELD . '_type',
      'single' => true,
      'heading' => __('Feld-Typ', 'lbwp'),
      'callback' => function($value, $postId) use ($types) {
        echo $types[$value];
      }
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_FIELD,
      'meta_key' => 'profiles',
      'column_key' => self::TYPE_FIELD . '_profiles',
      'single' => false,
      'heading' => __('Verfügbar für', 'lbwp'),
      'callback' => function($values, $postId) use ($categories) {
        $display = array();
        foreach ($values as $key) {
          $display[] = $categories[$key];
        }
        echo implode('<br>', $display);
      }
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_FIELD,
      'meta_key' => 'tab',
      'column_key' => self::TYPE_FIELD . '_tab',
      'single' => true,
      'heading' => __('Anzeige im Tab', 'lbwp'),
      'callback' => function($key, $postId) {
        echo $this->configuration['tabs'][$key];
      }
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::TYPE_FIELD,
      'column_key' => self::TYPE_FIELD . '_id',
      'single' => true,
      'heading' => __('ID', 'lbwp'),
      'callback' => function($key, $postId) {
        echo $postId;
      }
    ));
  }



  /**
   * @param $columns
   * @return mixed
   */
  public function addUserTableColumnHeader($columns)
  {
    // Add the status, and remove the count posts
    $columns['crm-status'] = 'Status';
    unset($columns['posts']);

    // Also, add custom configured custom fields
    if (isset($this->configuration['customUserColumns'])) {
      foreach ($this->configuration['customUserColumns'] as $field => $name) {
        $columns[$field] = $name;
      }
    }

    return $columns;
  }

  /**
   * @param mixed $value
   * @param string $field
   * @param int $userId
   */
  public function addUserTableColumnCell($value, $field, $userId)
  {
    // Check for a custom field
    if (Strings::startsWith($field, 'crmcf-')) {
      $value = get_user_meta($userId, $field, true);
    }

    // If it is the status
    if ($field == 'crm-status') {
      $value = __('Aktiv', 'lbwp');
      if (get_user_meta($userId, 'member-disabled', true) == 1) {
        $value = '<em>' . __('Inaktiv', 'lbwp') . '<em>';
      }
    }

    return $value;
  }

  /**
   * Make the user able to filter by active state
   */
  public function restrictUserTableFilter()
  {
    $current = $_GET['status-filter'];
    echo '
      <select name="status-filter" id="status-filter" style="display:none;margin:0px 15px 4px 0px;">
        <option value="">Aktive & Inaktive anzeigen</option>
        <option value="active" ' . selected($current, 'active', false) . '>Nur Aktive anzeigen</option>
        <option value="inactive" ' . selected($current, 'inactive', false) . '>Nur Inaktive anzeigen</option>
      </select>
      <script type="text/javascript">
        jQuery(function() {
          // Move and re-style
          var dropdown = jQuery("#status-filter");
          jQuery(".tablenav-pages").prepend(dropdown);
          dropdown = jQuery("#status-filter");
          dropdown.css("display", "inline");
          // Add functionality
          dropdown.on("change", function() {
            document.location.href = "/wp-admin/users.php?status-filter=" + jQuery(this).val();
          });
        });
      </script>
    ';
  }

  /**
   * Add query arguments if needed
   */
  public function userTableFilterByStatus($args)
  {
    if (isset($_GET['status-filter']) && strlen($_GET['status-filter']) > 0) {
      $args['meta_query'] = array();
      if ($_GET['status-filter'] == 'active') {
        $args['meta_query'][] = array(
          'key' => 'member-disabled',
          'compare' => 'NOT EXISTS'
        );
      } else if ($_GET['status-filter'] == 'inactive') {
        $args['meta_query'][] = array(
          'key' => 'member-disabled',
          'value' => 1,
          'compare' => '='
        );
      }
    }

    return $args;
  }

  /**
   * There are some menus that can be hidden by caps, so we need to
   * Remove them from the menu global before they are shown
   */
  public function hideMenusFromMembers()
  {
    if ($this->currentIsMember()) {
      global $menu;
      foreach ($menu as $key => $item) {
        if ($item[2] == 'index.php' || $item[2] == 'comotive-newsletter') {
          unset($menu[$key]);
        }
        if ($item[4] == 'wp-menu-separator') {
          unset($menu[$key]);
        }
      }
    }
  }

  /**
   * @param int $userId
   * @return bool true if the user is a member
   */
  public function isMember($userId)
  {
    if ($userId == $this->editedUserId) {
      return in_array(
        $this->editedUser->roles[0],
        $this->configuration['roles']
      );
    } else {
      return in_array(
        get_user_by('id', $userId)->roles[0],
        $this->configuration['roles']
      );
    }
  }

  /**
   * @return bool tells if the user is a backend member
   */
  public function currentIsMember()
  {
    $userId = get_current_user_id();
    return $this->isMember($userId);
  }

  /**
   * If a user goes to his dashboard (which shouldn't happen) redirect to profile
   */
  public function preventUserOnDashboard()
  {
    if ($this->userAdminData['userIsMember']) {
      $screen = get_current_screen();
      if ($screen->base == 'dashboard') {
        header('Location: ' . get_admin_url() . 'profile.php', null, 301);
        exit;
      }
    }
  }

  /**
   * Let the user select a group source for that segment
   * @param Metabox $helper the meta box helper object
   * @param string $boxId the box id tu use
   * @param int $postId the post id, if needed
   */
  public function addMemberMetabox($helper, $boxId, $postId)
  {
    $selection = array(0 => 'Alle Profilkategorien');
    $profileCategories = self::getProfileCategoryList();
    foreach ($profileCategories as $category) {
      $selection[$category->ID] = $category->post_title;
    }
    $helper->addDropdown('profile-category', $boxId, 'Profilkategorie', array(
      'items' => $selection
    ));

    $selection = array();
    $contactCategories = self::getContactCategoryList();
    foreach ($contactCategories as $category) {
      $selection[$category->ID] = $category->post_title;
    }
    $helper->addDropdown('contact-category', $boxId, 'Kontaktart', array(
      'items' => $selection
    ));

  }

  /**
   * @param array $data the initially empty array to be filled
   * @param int $listId the list id
   * @return array a possibly filled data array
   */
  public function getSegmentData($data, $listId)
  {
    $contactCategoryId = get_post_meta($listId, 'contact-category', true);
    $profileCategoryId = get_post_meta($listId, 'profile-category', true);
    // Get the contact lists, already broken down to a category
    $data = $this->getContactsByCategory($contactCategoryId);

    // Filter by profile category, if needed
    if ($profileCategoryId > 0) {
      $data = array_filter($data, function($contact) use ($profileCategoryId) {
        return in_array($profileCategoryId, $contact['profile-categories']);
      });
    }

    // Remove the profile-categories element from the arrays (as not needed in segment)
    foreach ($data as $key => $contact) {
      unset($data[$key]['profile-categories']);
    }

    return $data;
  }

  /**
   * @param $user
   * @return mixed
   */
  public function preventDisabledUserLogin($user)
  {
    if (in_array($user->ID, $this->getInactiveUserIds())) {
      // This has a comment in it so that CleanUp module can display the message
      $user = new \WP_Error(
        'authentication_prevented',
        __('Ihr Benutzerkonto ist im Moment deaktiviert. <!--authentication-prevented-->', 'lbwp')
      );
    }

    return $user;
  }

  /**
   * @return array list of contacts of a specified category, contains profile categories of the assigned members
   */
  protected function getContactsByCategory($categoryId)
  {
    $contacts = wp_cache_get('fullContactList', 'CrmCore');
    // Build a new list, if not in cache
    if (!is_array($contacts)) {
      $db = WordPress::getDb();
      $contacts = array();
      $fields = $this->getCustomFields(false);
      $disabledUsers = $this->getInactiveUserIds();
      // Reduce this to segmentation fields
      $segmentFields = array();
      foreach ($fields as $field) {
        if ($field['segmenting-active'] && strlen($field['segmenting-slug']) > 0) {
          $segmentFields['crmcf-' . $field['id']] = Strings::forceSlugString($field['segmenting-slug']);
        }
      }

      // Get all the contact categories to access user meta fields
      foreach (self::getContactCategoryList() as $category) {
        $contacts[$category->ID] = array();
        // Get all Contacts of that category
        $sql = 'SELECT user_id, meta_value FROM {sql:userMeta} WHERE meta_key = {contactKey}';
        $raw = $db->get_results(Strings::prepareSql($sql, array(
          'userMeta' => $db->usermeta,
          'contactKey' => 'crm-contacts-' . $category->ID
        )));

        // Attach member profile categories to each contact for lter filtering
        foreach ($raw as $result) {
          // If the user is disabled, skip it
          if (in_array($result->user_id, $disabledUsers)) {
            continue;
          }
          // Unserialize and merge
          $userContacts = maybe_unserialize($result->meta_value);
          $metaFields = array(
            'userid' => $result->user_id,
            'profile-categories' => get_user_meta($result->user_id, 'profile-categories', true),
          );
          // Get user fields, that are configured to be accessed in mail segments
          foreach ($segmentFields as $metaKey => $segmentKey) {
            $metaFields[$segmentKey] = get_user_meta($result->user_id, $metaKey, true);
          }
          // Integrate all contacts into the main array
          foreach ($userContacts as $contact) {
            $contact['salutation'] = $this->getSalutationByKey($contact['salutation']);
            foreach ($metaFields as $key => $value) {
              $contact[$key] = $value;
            }
            $contacts[$category->ID][] = $contact;
          }
        }
      }

      // Save to cache for next time fast use
      wp_cache_set('fullContactList', $contacts, 'CrmCore');
    }

    // Reduce the array to the desired group
    if (isset($contacts[$categoryId])) {
      return $contacts[$categoryId];
    }

    // If category doesn't exist, return an empty array
    return array();
  }

  /**
   * Add Submenu to users to add a crm export feature
   */
  public function addExportView()
  {
    add_submenu_page(
      'users.php',
      'CRM Export',
      'CRM Export',
      'administrator',
      'crm-export',
      array($this, 'displayExportView')
    );
  }

  /**
   * Display the export UI (which is simple for the moment
   */
  public function displayExportView()
  {
    $html = '';
    // Maybe export data, if needed
    $this->runExportView();
    // Get role names to map
    $roles = WordPress::getRoles();
    $roles = $roles->get_names();

    // Functions / UI to create data exports for members
    $html .= '<div><label class="field-description">Rolle:</label>  <select name="field-role">';
    foreach ($this->configuration['roles'] as $key) {
      $html .= '<option value="' . $key . '">' . $roles[$key] . '</option>"';
    }
    $html .= '
      </select></div>
      <div>
        <label class="field-description">Status:</label>
        <span>
          <label>
            <input type="radio" name="member-status" value="active" checked="checked"> Nur Aktive
          </label>
          <label>
            <input type="radio" name="member-status" value="inactive"> Nur Inaktive
          </label>
          <label>
            <input type="radio" name="member-status" value="all"> Alle
          </label>
        </span>
      </div>
      <div>
        <label class="field-description">Versionierte Felder:</label>
        <label>
          <input type="checkbox" name="use-history" value="1"> Alle Versionen exportieren 
        </label>
      </div>
      <input type="submit" class="button-primary" name="field-export" value="Daten-Export starten" />
      
      <hr>
    ';

    // Functions / UI to create contact exports for members
    $html .= '<div><label class="field-description">Rolle:</label>  <select name="contact-role">';
    foreach ($this->configuration['roles'] as $key) {
      $html .= '<option value="' . $key . '">' . $roles[$key] . '</option>';
    }

    $html .= '</select></div><div><label class="field-description">Kontaktart:</label><select name="contact-category">';
    foreach (self::getContactCategoryList() as $category) {
      $html .= '<option value="' . $category->ID . '">' . $category->post_title . '</option>';
    }
    $html .= '
      </select></div>
      <input type="submit" class="button-primary" name="contact-export" value="Kontakte-Export starten" />
    ';

    // Print the wrapper and html
    echo '
      <div class="wrap">
        <h1 class="wp-heading-inline">CRM Export</h1>
        <p>
          Es stehen Ihnen für den Moment grundlegende Export-Funktionen zur Verfügung.<br>
          Funktionen um feiner granuliertere Exporte zu erzeugen folgen in einem späteren Release.
        </p>
        <hr class="wp-header-end">
        <form method="post">
          ' . $html . '
        </form>
        <br class="clear">
      </div>
    ';
  }

  /**
   * Run export if desired
   */
  protected function runExportView()
  {
    // Do a full data export
    if (isset($_POST['field-export']) && isset($_POST['field-role'])) {
      $role = Strings::forceSlugString($_POST['field-role']);
      $this->downloadFieldExport($role);
    }

    // Do a contact list export
    if (isset($_POST['contact-export']) && isset($_POST['contact-role'])) {
      $role = Strings::forceSlugString($_POST['contact-role']);
      $category = intval($_POST['contact-category']);
      $this->downloadContactExport($role, $category);
    }
  }

  /**
   * @param string $role the role to export field data from
   */
  protected function downloadFieldExport($role)
  {
    // Are we exporting history
    $history = intval($_POST['use-history']) == 1;
    // See if we need to get inactives
    $inactives = false;
    if ($_POST['member-status'] != 'active') {
      $inactives = true;
    }

    // Get all members and all fields to prepare for the export
    $members = $this->getMembersByRole($role, 'display_name', 'ASC', $inactives);
    $inactives = $this->getInactiveUserIds();
    $fields = $this->getCustomFields(false);

    // If we only show inactives, sort out all active members
    if ($_POST['member-status'] == 'inactive') {
      foreach ($members as $key => $member) {
        if (!in_array($member->ID, $inactives)) {
          unset($members[$key]);
        }
      }
    }

    // Begin output data array
    $data = array('columns' => array('Status'));

    // Create a heading column
    foreach ($fields as $field) {
      if ($history && $field['history-active']) {
        foreach (array_reverse($field['versions']) as $version) {
          $data['columns'][] = $field['title'] . ' ' . $version;
        }
      } else {
        $data['columns'][] = $field['title'];
      }
    }

    // Now for each member, create a new row
    foreach ($members as $member) {
      $row = array();
      $row[] = in_array($member->ID, $inactives) ? 'Inaktiv' : 'Aktiv';
      foreach ($fields as $field) {
        if ($history && $field['history-active']) {
          foreach (array_reverse($field['versions']) as $id => $version) {
            // If the version is not the newest, add the suffix to our key
            $key = 'crmcf-' . $field['id'];
            if ($id > 0) $key .= '_' . $version;
            $row[] = get_user_meta($member->ID, $key, true);
          }
        } else {
          $row[] = get_user_meta($member->ID, 'crmcf-' . $field['id'], true);
        }

      }
      $data[] = $row;
    }

    $file = 'daten-export-' . date('Y-m-d') . '.csv';
    Csv::downloadFile($data, $file);
  }

  /**
   * @param string $role the role to export contacts of
   * @param int $category the contact category we need to get
   */
  protected function downloadContactExport($role, $category)
  {
    $members = $this->getMembersByRole($role);
    $category = self::getContactCategory($category);

    // Begin output data array
    $data = array('columns' => array());
    $columns = array();

    // Add the custom fields in front
    foreach ($this->configuration['export']['contact-fields'] as $key => $value) {
      $data['columns'][] = $value;
    }
    // Now add the basic core fields
    $fields = array(
      'salutation' => 'Anrede',
      'firstname' => 'Vorname',
      'lastname' => 'Nachname',
      'email' => 'E-Mail',
    );
    // Subtract the hidden fields
    foreach ($category['hiddenfields'] as $field) {
      unset($fields[$field]);
    }
    foreach ($fields as $key => $value) {
      $columns[] = $key;
      $data['columns'][] = $value;
    }
    // Finally, add the custom columns
    foreach ($category['fields'] as $field) {
      $columns[] = Strings::forceSlugString($field);
      $data['columns'][] = $field;
    }

    // Now go on with the data per member
    foreach ($members as $member) {
      // Create the basic row (custom fields)
      $base = array();
      foreach ($this->configuration['export']['contact-fields'] as $key => $value) {
        $base[] = get_user_meta($member->ID, $key, true);
      }

      // Get all contacts for that member
      $contacts = get_user_meta($member->ID, 'crm-contacts-' . $category['id'], true);
      // Create a row per contact
      foreach ($contacts as $contact) {
        $row = $base;
        // Fill empty cells with key unknown
        $fixed = array();
        // Maintain correct sort order
        foreach ($columns as $column) {
          if (!isset($contact[$column])) {
            $fixed[] = '';
          } else {
            if ($column == 'salutation') {
              $fixed[] = $this->getSalutationByKey($contact[$column]);
            } else {
              $fixed[] = $contact[$column];
            }
          }
        }
        // Finaly pull it into our data stream
        foreach ($fixed as $value) {
          $row[] = $value;
        }
        $data[] = $row;
      }
    }

    $file = 'kontakt-export-' . date('Y-m-d') . '.csv';
    Csv::downloadFile($data, $file);
  }

  /**
   * @param $value
   * @return mixed
   */
  public function configureAutoLoginLink($value)
  {
    $value = $this->configuration['misc']['autologinValidity'];
    return $value;
  }

  /**
   * @param string $key
   * @return string
   */
  protected function getSalutationByKey($key)
  {
    switch ($key) {
      case 'male': return 'Sehr geehrter Herr';
      case 'female': return 'Sehr geehrte Frau';
      default: return 'Sehr geehrte Damen und Herren';
    }
  }

  /**
   * Saves versions, if a new one is added, the previous one is archived
   * @param int $postId
   * @param array $field
   * @param string $boxId
   * @return array|string
   */
  public function saveCustomFieldVersion($postId, $field, $boxId)
  {
    // Validate and save the field
    $new = $_POST[$postId . '_' . $field['key']];
    $prev = get_post_meta($postId, $field['key']);

    // See if the tables have turned (contents have changed)
    if (serialize($new) == serialize($prev) || !is_array($new)) {
      // Return, nothing to save
      return $new;
    }

    // Build an array of key (version) and change (same, archive, new)
    $newVersionId = count(array_keys($new)) - 1;
    $archiveVersionId = ($newVersionId - 1);
    $version = $new[$archiveVersionId];

    // Archive the old version by renaming the meta fields in db
    if (count($new) > 1 && strlen($version) > 0) {
      $db = WordPress::getDb();
      $sql = 'UPDATE {sql:userMeta} SET meta_key = {versionedKey} WHERE meta_key = {currentKey}';
      $db->query(Strings::prepareSql($sql, array(
        'userMeta' => $db->usermeta,
        'currentKey' => 'crmcf-' . $postId,
        'versionedKey' => 'crmcf-' . $postId . '_' . $version
      )));

      // Flush all user-like caches asynchronously
      MemcachedAdmin::flushByKeyword('*user_meta_*');
      MemcachedAdmin::flushByKeyword('*users_*');
    }

    // Save the new version config
    delete_post_meta($postId, $field['key']);
    foreach ($new as $version) {
      add_post_meta($postId, $field['key'], $version, false);
    }

    return $new;
  }

  /**
   * Adds post types for member and contact categorization
   */
  public function addCategorizationPostTypes()
  {
    WordPress::registerType(self::TYPE_PROFILE_CAT, 'Profilkategorie', 'Profilkategorien', array(
      'show_in_menu' => 'users.php',
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'show_in_nav_menus' => false,
      'has_archive' => false,
      'supports' => array('title'),
      'rewrite' => false
    ), '');

    WordPress::registerType(self::TYPE_CONTACT_CAT, 'Kontaktart', 'Kontaktarten', array(
      'show_in_menu' => 'users.php',
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'show_in_nav_menus' => false,
      'has_archive' => false,
      'supports' => array('title'),
      'rewrite' => false
    ), '');

    WordPress::registerType(self::TYPE_FIELD, 'Feld', 'Felder', array(
      'show_in_menu' => 'users.php',
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'show_in_nav_menus' => false,
      'has_archive' => false,
      'supports' => array('title'),
      'rewrite' => false
    ), 's');

    // Register sortable types
    SortableTypes::init(array(
      self::TYPE_FIELD => array(
        'type' => self::TYPE_FIELD,
        'field' => 'menu_order',
        'noImages' => true,
        'custom-menu' => array(
          'slug' => 'users.php',
          'name' => 'Felder sortieren'
        )
      )
    ));
  }

  /**
   * Adds meta fields for the categorization types
   */
  public function addCategorizationMetaboxes()
  {
    $helper = Metabox::get(self::TYPE_PROFILE_CAT);
    $helper->addMetabox('settings', 'Einstellungen');
    $helper->addPostTypeDropdown('contact-categories', 'settings', 'Kontaktarten', self::TYPE_CONTACT_CAT, array(
      'multiple' => true
    ));

    // Configuration for contact categories
    $helper = Metabox::get(self::TYPE_CONTACT_CAT);
    $helper->addMetabox('settings', 'Einstellungen');
    $helper->addInputText('max-contacts', 'settings', 'Max. Anzahl Kontakte', array(
      'description' => 'Wählen Sie z.B. "1" sofern in dieser Gruppe nur ein Kontakt erstellt werden darf.'
    ));
    $helper->addInputText('min-contacts', 'settings', 'Min. Anzahl Kontakte', array(
      'description' => 'Geben Sie hier eine Zahl ein, wenn die Kontaktart eine Mindestzahl an Kontakten voraussetzt.'
    ));
    $helper->addDropdown('tab', 'settings', 'Anzeigen in', array(
      'items' => $this->configuration['tabs']
    ));
    $helper->addTextarea('description', 'settings', 'Beschreibung', 70, array(
      'description' => 'Eine optionale Feldbeschreibung (wie dieser hier).'
    ));
    $helper->addInputText('sort', 'settings', 'Sortiernummer', array(
      'description' => 'Eine optionale Sortiernummer, damit die Reihenfolge der Kontaktarten in jeder Kombination stimmt.'
    ));
    $helper->addDropdown('custom-fields', 'settings', 'Zusätzliche Felder', array(
      'multiple' => true,
      'sortable' => true,
      'items' => 'self',
      'add_new_values' => true
    ));
    $helper->addMetabox('settings-cap', 'Zugriffsrechte');
    $helper->addCheckbox('cap-read', 'settings-cap', 'Rechte für Benutzer', array(
      'description' => 'Kann Kontakte sehen (Muss aktiv sein, damit weitere Rechte greifen)'
    ));
    $helper->addCheckbox('cap-edit', 'settings-cap', '&nbsp;', array(
      'description' => 'Kann Kontakte bearbeiten'
    ));
    $helper->addCheckbox('cap-delete', 'settings-cap', '&nbsp;', array(
      'description' => 'Kann Kontakte löschen'
    ));
    $helper->addCheckbox('cap-add', 'settings-cap', '&nbsp;', array(
      'description' => 'Kann Kontakte hinzufügen'
    ));
    $helper->addMetabox('settings-fields', 'Pflichtfelder');
    $helper->addCheckbox('neutral-salutation', 'settings-fields', 'Adressdaten', array(
      'description' => 'Neutrale Anrede ermöglichen (Felder für Anrede, Vorname, Nachname sind optional)'
    ));
    $helper->addCheckbox('optional-email', 'settings-fields', 'E-Mail-Feld', array(
      'description' => 'Das E-Mail Feld ist optional'
    ));
    $helper->addDropdown('hidden-fields', 'settings-fields', 'Felder ausblenden', array(
      'multiple' => true,
      'items' => array(
        'salutation' => 'Anrede',
        'firstname' => 'Vorname',
        'lastname' => 'Nachname',
        'email' => 'E-Mail-Adresse'
      )
    ));

    $helper = Metabox::get(self::TYPE_FIELD);
    $helper->addMetabox('settings', 'Einstellungen');
    $helper->addHtml('field-scripts', 'settings', $this->getFieldUiScripts());
    $helper->addDropdown('type', 'settings', 'Feld-Typ', array(
      'items' => $this->getCustomFieldTypes()
    ));
    $helper->addDropdown('profiles', 'settings', 'Verfügbar für', array(
      'items' => $this->getSelectableProfileCategories(),
      'multiple' => true
    ));
    $helper->addDropdown('tab', 'settings', 'Anzeigen in', array(
      'items' => $this->configuration['tabs']
    ));
    $helper->addTextarea('description', 'settings', 'Beschreibung', 70, array(
      'description' => 'Eine optionale Feldbeschreibung (wie dieser hier).'
    ));

    $helper->addMetabox('capabilities', 'Berechtigungen');
    $helper->addCheckbox('cap-invisible', 'capabilities', 'Sichtbarkeit', array(
      'description' => 'Dieses Feld können nur Administratoren sehen'
    ));
    $helper->addCheckbox('cap-readonly', 'capabilities', 'Schreibrechte', array(
      'description' => 'Das Feld kann vom Benutzer nicht geändert werden'
    ));
    $helper->addCheckbox('cap-required', 'capabilities', 'Pflichtfeld', array(
      'description' => 'Das Feld muss zwingend ausgefüllt werden'
    ));

    $helper->addMetabox('segments', 'Segmentierung');
    $helper->addCheckbox('segmenting-active', 'segments', 'Anwendung', array(
      'description' => 'Dieses Feld als Datenfeld in der Segmentierung nutzen'
    ));
    $helper->addInputText('segmenting-slug', 'segments', 'Feldname für E-Mails', array(
      'description' => 'Sollte möglichst nur Kleinbuchstaben und Bindestriche verwenden.'
    ));

    $helper->addMetabox('data-history', 'Historisierung');
    $helper->addCheckbox('history-active', 'data-history', 'Aktivieren', array(
      'description' => 'Historisierung der Feld-Daten aktivieren'
    ));
    $helper->addDropdown('versions', 'data-history', 'Versionen', array(
      'multiple' => true,
      'items' => 'self',
      'add_new_values' => true,
      'saveCallback' => array($this, 'saveCustomFieldVersion'),
      'description' => 'Beim Hinzufügen einer neuen Version werden die aktuelle Feld-Daten archiviert, sobald die Feld-Einstellungen gespeichert werden.'
    ));
    $helper->addHtml('versions-script', 'data-history', $this->getVersionConfirmationScript());

    $helper->addMetabox('multi-values', 'Feldinformationen für Tabellen / Dropdowns');
    $helper->addParagraph('multi-values', 'Hier können Sie die Vorgabewerte für Dropdowns bzw. die Spaltenwerte für Tabellen angeben.');
    $helper->addDropdown('field-values', 'multi-values', 'Versionen', array(
      'multiple' => true,
      'items' => 'self',
      'add_new_values' => true
    ));
  }

  /**
   * @return string script that does confirmation messages when adding a new field version
   */
  protected function getVersionConfirmationScript()
  {
    return '
      <script type="text/javascript">
        jQuery(function() {
          jQuery(".versions input[type=button]").on("click", function(e) {
            var message = "";
            var select = jQuery(".versions select");
            var versions = jQuery.map(select.find("option") ,function(option) {
              return option.value;
            });
            // Also get the newly added version
            versions.push(jQuery(".mbh-add-dropdown-value input[type=text]").val());
            // If there is at least an old and a new version, make a confirm message
            if (versions.length >= 2) {
              var index = (versions.length - 1);
              message = "Dadurch wird die neue Version *" + versions[index] + "* hinzugefügt und die Version *" + versions[index-1] + "* archiviert. Wenn Sie dies tun wollen, bitte Bestätigen Sie den Dialog mit OK. Die Aktion wird unwiederruflich durchgeführt, sobald das Feld mittels *Aktualisieren* gespeichert wird.";
            }
            
            // If there is a confirm message, ask for it
            if (message.length > 0 && !confirm(message)) {
              MetaboxHelper.preventAdd = true;
              setTimeout(function() {
                MetaboxHelper.preventAdd = false;
              }, 200);
            }
            return true;
          });
        });
      </script>
    ';
  }

  /**
   * @return string html tag with scripts
   */
  protected function getFieldUiScripts()
  {
    return '
      <script type="text/javascript">
        jQuery(function() {
          var select = jQuery("select[data-metakey=type]");
          // On Change of the type field
          select.on("change", function() {
            var fieldType = jQuery(this).val();
            var history = jQuery("#crm-custom-field__data-history");
            var multivalues = jQuery("#crm-custom-field__multi-values");
            // Basically allow history, but dont show multi values
            history.show();
            multivalues.hide();
            // If it is a table, show multival and disable history
            if (fieldType == "table") {
              history.hide();
              multivalues.show();
            }
            // If it is a dropdown, show multival
            if (fieldType == "dropdown") {
              multivalues.show();
            }
          });
          
          // On load trigger a change to the type to show fields
          select.trigger("change");
        });
      </script>
    ';
  }

  /**
   * @return array custom field types
   */
  protected function getCustomFieldTypes()
  {
    return array(
      'textfield' => 'Einzeiliges Textfeld',
      'textarea' => 'Mehrzeiliges Textfeld',
      'checkbox' => 'Checkbox',
      'dropdown' => 'Dropdown',
      'table' => 'Tabelle',
      'file' => 'Datei-Upload'
    );
  }

  /**
   * @return array a selectable list of categories
   */
  protected function getSelectableProfileCategories()
  {
    $list = self::getProfileCategoryList();
    $categories = array();

    foreach ($list as $entry) {
      $categories[$entry->ID] = $entry->post_title;
    }

    return $categories;
  }

  /**
   * @return array the full user admin build data
   */
  protected function getUserAdminData()
  {
    $isMember = $this->currentIsMember();
    return array(
      'config' => $this->configuration,
      'editedIsMember' => $isMember || $this->isMember($_REQUEST['user_id']),
      'editedUserId' => $_REQUEST['user_id'],
      'userIsMember' => $isMember,
      'userIsAdmin' => current_user_can('administrator'),
      'neutralSalutations' => $this->getSalutationOptions(true, ''),
      'defaultSalutations' => $this->getSalutationOptions(false, ''),
      'titleOverrideField' => $this->configuration['misc']['titleOverrideField'],
      'saveUserButton' => $this->configuration['misc']['saveUserButton'],
      'text' => array(
        'requiredFieldsMessage' => __('Es wurden nicht alle Pflichtfelder ausgefüllt', 'lbwp'),
        'noContactsYet' => __('Es sind noch keine Kontakte in dieser Kategorie vorhanden.', 'lbwp'),
        'sureToDelete' => __('Möchten Sie den Kontakt wirklich löschen?', 'lbwp'),
        'deleteImpossible' => __('Löschen nicht möglich. Mindestens {number} Kontakt/e sind erforderlich.', 'lbwp')
      )
    );
  }

  /**
   * Invalidate performance caches
   */
  public function invalidateCaches()
  {
    wp_cache_delete('crmCustomFields', 'CrmCore');
    do_action('crm_on_cache_invalidation');
  }

  /**
   * @param $role
   * @param string $orderby
   * @param string $order
   * @param bool $inactives
   * @return array
   */
  public function getMembersByRole($role, $orderby = 'display_name', $order = 'ASC', $inactives = false)
  {
    $users = get_users(array(
      'role' => $role,
      'orderby' => $orderby,
      'order' => $order
    ));

    // Filter all inactive users out if needed
    if (!$inactives) {
      $userIds = $this->getInactiveUserIds();
      $users = array_filter($users, function($user) use ($userIds) {
        return !in_array($user->ID, $userIds);
      });
    }

    return $users;
  }

  /**
   * @return array
   */
  protected function getInactiveUserIds()
  {
    if (!is_array($this->inactiveUserIds)) {
      $sql = '
        SELECT user_id FROM {sql:userMetaTable}
        WHERE meta_key = "member-disabled" AND meta_value = 1
      ';

      $db = WordPress::getDb();
      $this->inactiveUserIds = $db->get_col(Strings::prepareSql($sql, array(
        'userMetaTable' => $db->usermeta
      )));
    }

    return $this->inactiveUserIds;
  }

  /**
   * @return array the profile category list
   */
  public static function getProfileCategoryList()
  {
    return get_posts(array(
      'post_type' => self::TYPE_PROFILE_CAT,
      'posts_per_page' => -1,
      'orderby' => 'title',
      'order' => 'ASC'
    ));
  }

  /**
   * @return array the contact category list
   */
  public static function getContactCategoryList()
  {
    $categories = get_posts(array(
      'post_type' => self::TYPE_CONTACT_CAT,
      'posts_per_page' => -1,
      'orderby' => 'title',
      'order' => 'ASC'
    ));

    // Order by sort
    usort($categories, function($a, $b) {
      $na = intval(get_post_meta($a->ID, 'sort', true));
      $nb = intval(get_post_meta($b->ID, 'sort', true));
      if ($na > $nb) {
        return 1;
      } else if ($na < $nb) {
        return -1;
      }
      return 0;
    });

    return $categories;
  }

  /**
   * Get a comprehensible list of custom fields for the given role
   * @param array $categories list of profile categories
   * @return array a list of custom fields for that role
   */
  public function getCustomFields($categories)
  {
    $allFields = wp_cache_get('crmCustomFields', 'CrmCore');

    // Get the fields from db
    if (!is_array($allFields)) {
      $raw = get_posts(array(
        'post_type' => self::TYPE_FIELD,
        'posts_per_page' => -1,
        'orderby' => 'menu_order',
        'order' => 'ASC'
      ));

      foreach ($raw as $field) {
        $allFields[] = array(
          'id' => $field->ID,
          'title' => $field->post_title,
          'type' => get_post_meta($field->ID, 'type', true),
          'profiles' => get_post_meta($field->ID, 'profiles'),
          'tab' => get_post_meta($field->ID, 'tab', true),
          'history-active' => get_post_meta($field->ID, 'history-active', true) == 'on',
          'versions' => get_post_meta($field->ID, 'versions'),
          'field-values' => get_post_meta($field->ID, 'field-values'),
          'segmenting-active' => get_post_meta($field->ID, 'segmenting-active', true) == 'on',
          'segmenting-slug' => get_post_meta($field->ID, 'segmenting-slug', true),
          'description' => get_post_meta($field->ID, 'description', true),
          'invisible' => get_post_meta($field->ID, 'cap-invisible', true) == 'on',
          'readonly' => get_post_meta($field->ID, 'cap-readonly', true) == 'on',
          'required' => get_post_meta($field->ID, 'cap-required', true) == 'on',
        );
      }

      wp_cache_set('crmCustomFields', $allFields, 'CrmCore');
    }

    // Remove invisible fields, if the user is not admin
    if (!$this->userAdminData['userIsAdmin']) {
      foreach ($allFields as $key => $field) {
        if ($field['invisible']) unset($allFields[$key]);
      }
    }

    // Filter the fields by role if given
    if (is_array($categories)) {
      return array_filter($allFields, function($item) use ($categories) {
        foreach ($categories as $categoryId) {
          if (in_array($categoryId, $item['profiles'])) {
            return true;
          }
        }
        return false;
      });
    }

    // Or return all fields if no role was given
    return $allFields;
  }

  /**
   * @param int $id the field id
   * @return bool|array the field or false
   */
  protected function getCustomFieldById($id)
  {
    $fields = $this->getCustomFields(false);
    foreach ($fields as $field) {
      if ($field['id'] == $id) {
        return $field;
      }
    }

    return false;
  }
}