<?php

namespace Avior\TaxCalculation\Model\Tax\Sales\Total\Quote;

use Avior\TaxCalculation\Helper\Data;
use Avior\TaxCalculation\Helper\Data as DataHelper;
use Avior\TaxCalculation\Model\Tax\TaxCalculation;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Framework\Exception\InputException;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemExtensionFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Model\Config;

/**
 * Class Tax
 *
 * @package Avior\TaxCalculation\Model\Tax\Sales\Total\Quote
 */
class Tax extends \Magento\Tax\Model\Sales\Total\Quote\Tax
{
    /**
     * @var QuoteDetailsItemExtensionFactory
     */
    private $extensionFactory;

    /**
     * @var TaxCalculation
     */
    private $taxCalculation;

    /**
     * @var DataHelper
     */
    private $dataHelper;

    /**
     * @param Config $taxConfig
     * @param TaxCalculationInterface $taxCalculationService
     * @param QuoteDetailsInterfaceFactory $quoteDetailsDataObjectFactory
     * @param QuoteDetailsItemInterfaceFactory $quoteDetailsItemDataObjectFactory
     * @param TaxClassKeyInterfaceFactory $taxClassKeyDataObjectFactory
     * @param AddressInterfaceFactory $customerAddressFactory
     * @param RegionInterfaceFactory $customerAddressRegionFactory
     * @param \Magento\Tax\Helper\Data $taxData
     * @param QuoteDetailsItemExtensionFactory $extensionFactory
     * @param TaxCalculation $taxCalculation
     * @param DataHelper $dataHelper
     */
    public function __construct(
        Config                           $taxConfig,
        TaxCalculationInterface          $taxCalculationService,
        QuoteDetailsInterfaceFactory     $quoteDetailsDataObjectFactory,
        QuoteDetailsItemInterfaceFactory $quoteDetailsItemDataObjectFactory,
        TaxClassKeyInterfaceFactory      $taxClassKeyDataObjectFactory,
        AddressInterfaceFactory          $customerAddressFactory,
        RegionInterfaceFactory           $customerAddressRegionFactory,
        \Magento\Tax\Helper\Data         $taxData,
        QuoteDetailsItemExtensionFactory $extensionFactory,
        TaxCalculation                   $taxCalculation,
        DataHelper                       $dataHelper

    ) {
        $this->extensionFactory = $extensionFactory;
        $this->taxCalculation   = $taxCalculation;
        $this->dataHelper       = $dataHelper;
        parent::__construct(
            $taxConfig,
            $taxCalculationService,
            $quoteDetailsDataObjectFactory,
            $quoteDetailsItemDataObjectFactory,
            $taxClassKeyDataObjectFactory,
            $customerAddressFactory,
            $customerAddressRegionFactory,
            $taxData
        );
    }

    /**
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     * @return $this|Tax
     * @throws InputException
     */
    public function collect(
        Quote                       $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total                       $total
    ) {
        $isEnabled = $this->dataHelper->isEnabled($quote->getStoreId());
        if (!$isEnabled) {
            return parent::collect($quote, $shippingAssignment, $total);
        }

        if (!$shippingAssignment->getItems()) {
            return $this;
        }

        $baseQuoteTaxDetails = $this->getQuoteTaxDetailsInterface($shippingAssignment, $total, true);
        $response            = $this->dataHelper->fetchTax($quote, $baseQuoteTaxDetails, $shippingAssignment);

        if ($response) {
            foreach ($response as $data) {
                if (isset($data['error code'])) {
                    throw new InputException(__($data['error comments']));
                }
            }
            $this->clearValues($total);

            $quoteTax = $this->getQuoteTax($quote, $shippingAssignment, $total);
            //Populate address and items with tax calculation results
            $itemsByType = $this->organizeItemTaxDetailsByType($quoteTax['tax_details'], $quoteTax['base_tax_details']);
            if (isset($itemsByType[self::ITEM_TYPE_PRODUCT])) {
                $this->processProductItems($shippingAssignment, $itemsByType[self::ITEM_TYPE_PRODUCT], $total);
            }

            //Apply Taxes
            $this->processAppliedTaxes($total, $shippingAssignment, $itemsByType);
        } else {
            throw new InputException(__('Please enter the correct address for the best tax calculation results.'));
        }
        return $this;
    }

    /**
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     * @return array
     */
    private function getQuoteTax(
        Quote                       $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total                       $total
    ) {
        $baseTaxDetailsInterface = $this->getQuoteTaxDetailsInterface($shippingAssignment, $total, true);
        $baseTaxDetails          = $this->getQuoteTaxDetailsOverride($quote, $baseTaxDetailsInterface, true);

        $taxDetailsInterface = $this->getQuoteTaxDetailsInterface($shippingAssignment, $total, false);
        $taxDetails          = $this->getQuoteTaxDetailsOverride($quote, $taxDetailsInterface, false);

        return [
            'base_tax_details' => $baseTaxDetails,
            'tax_details'      => $taxDetails
        ];
    }

