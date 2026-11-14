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

        $tenantId = $request->user()->tenant_id;

        // Cumulative funnel from the append-only status event log (matches Meta's
        // Insights). A message "reached the device" if it EVER got a delivered OR
        // read event — so Sent = all outbound, Delivered = reached, Read = read,
        // Failed = Sent − Delivered. This also corrects history, because the
        // delivered/read events stay logged even if the row's status later flipped.
        // (Tenant-qualified + withoutGlobalScopes so the join can't make the
        // tenant_id column ambiguous.)
        $outbound = Message::withoutGlobalScopes()
            ->where('messages.tenant_id', $tenantId)
            ->where('messages.direction', 'outbound')
            ->where('messages.created_at', '>=', $startDate)
            ->leftJoin('message_statuses as ms', 'ms.message_id', '=', 'messages.id')
            ->selectRaw("DATE(messages.created_at) as date")
            ->selectRaw("COUNT(DISTINCT messages.id) as sent")
            ->selectRaw("COUNT(DISTINCT CASE WHEN ms.status IN ('delivered','read') THEN messages.id END) as delivered")
            ->selectRaw("COUNT(DISTINCT CASE WHEN ms.status = 'read' THEN messages.id END) as `read`")
            ->groupByRaw("DATE(messages.created_at)")
            ->get()
            ->keyBy('date');

        $inbound = Message::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('direction', 'inbound')
            ->where('created_at', '>=', $startDate)
            ->selectRaw("DATE(created_at) as date, COUNT(*) as inbound")
            ->groupByRaw("DATE(created_at)")
            ->get()
            ->keyBy('date');

        $daily = $outbound->keys()->merge($inbound->keys())->unique()->sort()->values()
            ->map(function ($date) use ($outbound, $inbound) {
                $o = $outbound->get($date);
                $sent = (int) ($o->sent ?? 0);
                $delivered = (int) ($o->delivered ?? 0);
                $read = (int) ($o->read ?? 0);

                return [
                    'date' => $date,
                    'sent' => $sent,
                    'delivered' => $delivered,
                    'read' => $read,
                    'failed' => max(0, $sent - $delivered), // Sent − reached-device
                    'inbound' => (int) (optional($inbound->get($date))->inbound ?? 0),
                ];
            });

        $totalSent = $daily->sum('sent');
        $totalDelivered = $daily->sum('delivered');
        $totalRead = $daily->sum('read');
        $totalFailed = $daily->sum('failed');
        $totalInbound = $daily->sum('inbound');

        return $this->ok([
            'daily' => $daily,
            'totals' => [
                'total_sent' => $totalSent,
                'total_delivered' => $totalDelivered,
                'total_read' => $totalRead,
                'total_failed' => $totalFailed,
                'total_inbound' => $totalInbound,
                'delivery_rate' => $totalSent > 0 ? round($totalDelivered / $totalSent * 100, 2) : 0,
                'read_rate' => $totalSent > 0 ? round($totalRead / $totalSent * 100, 2) : 0,
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
