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

$this->configData['HeaderFooterFilter']['items']['GoogleSiteVerification'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Search Console Tools ID',
  'description' => 'Damit können Sie die Webseite für die Google Search Console verifizieren.'
);

$this->configData['HeaderFooterFilter']['items']['GoogleTagmanagerId'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Google Tag Manager / GA4 ID',
  'description' => 'Damit können Sie Google Tag Manager oder Google Analytics V4 einbinden. Es löst die alte Analytics ID (v3) ab.'
);

$this->configData['HeaderFooterFilter']['items']['GoogleAnalyticsId'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Google Analytics ID (v3)',
  'description' => 'Damit können Sie Google Universal Analytics einbinden. Einfach die ID hier rein kopieren (UA-XXXXXX-Y).'
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
  'label' => 'Wartungsmodus',
  'checkbox' => true,
  'description' => 'Wenn aktiv, haben weder Besucher noch Suchmaschinen Zugriff auf die Webseite.'
);

$this->configData['Various']['items']['MaintenancePassword'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Passwort für Wartungsmodus',
  'description' => 'Optional: Wenn eingegeben, kann man den Wartungsmodus mit diesem Passwort einfacher umgehen.'
);

$this->configData['Various']['items']['MaintenanceIPWhiltelist'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'IP Whitelist für Wartungsmodus',
  'description' => 'Optional: Sie können kommagetrennt IP-Adressen eingeben, welche den Wartungsmodus automatisch umgehen.'
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
    'rangeFrom' => 800
  ),
  'title' => 'Maximale Bildgrösse',
  'description' => 'Originalbilder werden beim Upload auf diese Maximalgrösse zugeschnitten (Längere Kante).'
);

