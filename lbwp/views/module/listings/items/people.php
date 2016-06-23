<?php
use LBWP\Util\WordPress;
use LBWP\Util\Strings;
use LBWP\Module\Listings\Core as ListingCore;
$item = ListingCore::getCurrentListElementItem();

// title (people's name)
$title = '';
$title = trim(implode(' ', array(
  get_post_meta($item->ID, 'salutation', true),
  get_post_meta($item->ID, 'firstname', true),
  get_post_meta($item->ID, 'lastname', true)
)));

// load image
$image = '';
$imageId = get_post_meta($item->ID, 'avatar', true);
$imageUrl = WordPress::getImageUrl($imageId, 'medium');
$imageAlt = WordPress::getImageAltText($imageId);
if (strlen($imageAlt) == 0) {
  $imageAlt = sprintf(__('Personenfoto von %s','lbwp'), $title);
}
if (strlen($imageUrl)) {
  $image = '<img src="' . $imageUrl . '" alt="' . $imageAlt . '" />';
}

// e-mail
$mail = get_post_meta($item->ID, 'email', true);
if (strlen($mail)) {
  $mail = '<a href="' . Strings::convertToEntities('mailto:' . $mail) . '">' . Strings::convertToEntities($mail) . '</a>';
}

// subtitle
$subtitle = '';
$role = get_post_meta($item->ID, 'role', true);
if (strlen($role)) {
  $subtitle = $role;
}
if (strlen($mail)) {
  $subtitle .= ', ' . $mail;
}

$content =
  '<h3>' . $title . '</h3>
  <p class="subtitle">' . $subtitle . '</p>' .
  wpautop(get_post_meta($item->ID, 'description', true))
;

echo '
  <article>
    <div class="image">' . $image . '</div>
    <div class="text">' . $content . '</div>
  </article>
';