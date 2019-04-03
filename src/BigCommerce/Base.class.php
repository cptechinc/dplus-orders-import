<?php
    namespace Dplus\Import\Orders\BigCommerce;
    
    /**
     * Import BigCommerce Libraries
     */
    use Bigcommerce\Api\Client as BigCommerce;
    use Dplus\Base\ThrowErrorTrait;

    class BaseAPI {
        use ThrowErrorTrait;
        
        /**
         * Big Commerce Client ID
         * @var string
         */
        protected $client_id;

        /**
         * Big Commerce Client Secret
         * @var string
         */
        protected $client_secret;

       /**
         * Big Commerce Client API token
         * @var string
         */
        protected $client_token;

        /**
         * Big Commerce Store Hash
         * @var string
         */
        protected $storehash;

        /**
         * Constructor
         *
         * @param string $client_id     Big Commerce API Client ID
         * @param string $client_token  Big Commerce API Client Token
         * @param string $client_secret Big Commerce API Client Secret
         * @param string $storehash     Big Commerce Store Hash
         */
        function __construct($client_id, $client_token, $client_secret, $storehash) {
            $this->client_id = $client_id;
            $this->client_token = $client_token;
            $this->client_secret = $client_secret;
            $this->storehash = $storehash;
            $this->connect();
        }

        /**
         * Inititates connection to Big Commerce's API,
         * then validates connection
         *
         * @return void
         */
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