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

    /**
     * Immport classes / libs from same package
     */
    use Dplus\Import\Orders\OrderImporter;
    use Dplus\Import\Orders\ObjectMapper;

    class OrdersAPI extends BaseAPI implements OrderImporter {
        use ObjectMapper;

        protected $structure = array(
            'billing' => array( // This Maps SalesOrderEdit to BigCommerce\Api\Resources\Order
                'orderno'      => array('field' => 'id'),
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
                'linenbr'    => array('field' => 'id'),
                'itemid'     => array('field' => 'product_id'),
                'price'      => array('field' => 'base_price', 'format' => 'currency'),
                'qty'        => array('field' => 'quantity'),
                'desc1'      => array('field' => 'name'),
                'desc2'      => array('field' => 'sku'),
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
            return BigCommerce::getOrders();
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

            
            $results['head'] = $dplusorder->_toArray();
            
            foreach ($bc_order_details as $bc_order_detail) {
                $dplus_detail = new SalesOrderDetail();
                $this->map_details($bc_order_detail, $dplus_detail);
                $results['detail'][$bc_order_detail->id] = $dplus_detail->create();
            }
            return $results;
        }

        /**
         * Sets the Sales Order property values by using the mapper for billing
         * 
         * @param BigCommerceOrder $bc_order   Big Commerce Order 
         * @param SalesOrderEdit   $dplusorder Sales Order for Dplus
         * @return void
         */
        protected function map_billing(BigCommerceOrder $bc_order, SalesOrderEdit $dplusorder) {
            foreach ($this->structure['billing'] as $fieldname => $properties) {
                $property = $fieldname;
                $dplusorder->set($property, $this->get_value($bc_order, $fieldname, $properties));
            }
        }

        /**
         * Sets the Sales Order property values by using the mapper for shipping
         *
         * @param BigCommerceAddress $address    Big Commerce Address
         * @param SalesOrderEdit     $dplusorder Sales Order for Dplus
         * @return void
         */
        protected function map_shipping(BigCommerceAddress $address, SalesOrderEdit $dplusorder) {
            foreach ($this->structure['shipping'] as $fieldname => $properties) {
                $property = $fieldname;
                $salesorder->set($property, $this->get_value($address, $fieldname, $properties));
            }
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