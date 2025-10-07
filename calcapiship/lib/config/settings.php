<?php
//additional plugin settings
$this->registerControl('DeliveryAddressControl', array($this, 'settingDeliveryAddressControl'));
$this->registerControl('WeightControl', array($this, 'settingWeightControl'));
return array(
    'unsafe_mode' => array(
        'title' => $this->_w('Unsafe mode'),
        'description' => $this->_w('A temporary solution to the problem "cURL error 60: SSL certificate problem: certificate has expired"'),
        'value' => '',
        'control_type' => waHtmlControl::CHECKBOX,
    ),
    'test_mode' => array(
        'title' => $this->_w('Test mode'),
        'description' => '',
        'value' => '',
        'control_type' => waHtmlControl::CHECKBOX,
    ),
    'username' => array(
        'title' => $this->_w('Username'),
        'description' => '',
        'value' => '',
        'control_type' => waHtmlControl::INPUT,
    ),
    'password' => array(
        'title' => $this->_w('Password'),
        'description' => '',
        'value' => '',
        'control_type' => waHtmlControl::INPUT,
    ),
    'delivery_address' => array(
        'title' => $this->_w('Delivery region'),
        'description' => '',
        'value' => array(
            'country' => '',
            'region' => '',
            'city' => '',
        ),
        'control_type' => 'DeliveryAddressControl',
        'items' => array(
            'country' => array(
                'description' => $this->_w('Delivery country'),
            ),
            'region' => array(
                'description' => $this->_w('Delivery region'),
            ),
            'city' => array(
                'description' => $this->_w('Delivery city'),
            ),
        )
    ),
    'dispatch_address' => array(
        'title' => $this->_w('Dispatch address'),
        'description' => '',
        'value' => array(
            'country' => wa()->getConfig()->getAppConfig($this->getAdapter()->getInstance()->getAppId())->getGeneralSettings('country'),
            'region' => '',
            'city' => '',
            'street' => '',
        ),
        'control_type' => waHtmlControl::CUSTOM . ' calcapishipShipping::settingDispatchAddressControl',
        'items' => array(
            'country' => array(
                'description' => $this->_w('Dispatch country'),
            ),
            'region' => array(
                'description' => $this->_w('Dispatch region'),
            ),
            'city' => array(
                'description' => $this->_w('Dispatch city'),
            ),
            'street' => array(
                'description' => $this->_w('Dispatch street'),
            ),
        )
    ),
    'weight' => array(
        'title' => $this->_w('Weight') . ' (' . $this->_w('g') . ')',
        'description' => $this->_w('Entire order weight, when the weight of each product in the cart is not indicated in the value of the feature with the code "weight"'),
        'value' => array(
            'weight' => 500,
            'force' => '',
        ),
        'control_type' => 'WeightControl',
    ),
    'weight_and_dimensions' => array(
        'title' => $this->_w('Weight and dimensions'),
        'description' => $this->_w('Dispatch dimensions depending on the order weigh'),
        'value' => array(
            'weight' => array(500),
            'length' => array(15),
            'width' => array(10),
            'height' => array(5),
        ),
        'control_type' => waHtmlControl::CUSTOM . ' calcapishipShipping::settingWeightAndDimensionsControl',
    ),
    'providers' => array(
        'title' => $this->_w('Providers'),
        'description' => $this->_w('To get providers, choose a test mode or input a correct username and password'),
        'value' => '',
        'control_type' => waHtmlControl::GROUPBOX,
        'options_callback' => array($this, 'settingProvidersControlOptions'),
    ),
    'delivery_types' => array(
        'title' => $this->_w('Delivery types'),
        'description' => $this->_w('To get delivery types, choose a test mode or input a correct username and password'),
        'value' => '',
        'control_type' => waHtmlControl::GROUPBOX,
        'options_callback' => array($this, 'settingDeliveryTypesControlOptions'),
    ),
    'pickup_types' => array(
        'title' => $this->_w('Pickup types'),
        'description' => $this->_w('To get pickup types, choose a test mode or input a correct username and password'),
        'value' => '',
        'control_type' => waHtmlControl::GROUPBOX,
        'options_callback' => array($this, 'settingPickupTypesControlOptions'),
    ),
    'cheat_sheet' => array(
        'title' => $this->_w('Cheat sheet'),
        'description' => $this->_w('To display delivery details in the notifications, place this code in the message templates'),
        'value' => '
{if !empty($order.params)}
    <p>
        <ul>
            {if !empty($order.params.shipping_est_delivery)}
                <li>' . $this->_w('Estimated delivery time') . ': {$order.params.shipping_est_delivery}</li>
            {/if}
            {if !empty($order.params.shipping_params_delivery_type)}
                <li>' . $this->_w('Delivery type') . ': {$order.params.shipping_params_delivery_type}</li>
            {/if}
            {if !empty($order.params.shipping_params_provider)}
                <li>' . $this->_w('Provider') . ': {$order.params.shipping_params_provider}</li>
            {/if}
            {if !empty($order.params.shipping_params_tariff)}
                <li>' . $this->_w('Tariff') . ': {$order.params.shipping_params_tariff}</li>
            {/if}
            {if !empty($order.params.shipping_params_point)}
                <li>' . $this->_w('Point') . ': {$order.params.shipping_params_point}</li>
            {/if}
            {if !empty($order.params.shipping_params_point_type)}
                <li>' . $this->_w('Point type') . ': {$order.params.shipping_params_point_type}</li>
            {/if}
            {if !empty($order.params.shipping_params_point_code)}
                <li>' . $this->_w('Point code') . ': {$order.params.shipping_params_point_code}</li>
            {/if}
            {if !empty($order.params.shipping_params_address)}
                <li>' . $this->_w('Address') . ': {$order.params.shipping_params_address}</li>
            {/if}
            {if !empty($order.params.shipping_params_phone)}
                <li>' . $this->_w('Phone') . ': {$order.params.shipping_params_phone}</li>
            {/if}
            {if !empty($order.params.shipping_params_timetable)}
                <li>' . $this->_w('Timetable') . ': {$order.params.shipping_params_timetable}</li>
            {/if}
            {if !empty($order.params.shipping_params_description)}
                <li>' . $this->_w('Description') . ': {$order.params.shipping_params_description}</li>
            {/if}
            {if !empty($order.params.shipping_params_payment)}
                <li>' . $this->_w('Payment') . ': {$order.params.shipping_params_payment}</li>
            {/if}
            {if !empty($order.params.shipping_params_payment_card)}
                <li>' . $this->_w('Payment card') . ': {$order.params.shipping_params_payment_card}</li>
            {/if}
            {if !empty($order.params.shipping_params_fitting_room)}
                <li>' . $this->_w('Fitting room') . ': {$order.params.shipping_params_fitting_room}</li>
            {/if}
        </ul>
    </p>
{/if}
        ',
        'control_type' => waHtmlControl::CUSTOM . ' calcapishipShipping::settingCheatSheetControl',
    ),
    'sort_by' => array(
        'title' => _wp('Sort results'),
        'description' => _wp('Sort results by the selected option'),
        'value' => '',
        'control_type' => waHtmlControl::SELECT,
        'options' => array(
            '' => _wp('Do not sort'),
            'name' => _wp('By alphabet'),
            'rate' => _wp('By cost'),
        ),
    ),
    'display_point_types' => array(
        'title' => $this->_w('Display point types'),
        'description' => $this->_w('Display a filter by the point types on the map.'),
        'value' => 1,
        'control_type' => waHtmlControl::CHECKBOX,
    ),
    'yandex_maps_api_key' => array(
        'title' => $this->_w('Yandex.Maps API key'),
        'description' => $this->_w('Get an API key in the <a href="https://developer.tech.yandex.ru/" target="_blank">developer dashboard</a>. Select "JavaScript API and Geocoder HTTP API" option.'),
        'value' => '',
        'control_type' => waHtmlControl::INPUT,
    ),
);
