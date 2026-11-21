<?php

use LBWP\Module\General\Cms\InboxForwarder;

require_once '../../../../../wp-load.php';

(new InboxForwarder())->run();
