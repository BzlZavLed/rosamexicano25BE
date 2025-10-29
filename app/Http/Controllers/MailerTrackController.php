<?php
// app/Http/Controllers/MailerTrackController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MailerTrack;

class MailerTrackController extends Controller
{
    /**
     * GET /api/mailer-track
     * Optional query params:
     * - limit (int, default 24)
     * - order (asc|desc, default desc) — sorts by year then month
     */
    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 24);
        $order = strtolower($request->query('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $rows = MailerTrack::query()
            ->select(['year', 'month', 'sent_count'])
            ->orderBy('year', $order)
            ->orderBy('month', $order)
            ->limit($limit)
            ->get();

        // Shape it nicely for charts/widgets
        $items = $rows->map(function ($r) {
            $ym = sprintf('%04d-%02d', $r->year, $r->month);
            return [
                'year'        => $r->year,
                'month'       => $r->month,
                'sent_count'  => $r->sent_count,
                'label'       => $ym,         // e.g., "2025-10"
                'date'        => $ym . '-01', // useful for charts as an ISO date
            ];
        });

        return response()->json([
            'items' => $items,
            'meta'  => [
                'count' => $items->count(),
                'order' => $order,
            ],
        ]);
    }
}