    /**
     * @param $shippingAssignment
     * @param $total
     * @param $useBaseCurrency
     * @return \Magento\Tax\Api\Data\QuoteDetailsInterface
     */
    private function getQuoteTaxDetailsInterface($shippingAssignment, $total, $useBaseCurrency)
    {
        $address = $shippingAssignment->getShipping()->getAddress();

        $priceIncludesTax = $this->_config->priceIncludesTax($address->getQuote()->getStore());

        $itemDataObjects = $this->mapItems($shippingAssignment, $priceIncludesTax, $useBaseCurrency);

        $quoteExtraTaxables = $this->mapQuoteExtraTaxables(
            $this->quoteDetailsItemDataObjectFactory,
            $address,
            $useBaseCurrency
        );

        if (!empty($quoteExtraTaxables)) {
            $itemDataObjects = array_merge($itemDataObjects, $quoteExtraTaxables);
        }
        //Preparation for calling taxCalculationService
        $quoteDetails = $this->prepareQuoteDetails($shippingAssignment, $itemDataObjects);

        return $quoteDetails;
    }

    /**
     * @param Quote $quote
     * @param \Magento\Tax\Api\Data\QuoteDetailsInterface $taxDetails
     * @param $useBaseCurrency
     * @return \Magento\Tax\Api\Data\TaxDetailsInterface
     */
    public function getQuoteTaxDetailsOverride(
        Quote                                       $quote,
        \Magento\Tax\Api\Data\QuoteDetailsInterface $taxDetails,
                                                    $useBaseCurrency
    ) {
        $store      = $quote->getStore();
        $taxDetails = $this->taxCalculation->calculateTaxDetails($taxDetails, $useBaseCurrency, $store);
        return $taxDetails;
    }

    /**
     * @param QuoteDetailsItemInterfaceFactory $itemDataObjectFactory
     * @param Quote\Item\AbstractItem $item
     * @param $priceIncludesTax
     * @param $useBaseCurrency
     * @param $parentCode
     * @return \Magento\Tax\Api\Data\QuoteDetailsItemInterface
     */
    public function mapItem(
        QuoteDetailsItemInterfaceFactory             $itemDataObjectFactory,
        \Magento\Quote\Model\Quote\Item\AbstractItem $item,
                                                     $priceIncludesTax,
                                                     $useBaseCurrency,
                                                     $parentCode = null
    ) {
        $itemDataObject = parent::mapItem(
            $itemDataObjectFactory,
            $item,
            $priceIncludesTax,
            $useBaseCurrency,
            $parentCode
        );
        $itemDataObject->setSku($item->getProduct()->getSku());

        $lineItemTax = $this->dataHelper->getResponseLineItem($itemDataObject->getCode());
        if ($lineItemTax) {
            /**
             * @var \Magento\Tax\Api\Data\QuoteDetailsItemExtensionInterface $extensionAttributes
             */
            $extensionAttributes = $itemDataObject->getExtensionAttributes()
                ? $itemDataObject->getExtensionAttributes()
                : $this->extensionFactory->create();

            $taxCollectable  = 0;
            $taxCombinedRate = 0;
            $id              = "";
            foreach ($lineItemTax as $key => $value) {
                if (strpos($key, 'fips tax amount') === 0) {
                    $taxCollectable += $value;
                }
                if (strpos($key, 'fips tax rate') === 0) {
                    $taxCombinedRate += $value;
                }
                if (strpos($key, 'fips jurisdiction code') === 0) {
                    $id .= $value;
                }
            }

            $extensionAttributes->setTaxCollectable($taxCollectable);
            $extensionAttributes->setCombinedTaxRate($taxCombinedRate);
            $extensionAttributes->setProductType($item->getProductType());
            $extensionAttributes->setPriceType($item->getProduct()->getPriceType());

            $jurisdictionRates = [];
            if ($taxCollectable) {
                $jurisdictionRates = [
                    'sales tax' => [
                        'rate'   => (double)$taxCombinedRate,
                        'amount' => (double)$taxCollectable,
                        'id'     => $id,
                    ],
                ];
            }

            $extensionAttributes->setJurisdictionTaxRates($jurisdictionRates);

            $itemDataObject->setExtensionAttributes($extensionAttributes);
        }

        return $itemDataObject;
    }

    /**
     * @param \Magento\Tax\Api\Data\QuoteDetailsItemInterface $shippingDataObject
     * @return \Magento\Tax\Api\Data\QuoteDetailsItemInterface
     */
    private function extendShippingItem(
        \Magento\Tax\Api\Data\QuoteDetailsItemInterface $shippingDataObject
    ) {
        /** @var \Magento\Tax\Api\Data\QuoteDetailsItemExtensionInterface $extensionAttributes */
        $extensionAttributes = $shippingDataObject->getExtensionAttributes()
            ? $shippingDataObject->getExtensionAttributes()
            : $this->extensionFactory->create();
        $shippingTax         = $this->dataHelper->getResponseShipping();
        if (isset($shippingTax['amount']) && isset($shippingTax['rate'])) {
            $extensionAttributes->setTaxCollectable((float)$shippingTax['amount']);
            $extensionAttributes->setCombinedTaxRate((float)$shippingTax['rate']);

            $shippingDataObject->setExtensionAttributes($extensionAttributes);
        }
        return $shippingDataObject;
    }
}
