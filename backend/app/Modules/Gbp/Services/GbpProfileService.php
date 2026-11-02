<?php

namespace App\Modules\Gbp\Services;

use App\Models\GbpLocation;
use Illuminate\Support\Str;

/**
 * The Business Information editor behind the GBP profile page: turns a stored
 * GbpLocation into the structured, editable shape the UI uses (About, Contact,
 * Location, Hours, More/attributes, and the review-share card), applies edits
 * back onto the row, and builds the Google Business Profile patch body for the
 * fields Google lets us write when the account is connected.
 */
class GbpProfileService
{
    /** Days in Google's week order, with the API enum for each. */
    public const DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    /** Attribute groups shown on the "More" tab (mirrors Google's editor). */
    public const ATTRIBUTE_GROUPS = [
        'From the business', 'Accessibility', 'Amenities', 'Children',
        'Crowd', 'Parking', 'Planning', 'Service options',
    ];

    /** The editable profile for the UI. */
    public function toArray(?GbpLocation $l): array
    {
        $meta = $l?->meta ?? [];

        return [
            'id' => $l?->uuid,
            'name' => $l?->name ?? '',
            'category' => $l?->category ?? '',
            'description' => $meta['description'] ?? '',
            'phone' => $l?->phone ?? '',
            'chat_url' => $meta['chat_url'] ?? '',
            'website' => $l?->website ?? '',
            'address' => $l?->address ?? '',
            'service_area' => array_values($meta['service_area'] ?? []),
            'hours' => $this->hours($meta['hours'] ?? null),
            'special_hours' => array_values($meta['special_hours'] ?? []),
            'attributes' => $this->attributes($meta['attributes'] ?? []),
            'opening_date' => $meta['opening_date'] ?? '',
            'labels' => array_values($meta['labels'] ?? []),
            'review_url' => $this->reviewUrl($l),
            'share' => $this->share($l),
            'rating' => (float) ($l?->rating ?? 0),
            'review_count' => (int) ($l?->review_count ?? 0),
            'is_verified' => (bool) ($l?->is_verified ?? false),
            'google_location_id' => $l?->google_location_id,
        ];
    }

    /** Apply validated editor data onto a location row (columns + meta) and save. */
    public function apply(GbpLocation $l, array $data): GbpLocation
    {
        foreach (['name', 'category', 'phone', 'website', 'address'] as $col) {
            if (array_key_exists($col, $data)) {
                $l->{$col} = $data[$col];
            }
        }

        $meta = $l->meta ?? [];
        foreach (['description', 'chat_url', 'opening_date'] as $k) {
            if (array_key_exists($k, $data)) {
                $meta[$k] = $data[$k];
            }
        }
        if (array_key_exists('service_area', $data)) {
            $meta['service_area'] = array_values(array_filter(array_map('trim', (array) $data['service_area'])));
        }
        if (array_key_exists('labels', $data)) {
            $meta['labels'] = array_values(array_filter(array_map('trim', (array) $data['labels'])));
        }
        if (array_key_exists('hours', $data)) {
            $meta['hours'] = $this->hours($data['hours']);
        }
        if (array_key_exists('special_hours', $data)) {
            $meta['special_hours'] = array_values((array) $data['special_hours']);
        }
        if (array_key_exists('attributes', $data)) {
            $meta['attributes'] = $this->attributes($data['attributes']);
        }
        if (array_key_exists('review_url', $data) && $data['review_url']) {
            $meta['review_url'] = $data['review_url'];
        }

        $l->meta = $meta;
        $l->save();

        return $l->fresh();
    }

    /**
     * Apply an AI auto-setup plan WITHOUT overwriting anything the user already
     * filled in: description, "from the business" highlights, keyword labels and
     * service area are only set when currently empty, and a sensible default
     * hours grid is seeded if none exists. Returns the subset of editor fields
     * that actually changed, so the caller can push just those to Google.
     *
     * @param  array{description?:string, highlights?:array, keywords?:array, service_area?:array}  $plan
     * @return array<string, mixed> the applied changes (googlePatch-compatible)
     */
    public function applyAutoPlan(GbpLocation $l, array $plan): array
    {
        $meta = $l->meta ?? [];
        $data = [];

        if (empty($meta['description']) && ! empty($plan['description'])) {
            $data['description'] = (string) $plan['description'];
        }

        // Fill the "More" attribute groups that are still empty: highlights go to
        // "From the business", and the AI's per-group suggestions fill the rest.
        $attrs = $this->attributes($meta['attributes'] ?? []);
        $attrsTouched = false;
        if (empty($attrs['From the business']) && ! empty($plan['highlights'])) {
            $attrs['From the business'] = array_values((array) $plan['highlights']);
            $attrsTouched = true;
        }
        foreach ((array) ($plan['attributes'] ?? []) as $group => $items) {
            if (array_key_exists($group, $attrs) && empty($attrs[$group]) && ! empty($items)) {
                $attrs[$group] = array_values((array) $items);
                $attrsTouched = true;
            }
        }
        if ($attrsTouched) {
            $data['attributes'] = $attrs;
        }

        if (empty($meta['labels']) && ! empty($plan['keywords'])) {
            $data['labels'] = array_values((array) $plan['keywords']);
        }

        if (empty($meta['service_area']) && ! empty($plan['service_area'])) {
            $data['service_area'] = array_values((array) $plan['service_area']);
        }

        if (empty($meta['hours'])) {
            // Seed the default Mon–Sat 10–18 grid so the listing shows hours.
            $data['hours'] = $this->hours(null);
        }

        if ($data !== []) {
            $this->apply($l, $data);
        }

        return $data;
    }

