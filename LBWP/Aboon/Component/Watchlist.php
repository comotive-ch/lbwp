<?php

namespace LBWP\Aboon\Component;

use LBWP\Theme\Component\ACFBase;
use Standard03\Component\ACF;
use LBWP\Util\File;
use LBWP\Core;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Feature\FocusPoint;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;

/**
 * Provides for watchlist(s) functionality in the shop
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch
 */
class Watchlist extends ACFBase
{
	/**
	 * The meta key for the users watchlist
	 */
	const USER_LISTS_KEY = 'lbwp-watchlist';

  /**
   * List overview permalink slug
   */
  const LISTS_PERMALINK = 'merkliste';

	/**
	 * The meta key for the active list number
	 */
	const USER_ACTIVE_LIST_KEY = 'lbwp-watchlist-active';

	/**
	 * Name of the watchlist
	 */
	public $naming = array(
		's' => 'Merkliste',
		'p' => 'Merklisten'
	);

	/**
	 * Watchlist instance
	 */
	protected static $instance;

  /**
   * Initialize the watchlist component, which is nice
   */
  public function init()
  {
		self::$instance = $this;

		// Set the watchlist naming
		$this->setNaming();

		// Set the watchlist icon to use in the shop
		$this->icons = apply_filters('lbwp_override_watchlist_icons', array(
			'main' => '<i class="fa-light fa-heart"></i>',
			'remove' => '<i class="fa-light fa-circle-minus"></i>',
			'addCart' => '<i class="fa-light fa-cart-plus"></i>',
			'close' => '<i class="fa-light fa-xmark"></i>',
			'edit' => '<i class="fa-light fa-pen"></i>',
			'trash' => '<i class="fa-light fa-trash"></i>'
		));
    // Let them go trough the new standard filter as well
    foreach ($this->icons as $name => $icon) {
      $this->icons[$name] = apply_filters('aboon_general_icon_filter', $icon, 'aboon-watchlist-' . $name);
    }

		// Activats quantity input in the lists
		$this->displayQty = apply_filters('lbwp_watchlist_show_qty_in_list', false);

		// Add shortcode to display the shortcode menu
		add_shortcode('lbwp-watchlist-menu', array($this, 'setWatchlistMenuShorcode'));

		// Add teh watchlist button before the add to cart
		add_action('woocommerce_product_meta_start', array($this, 'addWatchlistButton'));

		// Add AJAX hooks
		add_action('wp_ajax_nopriv_updateListItem', array($this, 'updateListItem'));
		add_action('wp_ajax_updateListItem', array($this, 'updateListItem'));
		add_action('wp_ajax_nopriv_getWatchlistHtml', array($this, 'getWatchlistMenuHtml'));
		add_action('wp_ajax_getWatchlistHtml', array($this, 'getWatchlistMenuHtml'));
		add_action('wp_ajax_nopriv_setActiveWatchlist', array($this, 'setCurrentListAjax'));
		add_action('wp_ajax_setActiveWatchlist', array($this, 'setCurrentListAjax'));

		// Merge local and logged-in watchlist
		add_action('wp_ajax_nopriv_mergeWatchlists', array($this, 'mergeWatchlists'));
		add_action('wp_ajax_mergeWatchlists', array($this, 'mergeWatchlists'));

		// Add watchlist menu to myaccount page (remember to flush permalinks on activation)
		add_rewrite_endpoint(self::LISTS_PERMALINK, EP_ROOT | EP_PAGES);
		add_action('woocommerce_account_menu_items', array($this, 'addWatchlistAccountMenu'));
		add_action('woocommerce_account_' . self::LISTS_PERMALINK . '_endpoint', array($this, 'watchlistAccountPage'));
		$this->handleWatchlistForms($this->getWatchlists());
  }

  /**
   * Adds field settings
   */
  public function fields(){}

  /**
   * Registers no own blocks
   */
  public function blocks() {}

	/**
	 * Enqueue the assets
	 */
	public function assets(){
		$base = File::getResourceUri();
		wp_enqueue_script('watchlist-js', $base . '/js/aboon/watchlist.js', array('jquery'), Core::REVISION, true);

		$availableLists = $this->getWatchlists();
		wp_localize_script('watchlist-js', 'watchlistData',
				array(
						'ajaxUrl' => admin_url('admin-ajax.php'),
						'userLists' => json_encode($availableLists),
						'loggedInMode' => is_user_logged_in(),
				)
		);
	}

