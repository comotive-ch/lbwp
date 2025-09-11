<?php

namespace LBWP\Aboon\Component;

use LBWP\Theme\Base\Component;
use LBWP\Util\Date;
use LBWP\Util\Strings;

/**
 * Shows the purchase history in the user backend
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class PurchaseHistory extends Component
{
  public function init()
  {
    // Add history menu to myaccount page (remember to flush permalinks on activation)
    add_rewrite_endpoint('bestellhistorie', EP_ROOT | EP_PAGES);
    add_action('woocommerce_account_menu_items', array($this, 'addHistoryAccountMenu'));
    add_action('woocommerce_account_bestellhistorie_endpoint', array($this, 'historyAccountPage'), 99);

    // Setup rest api
    add_action('rest_api_init', function () {
      register_rest_route('user', '/purchase-history', array(
        'methods' => 'POST',
        'callback' => array($this, 'getProductsHtml'),
        'permission_callback' => function () {
          return is_user_logged_in();
        },
      ));
    });

    $this->settings = get_field('purchase-history-settings', 'option');
  }

  /**
   * Check if component is active
   * @return bool
   */
  public static function isActive(){
    $phActive = get_field('purchase-history-active', 'option');

    return is_array($phActive) && $phActive[0] == 1;
  }

  public function getProductsHtml($data){
    $page = isset($data['page']) ? intval($data['page']) : 1;
    $numProducts = apply_filters('lbwp_purchase_history_shown_products', 24);

    // wp_cache_start_transaction();
    $purchasedProducts = self::getProducts();
    $offset = ($page - 1) * $numProducts;

    if($offset >= count($purchasedProducts)){
      return json_encode('');
    }

    $products = wc_get_products(array(
      'include' => array_slice($purchasedProducts, ($page - 1) * $numProducts, $numProducts),
      'limit' => -1,
      'status' => array('publish'),
    ));

    $productHtml = '';
    $filterComponent = $this->getTheme()->searchUniqueComponent('Filter');

    foreach ($products as $wcProduct){
      $productHtml .= apply_filters('lbwp_purchase_history_product_html', $filterComponent->getSingleProductHtml($wcProduct), $wcProduct);
    }
    // wp_cache_commit_transaction();

    return json_encode($productHtml);
  }

  /**
   * Get the purchased products of the current user
   * @return array|false|int[]|string[]
   */
  public static function getProducts()
  {
    $user = wp_get_current_user();
    if($user->ID === 0){
      return false;
    }

    $cachedProducts = wp_cache_get('purchaseHistoryProducts_' . $user->ID);
    if($cachedProducts !== false && is_array($cachedProducts)) {
      return $cachedProducts;
    }

    wp_cache_start_transaction();
    $orders = wc_get_orders(array(
      'limit' => -1,
      'orderby' => 'date',
      'order' => 'DESC',
      'customer_id' => $user->ID,
    ));

    $products = array();
    foreach($orders as $order){
      foreach($order->get_items() as $item){
        $products[] = $item['product_id'];
      }
    }

    $products = array_unique($products);
    wp_cache_set('purchaseHistoryProducts_' . $user->ID, $products, '', 3600);
    wp_cache_commit_transaction();

    return $products;
  }

	/**
	 * Check if the purchase history is active
	 *
	 * @return bool
	 */
	public function addHistoryAccountMenu($menuLinks)
  {
    $menuTitle = Strings::isEmpty($this->settings['menu-title']) ? 'Gekaufte Artikel' : $this->settings['menu-title'];
    $menuLinks =
        array_slice($menuLinks, 0, 2, true) +
        array('bestellhistorie' => apply_filters('lbwp_watchlist_account_menu_name', __($menuTitle, 'lbwp'))) +
        array_slice($menuLinks, 2, null, true);

    return $menuLinks;
  }

  /**
   * The purchase history page
   * @return void
   */
  public function historyAccountPage()
  {
    $purchasedProducts = self::getProducts();
    $productHtml = json_decode($this->getProductsHtml([]));

    echo '
      <div class="purchase-history__heading">
        <h2>' . $this->settings['heading'] . '</h2>
        <p>' . (empty($purchasedProducts) ? $this->settings['no-products-text'] : $this->settings['text']) . '</p>
      </div>
      <div class="lbwp-wc__product-listing purchase-history__products row">' .
        $productHtml .
        '<div class="lbwp-wc__product-listing--load-more">
          <button class="btn btn--primary" id="lbwp-purchase-history-load-more">' . __('Mehr Produkte laden', 'lbwp') . '</button>
        </div>    
      </div>
      <script>
        let ajaxBtn = document.getElementById("lbwp-purchase-history-load-more");
        let page = 1;
        
        ajaxBtn.addEventListener("click", function() {
          ajaxBtn.classList.add("loading");
          page++;
          let data = {
            action: "lbwp_purchase_history_get_products",
            page: page
          };
          
          fetch("' . esc_url(rest_url('user/purchase-history')) . '", {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              "X-WP-Nonce": "' . wp_create_nonce('wp_rest') . '"
            },
            body: JSON.stringify(data)
          })
          .then(response => response.json())
          .then(data => {
            ajaxBtn.classList.remove("loading");
            data = JSON.parse(data)
            let loadMoreCon = document.querySelector(".lbwp-wc__product-listing--load-more");  
                        
            if(data.trim() === "") {
              ajaxBtn.remove();
              loadMoreCon.innerHTML("<p>' . __("Keine weitere Produkte verfügbar", "lbwp") . '</p>");
            } else {
              loadMoreCon.insertAdjacentHTML("beforebegin", data);
            }
          });
        });
      </script>
      ';
  }
}