<?php

declare (strict_types=1);
namespace BrevoScoped\Swoole;

class ExitException extends Exception
{
    private $flags = 0;
    private $status = 0;
    /**
     * @return mixed
     */
    public function getFlags()
    {
    }
    /**
     * @return mixed
     */
    public function getStatus()
    {
    }
}
