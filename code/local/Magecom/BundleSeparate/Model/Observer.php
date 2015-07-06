<?php
/**
 * Add bundle product as simple products
 *
 * @category    Magecom
 * @package     Magecom_BundleSepareate
 * @author      Anastasiya Sukhorukova <asukhorukova@magecom.net>
 */
class Magecom_BundleSeparate_Model_Observer extends Mage_Core_Model_Observer
{
    /**
     * Is we need to remove bundle from cart
     *
     * @var bool
     */
    private $_removeBundleProduct = false;
    /**
     * @var array
     */
    private $_addedProducts = array();
    /**
     * Is we need to apply discount
     *
     * @var bool
     */
    private $_percent = false;
    private $_selectsPrice = array();
    /**
     * Get selected products from bundle and add to cart
     *
     * @param $observer
     *
     * @return $this
     */
    public function bundleSeparate($observer)
    {
        $product = $observer->getProduct();
        if ($product->getTypeId() == 'bundle') {
            $cart = Mage::helper('checkout/cart')->getCart();
            $quoteItem = $observer->getQuoteItem();
            $selectedProducts = $quoteItem->getParentItem()->getQtyOptions();
            $simplesPrice = 0;

            foreach ($selectedProducts as $productId => $selectedProduct) {
                $cart->addProduct($productId, $selectedProduct->getValue());
                $this->_addedProducts[] = $productId;
                $simplesPrice += $selectedProduct->getProduct()->getPrice();
                if (!is_null($product->getSpecialPrice())) {
                    $this->_selectsPrice[$productId] = $selectedProduct->getProduct()->getSelectionPriceValue()*$product->getSpecialPrice()/100;
                } else {
                    $this->_selectsPrice[$productId] = $selectedProduct->getProduct()->getSelectionPriceValue();
                }
            }
            if ($product->getPriceType() == 1 && $simplesPrice > $product->getPrice()) {
                $this->_percent = $product->getPrice()*100/$simplesPrice;
                if (!is_null($product->getSpecialPrice())) {
                    $this->_percent = $this->_percent*$product->getSpecialPrice()/100;
                }
            } elseif (!is_null($product->getSpecialPrice())) {
                $this->_percent = $product->getSpecialPrice();
            }

            $this->_removeBundleProduct = $quoteItem->getId();
            $cart->save();
            $cart->setCartWasUpdated(true);

        }
        return $this;

    }

    /**
     * Remove bundle product from cart and apply discount
     *
     * @param $observer
     *
     * @return $this
     */
    public function removeBundle($observer)
    {
        if ($this->_removeBundleProduct) {
            $items = $observer->getCart()->getItems();
            $session = Mage::getSingleton('checkout/session');
            $discountedProducts = is_null($session->getDiscountedProducts())?array():$session->getDiscountedProducts();
            $discounts = is_null($session->getDiscounts())?array():$session->getDiscounts();
            $duplicates = array();
            foreach ($items as $itemId => $item) {
                if ($item->getProduct()->getTypeId() == 'bundle') {
                    $observer->getCart()->removeItem($itemId);
                    continue;
                } elseif ($this->_percent >= 0 && is_null($item->getParentItemId()) && !in_array($item->getProductId(), $discounts)) {
                    $item->setOriginalCustomPrice(($item->getProduct()->getPrice())*($this->_percent)/100
                        + $this->_selectsPrice[$item->getProductId()]);
                    $discountedProducts[$this->_removeBundleProduct][] = $item->getId();
                    $discounts[] = $item->getProductId();
                }elseif (in_array($item->getProductId(), $discounts)  && in_array($item->getProductId(), $this->_addedProducts)) {
                    $discountedProducts[$this->_removeBundleProduct][] = $item->getId();
                    $duplicates[] = $item->getProductId();
                    $this->_addedProducts = array();
                }
            }

            $session->setDiscountedProducts($discountedProducts);
            $session->setDiscounts($discounts);
            $session->setDuplicates($duplicates);
            $this->_removeBundleProduct = false;
            $this->_percent = false;
        }

        return $this;
    }
    /**
     * Remove discount when one piece of bundle remove (cart)
     *
     * @param $observer
     *
     * @return $this
     */
    public function removeDiscount($observer) {
        $session = Mage::getSingleton('checkout/session');
        $discounts = $session->getDiscounts();
        $duplicates = $session->getDuplicates();
        $quoteItem = $observer->getQuoteItem();

        if ($discounts && in_array($quoteItem->getProductId(), $discounts)) {
            $cart = Mage::helper('checkout/cart')->getCart();
            $discountedProducts = $session->getDiscountedProducts();
            $removeKey = $discounts = array();
            foreach($discountedProducts as $key => $product) {
                if (in_array($quoteItem->getItemId(), $product)) {
                    foreach ($product as $itemProduct) {
                        $item = $cart->getQuote()->getItemById($itemProduct);
                        if (!is_null($item) && !in_array($item->getProductId(), $duplicates)) {
                                $item->setOriginalCustomPrice($item->getProduct()->getPrice());
                        } elseif (in_array($item->getProductId(), $duplicates)) {
                            unset($duplicates[array_search($item->getProductId(),$duplicates)]);
                        }
                    }

                    $removeKey[] = $key;
                }
            }

            foreach ($removeKey as $key) {
                unset($discountedProducts[$key]);
            }

            foreach ($discountedProducts as $discount) {
                $discounts += $discount;
            }

            $session->setDiscountedProducts($discountedProducts);
            $session->setDiscounts($discounts);
            $session->setDuplicates($duplicates);
        }

        return $this;

    }

    /**
     * Separate bundle for simples in order
     *
     * @param $observer
     * @return $this
     */
    public function separateBundleInOrder ($observer)
    {
        $order = $observer->getOrder();
        $productAdd = $childPrices = $simplePrices = array();
        $discount = 100;
        $bundlePrice = 0;

        $items = $order->getAllItems();
        foreach ($items as $item) {
            if ($item->getProductType() == 'bundle') {
                $bundlePrice = $item->getPrice();
                $item->delete();
            }

            if ($item->getParentItemId() && $item->getParentItem()->getProductType() == 'bundle') {
                $item->unsParentItemId();
                $productAdd[$item->getProductId()] = $item->getProduct();
                $childPrices[$item->getProductId()] = $item->getPrice();
                $simplePrices[$item->getProductId()] = $item->getProduct()->getPrice();
            }

        }
        if (count($productAdd) > 0) {
            if (array_sum($childPrices) != $bundlePrice) {
                $discount = $bundlePrice * 100 / array_sum($simplePrices);
            }
            foreach ($items as $item) {
                if (isset($productAdd[$item->getProductId()])) {
                    $price = $item->getProduct()->getPrice() * $discount / 100;
                    $productOptions =$item->getProductOptions();
                    if (isset($productOptions['bundle_selection_attributes']['price'])
                        && $productOptions['bundle_selection_attributes']['price'] > 0) {
                        $price += $productOptions['bundle_selection_attributes']['price'];
                    }
                    $item
                        ->setPrice($price)
                        ->setOriginalPrice($item->getProduct()->getPrice())
                        ->setRowTotal($price * $item->getQtyOrdered())
                        ->setSubtotal($price * $item->getQtyOrdered())
                        ->setOrder($order)
                        ->save();
                }
            }
        }
        return $this;
    }
}