<?php
/**
 * This file is loaded only if needed in Module_LbwpConfig, hence
 * $this referenced the instance of the related module.
 */

/**
 * HTML Cache section and settings
 */
$this->configData['HTMLCache'] = array(
  'title' => 'Einstellungen zum HTML Cache',
  'description' => '
    Hier können Einstellungen zum HTML Cache treffen. Der HTML Cache bewirkt, dass Ihre Seite viel schneller
    lädt. Der Wert sollte hoch sein (Wir empfehlen einen Tag), da der Speicher jeweils gelöscht wird, wenn
    Sie im Administrations-Bereich etwas an der Webseite ändern.
  ',
  'requires' => array('FrontendModules','HTMLCache',1),
  'items' => array()
);

$this->configData['HTMLCache']['items']['CacheTime'] = array(
  'type' => 'number',
  'typeConfig' => array(
    'afterHtml' => 'Sekunden',
    'rangeFrom' => 300,
    'rangeTo' => 172800
  ),
  'title' => 'HTML-Cache in Sekunden',
  'description' => 'Definiert, wie lange die Webseite gecached wird. Geänderte Menus und Widgets werden erst nach dieser Zeit für alle sichtbar.'
);

$this->configData['HTMLCache']['items']['CacheTimeSingle'] = array(
  'type' => 'number',
  'typeConfig' => array(
    'afterHtml' => 'Sekunden',
    'rangeFrom' => 300,
    'rangeTo' => 172800
  ),
  'title' => 'Cache Artikel/Seiten',
  'description' => 'Sie können damit die Cache-Zeit für Artikel/Seiten höher oder tiefern Einstellen als die der restliche Webseite.'
);

/**
 * Header and Footer filter section and settings
 */
$this->configData['HeaderFooterFilter'] = array(
  'title' => 'Metainformationen für Header und Footer',
  'description' => '
    Diese Einstellungen beinflussen Metainformationen und Quellcode im Header und Footer Bereich der Webseite.
  ',
  'requires' => array('OutputFilterFeatures','HeaderFooterFilter',1),
  'items' => array()
);

$this->configData['HeaderFooterFilter']['items']['FbPageId'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Facebook Page-ID',
  'description' => '
    Verknüpft diese Webseite mit der Angegebenen Facebook Seite. Diese Information ermöglicht unter
    anderem Erweiterte Statistiken für Ihre Facebook Seite.
  '
);

$this->configData['HeaderFooterFilter']['items']['GPlusMainPublisher'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Google+ Page-URL',
  'description' => '
    Verknüpft diese Webseite mit dem angegebenen Google+ Firmen- oder Personenprofil. Sie können einfach die URL zu
    Ihrer Google+ Seite verwenden. Diese Angabe kann der Google AuthorRank verbessern.
  '
);

$this->configData['HeaderFooterFilter']['items']['GoogleSiteVerification'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Webmaster Tools ID',
  'description' => 'Damit können Sie die Webseite für die Google Webmaster Tools verifizieren.'
);

$this->configData['HeaderFooterFilter']['items']['GoogleAnalyticsId'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Google Analytics ID',
  'description' => 'Damit können Sie Google Analytics einbinden. Einfach die ID hier rein kopieren (UA-XXXXXX-Y).'
);

$this->configData['HeaderFooterFilter']['items']['FaviconPngUrl'] = array(
  'type' => 'file',
  'typeConfig' => array('valueType' => 'url', 'width' => 32),
  'title' => 'Favicon (PNG)',
  'description' => '
    Laden Sie hier ein Favicon hoch (PNG, mindestens 57x57px, optimal sind 194x194px, quadratisch, das Bild wird nicht zugeschnitten)
  '
);

$this->configData['HeaderFooterFilter']['items']['DefaultThumbnailId'] = array(
  'type' => 'file',
  'typeConfig' => array('valueType' => 'id', 'width' => 200),
  'title' => 'Standard Bild',
  'description' => '
    Wählen Sie hier ein Bild aus, welches in sozialen Kanälen angezeigt werden soll, sofern kein Beitragsbild zum Inhalt ausgewählt wurde.
  '
);

$this->configData['HeaderFooterFilter']['items']['HeaderHtml'] = array(
  'type' => 'textarea',
  'typeConfig' => array(),
  'title' => 'Header HTML Code',
  'description' => '
    In dieses Feld können Sie HTML Code (Kann auch Javascript und CSS enthalten) für den Header Bereich
    einfügen, etwa für Tracking-Codes, verändern von Styles etc.
  '
);

$this->configData['HeaderFooterFilter']['items']['FooterHtml'] = array(
  'type' => 'textarea',
  'typeConfig' => array(),
  'title' => 'Footer HTML Code',
  'description' => '
    In dieses Feld können Sie HTML Code (Kann auch Javascript und CSS enthalten) für den Footer Bereich
    einfügen was primär für Tracking-Codes verwendet wird. Wird vor dem &lt;/body&gt; eingefügt.
  '
);

/**
 * Various / General settings
 */
$this->configData['Various'] = array(
  'title' => 'Verschiedene Einstellungen',
  'description' => 'Eine Reihe verschiedener Einstellungen für ihre Webseite.',
  'visible' => true,
  'items' => array()
);

