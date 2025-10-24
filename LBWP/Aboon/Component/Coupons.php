<?php

namespace LBWP\Aboon\Component;

use Banholzer\Component\Shop;
use LBWP\Aboon\Base\Shop as AboonShop;
use LBWP\Helper\Import\Csv;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Base\Component as Component;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Add some functionalty to the woocommerce coupons
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Coupons extends Component
{
  /**
   * Coupon post type (woocommerce)
   */
  const POST_TYPE = 'shop_coupon';

  /**
   * Coupon categories
   */
  const TAX_SLUG = 'coupon_category';

  /**
   * Setup custom coupon category page and taxonomy (also handle GET request here)
   * @return void
   */
  public function setup()
  {
    parent::setup();

    add_action('admin_menu', array($this, 'addMenuPages'), 9999);
    add_action('admin_head-edit.php', array($this, 'handleAction'));
  }

  /**
   * Add menu pages for the coupons
   * @return void
   */
  public function addMenuPages(){
    add_submenu_page(
      'woocommerce',
      __('Gutscheine Kategorie', 'aboon'),
      __('Gutscheine Kategorie', 'aboon'),
      'manage_options',
      'edit-tags.php?taxonomy=' . self::TAX_SLUG . '&post_type=' . self::POST_TYPE,
      '',
      99
    );
  }

  /**
   * Initialize the coupons
   * @return void
   */
  public function init()
  {
    $this->addTaxonomy();
    $this->addCouponsOptions();
    $this->addCouponsCategoryColumn();

    // Doing what the functions says
    add_action('woocommerce_thankyou', array($this, 'reduceCouponValue'));
    add_action('wp_ajax_duplicate_coupons', array($this, 'handleAjaxRequest'));
    add_action('manage_posts_extra_tablenav', array($this, 'addExportButton'));
    add_filter('post_row_actions', array($this, 'addDuplicateAction'), 10, 2);
    //add_filter('woocommerce_package_rates', array($this, 'discountShipping'), 99, 2); Not needed (anymore)?

    // Add import fields
    add_filter('views_edit-shop_coupon', array($this, 'addImportFields'));
  }

  /**
   * @return void
   */
  private function addTaxonomy()
  {
    $this->registerTaxonomy(
      self::TAX_SLUG,
      __('Gutschein Kategorie', 'aboon'),
      __('Gutschein Kategorien', 'aboon'),
      'n',
      array(),
      array(self::POST_TYPE)
    );
  }

  /**
   * Adding custom field to the woocommerce coupons
   * @return void
   */
  private function addCouponsOptions()
  {
    // Add a custom field to Admin coupon settings pages
    add_action('woocommerce_coupon_options', function () {
      woocommerce_wp_checkbox(array(
        'id' => 'variable-value',
        'label' => __('Gutschein-Wert ist variabel', 'lbwp'),
        'placeholder' => '',
        'description' => __('Benutzer kann der Gutschein mehrmals verwenden falls es noch wert hat.', 'lbwp'),
        'desc_tip' => true,
      ));

      woocommerce_wp_checkbox(array(
        'id' => 'reduce-shipping',
        'label' => __('Versand reduzieren', 'lbwp'),
        'placeholder' => '',
        'description' => __('Versand wird reduziert, falls der Gutschein noch genug wert Vorhanden hat', 'lbwp'),
        'desc_tip' => true,
      ));
    }, 10);


    // Save the custom field value from Admin coupon settings pages
    add_action('woocommerce_coupon_options_save', function ($post_id, $coupon) {
      if (isset($_POST['variable-value'])) {
        $coupon->update_meta_data('variable-value', sanitize_text_field($_POST['variable-value']));
        $coupon->save();
      }
      if (isset($_POST['reduce-shipping'])) {
        $coupon->update_meta_data('reduce-shipping', sanitize_text_field($_POST['reduce-shipping']));
        $coupon->save();
      }
    }, 10, 2);
  }

  /**
   * @return void
   */
  public function addCouponsCategoryColumn()
  {
    add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'addColumnHead'), 9999);
    add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'addColumnContent'), 10, 2);
  }

  /**
   * @param $columns array the post listing table columns
   * @return mixed
   */
  public function addColumnHead($columns)
  {
    $columns['coupon_category'] = __('Kategorie', 'lbwp');
    return $columns;
  }

  /**
   * @param $column
   * @param $postId
   * @return void
   */
  public function addColumnContent($column, $postId)
  {
    if ($column === 'coupon_category') {
      $categories = wp_get_post_terms($postId, self::TAX_SLUG);

      if (empty($categories)) {
        echo '–';
      } else {
        foreach ($categories as $category) {
          echo '<a href="' . admin_url('edit.php?post_type=' . self::POST_TYPE . '&coupon_category=' . $category->slug) . '">' . $category->name . '</a>';
        }
      }
    }
  }

  /**
   * @return void
   */
  public function addExportButton()
  {
    if (get_current_screen()->post_type !== self::POST_TYPE) {
      return;
    }

    $name = 'export-coupons' . (isset($_GET['coupon_category']) ? '__' . $_GET['coupon_category'] : '');

    echo '<input type="submit" name="' . $name . '" class="button" value="' . __('Exportieren', 'lbwp') . '">';
  }

  /**
   * Reduce coupon value on thankyou page
   * @param $orderId
   * @return void
   */
  public function reduceCouponValue($orderId)
  {
    if (!$orderId) {
      return;
    }

    $order = wc_get_order($orderId);
    // Allow code execution only once
    if (AboonShop::wcThankyouOnce($orderId, 'coupons')) {

      $coupons = $order->get_coupon_codes();

      if (is_array($coupons) && count($coupons) > 0) {
        $savedAmount = 0;
        $shippingCost = null;

        foreach ($coupons as $coupon) {
          $coupon = new \WC_Coupon($coupon);

          if ($coupon->get_meta('variable-value') === 'yes') {
            $couponVal = floatval($coupon->get_amount());
            $amount = 0.0;

            foreach ($order->get_items() as $id => $item) {
              $item = $item->get_data();
              $itemAmount = ($item['subtotal'] + $item['subtotal_tax']) * 100;
              $itemAmount = (fmod($itemAmount, 5) <= 0) ? $itemAmount : round($itemAmount / 5) * 5;
              $itemAmount /= 100;

              $amount += $itemAmount;
            }

            // Remove amount, that has already been removed from other coupon
            if($amount < $savedAmount){
              $amount = 0.0;
            }else{
              $amount -= $savedAmount;
            }

            if ($coupon->get_meta('reduce-shipping') === 'yes' && $amount < $couponVal) {
              if($shippingCost === null){
                $taxRate = AboonShop::getStandardTaxes()[0];
                $taxRate = 1 + ($taxRate['tax_rate'] / 100);
                $shippingMethod = array_values($order->get_shipping_methods())[0]->get_data();
                $shippingCost = floatval(get_option('woocommerce_' . $shippingMethod['method_id'] . '_' . $shippingMethod['instance_id'] . '_settings')['cost']);
                $shippingCost = $shippingCost * (Shop::isPrivateCustomer() ? 1 : $taxRate);
              }

              if($amount + $shippingCost > $couponVal){
                $amount += $shippingCost;
                $shippingCost = $amount - $couponVal;
              }else{
                $amount += $shippingCost;
              }
            }

            if ($amount < $couponVal) {
              $coupon->set_amount($couponVal - $amount);
              $coupon->save();
              $savedAmount = $amount;
            } else {
              $coupon->set_amount(0);
              $coupon->save();
              $savedAmount = floatval($couponVal);
            }
          }
        }
      }
    }
  }

  /**
   * @param $actions
   * @param $post
   * @return mixed
   */
  public function addDuplicateAction($actions, $post)
  {
    $url = '/wp-admin/edit.php?post_type=' . self::POST_TYPE . '&duplicate&post_id=' . $post->ID;
    $actions['duplicate'] = '<a href="#"
      onclick="let num = prompt(\'Anzahl Duplizierungen\');  if(num && num < 999){window.location.href=\'' . $url . '&number=\' + num}">Duplizieren</a>';

    return $actions;
  }

  /**
   * Handle some GET requests
   * @return void
   */
  public function handleAction()
  {
    global $post_type;

    if ($post_type === self::POST_TYPE) {
      // Duplicate (request)
      if (isset($_GET['duplicate'])) {
        $this->sendDuplicateRequest($_GET['post_id'], $_GET['number']);
      }

      // Duplicate (redirect)
      if (isset($_GET['duplicating'])) {
        add_action('admin_notices', function () {
          echo '<div class="notice notice-success is-dismissible">
            <p>Gutscheine werden generiert...</p>
          </div>';
        });
      }

      // Export
      $exportCoupons = array_keys($_GET);
      $exportCoupons = preg_grep('/^export-coupons*/', $exportCoupons);
      if (!empty($exportCoupons)) {
        $couponCat = explode('__', array_values($exportCoupons)[0])[1];
        $this->exportCoupons($couponCat);
      }

      // Import
      if(isset($_GET['download-import-template'])){
        $importTemplateFile = File::getResourcePath() . '/files/import-vorlage.xlsx';

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($importTemplateFile) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($importTemplateFile));
        ob_end_clean();
        echo file_get_contents($importTemplateFile);
        exit;
        //wp_redirect(admin_url('edit.php?post_type=' . self::POST_TYPE));
      }

      if(isset($_POST['import-coupons']) && isset($_FILES['import-coupons-file'])){
        $this->importCoupons();
      }
    }
  }

  /**
   * Export coupons as CSV
   * @param $category
   * @return void
   */
  private function exportCoupons($category = null)
  {
    $queryArgs = array('numberposts' => -1, 'post_type' => self::POST_TYPE);
    if (isset($category)) {
      $queryArgs['tax_query'] = array(
        array(
          'taxonomy' => self::TAX_SLUG,
          'field' => 'slug',
          'terms' => $category
        ),
      );
    }

    $exportContent = array(array(
      'ID',
      'Gutscheincode',
      'Gutschein-Typ',
      'Wert',
      'Anzahl Verwendungen',
      'Kategorie',
    ));
    $couponTypes = array(
      'percent' => 'Prozentualer Nachlass/Rabatt',
      'fixed_cart' => 'Fester Warenkorb-Rabatt',
      'fixed_product' => 'Fester Rabatt für Produkt'
    );

    foreach (get_posts($queryArgs) as $coupon) {
      if(!isset($category)){
        $catList = array();

        foreach(get_the_terms($coupon->ID, self::TAX_SLUG) as $term){
          $catList[] = $term->name;
        }

        $catList = implode(', ', $catList);
      }else{
        $catList = $category;
      }

      $couponData = WordPress::getAccessiblePostMeta($coupon->ID);

      $exportContent[] = array(
        $coupon->ID,
        $coupon->post_title,
        $couponTypes[$couponData['discount_type']],
        $couponData['coupon_amount'],
        $couponData['usage_count'],
        $catList
      );
    }

    Csv::downloadFile($exportContent, 'gutscheine' . (isset($category) ? '-' . $category : ''));
  }

  /**
   * Ajay request to duplicate (many) coupons
   * @return void
   */
  public function handleAjaxRequest()
  {
    SystemLog::mDebug('data', $_POST);
    $this->duplicateCoupons($_POST['postId'], $_POST['number']);
  }

  /**
   * @param $postId
   * @param $number
   * @return void
   */
  private function duplicateCoupons($postId, $number = 1)
  {
    set_time_limit(60 * 60 * 30);
    $ogPost = get_post($postId);
    $postMeta = get_post_meta($postId);
    unset($postMeta['_edit_lock']);
    unset($postMeta['_edit_last']);

    // Generate coupon name
    $randStr = array_merge(Strings::ALPHABET, Strings::ALPHABET, Strings::ALPHABET);
    $couponNames = array_column(array_values(get_posts(array('post_type' => self::POST_TYPE, 'numberposts' => -1))), 'post_title');

    // Loop through coupons number
    for ($i = 0; $i < $number;) {
      $_SESSION['duplicating'] = $i;
      $newPost = array();
      shuffle($randStr);
      $couponName = strtoupper(implode(array_slice($randStr, 0, 8)));

      // Regenerate coupon name if already used
      if (in_array($couponName, $couponNames)) {
        continue;
      }

      // Initialize new post data
      $couponNames[] = $couponName;
      $newPost['post_title'] = $couponName;
      $newPost['post_type'] = self::POST_TYPE;
      $newPost['post_excerpt'] = $ogPost->post_excerpt;
      $newPost['post_status'] = $ogPost->post_status;

      // Copy metas
      $newPostId = wp_insert_post($newPost);
      if ($newPostId !== 0) {
        foreach ($postMeta as $key => $value) {
          update_post_meta($newPostId, $key, maybe_unserialize($value[0]));
        }
      }

      // Copy terms
      $categories = wp_get_object_terms($postId, self::TAX_SLUG);
      $cat = array();
      foreach ($categories as $category) {
        $cat[] = $category->slug;
      }
      wp_set_object_terms($newPostId, $cat, self::TAX_SLUG);

      $i++;
    }

    // Add notice when done (not sure if this works tho)
    add_action('admin_notices', function () use ($number) {
      echo '<div class="notice notice-success is-dismissible">
        <p>' . $number . ' Gutscheine wurden generiert</p>
      </div>';
    });
  }

  /**
   * Send duplicate request over ajax
   * @param $postId
   * @param $number
   * @return void
   */
  private function sendDuplicateRequest($postId, $number)
  {
    echo '<script>
      window.onload = function(){
        console.log("start duplicating");
        let postData = {postId: ' . $postId . ', number: ' . $number . '}
        jQuery.post("' . admin_url('admin-ajax.php') . '?action=duplicate_coupons", postData, function(data, response){
          console.log("coupons generated");
        });
        
        setTimeout(function(){
          window.location.replace("' . admin_url('edit.php?post_type=' . self::POST_TYPE . '&duplicating') . '");
        }, 5000);
      }
    </script>';
  }

  /**
   * Reduce shipping if the coupon has enough value
   * @param $rates
   * @param $package
   * @return mixed
   */
  public function discountShipping($rates, $package)
  {
    if (is_admin() && !defined('DOING_AJAX')) {
      return $rates;
    }

    $cartDiscount = WC()->cart->get_cart_discount_total() + WC()->cart->get_cart_discount_tax_total();

    foreach (WC()->cart->get_applied_coupons() as $coupon) {
      $coupon = new \WC_Coupon($coupon);
      $couponVal = $coupon->get_amount() - $cartDiscount;
      $cartDiscount -= $coupon->get_amount();

      if ($coupon->get_meta('reduce-shipping') === 'yes' && $couponVal > 0) {
        foreach ($rates as $key => $rate) {
          if (floatval($rate->cost) <= 0.0) {
            continue;
          }

          $shippingVal = $rate->cost - $couponVal;

          if ($shippingVal < 0) {
            $shippingCost = round($rate->cost * 100) / 100;
            $rates[$key]->label = __('Gratisversand (' . $shippingCost . '&nbsp;' . get_woocommerce_currency() . ' werden vom Gutschein abgezogen)', 'lbwp');
            $rates[$key]->cost = 0;
          } else {
            $rates[$key]->cost = floatval($shippingVal);
          }
        }
      }
    }

    return $rates;
  }

  public function addImportFields($views){
    echo '<form method="post" enctype="multipart/form-data">
        <strong>Gutscheine Importieren</strong><br>
        Beispiel-Datei herunter laden: <a href="?post_type=' . self::POST_TYPE . '&download-import-template">Import-Vorlage.xlsx</a><br>
        <input type="file" name="import-coupons-file" accept=".xlsx">
        <input type="submit" class="button" name="import-coupons" value="Importieren">
      </form>';

    return $views;
  }

  private function importCoupons(){
    require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/phpspreadsheet/vendor/autoload.php';

    $inputFile = $_FILES['import-coupons-file']['tmp_name'];

    $inputFileType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($inputFile);
    $objReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
    $objReader->setReadDataOnly(true);
    $objPHPExcel = $objReader->load($inputFile);

    //Get worksheet dimensions
    $sheet = $objPHPExcel->getSheet(0);
    $data = $sheet->toArray();

    foreach($data as $rowNum => $row){
      for($i = 0; $i < intval($row[2]); $i++){
        $name = strtoupper($row[0] . '-' . Strings::getRandom(intval($row[1])));
        $metadata = array(
          'discount_type' => strtolower($row[4]) === 'prozent' ? 'percent' : 'fixed_cart',
          'coupon_amount' => intval($row[5]),
          'individual_use' => strtolower($row[6]) === 'nein' ? 'yes' : 'no',
          'expiry_date' => $row[7] !== null ? date('Y-m-d', strtotime($row[7])) : '',
          'usage_limit' => max(intval($row[8]), 1),
        );

        $taxonomy = get_term_by('name', $row[3], self::TAX_SLUG);
        $taxonomy = $taxonomy === false ? false : array($taxonomy->term_id);

        $this->generateCoupon($name, $metadata, $taxonomy);
      }
    }

    wp_admin_notice('Gutscheine wurden importiert', array('type' => 'success'));
  }

  private function generateCoupon($name, $metadata = array(), $taxonomies = false){
    $couponData = array(
      'post_title' => $name,
      'post_type' => self::POST_TYPE,
      'post_status' => 'publish',
    );

    foreach($metadata as $key => $value){
      $couponData['meta_input'][$key] = $value;
    }

    if($taxonomies !== false){
      $couponData['tax_input'] = array(
        self::TAX_SLUG => $taxonomies
      );
    }

    return wp_insert_post($couponData);
  }
}