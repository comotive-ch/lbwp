<?php
/**
 * This file is loaded only if needed in Module_FeatureConfig, hence
 * $this referenced the instance of the related module.
 */
$this->featureData = array();


/**
 * Proved Plugins
 */
$this->featureData['CorePackages'] = array(
  'name' => 'LBWP Grundpakete',
  'description' => '
    Grundpakete für Ihr WordPress Hosting. Bitte<br/>
    kontaktieren Sie uns, wenn Sie einen Änderungswunsch haben.
  ',
  'icon' => '/wp-content/plugins/lbwp/resources/images/cfg/plugin.png',
  'sub' => array()
);

$this->featureData['CorePackages']['sub']['LbwpProfessional'] = array(
  'name' => 'Loadbalanced WordPress',
  'state' => 'editable',
  'costs' => 680,
  'description' => '
    Managed WordPress Hosting mit Telefonsupport und E-Mail Support,
    Backups, automatischer Skalierung und Ausfallsicherung. Updates werden
    geprüft und automatisch eingespielt. Und: Super schnell.
  ',
  'availabilityCallback' => array($this, 'isSuperuser')
);

$this->featureData['CorePackages']['sub']['LbwpSimple'] = array(
  'name' => 'Non-Profit LBWP',
  'state' => 'editable',
  'adminonly' => true,
  'costs' => 280,
  'description' => '
    Loadbalanced WordPress. Aber als Einzelperson, NGO und/oder guter
    Freund der Firma hast du diesen Spezialpreis bekommen.
  ',
  'availabilityCallback' => array($this, 'isSuperuser')
);

$this->featureData['CorePackages']['sub']['DnsHosting'] = array(
  'name' => 'Nur DNS Hosting',
  'state' => 'editable',
  'description' => 'Hochverfügbares Hosting Ihrer www-Adresse bei uns. Mail-Dienste sind jedoch nicht inklusive.',
  'availabilityCallback' => array($this, 'isSuperuser')
);

$this->featureData['CorePackages']['sub']['DnsHostingMail'] = array(
  'name' => 'DNS Hosting + E-Mail',
  'state' => 'editable',
  'description' => 'Hosting ihrer www-Adresse bei unserem Mail-Service Partner. (Mailboxen exkl.)',
  'availabilityCallback' => array($this, 'isSuperuser')
);

$this->featureData['CorePackages']['sub']['ForcedSSL'] = array(
  'name' => 'Verschlüsselung / SSL',
  'state' => 'editable',
  'costs' => 125,
  'description' => 'Durchgehende Verschlüsselung ihrer Webseite. Inklusive weltweit gültigem Zertifikat.',
  'availabilityCallback' => array($this, 'isSuperuser')
);


/**
 * Proved Plugins
 */
$this->featureData['Plugins'] = array(
  'name' => 'Geprüfte Plugins',
  'description' => 'Für LBWP geprüfte Plugins.',
  'icon' => '/wp-content/plugins/lbwp/resources/images/cfg/plugin.png',
  'sub' => array()
);

$this->featureData['Plugins']['sub']['GoogleXmlSitemap'] = array(
  'name' => 'Google XML Sitemaps',
  'state' => 'editable',
  'description' => 'Generiert automatisch eine XML Sitemap für die Google Webmaster Tools.',
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => 'google-sitemap-generator/sitemap.php')
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => 'google-sitemap-generator/sitemap.php')
  )
);

$this->featureData['Plugins']['sub']['AntispamBee'] = array(
  'name' => 'Antispam Bee',
  'state' => 'editable',
  'description' => 'Verhindert Kommentar-/ und Trackbackspam.',
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => 'antispam-bee/antispam_bee.php')
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => 'antispam-bee/antispam_bee.php')
  )
);

$this->featureData['Plugins']['sub']['TinyMceAdvanced'] = array(
  'name' => 'TinyMCE Advanced',
  'state' => 'editable',
  'description' => 'Erlaubt es den Editor beliebig zu konfigurieren. Unter anderem sehr praktisch für Tabellen.',
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => 'tinymce-advanced/tinymce-advanced.php')
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => 'tinymce-advanced/tinymce-advanced.php')
  )
);

