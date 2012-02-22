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

class Exanto_Heidelpay_Block_Success extends Mage_Core_Block_Template
{
    protected function _toHtml()
    {
    	$successUrl = Mage::getUrl('*/*/success');
    	
        $html	= '<html>'
        		. '<meta http-equiv="refresh" content="0; URL='.$successUrl.'">'
        		. '<body>'
        		. '<p>' . $this->__('Your payment has been successfully processed by our shop system.') . '</p>'
        		. '<p>' . $this->__('Please click <a href="%s">here</a> if you are not redirected automatically.', $successUrl) . '</p>'
        		. '</body></html>';

        return $html;
    }
}