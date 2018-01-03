<?php
use LBWP\Util\WordPress;
use LBWP\Module\Listings\Core as ListingCore;
use LBWP\Util\Strings;
use LBWP\Theme\Feature\FocusPoint;
use LBWP\Util\Templating;

$item = ListingCore::getCurrentListElementItem();

// title as default output
$itemHtml = get_post_meta($item->ID, 'content-title', true);

// load image
$imageHtml = '';
$imageId = get_post_thumbnail_id($item->ID);
if (strlen($imageId)) {
  $imageHtml = '
    <div class="image">
      ' . FocusPoint::getFeaturedImage($item->ID,'medium') . '
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
    <a class="more-link" href="' . $itemLink . '" ' . Templating::autoTargetBlank($itemLink) . '>' .
      $itemLinkText .
    '</a>';
  // Append link to the past <p> of $content
  $content = Strings::replaceLastOccurence('</p>',$itemLinkHtml,$content);
  // Append link to the image too
  $imageHtml = '
    <a href="' . $itemLink . '" ' . Templating::autoTargetBlank($itemLink) . '>
    ' . $imageHtml . '
    </a>';
}

echo '
  <div>
    ' . $imageHtml . '
    <div class="content">' . $content . '</div>
  </div>'
;