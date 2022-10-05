<?php

namespace Avior\TaxCalculation\Block\Adminhtml\Connection;

use Avior\TaxCalculation\Helper\Data;
use Magento\Backend\Block\Template;

/**
 * Class Status
 *
 * @package Avior\TaxCalculation\Block\Adminhtml\Connection
 */
class Status extends Template
{
    /**
     * @var string
     */
    protected $_template = 'system/config/connection/status.phtml';

    /**
     * @var Data
     */
    protected $dataHelper;

    /**
     * @param Template\Context $context
     * @param Data $dataHelper
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        Data             $dataHelper,
        array            $data = []
    ) {
        parent::__construct($context, $data);
        $this->dataHelper = $dataHelper;
        $this->dataHelper->loginAvior();
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return $this->dataHelper->isConnected();
    }
}
