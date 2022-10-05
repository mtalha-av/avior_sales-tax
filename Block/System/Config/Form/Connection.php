<?php

namespace Avior\TaxCalculation\Block\System\Config\Form;

use Avior\TaxCalculation\Block\Adminhtml\Connection\Status as ConnectionStatus;
use Magento\Config\Block\System\Config\Form\Field as ConfigFormField;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Class Connection
 *
 * @package Avior\TaxCalculation\Block\System\Config\Form
 */
class Connection extends ConfigFormField
{
    /**
     * @param AbstractElement $element
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $connectionHtml = $this->getLayout()->createBlock(ConnectionStatus::class)->toHtml();

        return $element->getElementHtml() . $connectionHtml;
    }
}

