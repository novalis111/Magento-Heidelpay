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

class Exanto_Heidelpay_Model_Entity_Setup extends Mage_Customer_Model_Entity_Setup
{
    public function getDefaultEntities()
    {
        return array(
            'customer' => array(
                'entity_model'          => 'customer/customer',
                'table'                 => 'customer/entity',
                'increment_model'       => 'eav/entity_increment_numeric',
                'increment_per_store'   => FALSE,
                'attributes' => array(
                    'heidelpay_unique_id' => array(
                        'type'          => 'varchar',
                        'label'         => 'Heidelpay Unique ID',
                        'required'      => FALSE,
                        'sort_order'    => 300,
                        ),
                    'heidelpay_payment_type' => array(
                        'type'          => 'varchar',
                        'label'         => 'Heidelpay Payment Type',
                        'required'      => FALSE,
                        'sort_order'    => 310,
                        ),
                    ),
                ),
            'order' => array(
                'entity_model'          => 'sales/order',
                'table'                 => 'sales/order',
                'increment_model'       => 'eav/entity_increment_numeric',
                'increment_per_store'   => TRUE,
                'attributes' => array(
                    'heidelpay_unique_id' => array(
                        'type'          => 'varchar',
                        'label'         => 'Heidelpay Unique ID',
                        'required'      => FALSE,
                        'sort_order'    => 300,
                        'visible'       => TRUE,
                        ),
                    ),
                ),
            );
    }
}
