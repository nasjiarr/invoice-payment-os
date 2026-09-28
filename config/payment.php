<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | This value determines the default payment gateway driver used by
    | the PaymentService. You may specify 'mock', 'midtrans', 'xendit',
    | or any other custom registered gateway.
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the settings for each payment gateway supported
    | by your application.
    |
    */

    'gateways' => [
        'mock' => [
            'name' => 'Mock Gateway',
        ],

        'midtrans' => [
            'server_key' => env('MIDTRANS_SERVER_KEY'),
            'client_key' => env('MIDTRANS_CLIENT_KEY'),
            'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        ],

        'xendit' => [
            'secret_key' => env('XENDIT_SECRET_KEY'),
            'public_key' => env('XENDIT_PUBLIC_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Security Configuration
    |--------------------------------------------------------------------------
    |
    | Defines supported providers and secret tokens used to verify webhook
    | authenticity and prevent spoofed payment callbacks.
    |
    */

    'webhook' => [
        'secret' => env('PAYMENT_WEBHOOK_SECRET'),
        'allowed_providers' => ['mock', 'midtrans', 'xendit'],
        'signature_header' => env('PAYMENT_WEBHOOK_SIGNATURE_HEADER', 'X-Webhook-Signature'),
    ],

];
