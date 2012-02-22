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

class Exanto_Heidelpay_Model_Shared extends Mage_Payment_Model_Method_Abstract
{

    /**
     * Eindeutiger interner Bezeichner für diese Bezahlmethode
     *
     * @var string [a-z0-9_]
     */
    protected $_code = 'heidelpay_shared';
    protected $_order;

    /**
     * Einige Umgebungsvariablen für die XML Schnittstelle
     */
    protected $_xmlTestUrl	= 'https://test.ctpe.io/payment/ctpe';
    protected $_xmlLiveUrl	= 'https://ctpe.io/payment/ctpe';

    protected $transaction_response = "SYNC";
    protected $user_agent           = "php ctpepost";
    protected $request_version      = "1.0";

    /**
     * Test- und Live-URL für alle Bezahlmethoden
     */
    protected $_testUrl	= 'https://test.ctpe.net/frontend/payment.prc';
    protected $_liveUrl	= 'https://ctpe.net/frontend/payment.prc';
    /* HPC
    protected $_testUrl	= 'https://test-heidelpay.hpcgw.net/sgw/gtw';
    protected $_liveUrl	= 'https://heidelpay.hpcgw.net/sgw/gtw';
    */

    /**
     * Es folgen einige Flags, die die Funktionalitäten dieses Moduls für das
     * Front- und Backend von Magento bestimmen
     *
     * @see alle Flags und ihre Standardwerte in Mage_Payment_Model_Method_Abstract
     *
     * Es ist möglich, eine angepasste dynamische Logik durch Überladen der
     * public function can* für jede Flag zu definieren
     */

    /**
     * Ist diese Bezahlmethode ein Gateway (online auth/charge) ?
     */
    protected $_isGateway               = false;

    /**
     * Kann sie online authorisieren?
     */
    protected $_canAuthorize            = true;

    /**
     * Kann sie online Zahlungen erfassen?
     */
    protected $_canCapture              = true;

    /**
     * Kann sie partielle Beträge online erfassen?
     */
    protected $_canCapturePartial       = false;

    /**
     * Kann sie Rückerstattungen online durchführen?
     */
    protected $_canRefund               = false;

    /**
     * Kann sie Transaktionen online stornieren?
     */
    protected $_canVoid                 = false;

    /**
     * Kann man diese Methode im Administrationsbereich benutzen?
     */
    protected $_canUseInternal          = false;

    /**
     * Kann diese Methode als Option auf der Bezahlseite im Bestellvorgang angezeigt werden?
     */
    protected $_canUseCheckout          = true;

    /**
     * Ist sie passend für Bestellvorgänge mit verschiedenen Versand/Rechnungsadressen?
     */
    protected $_canUseForMultishipping  = true;

    /**
     * Kann sie Kreditkartendaten für spätere Bearbeitung speichern?
     */
    protected $_canSaveCc               = false;

    /**
     * Get order model
     *
     * @return Mage_Sales_Model_Order
     */
    public function getOrder()
    {
        if (!$this->_order) {
            $paymentInfo = $this->getInfoInstance();
            $this->_order = Mage::getModel('sales/order')
                            ->loadByIncrementId($paymentInfo->getOrder()->getRealOrderId());
        }
        return $this->_order;
    }

    public function getOrderPlaceRedirectUrl()
    {
          return Mage::getUrl('heidelpay/process');
    }

    public function capture(Varien_Object $payment, $amount)
    {
        $payment->setStatus(self::STATUS_APPROVED)
            ->setLastTransId($this->getTransactionId());
        return $this;
    }

    public function cancel(Varien_Object $payment)
    {
        $payment->setStatus(self::STATUS_DECLINED)
            ->setLastTransId($this->getTransactionId());
        return $this;
    }

    /**
     * Return redirect block type
     *
     * @return string
     */
    public function getRedirectBlockType()
    {
        return $this->_redirectBlockType;
    }

