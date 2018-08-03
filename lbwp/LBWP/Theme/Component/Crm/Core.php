<?php

namespace LBWP\Theme\Component\Crm;

use LBWP\Helper\Metabox;
use LBWP\Theme\Base\Component;
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
   * @var int the edited user id
   */
  protected $editedUserId = 0;
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
            <span class="description"><label for="crmcf-{fieldName}">{fieldDescription}</label></span>
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
      add_action('admin_init', array($this, 'addCategorizationMetaboxes'), 50);
      add_action('admin_menu', array($this, 'hideMenusFromMembers'), 50);
      add_action('admin_footer', array($this, 'invokeMemberAdminScripts'));
      // Save user data
      add_action('profile_update', array($this, 'saveMemberData'));
      add_action('save_post_' . self::TYPE_FIELD, array($this, 'invalidateCaches'));
      // Add custom fields as columns in admin tables
      $this->addAdminTableColumns();
    }

    // Filters to add segments containing of profile- and contact categories
    add_action('Lbwp_LMS_Metabox_' . self::FILTER_REF_KEY, array($this, 'addMemberMetabox'), 10, 3);
    add_filter('Lbwp_LMS_Data_' . self::FILTER_REF_KEY, array($this, 'getSegmentData'), 10, 2);
    add_filter('Lbwp_Autologin_Link_Validity', array($this, 'configureAutoLoginLink'));

    if ($this->userAdminData['editedIsMember']) {
      // Include the tabs navigation and empty containers
      add_action('show_user_profile', array($this, 'addTabContainers'));
      add_action('edit_user_profile', array($this, 'addTabContainers'));
      // Include custom fields as of configuration and callbacks
      add_action('show_user_profile', array($this, 'addCustomUserFields'));
      add_action('edit_user_profile', array($this, 'addCustomUserFields'));
      // Custom field save functions
      add_action('profile_update', array($this, 'saveCustomFieldData'));
      add_action('profile_update', array($this, 'saveContactData'));
    }
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
    $customFields = $this->getCustomFields($this->editedUser->roles[0]);
    // Print the fields
    foreach ($customFields as $field) {
      echo Templating::getBlock($this->fieldTemplate, array(
        '{fieldName}' => $field['id'],
        '{fieldLabel}' => $field['title'],
        '{tabName}' => $field['tab'],
        '{fieldDescription}' => $field['description'],
        '{fieldRequired}' => ($field['required']) ? ' <span class="required">*</span>' : '',
        '{fieldContent}' => $this->getCustomFieldContent($field),
      ));
    }

    // Add profile categories view or edit field
    echo $this->getProfileCategoriesEditor();
    // Add the contact UIs
    echo $this->getProfileContactsEditor();
  }

  /**
   * @param array $field the field array
   * @return string the custom field content
   */
  protected function getCustomFieldContent($field)
  {
    // Get the current field content, if given
    $html = '';
    $key = 'crmcf-' . $field['id'];
    $value = get_user_meta($this->editedUserId, $key, true);

    // Define attributes for the input field
    $attr = 'id="' . $key . '" name="' . $key . '" class="regular-text"';
    if ($field['required'])
      $attr .= ' required="required"';
    if ($field['readonly'] && $this->userAdminData['userIsMember'])
      $attr .= ' readonly="readonly"';

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
        $html .= '<input type="checkbox" ' . $attr . ' value="1" ' . $checked . ' />';
        break;
    }

    return $html;
  }

  /**
   * Saves the custom user fields with loose validation
   */
  public function saveCustomFieldData()
  {
    // Save the custom fields as given
    $customFields = $this->getCustomFields($this->editedUser->roles[0]);
    // Print the fields
    foreach ($customFields as $field) {
      $key = 'crmcf-' . $field['id'];
      // Need to be admin or have access to the field
      if ($this->userAdminData['userIsAdmin'] || (!$field['invisible'] && !$field['readonly'])) {
        if (isset($_POST[$key])) {
          update_user_meta($this->editedUserId, $key, $_POST[$key]);
        } else {
          delete_user_meta($this->editedUserId, $key);
        }
      }
    }
  }

  /**
   * Saves all the contacts of the profile
   */
  public function saveContactData()
  {
    // Save all given contacts
    $categories = array_map('intval', $_POST['crm-contact-categories']);

    // Go trough each category, validate inputs and save them
    foreach ($categories as $categoryId) {
      $key = 'crm-contacts-' . $categoryId;
      $contacts = $this->validateInputContacts($_POST[$key]);
      if (count($contacts) > 0) {
        update_user_meta($this->editedUserId, $key, $contacts);
      } else {
        delete_user_meta($this->editedUserId, $key);
      }
    }
  }

  /**
   * @param array $candidates list of contact candidates to be saved from POST
   * @return array validated (hence maybe empty) list of contacts
   */
  protected function validateInputContacts($candidates)
  {
    $contacts = array();
    for ($i = 0; $i < count($candidates['email']); ++$i) {
      if (Strings::checkEmail($candidates['email'][$i])) {
        $contacts[] = array(
          'salutation' => $candidates['salutation'][$i],
          'firstname' => $candidates['firstname'][$i],
          'lastname' => $candidates['lastname'][$i],
          'email' => $candidates['email'][$i]
        );
      }
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
    $current = ArrayManipulation::forceArray(get_user_meta($this->editedUserId, 'profile-categories', true));

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
            <th><label for="profile_categories">Zugewiesene Gruppen</label></th>
            <td>' . $html . '</td>
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

    // Sort by number so that topmost IDs are top
    sort($contactCategories, SORT_NUMERIC);

    // Get the contact editing screen for all the categories
    $index = 0;
    foreach ($contactCategories as $categoryId) {
      $category = $this->getContactCategory($categoryId);
      if ($category['visible']) {
        $html .= $this->getContactsEditorHtml($category, ++$index);
      }
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

    // Display available contacts
    if (count($contacts) > 0) {
      foreach ($contacts as $contact) {
        $html .= '
          <tr>
            <td><select name="' . $key . '[salutation][]">' . $this->getSalutationOptions($category['allow-neutral'], $contact['salutation']) . '</select></td>
            <td><input type="text" name="' . $key . '[firstname][]" ' . $required . ' value="' . esc_attr($contact['firstname']) . '" /></td>
            <td><input type="text" name="' . $key . '[lastname][]" ' . $required . ' value="' . esc_attr($contact['lastname']) . '" /></td>
            <td><input type="text" name="' . $key . '[email][]"  required="required" value="' . esc_attr($contact['email']) . '" /></td>
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
        data-target-tab="contacts"
        data-input-key="' . $key . '"
        data-max-contacts="' . $category['max-contacts'] . '"
        data-allow-delete="' . ($category['delete'] ? '1' : '0') . '"
        data-allow-neutral="' . ($category['allow-neutral'] ? '1' : '0') . '"
        >
        <h4>' . $category['title'] . '</h4>
        <div class="contact-table-container">
          <table class="widefat contact-table">
            <thead>
              <tr>
                <th class="th-salutation">Anrede</th>
                <th class="th-firstname">Vorname</th>
                <th class="th-lastname">Nachname</th>
                <th class="th-email">E-Mail-Adresse</th>
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
      'visible' =>  $admin || get_post_meta($categoryId, 'cap-read', true) == 'on',
      'edit' => $admin || get_post_meta($categoryId, 'cap-edit', true) == 'on',
      'delete' => $admin || get_post_meta($categoryId, 'cap-delete', true) == 'on',
      'add' => $admin || get_post_meta($categoryId, 'cap-add', true) == 'on',
      'allow-neutral' => get_post_meta($categoryId, 'neutral-salutation', true) == 'on',
      'max-contacts' => intval(get_post_meta($categoryId, 'max-contacts', true)),
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
    if ($screen->base == 'user-edit' || $screen->base == 'profile') {
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
        <script src="' . $uri . '/js/lbwp-crm-backend.js" type="text/javascript"></script>
        <link rel="stylesheet" href="' . $uri . '/css/lbwp-crm-backend.css">
      ';
    }
  }

  /**
   * Adds various columns to custom types tables
   */
  protected function addAdminTableColumns()
  {
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

    // For custom fields
    $types = $this->getCustomFieldTypes();
    $roles = $this->getSelectableRoles();
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
      'meta_key' => 'roles',
      'column_key' => self::TYPE_FIELD . '_roles',
      'single' => false,
      'heading' => __('Verfügbar für', 'lbwp'),
      'callback' => function($values, $postId) use ($roles) {
        $display = array();
        foreach ($values as $key) {
          $display[] = $roles[$key];
        }
        echo implode(', ', $display);
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
   * @return array list of contacts of a specified category, contains profile categories of the assigned members
   */
  protected function getContactsByCategory($categoryId)
  {
    $contacts = wp_cache_get('fullContactList', 'CrmCore');
    // Build a new list, if not in cache
    if (!is_array($contacts)) {
      $db = WordPress::getDb();
      $contacts = array();
      $fields = $this->getCustomFields();
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
    $helper->addCheckbox('cap-read', 'settings', 'Rechte für Benutzer', array(
      'description' => 'Kann Kontakte sehen (Muss aktiv sein, damit weitere Rechte greifen)'
    ));
    $helper->addCheckbox('cap-edit', 'settings', '&nbsp;', array(
      'description' => 'Kann Kontakte bearbeiten'
    ));
    $helper->addCheckbox('cap-delete', 'settings', '&nbsp;', array(
      'description' => 'Kann Kontakte löschen'
    ));
    $helper->addCheckbox('cap-add', 'settings', '&nbsp;', array(
      'description' => 'Kann Kontakte hinzufügen'
    ));
    $helper->addCheckbox('neutral-salutation', 'settings', 'Adressdaten', array(
      'description' => 'Neutrale Anrede ermöglichen (Felder für Anrede, Vorname, Nachname sind optional)'
    ));
    $helper->addInputText('max-contacts', 'settings', 'Max. Anzahl Kontakte', array(
      'description' => 'Wählen Sie z.B. "1" sofern in dieser Gruppe nur ein Kontakt erstellt werden darf'
    ));

    $helper = Metabox::get(self::TYPE_FIELD);
    $helper->addMetabox('settings', 'Einstellungen');
    $helper->addDropdown('type', 'settings', 'Feld-Typ', array(
      'items' => $this->getCustomFieldTypes()
    ));
    $helper->addDropdown('roles', 'settings', 'Verfügbar für', array(
      'items' => $this->getSelectableRoles(),
      'multiple' => true
    ));
    $helper->addDropdown('tab', 'settings', 'Anzeigen in', array(
      'items' => $this->configuration['tabs']
    ));
    $helper->addInputText('description', 'settings', 'Beschreibung', array(
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
  }

  /**
   * @return array custom field types
   */
  protected function getCustomFieldTypes()
  {
    return array(
      'textfield' => 'Einzeiliges Textfeld',
      'textarea' => 'Mehrzeiliges Textfeld',
      'checkbox' => 'Checkbox'
    );
  }

  /**
   * @return array key value pair of selectable member roles
   */
  protected function getSelectableRoles()
  {
    $availableRoles = wp_roles();
    $roles = array();

    // Map the roles to their key
    foreach ($availableRoles->roles as $key => $role) {
      if (in_array($key, $this->configuration['roles'])) {
        $roles[$key] = $role['name'];
      }
    }

    return $roles;
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
      'userIsMember' => $isMember,
      'userIsAdmin' => current_user_can('administrator'),
      'neutralSalutations' => $this->getSalutationOptions(true, ''),
      'defaultSalutations' => $this->getSalutationOptions(false, ''),
      'titleOverrideField' => $this->configuration['misc']['titleOverrideField'],
      'saveUserButton' => $this->configuration['misc']['saveUserButton'],
      'text' => array(
        'requiredFieldsMessage' => __('Es wurden nicht alle Pflichtfelder ausgefüllt', 'lbwp'),
        'noContactsYet' => __('Es sind noch keine Kontakte in dieser Kategorie vorhanden.', 'lbwp'),
        'sureToDelete' => __('Möchten Sie den Kontakt wirklich löschen?', 'lbwp')
      )
    );
  }

  /**
   * Invalidate performance caches
   */
  public function invalidateCaches()
  {
    wp_cache_delete('crmCustomFields', 'CrmCore');
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
    return get_posts(array(
      'post_type' => self::TYPE_CONTACT_CAT,
      'posts_per_page' => -1,
      'orderby' => 'title',
      'order' => 'ASC'
    ));
  }

  /**
   * Get a comprehensible list of custom fields for the given role
   * @param string $role a role slug (optional)
   * @return array a list of custom fields for that role
   */
  public function getCustomFields($role = '')
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
          'roles' => get_post_meta($field->ID, 'roles'),
          'tab' => get_post_meta($field->ID, 'tab', true),
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
    if (strlen($role) > 0) {
      return array_filter($allFields, function ($item) use ($role) {
        return in_array($role, $item['roles']);
      });
    }

    // Or return all fields if no role was given
    return $allFields;
  }
}