$this->configData['Various']['items']['MaintenanceMode'] = array(
  'type' => 'checkbox',
  'typeConfig' => array(),
  'title' => 'Wartungsmodus aktiv',
  'checkbox' => true,
  'description' => 'Wenn aktiv, haben weder Besucher noch Suchmaschinen Zugriff auf die Webseite.'
);

$this->configData['Various']['items']['MaintenancePassword'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Passwort für Wartungsmodus',
  'description' => 'Optional: Wenn eingegeben, kann man den Wartungsmodus mit diesem Passwort einfacher umgehen.'
);

$this->configData['Various']['items']['GoogleEngineId'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Google Site Search ID',
  'description' => 'Nur nutzbar, wenn ihr Theme dies unterstützt. Bitte die Engine ID aus dem GSS Backend eingeben.'
);

$this->configData['Various']['items']['MaxImageSize'] = array(
  'type' => 'number',
  'typeConfig' => array(
    'afterHtml' => 'Pixel',
    'rangeFrom' => 800,
  ),
  'title' => 'Maximale Bildgrösse',
  'description' => 'Originalbilder werden beim Upload auf diese Maximalgrösse zugeschnitten (Längere Kante).'
);

$this->configData['Various']['items']['RobotsTxt'] = array(
  'type' => 'textarea',
  'typeConfig' => array(),
  'title' => 'Zusätzlicher Inhalt robots.txt',
  'description' => '
    Dieses Feld kann verwendet werden um z.b. einzelne Seiten oder Verzeichnisse, aber auch Bilder von der Indexierung auszuschliessen.
    Achtung: Die Syntax für die robots.txt muss korrekt sein, da sonst die Seite möglicherweise nicht mehr indexiert wird.
  '
);

/**
 * Not found page settings
 */
$this->configData['NotFoundSettings'] = array(
  'title' => 'Einstellungen für die 404 Seite',
  'description' => 'Die "404" Seite wird angezeigt, wenn ein Besucher auf einen Link geklickt hat, den es nicht (mehr) gibt.',
  'requireCallback' => array('\LBWP\Theme\Feature\NotFoundSettings','isActive'),
  'items' => array()
);

$this->configData['NotFoundSettings']['items']['Title'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Überschrift',
  'description' => 'Überschrift für die 404 Seite'
);

$this->configData['NotFoundSettings']['items']['UsePermanentRedirect'] = array(
  'type' => 'checkbox',
  'typeConfig' => array(),
  'checkbox' => true,
  'title' => 'Auf die Startseite weiterleiten',
  'description' => 'Alle Fehler leiten auf die Startseite weiter. Suchmaschinen werden informiert, dass es die alten Links nicht mehr gibt.'
);

$this->configData['NotFoundSettings']['items']['Content'] = array(
  'type' => 'editor',
  'typeConfig' => array(),
  'title' => 'Inhalt',
  'description' => '
    Inhalt für die 404 Seite. Der Besucher sollte informiert werden, dass der gesuchte Inhalt nicht (mehr) existiert.
    Es macht Sinn, entsprechende Hinweise auf eine Suchfunktion und eventuell einen Link zur Startseite auf einzufügen.
  '
);

/**
 * Reference post type section and settings
 */
$this->configData['Reference_Posttype'] = array(
  'title' => 'Einstellungen zum Inhaltstyp "Referenzen"',
  'description' => '
    Konfiguration der Referenzenseite.
  ',
  'requires' => array('PublicModules','Reference_Posttype',1),
  'items' => array()
);

$this->configData['Reference_Posttype']['items']['ImageWidth'] = array(
  'type' => 'number',
  'typeConfig' => array(
    'afterHtml' => 'Pixel',
    'rangeFrom' => 150,
    'rangeTo' => 2000
  ),
  'title' => 'Breite des Referenzbildes',
  'description' => 'Definiert die exakte Breite des Referenzbildes (Screenshot).'
);

$this->configData['Reference_Posttype']['items']['ImageHeight'] = array(
  'type' => 'number',
  'typeConfig' => array(
    'afterHtml' => 'Pixel',
    'rangeFrom' => 150,
    'rangeTo' => 1200
  ),
  'title' => 'Höhe des Referenzbildes',
  'description' => 'Definiert die exakte Höhe des Referenzbildes (Screenshot).'
);

/**
 * Reference post type section and settings
 */
$this->configData['Events'] = array(
  'title' => 'Event-Einstellungen',
  'description' => '
    Diverse Einstellungen für Events.
  ',
  'requires' => array('PublicModules','Events',1),
  'items' => array()
);

$this->configData['Events']['items']['CleanupEvents'] = array(
  'type' => 'checkbox',
  'typeConfig' => array(),
  'title' => 'Alte Events aus der Datenbank entfernen',
  'checkbox' => true,
  'description' => 'Events werden nach der unten eingestellten Anzahl Monate gelöscht.'
);

$this->configData['Events']['items']['CleanupMonths'] = array(
  'type' => 'number',
  'typeConfig' => array(
    'afterHtml' => 'Monaten',
    'rangeFrom' => 3,
    'rangeTo' => 48
  ),
  'title' => 'Events entfernen nach',
  'description' => 'Events werden nur gelöscht, wenn die Checkbox oben aktiviert ist.'
);