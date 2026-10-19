<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // features.dash_* control which of the three dashboards a plan unlocks.
        $plans = [
            [
                'name' => 'Starter', 'slug' => 'starter', 'price' => 0, 'trial_days' => 14, 'sort_order' => 1,
                'limits' => ['max_agents' => 2, 'max_contacts' => 1000, 'max_campaigns' => 2, 'max_chatbots' => 1, 'max_templates' => 10, 'max_locations' => 1, 'max_leads' => 500, 'ai_credits' => 100],
                'features' => ['dash_whatsapp' => true, 'dash_gbp' => false, 'dash_leads' => false, 'reports' => true, 'advanced_reports' => false, 'export' => false, 'social' => false, 'chatbot' => false, 'automations' => false, 'api_access' => false],
            ],
            [
                'name' => 'Growth', 'slug' => 'growth', 'price' => 2999, 'trial_days' => 14, 'sort_order' => 2,
                'limits' => ['max_agents' => 10, 'max_contacts' => 50000, 'max_campaigns' => 50, 'max_chatbots' => 10, 'max_templates' => 100, 'max_locations' => 5, 'max_leads' => 25000, 'max_keywords' => 100, 'ai_credits' => 1000],
                'features' => ['dash_whatsapp' => true, 'dash_gbp' => true, 'dash_leads' => true, 'reports' => true, 'advanced_reports' => true, 'export' => true, 'social' => true, 'chatbot' => true, 'automations' => true, 'api_access' => true, 'seo' => true, 'ads' => true, 'invoicing' => true],
            ],
            [
                'name' => 'Enterprise', 'slug' => 'enterprise', 'price' => 9999, 'trial_days' => 0, 'sort_order' => 3,
                'limits' => ['max_agents' => -1, 'max_contacts' => -1, 'max_campaigns' => -1, 'max_chatbots' => -1, 'max_templates' => -1, 'max_locations' => -1, 'max_leads' => -1, 'max_keywords' => -1, 'ai_credits' => 50000],
                'features' => ['dash_whatsapp' => true, 'dash_gbp' => true, 'dash_leads' => true, 'reports' => true, 'advanced_reports' => true, 'export' => true, 'social' => true, 'chatbot' => true, 'automations' => true, 'api_access' => true, 'seo' => true, 'ads' => true, 'invoicing' => true],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], array_merge($plan, [
                'billing_period' => 'monthly',
                'currency' => 'INR',
                'is_active' => true,
                'is_public' => true,
            ]));
        }
    }
}
