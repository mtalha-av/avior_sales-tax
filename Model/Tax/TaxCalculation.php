<?php

namespace Avior\TaxCalculation\Model\Tax;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Api\Data\AppliedTaxInterfaceFactory;
use Magento\Tax\Api\Data\AppliedTaxRateInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterface;
use Magento\Tax\Api\Data\TaxDetailsInterfaceFactory;
use Magento\Tax\Api\Data\TaxDetailsItemInterfaceFactory;
use Magento\Tax\Api\TaxClassManagementInterface;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Calculation\CalculatorFactory;
use Magento\Tax\Model\Config;
use Magento\Tax\Model\TaxDetails\TaxDetails;

/**
 * Class TaxCalculation
 *
 * @package Avior\TaxCalculation\Model\Tax
 */
class TaxCalculation extends \Magento\Tax\Model\TaxCalculation
{
    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var \Avior\TaxCalculation\Logger\Logger
     */
    protected $logger;

    /**
     * @var AppliedTaxInterfaceFactory
     */
    private $appliedTaxDataObjectFactory;

    /**
     * @var AppliedTaxRateInterfaceFactory
     */
    private $appliedTaxRateDataObjectFactory;

    /**
     * @var
     */
    private $keyedQuoteDetailItems;

    /**
     * @param Calculation $calculation
     * @param CalculatorFactory $calculatorFactory
     * @param Config $config
     * @param TaxDetailsInterfaceFactory $taxDetailsDataObjectFactory
     * @param TaxDetailsItemInterfaceFactory $taxDetailsItemDataObjectFactory
     * @param StoreManagerInterface $storeManager
     * @param TaxClassManagementInterface $taxClassManagement
     * @param \Magento\Framework\Api\DataObjectHelper $dataObjectHelper
     * @param \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency
     * @param AppliedTaxInterfaceFactory $appliedTaxDataObjectFactory
     * @param AppliedTaxRateInterfaceFactory $appliedTaxRateDataObjectFactory
     * @param \Avior\TaxCalculation\Logger\Logger $logger
     */
    public function __construct(
        Calculation                                       $calculation,
        CalculatorFactory                                 $calculatorFactory,
        Config                                            $config,
        TaxDetailsInterfaceFactory                        $taxDetailsDataObjectFactory,
        TaxDetailsItemInterfaceFactory                    $taxDetailsItemDataObjectFactory,
        StoreManagerInterface                             $storeManager,
        TaxClassManagementInterface                       $taxClassManagement,
        \Magento\Framework\Api\DataObjectHelper           $dataObjectHelper,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        AppliedTaxInterfaceFactory                        $appliedTaxDataObjectFactory,
        AppliedTaxRateInterfaceFactory                    $appliedTaxRateDataObjectFactory,
        \Avior\TaxCalculation\Logger\Logger               $logger
    ) {
        $this->logger                          = $logger;
        $this->priceCurrency                   = $priceCurrency;
        $this->appliedTaxDataObjectFactory     = $appliedTaxDataObjectFactory;
        $this->appliedTaxRateDataObjectFactory = $appliedTaxRateDataObjectFactory;
        return parent::__construct(
            $calculation,
            $calculatorFactory,
            $config,
            $taxDetailsDataObjectFactory,
            $taxDetailsItemDataObjectFactory,
            $storeManager,
            $taxClassManagement,
            $dataObjectHelper
        );
    }

