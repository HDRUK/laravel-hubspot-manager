<?php

return [
    /**
     * Default Hubspot manager config
     */
    'default' => [
        'product_name' => env('HUBSPOT_INTEGRATION_PRODUCT_NAME'),
        'enabled' => env('HUBSPOT_INTEGRATION_ENABLED', true),
        'access' => [
            'hubspot_base_url' => env('HUBSPOT_BASE_URL', 'https://api.hubapi.com'),
            'hubspot_api_key' => env('HUBSPOT_API_KEY'),
        ],
        'endpoints' => [
            'contacts' => env('HUBSPOT_CONTACTS_ENDPOINT', 'crm/v3/objects/contacts'),
        ],
        'models' => [
            'users' => env('HUBSPOT_SYNC_USER_MODEL', 'App\Models\User'),
        ],
    ],
];
