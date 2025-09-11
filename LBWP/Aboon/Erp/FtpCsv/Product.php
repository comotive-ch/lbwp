<?php

namespace LBWP\Aboon\Erp\FtpCsv;

use LBWP\Aboon\Erp\Product as ProductBase;
use LBWP\Aboon\Erp\Entity\Product as ImportProduct;
use LBWP\Helper\Cronjob;
use LBWP\Helper\Import\Csv;
use LBWP\Helper\Import\Ftp;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
use LBWP\Util\Strings;

/**
 * ERP implementation of a statically synced CSV erp, with prefined formats
 * @package LBWP\Aboon\Erp\FtpCsv
 */
abstract class Product extends ProductBase
{
  /**
   * Variables for FTP connection and file access, can and should be overridden in customer class
   */
  protected string $ftpServer = '__override_plz';
  protected string $ftpUser = '__override_plz';
  protected string $ftpPass = '__override_plz_maybe_encrypt';
  protected int $ftpPort = 21;
  protected string $sourceFile = 'erp-import-products.csv';
  protected string $configFile = 'erp-product-config.csv';
  protected string $internalSeparator = ',';
  protected string $mainFileSeparator = ';';
  protected bool $cacheFile = true;
  protected bool $manualImportUpdateImage = true;
  protected bool $updateCatPropList = true;
  protected int $cacheFileTime = 40000;
  protected bool $categoryTreeCutLastWord = false;
  protected int $idIndex = 0;
  /**
   * @var bool|array therefore not typed
   */
  protected $manualImportFile = false;

  /**
   * Make sure to initialize everything from parent and some specific FTP functions
   */
  public function init()
  {
    parent::init();
    // Add product import features
    add_action('admin_menu', array($this, 'addImportSubmenu'), 100);
    add_action('cron_job_ftpsv_manual_import', array($this, 'runManualImport'));
  }

  /**
   * Add import submenu page
   */
  public function addImportSubmenu()
  {
    add_submenu_page(
      'edit.php?post_type=product',
      'Import',
      'Import',
      'administrator',
      'erp-import-product',
      array($this, 'displayImportPage')
    );
  }

  /**
   * Loads the while file in to cache and retursn it
   * @param bool $force true when we want to reload
   * @return array
   */
  protected function loadRemoteFile(bool $force = false): array
  {
    // Eventually try getting if from a local variable if set
    if ($this->manualImportFile !== false) {
      return $this->manualImportFile;
    }

    // Try getting raw file from cache eventually
    $cacheKey = 'productImportFile_' . md5($this->sourceFile . $this->configFile);
    $products = wp_cache_get($cacheKey, 'ftpCsv');

    // If not available, load from FTP server
    if ($products === false || $force === true) {
      $ftp = new Ftp($this->ftpServer, $this->ftpUser, $this->ftpPass, $this->ftpPort);
      $file = array();
      if (strlen($this->sourceFile) > 0) {
        $path = $ftp->getFile($this->sourceFile);
        // Use native csv methods to omit problems with breaks
        $handle = fopen($path, 'r');
        if ($handle !== false) {
          $row = fgetcsv($handle, 0, $this->mainFileSeparator, '"');
          while (!empty($row)) {
            $file[] = $row;
            $row = fgetcsv($handle, 0, $this->mainFileSeparator);
            if (is_array($row)) {
              $row = array_filter($row);
            }
          }
        }
      }
      $path = $ftp->getFile($this->configFile);
      $config = Csv::getArray($path, ';', '"', false, true);
      $ftp->close();

      // Build a better readable file from this
      $products = array();
      // Get the columns line, but handle gracefully if there is no full import file
      if (count($file) > 0) {
        $columnNames = $file[0];
        // Now we know until where to read the config
        foreach ($config as $line) {
          $columnIndex = array_search($line[0], $columnNames);
          if ($columnIndex !== false) {
            $columnKey = Strings::forceSlugString($columnNames[$columnIndex]);
          } else {
            // Internal config after the field configs must be handeled differently
            $columnKey = $line[0];
            $columnIndex = $columnKey;
          }
          $products['config'][$columnIndex] = array(
            'key' => $columnKey,
            'name' => $line[0],
            'type' => $line[1],
            'config' => $line[2],
          );
        }

        // Remove very first line with column names
        unset($file[0]);
        // Now add the products to the array
        $products['list'] = array();
        foreach ($file as $line) {
          $products['list'][] = $line;
        }

        // Let the data be changed before we cache it for special cases
        $products = apply_filters('lbwp_aboon_ftpcsv_after_fileread', $products);
      } else {
        foreach ($config as $line) {
          // Build simplistic config from only the config to show in backend
          $products['list'] = array();
          $products['config'][] = array(
            'name' => $line[0],
            'type' => $line[1],
            'config' => $line[2],
          );
        }
      }

      // Maybe cache if allowed to do so
      if ($this->cacheFile) {
        wp_cache_set($cacheKey, $products, 'ftpCsv', $this->cacheFileTime);
      }
    }

    return $products;
  }

