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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'firebase' => [
        'api_key' => env('FIREBASE_API_KEY'),
        'project_id' => env('FIREBASE_PROJECT_ID', 'kid-parent-app-65a80'),
        'auth_domain' => env(
            'FIREBASE_AUTH_DOMAIN',
            'kid-parent-app-65a80.firebaseapp.com',
        ),
        'reset_continue_url' => env(
            'FIREBASE_RESET_CONTINUE_URL',
            'https://kid-parent-app-65a80.firebaseapp.com/reset-password',
        ),
        'android_package_name' => env(
            'FIREBASE_ANDROID_PACKAGE_NAME',
            'com.example.kidsecure_parent_app',
        ),
        'ios_bundle_id' => env(
            'FIREBASE_IOS_BUNDLE_ID',
            'com.example.kidsecureParentApp',
        ),

        
    ],

    'device' => [
        'scan_secret' => env('DEVICE_SCAN_SECRET'),
    ],

    'brevo' => [
    'key' => env('BREVO_API_KEY'),
    ],

];