	/**
	 * Get the instance of the watchlist
	 *
	 * @return Watchlist
	 */
	public static function getInstance(){
		return self::$instance;
	}

	/**
	 * Check if the watchlist is active
	 *
	 * @return bool
	 */
	public static function isActive(){
		$getWlOption = get_field('watchlist-active', 'option');

		return is_array($getWlOption) && $getWlOption[0] == 1;
	}

	/**
	 * Set custom names for "watchlist"
	 */
	private function setNaming(){
		// Firstly check if the watchlist has a custom name
		$wlSettings = get_field('watchlist-settings', 'option');
		if(!empty($wlSettings['rename-watchlists'])){
			$this->naming['s'] = $wlSettings['wl-name-singular'];
			$this->naming['p'] = $wlSettings['wl-name-plural'];
		}

		// Make the names translatable
		$this->naming['s'] = __($this->naming['s'], 'lbwp');
		$this->naming['p'] = __($this->naming['p'], 'lbwp');
	}

	/**
	 * Get the watchlist html
	 *
	 * @param  bool $showQty if the quantity should be shown or not
	 */
	public function getWatchlistMenuHtml($showQty = null){
		$theWatchlist = $this->getWatchlists();
		$curList = $this->getCurrentList();

		if($_POST['useLocalList'] == 'true'){
			$theWatchlist = json_decode($_POST['localWatchlist'], true);
		}

		if($showQty !== true && $showQty !== false){
			$showQty = $this->displayQty;
		}

		$listSelection = '';
		if(count($theWatchlist) > 1){
			$listSelection = '<div class="lbwp-watchlist__select">
				<span>' . sprintf(__('Aktive %s', 'lbwp'), $this->naming['s']) . '</span>
				<select>';

			foreach($theWatchlist as $wListKey => $wList){
				$isActive = $curList == $wListKey;
				$wListKey = explode('_', $wListKey)[1];
				$listSelection .= '<option value="' . $wListKey . '"' . ($isActive ? ' selected' : '') . '>' . $wList['name'] . '</option>';
			}

			$listSelection .= '		
				</select>
			</div>';
		}

		$listHtml =  '
			<div class="lbwp-watchlist__menu">
				<div class="lbwp-watchlist__icon">
					' . $this->icons['main'] . '
				</div>
				<div class="lbwp-watchlist__listing">
					' . $listSelection . '
					<div class="lbwp-watchlist__close">' . $this->icons['close'] . '</div>
		';
		$list = $theWatchlist[$curList];
		$listProducts = '';

		if(!is_array($list)){
			$list = (array) $list;
		}

		$listProducts .= '
			<div class="lbwp-watchlist__list">
				<h5 data-wg-notranslate>' . $list['name'] . '</h5>
				<ul>';

		if(empty($list['products'])){
			$listProducts .= '<li class="empty-list-text">' . __('Diese Liste ist zurzeit noch leer.', 'lbwp') . '</li>';
		}else{
			foreach($list['products'] as $productId){
				$product = wc_get_product($productId);
        if (!($product instanceof \WC_Product)) {
          continue;
        }
				$hasVariants = !empty(get_field('has-variants', $productId));
				$prodLink = get_permalink($productId);
				$inputArgs = PackagingUnit::getInputArgs($product);
				$inStock = $product->is_in_stock();
				$qtyInput = '
				<input 
					type="number" 
					class="hide ' . implode(' ' , $inputArgs['classes']) . '" 
					step="' . $inputArgs['step'] . '" 
					min="' . $inputArgs['min_value'] . '" 
					max="" 
					name="quantity" 
					value="' . $inputArgs['min_value'] . '" 
					title="Menge" 
					placeholder="" 
					inputmode="numeric"
				>';

				$listProducts .= '<li' . ($showQty && !$hasVariants ? ' class="has-qty-input"' : '') . ' data-wg-notranslate>
					<a class="watchlist-product-image" href="' . $prodLink . '">' .
            apply_filters('lbwp_watchlist_list_item_image', $product->get_image(), $product)
          . '</a>
					<a class="watchlist-product-title" href="' . $prodLink . '">' .
						apply_filters('lbwp_watchlist_list_item_title', $product->get_name(), $product) .
					'</a>
					<div class="watchlist-product-actions">' .
						($showQty && !$hasVariants ? $qtyInput : '') .
						'<div class="lbwp-watchlist__' . ($hasVariants ? 'product-detail' : 'add-to-cart') . '">
							<a href="' .
              (!$hasVariants && $inStock ? $prodLink . '?add-to-cart=' . $productId . '&' : $prodLink . '?') . 'skip-notices' .
              '" rel="nofollow">' . $this->icons['addCart'] . '</a>
						</div>
						<div class="lbwp-watchlist__remove" data-product="' . $productId . '">' . $this->icons['remove'] . '</div>
					</div>
				</li>';
			}
		}

		$listProducts .= '</ul>';

		if(count($theWatchlist) > 1 && is_user_logged_in()){
			$listProducts .= '<a class="watchlist-overview-link" href="' . get_permalink(get_option('woocommerce_myaccount_page_id')) . self::LISTS_PERMALINK . '">' . __('Listen verwalten', 'lbwp') . '</a>';
		}

		$listProducts .= '</div>';

    if(defined('DOING_AJAX')){
      header('Content-Type:text/html');
      echo $listProducts;
      exit;
		}

		return $listHtml . $listProducts . '</div></div>';
	}