$this->configData['Various']['items']['ImageCompressionRatio'] = array(
  'type' => 'number',
  'typeConfig' => array(
    'afterHtml' => '%',
    'defaultIfEmpty' => 86,
    'rangeFrom' => 70,
    'rangeTo' => 100
  ),
  'title' => 'Bildkompressionsrate',
  'description' => 'Ohne weitere Einstellung ist die Kompression auf ein gutes Verhältnis aus Qualität und Bildgrösse abgestimmt.'
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

$this->configData['Various']['items']['AdditionalCommentNotifications'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Kommentarbenachrichtigungen',
  'description' => '
    Standardmässig werden Kommentarbenachrichtigungen nur an den Autoren eines Beitrags gesendet.
    Sie können hier eine oder mehrere (Kommagetrennte) E-Mail Adressen als weitere Empfänger eintragen.
  '
);

$this->configData['Various']['items']['BrowserColor'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Browser einfärben',
  'description' => 'Mobilebrowser erlauben es, den Browser im Stil der Website einzufärben. Die Farbe muss als Hexcode, z.b. #ff0000 angegeben werden.'
);

$this->configData['Various']['items']['RedirectAttachmentDetail'] = array(
  'type' => 'checkbox',
  'typeConfig' => array(),
  'title' => 'Detailseiten von Medien-Dateien auf deren Beitrag weiterleiten',
  'label' => 'Medien-Dateien',
  'checkbox' => true,
  'description' => '
    Meistens werden diese Seiten nur von Suchmaschinen gefunden oder versehentlich verlinkt. Es empfiehlt sich daher,
    auf den Beitrag weiterzuleiten in welchem die Medien-Datei (Bild, Video, PDF) verwendet wird.
  '
);

$this->configData['Various']['items']['PandocReferenceDocument'] = array(
  'type' => 'file',
  'typeConfig' => array('valueType' => 'id', 'width' => 100),
  'title' => 'Refenrenz Dokument (Pandoc)',
  'description' => 'Damit können Format-Vorlagen definiert werden für Docx-Dateien, die vom CMS erstellt/konvertiert werden.'
);

$this->configData['Various']['items']['DisableWebpConversion'] = array(
  'type' => 'checkbox',
  'typeConfig' => array(),
  'title' => 'Optimierung ins WebP Bildformat deaktivieren',
  'label' => 'Bildoptimierung',
  'checkbox' => true,
  'description' => 'WebP wird von allen modernen Browsern unterstützt. Manche alte Clients können damit nicht umgehen.'
);

/**
 * Various / General settings
 */
$this->configData['Privacy'] = array(
  'title' => 'Datenschutz Einstellungen',
  'description' => '
    Einstellungen um Ihre Datenschutz-Massnahmen für die Besucher transparenter zu machen.
  ',
  'visible' => true,
  'items' => array()
);

$this->configData['Privacy']['items']['DataPrivacyStatementPageId'] = array(
  'type' => 'pagedropdown',
  'typeConfig' => array('optional' => true),
  'title' => 'Datenschutz-Seite',
  'description' => 'Bitte wählen Sie die Seite ihrer Datenschutzerklärung aus. Diese wird wo nötig automatisch verlinkt.'
);

$this->configData['Privacy']['items']['InformationalBannerActive'] = array(
  'type' => 'checkbox',
  'typeConfig' => array(),
  'title' => 'Informations-Banner zur Nutzung von Cookies und zur Anzeige der Datenschutzerklärung aktivieren',
  'label' => 'Informations-Banner',
  'checkbox' => true,
  'description' => 'Es ist zu beachten, dass dieser Banner zur Einhaltung der EU-DSGVO nicht zwingend nötig ist.'
);

$this->configData['Privacy']['items']['InformationalBannerOptout'] = array(
  'type' => 'checkbox',
  'typeConfig' => array(),
  'title' => 'Informations-Banner erlaubt deaktivieren des Tracking, erst bei Einwilligung wird getrackt',
  'label' => 'Standardmässig kein Tracking',
  'checkbox' => true,
  'description' => ''
);

$this->configData['Privacy']['items']['InformationalBannerContent'] = array(
  'type' => 'editor',
  'typeConfig' => array(),
  'title' => 'Banner Inhalt',
  'description' => 'Der Inhalt des Banners sollte so kurz wie möglich sein und auf die Datenschutzerklärung verlinken.'
);

$this->configData['Privacy']['items']['InformationalBannerButton'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Banner Button',
  'description' => 'Text für den Button um den Informations-Banner zu bestätigen / schliessen. Der Button zeigt den Text "OK", sofern keine Beschriftung eingegeben wird.'
);
$this->configData['Privacy']['items']['InformationalBannerButtonOptout'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Opt-Out Button',
  'description' => 'Button um das Tracking zu deaktivieren. wird nur angezeigt, wenn diese Option weiter oben aktiviert wird'
);

$this->configData['Privacy']['items']['InformationalBannerVersion'] = array(
  'type' => 'number',
  'typeConfig' => array(
    'rangeFrom' => 1,
    'rangeTo' => 9999
  ),
  'title' => 'Banner Version',
  'description' => '
    Hat ein Besucher den Banner geschlossen wird er diesen nicht erneut sehen. Sofern Sie aber den Inhalt des Banners ändern und die Änderung
    von grosser Wichtigkeit ist, können Sie hier die Versionsnummer hochzählen. Damit wird die neue Version des Inhalts wieder allen Besuchern erneut gezeigt.    
  '
);

$this->configData['Privacy']['items']['PrivacyOptimizedShareButtons'] = array(
  'type' => 'checkbox',
  'typeConfig' => array(),
  'title' => 'Alternative Share-Buttons ohne Tracking-Code verwenden',
  'label' => 'Share-Buttons',
  'checkbox' => true,
  'description' => '
    Die Share-Buttons der verschiedenen Social-Sharing-Dienste zeichnen alleine durch die Anzeige des Buttons Nutzerdaten auf.
    Wenn aktiv, werden alternative Buttons verwendet, welche erst beim Klick auf den Button einen externen Teilen-Dialog des Dienstes aufrufen.
    Diese Einstellung kommt nur zum Tragen, wenn sie Share-Buttons verwenden.
  '
);

$this->configData['Privacy']['items']['CommentAnonymizeDays'] = array(
  'type' => 'number',
  'typeConfig' => array(
    'defaultIfEmpty' => 0,
    'rangeFrom' => 0,
    'rangeTo' => 9999
  ),
  'title' => 'Kommentare anonymisieren',
  'description' => '
    Wenn hier ein Zahl grösser als 0 eingegeben wird, werden Kommentare (Name und E-Mail) nach dieser Anzahl Tage anonymisiert.  
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
  'description' => 'Überschrift für die 404 Seite.'
);

$this->configData['NotFoundSettings']['items']['UsePermanentRedirect'] = array(
  'type' => 'checkbox',
  'typeConfig' => array(),
  'checkbox' => true,
  'label' => 'Weiterleitung',
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
 * Header and Footer filter section and settings
 */
$this->configData['EmailTemplates'] = array(
  'title' => 'Einstellungen für Formular E-Mail-Templates',
  'description' => 'Damit können Sie die Darstellung der E-Mails die von Formularen generiert werden.',
  'visible' => true,
  'items' => array()
);

$this->configData['EmailTemplates']['items']['LogoImageUrl'] = array(
  'type' => 'file',
  'typeConfig' => array('valueType' => 'url', 'width' => 100),
  'title' => 'Logo',
  'description' => 'Laden Sie ein Logo hoch um Ihre E-Mail Templates in Formularen zu individualisieren. Es sollte maximal 300 Pixel breit sein'
);

$this->configData['EmailTemplates']['items']['LogoSize'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Logo Grösse',
  'description' => 'Grösse für das Logo im E-Mail (breite in Pixel)'
);

$this->configData['EmailTemplates']['items']['PrimaryColor'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Primärfarbe',
  'description' => 'Farbe für die Titelzeile mit dem E-Mail Betreff (Farbe inkl. # als Hexcode angeben).'
);

$this->configData['EmailTemplates']['items']['BackgroundColor'] = array(
  'type' => 'text',
  'typeConfig' => array(),
  'title' => 'Hintergrundfarbe',
  'description' => 'Hintergrundfarbe für die E-Mails (Farbe inkl. # als Hexcode angeben).'
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
  'label' => 'Aufräumen',
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