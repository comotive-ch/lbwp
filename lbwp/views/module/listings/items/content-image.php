<?php
use LBWP\Util\WordPress;
use LBWP\Module\Listings\Core as ListingCore;
$item = ListingCore::getCurrentListElementItem();

// title as default output
$itemHtml = get_post_meta($item->ID, 'content-title', true);

// load image
$imageHtml = '';
$imageId = get_post_meta($item->ID, 'content-image', true);
$imageUrl = WordPress::getImageUrl($imageId, 'thumbnail');
if (strlen($imageUrl)) {
  $imageHtml = '<img src="' . $imageUrl . '" alt="' . WordPress::getImageAltText($imageId) . '" />';
}

// content-col
$content = '<h2>' . get_post_meta($item->ID, 'content-title', true) . '</h2>';
$content .= do_shortcode(wpautop(get_post_meta($item->ID, 'content-text', true)));

// check for link
$itemLink = get_post_meta($item->ID, 'content-link', true);
if (strlen($itemLink)) {
  $itemHtml = '
    <a href="' . $itemLink . '" target="' . get_post_meta($item->ID, 'logo-link-target', true) .'">' .
      $itemHtml .
    '</a>';
}

echo '
  <div>
    <div class="image">' . $imageHtml . '</div>
    <div class="content">' . $content . '</div>
  </div>'
;