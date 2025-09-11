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
$imageId = get_post_thumbnail_id($item->ID);
$imageUrl = WordPress::getImageUrl($imageId, 'medium');
$imageAlt = WordPress::getImageAltText($imageId);
if (strlen($imageAlt) == 0) {
  $imageAlt = sprintf(__('Personenfoto von %s','lbwp'), $title);
}
if (strlen($imageUrl)) {
  $image = '<img src="' . $imageUrl . '" alt="' . $imageAlt . '" pagespeed_no_transform />';
}

// e-mail
$mail = get_post_meta($item->ID, 'email', true);
if (strlen($mail)) {
  $mail = '<span class="mail"><a href="' . Strings::convertToEntities('mailto:' . $mail) . '">' . Strings::convertToEntities($mail) . '</a></span>';
}

// phone
$phone = get_post_meta($item->ID, 'phone', true);
if (strlen($phone)) {
  $phone = '<span class="phone"><a href="tel:' . str_replace(' ', '', trim($phone)) . '">' . $phone . '</a></span>';
}

// roles
$role = get_post_meta($item->ID, 'role', true);
if (strlen($role)) {
  $role = '<span class="role">' . $role . '</span>';
}
$role2 = get_post_meta($item->ID, 'role-2', true);
if (strlen($role2)) {
  $role2 = '<span class="role-2">' . $role2 . '</span>';
}

$content =
  '<h3>' . $title . '</h3>
  <p class="attr">' . $role . $role2 . $mail . $phone . '</p>' .
  wpautop(get_post_meta($item->ID, 'description', true))
;

echo '
  <article>
    <div class="image">' . $image . '</div>
    <div class="text">' . $content . '</div>
  </article>
';