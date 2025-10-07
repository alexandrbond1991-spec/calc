<?php

/**
 * Class calcapishipShipping calculates a shipping cost and time
 * @property-read array $delivery_address Delivery address
 * @property-read array $dispatch_address Dispatch address
 * @property-read array $weight Weight
 * @property-read array $weight_and_dimensions Weight and dimensions
 * @property-read array $providers Providers
 * @property-read array $delivery_types Delivery types
 * @property-read array $pickup_types Pickup types
 * @property-read string $cheat_sheet Cheat sheet
 * @property-read string $sort_by Sort results by
 * @property-read boolean $display_point_types Display point types
 * @property-read string $yandex_maps_api_key Yandex.Maps API key
 * todo show rules applied to the shipping cost and time in the backend
 */
class calcapishipShipping extends waShipping
{
    /**
     * @var boolean Service API unsafe mode
     */
    private $unsafe_mode;

    /**
     * @var string Service API unsafe URL
     */
    private $unsafe_url = 'http://api.apiship.ru:11987/v1/';

    /**
     * @var string Service API URL
     */
    private $url = 'https://api.apiship.ru/v1/';

    /**
     * @var string Service API username
     */
    private $username;

    /**
     * @var string Service API password
     */
    private $password;

    /**
     * @var boolean Service API test mode
     */
    private $test_mode;

    /**
     * @var string Service API test mode URL
     */
    private $test_mode_url = 'http://api.dev.apiship.ru/v1/';

    /**
     * @var string Service API test mode username
     */
    private $test_mode_username = 'test';

    /**
     * @var string Service API test mode password
     */
    private $test_mode_password = 'test';

    /**
     * @var array Service API token and its expiration date
     */
    private $token = array();

    /**
     * @var array Point types
     */
    private $point_types = array();

    /**
     * @var array Points
     */
    private $points = array();

    /**
     * @var array Rules
     */
    private $rules = array(
        'increase_cost' => array(
            'measure' => array(
                'percent' => array(
                    'shipping',
                    'origin',
                    'order',
                ),
                'flatfee' => 'currency',
            ),
        ),
        'decrease_cost' => array(
            'measure' => array(
                'percent' => array(
                    'shipping',
                    'origin',
                    'order',
                ),
                'flatfee' => 'currency',
            ),
        ),
        'fix_cost' => array(
            'measure' => 'currency',
        ),
        'increase_min_time' => array(
            'measure' => 'day',
        ),
        'decrease_min_time' => array(
            'measure' => 'day',
        ),
        'fix_min_time' => array(
            'measure' => 'day',
        ),
        'increase_max_time' => array(
            'measure' => 'day',
        ),
        'decrease_max_time' => array(
            'measure' => 'day',
        ),
        'fix_max_time' => array(
            'measure' => 'day',
        ),
    );

    /**
     * @var array Provider colors
     */
    private $colors = array(
        'boxberry' => '#dd214b',
        'cdek' => '#57a52c',
        'dalli' => '#0055ab',
        'dostavista' => '#ff3e80',
        'dpd' => '#e11836',
        'easyway' => '#252069',
        'gett' => '#fbb739',
        'hermes' => '#0091cd',
        'iml' => '#ffb94a',
        'logsis' => '#00c8ff',
        'maxi' => '#e44619',
        'pony' => '#7ac143',
        'rupost' => '#2051a8',
        'shoplogist' => '#f5db47',
    );

    /**
     * Initialises a plugin
     * @return void
     * @throws Exception
     */
    protected function init()
    {
        parent::init();
        $this->clearingCache();
        $this->unsafe_mode = $this->getSettings('unsafe_mode');
        $this->test_mode = $this->getSettings('test_mode');
        if (!empty($this->test_mode)) {
            $this->username = $this->test_mode_username;
            $this->password = $this->test_mode_password;
        } else {
            $this->username = $this->getSettings('username');
            $this->password = $this->getSettings('password');
        }
        $this->token = $this->settingToken();
        $this->point_types = $this->cachingPointTypes();
    }

    /**
     * Returns a list of the rates
     * @return array|string Rates, an error message otherwise
     * @throws waException
     */
    protected function calculate()
    {
        $cached_error = new waVarExportCache($this->getSessionKey('error'), $this->getSessionTtl(), 'shipping_calcapiship');
        if (empty($this->dispatch_address)) {
            waLog::dump(array(
                'params' => $this->dispatch_address,
                'error' => $this->_w('Input a dispatch address')
            ), 'wa-plugins/shipping/calcapiship/calculate.log');
            $cached_error->set(true);
            return $this->_w('Something has gone wrong');
        }
        if (empty($this->weight)) {
            waLog::dump(array(
                'params' => $this->weight,
                'error' => $this->_w('Weight is empty')
            ), 'wa-plugins/shipping/calcapiship/calculate.log');
            $cached_error->set(true);
            return $this->_w('Something has gone wrong');
        }
        if (empty($this->weight_and_dimensions)) {
            waLog::dump(array(
                'params' => $this->weight_and_dimensions,
                'error' => $this->_w('Weight and dimensions are empty')
            ), 'wa-plugins/shipping/calcapiship/calculate.log');
            $cached_error->set(true);
            return $this->_w('Something has gone wrong');
        }
        if (empty($this->providers)) {
            waLog::dump(array(
                'params' => $this->providers,
                'error' => $this->_w('Choose at least one provider')
            ), 'wa-plugins/shipping/calcapiship/calculate.log');
            $cached_error->set(true);
            return $this->_w('Something has gone wrong');
        }
        if (empty($this->delivery_types)) {
            waLog::dump(array(
                'params' => $this->delivery_types,
                'error' => $this->_w('Choose at least one delivery type')
            ), 'wa-plugins/shipping/calcapiship/calculate.log');
            $cached_error->set(true);
            return $this->_w('Something has gone wrong');
        }
        if (empty($this->pickup_types)) {
            waLog::dump(array(
                'params' => $this->pickup_types,
                'error' => $this->_w('Choose at least one pickup type')
            ), 'wa-plugins/shipping/calcapiship/calculate.log');
            $cached_error->set(true);
            return $this->_w('Something has gone wrong');
        }
        $checkout_version = null;
        if (wa()->getEnv() == 'frontend') {
            $route = wa()->getRouting()->getRoute();
            $checkout_version = ifset($route, 'checkout_version', 1);
        }
        $delivery_address = $this->getAddress();
        $delivery_address_error = array();
        if (empty($delivery_address['country'])) {
            array_push($delivery_address_error, $this->_w('Country'));
        }
        if (empty($delivery_address['region'])) {
            array_push($delivery_address_error, $this->_w('Region'));
        }
        if (empty($delivery_address['city'])) {
            array_push($delivery_address_error, $this->_w('City'));
        }
        if (
            $checkout_version != 2 &&
            wa()->getEnv() == 'frontend' &&
            !empty($this->delivery_types[1]) &&
            empty($delivery_address['street']) &&
            !empty(waRequest::get())
        ) {
            array_push($delivery_address_error, $this->_w('Street'));
        }
        if (!empty($delivery_address_error)) {
            $cached_error->set(true);
            return array(
                array(
                    'rate' => null,
                    'comment' => $this->_w('Input a delivery address') . ': ' . implode('; ', $delivery_address_error),
                )
            );
        }
        $total_weight = 0;
        if (method_exists($this, 'getTotalWeight')) {
            $total_weight = $this->getTotalWeight();
            $total_weight = ceil($total_weight);
        }
        $weight = $this->weight['weight'];
        if (
            empty($this->weight['force']) &&
            !empty($total_weight)
        ) {
            $weight = $total_weight;
        }
        $total_length = 0;
        if (method_exists($this, 'getTotalLength')) {
            $total_length = $this->getTotalLength();
            $total_length = ceil($total_length);
        }
        $length = 0;
        if (!empty($total_length)) {
            $length = $total_length;
        }
        $total_width = 0;
        if (method_exists($this, 'getTotalWidth')) {
            $total_width = $this->getTotalWidth();
            $total_width = ceil($total_width);
        }
        $width = 0;
        if (!empty($total_width)) {
            $width = $total_width;
        }
        $total_height = 0;
        if (method_exists($this, 'getTotalHeight')) {
            $total_height = $this->getTotalHeight();
            $total_height = ceil($total_height);
        }
        $height = 0;
        if (!empty($total_height)) {
            $height = $total_height;
        }
        if (
            empty($length) ||
            empty($width) ||
            empty($height)
        ) {
            foreach ($this->weight_and_dimensions['weight'] as $weight_key => $weight_value) {
                if ($weight <= $weight_value) {
                    $length = $this->weight_and_dimensions['length'][$weight_key];
                    $width = $this->weight_and_dimensions['width'][$weight_key];
                    $height = $this->weight_and_dimensions['height'][$weight_key];
                    break;
                }
            }
        }
        //array_keys function returns a numeric key as an integer,
        //what is not acceptable by the service API,
        //because it requires the strings only
        //example {"key": 8, "name": "courierexe 8 provider"}
        $provider_keys = array();
        foreach ($this->providers as $provider) {
            array_push($provider_keys, $provider['key']);
        }
        $dispatch_address = $this->convertAddress($this->dispatch_address);
        $delivery_address = $this->convertAddress($delivery_address);
        if (empty($weight)) {
            $weight = 1;
        }
        $params = array(
            'from' => $dispatch_address,
            'to' => $delivery_address,
            'weight' => $weight,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'assessedCost' => $this->getTotalPrice(),
            'providerKeys' => $provider_keys,
            'deliveryTypes' => array_keys($this->delivery_types),
            'pickupTypes' => array_keys($this->pickup_types),
        );
        $departure_datetime = $this->getPackageProperty('departure_datetime');
        if (!empty($departure_datetime)) {
            if (strtotime($departure_datetime) < time()) {
                $departure_datetime = shopDepartureDateTimeFacade::getDeparture();
            }
            $pickup_timestamp = strtotime($departure_datetime);
            if ($pickup_timestamp) {
                $pickup_timestamp = $this->getNearestWorkingTimestamp($pickup_timestamp);
                $params['pickupDate'] = date('Y-m-d', $pickup_timestamp);
            } else {
                $params['pickupDate'] = date('Y-m-d');
            }
        }
        $rates = array();
        $cached_providers = new waVarExportCache($this->getSessionKey('providers'), $this->getSessionTtl(), 'shipping_calcapiship');
        $cached_params = new waVarExportCache($this->getSessionKey('params'), $this->getSessionTtl(), 'shipping_calcapiship');
        $cached_rates_to_door = new waVarExportCache($this->getSessionKey('rates_to_door'), $this->getSessionTtl(), 'shipping_calcapiship');
        $cached_rates_to_point = new waVarExportCache($this->getSessionKey('rates_to_point'), $this->getSessionTtl(), 'shipping_calcapiship');
        $cached_rates = new waVarExportCache($this->getSessionKey('rates'), $this->getSessionTtl(), 'shipping_calcapiship');
        if (
            empty($cached_rates->get()) ||
            $this->providers != $cached_providers->get() ||
            $params != $cached_params->get()
        ) {
            $cached_providers->delete();
            $cached_params->delete();
            $cached_rates_to_door->delete();
            $cached_rates_to_point->delete();
            $cached_rates->delete();
            $cached_providers->set($this->providers);
            $cached_params->set($params);
            $data = $this->getCalculator($params);
            if (!empty($data['deliveryToDoor'])) {
                $rates_to_door = $this->convertRatesToDoor($data['deliveryToDoor']);
                $cached_rates_to_door->set($rates_to_door);
                $rates = array_merge($rates, $this->convertRates($rates_to_door));
            }
            if (!empty($data['deliveryToPoint'])) {
                $this->points = $this->cachingPoints($provider_keys, $delivery_address['countryCode'], $delivery_address['city']);
                $rates_to_point = $this->convertRatesToPoint($data['deliveryToPoint']);
                $cached_rates_to_point->set($rates_to_point);
                $rates = array_merge($rates, $this->convertRates($rates_to_point));
            }
            if (!empty($this->sort_by)) {
                $sorted_rates = array();
                foreach ($rates as $key => $value) {
                    $sorted_rates[$key] = $value[$this->sort_by];
                }
                array_multisort($sorted_rates, SORT_ASC, $rates);
            }
            $cached_rates->set($rates);
        } else {
            $rates = $cached_rates->get();
        }
        if (!empty($rates)) {
            $cached_error->set(false);
            return $rates;
        }
        $cached_error->set(true);
        return array(
            array(
                'rate' => null,
                'comment' => $this->_w('No suitable options'),
            )
        );
    }

    /**
     * Returns a currency ISO3 code
     * @return string Currency ISO3 code
     */
    public function allowedCurrency()
    {
        return 'RUB';
    }

    /**
     * Returns a weight unit
     * @return string Weight unit
     */
    public function allowedWeightUnit()
    {
        return 'g';
    }

    /**
     * Returns a linear unit
     * @return string Linear unit
     */
    public function allowedLinearUnit()
    {
        return 'cm';
    }

    /**
     * Returns an address
     * @return array Address
     */
    public function allowedAddress()
    {
        $delivery_address = array();
        foreach ($this->delivery_address as $field => $value) {
            if (!empty($value)) {
                if ($field == 'country') {
                    $delivery_address[$field] = trim($value);
                }
                if ($field == 'region') {
                    $regions = array();
                    foreach ($value as $region) {
                        if (!empty($region)) {
                            array_push($regions, $region);
                        }
                    }
                    if (!empty($regions)) {
                        $delivery_address[$field] = $regions;
                    }
                }
                if ($field == 'city') {
                    if (strpos($value, ',')) {
                        $delivery_address[$field] = array_filter(array_map('trim', explode(',', $value)), 'strlen');
                    } else {
                        $delivery_address[$field] = trim($value);
                    }
                }
            }
        }
        return array($delivery_address);
    }

    /**
     * Returns a list of the delivery address fields
     * @return array Delivery address fields
     */
    public function requestedAddressFields()
    {
        $fields = array(
            'country' => array(
                'cost' => true,
                'required' => true
            ),
            'region' => array(
                'cost' => true,
                'required' => true
            ),
            'city' => array(
                'cost' => true,
                'required' => true
            ),
        );
        if (!empty($this->delivery_types[1])) {
            $fields['street'] = array(
                'cost' => true,
                'required' => true
            );
        }
        return $fields;
    }

