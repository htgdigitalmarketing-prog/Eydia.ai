<?php

namespace Database\Seeders;

use App\Models\CreditPack;
use Illuminate\Database\Seeder;

/** Default buyable AI-credit packs (GST-inclusive prices). Idempotent. */
class CreditPackSeeder extends Seeder
{
    public function run(): void
    {
        $packs = [
            ['name' => 'Starter Pack', 'credits' => 250, 'price' => 499, 'sort_order' => 1],
            ['name' => 'Booster Pack', 'credits' => 1000, 'price' => 1799, 'sort_order' => 2],
            ['name' => 'Power Pack', 'credits' => 2000, 'price' => 2999, 'sort_order' => 3],
            ['name' => 'Agency Pack', 'credits' => 5000, 'price' => 6999, 'sort_order' => 4],
        ];

        foreach ($packs as $p) {
            CreditPack::firstOrCreate(
                ['name' => $p['name']],
                array_merge($p, ['gst_rate' => 18, 'is_active' => true]),
            );
        }
    }
}