  /**
   * Get the next n-IDs from the file
   * @param int $page
   * @return array
   */
  protected function getPagedProductIds($page): array
  {
    // First open file and get all distinct ids
    $remoteIds = array();
    $file = $this->loadRemoteFile();
    // Remove heading line before reading
    foreach ($file['list'] as $line) {
      if (!in_array($line[$this->idIndex], $remoteIds)) {
        $remoteIds[] = $line[$this->idIndex];
      }
    }

    // Now return the page slice of that
    $offset = ($page - 1) * $this->importsPerRun;
    return array_slice($remoteIds, $offset, $this->importsPerRun);
  }

  /**
   * @param mixed $remoteId
   * @return ImportProduct fully filled product object
   */
  public function convertProduct($remoteId, $localId = 0): ImportProduct
  {
    $raw = $this->reduceFileToId($remoteId, $this->loadRemoteFile());
    // Maybe translate values in our product
    $this->translateFields($raw);
    // Make sure to not cache term objects as this causes overflows
    wp_cache_add_never_persistent_groups(array('terms', 'term_meta'));
    // Update the property and the category tree
    if (strlen($this->sourceFile) > 0 && $this->updateCatPropList) {
      $this->updateCategories($this->getFullCategoryList());
      $this->updateProperties($this->getFullPropertyList());
    }

    $product = new ImportProduct($remoteId, $localId);
    // First, add meta for the eventual id field, if given in config
    foreach ($raw['config'] as $key => $config) {
      if ($config['type'] == 'id' && strlen($config['config']) > 0) {
        $product->setMeta($config['config'], $remoteId);
      }
      // Set actual post date if parseable
      if ($config['type'] == 'core' && $config['config'] == 'post_date') {
        $ts = strtotime($raw['data'][$key]);
        if ($ts !== false) {
          $sqlDate = Date::getTime(Date::SQL_DATETIME, $ts);
          $product->setCoreField('post_date', $sqlDate);
          $product->setCoreField('post_date_gmt', $sqlDate);
        }
      }
    }

    // Set the product slug combined from various fields
    if (isset($raw['config']['post_name'])) {
      $fields = array_map('trim', explode(';', $raw['config']['post_name']['type']));
      $values = array();
      foreach ($fields as $field) {
        $field = Strings::forceSlugString($field);
        $values[] = Strings::forceSlugString($this->getFieldValueByName($field, $raw));
      }

      // Set combined field or fallback if nothing was generated
      if (count($values) > 0) {
        $slug = str_replace('--', '-', implode('-', $values));
        $product->setCoreField('post_name', $slug);
      } else {
        $product->setCoreField('post_name', 'product-id-' . $remoteId);
      }
    }

    // Basically tell the product in which taxonomies it needs to save
    $product->setCategoryTaxonomy($this->catTaxonomy);
    $product->setPropertyTaxonomy($this->propTaxonomy);

    // Attach actual data to the product
    $this->attachProductData($raw['config'], $raw['data'], $product);
    // If the product has additional rows, work trough them
    if (isset($raw['data']['additional'])) {
      foreach ($raw['data']['additional'] as $additional) {
        $this->attachProductData($raw['config'], $additional, $product);
      }
    }

    // Allow special cases to be impored
    $this->importProductVariants($raw, $product);
    $this->importProductBulkPricing($raw, $product);

    return $product;
  }

