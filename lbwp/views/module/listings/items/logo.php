<?php
use LBWP\Util\WordPress;
use LBWP\Module\Listings\Core as ListingCore;
$item = ListingCore::getCurrentListElementItem();

$itemHtml = '';

$itemTitle = get_post_meta($item->ID, 'logo-title', true);
if (strlen($itemTitle) > 0) {
  $itemTitle = '<span class="title">' . $itemTitle .'</span>';
}

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
      $itemHtml . $itemTitle .
    '</a>';
}

echo '<li><div>' . $itemHtml . '</div></li>';