	/**
	 * Echo the watchlist menu
	 */
	public function setWatchlistMenuShorcode($attr){
		$attr = is_array($attr) ? $attr : array();
		$showQty = in_array('show-quantity', array_keys($attr)) || $this->displayQty;

		if($attr['show-qunatity'] == 'false'){
			$showQty = false;
		}

		echo $this->getWatchlistMenuHtml($showQty);
	}

	/**
	 * Adds the watchlist button before the add to cart button
	 */
	public function addWatchlistButton(){
		global $product;
		$text = $this->icons['main'] .
			'<span class="add-text">' . apply_filters('lbwp_custom_watchlist_icon_text', sprintf(__('Zur %s hinzufügen', 'lbwp'), $this->naming['s'])) . '</span>' .
			'<span class="remove-text">' . apply_filters('lbwp_custom_watchlist_icon_text_remove', sprintf(__('Von %s entfernen', 'lbwp'), $this->naming['s'])) . '</span>';
		echo self::watchlistButtonHtml($text, $product->get_id());
	}

	/**
	 * Get the watchlist add/remove button html
	 *
	 * @param  string $text the icon and the text html
	 * @param  int $pid the product id
	 * @return string the button html
	 */
	public static function watchlistButtonHtml($text, $pId){
		$theLists = get_user_meta(wp_get_current_user()->data->ID, self::USER_LISTS_KEY, true);
		$curList = self::getCurrentList();
		if($theLists == ''){
			$products = array();
		}else{
			$products = is_array($theLists[$curList]['products']) ? $theLists[$curList]['products'] : array();
		}

		if($text === ''){
			$text = self::getInstance()->icons['main'];
		}

		return '<div class="lbwp-add-to-watchlist' . (in_array($pId, $products) ? ' in-list' : '') . '" data-product-id="' . $pId . '">' . $text . '</div>';
	}

	/**
	 * Get the watchlists
	 */
	public function getWatchlists(){
		$getLists = get_user_meta(wp_get_current_user()->data->ID, self::USER_LISTS_KEY, true);
		if(Strings::isEmpty($getLists) || $getLists === false){
			$getLists = array(
				'list_0' => array(
					'name' => apply_filters('lbwp_watchlist_standard_name', $this->naming['s']),
					'date' => time(),
					'products' => array()
				)
			);
		}
		return $getLists;
	}

	/**
	 * The AJAX hook to add products to the list
	 */
	public function updateListItem(){
		$lists = $this->getWatchlists();
		$currentList = self::getCurrentList();
		$productId = intval($_POST['product']);

    if(isset($_POST['listId']) && $_POST['listId'] !== null){
      $currentList = $_POST['listId'];
    }

		if(in_array($productId, $lists[$currentList]['products'])){
			$delIndex = array_search($productId, $lists[$currentList]['products']);
			array_splice($lists[$currentList]['products'], $delIndex, 1);
		}else{
			$lists[$currentList]['products'][] = $productId;
		}

		update_user_meta(wp_get_current_user()->data->ID, self::USER_LISTS_KEY, $lists);
		exit;
	}