  /**
   * @param array $raw
   * @param ImportProduct $product
   */
  protected function importProductVariants(array $raw, ImportProduct $product)
  {
    $variantConfig = array();
    $variants = array();

    foreach ($raw['config'] as $index => $config) {
      if ($config['type'] == 'simple-variant') {
        $variantConfig[$config['config']] = $index;
      }
    }

    // If there is no config, leave
    if (count($variantConfig) == 0) {
      return;
    }

    // Get main and additional rows to get importable candidates
    $candidates = array($raw['data']);
    if (isset($raw['data']['additional'])) {
      foreach ($raw['data']['additional'] as $additional) {
        $candidates[] = $additional;
      }
    }

    // Now actually build the variants array if given
    foreach ($candidates as $candidate) {
      if (!isset($candidate[$variantConfig['sort']]) && isset($candidate[$variantConfig['type']])) {
        $candidate[$variantConfig['sort']] = 0;
      }
      if (isset($candidate[$variantConfig['sort']])) {
        $variant = array();
        foreach ($variantConfig as $key => $index) {
          $variant[$key] = (string)$candidate[$index];
        }
        $variants[] = $variant;
      }
    }

    // If there are variants, sort and save them
    if (count($variants) > 0) {
      ArrayManipulation::sortByNumericField($variants, 'sort');
      // Set basic data
      $product->setMeta('variants', count($variants));
      $product->setMeta('_variants', 'field_7139e19793c5d');
      $product->setMeta('has-variants', array(0 => '1'));
      $product->setMeta('_has-variants', 'field_60427c1cd0bf2');
      // Set every variant
      foreach ($variants as $key => $variant) {
        foreach ($variant as $field => $value) {
          $product->setMeta('variants_' . $key . '_' . $field, $value);
        }
      }
    }
  }

  /**
   * @param array $raw
   * @param ImportProduct $product
   */
  protected function importProductBulkPricing(array $raw, ImportProduct $product)
  {
    $bulkConfig = array();
    $bulks = array();

    foreach ($raw['config'] as $index => $config) {
      if ($config['type'] == 'bulk-pricing') {
        $bulkConfig[$config['config']] = $index;
      }
    }

    // If there is no config, leave
    if (count($bulkConfig) == 0) {
      return;
    }

    // Get main and additional rows to get importable candidates
    $candidates = array($raw['data']);
    if (isset($raw['data']['additional'])) {
      foreach ($raw['data']['additional'] as $additional) {
        $candidates[] = $additional;
      }
    }

    // Now actually build the variants array if given
    foreach ($candidates as $candidate) {
      if (isset($candidate[$bulkConfig['sort']]) && $candidate[$bulkConfig['sort']] > 0) {
        // Skip if there is date field other than 0000-00-00
        if (isset($candidate[$bulkConfig['date']]) && $candidate[$bulkConfig['date']] != '0000-00-00') {
          continue;
        }
        $bulk = array();
        foreach ($bulkConfig as $key => $index) {
          $bulk[$key] = (string) $candidate[$index];
        }
        $bulks[] = $bulk;
      }
    }

    // If there are variants, sort and save them
    if (count($bulks) > 0) {
      ArrayManipulation::sortByNumericField($bulks, 'sort');
      // Set basic data
      $product->setMeta('bulk-price-list', count($bulks));
      $product->setMeta('has-bulkprices', array(0 => '1'));
      // Set every variant
      foreach ($bulks as $key => $bulk) {
        if (isset($bulk['date'])) unset($bulk['date']);
        foreach ($bulk as $field => $value) {
          $product->setMeta('bulk-price-list_' . $key . '_' . $field, $value);
        }
      }
    }
  }

