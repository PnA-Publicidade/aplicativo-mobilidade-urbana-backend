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

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),

        'max_resultados_busca' => (int) env('GOOGLE_MAPS_MAX_RESULTADOS', 5),
        'faixa_proximidade_km' => (float) env('GOOGLE_MAPS_FAIXA_PROXIMIDADE_KM', 50),

        'regiao_atendida' => [
            'latitude_min' => (float) env('GOOGLE_MAPS_REGIAO_LATITUDE_MIN', -13.75),
            'longitude_min' => (float) env('GOOGLE_MAPS_REGIAO_LONGITUDE_MIN', -66.85),
            'latitude_max' => (float) env('GOOGLE_MAPS_REGIAO_LATITUDE_MAX', -7.95),
            'longitude_max' => (float) env('GOOGLE_MAPS_REGIAO_LONGITUDE_MAX', -59.75),
        ],
    ],

    // Terreno preparado para login social (Laravel Socialite) — sem
    // credenciais reais ainda, fluxo de autenticação não implementado.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

];