    /**
     * @param \Magento\Tax\Api\Data\QuoteDetailsInterface $quoteDetails
     * @param $useBaseCurrency
     * @param $scope
     * @return \Magento\Tax\Api\Data\TaxDetailsInterface
     */
    public function calculateTaxDetails(
        \Magento\Tax\Api\Data\QuoteDetailsInterface $quoteDetails,
                                                    $useBaseCurrency,
                                                    $scope
    ) {
        // initial TaxDetails data
        $taxDetailsData = [
            TaxDetails::KEY_SUBTOTAL                         => 0.0,
            TaxDetails::KEY_TAX_AMOUNT                       => 0.0,
            TaxDetails::KEY_DISCOUNT_TAX_COMPENSATION_AMOUNT => 0.0,
            TaxDetails::KEY_APPLIED_TAXES                    => [],
            TaxDetails::KEY_ITEMS                            => [],
        ];
        $items          = $quoteDetails->getItems();
        if (empty($items)) {
            return $this->taxDetailsDataObjectFactory->create()
                ->setSubtotal(0.0)
                ->setTaxAmount(0.0)
                ->setDiscountTaxCompensationAmount(0.0)
                ->setAppliedTaxes([])
                ->setItems([]);
        }
        $keyedItems       = [];
        $parentToChildren = [];
        foreach ($items as $item) {
            if ($item->getParentCode() === null) {
                $keyedItems[$item->getCode()] = $item;
            } else {
                $parentToChildren[$item->getParentCode()][] = $item;
            }
        }
        $this->keyedQuoteDetailItems = $keyedItems;
        $processedItems              = [];
        /** @var QuoteDetailsItemInterface $item */
        foreach ($keyedItems as $item) {
            if (isset($parentToChildren[$item->getCode()])) {
                $processedChildren = [];
                foreach ($parentToChildren[$item->getCode()] as $child) {
                    $processedItem                             = $this->processItemDetails($child, $useBaseCurrency, $scope);
                    $taxDetailsData                            = $this->aggregateItemData($taxDetailsData, $processedItem);
                    $processedItems[$processedItem->getCode()] = $processedItem;
                    $processedChildren[]                       = $processedItem;
                }
                $processedItem = $this->calculateParent($processedChildren, $item->getQuantity());
                $processedItem->setCode($item->getCode());
                $processedItem->setType($item->getType());
            } else {
                $processedItem  = $this->processItemDetails($item, $useBaseCurrency, $scope);
                $taxDetailsData = $this->aggregateItemData($taxDetailsData, $processedItem);
            }
            $processedItems[$processedItem->getCode()] = $processedItem;
        }
        $taxDetailsDataObject = $this->taxDetailsDataObjectFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $taxDetailsDataObject,
            $taxDetailsData,
            '\Magento\Tax\Api\Data\TaxDetailsInterface'
        );
        $taxDetailsDataObject->setItems($processedItems);
        return $taxDetailsDataObject;
    }

    /**
     * @param QuoteDetailsItemInterface $item
     * @param $useBaseCurrency
     * @param $scope
     * @return \Magento\Tax\Api\Data\TaxDetailsItemInterface
     */
    private function processItemDetails(
        QuoteDetailsItemInterface $item,
                                  $useBaseCurrency,
                                  $scope
    ) {
        $price               = $item->getUnitPrice();
        $quantity            = $this->getTotalQuantity($item);
        $extensionAttributes = $item->getExtensionAttributes();
        $taxCollectable      = $extensionAttributes ? $extensionAttributes->getTaxCollectable() : 0;
        $taxPercent          = $extensionAttributes ? $extensionAttributes->getCombinedTaxRate() : 0;
        if (!$useBaseCurrency) {
            $taxCollectable = $this->priceCurrency->convert($taxCollectable, $scope);
        }
        $rowTotal                      = $price * $quantity;
        $rowTotalInclTax               = $rowTotal + $taxCollectable;
        $priceInclTax                  = $rowTotalInclTax / $quantity;
        $discountTaxCompensationAmount = 0;
        $appliedTaxes                  = $this->getAppliedTaxes($item);
        return $this->taxDetailsItemDataObjectFactory->create()
            ->setCode($item->getCode())
            ->setType($item->getType())
            ->setRowTax($taxCollectable)
            ->setPrice($price)
            ->setPriceInclTax($priceInclTax)
            ->setRowTotal($rowTotal)
            ->setRowTotalInclTax($rowTotalInclTax)
            ->setDiscountTaxCompensationAmount($discountTaxCompensationAmount)
            ->setAssociatedItemCode($item->getAssociatedItemCode())
            ->setTaxPercent($taxPercent)
            ->setAppliedTaxes($appliedTaxes);
    }

    /**
     * @param QuoteDetailsItemInterface $item
     * @return array
     */
    private function getAppliedTaxes(
        QuoteDetailsItemInterface $item
    ) {
        $extensionAttributes  = $item->getExtensionAttributes();
        $jurisdictionTaxRates = $extensionAttributes ? $extensionAttributes->getJurisdictionTaxRates() : [];
        $appliedTaxes         = [];

        if ($jurisdictionTaxRates !== null) {
            if (!empty($jurisdictionTaxRates)) {
                foreach ($jurisdictionTaxRates as $label => $jurisdictionTaxRate) {
                    if ($jurisdictionTaxRate['rate'] > 0) {
                        $rateDataObject       = $this->appliedTaxRateDataObjectFactory->create()
                            ->setPercent($jurisdictionTaxRate['rate'])
                            ->setCode($jurisdictionTaxRate['id'])
                            ->setTitle(strtoupper($label) . (empty($jurisdictionTaxRate['code']) ? '' :" (" . $jurisdictionTaxRate['id'] . ")"));
                        $appliedTaxDataObject = $this->appliedTaxDataObjectFactory->create();
                        $appliedTaxDataObject->setAmount((float)$jurisdictionTaxRate['amount']);
                        $appliedTaxDataObject->setPercent($jurisdictionTaxRate['rate']);
                        $appliedTaxDataObject->setTaxRateKey($jurisdictionTaxRate['id']);
                        $appliedTaxDataObject->setRates([$rateDataObject]);
                        $appliedTaxes[$appliedTaxDataObject->getTaxRateKey()] = $appliedTaxDataObject;
                    }
                }
            }
        }
        return $appliedTaxes;
    }

    /**
     * @param QuoteDetailsItemInterface $item
     * @return float|int
     */
    protected function getTotalQuantity(QuoteDetailsItemInterface $item)
    {
        // @codingStandardsIgnoreEnd
        if ($item->getParentCode()) {
            $parentQuantity = $this->keyedQuoteDetailItems[$item->getParentCode()]->getQuantity();
            return $parentQuantity * $item->getQuantity();
        }
        return $item->getQuantity();
    }
}