$this->featureData['Plugins']['sub']['wpSeo'] = array(
  'name' => 'wpSEO',
  'state' => 'editable',
  'description' => 'Eines der bekanntesten SEO Plugins um die Sichtbarkeit in Suchmaschinen zu verbessern. Wenden Sie sich an uns, damit wir die Lizenz für Sie eintragen können.',
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => 'wpseo/wpseo.php')
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => 'wpseo/wpseo.php')
  )
);

$this->featureData['Plugins']['sub']['wpSeoYoast'] = array(
  'name' => 'WordPress SEO by Yoast',
  'state' => 'editable',
  'description' => 'Dieses SEO Plugin erlaubt verschiedene Einstellungen für Profis. Gerne können wir Ihnen helfen, ihre Auffindbarkeit damit zu verbessern.',
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => 'wordpress-seo/wp-seo.php')
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => 'wordpress-seo/wp-seo.php')
  )
);

$this->featureData['Plugins']['sub']['analyticsYoast'] = array(
  'name' => 'Google Analytics by Yoast',
  'state' => 'editable',
  'description' => 'Dieses Plugin erlaubt es, einfache Besucherstatistiken im Admin-Bereich anzuzeigen und Google Tracking Codes zu konfigurieren.',
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => 'google-analytics-for-wordpress/googleanalytics.php')
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => 'google-analytics-for-wordpress/googleanalytics.php')
  )
);

$this->featureData['Plugins']['sub']['CustomSidebars'] = array(
  'name' => 'Verbesserte Sidebar Einstellungen',
  'state' => 'editable',
  'description' => '
    Dieses Plugin erlaubt es eigene Sidebars für Kategorienseiten, einzelne Beiträge und Seiten und verschiedene
    Spezialseiten (z.B. Startseite, 404 Seite, etc.) anzulegen.
  ',
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => 'custom-sidebars/customsidebars.php')
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => 'custom-sidebars/customsidebars.php')
  )
);

$this->featureData['Plugins']['sub']['EnhancedMediaLibrary'] = array(
  'name' => 'Kategorisierung von Medien',
  'state' => 'editable',
  'description' => '
    Dieses Plugin erlaubt es Medien in Kategorien- und Unterkategorien einzuteilen und
    die Medien im Editor zu filtern.
  ',
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => 'enhanced-media-library/enhanced-media-library.php')
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => 'enhanced-media-library/enhanced-media-library.php')
  )
);

$this->featureData['Plugins']['sub']['Polylang'] = array(
  'name' => 'Mehrsprachigkeit',
  'state' => 'editable',
  'description' => '
    Mithilfe von Polylang und eigenen Erweiterungen ermöglicht dieses Plugin, dass die Webseite in
    einer beliebigen Anzahl Sprachen verwaltet und angezeigt werden kann.
  ',
  'costs' => 645,
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => 'polylang/polylang.php')
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => 'polylang/polylang.php')
  )
);

$this->featureData['Plugins']['sub']['WooCommerce'] = array(
  'name' => 'WooCommerce',
  'state' => 'editable',
  'description' => 'Bekanntes Shop-Plugin. Bitte beachten Sie, dass Sie ein Theme benötigen welches einen WooCommerce Shop unterstützt.',
  'costs' => 255,
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => array(
      'woocommerce/woocommerce.php',
      'woocommerce-de/woocommerce-de.php',
    ))
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => array(
      'woocommerce/woocommerce.php',
      'woocommerce-de/woocommerce-de.php',
    ))
  )
);

/**
 * WooCommerce payment gateways
 */
