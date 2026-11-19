<?php

/**
 * AI model pricing used to estimate spend for the super-admin AI insights.
 * Text  : USD per 1,000,000 tokens, as [input, output].
 * Image : USD per generated image.
 *
 * These are list prices (update as providers change them). The dashboard labels
 * the figure as an estimate; OpenAI/Anthropic also expose official cost APIs for
 * cross-checking, while Google AI Studio has no public billing API.
 *
 * Matching is by exact model id first, then by a prefix in `text_prefixes` /
 * `image_prefixes`, then the defaults — so new dated model ids still get a price.
 */
return [
    'text' => [
        // Gemini
        'gemini-3.6-flash'       => [0.075, 0.30],
        'gemini-1.5-flash'       => [0.075, 0.30],
        'gemini-1.5-flash-8b'    => [0.0375, 0.15],
        'gemini-1.5-pro'         => [1.25, 5.00],
        'gemini-2.0-flash'       => [0.10, 0.40],
        // OpenAI
        'gpt-4o-mini'            => [0.15, 0.60],
        'gpt-4o'                 => [2.50, 10.00],
        'gpt-4.1-mini'           => [0.40, 1.60],
        'gpt-4.1'                => [2.00, 8.00],
        // Anthropic
        'claude-opus-5'          => [15.00, 75.00],
        'claude-opus-4-8'        => [15.00, 75.00],
        'claude-sonnet-4-5'      => [3.00, 15.00],
        'claude-haiku-4-5'       => [1.00, 5.00],
        'claude-3-5-haiku'       => [0.80, 4.00],
    ],

    // Prefix fallbacks (longest match wins) for dated / versioned ids.
    'text_prefixes' => [
        'gemini-1.5-pro'   => [1.25, 5.00],
        'gemini-1.5-flash' => [0.075, 0.30],
        'gemini'           => [0.075, 0.30],
        'gpt-4o-mini'      => [0.15, 0.60],
        'gpt-4o'           => [2.50, 10.00],
        'gpt-4.1-mini'     => [0.40, 1.60],
        'gpt-4.1'          => [2.00, 8.00],
        'gpt'              => [0.50, 1.50],
        'claude-opus'      => [15.00, 75.00],
        'claude-sonnet'    => [3.00, 15.00],
        'claude-haiku'     => [1.00, 5.00],
        'claude'           => [3.00, 15.00],
    ],

    'image' => [
        'gemini-2.5-flash-image' => 0.039,
        'gpt-image-1'            => 0.040,
        'dall-e-3'              => 0.040,
        'dall-e-2'              => 0.020,
    ],
    'image_prefixes' => [
        'gemini' => 0.039,
        'gpt-image' => 0.040,
        'dall-e' => 0.040,
    ],

    'default_text'  => [0.50, 1.50],
    'default_image' => 0.040,

    // Approximate USD→INR for the ₹ figure on the insights dashboard (display only).
    'usd_to_inr' => env('USD_TO_INR', 88),
];
