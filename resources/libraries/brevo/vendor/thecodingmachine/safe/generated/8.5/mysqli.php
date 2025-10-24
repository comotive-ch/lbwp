<?php

namespace BrevoScoped\Safe;

use BrevoScoped\Safe\Exceptions\MysqliException;
/**
 * Returns client per-process statistics.
 *
 * @return array|false Returns an array with client stats.
 *
 */
function mysqli_get_client_stats()
{
    error_clear_last();
    $safeResult = \mysqli_get_client_stats();
    return $safeResult;
}
