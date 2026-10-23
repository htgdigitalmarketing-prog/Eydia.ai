<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Email-gated features
    |--------------------------------------------------------------------------
    | Emails allowed to see and install the one-click "GBP Lead Automation"
    | WhatsApp funnel. Every other account sees the normal chatbot page without
    | it. Override with a comma-separated LEAD_FLOW_EMAILS in .env to add more.
    */
    'lead_flow_emails' => array_values(array_filter(array_map(
        fn ($e) => trim(strtolower($e)),
        explode(',', (string) env('LEAD_FLOW_EMAILS', 'heltogtechnologies@gmail.com'))
    ))),
];
