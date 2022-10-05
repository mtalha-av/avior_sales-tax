<?php

namespace Avior\TaxCalculation\Helper;

use Avior\TaxCalculation\Logger\Logger;
use DateTime;
use Exception;
use Magento\Bundle\Model\Product\Price;
use Magento\Catalog\Model\Product\Type;
use Magento\Config\Model\Config;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;
use Magento\Tax\Api\Data\QuoteDetailsInterface;

/**
 * Class Data
 *
 * @package Avior\TaxCalculation\Helper
 */
class Data extends AbstractHelper
{
    const CONFIG_PATH_AVIOR_ENABLED   = 'tax/avior_settings/enabled';
    const CONFIG_PATH_AVIOR_LOG       = 'tax/avior_settings/log';
    const CONFIG_PATH_AVIOR_USERNAME  = 'tax/avior_settings/username';
    const CONFIG_PATH_AVIOR_SELLER_ID = 'tax/avior_settings/seller_id';
    const CONFIG_PATH_AVIOR_PASSWORD  = 'tax/avior_settings/password';
    const CONFIG_PATH_AVIOR_ENDPOINT  = 'tax/avior_settings/endpoint';
    const CONFIG_PATH_IS_CONNECTED    = 'tax/avior_settings/is_connected';
    const CONFIG_PATH_AVIOR_TOKEN     = 'tax/avior_settings/token';
    const ENDPOINT_LOGIN              = 'api/auth/token/login';
    const ENDPOINT_FETCH_TAX          = 'suttaxd/gettax/';

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var Config
     */
    protected $configModel;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var RegionFactory
     */
    private $regionFactory;

    /**
     * @var bool
     */
    private $response = false;

    /**
     * @var array
     */
    private $mapping = [];

    /**
     * @param Context $context
     * @param Config $configModel
     * @param Curl $curl
     * @param Json $json
     * @param Logger $logger
     * @param EncryptorInterface $encryptor
     * @param RegionFactory $regionFactory
     */
    public function __construct(
        Context            $context,
        Config             $configModel,
        Curl               $curl,
        Json               $json,
        Logger             $logger,
        EncryptorInterface $encryptor,
        RegionFactory      $regionFactory
    ) {
        $this->encryptor     = $encryptor;
        $this->curl          = $curl;
        $this->json          = $json;
        $this->configModel   = $configModel;
        $this->logger        = $logger;
        $this->regionFactory = $regionFactory;
        parent::__construct($context);
    }

    /**
     * @param $path
     * @param $scopeId
     * @param $scope
     * @return mixed
     */
    public function getConfigValue($path, $scopeId = null, $scope = ScopeInterface::SCOPE_STORE)
    {
        return $this->scopeConfig->getValue($path, $scope, $scopeId);
    }

    /**
     * @param $path
     * @param $store
     * @param $scope
     * @return string
     */
    public function getEndpoint($path = '', $store = null, $scope = ScopeInterface::SCOPE_STORE)
    {
        return trim($this->getConfigValue(self::CONFIG_PATH_AVIOR_ENDPOINT, $store, $scope), '/') . "/" . $path;
    }

    /**
     * @param $store
     * @param $scope
     * @return mixed
     */
    public function getUserName($store = null, $scope = ScopeInterface::SCOPE_STORE)
    {
        return $this->getConfigValue(self::CONFIG_PATH_AVIOR_USERNAME, $store, $scope);
    }

    /**
     * @param $store
     * @param $scope
     * @return mixed
     */
    public function getSellerId($store = null, $scope = ScopeInterface::SCOPE_STORE)
    {
        return $this->getConfigValue(self::CONFIG_PATH_AVIOR_SELLER_ID, $store, $scope);
    }

    /**
     * @param $store
     * @param $scope
     * @return string
     */
    public function getPassword($store = null, $scope = ScopeInterface::SCOPE_STORE)
    {
        return $this->encryptor->decrypt($this->getConfigValue(self::CONFIG_PATH_AVIOR_PASSWORD, $store, $scope));
    }

