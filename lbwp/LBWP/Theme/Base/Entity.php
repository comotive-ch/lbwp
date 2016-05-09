<?php

namespace LBWP\Theme\Base;
use LBWP\Util\String;
use LBWP\Util\WordPress;
use StepChallenge\Helper\Constants;

/**
 * Base class for theme entities
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Entity
{
  /**
   * @var int the id of the entity element
   */
  protected $id = 0;
  /**
   * @var array name of primary keys (for now, only one item is supported
   */
  protected $primaryKey = array();
  /**
   * @var array list of field names that need to be jsonized
   */
  protected $jsonFields = array();
  /**
   * @var string the table name to use
   */
  protected $table = '';
  /**
   * @var array the data
   */
  protected $data = array();
  /**
   * @var string the cache key of the current object
   */
  protected $cacheKey = '';
  /**
   * @var string the cache group for entities
   */
  protected $cacheGroup = 'ThemeEntity';
  /**
   * @var int the time an object is cached before reloaded from db automatically
   */
  protected $cacheTime = 7200;
  /**
   * @var \wpdb the database
   */
  protected $db = NULL;

  /**
   * This will load the dataset for $id from $table
   * @param int $id the id of the dataset to load
   */
  protected function __construct($id)
  {
    $this->id = $id;
    $this->db = WordPress::getDb();
    $this->cacheKey = $this->table . '_' . $this->id;

    // Insert a new dataset, if there is no id set
    if ($id == 0) {
      $this->create();
    }

    $this->load();
  }

  /**
   * This will load the data object from cache or db
   */
  protected function load()
  {
    // See if we can get the item from cache
    $this->data = wp_cache_get($this->cacheKey, $this->cacheGroup);
    // If there's nothing, load the data from DB and cache it
    if ($this->data === false) {
      $this->loadFromDatabase();
      wp_cache_set($this->cacheKey, $this->data, $this->cacheGroup, $this->cacheTime);
    }

    // Run possible user conversion
    $this->afterLoadingCache();
  }

  /**
   * Loads the data from id
   */
  protected function loadFromDatabase()
  {
    // Prepare sql and load data
    $sql = 'SELECT * FROM {sql:tableName} WHERE {sql:primaryKey} = {primaryValue}';
    $this->data = $this->db->get_row(String::prepareSql($sql, array(
      'tableName' => $this->table,
      'primaryKey' => $this->primaryKey[0],
      'primaryValue' => $this->id
    )), ARRAY_A);

    // Decode json fields
    foreach ($this->data as $key => $value) {
      if (in_array($key, $this->jsonFields)) {
        $this->data[$key] = json_decode($value, true);
        if ($this->data[$key] === NULL) {
          $this->data[$key] = array();
        }
      }
    }

    // Run possible user conversion
    $this->afterLoadingDatabase();
  }

  /**
   * @param string $key the database field name
   * @return mixed the value of the database field
   */
  public function get($key)
  {
    return $this->data[$key];
  }

  /**
   * @param string $key a json-ized field
   * @param string $field the field key within the json field
   * @return mixed the value or null if non existant
   */
  public function getField($key, $field)
  {
   return $this->data[$key][$field];
  }

  /**
   * @param string $key the database field name
   * @param mixed $value the value of the field
   */
  public function set($key, $value)
  {
    $this->data[$key] = $value;
  }

  /**
   * @param string $key a json-ized field
   * @param string $field the field key within the json field
   * @param mixed $value the value of the field
   */
  public function setField($key, $field, $value)
  {
    $this->data[$key][$field] = $value;
  }

  /**
   * Commits the current data back to the database
   */
  public function commit()
  {
    $commitData = $this->data;
    // Unset the primary key
    unset($commitData[$this->primaryKey[0]]);

    // json encode fields
    foreach ($commitData as $key => $value) {
      if (in_array($key, $this->jsonFields)) {
        $commitData[$key] = json_encode($value);
      }
    }

    // Save to db
    $this->db->update(
      $this->table,
      $commitData,
      array($this->primaryKey[0] => $this->id)
    );

    // Delete from cache
    $this->resetCache();
  }

  /**
   * Resets the cache of the object (used on commit and delete)
   */
  public function resetCache()
  {
    wp_cache_delete($this->cacheKey, $this->cacheGroup);
  }

  /**
   * Removes the object from DB and removes it from cache
   */
  public function removeObject()
  {
    $sql = 'DELETE FROM {sql:tableName} WHERE {sql:primaryKey} = {primaryValue}';
    $this->db->query(String::prepareSql($sql, array(
      'tableName' => $this->table,
      'primaryKey' => $this->primaryKey[0],
      'primaryValue' => $this->id
    )));

    $this->resetCache();
  }

  /**
   * @param string $table the table to insert to
   * @param array $data the data
   * @return int the newly inserted id
   */
  public static function basicInsert($table, $data)
  {
    $db = WordPress::getDb();
    $db->insert($table, $data);
    return intval($db->insert_id);
  }

  /**
   * Create a new data set and set the id
   */
  protected function create()
  {
    $this->db->insert(
      Constants::TABLE_GROUP,
      array($this->primaryKey[0] => NULL)
    );

    $this->id = $this->db->insert_id;
    $this->data[$this->primaryKey[0]] = $this->id;
  }

  /**
   * Overrideable to modify data right after loading (only ran when loading from db)
   */
  protected function afterLoadingDatabase() {  }

  /**
   * Overrideable to modify data right after loading (always ran when loading from db/cache)
   */
  protected function afterLoadingCache() {  }
}