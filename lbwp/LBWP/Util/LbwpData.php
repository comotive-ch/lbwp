<?php

namespace LBWP\Util;

/**
 * Simple class to work with row based data to have more atomicity than with options
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class LbwpData
{
  /**
   * @var null|\wpdb the wordpress database class
   */
  protected $db = NULL;

  protected $userId = 0;
  /**
   * @var string the current keyspace to work with
   */
  protected $key = '_not_set';

  /**
   * Initializes the data table to work in the specified keyspace
   * @param string $rowKey the key space
   * @param int $userId the user id that transforms data
   */
  public function __construct($rowKey, $userId = 0)
  {
    $this->db = WordPress::getDb();
    $this->db->lbwp_data = $this->db->prefix . 'lbwp_data';
    Strings::alphaNumLow($rowKey);
    $this->key = $rowKey;
    $this->userId = intval($userId);

    // If there is no user given, assume currently logged in user
    if ($this->userId == 0) {
      $this->userId = intval(get_current_user_id());
    }
  }

  /**
   * @param string $rowKey the row keyspace to work with
   */
  public function setKey($rowKey)
  {
    Strings::alphaNumLow($rowKey);
    $this->key = $rowKey;
  }

  /**
   * @param int $userId the user that modifies data
   */
  public function setUserId($userId)
  {
    $this->userId = $userId;
  }

  /**
   * @return bool tells if the keyspace has rows
   */
  public function hasRows()
  {
    $sql = 'SELECT COUNT(pid) FROM {raw:lbwpData} WHERE row_key = {rowKey}';
    return $this->db->get_var(Strings::prepareSql($sql, array(
      'lbwpData' => $this->db->lbwp_data,
      'rowKey' => $this->key
    ))) > 0;
  }

  /**
   * @param string $orderBy database field
   * @param string $order ASC or DESC
   * @return array of all current rows in keyspace
   */
  public function getRows($orderBy = 'pid', $order = 'ASC')
  {
    $sql = '
      SELECT * FROM {raw:lbwpData} WHERE row_key = {rowKey}
      ORDER BY {raw:dataOrderBy} {raw:dataOrder}
    ';

    // Get the native rows
    $data = $this->db->get_results(Strings::prepareSql($sql, array(
      'lbwpData' => $this->db->lbwp_data,
      'rowKey' => $this->key,
      'dataOrderBy' => $orderBy,
      'dataOrder' => ($order == 'ASC') ? 'ASC' : 'DESC'
    )));

    return $this->convert($data);
  }

  /**
   * @param array $raw row database result set
   * @return array the data rows
   */
  protected function convert($raw)
  {
    $data = array();
    if (is_array($raw)) {
      foreach ($raw as $row) {
        $data[$row->row_id] = array(
          'pid' => intval($row->pid),
          'id' => $row->row_id,
          'key' => $row->row_key,
          'created' => $row->row_created,
          'modified' => $row->row_modified,
          'user' => intval($row->user_id),
          'data' => json_decode($row->row_data, true)
        );
      }
    } else if ($raw instanceof \stdClass) {
      $data = array(
        'pid' => intval($raw->pid),
        'id' => $raw->row_id,
        'key' => $raw->row_key,
        'created' => $raw->row_created,
        'modified' => $raw->row_modified,
        'user' => intval($raw->user_id),
        'data' => json_decode($raw->row_data, true)
      );
    }


    return $data;
  }

  /**
   * @param string $rowId the row id to check
   * @return bool tells if the row exists in keyspace
   */
  public function rowExists($rowId)
  {
    $sql = 'SELECT COUNT(pid) FROM {raw:lbwpData} WHERE row_key = {rowKey} and row_id = {rowId}';
    return $this->db->get_var(Strings::prepareSql($sql, array(
      'lbwpData' => $this->db->lbwp_data,
      'rowKey' => $this->key,
      'rowId' => $rowId
    ))) == 1;
  }

  /**
   * @param string $rowId the id of the row to get
   * @return array|bool the data row or false if not existing
   */
  public function getRow($rowId)
  {
    $sql = 'SELECT * FROM {raw:lbwpData} WHERE row_key = {rowKey} and row_id = {rowId}';
    // Get the native rows
    $data = $this->db->get_row(Strings::prepareSql($sql, array(
      'lbwpData' => $this->db->lbwp_data,
      'rowKey' => $this->key,
      'rowId' => $rowId
    )));

    return $this->convert($data);
  }

  /**
   * @param string $rowId the row
   * @param array $data the data to save
   * @return bool|int false if error, int if inserted/updated
   */
  public function updateRow($rowId, $data)
  {
    // Decide whether to create a new row or update an existing one
    if ($this->rowExists($rowId)) {
      return $this->db->update(
        $this->db->lbwp_data,
        array(
          'user_id' => $this->userId,
          'row_modified' => current_time('mysql'),
          'row_data' => json_encode($data)
        ),
        array(
          'row_key' => $this->key,
          'row_id' => $rowId,
        )
      );
    } else {
      return $this->db->insert(
        $this->db->lbwp_data,
        array(
          'row_key' => $this->key,
          'row_id' => $rowId,
          'user_id' => $this->userId,
          'row_data' => json_encode($data)
        )
      );
    }
  }

  /**
   * @param string $rowId the row
   * @return bool true if the deletion worked
   */
  public function deleteRow($rowId)
  {
    if ($rowId !== false) {
      Strings::alphaNumLow($rowId);
      $this->db->query('
        DELETE FROM ' . $this->db->lbwp_data . '
        WHERE row_id = "' . $rowId . '" AND row_key = "' . $this->key . '"
      ');
      return true;
    }

    return false;
  }

  /**
   * @param int $pid a pid
   * @return string|bool row id or false if not existing
   */
  public function getRowIdByPid($pid)
  {
    $rowId = $this->db->get_var('
      SELECT row_id FROM ' . $this->db->lbwp_data . '
      WHERE row_key = "' . $this->key . '" AND pid = ' . intval($pid) . '
    ');

    return strlen($rowId) > 0 ? $rowId : false;
  }

  /**
   * @param int $pid the pid to get, only works if pid is in current keyspace
   * @return array|bool the data row or false if not existing
   */
  public function getRowByPid($pid)
  {
    return $this->getRow($this->getRowIdByPid($pid));
  }

  /**
   * @param int $pid the pid to get, only works if pid is in current keyspace
   * @param array $data the data to save in that row
   * @return bool true or false if the saving/adding worked
   */
  public function updateRowByPid($pid, $data)
  {
    return $this->updateRow($this->getRowIdByPid($pid), $data);
  }

  /**
   * @param int $pid the pid to get, only works if pid is in current keyspace
   * @return bool true if the deletion worked
   */
  public function deleteRowByPid($pid)
  {
    return $this->deleteRow($this->getRowIdByPid($pid));
  }
}