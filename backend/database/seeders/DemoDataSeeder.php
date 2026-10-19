<?php

namespace Database\Seeders;

use App\Models\GbpLocation;
use App\Models\GbpReview;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Auth\Actions\RegisterTenantAction;
use App\Modules\Auth\DTOs\RegisterData;
use Illuminate\Database\Seeder;

/**
 * Seeds a ready-to-demo company (on the Growth plan, so all three dashboards are
 * unlocked) with sample leads and Google reviews. Idempotent — safe to re-run.
 *
 * Login: demo@jayvisionx.test / password
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'demo@jayvisionx.test';

        $owner = User::withoutGlobalScopes()->where('email', $email)->first();
        if (! $owner) {
            $owner = app(RegisterTenantAction::class)->execute(new RegisterData(
                companyName: 'JayVisionX Demo Co',
                name: 'Demo Admin',
                email: $email,
                password: 'password',
                phone: '+91 90000 00000',
            ));
        }

        $tenantId = $owner->tenant_id;

        // Put the demo tenant on the Growth plan (all 3 dashboards unlocked).
        $growth = Plan::where('slug', 'growth')->first();
        if ($growth) {
            $sub = Subscription::withoutGlobalScopes()->where('tenant_id', $tenantId)->latest('id')->first();
            $attrs = [
                'plan_id' => $growth->id,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ];
            $sub ? $sub->update($attrs) : Subscription::create(array_merge($attrs, ['tenant_id' => $tenantId]));
        }

        $this->seedLeads($tenantId);
        $this->seedReviews($tenantId);
    }

    private function seedLeads(int $tenantId): void
    {
        if (Lead::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $rows = [
            ['Aarav Sharma', '+919812300001', 'aarav@example.com', 'Sharma Interiors', 'GOOGLE', 'NEW', 82, 45000],
            ['Priya Menon', '+919812300002', 'priya@example.com', null, 'META', 'CONTACTED', 68, 30000],
            ['Rohan Gupta', '+919812300003', 'rohan@example.com', 'Gupta Motors', 'WHATSAPP', 'FOLLOW_UP', 74, 120000],
            ['Neha Verma', '+919812300004', 'neha@example.com', null, 'WEBSITE', 'APPOINTMENT', 90, 85000],
            ['Imran Khan', '+919812300005', 'imran@example.com', 'Khan Traders', 'MANUAL', 'CONVERTED', 95, 210000],
            ['Sanya Kapoor', '+919812300006', 'sanya@example.com', null, 'GOOGLE', 'NEW', 55, 15000],
            ['Vikram Singh', '+919812300007', 'vikram@example.com', 'VS Realty', 'META', 'LOST', 40, 0],
            ['Diya Nair', '+919812300008', 'diya@example.com', null, 'WHATSAPP', 'CONTACTED', 71, 52000],
            ['Kabir Joshi', '+919812300009', 'kabir@example.com', 'Joshi & Co', 'GOOGLE', 'FOLLOW_UP', 80, 96000],
            ['Ananya Rao', '+919812300010', 'ananya@example.com', null, 'MANUAL', 'CONVERTED', 88, 175000],
        ];

        foreach ($rows as [$name, $phone, $mail, $company, $source, $stage, $score, $value]) {
            Lead::create([
                'tenant_id' => $tenantId,
                'name' => $name, 'phone' => $phone, 'email' => $mail, 'company' => $company,
                'source' => $source, 'stage' => $stage, 'score' => $score, 'value' => $value,
                'notes' => "Imported demo lead from {$source}.",
            ]);
        }
    }

    private function seedReviews(int $tenantId): void
    {
        if (GbpLocation::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $loc = GbpLocation::create([
            'tenant_id' => $tenantId,
            'google_location_id' => 'demo-loc-1',
            'name' => 'JayVisionX Studio — MG Road',
            'address' => '12 MG Road, Bengaluru, KA 560001',
            'phone' => '+91 80 4000 0000',
            'website' => 'https://jayvisionx.example',
            'category' => 'Marketing Agency',
            'rating' => 4.6,
            'review_count' => 5,
            'is_verified' => true,
            'last_synced_at' => now(),
        ]);

        $reviews = [
            ['Meera Iyer', 5, 'Fantastic service, our Google ranking jumped in weeks!', 'Thank you Meera! Delighted to help.'],
            ['Arjun Das', 5, 'Very professional team and great communication.', null],
            ['Fatima Sheikh', 4, 'Good results overall, reporting could be more frequent.', null],
            ['Nikhil Reddy', 5, 'Our review count doubled. Highly recommended.', 'Appreciate the kind words, Nikhil!'],
            ['Sara Thomas', 3, 'Decent, took a while to get started.', null],
        ];

        foreach ($reviews as $i => [$author, $rating, $comment, $reply]) {
            GbpReview::create([
                'tenant_id' => $tenantId,
                'gbp_location_id' => $loc->id,
                'google_review_id' => 'demo-rev-' . ($i + 1),
                'author_name' => $author,
                'rating' => $rating,
                'comment' => $comment,
                'reply' => $reply,
                'replied_at' => $reply ? now()->subDays($i) : null,
                'reviewed_at' => now()->subDays($i + 1),
            ]);
        }
    }
}