  /**
   * @param array $rawconfig
   * @param array $data
   * @param ImportProduct $product
   */
  protected function attachProductData(array $rawconfig, array $data, ImportProduct $product)
  {
    // Now go trough every other numeric property, but skip id properties (already done)
    foreach ($rawconfig as $index => $config) {
      if ($config['type'] == 'id' || !is_int($index)) {
        continue;
      }

      switch ($config['type']) {
        case 'core':
          // Special case, skip post date as it is parsed seperrately
          if ($config['config'] == 'post_date') {
            continue;
          }
          if (strlen($config['config']) > 0 && isset($data[$index])) {
            $product->setCoreField($config['config'], $data[$index]);
          }
          break;
        case 'meta':
          if (strlen($config['config']) > 0 && isset($data[$index])) {
            $product->setMeta($config['config'], $data[$index]);
          }
          break;
        case 'property':
          if (Strings::contains($config['config'], 'self-by-default')) {
            if (strlen($data[$index]) == 0) {
              $raw['data'][$index] = $product->getRemoteId();
            }
          }

          $propertiesSet = 0;
          $name = $config['name'];
          $pos = stripos($name, ':');
          if ($pos !== false) {
            $name = substr($name, $pos + 1);
          }
          // Skip of property is not imported yet
          if (isset($this->properties[$name])) {
            foreach ($this->properties[$name]['terms'] as $term => $id) {
              $value = str_replace('#1', '', $data[$index]);
              $value = array_map('trim', explode($this->internalSeparator, $value));
              foreach ($value as $property) {
                if ($term == $property) {
                  $product->setProperty(intval($id));
                  ++$propertiesSet;
                }
              }
            }
          }

          // Only set the main prop, if sub props have been set
          if ($propertiesSet > 0) {
            $product->setProperty(intval($this->properties[$name]['id']));
          }

          break;
        case 'category-tree':
          $categories = array_map('trim', explode($this->internalSeparator, $data[$index]));
          foreach ($categories as $name) {
            // Remove strange characters
            $name = preg_replace("/\xE2\x80\x8B/", '', $name);
            $parts = explode($this->treeSeparator, $name);
            // Pop off parts until we selected all categories in the branch-name
            while (count($parts) > 0) {
              $actualName = implode($this->treeSeparator, $parts);
              $product->setCategory(intval($this->categories[$actualName]));
              array_pop($parts);
            }
          }
          break;
      }
    }
  }

  /**
   * @param array $raw
   */
  protected function translateFields(array &$raw)
  {
    foreach ($raw['config'] as $index => $config) {
      if (strlen($config['config']) > 0) {
        $this->translateFieldBySyntax($raw['data'], $config['config'], $index);
      }
      // Special case for category tree
      if ($this->categoryTreeCutLastWord && $config['type'] == 'category-tree') {
        $this->treeCutLastWord($raw['data'], $index);
      }
    }
  }

  /**
   * @param $data
   * @param $config
   * @param $index
   */
  protected function translateFieldBySyntax(&$data, $config, $index)
  {
    switch ($config) {
      case 'bool-translate':
        $value = strtolower($data[$index]);
        $value = str_replace(array('wahr', 'true'), 'Ja', $value);
        $value = str_replace(array('falsch', 'false'), 'Nein', $value);
        $data[$index] = $value;
        break;
    }
  }

  /**
   * @param $data
   * @param $index
   */
  protected function treeCutLastWord(&$data, $index)
  {
    // This replaces a bare nbsp with a normal space. BUT WHAT IS NORMAL, EH
    $data[$index] = html_entity_decode(str_replace('&nbsp;', ' ', htmlentities($data[$index])));
    $tree = array_map('trim', explode(',', $data[$index]));

    foreach ($tree as $outer => $value) {
      $value = array_map('trim', explode('>', $value));
      foreach ($value as $inner => $part) {
        $value[$inner] = substr($part, 0, strrpos($part, ' '));
      }
      $tree[$outer] = implode('>', $value);
    }
    $data[$index] = implode(',', $tree);
  }

