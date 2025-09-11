<?php

namespace LBWP\Util;

use A\B\MyClass;
use LBWP\Module\Backend\S3Upload;

/**
 * Statische Funktionen für Filezugriffe
 * @author Michael Sebel <michael@comotive.ch>
 */
class File
{

	/**
	 * Extension eines Files zurückgeben inklusive . am Anfang
	 * @param string $sFile zu bearbeitendes File
	 * @return string Dateiendung mit Punkt
	 */
	public static function getExtension($sFile)
  {
		return(substr($sFile,strripos($sFile,'.')));
	}

  /**
   * @param string $command
   * @return void
   */
  public static function debugExec($command)
  {
    // Open the process
    $descriptorspec = array(
      0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
      1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
      2 => array("pipe", "w")   // stderr is a pipe that the child will write to
    );

    $process = proc_open($command, $descriptorspec, $pipes);

    if (is_resource($process)) {
      // Close stdin since we're not writing to it
      fclose($pipes[0]);
      // Read from STDOUT
      $stdout = stream_get_contents($pipes[1]);
      // Read from STDERR
      $stderr = stream_get_contents($pipes[2]);
      // Close the pipes
      fclose($pipes[1]);
      fclose($pipes[2]);
      // Close the process
      $return_value = proc_close($process);
      // Output the results
      echo "STDOUT: " . $stdout . "\n";
      echo "STDERR: " . $stderr . "\n";
      echo "Return Value: " . $return_value . "\n";
    }
  }

	/**
	 * Gibt ein Array aller Files in einem Ordner zurück
	 * @param string $sFolder Ordnername
	 * @param bool $filesOnly nur filenamen, nicht ganze pfade
	 * @return array Gefundene Files im Ordner
	 */
	public static function getFiles($sFolder,$filesOnly = false)
  {
		$arrFiles = array();
		// Folder durchgehen
		if ($resDir = opendir($sFolder)) {
			while (($sFile = readdir($resDir)) !== false) {
				if (filetype($sFolder . $sFile) == 'file') {
			if ($filesOnly) {
				array_push($arrFiles,$sFile);
			} else {
				array_push($arrFiles,$sFolder.$sFile);
			}
				}
			}
		}
		// Ordner schliessen und Rückgabe
		closedir($resDir);
		return($arrFiles);
	}

	/**
	 * Gibt von einem Pfad nur den Ordnernamen zurück
	 * @param string $sPath Pfad zur Datei
	 * @return string the file folder
	 */
	public static function getFileFolder($sPath)
  {
		return(substr($sPath, 0, strrpos($sPath, '/') + 1));
	}

	/**
	 * Gibt von einem Pfad nur den Dateinamen zurück
	 * @param string $sPath Pfad zur Datei
	 * @return string the file only
	 */
	public static function getFileOnly($sPath)
  {
		return(substr($sPath, strrpos($sPath, '/') + 1));
	}

	/**
	 * Ordner rekursiv löschen (Egal ob Inhalt oder nicht)
	 * @param string $sPath Pfad zu einem Ordner oder File
	 * @return bool true/false ob Erfolgreich oder nicht
	 */
	public static function deleteFolder($sPath)
  {
		// Prüfen ob der Ordner/das File existiert
		if (!file_exists($sPath)) return(false);
		// File löschen, wenn es ein File ist
		if (is_file($sPath)) return(unlink($sPath));
		// Durch den Ordner loopen
		$dir = dir($sPath);
		while (false !== $entry = $dir->read()) {
			// Pointer überspringen
			if ($entry == '.' || $entry == '..') continue;
			// Rekursiv wieder aufrufen für Subfolder
			self::deleteFolder("$sPath/$entry");
		}
		// Resourcen schliessen
		$dir->close();
		return(rmdir($sPath));
	}

  /**
   * @param string $path the path
   * @param string $url the url
   */
	public static function downloadLargeFile($path, $url)
  {
    $fp = fopen($path, 'w+');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    // Write curl response to file
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
  }

