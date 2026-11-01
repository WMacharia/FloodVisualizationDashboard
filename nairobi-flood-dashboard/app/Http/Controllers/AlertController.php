<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\SavedPlace;
use App\Services\SystemMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * On-screen alerts. Every page polls index() about once a minute; that poll also
 * drives the automatic system and weather checks (SystemMonitor).
 */
class AlertController extends Controller
{
    public function index(SystemMonitor $monitor)
    {
        $monitor->runIfDue();
        $user = Auth::user();

        $alerts = Alert::active()->visibleTo($user)->with('creator:id,name')
            ->orderByDesc('created_at')->limit(50)->get();
        $read = $user
            ? DB::table('alert_reads')->where('user_id', $user->id)->whereIn('alert_id', $alerts->pluck('id'))->pluck('alert_id')->all()
            : [];

        return response()->json([
            'role' => $user?->role ?? 'guest',
            'alerts' => $alerts->map(fn (Alert $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'level' => $a->level,
                'audience' => $a->audience,
                'title' => $a->title,
                'body' => $a->body,
                'areas' => $a->areas ?? [],
                'action' => $a->action,
                'issued_by' => $a->kind === 'advisory' ? $a->creator?->name : null,
                'created_at' => $a->created_at->toIso8601String(),
                'expires_at' => $a->expires_at?->toIso8601String(),
                'read' => in_array($a->id, $read, true),
                'can_withdraw' => $a->kind === 'advisory' && $this->canWithdraw($a),
            ]),
            'weather' => $user?->isStaff() ? Cache::get('monitor:weather') : null,
        ]);
    }

    public function acknowledge(Alert $alert)
    {
        $user = Auth::user();
        abort_unless(Alert::whereKey($alert->id)->visibleTo($user)->exists(), 404);

        DB::table('alert_reads')->updateOrInsert(
            ['alert_id' => $alert->id, 'user_id' => $user->id],
            ['read_at' => now()]
        );
        // Staff acknowledgements are part of the incident record
        if ($alert->audience !== 'public' && $alert->audience !== 'user') {
            AuditLog::record('alert.acknowledged', "Acknowledged: {$alert->title}", ['alert_id' => $alert->id]);
        }
        return response()->json(['ok' => true]);
    }

    public function storeAdvisory(Request $request)
    {
        $names = $this->subcountyNames();
        $v = $request->validate([
            'level' => ['required', Rule::in(Alert::LEVELS)],
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:600',
            'areas' => 'required|array|min:1',
            'areas.*' => $names ? ['string', Rule::in($names)] : ['string', 'max:60'],
            'hours' => 'required|integer|min:1|max:72',
        ]);

        $advisory = Alert::create([
            'kind' => 'advisory', 'level' => $v['level'], 'audience' => 'public',
            'title' => $v['title'], 'body' => $v['body'], 'areas' => array_values(array_unique($v['areas'])),
            'created_by' => Auth::id(), 'expires_at' => now()->addHours($v['hours']),
        ]);

        // Personal alert for every resident with a saved place in an affected sub-county
        $places = SavedPlace::whereIn('subcounty', $advisory->areas)->get();
        foreach ($places as $p) {
            Alert::create([
                'kind' => 'place', 'level' => $advisory->level, 'audience' => 'user', 'user_id' => $p->user_id,
                'parent_id' => $advisory->id,
                'title' => "Advisory for your place \"{$p->name}\"",
                'body' => "{$p->name} is in {$p->subcounty}, covered by: {$advisory->title}",
                'areas' => [$p->subcounty], 'expires_at' => $advisory->expires_at,
            ]);
        }

        AuditLog::record('advisory.issued', "Issued {$advisory->level} advisory: {$advisory->title}", [
            'alert_id' => $advisory->id, 'areas' => $advisory->areas, 'hours' => $v['hours'], 'residents_notified' => $places->pluck('user_id')->unique()->count(),
        ]);
        return response()->json(['ok' => true, 'id' => $advisory->id, 'residents_notified' => $places->pluck('user_id')->unique()->count()], 201);
    }

    public function withdraw(Alert $alert)
    {
        abort_unless($alert->kind === 'advisory' && $this->canWithdraw($alert), 403, 'You can only withdraw advisories you issued.');
        $alert->resolve(Auth::user());
        AuditLog::record('advisory.withdrawn', "Withdrew advisory: {$alert->title}", ['alert_id' => $alert->id]);
        return response()->json(['ok' => true]);
    }

    private function canWithdraw(Alert $a): bool
    {
        $u = Auth::user();
        return $u && ($u->isAdmin() || ($u->role === 'responder' && $a->created_by === $u->id));
    }

    private function subcountyNames(): array
    {
        return Cache::remember('subcounty_names', now()->addDay(), function () {
            try {
                $r = Http::timeout(10)->get(rtrim(config('services.flood_model.url'), '/') . '/subcounties');
                return collect($r->json('features') ?? [])->pluck('properties.name')->filter()->values()->all();
            } catch (Throwable $e) {
                return [];
            }
        }) ?: [];
    }
}
