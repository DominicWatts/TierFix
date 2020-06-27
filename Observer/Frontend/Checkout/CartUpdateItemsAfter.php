<?php

declare(strict_types=1);

namespace Xigen\Discount\Observer\Frontend\Checkout;

// phpcs:disable Generic.Metrics.NestingLevel

use Magento\Catalog\Model\Product as Model;
use Magento\Catalog\Model\Product\Type;
use Magento\CatalogRule\Helper\Data;
use Magento\CatalogRule\Model\Product\PriceModifier;
use Magento\CatalogRule\Model\ResourceModel\Rule;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;

class CartUpdateItemsAfter implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $product;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface
     */
    protected $priceCurrencyInterface;

    /**
     * @var PriceModifier
     */
    protected $priceModifier;

    /**
     * @var Rule
     */
    protected $rule;

    /**
     * @var TimezoneInterface
     */
    protected $_localeDate;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var \Magento\CatalogRule\Helper\Data
     */
    protected $_catalogRuleData;

    /**
     * CartUpdateItemsAfter constructor.
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Catalog\Model\ProductFactory $product
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrencyInterface
     * @param PriceModifier $priceModifier
     * @param Rule $rule
     * @param Data $catalogRuleData
     * @param TimezoneInterface $localeDate
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Magento\Catalog\Model\ProductFactory $product,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrencyInterface,
        PriceModifier $priceModifier,
        Rule $rule,
        Data $catalogRuleData,
        TimezoneInterface $localeDate,
        StoreManagerInterface $storeManager
    ) {
        $this->logger = $logger;
        $this->product = $product;
        $this->customerSession = $customerSession;
        $this->registry = $registry;
        $this->priceCurrencyInterface = $priceCurrencyInterface;
        $this->priceModifier = $priceModifier;
        $this->rule = $rule;
        $this->_localeDate = $localeDate;
        $this->_storeManager = $storeManager;
        $this->_catalogRuleData = $catalogRuleData;
    }

    /**
     * Execute observer
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(
        \Magento\Framework\Event\Observer $observer
    ) {
        $cart = $observer->getEvent()->getCart();
        if (!$cart) {
            return;
        }

        $quote = $cart->getData('quote');

        if (!$quote) {
            return;
        }

        $collection = $quote->getAllVisibleItems();
        foreach ($collection as $item) {
            // $this->logger->critical('CartUpdateItemsAfter ' . (int) $item->getQty());
            if ($item->getProduct()->getTypeId() == Configurable::TYPE_CODE) {
                if ($children = $item->getChildren()) {
                    foreach ($children as $child) {
                        if ($child->getProduct()->getTypeId() != Type::TYPE_SIMPLE) {
                            continue;
                        }
                        // $this->logger->critical($child->getProduct()->getId());
                        $loaded = $this->getById($child->getProduct()->getId());
                        $tierPrices = $this->getTierPrice($loaded);
                        if (empty($tierPrices)) {
                            continue;
                        }
                        foreach ($tierPrices as $tierPrice) {
                            if (isset($tierPrice['discount_price']) &&
                                $tierPrice['discount_price'] > 0 &&
                                isset($tierPrice['price_qty'])) {
                                if ((int) $tierPrice['price_qty'] > (int) $item->getQty()) {
                                    continue;
                                }
                                // $this->logger->critical('Requested ' . (int) $item->getQty());
                                // $this->logger->critical('Tier ' . (int) $tierPrice['price_qty']);
                                // $this->logger->critical($tierPrice['discount_price']);
                                $item->setCustomPrice($tierPrice['discount_price']);
                                $item->setOriginalCustomPrice($tierPrice['discount_price']);
                                $item->getProduct()->setIsSuperMode(true);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Get product by Id. Loading via product repository throws error!?
     * @param int $productId
     * @return \Magento\Catalog\Model\Data\Product
     */
    public function getById($productId)
    {
        try {
            return $this->product->create()->load($productId);
        } catch (\Exception $e) {
            $this->logger->critical($e);
            return false;
        }
    }

    /**
     * get customer group ID
     * @return int
     */
    public function getCustomerGroupId()
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->customerSession->getCustomer()->getGroupId();
        }
        return null;
    }

    /**
     * Get tier price
     * @return array
     */
    public function getTierPrice($product)
    {
        $array = [];
        $tierPrices = $product->getTierPrice();
        if (count($tierPrices) > 2) {
            foreach ($tierPrices as $tierPrice) {
                $tierPrice['discount_price'] = $this->getRuleByProduct($product, $tierPrice['website_price']);
                if ($tierPrice['all_groups'] == 1 || $tierPrice['cust_group'] == $this->getCustomerGroupId()) {
                    $tierPrice['pack'] = $product->getAttPackqty();
                    $array[] = $tierPrice;
                }
            }
            return $array;
        }
        return [];
    }

    /**
     * Get rules from product
     * @param string $dateTs
     * @param int $websiteId
     * @param array $customerGroupId
     * @param int $productId
     * @return array
     */
    protected function _getRulesFromProduct($dateTs, $websiteId, $customerGroupId, $productId)
    {
        return $this->rule->getRulesFromProduct($dateTs, $websiteId, $customerGroupId, $productId);
    }

    /**
     * Calculate price using catalog price rule of product
     * @param Product $product
     * @param float $price
     * @return float|null
     */
    public function getRuleByProduct(Model $product, $price)
    {
        $priceRules = null;
        $productId = $product->getId();
        $storeId = $product->getStoreId();
        $dateTs = $this->_localeDate->scopeTimeStamp($storeId);
        $websiteId = $this->_storeManager->getStore($storeId)->getWebsiteId();

        if ($product->hasCustomerGroupId()) {
            $customerGroupId = $product->getCustomerGroupId();
        } else {
            $customerGroupId = $this->customerSession->getCustomerGroupId();
        }
        $currentDateTime = new \DateTime();
        $rulesData = $this->_getRulesFromProduct($dateTs, $websiteId, $customerGroupId, $productId);
        if ($rulesData) {
            foreach ($rulesData as $ruleData) {
                $priceRules = $this->_catalogRuleData->calcPriceRule(
                    $ruleData['action_operator'],
                    $ruleData['action_amount'],
                    $price
                );
                if ($ruleData['action_stop']) {
                    break;
                }
            }
            return $priceRules;
        }
        return null;
    }
}
