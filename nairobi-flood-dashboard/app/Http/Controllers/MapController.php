<?php

namespace App\Http\Controllers;

use App\Services\SystemMonitor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Proxies the dashboard's map requests to the Python model server (scripts/model_api.py).
 * The browser never calls the model server directly. Every request carries the storm
 * parameters, so one user's simulation never changes another user's results.
 */
class MapController extends Controller
{
    // The first request for a new storm runs the U-Net on the whole grid (~10-25 s on CPU)
    private const PREDICT_TIMEOUT = 120;
    private const FAST_TIMEOUT = 60;

    private function modelUrl(string $path): string
    {
        return rtrim(config('services.flood_model.url'), '/') . $path;
    }

    private function storm(Request $request): array
    {
        $v = $request->validate([
            'intensity' => 'required|numeric|min:0|max:120',
            'duration'  => 'required|numeric|min:1|max:6',
        ]);
        return ['intensity' => (float) $v['intensity'], 'duration' => (float) $v['duration']];
    }

    private function point(Request $request): array
    {
        $v = $request->validate([
            'lat' => 'required|numeric|between:-1.6,-1.0',
            'lng' => 'required|numeric|between:36.5,37.3',
        ]);
        return ['lat' => (float) $v['lat'], 'lng' => (float) $v['lng']];
    }

    private function forward(string $path, array $query, int $timeout = self::FAST_TIMEOUT)
    {
        $t0 = microtime(true);
        $elapsed = fn () => (int) round((microtime(true) - $t0) * 1000);
        try {
            $response = Http::timeout($timeout)->get($this->modelUrl($path), $query);
        } catch (ConnectionException $e) {
            SystemMonitor::recordRequest($path, $elapsed(), false);
            return response()->json(['error' => 'The flood model server is not running. Start it with: '
                . 'uvicorn model_api:app --host 127.0.0.1 --port 8001'], 503);
        }
        // Client errors (e.g. a point outside the map) are the user's input, not a system failure
        SystemMonitor::recordRequest($path, $elapsed(), $response->status() < 500);

        if ($response->failed()) {
            $detail = $response->json('detail') ?? 'Model server error';
            return response()->json(['error' => is_string($detail) ? $detail : 'Invalid request'], $response->status());
        }

        return response($response->body(), 200)
            ->header('Content-Type', $response->header('Content-Type') ?: 'application/json');
    }

    public function predict(Request $request)
    {
        return $this->forward('/predict', $this->storm($request), self::PREDICT_TIMEOUT);
    }

    public function overlay(Request $request)
    {
        return $this->forward('/overlay.png', $this->storm($request), self::PREDICT_TIMEOUT)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function zones(Request $request)
    {
        return $this->forward('/zones', $this->storm($request), self::PREDICT_TIMEOUT)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function extent(Request $request)
    {
        return $this->forward('/extent', $this->storm($request), self::PREDICT_TIMEOUT)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function pointInfo(Request $request)
    {
        return $this->forward('/point', $this->point($request) + $this->storm($request), self::PREDICT_TIMEOUT);
    }

    public function explain(Request $request)
    {
        return $this->forward('/explain', $this->point($request) + $this->storm($request), self::PREDICT_TIMEOUT);
    }

    public function route(Request $request)
    {
        $v = $request->validate([
            'start_lat' => 'required|numeric|between:-1.6,-1.0',
            'start_lng' => 'required|numeric|between:36.5,37.3',
            'end_lat'   => 'required|numeric|between:-1.6,-1.0',
            'end_lng'   => 'required|numeric|between:36.5,37.3',
            'vehicle'   => 'required|string|in:boda_boda,saloon,large_car,suv_4x4,lorry_bus',
        ]);
        return $this->forward('/route', $v + $this->storm($request), self::PREDICT_TIMEOUT);
    }

    public function subcounties()
    {
        return $this->forward('/subcounties', [])->header('Cache-Control', 'public, max-age=86400');
    }

    public function modelInfo()
    {
        return $this->forward('/health', []);
    }
}
