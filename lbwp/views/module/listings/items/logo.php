<?php
use LBWP\Util\WordPress;
use LBWP\Module\Listings\Core as ListingCore;
$item = ListingCore::getCurrentListElementItem();

// title as default output
$itemHtml = get_post_meta($item->ID, 'logo-title', true);

// load image
$imageId = get_post_thumbnail_id($item->ID);
$imageUrl = WordPress::getImageUrl($imageId, 'medium');
if (strlen($imageUrl)) {
  $itemHtml = '<img src="' . $imageUrl . '" alt="' . WordPress::getImageAltText($imageId) . '" />';
}

// check for link
$itemLink = get_post_meta($item->ID, 'logo-link', true);
if (strlen($itemLink)) {
  $itemHtml = '
    <a href="' . $itemLink . '" target="' . get_post_meta($item->ID, 'logo-link-target', true) .'">' .
      $itemHtml .
    '</a>';
}

echo '<li><div>' . $itemHtml . '</div></li>';