	/**
	 * Gibt Timestamp einer Datei zurück (Änderungsdatum
	 * @param $file File, welches geprüft wird
	 * @return int Timestamp des Änderungsdatum
	 */
	public static function getStamp($file)
  {
		if (file_exists($file)) {
			return(filemtime($file));
		} else {
			return(0);
		}
	}

  /**
   * Get an array that represents directory tree
   * @param string $directory Directory path
   * @param bool $recursive nclude sub directories
   * @param bool $listDirs Include directories on listing
   * @param bool $listFiles Include files on listing
   * @param string $exclude Exclude paths that matches this regex
   * @return string[] list of files and/or directories
   */
  public static function scanDirectory($directory, $recursive = true, $listDirs = false, $listFiles = true, $exclude = '')
  {
    $arrayItems = array();
    $skipByExclude = false;
    $handle = opendir($directory);
    if ($handle) {
      while (false !== ($file = readdir($handle))) {
        preg_match("/(^(([\.]){1,2})$|(\.(svn|git)))$/iu", $file, $skip);
        if ($exclude) {
          preg_match($exclude, $file, $skipByExclude);
        }
        if (!$skip && !$skipByExclude) {
          if (is_dir($directory . DIRECTORY_SEPARATOR . $file)) {
            if ($recursive) {
              $arrayItems = array_merge($arrayItems, self::scanDirectory($directory . DIRECTORY_SEPARATOR . $file, $recursive, $listDirs, $listFiles, $exclude));
            }
            if ($listDirs) {
              $file = $directory . DIRECTORY_SEPARATOR . $file;
              $arrayItems[] = $file . '/';
            }
          } else {
            if ($listFiles) {
              $file = $directory . DIRECTORY_SEPARATOR . $file;
              $arrayItems[] = $file;
            }
          }
        }
      }
      closedir($handle);
    }
    return $arrayItems;
  }

  /**
   * @param string $file the file
   * @return bool if the mime is an image type
   */
  public static function isImage($file)
  {
    $extension = substr(self::getExtension($file), 1);

    switch (strtolower($extension)) {
      case 'jpg':
      case 'jpeg':
      case 'webp':
      case 'gif':
      case 'png':
        return true;
    }

    return false;
  }

  /**
   * @param string $mime the mime type
   * @return bool if the mime is an image type
   */
  public static function isImageMime($mime)
  {
    switch (strtolower($mime)) {
      case 'image/jpg':
      case 'image/jpeg':
      case 'image/gif':
      case 'image/png':
        return true;
    }

    return false;
  }

  /**
   * Provides a new empty folder to work with. The files should be deleted afterwards.
   * @return string a new folder to upload or move a file
   */
  public static function getNewUploadFolder($entropy = false)
  {
    $folder = time();
    if ($entropy) $folder .= '-' . uniqid();
    $path = WP_CONTENT_DIR . '/uploads/' . ASSET_KEY . '/' . $folder . '/';
    if (!file_exists($path)) {
      mkdir($path, 0755);
    }
    return $path;
  }

  /**
   * @return S3Upload
   */
  public static function getUploader()
  {
    return \LBWP\Core::getModule('S3Upload');
  }

  /**
   * @return string the resource path
   */
  public static function getResourceUri()
  {
    return get_bloginfo('url') . '/wp-content/plugins/lbwp/resources';
  }

  /**
   * @return string the resource path
   */
  public static function getViewsUri()
  {
    return get_bloginfo('url') . '/wp-content/plugins/lbwp/views';
  }

  /**
   * @return string the resource path
   */
  public static function getResourcePath()
  {
    return ABSPATH . 'wp-content/plugins/lbwp/resources';
  }

  /**
   * @return string the resource path
   */
  public static function getViewsPath()
  {
    return ABSPATH . 'wp-content/plugins/lbwp/views';
  }
}