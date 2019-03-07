<?php
    namespace Dplus\Import\Orders\BigCommerce;
    
    /**
     * Import Big Commerce Library
     */
    use Bigcommerce\Api\Client as BigCommerce;
    use Bigcommerce\Api\Resources\Order as BigCommerceOrder;
    use Bigcommerce\Api\Resources\Address as BigCommerceAddress;
    use Bigcommerce\Api\Resources\OrderProduct as BigCommerceOrderProduct;

    /**
     * Import Dplus Libraries and Model Classes
     */
    use Dplus\Base\ThrowErrorTrait;
    use SalesOrderEdit;
    use SalesOrderDetail;

    /**
     * Immport classes / libs from same package
     */
    use Dplus\Import\Orders\OrderImporter;
    use Dplus\Import\Orders\ObjectMapper;

    class OrdersAPI extends BaseAPI implements OrderImporter {
        use ObjectMapper;

        /**
         * Payment Types and the Type they Map to in the SalesOrderEdit File
         * @var array
         */
        protected $payment_types = array(
            'default'                                       => 'cc',
            'paypal'                                        => 'payp',
            'Visa, Mastercard,  American Express, Discover' => 'cc'
        );

        protected $structure = array(
            'billing' => array( // This Maps SalesOrderEdit to BigCommerce\Api\Resources\Order
                'orderno'      => array('field' => 'id'),
                'sessionid'    => array('field' => 'id'),
                'recno'        => array('field' => 'id'),
                'custid'       => array('field' => 'customer_id'),
                'orderdate'    => array('field' => 'date_created', 'format' => 'date', 'date-format' => 'Ymd'),
                'shipdate'     => array('field' => 'date_shipped', 'format' => 'date', 'date-format' => 'Ymd'),
                'subtotal'     => array('field' => 'subtotal_ex_tax', 'format' => 'currency'),
                'freight'      => array('field' => 'base_shipping_cost', 'format' => 'currency'),
                'ordertotal'   => array('field' => 'total_inc_tax', 'format' => 'currency'),
                'salestax'     => array('field' => 'total_tax', 'format' => 'currency'),
                'contact'      => array('field' => 'billing_address.first_name|billing_address.last_name', 'glue' => ' '),
                'custname'     => array('field' => 'billing_address.company'),
                'billname'     => array('field' => 'billing_address.company'),
                'billaddress'  => array('field' => 'billing_address.street_1'),
                'billaddress2' => array('field' => 'billing_address.street_2'),
                'billcity'     => array('field' => 'billing_address.city'),
                'billstate'    => array('field' => 'billing_address.state'),
                'billzip'      => array('field' => 'billing_address.zip'),
                'billcountry'  => array('field' => 'billing_address.country_iso2'),
                'phone'        => array('field' => 'billing_address.phone'),
                'email'        => array('field' => 'billing_address.email'),
                'custpo'       => array('field' => 'id')
            ),
            'shipping' => array( // This maps SalesOrderEdit to BigCommerce\Api\Resources\Address
                'sconame'      => array('field' => 'first_name|last_name', 'glue' => ' '),
                'shipname'     => array('field' => 'company'),
                'shipaddress'  => array('field' => 'street_1'),
                'shipaddress2' => array('field' => 'street_2'),
                'shipcity'     => array('field' => 'city'),
                'shipstate'    => array('field' => 'state'),
                'shipzip'      => array('field' => 'zip'),
                'shipcountry'  => array('field' => 'country_iso2'),
                'freight'      => array('field' => 'base_cost')
            ),
            'details' => array( // This maps SalesOrderDetail to BigCommerce\Api\Resources\OrderProduct
                'orderno'    => array('field' => 'order_id'),
                'sessionid'  => array('field' => 'order_id'),
                'linenbr'    => array('field' => 'id'),
                'recno'      => array('field' => 'id'),
                'itemid'     => array('field' => 'sku'),
                'price'      => array('field' => 'base_price', 'format' => 'currency'),
                'qty'        => array('field' => 'quantity'),
                'desc1'      => array('field' => 'name'),
                'desc2'      => array('field' => 'product_id'),
                'qtyshipped' => array('field' => 'qtyshipped'),
                'totalprice' => array('field' => 'base_total', 'format' => 'currency')
            )
        );

        /**
         * Returns array of Orders
         *
         * @param  integer $limit
         * @param  array   $options
         * @return array   <BigCommerce\Api\Resources\Order>
         */
        public function get_orders($limit = 0, array $options) {
            if ($limit) {
                $options['limit'] = $limit;
            } 
            return BigCommerce::getOrders($options);
        }

        /**
         * Imports the Sales Orders to Database
         * @param  int   $limit
         * @param  array $options
         * @return array
         */
        public function import_orders($limit = 0, array $options) {
            $results = array();
            $orders = $this->get_orders($limit, $options);
            foreach ($orders as $order) {
                $results[$order->id] = $this->save_order($order);
            }
            return $results;
        }

        /**
         * Saves Order To the Database
         *
         * @param BigCommerceOrder $bc_order
         * @return array
         */
        public function save_order(BigCommerceOrder $bc_order) {
            $results = array('head' => false, 'details' => array());
            $dplusorder = new SalesOrderEdit();
            $bc_order_addresses = BigCommerce::getOrderShippingAddresses($bc_order->id, array('limit' => '1'));
            $bc_order_details = BigCommerce::getOrderProducts($bc_order->id);

            $this->map_billing($bc_order, $dplusorder);
            $this->map_shipping($bc_order_addresses[0], $dplusorder);
            
            
            // SalesOrderEdit in its current implementation does not create the record using the save()
            // So we check if it exists, then create / update it
            if (SalesOrderEdit::exists($dplusorder->sessionid, $dplusorder->ordernumber)) {
                $results['head'] = $dplusorder->update();
            } else {
                $results['head'] = $dplusorder->create();
            }
            
            if (!$results['head']) {
                $this->error("$bc_order->id was not able to be saved");
            } else {
                
                foreach ($bc_order_details as $bc_order_detail) {
                    $dplus_detail = new SalesOrderDetail();
                    $this->map_details($bc_order_detail, $dplus_detail);
                    $results['detail'][$bc_order_detail->id] = $dplus_detail->save();
                    
                    if (!$results['detail'][$bc_order_detail->id]) {
                        $this->error("$bc_order->id Line Number $bc_order_detail->id was not able to be saved");
                    }
                }
            }
            return $results;
        }

        /**
         * Sets the Sales Order property values by using the mapper for billing
         * NOTE The Billing Address State comes in itss long form (New York) not the 2 Character State Code (NY)
         * @param BigCommerceOrder $bc_order   Big Commerce Order 
         * @param SalesOrderEdit   $dplusorder Sales Order for Dplus
         * @return void
         */
        protected function map_billing(BigCommerceOrder $bc_order, SalesOrderEdit $dplusorder) {
            foreach ($this->structure['billing'] as $fieldname => $properties) {
                $property = $fieldname;
                $dplusorder->set($property, $this->get_value($bc_order, $fieldname, $properties));
            }
            $dplusorder->set('billstate', get_stateabbreviation(($bc_order->billing_address->state)));
            $dplusorder->set('paymenttype', $this->payment_types[$bc_order->payment_method]);
        }

        /**
         * Sets the Sales Order property values by using the mapper for shipping
         * NOTE The Billing Address State comes in itss long form (New York) not the 2 Character State Code (NY)
         * @param BigCommerceAddress $address    Big Commerce Address
         * @param SalesOrderEdit     $dplusorder Sales Order for Dplus
         * @return void
         */
        protected function map_shipping(BigCommerceAddress $address, SalesOrderEdit $dplusorder) {
            foreach ($this->structure['shipping'] as $fieldname => $properties) {
                $property = $fieldname;
                $dplusorder->set($property, $this->get_value($address, $fieldname, $properties));
            }
            $dplusorder->set('shipstate', get_stateabbreviation(($address->state)));
        }

        /**
         * Sets the Sales Order Detail property values by using the mapper for details
         *
         * @param BigCommerceOrderProduct $bc_order_detail  Big Commerce Order Detail
         * @param SalesOrderDetail        $dplus_detail     Dplus Sales Order Detail
         * @return void
         */
        protected function map_details(BigCommerceOrderProduct $bc_order_detail, SalesOrderDetail $dplus_detail) {
            foreach ($this->structure['details'] as $fieldname => $properties) {
                $property = $fieldname;
                $dplus_detail->set($property, $this->get_value($bc_order_detail, $fieldname, $properties));
            }
        }
    }
