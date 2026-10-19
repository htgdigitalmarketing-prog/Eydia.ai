<?php

namespace App\Modules\Analytics\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Template;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $this->authorize('analytics.view');

        $today = Carbon::now()->startOfDay();
        $weekAgo = Carbon::now()->subDays(7);
        $monthAgo = Carbon::now()->subDays(30);

        return $this->ok([
            'total_contacts' => Contact::count(),
            'total_conversations' => Conversation::count(),
            'open_conversations' => Conversation::where('status', 'open')->count(),
            'messages_today' => Message::where('created_at', '>=', $today)->count(),
            'messages_this_week' => Message::where('created_at', '>=', $weekAgo)->count(),
            'messages_this_month' => Message::where('created_at', '>=', $monthAgo)->count(),
            'outbound_today' => Message::where('direction', 'outbound')
                ->where('created_at', '>=', $today)
                ->count(),
            'inbound_today' => Message::where('direction', 'inbound')
                ->where('created_at', '>=', $today)
                ->count(),
            'templates_approved' => Template::where('status', 'APPROVED')->count(),
            'active_campaigns' => Campaign::where('status', 'running')->count(),
            'total_campaigns' => Campaign::count(),
        ]);
    }

    public function messageStats(Request $request): JsonResponse
    {
        $this->authorize('analytics.view');

        $days = min((int) $request->query('days', 30), 90);
        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $daily = Message::where('created_at', '>=', $startDate)
            ->selectRaw("DATE(created_at) as date")
            ->selectRaw("SUM(CASE WHEN direction = 'outbound' AND status = 'sent' THEN 1 ELSE 0 END) as sent")
            ->selectRaw("SUM(CASE WHEN direction = 'outbound' AND status = 'delivered' THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN direction = 'outbound' AND status = 'read' THEN 1 ELSE 0 END) as `read`")
            ->selectRaw("SUM(CASE WHEN direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->selectRaw("SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound")
            ->groupByRaw("DATE(created_at)")
            ->orderBy('date')
            ->get();

        $totalSent = $daily->sum('sent');
        $totalDelivered = $daily->sum('delivered');
        $totalRead = $daily->sum('read');
        $totalFailed = $daily->sum('failed');
        $totalInbound = $daily->sum('inbound');
        $totalOutbound = $totalSent + $totalDelivered + $totalRead + $totalFailed;

        return $this->ok([
            'daily' => $daily,
            'totals' => [
                'total_sent' => $totalSent,
                'total_delivered' => $totalDelivered,
                'total_read' => $totalRead,
                'total_failed' => $totalFailed,
                'total_inbound' => $totalInbound,
                'delivery_rate' => $totalOutbound > 0
                    ? round(($totalDelivered + $totalRead) / $totalOutbound * 100, 2)
                    : 0,
                'read_rate' => $totalOutbound > 0
                    ? round($totalRead / $totalOutbound * 100, 2)
                    : 0,
            ],
        ]);
    }

    public function campaignStats(Request $request): JsonResponse
    {
        $this->authorize('analytics.view');

        $campaigns = Campaign::orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(function (Campaign $campaign) {
                $totalOutbound = $campaign->sent_count
                    + $campaign->delivered_count
                    + $campaign->read_count
                    + $campaign->failed_count;

                return [
                    'name' => $campaign->name,
                    'status' => $campaign->status,
                    'total_recipients' => $campaign->total_recipients,
                    'sent_count' => $campaign->sent_count,
                    'delivered_count' => $campaign->delivered_count,
                    'read_count' => $campaign->read_count,
                    'failed_count' => $campaign->failed_count,
                    'delivery_rate' => $totalOutbound > 0
                        ? round(($campaign->delivered_count + $campaign->read_count) / $totalOutbound * 100, 2)
                        : 0,
                    'read_rate' => $totalOutbound > 0
                        ? round($campaign->read_count / $totalOutbound * 100, 2)
                        : 0,
                ];
            });

        return $this->ok(['campaigns' => $campaigns]);
    }
}