  /**
   * @param string $field the field slug
   * @param array $product the product info
   * @return string the value from the field (may be an empty string)
   */
  protected function getFieldValueByName(string $field, array $product): string
  {
    foreach ($product['config'] as $index => $config) {
      if ($config['key'] == $field && isset($product['data'][$index])) {
        return $product['data'][$index];
      }
    }

    return '';
  }

  /**
   * @param int $remoteId
   * @return array
   */
  protected function reduceFileToId(int $remoteId, $file): array
  {
    $raw = array(
      'config' => $file['config'],
      'data' => array()
    );

    foreach ($file['list'] as $line) {
      if ($line[$this->idIndex] == $remoteId) {
        $raw['data'] = $line;
        break;
      }
    }

    return $raw;
  }

  /**
   * @param mixed $remoteId
   * @return mixed $remoteId is int in every case
   */
  protected function validateRemoteId($remoteId)
  {
    return intval($remoteId);
  }

  /**
   * @return string[] list of all importable categories with subcategory syntax "category > subcategory > subsubcat"
   */
  protected function getFullCategoryList(): array
  {
    $found = false;
    $result = array();
    $file = $this->loadRemoteFile();
    // First find the category tree field in config
    foreach ($file['config'] as $index => $config) {
      if ($config['type'] == 'category-tree') {
        $found = true;
        break;
      }
    }

    // Return empty array if nothing found in config
    if (!$found) {
      return array();
    }

    // Now we specified the index
    if ($index != null) {
      foreach ($file['list'] as $product) {
        if ($this->categoryTreeCutLastWord) {
          $this->treeCutLastWord($product, $index);
        }
        $categories = array_map('trim', explode($this->internalSeparator, $product[$index]));
        // Strangeness with zero width space, remove them!
        foreach ($categories as $key => $category) {
          $categories[$key] = preg_replace("/\xE2\x80\x8B/", '', $category);
        }
        foreach ($categories as $category) $result[$category] = true;
      }
    }

    return array_filter(array_keys($result));
  }

  /**
   * @return string[] list of all main property names and their possible tags as key => array of properties
   */
  protected function getFullPropertyList(): array
  {
    $result = array();
    $file = $this->loadRemoteFile();
    // First find the category tree field in config
    foreach ($file['config'] as $index => $config) {
      if ($config['type'] == 'property') {
        $properties = array();
        foreach ($file['list'] as $product) {
          if (isset($product[$index]) && strlen($product[$index]) > 0) {
            $this->translateFieldBySyntax($product, $config['config'], $index);
            $name = $product[$index];
            $name = str_replace('#1', '', $name);
            if (Strings::contains($name, $this->internalSeparator)) {
              $name = array_map('trim', explode($this->internalSeparator, $name));
              foreach ($name as $sub) {
                $properties[$sub] = true;
              }
            } else {
              $properties[$name] = true;
            }
          }
        }
        if (count($properties) > 0) {
          $name = $config['name'];
          $pos = stripos($name, ':');
          if ($pos !== false) {
            $name = substr($name, $pos + 1);
          }
          $result[$name] = array_filter(array_keys($properties));
        }
      }
    }

    return $result;
  }

  /**
   * Flushes the file cache before doing the rest
   */
  public function registerFullSync()
  {
    // Force reloading the file and already cache it
    $this->loadRemoteFile(true);
    // Flush other caches then start the full sync
    parent::registerFullSync();
  }

