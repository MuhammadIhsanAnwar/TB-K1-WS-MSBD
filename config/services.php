<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // Database 3: AllegroGraph repository untuk data semantik tempat wisata.
    'sparql' => [
        'driver' => env('SPARQL_DRIVER', 'allegrograph'),
        'host' => env('SPARQL_HOST'),
        'database' => env('SPARQL_DATABASE'),
        'username' => env('SPARQL_USERNAME'),
        'password' => env('SPARQL_PASSWORD'),
        'endpoint' => env('SPARQL_ENDPOINT'),
        'statements_endpoint' => env('SPARQL_STATEMENTS_ENDPOINT', env('SPARQL_HOST').'/repositories/'.env('SPARQL_DATABASE').'/statements'),
        'timeout' => (int) env('SPARQL_TIMEOUT', 60),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Pembayaran tiket. Kosongkan server_key untuk kembali ke mode simulasi (pengembangan dan test).
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
    ],

    'supabase' => [
        'katalog' => [
            'url' => env('SUPABASE_DB1_URL'),
            'publishable_key' => env('SUPABASE_DB1_PUBLISHABLE_KEY'),
            'secret_key' => env('SUPABASE_DB1_SECRET_KEY'),
            'jwks_url' => env('SUPABASE_DB1_JWKS_URL'),
            'jwt_private_key_path' => env('SUPABASE_DB1_JWT_PRIVATE_KEY_PATH'),
            'jwt_key_id' => env('SUPABASE_DB1_JWT_KEY_ID'),
        ],
        'transaksi' => [
            'url' => env('SUPABASE_DB2_URL'),
            'publishable_key' => env('SUPABASE_DB2_PUBLISHABLE_KEY'),
            'secret_key' => env('SUPABASE_DB2_SECRET_KEY'),
            'jwks_url' => env('SUPABASE_DB2_JWKS_URL'),
        ],
    ],

    'turn' => [
        'urls' => array_values(array_filter(array_map('trim', explode(',', env('TURN_URLS', ''))))),
        'shared_secret' => env('TURN_SHARED_SECRET'),
        'credential_lifetime' => (int) env('TURN_CREDENTIAL_LIFETIME', 3600),
    ],

    'webrtc' => [
        'enabled' => env('WEBRTC_ENABLED') === null || env('WEBRTC_ENABLED') === '' ? true : filter_var(env('WEBRTC_ENABLED'), FILTER_VALIDATE_BOOL),
        'stun_url' => env('WEBRTC_STUN_URL') ?: 'stun:stun.l.google.com:19302',
        'turn_provider' => env('WEBRTC_TURN_PROVIDER') ?: 'coturn',
        'metered' => [
            'app_name' => env('METERED_APP_NAME') ?: 'medanvisit',
            'urls' => array_values(array_filter(array_map('trim', explode(',', env(
                'METERED_TURN_URLS',
                'turn:asia.relay.metered.ca:80,turn:asia.relay.metered.ca:80?transport=tcp,turn:asia.relay.metered.ca:443,turns:asia.relay.metered.ca:443?transport=tcp'
            ))))),
            'username' => env('METERED_TURN_USERNAME'),
            'credential' => env('METERED_TURN_CREDENTIAL'),
        ],
    ],

    'routing' => [
        'osrm_url' => env('ROUTING_OSRM_URL', 'https://router.project-osrm.org'),
        'valhalla_url' => env('ROUTING_VALHALLA_URL', 'https://valhalla1.openstreetmap.de'),
        'timeout' => (int) env('ROUTING_TIMEOUT', 15),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
