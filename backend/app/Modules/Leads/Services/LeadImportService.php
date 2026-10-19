<?php

namespace App\Modules\Leads\Services;

use App\Models\Lead;

/**
 * Normalizes lead payloads from Meta Lead Ads and Google Lead Forms into the
 * tenant's Lead pipeline. Idempotent: a lead is keyed by (tenant, source,
 * external_id) so re-delivering the same webhook or re-running a sync never
 * creates duplicates.
 */
class LeadImportService
{
    /** Common field aliases used to pull name/phone/email/company out of a flat map. */
    private const NAME_KEYS = ['full_name', 'name', 'first_name', 'your_name'];
    private const PHONE_KEYS = ['phone_number', 'phone', 'mobile', 'contact_number', 'telephone'];
    private const EMAIL_KEYS = ['email', 'email_address', 'work_email'];
    private const COMPANY_KEYS = ['company_name', 'company', 'business_name', 'organization'];

    /**
     * Import one Meta lead (the object returned by the Graph API /{leadgen_id}).
     *
     * @return array{lead:Lead, created:bool}
     */
    public function importMetaLead(int $tenantId, array $lead): array
    {
        $fields = $this->flattenMetaFields($lead['field_data'] ?? []);

        return $this->upsert($tenantId, Lead::SOURCES[1] /* META */, (string) ($lead['id'] ?? ''), [
            'name' => $this->firstOf($fields, self::NAME_KEYS)
                ?? trim(($fields['first_name'] ?? '') . ' ' . ($fields['last_name'] ?? '')),
            'phone' => $this->firstOf($fields, self::PHONE_KEYS),
            'email' => $this->firstOf($fields, self::EMAIL_KEYS),
            'company' => $this->firstOf($fields, self::COMPANY_KEYS),
            'created_time' => $lead['created_time'] ?? null,
            'meta' => [
                'form_id' => $lead['form_id'] ?? null,
                'form_name' => $lead['form_name'] ?? null,
                'ad_id' => $lead['ad_id'] ?? null,
                'campaign_id' => $lead['campaign_id'] ?? null,
                'platform' => $lead['platform'] ?? null,
                'field_data' => $fields,
            ],
        ]);
    }

    /**
     * Import one Google Lead Form submission (the webhook "user_column_data"
     * payload Google posts to the configured delivery URL).
     *
     * @return array{lead:Lead, created:bool}
     */
    public function importGoogleLead(int $tenantId, array $payload): array
    {
        $fields = $this->flattenGoogleColumns($payload['user_column_data'] ?? []);

        return $this->upsert($tenantId, Lead::SOURCES[0] /* GOOGLE */, (string) ($payload['lead_id'] ?? ''), [
            'name' => $this->firstOf($fields, self::NAME_KEYS)
                ?? trim(($fields['first_name'] ?? '') . ' ' . ($fields['last_name'] ?? '')),
            'phone' => $this->firstOf($fields, self::PHONE_KEYS),
            'email' => $this->firstOf($fields, self::EMAIL_KEYS),
            'company' => $this->firstOf($fields, self::COMPANY_KEYS),
            'created_time' => null,
            'meta' => [
                'campaign_id' => $payload['campaign_id'] ?? null,
                'form_id' => $payload['form_id'] ?? null,
                'gcl_id' => $payload['gcl_id'] ?? null,
                'field_data' => $fields,
            ],
        ]);
    }

    /** Upsert a lead, keyed by (tenant, source, external_id). */
    private function upsert(int $tenantId, string $source, string $externalId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? '')) ?: 'Unknown lead';

        $attributes = ['tenant_id' => $tenantId, 'source' => $source];
        // Only key on external_id when the provider gave us one; otherwise a
        // blank id would collapse every id-less lead into a single row.
        if ($externalId !== '') {
            $attributes['external_id'] = $externalId;
        } else {
            $attributes['name'] = $name;
            $attributes['created_at'] = now();
        }

        $existing = Lead::where($attributes)->first();

        $values = [
            'name' => $name,
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'company' => $data['company'] ?: null,
            'external_id' => $externalId ?: null,
            'meta' => $data['meta'],
        ];

        if ($existing) {
            $existing->update($values);

            return ['lead' => $existing, 'created' => false];
        }

        $lead = Lead::create(array_merge($values, [
            'tenant_id' => $tenantId,
            'source' => $source,
            'stage' => 'NEW',
        ]));

        return ['lead' => $lead, 'created' => true];
    }

    /** Meta field_data is [['name'=>'email','values'=>['a@b.com']], ...]. */
    private function flattenMetaFields(array $fieldData): array
    {
        $out = [];
        foreach ($fieldData as $field) {
            $key = strtolower((string) ($field['name'] ?? ''));
            if ($key === '') {
                continue;
            }
            $out[$key] = $field['values'][0] ?? null;
        }

        return $out;
    }

    /** Google user_column_data is [['column_id'=>'EMAIL','string_value'=>'a@b.com'], ...]. */
    private function flattenGoogleColumns(array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            $key = strtolower((string) ($col['column_id'] ?? $col['column_name'] ?? ''));
            if ($key === '') {
                continue;
            }
            $out[$key] = $col['string_value'] ?? ($col['value'] ?? null);
        }

        return $out;
    }

    /** First non-empty value among a set of candidate keys. */
    private function firstOf(array $fields, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! empty($fields[$key])) {
                return (string) $fields[$key];
            }
        }

        return null;
    }
}
