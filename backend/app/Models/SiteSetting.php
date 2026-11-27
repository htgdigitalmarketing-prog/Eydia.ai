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
        'facebook', 'instagram', 'youtube', 'linkedin',
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
        ]);
    }

    /** Public-facing snapshot used by the website footer. */
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
        ];
    }
}
