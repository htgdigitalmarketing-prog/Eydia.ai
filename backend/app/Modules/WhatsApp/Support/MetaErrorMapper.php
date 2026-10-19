<?php

namespace App\Modules\WhatsApp\Support;

/**
 * Translates Meta Graph API error codes into messages a business user can act on,
 * while the raw code/message stays in the logs. Codes per Meta's Cloud API error
 * reference (subject to change — keep this the single place they're interpreted).
 */
class MetaErrorMapper
{
    /** Meta error code => [user message, suggested HTTP status]. */
    private const MAP = [
        0      => ['Something went wrong sending your message. Please try again.', 502],
        3      => ['This WhatsApp number is not allowed to perform this action.', 403],
        10     => ['This WhatsApp number is not allowed to perform this action.', 403],
        100    => ['The request to WhatsApp was invalid. Please check the details and try again.', 422],
        200    => ['Your WhatsApp access token cannot send on this number. Re-connect with a permanent System User token that has whatsapp_business_messaging + whatsapp_business_management permissions and whose System User is assigned to this WhatsApp Business Account.', 403],
        131000 => ['Something went wrong on WhatsApp\'s side. Please try again.', 502],
        131005 => ['Access to this WhatsApp resource was denied.', 403],
        131008 => ['A required field was missing from the WhatsApp request.', 422],
        131009 => ['One of the values sent to WhatsApp was invalid.', 422],
        131016 => ['WhatsApp is temporarily unavailable. Please try again shortly.', 503],
        131021 => ['The recipient cannot receive this message (they may have blocked the business).', 422],
        131026 => ['Message could not be delivered. The recipient may not be available on WhatsApp.', 422],
        131030 => ['This recipient is not in your allowed list. Your WhatsApp number is in development mode — add the number as a test recipient in Meta, or complete business verification to message anyone.', 422],
        131047 => ['You can only message this contact with an approved template — the 24-hour window has closed.', 422],
        131051 => ['This message type is not supported.', 422],
        131052 => ['The media could not be downloaded by WhatsApp.', 422],
        131053 => ['The media could not be uploaded to WhatsApp.', 422],
        132000 => ['The template parameters don\'t match the approved template.', 422],
        132001 => ['This template does not exist or is not approved for this number.', 422],
        132005 => ['The template text was rejected because it differs from what Meta approved.', 422],
        132007 => ['The template content violates WhatsApp policy.', 422],
        133010 => ['This phone number is not registered on WhatsApp Cloud API.', 422],
        190    => ['The WhatsApp connection has expired. Please reconnect your WhatsApp account.', 401],
        368    => ['This action is temporarily blocked by WhatsApp due to policy limits.', 429],
        80007  => ['WhatsApp rate limit reached. Please slow down and try again.', 429],
        130429 => ['WhatsApp rate limit reached. Please slow down and try again.', 429],
        131031 => ['This WhatsApp account has been restricted by Meta.', 403],
    ];

    public static function message(?int $code): string
    {
        return self::MAP[$code][0] ?? 'The WhatsApp request could not be completed. Please try again.';
    }

    public static function httpStatus(?int $code): int
    {
        return self::MAP[$code][1] ?? 422;
    }
}