    /**
     * @param $store
     * @param $scope
     * @return mixed
     */
    public function getToken($store = null, $scope = ScopeInterface::SCOPE_STORE)
    {
        return $this->getConfigValue(self::CONFIG_PATH_AVIOR_TOKEN, $store, $scope);
    }

    /**
     * @param $scopeId
     * @param $scope
     * @return bool
     */
    public function isEnabled($scopeId = null, $scope = ScopeInterface::SCOPE_STORE)
    {
        return (bool)$this->getConfigValue(self::CONFIG_PATH_AVIOR_ENABLED, $scopeId, $scope);
    }

    /**
     * @param $scopeId
     * @param $scope
     * @return bool
     */
    public function isLogEnabled($scopeId = null, $scope = ScopeInterface::SCOPE_STORE)
    {
        return (bool)$this->getConfigValue(self::CONFIG_PATH_AVIOR_LOG, $scopeId, $scope);
    }

    /**
     * @param $scopeId
     * @param $scope
     * @return bool
     */
    public function isConnected($scopeId = null, $scope = ScopeInterface::SCOPE_STORE)
    {
        return (bool)$this->getConfigValue(self::CONFIG_PATH_IS_CONNECTED, $scopeId, $scope);
    }

    /**
     * @param $isConnected
     * @return void
     * @throws Exception
     */
    public function saveIsConnected($isConnected)
    {
        $this->configModel->setDataByPath(Data::CONFIG_PATH_IS_CONNECTED, $isConnected);
        $this->configModel->save();
    }

    /**
     * @param $token
     * @return void
     * @throws Exception
     */
    public function saveToken($token)
    {
        $this->configModel->setDataByPath(Data::CONFIG_PATH_AVIOR_TOKEN, $token);
        $this->configModel->save();
    }

    /**
     * @param $data
     * @param $url
     * @param $needToken
     * @return array|bool|float|int|mixed|string|null
     */
    protected function postData($data, $url, $needToken = false)
    {
        $options  = array(
            'http' => array(
                'method'  => 'POST',
                'content' => $this->json->serialize($data),
                'header'  => "Content-Type: application/json\r\n" .
                    "Accept: application/json\r\n" . ($needToken ? ("Authorization: Token " . $this->getToken()) : '')
            )
        );
        $context  = stream_context_create($options);
        $result   = file_get_contents($url, false, $context);
        $response = $this->json->unserialize($result);

        return $response;
    }

    /**
     * @return void
     * @throws Exception
     */
    public function loginAvior()
    {
        try {
            $data     = array('username' => $this->getUserName(), 'password' => $this->getPassword());
            $url      = $this->getEndpoint(self::ENDPOINT_LOGIN);
            $response = $this->postData($data, $url);
            $token    = $response['auth_token'];
            if (empty($token)) {
                $this->saveIsConnected(0);
                $this->saveToken(null);
            } else {
                $this->saveIsConnected(1);
                $this->saveToken($token);
            }

            if ($this->isLogEnabled()) {
                $this->logger->debug("--- login ---");
                $this->logger->debug("Login Request: " . $this->json->serialize($data));
                $this->logger->debug("Login Response: " . $token);
                $this->logger->debug("--- login end ---");
            }
        } catch (Exception $exception) {
            $this->saveIsConnected(0);
            $this->saveToken(null);

            $this->logger->error("--- login EXCEPTION---");
            $this->logger->error("Login Request: " . $this->json->serialize($data));
            $this->logger->error("Exception: " . $exception->getMessage());
            $this->logger->error("--- login end ---");
        }
    }