    /**
     * Build the Google Business Profile patch for the fields Google lets us write
     * from here: title, phone, website, description and regular hours. Address is
     * kept local (a storefront-address patch can disrupt a verified listing).
     *
     * @return array{body: array, mask: string}
     */
    public function googlePatch(array $data): array
    {
        $body = [];
        $mask = [];

        if (array_key_exists('name', $data)) {
            $body['title'] = $data['name'];
            $mask[] = 'title';
        }
        if (array_key_exists('phone', $data)) {
            $body['phoneNumbers'] = ['primaryPhone' => (string) $data['phone']];
            $mask[] = 'phoneNumbers';
        }
        if (array_key_exists('website', $data)) {
            $body['websiteUri'] = (string) $data['website'];
            $mask[] = 'websiteUri';
        }
        if (array_key_exists('description', $data)) {
            $body['profile'] = ['description' => (string) $data['description']];
            $mask[] = 'profile';
        }
        if (array_key_exists('hours', $data)) {
            $body['regularHours'] = ['periods' => $this->googlePeriods($this->hours($data['hours']))];
            $mask[] = 'regularHours';
        }

        return ['body' => $body, 'mask' => implode(',', $mask)];
    }

    /** Normalize hours to a 7-row Mon→Sun array: [{day, open, close, closed}]. */
    private function hours(?array $rows): array
    {
        $byDay = [];
        foreach ((array) $rows as $r) {
            $day = strtoupper((string) ($r['day'] ?? ''));
            if (in_array($day, self::DAYS, true)) {
                $byDay[$day] = [
                    'day' => $day,
                    'open' => $this->time($r['open'] ?? '09:00'),
                    'close' => $this->time($r['close'] ?? '18:00'),
                    'closed' => (bool) ($r['closed'] ?? false),
                ];
            }
        }

        // Default template when nothing is set: Mon–Sat 10:00–18:00, Sun closed.
        return array_map(fn (string $d) => $byDay[$d] ?? [
            'day' => $d,
            'open' => '10:00',
            'close' => '18:00',
            'closed' => $d === 'SUNDAY',
        ], self::DAYS);
    }

    /** Google regularHours.periods from our hours rows (skips closed days). */
    private function googlePeriods(array $hours): array
    {
        $periods = [];
        foreach ($hours as $h) {
            if (! empty($h['closed'])) {
                continue;
            }
            [$oh, $om] = array_pad(explode(':', $h['open']), 2, '0');
            [$ch, $cm] = array_pad(explode(':', $h['close']), 2, '0');
            $periods[] = [
                'openDay' => $h['day'],
                'openTime' => ['hours' => (int) $oh, 'minutes' => (int) $om],
                'closeDay' => $h['day'],
                'closeTime' => ['hours' => (int) $ch, 'minutes' => (int) $cm],
            ];
        }

        return $periods;
    }

    /** Keep attributes as a group → list-of-strings map over the known groups. */
    private function attributes($attrs): array
    {
        $attrs = (array) $attrs;
        $out = [];
        foreach (self::ATTRIBUTE_GROUPS as $group) {
            $out[$group] = array_values(array_filter(array_map('trim', (array) ($attrs[$group] ?? []))));
        }

        return $out;
    }

    private function time(string $t): string
    {
        return preg_match('/^\d{1,2}:\d{2}$/', trim($t)) ? trim($t) : '09:00';
    }

    /** Best-effort city from a free-form address ("…, Sector 19, Noida, UP 201301, India" → "Noida"). */
    public static function cityFromAddress(?string $address): ?string
    {
        if (! $address) {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));
        $n = count($parts);
        // Skip the country + "state PIN" tail: the city is usually two before the end.
        return $n >= 3 ? ($parts[$n - 3] ?? null) : ($parts[0] ?? null);
    }

    /** The public "write a review" link — stored override, or derived from the listing. */
    private function reviewUrl(?GbpLocation $l): string
    {
        $meta = $l?->meta ?? [];
        if (! empty($meta['review_url'])) {
            return $meta['review_url'];
        }
        // A Place ID (saved from Google) gives the canonical review deep-link.
        $placeId = $meta['place_id'] ?? null;
        if ($placeId) {
            return 'https://search.google.com/local/writereview?placeid=' . $placeId;
        }

        return '';
    }

    /** Ready-made share links for the review URL (WhatsApp / email / Facebook). */
    private function share(?GbpLocation $l): array
    {
        $url = $this->reviewUrl($l);
        if ($url === '') {
            return ['whatsapp' => '', 'email' => '', 'facebook' => ''];
        }

        $name = $l?->name ?: 'our business';
        $msg = "We'd love your feedback! Please leave {$name} a quick Google review: {$url}";

        return [
            'whatsapp' => 'https://wa.me/?text=' . rawurlencode($msg),
            'email' => 'mailto:?subject=' . rawurlencode('Review ' . $name . ' on Google')
                . '&body=' . rawurlencode($msg),
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url),
        ];
    }
}
