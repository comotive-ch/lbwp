<?php

$languages = array(
  'de_DE',
  'en_GB',
  'it_IT',
  'fr_FR',
);

foreach(scandir('/var/www/lbwp/wp-content/plugins/complianz-gdpr-premium/languages') as $file){
  if($file === '.' || $file === '..' || $file === 'index.php' || $file === 'complianz-gdpr.pot'){
    continue;
  }

  $delete = true;
  foreach($languages as $lang){
    if(str_starts_with($file, 'complianz-gdpr-' . $lang)){
      $delete = false;
      break;
    }
  }

  if($delete){
    unlink('/var/www/lbwp/wp-content/plugins/complianz-gdpr-premium/languages/' . $file);
  }
}