  /**
   * Display the import page
   */
  public function displayImportPage()
  {
    $message = '';
    $html = '';

    // Build config info
    $data = $this->loadRemoteFile();
    $configTable = '';
    foreach ($data['config'] as $config) {
      $configTable .= '
        <tr>
          <td>' . $config['name'] . '</td>
          <td>' . $config['type'] . '</td>
          <td>' . $config['config'] . '</td>
        </tr>
      ';
    }

    // Handling of new import uploads
    if (isset($_POST['cmdStartBackgroundImport']) && isset($_FILES['uploadedFile'])) {
      $message = $this->validateAndStartManualImport($_FILES['uploadedFile']);
    }

    $jobsTable = '<p>Aktuell sind keine Jobs geplant</p>';
    $jobs = Cronjob::list();
    if (isset($jobs['list']) && $jobs['count'] > 0) {
      $jobsHtml = '';
      foreach ($jobs['list'] as $job) {
        $jobsHtml .= '
          <tr>
            <td>' . $job['job_id'] . '</td>
            <td>' . date('d.m.Y H:i:s', $job['job_time']) . '</td>
            <td>' . $job['job_identifier'] . ' / ' . ((strlen($job['job_data']) > 0) ? $job['job_data'] : 'N/A') . '</td>
          </tr>
        ';
      }

      $jobsTable = '
        <table class="fixed widefat">
          <thead>
            <th>Job-ID</th>
            <th>Ausführung</th>
            <th>Job / Parameter</th>
          </thead>
          <tbody>
            ' . $jobsHtml . '
          </tbody>
        </table>
      ';
    }

    $additionalInfo = '';
    if (strlen($this->sourceFile) > 0) {
      $html .= '
        <h2>Import des Gesamtsortiments via FTP</h2>
        <p>Hiermit kann der Import der Stammdaten-Datei "' . $this->sourceFile . '" auf dem FTP Server angestossen werden. Dies kann mehrere Stunden dauern.</p>
        <a href="/wp-content/plugins/lbwp/views/cron/job.php?identifier=manual_aboon_erp_product_register_full_sync" target="_blank" class="button-primary full-import">Import starten</a>
      ';
    } else {
      $additionalInfo = '
        <strong>Achtung:</strong> Es ist keine Gesamtimport-Datei konfiguriert. Der Teilimport funktioniert grundsätzlich, kann aber keine neuen Eigenschaften (property) und Kategorien (category-tree) hinzufügen die es nicht bereits in der Datenbank gibt.
      ';
    }

    $html = apply_filters('lbwp_aboon_csv_import_page_html', $html);

    // Output frontend / messages
    echo '
      <div class="wrap">
        <h1>Import von Produktdaten</h1>
        ' . $message . '
        <p>Ihr ERP-System ist mit CSV Datenaustausch per FTP angebunden. Die Daten liegen auf dem Server ' . $this->ftpServer . '.</p>
        <p>Die Gesamtsortiment- oder Upload-Datei kann nur Spalten aus "<a class="show-config">' . $this->configFile . '</a>" verarbeiten, die ebenfalls auf dem FTP Server liegt.</p>
        <p>Es können nur Spalten teilimportiert werden, die auch in der Haupt-Importdatei "' . $this->sourceFile . '" enthalten sind.</p>
        ' . $additionalInfo . '
        <table class="fixed widefat show-config-toggle" style="display:none">
          <thead>
            <th>Spalte</th>
            <th>Typ</th>
            <th>Config</th>
          </thead>
          <tbody>
            ' . $configTable . '
          </tbody>
        </table>
        <h4>Eingestellte CSV Vorgaben</h4>
        <ol>
          <li>Trennzeichen Zellen = "' . $this->mainFileSeparator . '"</li>
          <li>Trennzeichen mehrere Werte pro Zelle: "' . $this->internalSeparator . '"</li>
          <li>Trennzeichen Kategorien-Baum "' . $this->treeSeparator . '".</li>
        </ol>
        <h2>Import via Upload-Datei</h2>
        <p>
          Für kleine Datenmengen kann der Upload verwendet werden. Der Import findet im Hintergrund statt. Aktualisieren Sie
          nach dem Upload die Seite, der Status in der Liste sollte sich dann aktualisieren. Bitte beachten, dass die ID-Spalte immer vorhanden sein muss.
        </p>
        <form action="" method="POST" enctype="multipart/form-data">
          <input type="file" name="uploadedFile" />
          <input type="submit" name="cmdStartBackgroundImport" value="Datenimport starten" class="button-primary" />
        </form>
        ' . $html . '
        <h2>Geplante Jobs im Hintergrund</h2>
        ' . $jobsTable . '
      </div>
      <script type="text/javascript">
        jQuery(function() {
          jQuery(".full-import").on("click", function() {
            return confirm("Sind Sie sicher, dass Sie alle Produktdaten neu importieren wollen?");
          });
          jQuery(".show-config").on("click", function() {
            jQuery(".show-config-toggle").toggle();
          });
        });
      </script>
    ';
  }

