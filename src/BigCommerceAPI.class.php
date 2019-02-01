<?php
    namespace Dplus\Import\Orders;
    
    use Bigcommerce\Api\Client as BigCommerce;
    use Bigcommerce\Api\Resources\Order as BigCommerceOrder;
    use Bigcommerce\Api\Resources\Address as BigCommerceAddress;
    use Bigcommerce\Api\Resources\OrderProduct as BigCommerceOrderProduct;

    use Dplus\Base\ThrowErrorTrait;
    use SalesOrderEdit;
    use Dplus\Base\Validator;

    class BigCommerceAPI {
        use ThrowErrorTrait;
        
        protected $client_id;
        protected $client_secret;
        protected $client_token;
        protected $storehash;

        function __construct($client_id, $client_token, $client_secret, $storehash) {
            $this->client_id = $client_id;
            $this->client_token = $client_token;
            $this->client_secret = $client_secret;
            $this->storehash = $storehash;
            $this->connect();
        }

        function connect() {
            $request = array(
                'client_id'  => $this->client_id,     // CLIENT ID
                'auth_token' => $this->client_token,  // ACCESS TOKEN
                'store_hash' => $this->storehash      // STORE ID
            );


            BigCommerce::configure($request);
            $ping = BigCommerce::getTime();

            if (!$ping) {
                $this->error('Connection to Big Commerce Failed');
            }
        }
    }

    class BigCommerceOrdersAPI extends BigCommerceAPI implements OrderImporter {
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

        protected function map_billing(BigCommerceOrder $bc_order, SalesOrderEdit $dplusorder) {
            foreach ($this->structure['billing'] as $fieldname => $properties) {
                $property = $fieldname;
                $dplusorder->set($property, $this->get_value($bc_order, $fieldname, $properties));
            }
        }

        protected function map_shipping(BigCommerceAddress $address, SalesOrderEdit $dplusorder) {
            foreach ($this->structure['shipping'] as $fieldname => $properties) {
                $property = $fieldname;
                $salesorder->set($property, $this->get_value($address, $fieldname, $properties));
            }
        }

        protected function map_details(BigCommerceOrderProduct $bc_order_detail, SalesOrderDetail $dplus_detail) {
            foreach ($this->structure['details'] as $fieldname => $properties) {
                $property = $fieldname;
                $dplus_detail->set($property, $this->get_value($bc_order_detail, $fieldname, $properties));
            }
        }
    }

  
    trait ObjectMapper {
        /**
		 * Determines the Value to get
		 * @param  mixed  $object          Object or Array that contains $field as a key / property
		 * @param  string $field           Property Name to get value of from $object
		 * @param  array  $fieldproperties Array of Properties for that field
		 * @return mixed                   Formatted Value
		 */
		protected function get_value($object, $field, $fieldproperties) {
            $field = !empty($fieldproperties['field']) ? $fieldproperties['field'] : $field;

            if (strpos($field, '|') !== false) {
                $fields = explode('|', $field);
                $values = array();

                foreach ($fields as $field) {
                    $values[] = $this->find_value($object, $field);
                }
                $glue = isset($fieldproperties['glue']) ? $fieldproperties['glue'] : ' ';
                $value = implode($glue, $values);
            } else {
               $value = $this->find_value($object, $field);
            }
            
			return $this->format_value($value, $fieldproperties);
        }

        function find_value($object, $field) {
            if (strpos($field, '.') !== false) {
                $property = $this->get_propertyfrompath($field);
                $child = $this->get_parentfrompath($object, $field);
                $value = $this->get_propertyvalue($child, $property);
            } else {
                $value = $this->get_propertyvalue($object, $field);
            }
            return $value;
        }

        function get_propertyvalue($object, $property) {
            if (is_array($object)) {
                $value = isset($object[$field]) ? $object[$field] : '';
            } else {
                $value = isset($object->$property) ? $object->$property : '';
            }
            return $value;
        }

        /**
         * Returns the property name which is the string after the last period
         * @param  string  $propertypath  Property path with property name ex. billing_address.lastname
         * @return string                 Property Name seperated from property path
         */
        function get_propertyfrompath($propertypath) {
            return substr($propertypath, strrpos($propertypath, '.') + 1);
        }

        /**
         * Returns the property name which is the string after the last period
         * @param  string  $propertypath  Property path with property name ex. billing_address.lastname
         * @return string                 Property Name seperated from property path
         */
        function get_parentfrompath($parent, $propertypath) {
            $children = explode('.', $propertypath); 
            array_pop($children); // Removes the property from parent path

            while (sizeof($children)) {
                $child = $children[0];

                if (is_array($parent)) {
                    $parent = $parent[$child];
                } else {
                    $parent = $parent->$child;
                }

                if (sizeof($children) == 1) {
                    unset($children[0]);
                } else {
                    array_shift($children);
                }
            }
            return $parent;
        }
        
        /**
		 * Formats the value of a field using the field properties array
		 * @param  mixed $value            Value to format
		 * @param  array $fieldproperties  Properties
		 * @return mixed                   Formatted Value
		 */
		protected function format_value($value, $fieldproperties) {
			if (isset($fieldproperties['format'])) {
				switch ($fieldproperties['format']) {
					case 'date':
						$value = date($fieldproperties['date-format'], strtotime($this->clean_value($value)));
                        break;
                    case 'currency':
                        $value = number_format(floatval($value), 2);
                        break;
				}
			} elseif (isset($fieldproperties['strlen'])) {
				$value = substr($this->clean_value($value), 0, $fieldproperties['strlen']);
			} else {
				$value = $this->clean_value($value);
			}
			
			if (isset($fieldproperties['auto'])) {
				$value = $this->clean_value($fieldproperties['auto']);
			}
			
			return (empty($value) && isset($fieldproperties['default'])) ? $fieldproperties['default'] : $value;
        }
        

        
		/**
		 * Sanitizes the value using str_replace
		 * @param  string $value string
		 * @return string        Sanitized String
		 */
		protected function clean_value($value) {
			$replace = array(
				"\r" => '',
				"\n" => ''
			);
			return trim(str_replace(array_keys($replace), array_values($replace), $value));
		}
    }