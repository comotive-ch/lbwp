<?php

$original = '/var/www/lbwp/wp-content/plugins/woocommerce-subscriptions/languages/woocommerce-subscriptions-de_DE.po';
$output = '/var/www/lbwp/wp-content/plugins/woocommerce-subscriptions/languages/woocommerce-subscriptions-de_DE.po';

$replaces = array(
  'Hi %s,' => 'Guten Tag',
  'Du bist' => 'Sie sind',
  'du bist' => 'sie sind',
  'Bist du' => 'Sind sie',
  'bist du' => 'sind sie',
  'Möchten du' => 'Möchten sie',
  'Möchtest du' => 'Möchten sie',
  'möchten du' => 'möchten sie',
  'möchtest du' => 'möchten sie',
  'Kannst du' => 'Können sie',
  'kannst du' => 'können sie',
  'Du musst' => 'Sie müssen',
  'du musst' => 'sie müssen',
  'hättest du' => 'hätten sie',
  'melde dich in deinem' => 'melden sie sich in ihrem',
  'Sieh dir' => 'Sehen Sie sich',
  'Lade deine' => 'Laden sie sich ihre',
  'lade deine' => 'laden sie sich ihre',
  'du nicht möchtest' => 'sie nicht möchten',
  'Dieses Abonnement-Produkte' => 'Dieses Abonnement-Produkt',
  'wende dich' => 'wenden sie sich',
  'benötigst' => 'benötigen',
  'Du hast' => 'Sie haben',
  'du hast' => 'sie haben',
  'Du kannst' => 'Sie können',
  'du kannst' => 'sie können',
  'kontaktiere uns' => 'kontaktieren sie uns',
  'du es bezahlt hast' => 'sie es bezahlt haben',
  'speicherst du' => 'speichern sie',
  'Zahlungen verwendest' => 'Zahlungen verwenden',
  'Bitte sieh dir' => 'Bitte sehen sie sich',
  'du es aktivierst' => 'sie es aktivieren',
  'aktualisiert hast' => 'aktualisiert haben',
  'bearbeitet hast' => 'bearbeitet haben',
  'weisst du' => 'wissen sie',
  'findest du sie' => 'finden sie sie',
  'ß' => 'ss',
  'Schliesse die Zahlung' => 'Schliessen sie die Zahlung',
  ' deine ' => ' ihre ',
  'deinem ' => 'ihrem ',
  'deines ' => 'ihres ',
  'deiner ' => 'ihrer ',
  ' dein ' => ' ihr ',
  'möchtest' => 'möchten',
  'Du ' => 'Sie ',
  ' du ' => ' sie ',
);

$text = file_get_contents($original);
foreach ($replaces as $search => $replace) {
  $text = str_replace($search, $replace, $text);
}

file_put_contents($output, $text);