    /**
     * @param QuoteDetailsInterface $quoteTaxDetails
     * @param $commonData
     * @return array
     */
    private function getLineItems(
        QuoteDetailsInterface $quoteTaxDetails,
                              $commonData
    ) {
        $lineItems = [];
        $items     = $quoteTaxDetails->getItems();

        if (count($items) > 0) {
            $parentQuantities = [];

            foreach ($items as $item) {
                if ($item->getType() == 'product') {
                    $id                  = $item->getCode();
                    $parentId            = $item->getParentCode();
                    $quantity            = $item->getQuantity();
                    $extensionAttributes = $item->getExtensionAttributes();

                    if ($extensionAttributes->getProductType() == Type::TYPE_BUNDLE) {
                        $parentQuantities[$id] = $quantity;
                        if ($extensionAttributes->getPriceType() == Price::PRICE_TYPE_DYNAMIC) {
                            continue;
                        }
                    }
                    if (isset($parentQuantities[$parentId])) {
                        $quantity *= $parentQuantities[$parentId];
                    }

                    $this->mapping[$item->getCode()] = array_push($lineItems, array_merge($commonData, [
                            'sku'            => (string)$item->getSku(),
                            'amount of sale' => (string)($quantity * $item->getUnitPrice()),
                        ])) - 1;
                }
            }
        }
        return $lineItems;
    }

    /**
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @return array
     */
    private function getCommonData(
        Quote                       $quote,
        ShippingAssignmentInterface $shippingAssignment)
    {
        $address          = $shippingAssignment->getShipping()->getAddress();
        $shippingRegionId = $this->scopeConfig->getValue('shipping/origin/region_id',
            ScopeInterface::SCOPE_STORE,
            $quote->getStoreId()
        );

        $postCode = explode('-', $address->getPostcode());
        if (count($postCode) > 1) {
            $zipCode     = $postCode[0];
            $zipCodePlus = $postCode[1];
        } else {
            $zipCode     = $postCode[0];
            $zipCodePlus = "";
        }
        return [
            'date'                 => empty($quote->getUpdatedAt()) ? date('Ymd') : DateTime::createFromFormat('Y-m-d H:i:s', $quote->getUpdatedAt())->format('Ymd'),
            'record number'        => (string)rand(111111, 999999),
            'seller id'            => $this->getSellerId(),
            'seller location id'   => '1',//todo
            'seller state'         => trim($this->regionFactory->create()->load($shippingRegionId)->getCode()),
            'delivery method'      => 'N',//todo Y or N
            'customer entity code' => 'T',//todo T or E
            'ship to address'      => trim($address->getData('street')),
            'ship to suite'        => '',//todo
            'ship to city'         => trim($address->getCity()),
            'ship to county'       => trim($address->getCounty()),
            'ship to state'        => trim($address->getRegionCode()),
            'ship to zip code'     => trim($zipCode),
            'ship to zip plus'     => trim($zipCodePlus)
        ];
    }

    /**
     * @param Quote $quote
     * @param QuoteDetailsInterface $quoteTaxDetails
     * @param ShippingAssignmentInterface $shippingAssignment
     * @return array|bool|float|int|mixed|string|null
     */
    public function fetchTax(
        Quote                       $quote,
        QuoteDetailsInterface       $quoteTaxDetails,
        ShippingAssignmentInterface $shippingAssignment)
    {
        $response = false;
        try {
            $commonData = $this->getCommonData($quote, $shippingAssignment);
            $data       = $this->getLineItems($quoteTaxDetails, $commonData);

            if ($this->validateRequest($data)) {
                $url      = $this->getEndpoint(self::ENDPOINT_FETCH_TAX);
                $response = $this->postData($data, $url, true);
                if ($this->isLogEnabled()) {
                    $this->logger->debug("--- fetchTax ---");
                    $this->logger->debug("fetchTax Request: " . $this->json->serialize($data));
                    $this->logger->debug("fetchTax Response: " . $this->json->serialize($response));
                }
                if (!$this->validateResponse($response, $data)) {
                    $this->logger->debug("INVALIDATE RESPONSE");
                    $response = false;
                }
            } else throw new Exception('INVALID REQUEST');
        } catch (Exception $exception) {
            $this->logger->error("--- fetchTax EXCEPTION---");
            $this->logger->error("fetchTax Request: " . $this->json->serialize($data));
            $this->logger->error("Exception: " . $exception->getMessage());
        }
        $this->logger->error("--- fetchTax end ---");
        $this->response = $response;
        return $response;
    }