	/**
	 * Get the current active list (to be programmed)
	 */
	public static function getCurrentList(){
		$user = wp_get_current_user()->data->ID;
		$curList = 0;

		if($user != 0){
			$listNum = get_user_meta($user, self::USER_ACTIVE_LIST_KEY, true);

			if($listNum !== ''){
				$curList = $listNum;
			}
		}

		return 'list_' . $curList;
	}

	/**
	 * Set the current active list
	 *
	 * @param  int $list the list id. Important: only the number/id, the prefix "list_" is not required
	 * @return void
	 */
	public function setCurrentList($list){
		$user = wp_get_current_user()->data->ID;
		update_user_meta($user, self::USER_ACTIVE_LIST_KEY, $list);
	}

	/**
	 * Set the current list (for ajax calls only)
	 */
	public function setCurrentListAjax(){
		$user = wp_get_current_user()->data->ID;
		update_user_meta($user, self::USER_ACTIVE_LIST_KEY, $_POST['list']);

		wp_send_json($this->getWatchlistMenuHtml($this->displayQty));
	}

	/**
	 * Merge the local list with the logged-in lists
	*/
	public function mergeWatchlists(){
		$userWl = $this->getWatchlists();
		$localWl = (array) json_decode($_POST['localWatchlist']);

		if(empty($localWl['list_0']->products)){
			wp_send_json(true);
		}

		if(empty($userWl['list_0']['products'])){
			$userWl['list_0']['products'] = $localWl['list_0']->products;
			$userWl['list_0']['date'] = time();
		}else{
			$listCount = count($userWl);

			$newList = array(
				'list_' . $listCount => array(
					'name' => $this->naming['s'] . ' ' . $listCount,
					'date' => time(),
					'products' => $localWl['list_0']->products
				)
			);

			$userWl = array_merge($userWl, $newList);
		}

		update_user_meta(wp_get_current_user()->data->ID, self::USER_LISTS_KEY, $userWl);
		wp_send_json(true);
	}

	/**
	 * Add the watchlist menu to the account page
	 *
	 * @param  array $menuLinks the array with the menu items/links
	 * @return arrray the menu links
	 */
	public function addWatchlistAccountMenu($menuLinks){
		$menuLinks = array_slice($menuLinks, 0, 1, true) + array(
			'merkliste' => apply_filters('lbwp_watchlist_account_menu_name', sprintf(__('Meine %s', 'lbwp'), $this->naming['p']))
		) + array_slice($menuLinks, 1, null, true);

		return $menuLinks;
	}