    /**
     * prepare params array to send it to gateway page via POST
     *
     * @return array
     */
    public function getWpfUrl()
    {
        // set transaction ID for order process
        $this->getOrder()->getPayment()->getMethodInstance()->setTransactionId($this->getOrder()->getRealOrderId());

        // prepare data
        $amount		= number_format($this->getOrder()->getBaseGrandTotal(), 2, '.', '');
        $billing	= $this->getOrder()->getBillingAddress();
        $currency	= $this->getOrder()->getBaseCurrencyCode();
        $street		= $billing->getStreet();
        $locale     = explode('_', Mage::app()->getLocale()->getLocaleCode());
        $methods    = array('CC', 'DD', 'DC', 'OT', 'IV', 'PP', 'UA', 'VA');
        $valid      = array('CC', 'DD', 'DC', 'OT'); // valid payment methods
        if ( ! in_array($this->getConfigData('hp_mode'), $valid)) {
            throw new Exception('Invalid Payment Mode given');
        }
        if (is_array($locale) && ! empty($locale)) {
            $locale = $locale[0];
        } else {
            $locale = $this->getDefaultLocale();
        }

        $parameters = array();
        // connection parameters
        $parameters['SECURITY.SENDER']      = $this->getConfigData('hp_sender');
        $parameters['USER.LOGIN']           = $this->getConfigData('hp_user_login');
        $parameters['USER.PWD']             = $this->getConfigData('hp_user_password');
        $parameters['TRANSACTION.CHANNEL']  = $this->getConfigData('hp_channel');
        $parameters['TRANSACTION.MODE']     = $this->getTransactionMode();
        $parameters['REQUEST.VERSION']      = $this->request_version;
        // data
        $parameters['IDENTIFICATION.TRANSACTIONID'] = $this->getOrder()->getPayment()->getMethodInstance()->getTransactionId();

        // are we asked for registration, debit or reservation?
        // Note: RG only makes sense for real customers with an account
        if ($this->getConfigData('hp_registry') && $this->getOrder()->getCustomerId()) {
            // register
            $parameters['PAYMENT.CODE'] = $this->getConfigData('hp_mode') . ".RG";
        } else if ($this->getConfigData('hp_preauth') || $this->getConfigData('hp_mode') == 'OT') {
            // preauth
            $parameters['PAYMENT.CODE'] = $this->getConfigData('hp_mode') . ".PA";
        } else {
            // debit
            $parameters['PAYMENT.CODE'] = $this->getConfigData('hp_mode') . ".DB";
        }

        // customer data
        $parameters['NAME.GIVEN']                   = $this->stringForWpf($billing->getFirstname());
        $parameters['NAME.FAMILY']                  = $this->stringForWpf($billing->getLastname());
        $parameters['ADDRESS.STREET']               = $this->stringForWpf($street[0]);
        $parameters['ADDRESS.ZIP']                  = $billing->getPostcode();
        $parameters['ADDRESS.CITY']                 = $this->stringForWpf($billing->getCity());
        $parameters['ADDRESS.COUNTRY']              = $billing->getCountry();
        $parameters['ACCOUNT.COUNTRY']              = $billing->getCountry(); // for DD
        $parameters['CONTACT.EMAIL']                = $this->getOrder()->getCustomerEmail();
        $parameters['CONTACT.IP']                   = $this->getOrder()->getRemoteIp();
        $parameters['PRESENTATION.AMOUNT']          = $amount;
        $parameters['PRESENTATION.CURRENCY']        = $currency;
        // frontend options (restrict payment methods etc)
        $parameters['FRONTEND.ENABLED']             = "true";
        $parameters['FRONTEND.POPUP']               = "false";
        $parameters['FRONTEND.MODE']                = "DEFAULT";
        $parameters['FRONTEND.LANGUAGE']            = $locale;
        //$parameters['FRONTEND.PM.DEFAULT_DISABLE_ALL']  = "true";
        // fill parameter for each payment method
        foreach ($methods as $key => $method) {
            $parameters['FRONTEND.PM.'.($key+1).'.METHOD'] = $method;
            if ($this->getConfigData('hp_mode') == $method) {
                $parameters['FRONTEND.PM.'.($key+1).'.ENABLED'] = "true";
            } else {
                $parameters['FRONTEND.PM.'.($key+1).'.ENABLED'] = "false";
            }
        }
        $parameters['FRONTEND.REDIRECT_TIME']       = 0;
        $parameters['FRONTEND.NEXTTARGET']          = "top.location.href";
        $parameters['FRONTEND.ONEPAGE']             = "true";
        $parameters['FRONTEND.HEIGHT']              = 450; // Pixel
        $parameters['FRONTEND.CSS_PATH']            = Mage::getModel('Mage_Core_Model_Design_Package')->getSkinUrl("css/heidelpay_wpf.css");
        $parameters['FRONTEND.JSCRIPT_PATH']        = Mage::getModel('Mage_Core_Model_Design_Package')->getSkinUrl("js/heidelpay_wpf.js");
        $parameters['FRONTEND.RESPONSE_URL']        = Mage::helper('core/url')->getHomeUrl() . 'heidelpay/process/response';

        // :DEBUG:
        //$parameters['TEST_URL'] = Mage::helper('checkout/url')->getCheckoutUrl();
        //echo "<pre>"; print_r($parameters); echo "</pre>";die;

        // build the postparameter string to send into the WPF
        $result = '';
        foreach (array_keys($parameters) as $key) {
            $$key = '';
        }
        foreach (array_keys($parameters) as $key) {
            $$key   .= $parameters[$key];
            $$key    = urlencode($$key);
            $$key   .= "&";
            $var     = strtoupper($key);
            $value   = $$key;
            $result .= "$var=$value";
        }
        $strPOST = stripslashes($result);

        // open the request url for the Web Payment Frontend
        $cpt = curl_init();
        curl_setopt($cpt, CURLOPT_URL, $this->getUrl());
        curl_setopt($cpt, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($cpt, CURLOPT_USERAGENT, $this->user_agent);
        curl_setopt($cpt, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($cpt, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($cpt, CURLOPT_POST, 1);
        curl_setopt($cpt, CURLOPT_POSTFIELDS, $strPOST);

        $curlresultURL  = curl_exec($cpt);
        $curlerror      = curl_error($cpt);
        $curlinfo       = curl_getinfo($cpt);
        curl_close($cpt);

        // here you can get all variables returned from the ctpe server (see post integration transactions documentation for help)
        // :DEBUG: print "$curlresultURL";die;

        // parse results
        $r_arr = explode("&", $curlresultURL);

        foreach($r_arr as $buf) {
            $temp    = urldecode($buf);
            $temp    = explode("=", $temp, 2);
            if ( ! isset($temp[0]) || ! isset($temp[1])) {
                continue;
            }
            $postatt = $temp[0];
            $postvar = $temp[1];
            $returnvalue[$postatt] = $postvar;
            // :DEBUG: print "<br>var: $postatt - value: $postvar<br>";
        }

        // :DEBUG: echo "<pre>"; print_r($returnvalue); echo "</pre>";die;

        $processingresult = isset($returnvalue['POST.VALIDATION'])? $returnvalue['POST.VALIDATION'] : false;
        $redirectURL = isset($returnvalue['FRONTEND.REDIRECT_URL'])? $returnvalue['FRONTEND.REDIRECT_URL'] : false;

        // everything ok? redirect to the WPF
        if ($processingresult == "ACK") {
            if (strpos($redirectURL, "http") !== false) {
                // redirect url is returned ==> everything ok
                // header("Location: $redirectURL");
                return $redirectURL;
            } else {
                // error-code is returned... redirect to error page (customize if needed)
                header("Location: " . Mage::helper('core/url')->getHomeUrl() . 'heidelpay/process/failure');
            }
        } else {
            // there is a connection problem with the ctpe server... redirect to error page (customize if needed)
            header("Location: " . Mage::helper('core/url')->getHomeUrl() . 'heidelpay/process/failure');
        }
    }

    /**
     * Performs Debit on Registration
     *
     * @param array data
     */
    public function debitOnRegistration($data)
    {
        if ($this->getConfigData('hp_preauth')) {
            // perform Preauthorisation
            $action = '.PA';
        } else {
            // perform debit
            $action = '.DB';
        }
        $strXML  = '<?xml version="1.0" encoding="UTF-8"?>';
        $strXML .= '<Request version="' . $this->request_version . '">';
        $strXML .= '<Header>';
        $strXML .= '  <Security sender="' . $this->getConfigData('hp_sender') . '" token="token" />';
        $strXML .= '</Header>';
        $strXML .= '<Transaction mode="' . $this->getTransactionMode() . '" response="' . $this->transaction_response . '" channel="' . $this->getConfigData('hp_channel') . '">';
        $strXML .= '  <User login="' . $this->getConfigData('hp_user_login') . '" pwd="' . $this->getConfigData('hp_user_password') . '"/>';
        $strXML .= '  <Identification>';
        $strXML .= '    <TransactionID>' . $data['IDENTIFICATION_TRANSACTIONID'] . '</TransactionID>';
        $strXML .= '  </Identification>';
        $strXML .= '  <Payment code="' . $this->getConfigData('hp_mode') . $action . '">';
        $strXML .= '    <Presentation>';
        $strXML .= '      <Amount>' . $data['PRESENTATION_AMOUNT'] . '</Amount>';
        $strXML .= '      <Currency>' . $data['PRESENTATION_CURRENCY'] . '</Currency>';
        $strXML .= '      <Usage>Payment for Transaction ID ' . $data['IDENTIFICATION_TRANSACTIONID'] . '</Usage>';
        $strXML .= '    </Presentation>';
        $strXML .= '  </Payment>';
        $strXML .= '  <Account registration="' . $data['IDENTIFICATION_UNIQUEID'] . '"/><!-- UniqueId of the previous RG response -->';
        $strXML .= '</Transaction>';
        $strXML .= '</Request>';

        // perform send
        $this->sendToCTPE($this->getXmlUrl(), $strXML);
        return $this->xmlResultUrl;
    }

    /**
     * Sends XML message to ctpe server
     */
    protected function sendToCTPE($url, $data) {
        $cpt = curl_init();

        $xmlpost = "load=" . urlencode($data);
        curl_setopt($cpt, CURLOPT_URL, $url);
        curl_setopt($cpt, CURLOPT_SSL_VERIFYHOST, 1);
        curl_setopt($cpt, CURLOPT_USERAGENT, $this->user_agent);
        curl_setopt($cpt, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($cpt, CURLOPT_SSL_VERIFYPEER, FALSE);
        //curl_setopt($cpt, CURLOPT_SSL_VERIFYPEER, 1);

        curl_setopt($cpt, CURLOPT_POST, 1);
        curl_setopt($cpt, CURLOPT_POSTFIELDS, $xmlpost);

        $this->xmlResultUrl = curl_exec($cpt);
        $this->xmlError     = curl_error($cpt);
        $this->xmlInfo      = curl_getinfo($cpt);

        curl_close($cpt);
    }

    /**
     * Return payment method type string
     *
     * @return string
     */
    protected function getPaymentMethodType()
    {
        return $this->_paymentMethod;
    }

    protected function getUrl()
    {
        if ( ! $this->getConfigData('hp_testmode')) {
            return $this->_liveUrl;
        }
        return $this->_testUrl;
    }

    protected function getXmlUrl()
    {
        if ( ! $this->getConfigData('hp_testmode')) {
            return $this->_xmlLiveUrl;
        }
        return $this->_xmlTestUrl;
    }

    protected function getTransactionMode()
    {
        if ( ! $this->getConfigData('hp_testmode')) {
            return 'LIVE';
        }
        return 'INTEGRATOR_TEST';
    }

    protected function stringForWpf($string)
    {
        return utf8_decode($string);
        // return Mage::helper('core')->removeAccents($string);
    }
}