  /**
   * @param $file
   * @return string|void
   */
  protected function validateAndStartManualImport($file)
  {
    $error = '<div id="message" class="error inline"><p>{msg}</p></div>';

    SystemLog::add('FtpCsv_Upload', 'debug', 'upload file info', $file);
    // Check for basic validity of the file
    if (!isset($file['type']) || ($file['type'] != 'text/csv' && $file['type'] != 'application/vnd.ms-excel')) {
      return str_replace('{msg}', 'Die Datei ist offenbar keine gültige CSV Datei', $error);
    }

    // Read in the file as CSV file and check for necessities
    $raw = file_get_contents($file['tmp_name']);

    // Only allow UTF-8 or plain ASCII encoding (utf-8 is detected as ascii when it has only ascii chars)
    if (!in_array(mb_detect_encoding($raw), array('UTF-8', 'ASCII'))) {
      return str_replace('{msg}', 'Die Datei muss im UTF-8 Zeichensatz hochgeladen werden', $error);
    }

    $delimiter = Csv::guessDelimiter($raw, true);
    // Read in manually to omit problems with breaks
    $data = array();
    $handle = fopen($file['tmp_name'], 'r');
    $row = fgetcsv($handle, 0, $delimiter);
    while (!empty($row)) {
      $data[] = $row;
      $row = fgetcsv($handle, 0, $delimiter);
    }

    $fullImportFile = $this->loadRemoteFile();
    $config = $fullImportFile['config'];

    // Check if there is an id column
    $tempConfig = $columns = array();
    $idColumn = false;
    foreach ($data[0] as $index => $column) {
      $column = trim($column);
      foreach ($config as $row) {
        if ($row['name'] == $column && $row['type'] == 'id') {
          $idColumn = $index;
        }
        if ($row['name'] == $column) {
          $tempConfig[$index] = $row;
          $columns[$index] = $row['name'];
        }
      }
    }

    // Eventual error messages
    if ($idColumn === false) {
      return str_replace('{msg}', 'Die nötige ID Spalte scheint zu fehlen.', $error);
    }
    if (count($tempConfig) <= 1) {
      return str_replace('{msg}', 'Es wurden keine korrekten Spalten oder nur eine ID Spalte gefunden. Keine Importdaten gefunden.', $error);
    }

    // Make a map from imported field indexes to the indexes in the main file
    $overrideMap = array();
    foreach ($data[0] as $srcIndex => $srcField) {
      foreach ($fullImportFile['config'] as $destIndex => $destField) {
        if ($srcField == $destField['name'] && intval($destIndex) >= 0) {
          $overrideMap[$srcIndex] = $destIndex;
        }
      }
    }

    // Remove the first row as not used anymore
    unset($data[0]);

    // Now override our partial import data into the main import file (add ur update)
    $updatedRows = array();
    foreach ($data as $row) {
      // Get the index from main import file (or a new index, if new data)
      $index = $this->getMainImportFileIndex($fullImportFile['list'], $row, $idColumn);
      // Update the actual entry from the list with our updated fields
      foreach ($overrideMap as $src => $dest) {
        $fullImportFile['list'][$index][$dest] = $row[$src];
      }
      // And remember which actual lines to import
      $updatedRows[] = $index;
    }

    // Let the data be changed before we cache it for special cases
    $fullImportFile = apply_filters('lbwp_aboon_ftpcsv_after_fileread', $fullImportFile);

    // Generate ID for that import for cron to reference it
    $importId = strtoupper(Strings::getRandom(6));
    wp_cache_set('manualImportData_' . $importId, $fullImportFile, 'FtpCsv', 3600);
    wp_cache_set('manualImportData_rows_' . $importId, $updatedRows, 'FtpCsv', 3600);

    // Schedule the cron
    Cronjob::register(array(
      current_time('timestamp') => 'ftpsv_manual_import::' . $importId
    ));

    // And Log start of the manual import
    $time = date('d.m.Y, H:i:s', current_time('timestamp'));
    SystemLog::add('ftpcsv-manual', 'debug', 'manual import started at ' . $time, array(
      'id' => $importId,
      'name' => $file['name']
    ));

    return '<div id="message" class="updated notice notice-success"><p>Import ist in der Warteschlange.</p></div>';
  }

