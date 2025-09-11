<?php
/**
 * This fills $this->features of LBWP
 */
$this->features = array(
  'CorePackages' => array(
    'LbwpSimple' => 0,
    'LbwpProfessional' => 0,
    'DnsHosting' => 0,
    'DnsHostingMail' => 0,
    'ForcedSSL' => 0
  ),
  'BackendModules' => array(
    'MemcachedAdmin' => 1,
    'KeepTaxonomyHierarchy' => 1,
    'MigrationTools' => 1,
    'PostDuplicate' => 1,
    'DevTools' => 0
  ),
  'FrontendModules' => array(
    'HTMLCache' => 0,
    'CronHandler' => 1,
    'OutputFilter' => 1,
    'Shortcodes' => 1,
    'SimpleFancybox' => 0
  ),
  'PublicModules' => array(
    'NewsletterBase' => 0,
    'NewsletterDeactivated' => 0,
    'Forms' => 0,
    'Listings' => 0,
    'Events' => 0,
    'PiwikIntegration' => 0,
    'Snippets' => 0,
    'Tables' => 0,
    'Redirects' => 0,
    'CleanUp' => 1,
    'S3Upload' => 1,
    'RememberCacheKeys' => 0,
    'CdnFileManager' => 0, //@deprecated
    'CmsFeatures' => 1,
    'Favicon' => 1,
    'AuthorHelper' => 1,
    'MenuManager' => 0,
    'FragmentCache' => 0
  ),
  'Crons' => array(
    'CleanRevisions' => 1,
    'CleanCommentSpam' => 1
  ),
  'Plugins' => array(
    'GoogleXmlSitemap' => 0,
    'AntispamBee' => 0,
    'SimpleShareButtons' => 0, //@deprecated
    '2ClickSmButtons' => 0, //@deprecated
    'Polylang' => 0,
    'CustomSidebars' => 0,
    'EnhancedMediaLibrary' => 0,
    'wpSeoYoast' => 0,
    'analyticsYoast' => 0,
    'wpSeo' => 0,
    'TinyMceAdvanced' => 0,
    'WooCommerce' => 0,
    'WooCommercePostFinance' => 0
  ),
  'OutputFilterFeatures' => array(
    'SingleOgTags' => 0,
    'CompressCssJs' => 0,
    'CloudFrontFilter' => 0,
    'HeaderFooterFilter' => 1
  )
);