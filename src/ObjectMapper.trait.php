<?php
    namespace Dplus\Import\Orders;
    
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

        /**
         * Travels down the property path if there is a period then determines the returns the value of the property
         *
         * @param  mixed  $object Array of Object to pull property value / path
         * @param  string $field  $field to pull value from array / object
         * @return mixed
         */
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
        
        /**
         * Returns array element / object property value
         *
         * @param  mixed  $object    Object / Array
         * @param  string $property  Name of Property to return value of
         * @return mixed
         */
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