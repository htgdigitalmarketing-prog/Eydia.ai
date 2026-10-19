<?php

return [
    'meta' => [
        'app_id'            => env('META_APP_ID'),
        'app_secret'        => env('META_APP_SECRET'),
        'verify_token'      => env('META_VERIFY_TOKEN', env('WHATSAPP_WEBHOOK_VERIFY_TOKEN')),
        'app_secrets'       => array_filter(array_map('trim', explode(',', env('META_APP_SECRETS', '')))),
        'verify_tokens'     => array_filter(array_map('trim', explode(',', env('META_VERIFY_TOKENS', '')))),
        'api_version'       => env('META_API_VERSION', 'v23.0'),
        'config_id'         => env('META_CONFIG_ID'),
        // System User token is the production credential. Encrypted at rest per WABA
        // in the DB; this env value is a fallback/bootstrap only.
        'system_user_token' => env('META_SYSTEM_USER_TOKEN'),
        'use_fake'          => env('META_USE_FAKE', false),
        'graph_base'        => env('META_GRAPH_BASE', 'https://graph.facebook.com'),
        'timeout'           => (int) env('META_HTTP_TIMEOUT', 30),
        'retries'           => (int) env('META_HTTP_RETRIES', 2),
        'retry_delay_ms'    => (int) env('META_HTTP_RETRY_DELAY', 300),
    ],

    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'razorpay' => [
        'key'            => env('RAZORPAY_KEY'),
        'secret'         => env('RAZORPAY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    // AI chatbot provider. 'gemini' | 'anthropic'. When unset, auto-detects:
    // Gemini if GEMINI_API_KEY is present, else Anthropic if ANTHROPIC_API_KEY is.
    'ai' => [
        'provider' => env('AI_PROVIDER'),
    ],

    // Hot list: also let the AI judge messages the keyword check can't decide.
    // Set HOT_LEAD_AI=false to use keywords only.
    'hot_lead' => [
        'ai' => env('HOT_LEAD_AI', true),
    ],

    'anthropic' => [
        'key'   => env('ANTHROPIC_API_KEY'),
        // Default to Anthropic's most capable model; override per deployment.
        // For a high-volume WhatsApp chatbot, claude-haiku-4-5 is cheaper/faster.
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
    ],

    'gemini' => [
        'key'   => env('GEMINI_API_KEY'),
        // Fast, low-cost default; override with GEMINI_MODEL (e.g. gemini-1.5-flash).
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
    ],

    // Mobile push. Expo needs no key; legacy FCM needs FCM_SERVER_KEY.
    'fcm' => [
        'key' => env('FCM_SERVER_KEY'),
    ],

    // Mobile-OTP login delivery. Prefers WhatsApp (an approved template sent from
    // the user's own number — no extra provider), else MSG91 SMS. With neither set,
    // the code is only logged (fine for local/dev).
    'otp' => [
        'whatsapp_template'        => env('OTP_WHATSAPP_TEMPLATE'), // e.g. login_otp
        'whatsapp_lang'            => env('OTP_WHATSAPP_LANG', 'en'), // MUST match the template's language exactly (en vs en_US)
        // 'authentication' (copy-code / auto-fill OTP template — recommended) adds
        // the code to the button too; 'utility' sends only the body.
        'whatsapp_type'            => env('OTP_WHATSAPP_TYPE', 'authentication'),
        'whatsapp_button_subtype'  => env('OTP_WHATSAPP_BUTTON_SUBTYPE', 'url'),
        // Send ALL login OTPs from this one number (its phone_number_id) so the
        // template is approved on a single account. Unset = use each user's own
        // tenant number.
        'whatsapp_phone_number_id' => env('OTP_WHATSAPP_PHONE_NUMBER_ID'),
    ],
    'msg91' => [
        'key'         => env('MSG91_AUTH_KEY'),
        'template_id' => env('MSG91_TEMPLATE_ID'),
        'sender'      => env('MSG91_SENDER'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Where Google sends the user back — must exactly match the "Authorized
        // redirect URI" in the Google Cloud console, e.g.
        // https://main.heltog.com/api/v1/auth/google/callback
        // Falls back to APP_URL + the callback path so it stays consistent even
        // if GOOGLE_REDIRECT_URI is not set explicitly.
        'redirect'      => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL', ''), '/') . '/api/v1/auth/google/callback'),
        // Where we send the user after issuing a token (the frontend app).
        'frontend_url'  => env('FRONTEND_URL', 'https://frontend.heltog.com'),

        // Google Business Profile "connect" flow. Separate from the login flow
        // above: it asks for the business.manage scope and lands on its own
        // callback so GBP tokens are stored per tenant (not on the user).
        'gbp' => [
            'redirect' => env('GOOGLE_GBP_REDIRECT_URI', rtrim((string) env('APP_URL', ''), '/') . '/api/v1/auth/gbp/callback'),
            'scopes'   => array_filter(array_map('trim', explode(',', env(
                'GOOGLE_GBP_SCOPES',
                'https://www.googleapis.com/auth/business.manage'
            )))),
            // Where to bounce the browser back to in the frontend after connecting.
            'return_path' => env('GOOGLE_GBP_RETURN_PATH', '/gbp'),
        ],

        // Google Lead Form Ads import. Needs the adwords scope + a linked Google
        // Ads customer id; left blank until the tenant connects.
        'leads' => [
            'redirect' => env('GOOGLE_LEADS_REDIRECT_URI', rtrim((string) env('APP_URL', ''), '/') . '/api/v1/auth/google-leads/callback'),
            'scopes'   => array_filter(array_map('trim', explode(',', env(
                'GOOGLE_LEADS_SCOPES',
                'https://www.googleapis.com/auth/business.manage'
            )))),
        ],
    ],

    // Meta Lead Ads import. Reuses the META_* app credentials above; the
    // leadgen webhook is verified with the shared verify token and the Graph
    // API is called with each tenant's stored page access token.
    'meta_leads' => [
        'redirect'    => env('META_LEADS_REDIRECT_URI', rtrim((string) env('APP_URL', ''), '/') . '/api/v1/auth/meta-leads/callback'),
        'scopes'      => array_filter(array_map('trim', explode(',', env(
            'META_LEADS_SCOPES',
            'leads_retrieval,pages_show_list,pages_manage_metadata,pages_read_engagement,business_management'
        )))),
        'return_path' => env('META_LEADS_RETURN_PATH', '/leads'),
    ],
];