    /**
     * @param $response
     * @param $data
     * @return bool
     */
    private function validateResponse($response, $data)
    {
        $c = count($data);

        for ($i = 0; $i < $c; $i++) {
            $diff = array_diff_assoc($data[$i], $response[$i]);
            if (!empty($diff)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param $data
     * @return bool
     */
    private function validateRequest($data)
    {
        $reqValid     = true;
        $fieldCounter = 0;

        $fields = array('date', 'record number', 'seller id', 'seller location id', 'seller state', 'delivery method', 'customer entity code', 'ship to address', 'ship to suite', 'ship to city', 'ship to county', 'ship to state', 'ship to zip code', 'sku', 'amount of sale');

        foreach ($data as $item) {
            #Validates all expected fields are present in the request and values are correct
            foreach ($item as $key => $value) {
                if (in_array($key, $fields)) {
                    $fieldCounter++;

                    switch ($key) {
                        case "date":
                        {
                            if (empty($item["date"]) || !is_numeric($item["date"])) {
                                $reqValid = false;
                            }
                        }
                        case "record number":
                        {
                            if (empty($item["record number"]) || !is_numeric($item["record number"])) {
                                $reqValid = false;
                            }
                        }
                        case "seller id":
                        {
                            if (empty($item["seller id"]) || !ctype_alnum(trim(str_replace(' ', '', $item["seller id"])))) {
                                $reqValid = false;
                            }
                        }
                        case "seller location id":
                        {
                            if (!empty($item["seller location id"]) && !ctype_alnum(trim(str_replace(' ', '', $item["seller location id"])))) {
                                $reqValid = false;
                            }
                        }
                        case "delivery method":
                        {
                            if (!empty($item["delivery method"]) && ($item["delivery method"] != "Y" && $item["delivery method"] != "N")) {
                                $reqValid = false;
                            }
                        }
                        case "seller state":
                        {
                            if (!empty($item["seller state"]) && (!is_string($item["seller state"]))) {
                                $reqValid = false;
                            }
                        }
                        case "customer entity code":
                        {
                            if (empty($item["customer entity code"]) || ($item["customer entity code"] != "T" && $item["customer entity code"] != "E")) {
                                $reqValid = false;
                            }
                        }
                        case "ship to address":
                        {
                            if (empty($item["ship to address"]) || !ctype_alnum(trim(str_replace(' ', '', $item["ship to address"])))) {
                                $reqValid = false;
                            }
                        }
                        case "ship to city":
                        {
                            if (empty($item["ship to city"]) || !is_string($item["ship to city"])) {
                                $reqValid = false;
                            }
                        }
                        case "ship to county":
                        {
                            if (!empty($item["ship to county"]) && !is_string($item["ship to county"])) {
                                $reqValid = false;
                            }
                        }
                        case "ship to state":
                        {
                            if (empty($item["ship to state"]) || !is_string($item["ship to state"])) {
                                $reqValid = false;
                            }
                        }
                        case "ship to zip code":
                        {
                            if (empty($item["ship to zip code"]) || !is_numeric($item["ship to zip code"])) {
                                $reqValid = false;
                            }
                        }
                        case "sku":
                        {
                            if (empty($item["sku"])) {
                                $reqValid = false;
                            }
                        }
                        case "amount of sale":
                        {
                            if (empty($item["amount of sale"]) || !is_numeric($item["amount of sale"])) {
                                $reqValid = false;
                            }
                        }
                    }
                }
            }
        }
        if ($fieldCounter == 16) {
            $reqValid = false;
        }

        #Return true or false
        return $reqValid;
    }

    /**
     * @param $id
     * @return mixed|void
     */
    public function getResponseLineItem($id)
    {
        if (($this->response)) {
            if (isset($this->mapping[$id])) {
                $item = $this->response[$this->mapping[$id]];
                return $item;

            }
        }
    }

    /**
     * @return array
     */
    public function getResponseShipping()
    {
        return [];
    }
}
