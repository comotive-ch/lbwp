<?php

namespace LBWP\Aboon\Component;

use LBWP\Aboon\Base\Shop;
use LBWP\Core;
use LBWP\Helper\Import\Csv;
use LBWP\Module\Backend\S3Upload;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\File;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Google shopping integration
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch
 */
class GoogleShopping extends ACFBase
{
	/**
	 * Google shopping instance
	 */
	protected static $instance;

  /**
   * Initialize the component, which is nice
   */
  public function init()
  {
		self::$instance = $this;

    add_action('cron_daily_1', array($this, 'generateXMLFile'));
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
	public function assets(){}

	/**
	 * Get the instance of google shopping
	 *
	 * @return GoogleShopping
	 */
	public static function getInstance(){
		return self::$instance;
	}

	/**
	 * Check if the component is active
	 *
	 * @return bool
	 */
	public static function isActive(){
		$setting = get_field('google-shopping-integration', 'option');

		return is_array($setting) && $setting[0] == 1;
	}

  public function generateXMLFile(){
    $db = WordPress::getDb();
    $sql = apply_filters(
      'aboon_google_shopping_items_query',
      'SELECT ID FROM ' . $db->posts . ' WHERE post_type = "product" AND post_status = "publish"'
    );
    $productIds = $db->get_col($sql);
    $taxIncluded = get_option('woocommerce_prices_include_tax') === 'yes';
    $taxRate = Shop::getStandardTaxes()[0];
    $taxRate = 1 + ($taxRate['tax_rate'] / 100);
    $currency = get_woocommerce_currency();
    $stockActive = get_option('woocommerce_manage_stock') === 'yes';
    $items = '';
    $rawItems = array();

    foreach ($productIds as $productId) {
      $product = wc_get_product($productId);
      $imageUrl = get_the_post_thumbnail_url($productId, 'medium');
      // No image, no google shopping
      if ($imageUrl === false || strlen($imageUrl) == 0) {
        continue;
      }
      $categories = wp_get_post_terms($productId, 'product_cat', array('fields' => 'names'));
      $title = $this->fixml(Strings::chopToSentences($product->get_title(), 100, 150, false, array(','), true));
      $price = floatval($product->get_price());

      // Check if price is including tax
      if (!$taxIncluded) {
        $price = round(($price * $taxRate) * 2, 1) / 2;
      }

      $stockStatus = 'in_stock';
      $availabilityDate = current_time('timestamp');
      // Check actual stock status from additionals which can have different status
      if($stockActive){
        if($product->managing_stock()){
          if($product->get_stock_quantity() < 0){
            $stockStatus = 'backorder';
            $availabilityDate = current_time('timestamp') + 60 * 60 * 24 * 14;
          }
        }else{
          if($product->get_stock_status() !== 'instock'){
            $stockStatus = 'backorder';
            $availabilityDate = current_time('timestamp') + 60 * 60 * 24 * 14;
          }
        }
      }

      $rawItem = array(
        'link' => get_permalink($productId),
        'id' => $product->get_id(),
        'title' => $title,
        'description' => $this->fixml(strip_tags($product->get_description())),
        'price' => $price . ' ' . $currency,
        'type' => $this->fixml(implode(' > ', $categories)),
        'image_link' => $imageUrl,
        'availability' => $stockStatus,
        'availability_date' => date('Y-m-d', $availabilityDate),
        'condition' => 'new'
      );
      $rawItem = apply_filters('aboon_generate_google_shopping_item', $rawItem, $product);
      $rawItems[] = $rawItem;

      // Build basic item properties

      $items .= '<item><link>' . $rawItem['link'] . '</link>' . PHP_EOL;
      foreach ($rawItem as $key => $value) {
        if ($key === 'link') continue;
        $items .= '<g:' . $key . '>' . $value . '</g:' . $key . '>' . PHP_EOL;
      }
      $items .= '</item>' . PHP_EOL;
    }

    $tempfile = tempnam('/tmp', 'aboon');
    $fileName = 'google-shopping';
    $filePath = get_bloginfo('url') . '/assets/lbwp-cdn/' . ASSET_KEY . '/files/shop/' . $fileName;
    $gsSettings = get_field('google-shopping-settings', 'option');

    // Build xml and add the items
    $xml = '<?xml version="1.0" encoding="utf-8"?>
    <rss xmlns:g="http://base.google.com/ns/1.0" xmlns:atom="http://www.w3.org/2005/Atom" version="2.0">
      <channel>
        <atom:link href="' . $filePath . '.xml" rel="self" type="application/rss+xml" />
        <title>' . $gsSettings['company-name'] . '</title>
        <description>' . get_bloginfo('title') . '</description>
        <link>' . get_bloginfo('url') . '</link>
        <language>' . get_bloginfo('language') . '</language>
        <image>
          <url>' . wp_get_attachment_url($gsSettings['logo']) . '</url>
          <title>' . $gsSettings['company-name'] . '</title>
        </image>
        ' . $items . '
        </channel>
      </rss>';

    /** @var S3Upload $s3 Put the file publicly accessible */
    file_put_contents($tempfile, $xml);
    $s3 = Core::getModule('S3Upload');
    $s3->uploadDiskFileFixedPath($tempfile, '/shop/' . $fileName . '.xml', 'application/rss+xml;charset=UTF-8', true);
    // Make another one for with CSV for aimondo
    $tempfile = tempnam('/tmp', 'aboon');
    Csv::write($rawItems, $tempfile);
    $s3->uploadDiskFileFixedPath($tempfile, '/shop/' . $fileName . '.csv', 'text/csv;charset=UTF-8', true);
  }

  /**
   * @param $string
   * @return array|string|string[]
   */
  protected function fixml($string)
  {
    $string = str_replace(' & ', ' und ', $string);
    $string = str_replace(' &amp; ', ' und ', $string);
    $string = str_replace('&nbsp;', ' ', $string);
    return $string;
  }
}