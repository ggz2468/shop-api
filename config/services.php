<?php

use App\Enums\PaymentTransaction\Provider;
use App\Enums\Shipment\Provider as ShipmentProvider;
use App\Gateways\Payments\EcpayPaymentGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'vonage' => [
        'key' => env('VONAGE_KEY'),
        'secret' => env('VONAGE_SECRET'),
        'sms_from' => env('VONAGE_SMS_FROM'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost'),

    // 金流設定
    'payment' => [
        'default_provider' => (int) env('PAYMENT_DEFAULT_PROVIDER', Provider::ECPAY->value),
        'gateways' => [
            Provider::ECPAY->value => EcpayPaymentGateway::class,
        ],
    ],

    // 物流設定
    'shipment' => [
        'default_provider' => (int) env('SHIPMENT_DEFAULT_PROVIDER', ShipmentProvider::ECPAY_LOGISTICS->value),
    ],

    // 綠界金流
    'ecpay' => [
        'merchant_id' => env('ECPAY_MERCHANT_ID'),
        'hash_key' => env('ECPAY_HASH_KEY'),
        'hash_iv' => env('ECPAY_HASH_IV'),
        'payment_action_url' => env('ECPAY_PAYMENT_ACTION_URL'),
        'return_url' => env('ECPAY_RETURN_URL'),
        'payment_info_url' => env('ECPAY_PAYMENT_INFO_URL'),
        'client_redirect_url' => env('ECPAY_CLIENT_REDIRECT_URL'),
        'client_back_url' => env('ECPAY_CLIENT_BACK_URL'),
    ],

    // 綠界物流
    'ecpay_logistics' => [
        'merchant_id' => env('ECPAY_LOGISTICS_MERCHANT_ID'),
        'hash_key' => env('ECPAY_LOGISTICS_HASH_KEY'),
        'hash_iv' => env('ECPAY_LOGISTICS_HASH_IV'),
        'create_action_url' => env('ECPAY_LOGISTICS_CREATE_ACTION_URL'),
        'query_action_url' => env('ECPAY_LOGISTICS_QUERY_ACTION_URL'),
        'store_map_action_url' => env('ECPAY_LOGISTICS_STORE_MAP_ACTION_URL'),
        'store_map_server_reply_url' => env('ECPAY_LOGISTICS_STORE_MAP_SERVER_REPLY_URL'),
        'store_map_client_redirect_url' => env('ECPAY_LOGISTICS_STORE_MAP_CLIENT_REDIRECT_URL'),
        'store_map_request_ttl_minutes' => (int) env('ECPAY_LOGISTICS_STORE_MAP_REQUEST_TTL_MINUTES', 30),
    ],

];
