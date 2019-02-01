<?php 
    namespace Dplus\Import\Orders;
    
    interface OrderImporter {

        public function get_orders($limit = 0, array $options);
    }