	/**
	 * Display the lists html
	 */
	public function watchlistAccountPage(){
    $watchlists = $this->getWatchlists();
		$listsHtml = '<h2>' . sprintf(__('Deine %s', 'lbwp'), $this->naming['p']) . '</h2>';

		foreach($watchlists as $listKey => $list){
			$listHtml = '
				<div class="container lbwp-watchlist__account-list" data-list-id="' . $listKey . '">
				<div class="row lbwp-watchlist__list-name">
					<h3 data-wg-notranslate>' . $list['name'] . '</h3>
					<div class="lbwp-watchlist__edit-list-name">
						<div class="list-action-button">' . $this->icons['edit'] . '</div>
						<form method="post">
							<label>
								' . __('Liste umbenennen', 'lbwp'). '
								<input type="text" name="new-list-name" required/>
							</label>
							<input type="hidden" name="list-id" value="' . $listKey . '"/>
							<input type="submit" name="change-name" value="Speichern" class="btn btn-primary"/>
						</form>
					</div>
					' . ($listKey !== 'list_0' ?
						'<div class="lbwp-watchlist__delete-list">
							<div class="list-action-button">' . $this->icons['trash'] . '</div>
							<form method="post">
								<input type="hidden" name="list-id" value="' . $listKey . '"/>
								<input type="submit" name="delete-list" value="Liste endgültig löschen"/>
							</form>
						</div>' :
						''
					) . '
				</div>
				<div class="lbwp-watchlist__list row">
					<ul>';

			if(empty($list['products'])){
				$listHtml .= '<li>' . __('Diese Liste ist zurzeit noch leer.', 'lbwp'). '</li>';
			}else{
				foreach($list['products'] as $productId){
					$product = wc_get_product($productId);

          if ($product === false || $product === null) {
            continue;
          }

					$link = get_permalink($productId);
					$hasVariants = !empty(get_field('has-variants', $productId));
					$qtyInput = '';

					if($this->displayQty){
						$inputArgs = PackagingUnit::getInputArgs($product);
						$qtyInput = '
						<input 
							type="number" 
							class="hide ' . implode(' ' , $inputArgs['classes']) . '" 
							step="' . $inputArgs['step'] . '" 
							min="' . $inputArgs['min_value'] . '" 
							max="" 
							name="quantity" 
							value="' . $inputArgs['min_value'] . '" 
							title="Menge" 
							placeholder="" 
							inputmode="numeric"
						>';
					}

					$listHtml .= '
						<li data-wg-notranslate>
							<a href="' . $link . '" class="watchlist-product-image">' .
                apply_filters('lbwp_watchlist_list_item_image', $product->get_image(), $product)
              . '</a>
							<a href="' . $link . '" class="watchlist-product-title"><h4>' .
								apply_filters('lbwp_watchlist_list_item_title', $product->get_name(), $product) .
							'</h4></a>' .
							$qtyInput . '
							<a href="' . (!$hasVariants ? '?add-to-cart=' . $productId : $link) . '" rel="nofollow">' . $this->icons['addCart'] . '</a>
							<div class="lbwp-watchlist__remove" data-product="' . $product->get_id() . '">' . $this->icons['remove'] . '</div>
						</li>';
				}
			}

			$listsHtml .= $listHtml . '</ul></div></div>';
		}

		$addListHtml = '
			<div class="lbwp-watchlist__add-list">
			<div class="lbwp-watchlist__add-list">
				<h2>' . sprintf(__('%s hinzufügen', 'lbwp'), $this->naming['s']) . '</h2>
				<form method="post">
					<input type="text" name="new-list-name" required/>
					<input type="submit" name="add-new-list" value="' . __('Hinzufügen', 'lbwp') . '" class="btn btn-primary"/>
				</form>
			</div>
		';

		echo '<div class="lbwp-watchlist__account-listing">' .
			apply_filters('lbwp_watchlist_account_html', $listsHtml, $watchlists) .
			$addListHtml .
		'</div>';
	}

	/**
	 * Handle the watchlists forms
	 */
	public function handleWatchlistForms($lists){
		// Only do something if on the watchlist account page
		$curPage = get_bloginfo('url') . $_SERVER['REQUEST_URI'];
		$watchlistPage = get_permalink(get_option('woocommerce_myaccount_page_id')) . self::LISTS_PERMALINK . '/';

		if($curPage !== $watchlistPage){
			return $lists;
		}

		$userId = wp_get_current_user()->data->ID;

		// Change the name of the list
		if(isset($_POST['change-name'])){
			$listId = $_POST['list-id'];
			$newListName = $_POST['new-list-name'];

			if($newListName === ''){
				return $lists;
			}

			$lists[$listId]['name'] = $newListName;
			update_user_meta($userId, self::USER_LISTS_KEY, $lists);
		}

		// Add a new list
		if(isset($_POST['add-new-list'])){
			$newListName = $_POST['new-list-name'];
			$listCount = count($this->getWatchlists());

			if($newListName === ''){
				return $lists;
			}

			$newList = array(
				'list_' . $listCount => array(
					'name' => $newListName,
					'date' => time(),
					'products' => array()
				)
			);

			$newLists = array_merge($lists, $newList);
			$lists = $newLists;
			update_user_meta($userId, self::USER_LISTS_KEY, $lists);
		}

		// Delete a list
		if(isset($_POST['delete-list'])){
			$listId = $_POST['list-id'];
			unset($lists[$listId]);
			update_user_meta($userId, self::USER_LISTS_KEY, $lists);
			$this->setCurrentList(0);
		}

		return $lists;
	}
}