$this->featureData['Plugins']['sub']['WooCommercePostFinance'] = array(
  'name' => 'WooCommerce PostFinance',
  'state' => 'editable',
  'description' => 'Anbindung der PostFinance Zahlungssoftware. Damit wird die Zahlung via PostCard und Kreditkarten ermöglicht.',
  'costs' => 200,
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => 'woocommerce_postfinancecw/woocommerce_postfinancecw.php')
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => 'woocommerce_postfinancecw/woocommerce_postfinancecw.php')
  )
);

/**
 * Output Filter Modules
 */
$this->featureData['OutputFilterFeatures'] = array(
  'name' => 'Die verschiedenen Ausgabe-Filter',
  'description' => 'Filter um HTML autom. zu generieren / modifizieren.',
  'icon' => '/wp-content/plugins/lbwp/resources/images/cfg/filter.png',
  'sub' => array()
);

$this->featureData['OutputFilterFeatures']['sub']['SingleOgTags'] = array(
  'name' => 'Facebook Metainformationen',
  'state' => 'editable',
  'description' => 'Auf Facebook geteilte Artikel laden dadurch garantiert den Auszug und das Artikelbild (wenn eingegeben).'
);

$this->featureData['OutputFilterFeatures']['sub']['CompressCssJs'] = array(
  'name' => 'Komprimieren von CSS/JS Dateien',
  'state' => 'editable',
  'description' => '
    Komprimiert CSS/JS Dateien und gibt diese direkt im HTML Code aus, um so die Benutzer-Ladezeit deutlich zu senken.
    Benötigt dafür etwas mehr Server-Ladezeit. Optimal verwendet mit den Frontend Cache.
  '
);

$this->featureData['OutputFilterFeatures']['sub']['CloudFrontFilter'] = array(
  'name' => 'Dateiauslieferung mit CloudFront',
  'state' => 'editable',
  'description' => '
    Bilder, CSS und JavaScript Dateien werden nicht mehr über Amazon S3 sondern CloudFront ausgeliefert. Die Ladezeit mit
    CloudFront kann im besten Fall bis zu dreimal schneller sein, die Dateien werden 24h zwischengespeichert.
  '
);

$this->featureData['OutputFilterFeatures']['sub']['HeaderFooterFilter'] = array(
  'name' => 'Header/Footer Metainformationen',
  'state' => 'editable',
  'description' => 'Bietet diverse Einstellungen um verschiedene Informationen in Header und Footer des Quellcodes einzufügen.'
);


/**
 * Frontend Modules
 */
$this->featureData['FrontendModules'] = array(
  'name' => 'Frontend Module',
  'description' => 'Module, welche nur im Frontend geladen werden.',
  'icon' => '/wp-content/plugins/lbwp/resources/images/cfg/module.png',
  'sub' => array()
);

$this->featureData['FrontendModules']['sub']['HTMLCache'] = array(
  'name' => 'Frontend HTML-Cache',
  'class' => '\\LBWP\\Module\\Frontend\\HTMLCache',
  'state' => 'editable',
  'description' => 'Generiert HTML und speichert es für eine definierte Zeit in Memcached. Senkt die Ladezeit deutlich.'
);

$this->featureData['FrontendModules']['sub']['CronHandler'] = array(
  'name' => 'Job-Server Manager',
  'class' => '\\LBWP\\Module\\General\\CronHandler',
  'state' => 'editable',
  'description' => 'Sorgt dafür, dass der externe Job-Server seine täglichen/stündlichen Aufgaben verrichtet.'
);

$this->featureData['FrontendModules']['sub']['OutputFilter'] = array(
  'name' => 'Ausgabe-Filter',
  'class' => '\\LBWP\\Module\\Frontend\\OutputFilter',
  'state' => 'editable',
  'description' => 'De-/Aktiviert die verschiedenen Ausgabe-Filter.'
);

$this->featureData['FrontendModules']['sub']['SimpleFancybox'] = array(
  'name' => 'Automatische FancyBox',
  'class' => '\\LBWP\\Module\\Frontend\\SimpleFancybox',
  'state' => 'editable',
  'description' => 'Alle verlinkten Grafiken werden automatisch in einem grossen Overlay angezeigt.'
);


