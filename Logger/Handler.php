<?php

namespace Avior\TaxCalculation\Logger;

use Magento\Framework\Logger\Handler\Base;

/**
 * Class Handler
 *
 * @package Avior\TaxCalculation\Logger
 */
class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/avior_tax/debug.log';

    /**
     * @var int
     */
    protected $loggerType = \Monolog\Logger::DEBUG;
}
