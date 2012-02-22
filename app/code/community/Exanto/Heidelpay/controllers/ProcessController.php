<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category   Exanto
 * @package    Exanto_Heidelpay
 * @copyright  Copyright (c) 2008 Irubin Consulting Inc. DBA Varien (http://www.varien.com)
 * @copyright  Copyright (c) 2008 Phoenix Medien GmbH & Co. KG (http://www.phoenix-medien.de)
 * @copyright  Copyright (c) 2009 eXanto Internet Solutions (http://www.exanto.de)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Exanto_Heidelpay_ProcessController extends Mage_Core_Controller_Front_Action
{
    protected $_sendNewOrderEmail   = TRUE;
    protected $_order               = NULL;
    protected $_paymentInst         = NULL;

    /**
     * Calls the <object> with WPF
     */
    public function indexAction()
    {
        $session = $this->getCheckout();
        $order   = Mage::getModel('sales/order');
        $order->loadByIncrementId($session->getLastRealOrderId());
        // Change status to "on_hold" only if order wasn't updated yet
        if ( ! isset($_SESSION['updated'])) {
            $order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, Mage::helper('heidelpay')->__('Heidelpay WPF was called.'));
        }
        $order->save();

        // restock
        if ( ! isset($_SESSION['updated']) || $_SESSION['updated'] != $session->getLastRealOrderId()) {
            $items = $order->getAllItems();
            if ($items) {
                foreach($items as $item) {
                    $quantity   = $item->getQtyOrdered();
                    $product_id = $item->getProductId();
                    // load stock for product
                    $stock      = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product_id);
                    // set to old qty
                    $stock->setQty($stock->getQty() + $quantity);
                    $stock->save();
                }
            }
            $_SESSION['updated'] = $session->getLastRealOrderId();
        }

        $payment = $order->getPayment()->getMethodInstance();
        $this->loadLayout();
        $this->getLayout()->getBlock('heidelpay_index')->setWpfUrl($payment->getWpfUrl());
        $this->renderLayout();
    }

    public function testAction()
    {
        // call http://your-url.com/heidelpay/process/test/
        /* :DEBUG: *
        $order   = Mage::getModel('sales/order');
        $order->loadByIncrementId(100000001);
        $payment = $order->getPayment()->getMethodInstance();
        $object  = $payment;
        // $object  = $this;
        echo "<h1>Klassenmethoden</h1><pre>"; print_r(get_class_methods($object)); echo "</pre>";
        echo "<h1>Klasse</h1><pre>"; echo get_class($object); echo "</pre>";
        echo "<h1>Oeffentliche Daten des Objekts</h1><pre>"; print_r(get_object_vars($object)); echo "</pre>";
        echo "<h1>Elternklasse</h1><pre>"; echo get_parent_class($object); echo "</pre>";
        echo "<h1>Alle definierten Klassen</h1><pre>"; print_r(get_declared_classes()); echo "</pre>";
        die;
        /* */
        $this->_redirect('checkout/onepage/failure');
    }

    /**
     * Get singleton of Checkout Session Model
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Heidelpay returns POST variables to this action
     */
    public function responseAction()
    {
        try {
            $request = $this->_checkReturnedPost();

            // log some extra info if we are in testmode
            // use the following to not log during RG
            // if ($this->_paymentInst->getConfigData('hp_testmode') && strpos($request['PAYMENT_CODE'], 'RG') === False) {
            if ($this->_paymentInst->getConfigData('hp_testmode')) {
                $this->_order->addStatusToHistory($this->_paymentInst->getConfigData('order_status'), 'Full POST Response: ' . var_export($request, True));
            }

            // check if respond is from a register action, and if so, perform
            // debit/preauth with unique id from this response
            if (strpos($request['PAYMENT_CODE'], 'RG') !== False) {
                // save information to customer account for later retrieval
                $this->saveRequestInformation($request);
                // perform debit/preauth through XML Request
                $resultUrl = $this->_paymentInst->debitOnRegistration($request);
                // log some extra info if we are in testmode
                if ($this->_paymentInst->getConfigData('hp_testmode')) {
                    $this->_order->addStatusToHistory($this->_paymentInst->getConfigData('order_status'), 'Customer registered with UniqueID: ' . $request['IDENTIFICATION_UNIQUEID']);
                    $this->_order->addStatusToHistory($this->_paymentInst->getConfigData('order_status'), 'Full XML Response: ' . var_export($resultUrl, True));
                }
                // check XML response (adds UniqueID from debit/preauth to order object on success)
                $this->_checkReturnedXml($resultUrl);
            }

            // set payment transaction id
            $this->_paymentInst->setTransactionId($request['IDENTIFICATION_TRANSACTIONID']);

            // fill order
            if ($this->_order->canInvoice()) {
                $convertor  = Mage::getModel('sales/convert_order');
                $invoice    = $convertor->toInvoice($this->_order);
                foreach ($this->_order->getAllItems() as $orderItem) {
                    if (!$orderItem->getQtyToInvoice()) {
                        continue;
                    }
                    $item = $convertor->itemToInvoiceItem($orderItem);
                    $item->setQty($orderItem->getQtyToInvoice());
                    $invoice->addItem($item);
                }

                $invoice->collectTotals();
                $invoice->register()->capture();
                Mage::getModel('core/resource_transaction')
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder())
                    ->save();
            }

            // log some extra info if we are in testmode
            if ($this->_paymentInst->getConfigData('hp_testmode')) {
                $this->_order->addStatusToHistory($this->_paymentInst->getConfigData('order_status'), Mage::helper('heidelpay')->__('Customer returned successfully'));
            }

            $this->_order->addStatusToHistory($this->_paymentInst->getConfigData('order_status'), $request['PROCESSING_RETURN'] . " / " . $request['PROCESSING_TIMESTAMP']);
            $this->_order->save();

            // Heidelpay redirect URL (plaintext)
            echo Mage::helper('core/url')->getHomeUrl() . "heidelpay/process/success";

        } catch (Exception $e) {

            // Log failure
            $this->_order->addStatusToHistory(Mage_Sales_Model_Order::STATE_CANCELED, "Error " . $e->getCode() . " during payment process: " . $e->getMessage());
            $this->_order->save();

            // Heidelpay redirect URL (plaintext)
            echo Mage::helper('core/url')->getHomeUrl() . "heidelpay/process/failure";
        }
    }

    /**
     * Heidelpay return action success
     */
    protected function successAction()
    {
        $session = $this->getCheckout();
        $order   = Mage::getModel('sales/order');
        $order->load($this->getCheckout()->getLastOrderId());

        // stock deduction in case of success
        $items = $order->getAllItems();
        if ($items) {
            foreach($items as $item) {
                $quantity   = $item->getQtyOrdered();
                $product_id = $item->getProductId();
                // load stock for product
                $stock      = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product_id);
                // set new qty after payment
                $stock->setQty($stock->getQty() - $quantity);
                $stock->save();
            }
        }

        // and away we go
        if ($order->getId() && $this->_sendNewOrderEmail) {
            $order->sendNewOrderEmail();
        }
        $this->_redirect('checkout/onepage/success');
    }

    /**
     * Heidelpay return action failure
     */
    protected function failureAction()
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    /**
     * Checking POST variables.
     * Creating invoice if payment was successfull or cancel order if payment was declined
     */
    protected function _checkReturnedPost()
    {
        // check request type
        if ( ! $this->getRequest()->isPost()) {
            throw new Exception('Wrong request type.', 10);
        }

        // get request variables
        $request = $this->getRequest()->getPost();
        if (empty($request)) {
            throw new Exception('Request doesn\'t contain POST elements.', 20);
        }

        Mage::log('Request: ' . $this->getRequest());
        // :TODO: validate request IP and DNS entry
        // throw new Exception('IP can\'t be validated as Heidelpay IP.', 30);

        // check order (transaction) id
        if (empty($request['IDENTIFICATION_TRANSACTIONID']) || strlen($request['IDENTIFICATION_TRANSACTIONID']) > 15) {
            throw new Exception('Missing or invalid Transaction ID', 40);
        }

        // load order for further validation
        $this->_order = Mage::getModel('sales/order')->loadByIncrementId($request['IDENTIFICATION_TRANSACTIONID']);
        $this->_paymentInst = $this->_order->getPayment()->getMethodInstance();

        // check transaction status
        if ( ! empty($request['PROCESSING_RESULT']) && $request['PROCESSING_RESULT'] != 'ACK') {
            throw new Exception('Transaction was not successfull.', 50);
        }

        // check transaction currency
        if ($this->_order->getBaseCurrencyCode() != $request['PRESENTATION_CURRENCY']) {
            throw new Exception('Transaction currency doesn\'t match.', 60);
        }

        // check transaction amount
        if (number_format($this->_order->getBaseGrandTotal(), 2, '.', '') != $request['PRESENTATION_AMOUNT']) {
            throw new Exception('Transaction Amount doesn\'t match.', 70);
        }

        return $request;
    }

    /**
     * Checking XML Response
     */
    protected function _checkReturnedXml($resultUrl)
    {
        $resultXml  = urldecode($resultUrl);
        $xml_object = simplexml_load_string($resultXml);
        if ($xml_object->Transaction->Processing->Result != 'ACK') {
            throw new Exception("Error during Debit/Preauth On Registration, full Response follows: " . $resultUrl, 0);
        } else {
            $this->_order->addStatusToHistory($this->_paymentInst->getConfigData('order_status'), 'Debit/Preauth On Registration was successful');
            // add UniqueID to order
            $this->_order->setHeidelpayUniqueId($xml_object->Transaction->Identification->UniqueID);
            return True;
        }
    }

    /**
     * Saving some information from the response to customer object
     */
    protected function saveRequestInformation($request)
    {
        // save unique id + payment type to customer account
        if ( ! $this->_order->getCustomerId()) {
            return FALSE;
        }
        $customer = Mage::getModel('customer/customer')->load($this->_order->getCustomerId());
        $customer->setHeidelpayUniqueId($request['IDENTIFICATION_UNIQUEID']);
        $customer->setHeidelpayPaymentType($request['PAYMENT_CODE']);
        $customer->save();
    }
}