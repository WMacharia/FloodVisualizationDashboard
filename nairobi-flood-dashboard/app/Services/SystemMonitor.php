<?php

namespace App\Services;

use App\Models\Alert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Raises and clears the automatic on-screen alerts:
 *   - system  (admin): model server down, slow or failing map requests
 *   - weather (staff): Open-Meteo forecast rain at or above the lightest simulated storm
 * There is no scheduler to keep running: the checks run at most once a minute,
 * triggered by any page polling /api/alerts.
 */
class SystemMonitor
{
    public const CHECK_EVERY_S = 60;
    public const RAIN_WARNING_MMHR = 10;   // lightest storm the simulator was run for
    public const RAIN_SEVERE_MMHR = 30;    // assumption, comparable to the "Intense" preset
    public const SLOW_MEDIAN_MS = 30000;   // first run of a new storm takes ~10-25 s on CPU
    public const FAILURES_FOR_ALERT = 3;   // failed map requests among the last 20
    private const REQUEST_LOG = 'monitor:requests';
    private const LAT = -1.2921, LNG = 36.8219;

    public function runIfDue(): void
    {
        if (!Cache::add('monitor:lock', true, self::CHECK_EVERY_S)) return;
        $this->checkModel();
        $this->checkRequests();
        $this->checkWeather();
    }

    // Called by MapController for every proxied request
    public static function recordRequest(string $path, int $ms, bool $ok): void
    {
        $log = Cache::get(self::REQUEST_LOG, []);
        $log[] = ['path' => $path, 'ms' => $ms, 'ok' => $ok, 'at' => now()->toIso8601String()];
        Cache::put(self::REQUEST_LOG, array_slice($log, -50), now()->addDay());
    }

    public static function requestStats(): array
    {
        $log = Cache::get(self::REQUEST_LOG, []);
        $ms = array_column($log, 'ms');
        sort($ms);
        $pick = fn ($q) => $ms ? $ms[min(count($ms) - 1, (int) floor($q * count($ms)))] : null;
        return [
            'count' => count($log),
            'median_ms' => $pick(0.5),
            'p95_ms' => $pick(0.95),
            'failures' => count(array_filter($log, fn ($r) => !$r['ok'])),
            'last_at' => $log ? end($log)['at'] : null,
        ];
    }

    public function checkModel(): array
    {
        $t0 = microtime(true);
        try {
            $r = Http::timeout(5)->get(rtrim(config('services.flood_model.url'), '/') . '/health');
            $online = $r->successful();
            $body = $online ? $r->json() : null;
            $error = $online ? null : 'HTTP ' . $r->status();
        } catch (Throwable $e) {
            $online = false;
            $body = null;
            $error = 'Not reachable';
        }
        $status = [
            'online' => $online,
            'latency_ms' => (int) round((microtime(true) - $t0) * 1000),
            'checked_at' => now()->toIso8601String(),
            'error' => $error,
            'model' => $body['model'] ?? null,
            'dv_available' => $body['dv_available'] ?? null,
        ];
        Cache::put('monitor:model', $status, now()->addDay());

        if ($online) {
            $this->clear('system:model_down');
        } else {
            $this->raise('system:model_down', [
                'kind' => 'system', 'level' => 'severe', 'audience' => 'admin',
                'title' => 'Flood model server is down',
                'body' => "The dashboard cannot run scenarios, spot checks or routes ({$error}). Start it with: uvicorn model_api:app --port 8001",
            ]);
        }
        return $status;
    }

    private function checkRequests(): void
    {
        $recent = array_slice(Cache::get(self::REQUEST_LOG, []), -20);
        $ms = array_column($recent, 'ms');
        sort($ms);
        $median = $ms ? $ms[intdiv(count($ms), 2)] : 0;
        if (count($ms) >= 5 && $median > self::SLOW_MEDIAN_MS) {
            $this->raise('system:slow', [
                'kind' => 'system', 'level' => 'warning', 'audience' => 'admin',
                'title' => 'Map requests are slow',
                'body' => 'Median response time over the last ' . count($ms) . ' requests is ' . round($median / 1000, 1) . ' s.',
            ]);
        } else {
            $this->clear('system:slow');
        }

        $failed = count(array_filter($recent, fn ($r) => !$r['ok']));
        if ($failed >= self::FAILURES_FOR_ALERT) {
            $this->raise('system:errors', [
                'kind' => 'system', 'level' => 'warning', 'audience' => 'admin',
                'title' => 'Map requests are failing',
                'body' => "{$failed} of the last " . count($recent) . ' map requests failed.',
            ]);
        } else {
            $this->clear('system:errors');
        }
    }

    // Hourly precipitation is the total for the preceding hour, so it reads directly as mm/h
    public function checkWeather(): ?array
    {
        try {
            $r = Http::timeout(8)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => self::LAT, 'longitude' => self::LNG, 'hourly' => 'precipitation',
                'past_hours' => 1, 'forecast_hours' => 24, 'timezone' => 'Africa/Nairobi',
            ]);
            $times = $r->json('hourly.time') ?? [];
            $rain = $r->json('hourly.precipitation') ?? [];
        } catch (Throwable $e) {
            return null;
        }
        if (!$times) return null;

        // Peak over the coming hours only (index 0 is the hour that has just passed)
        $peak = count($rain) > 1 ? 1 : 0;
        foreach ($rain as $k => $v) if ($k > $peak && $v > $rain[$peak]) $peak = $k;
        // Storm = the run of consecutive hours at or above the warning level around the peak
        [$a, $b] = [$peak, $peak];
        while ($a > 0 && $rain[$a - 1] >= self::RAIN_WARNING_MMHR) $a--;
        while ($b < count($rain) - 1 && $rain[$b + 1] >= self::RAIN_WARNING_MMHR) $b++;
        $hours = $b - $a + 1;
        $mean = array_sum(array_slice($rain, $a, $hours)) / $hours;

        $weather = [
            'now_mmhr' => $rain[0] ?? 0,
            'peak_mmhr' => $rain[$peak],
            'peak_time' => $times[$peak],
            'series' => array_map(fn ($t, $v) => ['time' => $t, 'mm' => $v], $times, $rain),
            'checked_at' => now()->toIso8601String(),
        ];
        Cache::put('monitor:weather', $weather, now()->addDay());

        if ($rain[$peak] >= self::RAIN_WARNING_MMHR) {
            $start = Carbon::parse($times[$a], 'Africa/Nairobi')->subHour();
            $intensity = (int) min(120, max(10, round($mean)));
            $duration = min(6, max(1, $hours));
            $this->raise('weather:' . $start->format('YmdH'), [
                'kind' => 'weather',
                'level' => $rain[$peak] >= self::RAIN_SEVERE_MMHR ? 'severe' : 'warning',
                'audience' => 'staff',
                'title' => 'Heavy rain forecast for Nairobi',
                'body' => sprintf('Open-Meteo forecasts up to %.0f mm/h, about %d h from %s. Run the matching scenario to see where flooding is likely.',
                    $rain[$peak], $hours, $start->format('D H:i')),
                'action' => ['type' => 'scenario', 'intensity' => $intensity, 'duration' => $duration],
                'expires_at' => $start->copy()->addHours($hours + 2)->utc(),
            ]);
        }
        return $weather;
    }

    private function raise(string $key, array $attrs): void
    {
        if (Alert::where('dedupe_key', $key)->active()->exists()) return;
        Alert::create($attrs + ['dedupe_key' => $key]);
    }

    private function clear(string $key): void
    {
        Alert::where('dedupe_key', $key)->active()->update(['resolved_at' => now()]);
    }
}