/**
 * Cron Modules
 */
$this->featureData['Crons'] = array(
  'name' => 'Job-Server Aufgaben',
  'description' => 'Aufgaben, welche der Job-Server erledigt.',
  'icon' => '/wp-content/plugins/lbwp/resources/images/cfg/job.png',
  'sub' => array()
);

$this->featureData['Crons']['sub']['CleanRevisions'] = array(
  'name' => 'Alte Revisionen löschen',
  'state' => 'editable',
  'description' => 'Löscht Revisionen und Automatische Speicherungen älter als 60 Tage.'
);

$this->featureData['Crons']['sub']['CleanCommentSpam'] = array(
  'name' => 'Kommentarspam löschen',
  'state' => 'editable',
  'description' => 'Löscht Kommentarspam der älter als 14 Tage ist.'
);


/**
 * Backend Modules
 */
$this->featureData['BackendModules'] = array(
  'name' => 'Backend Module',
  'description' => 'Module, welche nur im Backend geladen werden.',
  'icon' => '/wp-content/plugins/lbwp/resources/images/cfg/module.png',
  'sub' => array()
);

$this->featureData['BackendModules']['sub']['MemcachedAdmin'] = array(
  'name' => 'Memcached Administration',
  'class' => '\\LBWP\\Module\\Backend\\MemcachedAdmin',
  'state' => 'disabled',
  'description' => 'Administrations-Oberfläche für Memcached.'
);

$this->featureData['BackendModules']['sub']['DevTools'] = array(
  'name' => 'Entwickler Werkzeuge',
  'class' => '\\LBWP\\Module\\Backend\\DevTools',
  'state' => 'disabled',
  'description' => 'Werkzeuge für Entwickler. Nur für lokale Entwicklung benötigt.'
);

$this->featureData['BackendModules']['sub']['PostDuplicate'] = array(
  'class' => '\\LBWP\\Module\\Backend\\PostDuplicate',
  'state' => 'invisible'
);


/**
 * Public Modules
 */
$this->featureData['PublicModules'] = array(
  'name' => 'Generelle Module',
  'description' => 'Verschiedene Module und Custom Post Types.',
  'icon' => '/wp-content/plugins/lbwp/resources/images/cfg/module.png',
  'sub' => array()
);

$this->featureData['PublicModules']['sub']['NewsletterBase'] = array(
  'name' => 'Newsletter',
  'state' => 'editable',
  'class' => '\\LBWP\\Newsletter\\Core',
  'costs' => 255,
  'description' => '
    Erstellen Sie aus Beiträgen Ihre Newsletter mit verschieden Designs und erledigen Sie den
    zeitgesteuerten Versand einfach via Mailchimp.
  ',
  'install' => array(
    'callback' => array($this,'installPlugin'),
    'params' => array('files' => 'comotive-newsletter/newsletter.php')
  ),
  'uninstall' => array(
    'callback' => array($this,'uninstallPlugin'),
    'params' => array('files' => 'comotive-newsletter/newsletter.php')
  )
);
$this->featureData['PublicModules']['sub']['NewsletterDeactivated'] = array(
  'name' => 'Newsletter Editor deaktivieren',
  'state' => 'editable',
  'description' => 'Schaltet den Newsletter Editor und Zusatzfunktionen ab.'
);

$this->featureData['PublicModules']['sub']['Listings'] = array(
  'name' => 'Auflistungen',
  'state' => 'editable',
  'class' => '\\LBWP\\Module\\Listings\\Core',
  'description' => 'Erstelle selbst Auflistungen in verschiedenen Ausprägungen in ein- oder mehrspaltigen Layouts.',
  'availabilityCallback' => array($this, 'isSuperuser')
);

$this->featureData['PublicModules']['sub']['Events'] = array(
  'name' => 'Events & Ticketing',
  'state' => 'editable',
  'class' => '\\LBWP\\Module\\Events\\Core',
  'description' => 'Event und Ticketing Modul mit Optionaler Anbindung an WooCommerce',
  'availabilityCallback' => array($this, 'isSuperuser')
);

