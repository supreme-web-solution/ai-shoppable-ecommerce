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

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'url' => env('CLOUDINARY_URL'),
    ],

    'shopify' => [
        'api_key' => env('SHOPIFY_API_KEY'),
        'api_secret' => env('SHOPIFY_API_SECRET'),
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),
        'checkout_base' => env('SHOPIFY_CHECKOUT_BASE', 'https://checkout.shopify.com'),
    ],

    'woocommerce' => [
        'consumer_key' => env('WOO_CONSUMER_KEY'),
        'consumer_secret' => env('WOO_CONSUMER_SECRET'),
        'webhook_secret' => env('WOO_WEBHOOK_SECRET'),
        'checkout_base' => env('WOO_CHECKOUT_BASE', 'https://example.com/checkout'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_dimensions' => (int) env('OPENAI_EMBEDDING_DIMENSIONS', 1536),
    ],

    'heygen' => [
        'api_key' => env('HEYGEN_API_KEY'),
        'default_avatar_id' => env('HEYGEN_DEFAULT_AVATAR_ID'),
        'default_voice_id' => env('HEYGEN_DEFAULT_VOICE_ID'),
        'cache_ttl_seconds' => (int) env('HEYGEN_CACHE_TTL_SECONDS', 21600),
    ],

    'ai' => [
        'default_avatar_duration' => (int) env('AI_DEFAULT_AVATAR_DURATION', 45),
        'avatar_poll_attempts' => (int) env('AI_AVATAR_POLL_ATTEMPTS', 60),
        'avatar_poll_sleep_seconds' => (int) env('AI_AVATAR_POLL_SLEEP_SECONDS', 10),
    ],

];
