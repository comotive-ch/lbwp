<?php

namespace LBWP\Theme\Component\Crm;

use LBWP\Helper\Metabox;
use LBWP\Theme\Base\Component;
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
    }

    if ($this->userAdminData['editedIsMember']) {
      // Include the tabs navigation and empty containers
      add_action('show_user_profile', array($this, 'addTabContainers'));
      add_action('edit_user_profile', array($this, 'addTabContainers'));
      // Include custom fields as of configuration and callbacks
      add_action('show_user_profile', array($this, 'addCustomUserFields'));
      add_action('edit_user_profile', array($this, 'addCustomUserFields'));
    }
  }

  /**
   * Sets the current edit user id
   */
  protected function setEditedUserId()
  {
    $this->editedUserId = intval($_GET['user_id']);
    if ($this->editedUserId == 0) {
      $this->editedUserId = intval(get_current_user_id());
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
    // Add profile categories view or edit field
    echo $this->getProfileCategoriesEditor();
  }

  /**
   * @return string html for the profile categories editor
   */
  protected function getProfileCategoriesEditor()
  {
    $html = '';

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
   * Save the member main data, contacts and custom fields
   */
  public function saveMemberData($userId)
  {
    // Save the user profile categories
    if (is_array($_POST['profileCategories'])) {
      $categories = array_map('intval', $_POST['profileCategories']);
      update_user_meta($userId, 'profile-categories', $categories);
    }
  }

  /**
   * Invoke the scripts and data provision for member admin
   */
  public function invokeMemberAdminScripts()
  {
    $screen = get_current_screen();
    if ($screen->base == 'user-edit' || $screen->base == 'profile') {
      // Include usage of chosen
      wp_enqueue_script('chosen-js');
      wp_enqueue_style('chosen-css');
      wp_enqueue_style('jquery-ui-theme-lbwp');
      // And some custom library outputs
      echo '
        <script type="text/javascript">
          var crmAdminData = ' . json_encode($this->userAdminData) . ';
        </script>
        <script src="' . File::getResourceUri() . '/js/lbwp-crm-backend.js" type="text/javascript"></script>
        <style type="text/css">
          #your-profile { display:none; }
          .tab-container { display:none; }
          .profile-category-list { list-style-type:circle; margin:7px 0px 0px 20px; }
        </style>
      ';
    }
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
    return in_array(
      get_user_by('id', $userId)->roles[0],
      $this->configuration['roles']
    );
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
      'items' => array(
        'textfield' => 'Einzeiliges Textfeld',
        'textarea' => 'Mehrzeiliges Textfeld',
        'checkbox' => 'Checkbox'
      )
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
    $helper->addCheckbox('cap-invisible', 'settings', 'Sichtbarkeit', array(
      'description' => 'Dieses Feld können nur Administratoren sehen'
    ));
    $helper->addCheckbox('cap-readonly', 'settings', 'Schreibrechte', array(
      'description' => 'Das Feld kann vom Benutzer nicht geändert werden'
    ));
    $helper->addCheckbox('cap-required', 'settings', 'Pflichtfeld', array(
      'description' => 'Das Feld muss zwingend ausgefüllt werden'
    ));
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
      'editedIsMember' => $isMember || $this->isMember($_GET['user_id']),
      'userIsMember' => $isMember,
      'userIsAdmin' => current_user_can('administrator')
    );
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
} 