    /**
     * Returns a list of the delivery address fields depending on the service type
     * @param string $service Service
     * @return array Delivery address fields
     */
    public function requestedAddressFieldsForService($service)
    {
        $fields = array(
            'country' => array(
                'cost' => true,
                'required' => true
            ),
            'region' => array(
                'cost' => true,
                'required' => true
            ),
            'city' => array(
                'cost' => true,
                'required' => true
            ),
        );
        if (!empty($service['type'])) {
            if (
                $service['type'] == self::TYPE_TODOOR ||
                $service['type'] == self::TYPE_POST
            ) {
                $fields['street'] = array(
                    'cost' => true,
                    'required' => true
                );
            }
        }
        return $fields;
    }

    /**
     * Returns a list of the custom fields
     * @param waOrder $order Order
     * @return array Custom fields
     * @throws waException
     */
    public function customFields(waOrder $order)
    {
        $custom_fields = $this->getAdapter()->getAppProperties('custom_fields');
        if (empty($custom_fields)) {
            return array();
        }
        $fields = parent::customFields($order);
        $params = $order->shipping_params;
        $this->registerControl('CalculatorControl', array($this, 'settingCalculatorControl'));
        $fields['calculator'] = array(
            'title' => '',
            'value' => ifset($params['calculator']),
            'control_type' => 'CalculatorControl',
        );
//        $fields['delivery_cost'] = array(
//            'title' => $this->_w('Delivery cost'),
//            'value' => ifset($params['delivery_cost']),
//            'control_type' => waHtmlControl::HIDDEN,
//        );
//        $fields['delivery_time'] = array(
//            'title' => $this->_w('Delivery time'),
//            'value' => ifset($params['delivery_time']),
//            'control_type' => waHtmlControl::HIDDEN,
//        );
        $fields['delivery_type'] = array(
            'title' => $this->_w('Delivery type'),
            'value' => ifset($params['delivery_type']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['provider'] = array(
            'title' => $this->_w('Provider'),
            'value' => ifset($params['provider']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['tariff'] = array(
            'title' => $this->_w('Tariff'),
            'value' => ifset($params['tariff']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['point'] = array(
            'title' => $this->_w('Point'),
            'value' => ifset($params['point']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['point_type'] = array(
            'title' => $this->_w('Point type'),
            'value' => ifset($params['point_type']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['point_code'] = array(
            'title' => $this->_w('Point code'),
            'value' => ifset($params['point_code']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['point_iml_id'] = array(
            'title' => $this->_w('Point IML ID'),
            'value' => ifset($params['point_iml_id']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['address'] = array(
            'title' => $this->_w('Address'),
            'value' => ifset($params['address']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['phone'] = array(
            'title' => $this->_w('Phone'),
            'value' => ifset($params['phone']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['timetable'] = array(
            'title' => $this->_w('Timetable'),
            'value' => ifset($params['timetable']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['description'] = array(
            'title' => $this->_w('Description'),
            'value' => ifset($params['description']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['payment'] = array(
            'title' => $this->_w('Payment'),
            'value' => ifset($params['payment']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['payment_card'] = array(
            'title' => $this->_w('Payment card'),
            'value' => ifset($params['payment_card']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        $fields['fitting_room'] = array(
            'title' => $this->_w('Fitting room'),
            'value' => ifset($params['fitting_room']),
            'control_type' => waHtmlControl::HIDDEN,
        );
        return $fields;
    }

    /**
     * Returns a list of the custom fields depending on the service type
     * @param waOrder $order Order
     * @param string $service Service
     * @return array Custom fields
     * @throws waException
     */
    public function customFieldsForService(waOrder $order, $service)
    {
        $custom_fields = $this->getAdapter()->getAppProperties('custom_fields');
        if (empty($custom_fields)) {
            return array();
        }
        $fields = parent::customFields($order);
        $params = $order->shipping_params;
        if (!empty($service['type'])) {
            if ($service['type'] == self::TYPE_TODOOR) {
                $this->registerControl('CalculatorControl', array($this, 'settingCalculatorControl'));
                $fields['calculator'] = array(
                    'title' => '',
                    'value' => ifset($params['calculator']),
                    'control_type' => 'CalculatorControl',
                );
//                $fields['delivery_cost'] = array(
//                    'title' => $this->_w('Delivery cost'),
//                    'value' => ifset($params['delivery_cost']),
//                    'control_type' => waHtmlControl::HIDDEN,
//                );
//                $fields['delivery_time'] = array(
//                    'title' => $this->_w('Delivery time'),
//                    'value' => ifset($params['delivery_time']),
//                    'control_type' => waHtmlControl::HIDDEN,
//                );
                $fields['delivery_type'] = array(
                    'title' => $this->_w('Delivery type'),
                    'value' => ifset($params['delivery_type']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['provider'] = array(
                    'title' => $this->_w('Provider'),
                    'value' => ifset($params['provider']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['tariff'] = array(
                    'title' => $this->_w('Tariff'),
                    'value' => ifset($params['tariff']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
            }
            if ($service['type'] == self::TYPE_POST) {
                $this->registerControl('CalculatorControl', array($this, 'settingCalculatorControl'));
                $fields['calculator'] = array(
                    'title' => '',
                    'value' => ifset($params['calculator']),
                    'control_type' => 'CalculatorControl',
                );
//                $fields['delivery_cost'] = array(
//                    'title' => $this->_w('Delivery cost'),
//                    'value' => ifset($params['delivery_cost']),
//                    'control_type' => waHtmlControl::HIDDEN,
//                );
//                $fields['delivery_time'] = array(
//                    'title' => $this->_w('Delivery time'),
//                    'value' => ifset($params['delivery_time']),
//                    'control_type' => waHtmlControl::HIDDEN,
//                );
                $fields['delivery_type'] = array(
                    'title' => $this->_w('Delivery type'),
                    'value' => ifset($params['delivery_type']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['provider'] = array(
                    'title' => $this->_w('Provider'),
                    'value' => ifset($params['provider']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['tariff'] = array(
                    'title' => $this->_w('Tariff'),
                    'value' => ifset($params['tariff']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
            }
            if ($service['type'] == self::TYPE_PICKUP) {
                $this->registerControl('CalculatorControl', array($this, 'settingCalculatorControl'));
                $fields['calculator'] = array(
                    'title' => '',
                    'value' => ifset($params['calculator']),
                    'control_type' => 'CalculatorControl',
                );
//                $fields['delivery_cost'] = array(
//                    'title' => $this->_w('Delivery cost'),
//                    'value' => ifset($params['delivery_cost']),
//                    'control_type' => waHtmlControl::HIDDEN,
//                );
//                $fields['delivery_time'] = array(
//                    'title' => $this->_w('Delivery time'),
//                    'value' => ifset($params['delivery_time']),
//                    'control_type' => waHtmlControl::HIDDEN,
//                );
                $fields['delivery_type'] = array(
                    'title' => $this->_w('Delivery type'),
                    'value' => ifset($params['delivery_type']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['provider'] = array(
                    'title' => $this->_w('Provider'),
                    'value' => ifset($params['provider']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['tariff'] = array(
                    'title' => $this->_w('Tariff'),
                    'value' => ifset($params['tariff']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['point'] = array(
                    'title' => $this->_w('Point'),
                    'value' => ifset($params['point']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['point_type'] = array(
                    'title' => $this->_w('Point type'),
                    'value' => ifset($params['point_type']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['point_code'] = array(
                    'title' => $this->_w('Point code'),
                    'value' => ifset($params['point_code']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['point_iml_id'] = array(
                    'title' => $this->_w('Point IML ID'),
                    'value' => ifset($params['point_iml_id']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['address'] = array(
                    'title' => $this->_w('Address'),
                    'value' => ifset($params['address']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['phone'] = array(
                    'title' => $this->_w('Phone'),
                    'value' => ifset($params['phone']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['timetable'] = array(
                    'title' => $this->_w('Timetable'),
                    'value' => ifset($params['timetable']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['description'] = array(
                    'title' => $this->_w('Description'),
                    'value' => ifset($params['description']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['payment'] = array(
                    'title' => $this->_w('Payment'),
                    'value' => ifset($params['payment']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['payment_card'] = array(
                    'title' => $this->_w('Payment card'),
                    'value' => ifset($params['payment_card']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
                $fields['fitting_room'] = array(
                    'title' => $this->_w('Fitting room'),
                    'value' => ifset($params['fitting_room']),
                    'control_type' => waHtmlControl::HIDDEN,
                );
            }
        }
        return $fields;
    }

    /**
     * Returns controls' HTML code of the settings page
     * @param array $params Controls' parameters
     * @return string Controls' HTML code of the settings page
     * @throws Exception
     * @todo services of the providers
     */
    public function getSettingsHTML($params = array())
    {
        $params['title_wrapper'] = '%s <i class="icon16 loading hidden"></i>';
        $params['description_wrapper'] = '<p class="hint">%s</p>';
        $params['options_wrapper'] = array(
            'control_wrapper' => '<div class="calcapiship_settings">%2$s %1$s %3$s <i class="icon16 loading hidden"></i></div>',
            'control_separator' => '',
        );
        $view = wa()->getView();
        $post = waRequest::post();
        if (!empty($post)) {
            $providers = ifempty($post['shipping']['settings']['providers']);
            $this->unsafe_mode = ifempty($post['shipping']['settings']['unsafe_mode']);
            $this->test_mode = ifempty($post['shipping']['settings']['test_mode']);
            $this->username = ifempty($post['shipping']['settings']['username']);
            $this->password = ifempty($post['shipping']['settings']['password']);
            $this->token = $this->settingToken(true);
            if (!empty($providers)) {
                $provider_key = key($providers);
                $settings = $this->getSettings('providers');
                $this->registerControl('RulesControl', array($this, 'settingRulesControl'));
                waHtmlControl::addNamespace($params, array(
                    'providers',
                    $provider_key,
                ));
                $rules = array();
                if (
                    !empty($settings[$provider_key]) &&
                    !empty($settings[$provider_key]['rules'])
                ) {
                    $rules = $settings[$provider_key]['rules'];
                }
                $data['rules'] = waHtmlControl::getControl('RulesControl', 'rules', array(
                        'title' => $this->_w('Rules'),
                        'description' => implode('<br>', array(
                            $this->_w('Could be specified one or more rules'),
                            $this->_w('Rules are executed in the order specified in the settings'),
                            $this->_w('If two alike rules are specified, for example, "Increase a shipping time for 1 day" and "Increase a shipping time for 2 days", the shipping time will be increased fo 3 days'),
                            $this->_w('Total shipping cost and time will not be less than zero'),
                            $this->_w('Rule value should contain integer or decimal only'),
                        )),
                        'value' => $rules,
                        'control_wrapper' => '<div class="rules block shadowed">%s<br>%s<br>%s</div>',
                        'title_wrapper' => '<b>%s</b>',
                        'description_wrapper' => '<span class="hint"><b>%s</b></span>',
                    ) + $params);
                $tariffs_page = waRequest::get('page', 1, 'int');
                if (!empty($tariffs_page)) {
                    $tariffs = $this->getTariffs($provider_key, $tariffs_page);
                } else {
                    $tariffs = $this->getTariffs($provider_key);
                }
                if (!empty($tariffs)) {
                    if (
                        $post['shipping']['settings']['providers'][$provider_key] != $provider_key &&
                        !empty($post['shipping']['settings']['providers'][$provider_key]['selected_tariffs'])
                    ) {
                        $selected_tariffs = $post['shipping']['settings']['providers'][$provider_key]['selected_tariffs'];
                        $selected_tariffs_value = json_decode($selected_tariffs);
                    } else {
                        if (
                            !empty($settings[$provider_key]) &&
                            !empty($settings[$provider_key]['tariffs'])
                        ) {
                            $selected_tariffs = json_encode($settings[$provider_key]['tariffs']);
                            $selected_tariffs_value = $settings[$provider_key]['tariffs'];
                        } else {
                            $selected_tariffs = '';
                            $selected_tariffs_value = array();
                        }
                    }
                    $tariffs_value = array();
                    $tariffs_options = array();
                    foreach ($tariffs['items'] as $index => $tariff) {
                        if (in_array($tariff['id'], $selected_tariffs_value)) {
                            array_push($tariffs_value, $tariff['id']);
                        }
                        array_push($tariffs_options, array(
                            'title' => $tariff['name'],
                            'description' => $tariff['description'],
                            'value' => $tariff['id'],
                        ));
                    }
                    $view->assign('total', $tariffs['pages']);
                    $tariffs_pagination = $view->fetch($this->path . '/templates/pagination.html');
                    $selected_tariffs_control = waHtmlControl::getControl(waHtmlControl::HIDDEN, 'selected_tariffs', array(
                            'value' => $selected_tariffs,
                        ) + $params);
                    $data['tariffs'] = waHtmlControl::getControl(waHtmlControl::GROUPBOX, 'tariffs', array(
                            'title' => $this->_w('Tariffs'),
                            'description' => $this->_w('Choose at least one tariff'),
                            'value' => $tariffs_value,
                            'options' => $tariffs_options,
                            'options_wrapper' => array(
                                'control_wrapper' => '%2$s %1$s %3$s<br>',
                                'control_separator' => '',
                            ),
                            'control_wrapper' => '<div class="tariffs block shadowed">' . $selected_tariffs_control . '%s<br>%s%s' . urldecode($tariffs_pagination) . '</div>',
                            'title_wrapper' => '<b>%s</b>',
                            'description_wrapper' => '<span class="hint"><b>%s</b></span>',
                        ) + $params);
                }
                $this->sendJsonSuccess($data);
            } else {
                $providers = waHtmlControl::getControl(waHtmlControl::GROUPBOX, 'providers', array(
                        'description' => $this->_w('To get providers, choose a test mode or input a correct username and password'),
                        'value' => $this->getSettings('providers'),
                        'options_callback' => array($this, 'settingProvidersControlOptions'),
                    ) + $params);
                $delivery_types = waHtmlControl::getControl(waHtmlControl::GROUPBOX, 'delivery_types', array(
                        'description' => $this->_w('To get delivery types, choose a test mode or input a correct username and password'),
                        'value' => $this->getSettings('delivery_types'),
                        'options_callback' => array($this, 'settingDeliveryTypesControlOptions'),
                    ) + $params);
                $pickup_types = waHtmlControl::getControl(waHtmlControl::GROUPBOX, 'pickup_types', array(
                        'description' => $this->_w('To get pickup types, choose a test mode or input a correct username and password'),
                        'value' => $this->getSettings('pickup_types'),
                        'options_callback' => array($this, 'settingPickupTypesControlOptions'),
                    ) + $params);
                $data = array(
                    'providers' => $providers,
                    'delivery_types' => $delivery_types,
                    'pickup_types' => $pickup_types,
                );
                $this->sendJsonSuccess($data);
            }
        } else {
            $this->token = $this->settingToken(true);
            $controls = parent::getSettingsHTML($params);
            $view->assign('controls', $controls);
            return $view->fetch($this->path . '/templates/settings.html');
        }
    }

    /**
     * Saves plugin settings
     * @param array $settings Array of the settings key=>value
     * @return void
     * @throws waException
     */
    public function saveSettings($settings = array())
    {
        $settings = $this->convertData($settings);
        if (
            empty($settings['test_mode']) &&
            (
                empty($settings['username']) ||
                empty($settings['password'])
            )
        ) {
            throw new waException($this->_w('Choose a test mode or input a correct username and password'));
        }
        if (
            empty($settings['dispatch_address']['country']) ||
            empty($settings['dispatch_address']['region']) ||
            empty($settings['dispatch_address']['city']) ||
            empty($settings['dispatch_address']['street'])
        ) {
            throw new waException($this->_w('Input a dispatch address'));
        }
        if (!empty($settings['weight'])) {
            $weight_amount_validator = new waRegexValidator();
            $weight_amount_validator->setPattern('/^[0-9]+$/');
            if (
                empty($settings['weight']['weight']) ||
                $settings['weight']['weight'] == 0 ||
                !$weight_amount_validator->isValid($settings['weight']['weight'])
            ) {
                throw new waException($this->_w('Weight should be greater than zero and contain integer only'));
            }
            if (empty($settings['weight']['force'])) {
                $settings['weight']['force'] = 0;
            }
        }
        if (!empty($settings['weight_and_dimensions'])) {
            $weight_and_dimensions_validator = new waRegexValidator();
            $weight_and_dimensions_validator->setPattern('/^[0-9]+$/');
            asort($settings['weight_and_dimensions']['weight']);
            $weight_and_dimensions = array(
                'weight' => array(),
                'length' => array(),
                'width' => array(),
                'height' => array(),
            );
            foreach ($settings['weight_and_dimensions']['weight'] as $weight_key => $weight_value) {
                if (
                    empty($weight_value) ||
                    empty($settings['weight_and_dimensions']['length'][$weight_key]) ||
                    empty($settings['weight_and_dimensions']['width'][$weight_key]) ||
                    empty($settings['weight_and_dimensions']['height'][$weight_key]) ||
                    !$weight_and_dimensions_validator->isValid($weight_value) ||
                    !$weight_and_dimensions_validator->isValid($settings['weight_and_dimensions']['length'][$weight_key]) ||
                    !$weight_and_dimensions_validator->isValid($settings['weight_and_dimensions']['width'][$weight_key]) ||
                    !$weight_and_dimensions_validator->isValid($settings['weight_and_dimensions']['height'][$weight_key])
                ) {
                    throw new waException($this->_w('Weight and dimensions be greater than zero and contain integer only'));
                }
                array_push($weight_and_dimensions['weight'], $weight_value);
                array_push($weight_and_dimensions['length'], $settings['weight_and_dimensions']['length'][$weight_key]);
                array_push($weight_and_dimensions['width'], $settings['weight_and_dimensions']['width'][$weight_key]);
                array_push($weight_and_dimensions['height'], $settings['weight_and_dimensions']['height'][$weight_key]);
            }
            $settings['weight_and_dimensions'] = $weight_and_dimensions;
        }
        if (empty($settings['providers'])) {
            throw new waException($this->_w('Choose at least one provider'));
        }
        if (empty($settings['delivery_types'])) {
            throw new waException($this->_w('Choose at least one delivery type'));
        }
        if (empty($settings['pickup_types'])) {
            throw new waException($this->_w('Choose at least one pickup type'));
        }
        $this->unsafe_mode = ifempty($settings['unsafe_mode']);
        $this->test_mode = ifempty($settings['test_mode']);
        $this->username = ifempty($settings['username']);
        $this->password = ifempty($settings['password']);
        $this->token = $this->settingToken(true);
        $providers = $this->getProviders();
        $rules_value_validator = new waRegexValidator();
        $rules_value_validator->setPattern('/^[0-9\.]+$/');
        foreach ($providers as $index => $provider) {
            if (array_key_exists($provider['key'], $settings['providers'])) {
                $provider['tariffs'] = array();
                if (!empty($settings['providers'][$provider['key']]['selected_tariffs'])) {
                    $provider['tariffs'] = json_decode($settings['providers'][$provider['key']]['selected_tariffs'], true);
                } else {
                    throw new waException(implode(' ', array(
                        $this->_w('Choose at least one tariff of the provider'),
                        ' "' . $provider['name'] . '"',
                    )));
                }
                $provider['rules'] = array();
                if (!empty($settings['providers'][$provider['key']]['rules']['scheme'])) {
                    $schemes = array_filter($settings['providers'][$provider['key']]['rules']['scheme']);
                    $count_schemes = count($schemes);
                    if (!empty($count_schemes)) {
                        $rules = array();
                        $rules['scheme'] = array();
                        $scheme_index = 0;
                        foreach ($schemes as $scheme) {
                            array_push($rules['scheme'], $scheme);
                            if (
                                empty($settings['providers'][$provider['key']]['rules']['value'][$scheme_index]) ||
                                !$rules_value_validator->isValid($settings['providers'][$provider['key']]['rules']['value'][$scheme_index])
                            ) {
                                throw new waException($this->_w('Rule value should contain integer or decimal only'));
                            }
                            $scheme_index++;
                        }
                        $rules['value'] = $settings['providers'][$provider['key']]['rules']['value'];
                        $rules['measure'] = $settings['providers'][$provider['key']]['rules']['measure'];
                        $rules['percent'] = $settings['providers'][$provider['key']]['rules']['percent'];
                        $rules['description'] = $settings['providers'][$provider['key']]['rules']['description'];
                        $provider['rules'] = $rules;
                    }
                }
                $settings['providers'][$provider['key']] = $provider;
            }
        }
        $delivery_types = $this->getDeliveryTypes();
        foreach ($delivery_types as $index => $delivery_type) {
            if (array_key_exists($delivery_type['id'], $settings['delivery_types'])) {
                $settings['delivery_types'][$delivery_type['id']] = $delivery_type;
            }
        }
        $pickup_types = $this->getPickupTypes();
        foreach ($pickup_types as $index => $pickup_type) {
            if (array_key_exists($pickup_type['id'], $settings['pickup_types'])) {
                $settings['pickup_types'][$pickup_type['id']] = $pickup_type;
            }
        }
        $settings['token'] = $this->token;
        parent::saveSettings($settings);
    }

    /**
     * Returns a token and its expiration date
     * @param boolean $force Force the token to update, even it does not expire
     * @return array|null Token and its expiration date, NULL otherwise
     * @throws waException
     */
    public function settingToken($force = false)
    {
        $token = $this->getSettings('token');
        if (
            !empty($force) ||
            empty($token) ||
            empty($token['accessToken']) ||
            empty($token['expires']) ||
            (
                !empty($token) &&
                !empty($token['accessToken']) &&
                !empty($token['expires']) &&
                strtotime($token['expires']) < time()
            )
        ) {
            if (!empty($this->test_mode)) {
                $this->username = $this->test_mode_username;
                $this->password = $this->test_mode_password;
            }
            if (
                empty($this->username) ||
                empty($this->password)
            ) {
                return null;
            }
            $token = $this->getToken();
            if (
                !empty($token) &&
                !empty($this->key) &&
                $this->key != 'calcapiship'
            ) {
                $settings = $this->getSettings();
                $settings['token'] = $token;
                parent::saveSettings($settings);
            }
        }
        return $token;
    }

    /**
     * Returns a control's HTML code of the delivery address
     * @param string $name Control name
     * @param array $params Control params
     * @return string Control's HTML code of the delivery address
     * @throws Exception
     */
    public function settingDeliveryAddressControl($name, $params = array())
    {
        waHtmlControl::addNamespace($params, $name);
        $country_model = new waCountryModel();
        $country_options = array();
        array_push($country_options, array(
            'title' => $this->_w('Shipping will be restricted to the selected country'),
            'value' => '',
        ));
        $countries = $country_model->all();
        foreach ($countries as $country) {
            array_push($country_options, array(
                'title' => $country['name'],
                'value' => $country['iso3letter'],
            ));
        }
        $country_control = waHtmlControl::getControl(waHtmlControl::SELECT, 'country', array(
                'title' => '',
                'description' => $params['items']['country']['description'],
                'value' => $params['value']['country'],
                'options' => $country_options,
                'control_wrapper' => '<div class="field">%s%s%s</div>',
            ) + $params);
        $city_control = waHtmlControl::getControl(waHtmlControl::INPUT, 'city', array(
                'title' => '',
                'description' => $params['items']['city']['description'],
                'value' => $params['value']['city'],
                'control_wrapper' => '<div class="field">%s%s%s</div>',
            ) + $params);
        //Do not change the order. Region must be the last.
        waHtmlControl::addNamespace($params, array('region'));
        $region_control = waHtmlControl::getControl(waHtmlControl::SELECT, '', array(
                'title' => '',
                'description' => $params['items']['region']['description'],
                'value' => $params['value']['region'],
                'options' => array(array(
                    'title' => $this->_w('Shipping will be restricted to the selected region'),
                    'value' => '',
                )),
                'control_wrapper' => '<div class="field">%s%s <i class="icon16 add"></i>%s</div>',
            ) + $params);
        $view = wa()->getView();
        $view->assign('p', $this);
        $view->assign('name', $name);
        $view->assign('params', $params);
        $view->assign('country_control', $country_control);
        $view->assign('region_control', $region_control);
        $view->assign('city_control', $city_control);
        return $view->fetch(self::getPath('shipping') . '/calcapiship/templates/controls/DeliveryAddressControl.html');
    }

    /**
     * Returns a control's HTML code of the dispatch address
     * @param string $name Control name
     * @param array $params Control params
     * @return string Control's HTML code of the dispatch address
     * @throws Exception
     * @todo dispatch addresses of the warehouses
     */
    public static function settingDispatchAddressControl($name, $params = array())
    {
        $control = parent::settingRegionZoneControl($name, $params);
        $street_params = array(
            'description' => ifempty($params['items']['street']['description']),
            'value' => ifempty($params['value']['street']),
            'control_wrapper' => '<br><div class="street">%s%s%s</div>',
            'description_wrapper' => '<br><span class="hint">%s</span>',
        );
        waHtmlControl::addNamespace($street_params, $name);
        $street_control = waHtmlControl::getControl(waHtmlControl::INPUT, 'street', $street_params);
        $control .= $street_control;
//        $version = wa()->getConfig()->getAppConfig('shop')->getInfo('version');
//        $version_compare = version_compare($version, 8);
//        if ($version_compare != -1) {
        $control .= '
<script>
    \'use strict\';
    (function ($) {
        $(document).ready(function () {
            $(\'select[name="shipping[settings][dispatch_address][country]"]\').val("' . $params['value']['country'] . '").change();
            $(\'[name="shipping[settings][dispatch_address][region]"]\').val("' . $params['value']['region'] . '").change();
        });
    })(jQuery);
</script>
            ';
//        }
        return $control;
    }

    /**
     * Returns a control's HTML code of the weight
     * @param string $name Control name
     * @param array $params Control params
     * @return string Control's HTML code of the weight
     * @throws Exception
     */
    public function settingWeightControl($name, $params = array())
    {
        $view = wa()->getView();
        $view->assign('p', $this);
        $view->assign('name', $name);
        $view->assign('params', $params);
        return $view->fetch(self::getPath('shipping') . '/calcapiship/templates/controls/WeightControl.html');
    }

    /**
     * Returns a control's HTML code of the weight and dimensions
     * @param string $name Control name
     * @param array $params Control params
     * @return string Control's HTML code of the weight and dimensions
     * @throws Exception
     * @todo weight and dimensions depending on the parameters of the product or sku
     */
    public static function settingWeightAndDimensionsControl($name, $params = array())
    {
        $view = wa()->getView();
        $view->assign('name', $name);
        $view->assign('params', $params);
        return $view->fetch(self::getPath('shipping') . '/calcapiship/templates/controls/WeightAndDimensionsControl.html');
    }

    /**
     * Returns a control's HTML code of the rules
     * @param string $name Control name
     * @param array $params Control params
     * @return string Control's HTML code of the rules
     * @throws Exception
     */
    public function settingRulesControl($name, $params = array())
    {
        $view = wa()->getView();
        $view->assign('p', $this);
        $view->assign('name', $name);
        $view->assign('params', $params);
        return $view->fetch(self::getPath('shipping') . '/calcapiship/templates/controls/RulesControl.html');
    }

    /**
     * Returns a control's HTML code of the cheat sheet
     * @param string $name Control name
     * @param array $params Control params
     * @return string Control's HTML code of the cheat sheet
     * @throws Exception
     */
    public static function settingCheatSheetControl($name, $params = array())
    {
        $view = wa()->getView();
        $view->assign('name', $name);
        $view->assign('params', $params);
        return $view->fetch(self::getPath('shipping') . '/calcapiship/templates/controls/CheatSheetControl.html');
    }

    /**
     * Returns a control's options of the providers
     * @return array Control's options of the providers
     * @throws waException
     */
    public function settingProvidersControlOptions()
    {
        $options = array();
        $providers = $this->getProviders();
        foreach ($providers as $index => $provider) {
            array_push($options, array(
                'title' => $provider['name'],
                'description' => $provider['description'],
                'value' => $provider['key'],
            ));
        }
        return $options;
    }

    /**
     * Returns a control's options of the delivery types
     * @return array Control's options of the delivery types
     * @throws waException
     */
    public function settingDeliveryTypesControlOptions()
    {
        $options = array();
        $delivery_types = $this->getDeliveryTypes();
        foreach ($delivery_types as $index => $delivery_type) {
            array_push($options, array(
                'title' => $delivery_type['name'],
                'description' => $delivery_type['description'],
                'value' => $delivery_type['id'],
            ));
        }
        return $options;
    }

    /**
     * Returns a control's options of the pickup types
     * @return array Control's options of the pickup types
     * @throws waException
     */
    public function settingPickupTypesControlOptions()
    {
        $options = array();
        $pickup_types = $this->getPickupTypes();
        foreach ($pickup_types as $index => $pickup_type) {
            array_push($options, array(
                'title' => $pickup_type['name'],
                'description' => $pickup_type['description'],
                'value' => $pickup_type['id'],
            ));
        }
        return $options;
    }

    /**
     * Returns a control's HTML code of the calculator
     * @param string $name Control name
     * @param array $params Control params
     * @return string Control's HTML code of the calculator
     * @throws Exception
     * @todo map by Yandex or Google depending on the settings of the site
     */
    public function settingCalculatorControl($name, $params = array())
    {
        $url_params = array(
            'action_id' => 'calculator',
            'plugin_id' => $this->key,
        );
        $url = wa()->getRouteUrl($this->app_id . '/frontend/shippingPlugin', $url_params, true);
        $providers = array();
        foreach ($this->providers as $provider) {
            $icon = '';
            if (file_exists($this->path . '/img/providers/' . $provider['key'] . '.svg')) {
                $icon = wa_url() . 'wa-plugins/shipping/calcapiship/img/providers/' . $provider['key'] . '.svg';
            }
            $color = '#1e98ff';
            if (!empty($this->colors[$provider['key']])) {
                $color = $this->colors[$provider['key']];
            }
            $temp_provider = array(
                'key' => $provider['key'],
                'name' => $provider['name'],
                'icon' => $icon,
                'color' => $color,
            );
            array_push($providers, $temp_provider);
        }
        $point_types = array();
        foreach ($this->point_types as $point_type) {
            $temp_point_type = array();
            $temp_point_type[$point_type['id']] = $point_type['description'];
            array_push($point_types, $temp_point_type);
        }
        $view = wa()->getView();
        $view->assign('p', $this);
        $view->assign('key', $this->key);
        $view->assign('url', $url);
        $view->assign('api_key', $this->yandex_maps_api_key);
        $view->assign('providers', $this->convertJson($providers));
        $view->assign('point_types', $this->convertJson($point_types));
        $view->assign('display_point_types', $this->display_point_types);
        $view->assign('name', $name);
        $view->assign('params', $params);
        if (wa()->getEnv() == 'backend') {
            return $view->fetch($this->path . '/templates/controls/BackendCalculatorControl.html');
        }
        if (wa()->getEnv() == 'frontend') {
            $route = wa()->getRouting()->getRoute();
            $checkout_version = ifset($route, 'checkout_version', 1);
            if ($checkout_version == 2) {
                return $view->fetch($this->path . '/templates/controls/FrontendCartCalculatorControl.html');
            } else {
                $checkout = wa()->getStorage()->read('shop/checkout');
                $view->assign('shipping_id', ifempty($checkout['shipping']['id']));
                $view->assign('shipping_rate_id', ifempty($checkout['shipping']['rate_id']));
                return $view->fetch($this->path . '/templates/controls/FrontendCheckoutCalculatorControl.html');
            }
        }
    }

    /**
     * Returns a shipping cost and time as JSON response to AJAX request stored in the session
     * @return string JSON response of the shipping cost and time
     * @throws waException
     */
    public function calculatorAction()
    {
        if (waRequest::isXMLHttpRequest()) {
            $cached_error = new waVarExportCache($this->getSessionKey('error'), $this->getSessionTtl(), 'shipping_calcapiship');
            $error = $cached_error->get();
            if (empty($error)) {
                $cached_params = new waVarExportCache($this->getSessionKey('params'), $this->getSessionTtl(), 'shipping_calcapiship');
                $params = $cached_params->get();
                $address = $params['to']['city'];
                if (!empty($params['to']['addressString'])) {
                    $address .= ' ' . $params['to']['addressString'];
                }
                $rates = array();
                $cached_rates_to_door = new waVarExportCache($this->getSessionKey('rates_to_door'), $this->getSessionTtl(), 'shipping_calcapiship');
                $rates_to_door = $cached_rates_to_door->get();
                if (!empty($rates_to_door)) {
                    foreach ($rates_to_door as $rate_key => $rate_value) {
                        if (!empty($rate_value['comment'])) {
                            $rates[$rate_key] = $rate_value['comment'];
                        }
                        if (is_numeric($rate_value['rate'])) {
                            $rates[$rate_key]['delivery_cost'] = $this->convertRatesCost($rate_value['rate'], null, null, true);
                        }
                        if (!empty($rate_value['est_delivery'])) {
                            $rates[$rate_key]['delivery_time'] = $this->convertRatesEstimatedDeliveryDateToString($rate_value['est_delivery']);
                        }
                    }
                }
                $cached_rates_to_point = new waVarExportCache($this->getSessionKey('rates_to_point'), $this->getSessionTtl(), 'shipping_calcapiship');
                $rates_to_point = $cached_rates_to_point->get();
                if (!empty($rates_to_point)) {
                    foreach ($rates_to_point as $rate_key => $rate_value) {
                        if (!empty($rate_value['comment'])) {
                            $rates[$rate_key] = $rate_value['comment'];
                        }
                        if (is_numeric($rate_value['rate'])) {
                            $rates[$rate_key]['delivery_cost'] = $this->convertRatesCost($rate_value['rate'], null, null, true);
                            $rates[$rate_key]['cost'] = $rate_value['rate'];
                        }
                        if (!empty($rate_value['est_delivery'])) {
                            $rates[$rate_key]['delivery_time'] = $this->convertRatesEstimatedDeliveryDateToString($rate_value['est_delivery']);
                        }
                        if (!empty($rate_value['custom_data']['provider_key'])) {
                            $rates[$rate_key]['provider_key'] = $rate_value['custom_data']['provider_key'];
                        }
                        if (!empty($rate_value['custom_data']['type'])) {
                            $rates[$rate_key]['type'] = $rate_value['custom_data']['type'];
                        }
                        if (!empty($rate_value['custom_data']['lat'])) {
                            $rates[$rate_key]['lat'] = $rate_value['custom_data']['lat'];
                        }
                        if (!empty($rate_value['custom_data']['lng'])) {
                            $rates[$rate_key]['lng'] = $rate_value['custom_data']['lng'];
                        }
                        if (is_numeric($rate_value['custom_data']['days'][0])) {
                            $rates[$rate_key]['time'] = $rate_value['custom_data']['days'][0];
                        }
                    }
                }
                if (!empty($rates)) {
                    $data = array(
                        'address' => $address,
                        'rates' => $rates,
                    );
                    $this->sendJsonSuccess($data);
                }
            }
            $this->sendJsonFailure(array($this->_w('No suitable options')));
        }
        $this->sendJsonFailure(array($this->_w('Something has gone wrong')));
    }

    /**
     * Clear a cache
     * @return void
     * @throws waException
     */
    private function clearingCache()
    {
        $current_time = waDateTime::date('U');
        $folder = waSystem::getInstance()->getCachePath('cache', 'shipping_calcapiship');
        $files = waFiles::listdir($folder);
        if (!empty($files)) {
            foreach ($files as $file) {
                $path = $folder . '/' . $file;
                if (file_exists($path)) {
                    $created_time = filemtime($path);
                    if (!empty($created_time)) {
                        if (strpos($file, 'cache') !== false) {
                            $cache_ttl = $this->getCacheTtl();
                            if (($current_time - $created_time) > $cache_ttl) {
                                waFiles::delete($path);
                            }
                        }
                        if (strpos($file, 'session') !== false) {
                            $session_ttl = $this->getSessionTtl();
                            if (($current_time - $created_time) > $session_ttl) {
                                waFiles::delete($path);
                            }
                        }
                        if (
                            strpos($file, 'cache') !== false &&
                            strpos($file, 'session') !== false
                        ) {
                            if (($current_time - $created_time) > 24 * 60 * 60) {
                                waFiles::delete($path);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns point types stored in the cache
     * @return array Point types
     * @throws waException
     */
    private function cachingPointTypes()
    {
        $point_types = array();
        $cache = new waVarExportCache($this->getCacheKey('point_types'), $this->getCacheTtl(), 'shipping_calcapiship');
        if ($cache->isCached()) {
            $point_types = $cache->get();
        } else {
            $point_types = $this->getPointTypes();
            $cache->set($point_types);
        }
        return $point_types;
    }

    /**
     * Returns points stored in the cache
     * @param array $provider_keys Provider keys
     * @param string $country_code Country code
     * @param string $city City
     * @return array Points
     * @throws waException
     */
    private function cachingPoints($provider_keys, $country_code, $city)
    {
        $points = array();
        $cache_key = 'points' . json_encode($provider_keys) . $country_code . $city;
        $cache = new waVarExportCache($this->getCacheKey($cache_key), $this->getCacheTtl(), 'shipping_calcapiship');
        if ($cache->isCached()) {
            $points = $cache->get();
        } else {
            $points = $this->getPoints($provider_keys, $country_code, $city);
            $cache->set($points);
        }
        return $points;
    }

    /**
     * Returns a cache key
     * @param string $key Key
     * @return string Cache key
     */
    private function getCacheKey($key = null)
    {
        $cache_key = 'cache_' . md5($this->id . $this->key . $key);
        return $cache_key;
    }

    /**
     * Returns a session key
     * @param string $key Key
     * @return string Session key
     */
    private function getSessionKey($key = null)
    {
        $session_key = 'session_' . md5(session_id() . $this->id . $this->key . $key);
        return $session_key;
    }

    /**
     * Returns a cache ttl in seconds
     * @return string Cache ttl
     */
    private function getCacheTtl()
    {
        $cache_ttl = 12 * 60 * 60;
        return $cache_ttl;
    }

    /**
     * Returns a session ttl in seconds
     * @return string Session ttl
     */
    private function getSessionTtl()
    {
        $session_ttl = 30 * 60;
        return $session_ttl;
    }

    /**
     * Returns a list of the service API shipping cost and time
     * @param array $params Request body
     * @return array Shipping cost and time
     * @throws waException
     */
    private function getCalculator($params)
    {
        $response = $this->sendRequest('calculator', $params, waNet::METHOD_POST);
        if (empty($response)) {
            return array();
        }
        return $response;
    }

    /**
     * Returns a service API token and its expiration date
     * @return array|null Token and its expiration date, NULL otherwise
     * @throws waException
     */
    private function getToken()
    {
        $response = $this->sendRequest('login', json_encode(array(
            'login' => $this->username,
            'password' => $this->password
        )), waNet::METHOD_POST);
        if (!empty($response['code'])) {
            return null;
        }
        return $response;
    }

    /**
     * Returns a list of the service API providers
     * @return array Providers
     * @throws waException
     */
    private function getProviders()
    {
        $response = $this->sendRequest('lists/providers');
        if (empty($response['rows'])) {
            return array();
        }
        return $response['rows'];
    }

    /**
     * Returns a list of the service API delivery types
     * @return array Delivery types
     * @throws waException
     */
    private function getDeliveryTypes()
    {
        $response = $this->sendRequest('lists/deliveryTypes');
        if (empty($response)) {
            return array();
        }
        foreach ($response as $index => $delivery_type) {
            if ($delivery_type['id'] == 1) {
                $response[$index]['name'] = $this->_w('Courier');
            }
//            if ($delivery_type['id'] == 2) {
//                $response[$index]['name'] = $this->_w('Pickup');
//            }
        }
        return $response;
    }

    /**
     * Returns a list of the service API pickup types
     * @return array Pickup types
     * @throws waException
     */
    private function getPickupTypes()
    {
        $response = $this->sendRequest('lists/pickupTypes');
        if (empty($response)) {
            return array();
        }
        return $response;
    }

    /**
     * Returns a list of the service API point types
     * @return array Point types
     * @throws waException
     */
    private function getPointTypes()
    {
        $response = $this->sendRequest('lists/pointTypes');
        if (empty($response)) {
            return array();
        }
        return $response;
    }

    /**
     * Returns a list of the service API points
     * @param array $provider_keys Provider keys
     * @param string $country_code Country code
     * @param string $city City
     * @return array Points
     * @throws waException
     */
    private function getPoints($provider_keys, $country_code, $city)
    {
        $points = array();
        $fields = array(
            'id',
            'providerKey',
            'type',
            'cod',
            'paymentCard',
            'name',
            'lat',
            'lng',
            'code',
            'postIndex',
            'countryCode',
            'region',
            'regionType',
            'area',
            'city',
            'cityType',
            'street',
            'streetType',
            'house',
            'block',
            'office',
            'url',
            'phone',
            'timetable',
            'fittingRoom',
            'description',
        );
        $filter = array();
        if (!empty($provider_keys)) {
            array_push($filter, 'providerKey=' . json_encode($provider_keys));
        }
        if (!empty($country_code)) {
            array_push($filter, 'countryCode=' . $country_code);
        }
        if (!empty($city)) {
            array_push($filter, 'city=' . $city);
        }
        $limit = 1000;
        $offset = 0;
        $response = $this->sendRequest('lists/points', array(
            'fields' => implode(',', $fields),
            'filter' => implode(';', $filter),
            'limit' => $limit,
            'offset' => $offset,
        ));
        if (!empty($response['rows'])) {
            $points = $response['rows'];
        }
        if (!empty($response['meta']['total'])) {
            $pages = ceil($response['meta']['total'] / $limit);
            if ($response['meta']['total'] > $limit) {
                for ($i = 2; $i <= $pages; $i++) {
                    $offset = ($i - 1) * $limit;
                    $response = $this->sendRequest('lists/points', array(
                        'filter' => implode(';', $filter),
                        'limit' => $limit,
                        'offset' => $offset,
                    ));
                    if (!empty($response['rows'])) {
                        foreach ($response['rows'] as $point) {
                            array_push($points, $point);
                        }
                    }
                }
            }
        }
        return $points;
    }

    /**
     * Returns a list of the service API tariffs
     * Note that the limit param does not work correctly in the test mode,
     * what makes pages to contain a different number of the items
     * @param string $provider_key Provider key
     * @param integer $page Page
     * @return array Tariffs and a total number of the pages
     * @throws waException
     */
    private function getTariffs($provider_key, $page = 1)
    {
        $tariffs = array();
        if (!empty($provider_key)) {
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $response = $this->sendRequest('lists/tariffs', array(
                'filter' => 'providerKey=' . $provider_key,
                'limit' => $limit,
                'offset' => $offset,
            ));
            if (!empty($response['rows'])) {
                $tariffs['items'] = $response['rows'];
            }
            if (!empty($response['meta']['total'])) {
                $tariffs['pages'] = ceil($response['meta']['total'] / $limit);
            }
        }
        return $tariffs;
    }

    /**
     * Sends a request to the service API
     * @param string $endpoint API endpoint
     * @param array $params Request body
     * @param string $method HTTP method
     * @return mixed Response from the service API stored in the cache
     * @throws waException
     */
    private function sendRequest($endpoint, $params = array(), $method = waNet::METHOD_GET)
    {
        if (
            empty($this->token) &&
            $endpoint != 'login'
        ) {
            return null;
        }
        $net = new waNet(array(
            'format' => waNet::FORMAT_JSON,
        ), array(
            'Authorization' => ifempty($this->token['accessToken']),
        ));
        if (!empty($this->test_mode)) {
            $url = $this->test_mode_url . $endpoint;
        } else {
            if (!empty($this->unsafe_mode)) {
                $url = $this->unsafe_url . $endpoint;
            } else {
                $url = $this->url . $endpoint;
            }
        }
        try {
            return $net->query($url, $params, $method);
        } catch (Exception $e) {
            waLog::dump(array(
                'url' => $url,
                'params' => $this->convertJson($params),
                'error' => $e->getMessage(),
                'items' => $this->convertJson($this->getItems()),
            ), 'wa-plugins/shipping/calcapiship/request.log');
        }
        return null;
    }

    /**
     * Sends JSON response
     * @param array $response Response
     * @return void
     * @throws waException
     */
    private function sendJson($response)
    {
        wa()->getResponse()->addHeader('Content-Type', 'application/json')->sendHeaders();
        echo $this->convertJson($response);
        exit;
    }

    /**
     * Sends a success JSON response
     * @param mixed $data Data
     * @return void
     * @throws waException
     */
    private function sendJsonSuccess($data)
    {
        $response = array(
            'status' => 'ok',
            'data' => $data,
        );
        $this->sendJson($response);
    }

    /**
     * Sends a failure JSON response
     * @param array $errors Errors
     * @return void
     * @throws waException
     */
    private function sendJsonFailure($errors)
    {
        $response = array(
            'status' => 'fail',
            'errors' => $errors,
        );
        $this->sendJson($response);
    }

    /**
     * Converts a data to JSON
     * @param mixed $data Data
     * @return string JSON
     */
    private function convertJson($data)
    {
        $json = json_encode($data);
        if (version_compare(phpversion(), '5.4') >= 0) {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        return $json;
    }

    /**
     * Converts special characters to HTML entities
     * @param array|string $data Data
     * @return array|string Converted data
     */
    private function convertData($data)
    {
        if (is_array($data)) {
            $converted_data = array();
            foreach ($data as $key => $value) {
                $converted_data[$key] = $this->convertData($value);
            }
        } else {
            $converted_data = trim(htmlspecialchars($data, ENT_NOQUOTES));
        }
        return $converted_data;
    }

    /**
     * Converts address Shop-Script to ApiShip
     * @param array $address Shop-Script address
     * @return array Converted ApiShip address
     * @throws waException
     */
    private function convertAddress($address)
    {
        $address_parts = array();
        $country_model = new waCountryModel();
        $country = $country_model->getByField('iso3letter', $address['country']);
        if (!empty($country['iso2letter'])) {
            $converted_address['countryCode'] = strtoupper($country['iso2letter']);
        }
        if (!empty($country['name'])) {
            array_push($address_parts, _ws($country['name']));
        }
        $region_model = new waRegionModel();
        $region = $region_model->getByField(array(
            'country_iso3' => $address['country'],
            'code' => $address['region'],
        ));
        if (!empty($region['name'])) {
            $converted_address['region'] = $region['name'];
            array_push($address_parts, $region['name']);
        } else {
            $converted_address['region'] = $address['region'];
            array_push($address_parts, $address['region']);
        }
        $city = $address['city'];
        $city = trim($city);
        $city = mb_strtolower($city, 'UTF-8');
        $city = preg_replace('/\s+/', ' ', $city);
        $city = str_replace(' - ', '-', $city);
        $city = str_replace('.', '', $city);
        $city = str_ireplace(array(
            'город ',
            'гор ',
            'г ',
        ), '', $city);
        $converted_address['city'] = $city;
        if (mb_strtolower($converted_address['city']) != mb_strtolower($converted_address['region'])) {
            array_push($address_parts, $city);
        }
        if (!empty($address['street'])) {
            array_push($address_parts, $address['street']);
        }
        $converted_address['addressString'] = implode(', ', $address_parts);
        return $converted_address;
    }

    /**
     * Converts rates ApiShip to Shop-Script
     * @param array $rates ApiShip rates
     * @return array Converted Shop-Script rates
     */
    private function convertRates($rates)
    {
        $converted_rates = array();
        foreach ($rates as $rate_key => $rate_value) {
            $converted_rate = array();
            foreach ($rate_value as $field_key => $field_value) {
                if ($field_key == 'name') {
                    $converted_rate[$field_key] = $this->convertRatesNameToString($field_value);
                } else if ($field_key == 'comment') {
//                    $converted_rate[$field_key] = $this->convertRatesCommentToString($field_value);
                    $converted_rate[$field_key] = '';
                } else if ($field_key == 'est_delivery') {
                    $converted_rate[$field_key] = $this->convertRatesEstimatedDeliveryDateToString($field_value);
                } else {
                    $converted_rate[$field_key] = $field_value;
                }
            }
            $converted_rates[$rate_key] = $converted_rate;
        }
        return $converted_rates;
    }

    /**
     * Converts "to door" rates ApiShip to Shop-Script
     * @param array $rates ApiShip rates
     * @return array Converted Shop-Script "to door" rates
     * @throws waException
     * @todo rates depending on the services, feesIncluded, insuranceFee, cashServiceFee
     */
    private function convertRatesToDoor($rates)
    {
        $converted_rates = array();
        foreach ($rates as $rate) {
            if (!empty($rate['tariffs'])) {
                $tariffs = $this->providers[$rate['providerKey']]['tariffs'];
                foreach ($rate['tariffs'] as $tariff) {
                    if (in_array($tariff['tariffId'], $tariffs)) {
                        $comment = $this->convertRatesComment($rate['providerKey'], $tariff);
                        $name = $this->convertRatesName($comment);
                        $id = 'tariff-' . $tariff['tariffId'];
                        $converted_rates[$id] = array(
                            'rate' => $this->convertRatesRulesCost($rate['providerKey'], $tariff['deliveryCost']),
                            'name' => $name,
//                            'description' => $comment,
                            'comment' => $comment,
                            'est_delivery' => $this->convertRatesEstimatedDeliveryDate($rate['providerKey'], $tariff),
                            'currency' => $this->allowedCurrency(),
//                            'custom_data' => array(
//                                'tariff' => $tariff,
//                            ),
                        );
                        if (
                            defined('self::TYPE_TODOOR') &&
                            $rate['providerKey'] != 'rupost'
                        ) {
                            $converted_rates[$id]['type'] = self::TYPE_TODOOR;
                            $converted_rates[$id]['delivery_date'] = $this->convertRatesEstimatedDeliveryDate($rate['providerKey'], $tariff, 'fulldatetime');
//                            $converted_rates[$id]['service'] = $this->convertRatesNameToString($name);
                            $converted_rates[$id]['custom_data'][self::TYPE_TODOOR] = array(
                                'id' => $id,
                            );
                        }
                        if (
                            defined('self::TYPE_POST') &&
                            $rate['providerKey'] == 'rupost'
                        ) {
                            $converted_rates[$id]['type'] = self::TYPE_POST;
                            $converted_rates[$id]['delivery_date'] = $this->convertRatesEstimatedDeliveryDate($rate['providerKey'], $tariff, 'fulldatetime');
//                            $converted_rates[$id]['service'] = $this->convertRatesNameToString($name);
                        }
                    }
                }
            }
        }
        return $converted_rates;
    }

    /**
     * Converts "to point" rates ApiShip to Shop-Script
     * @param array $rates ApiShip rates
     * @return array Converted Shop-Script "to point" rates
     * @throws waException
     * @todo rates depending on the services, feesIncluded, insuranceFee, cashServiceFee
     */
    private function convertRatesToPoint($rates)
    {
        $converted_rates = array();
        foreach ($rates as $rate) {
            if (!empty($rate['tariffs'])) {
                $tariffs = $this->providers[$rate['providerKey']]['tariffs'];
                foreach ($rate['tariffs'] as $tariff) {
                    if (in_array($tariff['tariffId'], $tariffs)) {
                        if (!empty($tariff['pointIds'])) {
                            foreach ($tariff['pointIds'] as $point_id) {
                                if (!empty($this->points)) {
                                    foreach ($this->points as $point) {
                                        if ($point['id'] == $point_id) {
                                            $comment = $this->convertRatesComment($rate['providerKey'], $tariff, $point);
                                            $name = $this->convertRatesName($comment);
                                            $id = 'tariff-' . $tariff['tariffId'] . '_point-' . $point_id;
                                            $converted_rates[$id] = array(
                                                'rate' => $this->convertRatesRulesCost($rate['providerKey'], $tariff['deliveryCost']),
                                                'name' => $name,
//                                                'description' => $comment,
                                                'comment' => $comment,
                                                'est_delivery' => $this->convertRatesEstimatedDeliveryDate($rate['providerKey'], $tariff),
                                                'currency' => $this->allowedCurrency(),
                                                'custom_data' => array(
//                                                    'tariff' => $tariff,
//                                                    'point' => $point,
                                                    'provider_key' => $rate['providerKey'],
                                                    'type' => $point['type'],
                                                    'lat' => $point['lat'],
                                                    'lng' => $point['lng'],
                                                    'days' => $this->convertRatesEstimatedDeliveryDays($rate['providerKey'], $tariff),
//                                                    'payment' => $this->convertRatesPayment($point['cod']),
//                                                    'payment_card' => $this->convertRatesPaymentCard($point['paymentCard']),
//                                                    'fitting_room' => $this->convertRatesFittingRoom($point['fittingRoom']),
                                                ),
                                            );
                                            if (defined('self::TYPE_PICKUP')) {
                                                $converted_rates[$id]['type'] = self::TYPE_PICKUP;
                                                $converted_rates[$id]['delivery_date'] = $this->convertRatesEstimatedDeliveryDate($rate['providerKey'], $tariff, 'fulldatetime');
//                                                $converted_rates[$id]['service'] = $this->convertRatesNameToString($name);
                                                $address_parts = $this->convertRatesAddress($point);
                                                $address = $this->convertRatesAddressToString($address_parts);
                                                $converted_rates[$id]['custom_data'][self::TYPE_PICKUP] = array(
                                                    'id' => $id,
                                                    'lat' => $point['lat'],
                                                    'lng' => $point['lng'],
                                                    'name' => $this->convertRatesNameToString($name),
                                                    'description' => $address,
                                                    'way' => $point['description'],
                                                    'schedule' => $point['timetable'],
//                                                    'additional' => '',
//                                                    'timezone' => '',
//                                                    'payment' => '',
//                                                    'photos' => '',
//                                                    'storage' => '',
//                                                    'interval' => '',
                                                );
                                            }
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $converted_rates;
    }

    /**
     * Converts rate's name ApiShip to Shop-Script
     * @param array $comment Comment
     * @return array Converted Shop-Script rate's name
     * @throws waException
     */
    private function convertRatesName($comment)
    {
        $converted_name = array();
        $converted_name_fields = array(
            'delivery_type',
            'provider',
            'point',
            'tariff',
        );
        if (wa()->getEnv() == 'frontend') {
            $converted_name_fields = array(
                'delivery_type',
                'provider',
                'point',
            );
            if (
                !empty($comment['delivery_type']) &&
                $comment['delivery_type'] == $this->_w('Post')
            ) {
                $converted_name_fields = array(
                    'provider',
                    'tariff',
                );
            }
        }
        foreach ($comment as $comment_key => $comment_value) {
            $converted_name_index = array_search($comment_key, $converted_name_fields);
            if (
                $converted_name_index !== false &&
                !empty($comment_value)
            ) {
                array_splice($converted_name, $converted_name_index, 0, $comment_value);
            }
        }
        return $converted_name;
    }

    /**
     * Converts rate's name array to string
     * @param array $name Name
     * @return string Converted name
     */
    private function convertRatesNameToString($name)
    {
        $converted_name = implode(' | ', $name);
        return $converted_name;
    }

    /**
     * Converts rate's comment ApiShip to Shop-Script
     * @param string $provider_key Provider key
     * @param array $tariff ApiShip tariff
     * @param array|null $point ApiShip point
     * @return array Converted Shop-Script rate's comment
     * @throws waException
     */
    private function convertRatesComment($provider_key, $tariff, $point = null)
    {
        $converted_comment = array();
        if (!empty($tariff['deliveryTypes'][0])) {
            if (array_key_exists($tariff['deliveryTypes'][0], $this->delivery_types)) {
                $converted_comment['delivery_type'] = $this->delivery_types[$tariff['deliveryTypes'][0]]['name'];
            }
        }
        if ($provider_key == 'rupost') {
            $converted_comment['delivery_type'] = $this->_w('Post');
        }
        if (array_key_exists($provider_key, $this->providers)) {
            $converted_comment['provider'] = $this->providers[$provider_key]['name'];
        }
        $converted_comment['tariff'] = $tariff['tariffName'];
        if (!empty($point)) {
            $converted_comment['point'] = $point['name'];
            if ($provider_key == 'dpd') {
                $additional_address_parts = $this->convertRatesAddressAdditional($point);
                $additional_address = $this->convertRatesAddressToStringAdditional($additional_address_parts);
                $converted_comment['point'] .= ' - ' . $additional_address;
            }
            if ($provider_key == 'iml') {
                $additional_address_parts = $this->convertRatesAddressAdditional($point);
                $additional_address = $this->convertRatesAddressToStringAdditional($additional_address_parts);
                $converted_comment['point'] = $additional_address;
            }
            $point_type = '';
            foreach ($this->point_types as $point_type) {
                if ($point_type['id'] == $point['type']) {
                    $point_type = $point_type['description'];
                    break;
                }
            }
            $converted_comment['point_type'] = $point_type;
            $converted_comment['point_code'] = $point['code'];
            if ($provider_key == 'iml') {
                $point_iml_id = preg_replace('/[^0-9]/', '', $point['url']);
                $converted_comment['point_iml_id'] = $point_iml_id;
            }
            $address_parts = $this->convertRatesAddress($point);
            $address = $this->convertRatesAddressToString($address_parts);
            $converted_comment['address'] = $address;
            $phone = '';
            if (
                !empty($point['phone']) &&
                $point['phone'] != 'null'
            ) {
                $phone = $point['phone'];
            }
            $converted_comment['phone'] = $phone;
            $converted_comment['timetable'] = $point['timetable'];
            $converted_comment['description'] = $point['description'];
            $converted_comment['payment'] = $this->convertRatesPayment($point['cod']);
            $converted_comment['payment_card'] = $this->convertRatesPaymentCard($point['paymentCard']);
            $converted_comment['fitting_room'] = $this->convertRatesFittingRoom($point['fittingRoom']);
        }
        return $converted_comment;
    }

    /**
     * Converts rate's comment array to string
     * @param array $comment Comment
     * @return string Converted comment
     */
    public function convertRatesCommentToString($comment)
    {
        $comment_parts = array();
        foreach ($comment as $comment_key => $comment_value) {
            if (!empty($comment_value)) {
                $comment_part = array();
                if ($comment_key == 'delivery_type') {
                    $comment_part = array(
                        'title' => $this->_w('Delivery type'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'provider') {
                    $comment_part = array(
                        'title' => $this->_w('Provider'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'tariff') {
                    $comment_part = array(
                        'title' => $this->_w('Tariff'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'point') {
                    $comment_part = array(
                        'title' => $this->_w('Point'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'point_type') {
                    $comment_part = array(
                        'title' => $this->_w('Point type'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'point_code') {
                    $comment_part = array(
                        'title' => $this->_w('Point code'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'address') {
                    $comment_part = array(
                        'title' => $this->_w('Address'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'phone') {
                    $comment_part = array(
                        'title' => $this->_w('Phone'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'timetable') {
                    $comment_part = array(
                        'title' => $this->_w('Timetable'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'description') {
                    $comment_part = array(
                        'title' => $this->_w('Description'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'payment') {
                    $comment_part = array(
                        'title' => $this->_w('Payment'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'payment_card') {
                    $comment_part = array(
                        'title' => $this->_w('Payment card'),
                        'value' => $comment_value,
                    );
                }
                if ($comment_key == 'fitting_room') {
                    $comment_part = array(
                        'title' => $this->_w('Fitting room'),
                        'value' => $comment_value,
                    );
                }
                if (!empty($comment_part)) {
                    array_push($comment_parts, '<b>' . $comment_part['title'] . '</b>: ' . $comment_part['value']);
                }
            }
        }
        $converted_comment = implode('<br>', $comment_parts);
        return $converted_comment;
    }

    /**
     * Converts rate's estimated delivery days ApiShip to Shop-Script
     * @param string $provider_key Provider key
     * @param array $tariff ApiShip tariff
     * @return array Converted Shop-Script rate's estimated delivery days
     * @throws waException
     */
    private function convertRatesEstimatedDeliveryDays($provider_key, $tariff)
    {
        $converted_days = array(
            intval($this->convertRatesRulesMinTime($provider_key, $tariff['daysMin'])),
            intval($this->convertRatesRulesMaxTime($provider_key, $tariff['daysMax'])),
        );
        sort($converted_days);
        return $converted_days;
    }

    /**
     * Converts rate's estimated delivery date ApiShip to Shop-Script
     * @param string $provider_key Provider key
     * @param array $tariff ApiShip tariff
     * @param string $format Date format
     * @return array Converted Shop-Script rate's estimated delivery date
     * @throws waException
     */
    private function convertRatesEstimatedDeliveryDate($provider_key, $tariff, $format = 'humandate')
    {
        $converted_days = $this->convertRatesEstimatedDeliveryDays($provider_key, $tariff);
        $base_timestamp = $this->getNearestWorkingTimestamp();
        $converted_date = array(
            waDateTime::format($format, strtotime('+' . $converted_days[0] . ' days', $base_timestamp)),
            waDateTime::format($format, strtotime('+' . $converted_days[1] . ' days', $base_timestamp)),
        );
        $converted_date = array_unique($converted_date);
        return $converted_date;
    }

    /**
     * Returns the timestamp of the nearest working day according to storefront settings
     *
     * @param int|null $timestamp
     * @return int
     */
    private function getNearestWorkingTimestamp($timestamp = null)
    {
        if ($timestamp === null) {
            $timestamp = time();
        }

        $limit = 31;
        while (!$this->isStorefrontWorkingDay($timestamp) && $limit > 0) {
            $timestamp = strtotime('+1 day', $timestamp);
            $limit--;
        }

        return $timestamp;
    }

    /**
     * Checks whether storefront schedule marks timestamp day as working
     *
     * @param int $timestamp
     * @return bool
     */
    private function isStorefrontWorkingDay($timestamp)
    {
        static $schedule = null;

        if ($schedule === null) {
            $schedule = $this->getStorefrontSchedule();
        }

        $date = waDateTime::date('Y-m-d', $timestamp);
        if (!empty($schedule['dates']) && is_array($schedule['dates']) && array_key_exists($date, $schedule['dates'])) {
            $date_status = $this->isWorkingDayEntry($schedule['dates'][$date]);
            if ($date_status !== null) {
                return $date_status;
            }
        }

        $weekday = (int) waDateTime::date('w', $timestamp);

        $weekend_days = $this->getStorefrontWeekendDays();
        if (!empty($weekend_days) && in_array($weekday, $weekend_days, true)) {
            return false;
        }

        if (!empty($schedule['days']) && is_array($schedule['days']) && array_key_exists($weekday, $schedule['days'])) {
            $day_status = $this->isWorkingDayEntry($schedule['days'][$weekday]);
            if ($day_status !== null) {
                return $day_status;
            }
        }

        return true;
    }

    /**
     * Determines if schedule entry marks day as working
     *
     * @param mixed $entry
     * @return bool|null True for working day, false for weekend, null if undefined
     */
    private function isWorkingDayEntry($entry)
    {
        if (is_array($entry)) {
            if (array_key_exists('weekend', $entry)) {
                return !(bool) $entry['weekend'];
            }
            if (array_key_exists('status', $entry)) {
                return $entry['status'] !== 'weekend';
            }
        }

        return null;
    }

    /**
     * Returns storefront weekend days (0-6 with Sunday = 0)
     *
     * @return array
     */
    private function getStorefrontWeekendDays()
    {
        static $weekend_days = null;

        if ($weekend_days !== null) {
            return $weekend_days;
        }

        $weekend_days = array(0, 6);

        $schedule = $this->getStorefrontSchedule();
        if (!empty($schedule)) {
            $detected_weekends = array();
            if (!empty($schedule['weekends']) && is_array($schedule['weekends'])) {
                $detected_weekends = $schedule['weekends'];
            } elseif (!empty($schedule['workdays']) && is_array($schedule['workdays'])) {
                $all_days = range(0, 6);
                $workdays = array();
                foreach ($schedule['workdays'] as $day) {
                    $workdays[] = $this->normalizeWeekday($day);
                }
                $detected_weekends = array_diff($all_days, $workdays);
            } elseif (!empty($schedule['days']) && is_array($schedule['days'])) {
                foreach ($schedule['days'] as $day_index => $day_data) {
                    $is_weekend = false;
                    if (is_array($day_data)) {
                        if (isset($day_data['weekend'])) {
                            $is_weekend = (bool) $day_data['weekend'];
                        } elseif (isset($day_data['status'])) {
                            $is_weekend = ($day_data['status'] === 'weekend');
                        }
                    }
                    if ($is_weekend) {
                        $detected_weekends[] = $this->normalizeWeekday($day_index);
                    }
                }
            }

            if (!empty($detected_weekends)) {
                $weekend_days = array_values(array_unique(array_map(array($this, 'normalizeWeekday'), $detected_weekends)));
                sort($weekend_days);
            }
        }

        return $weekend_days;
    }

    /**
     * Returns storefront schedule array
     *
     * @return array
     */
    private function getStorefrontSchedule()
    {
        static $cache = array();

        $storefront = $this->getStorefrontIdentifier();
        if (empty($storefront) || !class_exists('waModel')) {
            return array();
        }

        if (array_key_exists($storefront, $cache)) {
            return $cache[$storefront];
        }

        $cache[$storefront] = array();

        try {
            $model = new waModel();
            $sql = 'SELECT value FROM shop_storefront_settings WHERE storefront = s:storefront AND name = s:name';
            $value = $model->query($sql, array('storefront' => $storefront, 'name' => 'schedule'))->fetchField();
            if (!empty($value)) {
                $decoded = @json_decode($value, true);
                if (is_array($decoded)) {
                    $cache[$storefront] = $decoded;
                    return $cache[$storefront];
                }
            }
        } catch (Exception $e) {
            // ignore database errors
        }

        return $cache[$storefront];
    }

    /**
     * Returns storefront identifier used in shop_storefront_settings
     *
     * @return string|null
     */
    private function getStorefrontIdentifier()
    {
        $storefront = $this->getPackageProperty('storefront');
        if (!empty($storefront)) {
            return $storefront;
        }

        $storefront = waRequest::param('storefront');
        if (!empty($storefront)) {
            return $storefront;
        }

        return null;
    }

    /**
     * Normalizes weekday value to 0-6 range
     *
     * @param int|string $day
     * @return int
     */
    private function normalizeWeekday($day)
    {
        $day = (int) $day;
        $day = (($day % 7) + 7) % 7;
        return $day;
    }

    /**
     * Converts rate's estimated delivery date array to string
     * @param array $date Estimated delivery date
     * @return string Converted estimated delivery date
     */
    private function convertRatesEstimatedDeliveryDateToString($date)
    {
        $converted_date = implode(' - ', $date);
        return $converted_date;
    }

    /**
     * Converts rate's cost ApiShip to Shop-Script
     * @param string|integer|float $cost ApiShip cost
     * @param string $current_currency Current currency
     * @param string $converted_currency Converted currency
     * @param boolean $to_string Converts the cost to the string
     * @return string Converted Shop-Script rate's cost
     * @throws waException
     */
    private function convertRatesCost($cost, $current_currency = null, $converted_currency = null, $to_string = false)
    {
        $converted_cost = $cost;
        if (empty($current_currency)) {
            $current_currency = $this->allowedCurrency();
        }
        if (empty($converted_currency)) {
            $converted_currency = wa()->getConfig()->getCurrency();
        }
        $primary_currency = wa()->getConfig()->getCurrency(true);
        if ($current_currency != $converted_currency) {
            $currencies = wa()->getConfig()->getCurrencies(array(
                $current_currency,
                $converted_currency,
            ));
            if ($current_currency != $primary_currency) {
                $converted_cost = $cost * $currencies[$current_currency]['rate'];
            }
            if ($converted_currency != $primary_currency) {
                $converted_cost = $cost / $currencies[$converted_currency]['rate'];
            }
        }
        $info = waCurrency::getInfo($converted_currency);
        if (!empty($info['precision'])) {
            $converted_cost = round($converted_cost, $info['precision']);
        }
        if (!empty($to_string)) {
            $converted_cost = wa_currency_html($converted_cost, $converted_currency);
        }
        return $converted_cost;
    }

    /**
     * Converts rate's payment ApiShip to Shop-Script
     * @param mixed $payment ApiShip payment
     * @param null|boolean $true_to_string Converts the payment to the string (null:digit; false:word; true:icon)
     * @param null|boolean $false_to_string Converts the payment to the string (null:digit; false:word; true:icon)
     * @return string|integer Converted Shop-Script rate's payment
     * @throws waException
     */
    private function convertRatesPayment($payment, $true_to_string = null, $false_to_string = null)
    {
        if (is_null($false_to_string)) {
            $converted_payment = 0;
        } else {
            if (empty($false_to_string)) {
                $converted_payment = $this->_w('Is absent');
            } else {
                $converted_payment = '<img src="' . wa()->getUrl(true) . 'wa-plugins/shipping/calcapiship/img/payment_failure.svg" width="16" height="16" border="0" title="' . $this->_w('Payment') . ': ' . $this->_w('Is absent') . '">';
            }
        }
        if ($payment == 1) {
            if (is_null($true_to_string)) {
                $converted_payment = $payment;
            } else {
                if (empty($true_to_string)) {
                    $converted_payment = $this->_w('Is presented');
                } else {
                    $converted_payment = '<img src="' . wa()->getUrl(true) . 'wa-plugins/shipping/calcapiship/img/payment_success.svg" width="16" height="16" border="0" title="' . $this->_w('Payment') . ': ' . $this->_w('Is presented') . '">';
                }
            }
        }
        return $converted_payment;
    }

    /**
     * Converts rate's payment card ApiShip to Shop-Script
     * @param mixed $payment_card ApiShip payment card
     * @param null|boolean $true_to_string Converts the payment card to the string (null:digit; false:word; true:icon)
     * @param null|boolean $false_to_string Converts the payment card to the string (null:digit; false:word; true:icon)
     * @return string|integer Converted Shop-Script rate's payment card
     * @throws waException
     */
    private function convertRatesPaymentCard($payment_card, $true_to_string = null, $false_to_string = null)
    {
        if (is_null($false_to_string)) {
            $converted_payment_card = 0;
        } else {
            if (empty($false_to_string)) {
                $converted_payment_card = $this->_w('Is absent');
            } else {
                $converted_payment_card = '<img src="' . wa()->getUrl(true) . 'wa-plugins/shipping/calcapiship/img/payment_card_failure.svg" width="16" height="16" border="0" title="' . $this->_w('Payment card') . ': ' . $this->_w('Is absent') . '">';
            }
        }
        if ($payment_card == 1) {
            if (is_null($true_to_string)) {
                $converted_payment_card = $payment_card;
            } else {
                if (empty($true_to_string)) {
                    $converted_payment_card = $this->_w('Is presented');
                } else {
                    $converted_payment_card = '<img src="' . wa()->getUrl(true) . 'wa-plugins/shipping/calcapiship/img/payment_card_success.svg" width="16" height="16" border="0" title="' . $this->_w('Payment card') . ': ' . $this->_w('Is presented') . '">';
                }
            }
        }
        return $converted_payment_card;
    }

    /**
     * Converts rate's fitting room ApiShip to Shop-Script
     * @param mixed $fitting_room ApiShip fitting room
     * @param null|boolean $true_to_string Converts the fitting room to the string (null:digit; false:word; true:icon)
     * @param null|boolean $false_to_string Converts the fitting room to the string (null:digit; false:word; true:icon)
     * @return string|integer Converted Shop-Script rate's fitting room
     * @throws waException
     */
    private function convertRatesFittingRoom($fitting_room, $true_to_string = null, $false_to_string = null)
    {
        if (is_null($false_to_string)) {
            $converted_fitting_room = 0;
        } else {
            if (empty($false_to_string)) {
                $converted_fitting_room = $this->_w('Is absent');
            } else {
                $converted_fitting_room = '<img src="' . wa()->getUrl(true) . 'wa-plugins/shipping/calcapiship/img/fitting_room_failure.svg" width="16" height="16" border="0" title="' . $this->_w('Fitting room') . ': ' . $this->_w('Is absent') . '">';
            }
        }
        if ($fitting_room == 1) {
            if (is_null($true_to_string)) {
                $converted_fitting_room = $fitting_room;
            } else {
                if (empty($true_to_string)) {
                    $converted_fitting_room = $this->_w('Is presented');
                } else {
                    $converted_fitting_room = '<img src="' . wa()->getUrl(true) . 'wa-plugins/shipping/calcapiship/img/fitting_room_success.svg" width="16" height="16" border="0" title="' . $this->_w('Fitting room') . ': ' . $this->_w('Is presented') . '">';
                }
            }
        }
        return $converted_fitting_room;
    }

    /**
     * Converts rate's point address ApiShip to Shop-Script
     * @param array|null $point ApiShip point
     * @return array Converted Shop-Script rate's point address
     */
    private function convertRatesAddress($point)
    {
        $converted_address = array(
            implode(' ', array(
                $point['streetType'],
                $point['street'],
            )),
            $point['house'],
            $point['block'],
            $point['office'],
            implode(' ', array(
                $point['cityType'],
                $point['city'],
            )),
            $point['area'],
            implode(' ', array(
                $point['regionType'],
                $point['region'],
            )),
            $point['countryCode'],
            $point['postIndex'],
        );
        $converted_address = array_unique($converted_address);
        $converted_address = array_filter($converted_address, 'strlen');
        return $converted_address;
    }

    /**
     * Converts rate's point address array to string
     * @param array $address Point address
     * @return string Converted point address
     */
    private function convertRatesAddressToString($address)
    {
        $converted_address = implode(', ', $address);
        return $converted_address;
    }

    /**
     * Converts rate's point additional address ApiShip to Shop-Script
     * @param array|null $point ApiShip point
     * @return array Converted Shop-Script rate's point additional address
     */
    private function convertRatesAddressAdditional($point)
    {
        $converted_address = array(
            implode(' ', array(
                $point['streetType'],
                $point['street'],
            )),
            $point['house'],
            $point['block'],
            $point['office'],
        );
        $converted_address = array_unique($converted_address);
        $converted_address = array_filter($converted_address, 'strlen');
        return $converted_address;
    }

    /**
     * Converts rate's point additional address array to string
     * @param array $address Point address
     * @return string Converted point additional address
     */
    private function convertRatesAddressToStringAdditional($address)
    {
        $converted_address = implode(' ', $address);
        return $converted_address;
    }

    /**
     * Converts rate's cost according to the cost rules
     * @param string $provider_key Provider key
     * @param integer $cost Cost
     * @return integer Converted cost
     */
    private function convertRatesRulesCost($provider_key, $cost = 0)
    {
        $converted_cost = $cost;
        if (!empty($this->providers[$provider_key]['rules']['scheme'])) {
            foreach ($this->providers[$provider_key]['rules']['scheme'] as $index => $rule) {
                $rule_name = str_replace('scheme_', '', $rule);
                if (!empty($this->rules[$rule_name])) {
                    $rule_key_parts = explode('_', $rule_name);
                    if ($rule_key_parts[1] == 'cost') {
                        $method_name_part = $this->convertSnakeToStudly($rule_name);
                        $method_name = 'convertRatesRules' . $method_name_part;
                        if (method_exists($this, $method_name)) {
                            $converted_cost = $this->{$method_name}($cost, $converted_cost, $rule_name, array(
                                'scheme' => $this->providers[$provider_key]['rules']['scheme'][$index],
                                'value' => $this->providers[$provider_key]['rules']['value'][$index],
                                'measure' => $this->providers[$provider_key]['rules']['measure'][$index],
                                'percent' => $this->providers[$provider_key]['rules']['percent'][$index],
                            ));
                        }
                    }
                }
            }
        }
        return $converted_cost;
    }

    /**
     * Converts rate's minimum time according to the time rules
     * @param string $provider_key Provider key
     * @param integer $time Time
     * @return integer Converted time
     */
    private function convertRatesRulesMinTime($provider_key, $time = 0)
    {
        $converted_time = $time;
        if (!empty($this->providers[$provider_key]['rules']['scheme'])) {
            foreach ($this->providers[$provider_key]['rules']['scheme'] as $index => $rule) {
                $rule_name = str_replace('scheme_', '', $rule);
                if (!empty($this->rules[$rule_name])) {
                    $rule_key_parts = explode('_', $rule_name);
                    if (
                        $rule_key_parts[1] == 'min' &&
                        !empty($rule_key_parts[2]) &&
                        $rule_key_parts[2] == 'time'
                    ) {
                        $method_name_part = $this->convertSnakeToStudly($rule_name);
                        $method_name = 'convertRatesRules' . $method_name_part;
                        if (method_exists($this, $method_name)) {
                            $converted_time = $this->{$method_name}($time, $converted_time, $rule_name, array(
                                'scheme' => $this->providers[$provider_key]['rules']['scheme'][$index],
                                'value' => $this->providers[$provider_key]['rules']['value'][$index],
                                'measure' => $this->providers[$provider_key]['rules']['measure'][$index],
                                'percent' => $this->providers[$provider_key]['rules']['percent'][$index],
                            ));
                        }
                    }
                }
            }
        }
        return $converted_time;
    }

    /**
     * Converts rate's maximum time according to the time rules
     * @param string $provider_key Provider key
     * @param integer $time Time
     * @return integer Converted time
     */
    private function convertRatesRulesMaxTime($provider_key, $time = 0)
    {
        $converted_time = $time;
        if (!empty($this->providers[$provider_key]['rules']['scheme'])) {
            foreach ($this->providers[$provider_key]['rules']['scheme'] as $index => $rule) {
                $rule_name = str_replace('scheme_', '', $rule);
                if (!empty($this->rules[$rule_name])) {
                    $rule_key_parts = explode('_', $rule_name);
                    if (
                        $rule_key_parts[1] == 'max' &&
                        !empty($rule_key_parts[2]) &&
                        $rule_key_parts[2] == 'time'
                    ) {
                        $method_name_part = $this->convertSnakeToStudly($rule_name);
                        $method_name = 'convertRatesRules' . $method_name_part;
                        if (method_exists($this, $method_name)) {
                            $converted_time = $this->{$method_name}($time, $converted_time, $rule_name, array(
                                'scheme' => $this->providers[$provider_key]['rules']['scheme'][$index],
                                'value' => $this->providers[$provider_key]['rules']['value'][$index],
                                'measure' => $this->providers[$provider_key]['rules']['measure'][$index],
                                'percent' => $this->providers[$provider_key]['rules']['percent'][$index],
                            ));
                        }
                    }
                }
            }
        }
        return $converted_time;
    }

    /**
     * Converts rate's cost according to the cost rules "increase cost"
     * @param integer $cost Cost
     * @param integer $converted_cost Converted cost
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return integer Converted cost
     * @throws waException
     */
    private function convertRatesRulesIncreaseCost($cost, $converted_cost, $rule_name, $rule_params = array())
    {
        if (is_array($this->rules[$rule_name]['measure'])) {
            if (
                is_array($this->rules[$rule_name]['measure'][$rule_params['measure']]) &&
                in_array($rule_params['percent'], $this->rules[$rule_name]['measure'][$rule_params['measure']])
            ) {
                if ($rule_params['percent'] == 'shipping') {
                    $converted_cost = $converted_cost + $converted_cost * $rule_params['value'] / 100;
                }
                if ($rule_params['percent'] == 'origin') {
                    $converted_cost = $converted_cost + $cost * $rule_params['value'] / 100;
                }
                if ($rule_params['percent'] == 'order') {
                    $converted_cost = $converted_cost + $this->getTotalPrice() * $rule_params['value'] / 100;
                }
            } else {
                if ($rule_params['measure'] == 'flatfee') {
                    $converted_cost = $this->convertRatesCost($converted_cost);
                    $converted_cost = $converted_cost + $rule_params['value'];
                    $converted_cost = $this->convertRatesCost($converted_cost, wa()->getConfig()->getCurrency(), $this->allowedCurrency());
                }
            }
        }
        return $converted_cost;
    }

    /**
     * Converts rate's cost according to the cost rules "decrease cost"
     * @param integer $cost Cost
     * @param integer $converted_cost Converted cost
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return integer Converted cost
     * @throws waException
     */
    private function convertRatesRulesDecreaseCost($cost, $converted_cost, $rule_name, $rule_params = array())
    {
        if (is_array($this->rules[$rule_name]['measure'])) {
            if (
                is_array($this->rules[$rule_name]['measure'][$rule_params['measure']]) &&
                in_array($rule_params['percent'], $this->rules[$rule_name]['measure'][$rule_params['measure']])
            ) {
                if ($rule_params['percent'] == 'shipping') {
                    $converted_cost = $converted_cost - $converted_cost * $rule_params['value'] / 100;
                }
                if ($rule_params['percent'] == 'origin') {
                    $converted_cost = $converted_cost - $cost * $rule_params['value'] / 100;
                }
                if ($rule_params['percent'] == 'order') {
                    $converted_cost = $converted_cost - $this->getTotalPrice() * $rule_params['value'] / 100;
                }
            } else {
                if ($rule_params['measure'] == 'flatfee') {
                    $converted_cost = $this->convertRatesCost($converted_cost);
                    $converted_cost = $converted_cost - $rule_params['value'];
                    $converted_cost = $this->convertRatesCost($converted_cost, wa()->getConfig()->getCurrency(), $this->allowedCurrency());
                }
            }
        }
        if ($converted_cost < 0) {
            $converted_cost = 0;
        }
        return $converted_cost;
    }

    /**
     * Converts rate's cost according to the cost rules "fix cost"
     * @param integer $cost Cost
     * @param integer $converted_cost Converted cost
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return integer Converted cost
     * @throws waException
     */
    private function convertRatesRulesFixCost($cost, $converted_cost, $rule_name, $rule_params = array())
    {
        if ($this->rules[$rule_name]['measure'] == $rule_params['measure']) {
            if ($rule_params['measure'] == 'currency') {
                $converted_cost = $this->convertRatesCost($rule_params['value'], wa()->getConfig()->getCurrency(), $this->allowedCurrency());
            }
        }
        return $converted_cost;
    }

    /**
     * Converts rate's time according to the time rules "increase min time"
     * @param integer $time Time
     * @param integer $converted_time Converted time
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted time
     */
    private function convertRatesRulesIncreaseMinTime($time, $converted_time, $rule_name, $rule_params = array())
    {
        if ($this->rules[$rule_name]['measure'] == $rule_params['measure']) {
            if ($rule_params['measure'] == 'day') {
                $converted_time = $converted_time + $rule_params['value'];
            }
        }
        return $converted_time;
    }

    /**
     * Converts rate's time according to the time rules "decrease min time"
     * @param integer $time Time
     * @param integer $converted_time Converted time
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted time
     */
    private function convertRatesRulesDecreaseMinTime($time, $converted_time, $rule_name, $rule_params = array())
    {
        if ($this->rules[$rule_name]['measure'] == $rule_params['measure']) {
            if ($rule_params['measure'] == 'day') {
                $converted_time = $converted_time - $rule_params['value'];
            }
        }
        if ($converted_time < 0) {
            $converted_time = 0;
        }
        return $converted_time;
    }

    /**
     * Converts rate's time according to the time rules "fix min time"
     * @param integer $time Time
     * @param integer $converted_time Converted time
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted time
     */
    private function convertRatesRulesFixMinTime($time, $converted_time, $rule_name, $rule_params = array())
    {
        if ($this->rules[$rule_name]['measure'] == $rule_params['measure']) {
            if ($rule_params['measure'] == 'day') {
                $converted_time = $rule_params['value'];
            }
        }
        return $converted_time;
    }

    /**
     * Converts rate's time according to the time rules "increase max time"
     * @param integer $time Time
     * @param integer $converted_time Converted time
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted time
     */
    private function convertRatesRulesIncreaseMaxTime($time, $converted_time, $rule_name, $rule_params = array())
    {
        if ($this->rules[$rule_name]['measure'] == $rule_params['measure']) {
            if ($rule_params['measure'] == 'day') {
                $converted_time = $converted_time + $rule_params['value'];
            }
        }
        return $converted_time;
    }

    /**
     * Converts rate's time according to the time rules "decrease max time"
     * @param integer $time Time
     * @param integer $converted_time Converted time
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted time
     */
    private function convertRatesRulesDecreaseMaxTime($time, $converted_time, $rule_name, $rule_params = array())
    {
        if ($this->rules[$rule_name]['measure'] == $rule_params['measure']) {
            if ($rule_params['measure'] == 'day') {
                $converted_time = $converted_time - $rule_params['value'];
            }
        }
        if ($converted_time < 0) {
            $converted_time = 0;
        }
        return $converted_time;
    }

    /**
     * Converts rate's time according to the time rules "fix max time"
     * @param integer $time Time
     * @param integer $converted_time Converted time
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted time
     */
    private function convertRatesRulesFixMaxTime($time, $converted_time, $rule_name, $rule_params = array())
    {
        if ($this->rules[$rule_name]['measure'] == $rule_params['measure']) {
            if ($rule_params['measure'] == 'day') {
                $converted_time = $rule_params['value'];
            }
        }
        return $converted_time;
    }

    /**
     * Converts rules to the control's HTML code of the rules
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param integer $rule_index Rule index
     * @return string Converted control's HTML code of the rules
     * @throws Exception
     */
    public function convertRules($control_name, $control_params = array(), $rule_index = 0)
    {
        $rule_name = ifempty($control_params['scheme'][$rule_index]);
        $rules_options = array();
        array_push($rules_options, array(
            'title' => $this->_w('Select a rule'),
            'value' => '',
        ));
        $rules_controls = array();
        foreach ($this->rules as $rule_key => $rule_value) {
            $method_name_part = $this->convertSnakeToStudly($rule_key);
            $method_name = 'convertRules' . $method_name_part;
            if (method_exists($this, $method_name)) {
                $rule = $this->{$method_name}($control_name, array(
                    'scheme' => ifempty($control_params['scheme'][$rule_index]),
                    'value' => ifempty($control_params['value'][$rule_index]),
                    'measure' => ifempty($control_params['measure'][$rule_index]),
                    'percent' => ifempty($control_params['percent'][$rule_index]),
                    'description' => ifempty($control_params['description'][$rule_index]),
                ), 'scheme_' . $rule_key, $rule_value);
                array_push($rules_options, $rule['option']);
                array_push($rules_controls, $rule['controls']);
            }
        }
        $controls = waHtmlControl::getControl(waHtmlControl::SELECT, $control_name . '[scheme][]', array(
            'value' => $rule_name,
            'options' => $rules_options,
            'control_wrapper' => '%s%s%s' . implode('', $rules_controls),
        ));
        return $controls;
    }

    /**
     * Converts "increase cost" rule to the controls and option of the rule
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted "increase cost" rule
     * @throws Exception
     */
    private function convertRulesIncreaseCost($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $converted_rules = array();
        $converted_rules['option'] = array(
            'title' => $this->_w('Raise a shipping cost by'),
            'value' => $rule_name,
        );
        $converted_rules['controls'] = $this->convertRulesControls($control_name, $control_params, $rule_name, $rule_params);
        return $converted_rules;
    }

    /**
     * Converts "decrease cost" rule to the controls and option of the rule
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted "decrease cost" rule
     * @throws Exception
     */
    private function convertRulesDecreaseCost($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $converted_rules = array();
        $converted_rules['option'] = array(
            'title' => $this->_w('Reduce a shipping cost by'),
            'value' => $rule_name,
        );
        $converted_rules['controls'] = $this->convertRulesControls($control_name, $control_params, $rule_name, $rule_params);
        return $converted_rules;
    }

    /**
     * Converts "fix cost" rule to the controls and option of the rule
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted "fix cost" rule
     * @throws Exception
     */
    private function convertRulesFixCost($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $converted_rules = array();
        $converted_rules['option'] = array(
            'title' => $this->_w('Fix a shipping cost at'),
            'value' => $rule_name,
        );
        $converted_rules['controls'] = $this->convertRulesControls($control_name, $control_params, $rule_name, $rule_params);
        return $converted_rules;
    }

    /**
     * Converts "increase min time" rule to the controls and option of the rule
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted "increase min time" rule
     * @throws Exception
     */
    private function convertRulesIncreaseMinTime($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $converted_rules = array();
        $converted_rules['option'] = array(
            'title' => $this->_w('Extend a minimum shipping time by'),
            'value' => $rule_name,
        );
        $converted_rules['controls'] = $this->convertRulesControls($control_name, $control_params, $rule_name, $rule_params);
        return $converted_rules;
    }

    /**
     * Converts "decrease min time" rule to the controls and option of the rule
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted "decrease min time" rule
     * @throws Exception
     */
    private function convertRulesDecreaseMinTime($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $converted_rules = array();
        $converted_rules['option'] = array(
            'title' => $this->_w('Shorten a minimum shipping time by'),
            'value' => $rule_name,
        );
        $converted_rules['controls'] = $this->convertRulesControls($control_name, $control_params, $rule_name, $rule_params);
        return $converted_rules;
    }

    /**
     * Converts "fix min time" rule to the controls and option of the rule
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted "fix min time" rule
     * @throws Exception
     */
    private function convertRulesFixMinTime($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $converted_rules = array();
        $converted_rules['option'] = array(
            'title' => $this->_w('Fix a minimum shipping time at'),
            'value' => $rule_name,
        );
        $converted_rules['controls'] = $this->convertRulesControls($control_name, $control_params, $rule_name, $rule_params);
        return $converted_rules;
    }

    /**
     * Converts "increase max time" rule to the controls and option of the rule
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted "increase max time" rule
     * @throws Exception
     */
    private function convertRulesIncreaseMaxTime($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $converted_rules = array();
        $converted_rules['option'] = array(
            'title' => $this->_w('Extend a maximum shipping time by'),
            'value' => $rule_name,
        );
        $converted_rules['controls'] = $this->convertRulesControls($control_name, $control_params, $rule_name, $rule_params);
        return $converted_rules;
    }

    /**
     * Converts "decrease max time" rule to the controls and option of the rule
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted "decrease max time" rule
     * @throws Exception
     */
    private function convertRulesDecreaseMaxTime($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $converted_rules = array();
        $converted_rules['option'] = array(
            'title' => $this->_w('Shorten a maximum shipping time by'),
            'value' => $rule_name,
        );
        $converted_rules['controls'] = $this->convertRulesControls($control_name, $control_params, $rule_name, $rule_params);
        return $converted_rules;
    }

    /**
     * Converts "fix max time" rule to the controls and option of the rule
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return array Converted "fix max time" rule
     * @throws Exception
     */
    private function convertRulesFixMaxTime($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $converted_rules = array();
        $converted_rules['option'] = array(
            'title' => $this->_w('Fix a maximum shipping time at'),
            'value' => $rule_name,
        );
        $converted_rules['controls'] = $this->convertRulesControls($control_name, $control_params, $rule_name, $rule_params);
        return $converted_rules;
    }

    /**
     * Converts rule to the controls
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return string Converted rule controls
     * @throws Exception
     */
    private function convertRulesControls($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $value = 0;
        $description = '';
        $disabled = true;
        $hidden = ' hidden';
        if ($control_params['scheme'] == $rule_name) {
            $value = $control_params['value'];
            $description = $control_params['description'];
            $disabled = false;
            $hidden = '';
        }
        $measure_control = $this->convertRulesMeasureControls($control_name, $control_params, $rule_name, $rule_params);
        $description_control = waHtmlControl::getControl(waHtmlControl::INPUT, $control_name . '[description][]', array(
            'value' => $description,
            'disabled' => $disabled,
            'class' => 'long',
            'placeholder' => $this->_w('Description'),
        ));
        $controls = waHtmlControl::getControl(waHtmlControl::INPUT, $control_name . '[value][]', array(
            'value' => $value,
            'size' => 5,
            'disabled' => $disabled,
            'control_wrapper' => ' <span class="' . $rule_name . $hidden . '">%s%s%s ' . $measure_control . ' ' . $description_control . '</span>',
        ));
        return $controls;
    }

    /**
     * Converts rule measure to the controls
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return string Converted rule measure controls
     * @throws Exception
     */
    private function convertRulesMeasureControls($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $controls = '';
        $value = '';
        $disabled = true;
        if ($control_params['scheme'] == $rule_name) {
            $value = $control_params['measure'];
            $disabled = false;
        }
        if (is_array($rule_params['measure'])) {
            $options = array();
            $percent = '';
            foreach ($rule_params['measure'] as $measure_key => $measure_value) {
                $measure_name = '';
                if ($measure_key == 'flatfee') {
                    $currency = waCurrency::getInfo(wa()->getConfig()->getCurrency());
                    $measure_name = $currency['sign'];
                }
                if ($measure_key == 'percent') {
                    $measure_name = '%%%%';
                    if (is_array($measure_value)) {
                        $percent = $this->convertRulesPercentControls($control_name, $control_params, $rule_name, $rule_params);
                    }
                }
                if (!empty($measure_name)) {
                    array_push($options, array(
                        'title' => $measure_name,
                        'value' => $measure_key,
                    ));
                }
            }
            $controls = waHtmlControl::getControl(waHtmlControl::SELECT, $control_name . '[measure][]', array(
                'value' => $value,
                'options' => $options,
                'disabled' => $disabled,
                'control_wrapper' => '%s%s%s ' . $percent,
            ));
        } else {
            $percent = waHtmlControl::getControl(waHtmlControl::HIDDEN, $control_name . '[percent][]', array(
                'value' => $value,
                'disabled' => $disabled,
                'control_wrapper' => '%s%s%s',
            ));
            $measure_name = '';
            if ($rule_params['measure'] == 'day') {
                $measure_name = $this->_w('days');
            }
            if ($rule_params['measure'] == 'currency') {
                $currency = waCurrency::getInfo(wa()->getConfig()->getCurrency());
                $measure_name = $currency['sign'];
            }
            $controls = waHtmlControl::getControl(waHtmlControl::HIDDEN, $control_name . '[measure][]', array(
                    'value' => $rule_params['measure'],
                    'disabled' => $disabled,
                )) . $measure_name . $percent;
        }
        return $controls;
    }

    /**
     * Converts rule percent to the controls
     * @param string $control_name Control name
     * @param array $control_params Control params
     * @param string $rule_name Rule name
     * @param array $rule_params Rule params
     * @return string Converted rule percent controls
     * @throws Exception
     */
    private function convertRulesPercentControls($control_name, $control_params = array(), $rule_name, $rule_params = array())
    {
        $value = '';
        $disabled = true;
        $hidden = 'hidden';
        if ($control_params['scheme'] == $rule_name) {
            $disabled = false;
            if ($control_params['measure'] == 'percent') {
                $value = $control_params['percent'];
                $hidden = '';
            } else {
                $hidden = 'hidden';
            }
        }
        $options = array();
        foreach ($rule_params['measure']['percent'] as $percent_key => $percent_value) {
            $percent_name = '';
            if ($percent_value == 'shipping') {
                $percent_name = $this->_w('from the shipping cost');
            }
            if ($percent_value == 'origin') {
                $percent_name = $this->_w('from the original shipping cost');
            }
            if ($percent_value == 'order') {
                $percent_name = $this->_w('from the order cost');
            }
            if (!empty($percent_name)) {
                array_push($options, array(
                    'title' => $percent_name,
                    'value' => $percent_value,
                ));
            }
        }
        $controls = waHtmlControl::getControl(waHtmlControl::SELECT, $control_name . '[percent][]', array(
            'value' => $value,
            'options' => $options,
            'disabled' => $disabled,
            'class' => $hidden,
        ));
        return $controls;
    }

    /**
     * Converts kebab-case string to Title Case
     * @param string $string String in kebab-case
     * @return string Converted string in Title Case
     */
    private function convertKebabToTitle($string)
    {
        $converted_string = str_replace('-', ' ', $string);
        $converted_string = ucwords($converted_string);
        return $converted_string;
    }

    /**
     * Converts kebab-case string to StudlyCase
     * @param string $string String in kebab-case
     * @return string Converted string in StudlyCase
     */
    private function convertKebabToStudly($string)
    {
        $converted_string = $this->convertKebabToTitle($string);
        $converted_string = str_replace(' ', '', $converted_string);
        return $converted_string;
    }

    /**
     * Converts kebab-case string to camelCase
     * @param string $string String in kebab-case
     * @return string Converted string in camelCase
     */
    private function convertKebabToCamel($string)
    {
        $converted_string = $this->convertKebabToStudly($string);
        $converted_string = lcfirst($converted_string);
        return $converted_string;
    }

    /**
     * Converts snake_case string to Title Case
     * @param string $string String in snake_case
     * @return string Converted string in Title Case
     */
    private function convertSnakeToTitle($string)
    {
        $converted_string = str_replace('_', '-', $string);
        $converted_string = $this->convertKebabToTitle($converted_string);
        return $converted_string;
    }

    /**
     * Converts snake_case string to StudlyCase
     * @param string $string String in snake_case
     * @return string Converted string in StudlyCase
     */
    private function convertSnakeToStudly($string)
    {
        $converted_string = str_replace('_', '-', $string);
        $converted_string = $this->convertKebabToStudly($converted_string);
        return $converted_string;
    }

    /**
     * Converts snake_case string to camelCase
     * @param string $string String in snake_case
     * @return string Converted string in camelCase
     */
    private function convertSnakeToCamel($string)
    {
        $converted_string = str_replace('_', '-', $string);
        $converted_string = $this->convertKebabToCamel($converted_string);
        return $converted_string;
    }
}
