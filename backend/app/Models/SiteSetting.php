<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Public website / footer contact details, editable by the Super Admin and
 * shown in the marketing site footer. Singleton — one row (id = 1). Not
 * tenant-scoped (platform-wide).
 */
class SiteSetting extends Model
{
    protected $fillable = [
        'email', 'phone', 'whatsapp', 'address',
        'facebook', 'instagram', 'youtube', 'linkedin', 'ratings',
    ];

    protected $casts = [
        'ratings' => 'array',
    ];

    /** Default homepage rating cards (score + count), shown until the admin edits them. */
    public const DEFAULT_RATINGS = [
        'app_store' => ['score' => '4.8', 'count' => '247 ratings'],
        'google_play' => ['score' => '4.6', 'count' => '676 ratings'],
        'google' => ['score' => '4.8', 'count' => '2,347 reviews'],
        'trustpilot' => ['score' => '4.6', 'count' => '176 reviews'],
    ];

    /** The single settings row, created with sensible defaults on first access. */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'email' => 'heltogtechnologies@gmail.com',
            'phone' => '+91 93119 15615',
            'whatsapp' => '919311915615',
            'address' => 'Nehru Place, New Delhi, 110019, India',
            'facebook' => 'https://www.facebook.com/eydia.ai',
            'instagram' => 'https://www.instagram.com/eydia.ai',
            'ratings' => self::DEFAULT_RATINGS,
        ]);
    }

    /** Ratings merged with defaults so every platform key is always present. */
    public function ratingsWithDefaults(): array
    {
        $saved = is_array($this->ratings) ? $this->ratings : [];
        $out = [];
        foreach (self::DEFAULT_RATINGS as $key => $def) {
            $out[$key] = [
                'score' => $saved[$key]['score'] ?? $def['score'],
                'count' => $saved[$key]['count'] ?? $def['count'],
            ];
        }
        return $out;
    }

    /** Public-facing snapshot used by the website footer + homepage ratings. */
    public function toPublicArray(): array
    {
        return [
            'email' => $this->email,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'address' => $this->address,
            'facebook' => $this->facebook,
            'instagram' => $this->instagram,
            'youtube' => $this->youtube,
            'linkedin' => $this->linkedin,
            'ratings' => $this->ratingsWithDefaults(),
        ];
    }
}
