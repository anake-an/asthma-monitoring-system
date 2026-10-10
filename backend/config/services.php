<?php

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mqtt' => [
        'host' => env('MQTT_HOST', 'mqtt'),
        'port' => (int) env('MQTT_PORT', 1883),
        'username' => env('MQTT_USERNAME'),
        'password' => env('MQTT_PASSWORD'),
    ],

    'ai_engine' => [
        'url' => env('AI_ENGINE_URL', 'http://ai_engine:8000'),
    ],

    'frontend' => [
        'url' => env('FRONTEND_URL', 'http://localhost:3005'),
    ],

    // Cloud firmware updates: CI uploads each new ESP32 build with this key (POST /api/firmware/upload).
    // Empty = uploads off. Use a long random value, the same as the FIRMWARE_UPLOAD_TOKEN GitHub secret.
    'firmware' => [
        'upload_token' => env('FIRMWARE_UPLOAD_TOKEN', ''),
    ],

];
