<?php


namespace LBWP\Helper\Import;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\File;

/**
 * Very simple FTP connection class
 * @package LBWP\Helper\Import
 */
class Ftp
{
  /**
   * @var resource
   */
  protected $connection = null;

  /**
   * @param $server
   * @param $user
   * @param $password
   * @param int $port
   * @param bool $passive
   */
  public function __construct($server, $user, $password, $port = 21, $passive = true)
  {
    $this->connection = ftp_connect($server, $port);
    ftp_login($this->connection, $user, $password);
    ftp_pasv($this->connection, true);
  }

  /**
   * @param string $fileName
   * @return string the local path of the copied file
   */
  public function getFile($fileName)
  {
    $path = File::getNewUploadFolder() . File::getFileOnly($fileName);
    ftp_get($this->connection, $path, $fileName, FTP_BINARY);
    return $path;
  }

  /**
   * @param string $local
   * @param string $remote
   */
  public function uploadFile($local, $remote)
  {
    ftp_put($this->connection, $remote, $local, FTP_BINARY);
  }

  /**
   * @param string $fileName remote file path on server
   * @param int $age file age in seconds
   * @return string path to the temporary local file
   */
  public function getFileCached($fileName, $age)
  {
    $path = '/tmp/' . File::getFileOnly($fileName);
    // Load the file if it doesn't exist or it is old enough
    if (!file_exists($path) || (filectime($path) + $age) < current_time('timestamp')) {
      ftp_get($this->connection, $path, $fileName, FTP_BINARY);
    }
    return $path;
  }

  /**
   * Delete the given file
   * @param $path string the path to the file
   * @param $days int days for the pdf file to be older to be deleted. Default: 7
   * @param $pattern string a asteriskable pattern which files to delete, empty for everything
   * @return void
   */
  public function deleteOldFiles($path, $days = 7, $pattern = '')
  {
    $files = ftp_nlist($this->connection, $path);
    $deleted = array();

    foreach ($files as $file) {
      if (ftp_mdtm($this->connection, $file) < time() - 60 * 60 * 24 * $days) {
        // Check for fnmatching the file if given, or delete everything if not pattern
        if (((strlen($file)) > 0 && fnmatch($pattern, $file) || strlen($pattern) == 0) && ftp_delete($this->connection, $file)) {
          $deleted[] = $file;
        }
      }
    }

    if (!empty($deleted)) {
      $deletedFiles = implode(', ', $deleted);
      SystemLog::add('FTP: File(s) gelöscht', 'debug', 'Filename(n)', $deletedFiles);
    }
  }

  /**
   * Close the FTP connection
   */
  public function close()
  {
    ftp_close($this->connection);
  }
}