<?php
use LBWP\Util\WordPress;
use LBWP\Module\Listings\Core as ListingCore;
use LBWP\Util\Strings;

$item = ListingCore::getCurrentListElementItem();

// title as default output
$itemHtml = get_post_meta($item->ID, 'content-title', true);

// load image
$imageHtml = '';
$imageId = get_post_meta($item->ID, 'content-image', true);
//todo use medium in default template
$imageUrl = WordPress::getImageUrl($imageId, 'thumbnail');
if (strlen($imageUrl)) {
  $imageHtml = '
    <div class="image">
      <img src="' . $imageUrl . '" alt="' . WordPress::getImageAltText($imageId) . '" />
    </div>
    ';
}

// content-col
$content = '<h2>' . get_post_meta($item->ID, 'content-title', true) . '</h2>';
$content .= do_shortcode(wpautop(get_post_meta($item->ID, 'content-text', true)));

// Check for link
$itemLink = get_post_meta($item->ID, 'link', true);
if (strlen($itemLink) > 0 && Strings::isURL($itemLink)) {
  $itemLinkText = get_post_meta($item->ID, 'link-text', true);
  // Use default link text, if missing
  if (strlen($itemLinkText) == 0) {
    $itemLinkText = __('mehr', 'lbwp');
  }
  $itemLinkHtml = '
    <a class="more-link" href="' . $itemLink . '" target="' . get_post_meta($item->ID, 'logo-link-target', true) .'">' .
      $itemLinkText .
    '</a>';
  // Append link to the past <p> of $content
  $content = Strings::replaceLastOccurence('</p>',$itemLinkHtml,$content);
}

echo '
  <div>
    ' . $imageHtml . '
    <div class="content">' . $content . '</div>
  </div>'
;