  /**
   * @param array $import
   * @param array $row
   * @param int $idCol
   * @return int
   */
  protected function getMainImportFileIndex(&$import, $row, $idCol)
  {
    foreach ($import as $index => $entry) {
      if ($entry[$idCol] == $row[$idCol]) {
        return $index;
      }
    }

    // If nothing was found, get the highest index, add 10 to be sure not causing conflicts
    return array_key_last($import) + 10;
  }

  /**
   * @param string $sku
   * @param int $idIndex
   * @param array $list
   * @return array
   */
  protected function getImportLineBySku(string $sku, int $idIndex, array &$list) : array
  {
    foreach ($list as $line) {
      if ($line[$idIndex] == $sku) {
        return $line;
      }
    }

    return array();
  }

  /**
   * Run manual imports from cached import data
   */
  public function runManualImport()
  {
    set_time_limit(1200);
    $importId = substr($_GET['data'],0,6);
    $import = wp_cache_get('manualImportData_' . $importId, 'FtpCsv');
    $indexes = wp_cache_get('manualImportData_rows_' . $importId, 'FtpCsv');
    // After that, remove the value immediately so no second cron is startet when it takes too long
    wp_cache_delete('manualImportData_' . $importId, 'FtpCsv');
    wp_cache_delete('manualImportData_rows_' . $importId, 'FtpCsv');

    // If cache info isn't there anymore, print message and leave
    if (!is_array($import) || !isset($import['list']) || !isset($import['list'])) {
      $time = date('d.m.Y, H:i:s', current_time('timestamp'));
      SystemLog::add('ftpcsv-manual', 'debug', 'manual import data missing, eventually prevented second import start - ' . $time, array(
        'id' => $importId,
        'name' => 'Fehler'
      ));
      return;
    }

    // Create list of actual IDs
    $remoteIds = array();
    foreach ($indexes as $index) {
      $remoteIds[] = $import['list'][$index][0];
    }

    // Mark product as basically always valid to be imported (it wont miss post_name, as we use the main import file)
    add_filter('aboon_erp_product_validate', '__return_true', 9, 2);

    // Set file that replaces the actual file
    $this->manualImportFile = $import;

    foreach ($remoteIds as $remoteId) {
      if ((strlen($remoteId) > 0 || $remoteId > 0) && $this->validateImport($remoteId)) {
        // Convert and use append as we only import one prop possibly
        $product = $this->convertProduct($remoteId);
        // Save and import product if new
        $this->updateProduct($product);
        // After import, make sure to load the image (works only for existing products)
        if ($this->manualImportUpdateImage) {
          sleep(1);
          $this->convertImage($product);
          // And save again, as convert image doesn't :-)
          $this->updateProduct($product);
        }
      }
    }

    // Log that we have completed this
    $time = date('d.m.Y, H:i:s', current_time('timestamp'));
    SystemLog::add('ftpcsv-manual', 'debug', 'manual import finished at ' . $time, array(
      'id' => $importId,
      'name' => 'Importierte Zeilen: ' . count($indexes)
    ));

    // Do some minimal cache flushes
    wp_cache_delete('vpeList', 'PackagingUnit');
    wp_cache_delete('getBulkPricingData', 'Aboon');

    // Allow developers to add their own things
    do_action('aboon_after_ftpsv_manual_import', $importId);
  }

  /**
   * @return bool can be overridden to add special validation rules
   */
  public function validateImport($remoteId)
  {
    return apply_filters('aboon_erp_product_validate_manual_import', true, $remoteId);
  }

  /**
   * @return array cannot update single elements trough triggers
   */
  protected function getQueueTriggerObject(): array
  {
    return array();
  }
}