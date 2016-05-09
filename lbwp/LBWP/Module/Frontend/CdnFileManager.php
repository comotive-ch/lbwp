<?php

namespace LBWP\Module\Frontend;

/**
 * @deprecated we need the empty class for backwards compat
 * This module handels resource files that are being sent to
 * s3, compressed and automatically replaced in the output.
 * There is also a view "sync-cdnfiles.php" that does the compression,
 * thus being executed on the MASTER_HOST. Syncing only takes place if a
 * file version is changed. The master database stores all the
 * files and their state, version and names globally.
 * @author Michael Sebel <michael@comotive.ch>
 */
class CdnFileManager extends \LBWP\Module\Base
{

  public function initialize()
  {

  }
}