$this->featureData['PublicModules']['sub']['Forms'] = array(
  'name' => 'Formulare',
  'state' => 'editable',
  'class' => '\\LBWP\\Module\\Forms\\Core',
  'description' => '
    Erstellen Sie Formulare um einfache Mails zu generieren. Je nach Design müssen noch kleine
    Anpassungen vorgenommen werden, damit die Formulare optimal dargestellt werden.
  '
);

$this->featureData['PublicModules']['sub']['Snippets'] = array(
  'name' => 'Snippets',
  'state' => 'editable',
  'class' => '\\LBWP\\Module\\General\\Snippets',
  'description' => '
    Definieren Sie HTML Snippets, welche als Vorlage oder als zentrale Ablage für Inhalte
    kopiert und referenziert werden können.
  '
);

$this->featureData['PublicModules']['sub']['Redirects'] = array(
  'name' => 'Weiterleitungen',
  'state' => 'editable',
  'class' => '\\LBWP\\Module\\General\\Redirects',
  'description' => 'Simples Modul zum erfassen von globalen Weiterleitungen.'
);

$this->featureData['PublicModules']['sub']['MenuManager'] = array(
  'name' => 'Menu Manager',
  'state' => 'editable',
  'class' => '\\LBWP\\Module\\General\\MenuManager',
  'description' => 'Menupunkte direkt in der Seitenverwaltung erstellen, verschieben und verändern.'
);

$this->featureData['PublicModules']['sub']['S3Upload'] = array(
  'name' => 'Amazon S3 Upload',
  'state' => 'editable',
  'class' => '\\LBWP\\Module\\Backend\\S3Upload',
  'description' => '
    Dateien in der Mediathek werden auf Amazon S3 abgelegt.
    Ermöglicht Loadbalancing durch externes Bereitstellen von Dateien.
  '
);

$this->featureData['PublicModules']['sub']['CleanUp'] = array(
  'name' => 'Clean Up',
  'class' => '\\LBWP\\Module\\General\\CleanUp',
  'state' => 'disabled',
  'description' => '
    Nimmt Administratoren bestimmte Rechte um Features zu deaktivieren,
    welche auf einer Loadbalancing Infrastruktur nicht funktionieren.
    Räumt zudem diverse störende Meldungen von Wordpress auf.
  '
);

$this->featureData['PublicModules']['sub']['RememberCacheKeys'] = array(
  'class' => '\\LBWP\\Module\\General\\RememberCacheKeys',
  'state' => 'invisible'
);

$this->featureData['PublicModules']['sub']['CmsFeatures'] = array(
  'class' => '\\LBWP\\Module\\General\\CmsFeatures',
  'state' => 'invisible'
);

$this->featureData['PublicModules']['sub']['Favicon'] = array(
  'class' => '\\LBWP\\Module\\General\\Favicon',
  'state' => 'invisible'
);

$this->featureData['PublicModules']['sub']['FragmentCache'] = array(
  'class' => '\\LBWP\\Module\\General\\FragmentCache\\Core',
  'state' => 'invisible'
);

$this->featureData['PublicModules']['sub']['AuthorHelper'] = array(
  'class' => '\\LBWP\\Module\\General\\AuthorHelper',
  'state' => 'invisible'
);

$this->featureData['FrontendModules']['sub']['Shortcodes'] = array(
  'class' => '\\LBWP\\Module\\Frontend\\Shortcodes',
  'state' => 'invisible'
);

$this->featureData['BackendModules']['sub']['KeepTaxonomyHierarchy'] = array(
  'class' => '\\LBWP\\Module\\Backend\\KeepTaxonomyHierarchy',
  'state' => 'invisible'
);

$this->featureData['BackendModules']['sub']['MigrationTools'] = array(
  'class' => '\\LBWP\\Module\\Backend\\MigrationTools',
  'state' => 'invisible'
);
