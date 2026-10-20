<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class MapController extends Controller
{
    public function getFloodZones(Request $request)
    {
        $intensity = (float) $request->query('intensity', 45);
        $duration  = (float) $request->query('duration', 2);

        // 1. Call FastAPI on port 8001 (isolated try/catch so PHP errors aren't misreported)
        try {
            $response = Http::timeout(60)->get('http://127.0.0.1:8001/predict', [
                'intensity' => $intensity,
                'duration'  => $duration,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
                'error' => 'FastAPI model server on port 8001 is not reachable: ' . $e->getMessage()
            ]);
        }

        if (!$response->successful()) {
            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
                'error' => 'FastAPI returned HTTP ' . $response->status()
            ]);
        }

        // 2. Intersect detected flood zones with PostGIS nairobi_roads
        $data = $response->json();
        $features = $data['features'] ?? [];

        $verifiedFeatures = [];
        $zoneMeta = [];

        foreach ($features as $f) {
            $p = $f['properties'] ?? [];
            $zId = $p['zone_id'] ?? 0;

            if (!isset($zoneMeta[$zId])) {
                $geomJson = json_encode($f['geometry']);
                $cnt = 0;
                $roadName = null;

                try {
                    $row = DB::selectOne("
                        SELECT COUNT(id) AS cnt
                        FROM nairobi_roads
                        WHERE ST_Intersects(geom, ST_SetSRID(ST_GeomFromGeoJSON(?), ST_SRID(geom)))
                    ", [$geomJson]);
                    $cnt = (int) ($row->cnt ?? 0);

                    $cLat = (float) ($p['center_lat'] ?? -1.288);
                    $cLng = (float) ($p['center_lng'] ?? 36.825);
                    $sampleRows = DB::select("
                        SELECT to_jsonb(r) - 'geom' AS props
                        FROM nairobi_roads r
                        WHERE ST_Intersects(r.geom, ST_SetSRID(ST_GeomFromGeoJSON(?), ST_SRID(r.geom)))
                        ORDER BY r.geom <-> ST_SetSRID(ST_MakePoint(?, ?), ST_SRID(r.geom))
                        LIMIT 15
                    ", [$geomJson, $cLng, $cLat]);

                    $names = [];
                    foreach ($sampleRows as $sr) {
                        $rp = json_decode($sr->props, true) ?: [];
                        $candidate = $rp['name'] ?? $rp['street'] ?? $rp['road_name'] ?? $rp['ref'] ?? null;
                        if (!empty($candidate) && !in_array($candidate, $names)) {
                            $names[] = $candidate;
                            if (count($names) >= 2) break;
                        }
                    }
                    if (!empty($names)) {
                        $roadName = implode(' / ', $names);
                    }
                } catch (\Exception $ex) {}

                $zoneMeta[$zId] = [
                    'cnt' => $cnt,
                    'road_name' => $roadName
                ];
            }

            // Fixed: single $cnt
            $cnt = $zoneMeta[$zId]['cnt'];
            $fullLabel = strtolower(($zoneMeta[$zId]['road_name'] ?? '') . ' ' . ($p['name'] ?? ''));

            // Exclude peri-urban Kiambu/Kajiado/Machakos border wards
            $isOutOfCounty = preg_match('/\b(gataka|nkaimurunya|wangige|banana|karuri|rongai|ronkai|mavoko|matathani|ruiru|kiambu|kiuu|olkeri|muratha)\b/', $fullLabel);

            if ($cnt >= 145 && !$isOutOfCounty) {
                if (!empty($zoneMeta[$zId]['road_name'])) {
                    $f['properties']['name'] = $zoneMeta[$zId]['road_name'] . ' (' . $p['name'] . ')';
                }

                $exposureBoost = min(0.14, ($cnt - 140) / 1800.0);
                $adjProb = min(0.96, round(($p['flood_probability'] ?? 0.5) + $exposureBoost, 2));
                $adjDepth = round(max(0.15, ($adjProb - 0.30) * 1.35), 2);

                if ($adjProb >= 0.74) {
                    $f['properties']['peak_level'] = 3;
                    $f['properties']['hazard_label'] = 'Critical / Impassable Inundation';
                } elseif ($adjProb >= 0.56) {
                    $f['properties']['peak_level'] = 2;
                    $f['properties']['hazard_label'] = 'High Flood Hazard';
                } else {
                    $f['properties']['peak_level'] = 1;
                    $f['properties']['hazard_label'] = 'Flood Warning (Surface Pooling)';
                }

                $demPart = trim(explode('|', $p['category'] ?? 'DEM: 1650m')[0]);
                $f['properties']['flood_probability'] = $adjProb;
                $f['properties']['predicted_depth_m'] = $adjDepth;
                $f['properties']['category'] = "{$cnt} road segments impacted | {$demPart} | Prob: " . intval($adjProb * 100) . "%";
                $verifiedFeatures[] = $f;
            }
        }

        $data['features'] = $verifiedFeatures;
        return response()->json($data);
    }

    public function calculateRoute(Request $request)
    {
        $startLng   = (float) $request->query('start_lng');
        $startLat   = (float) $request->query('start_lat');
        $endLng     = (float) $request->query('end_lng');
        $endLat     = (float) $request->query('end_lat');
        $avoidFlood = $request->query('avoid_flood', 'true');
        $intensity  = (float) $request->query('intensity', 45);
        $duration   = (float) $request->query('duration', 2);

        try {
            $response = Http::timeout(20)->get('http://127.0.0.1:8001/route', [
                'start_lat'   => $startLat,
                'start_lng'   => $startLng,
                'end_lat'     => $endLat,
                'end_lng'     => $endLng,
                'avoid_flood' => $avoidFlood,
                'intensity'   => $intensity,
                'duration'    => $duration,
            ]);

            if ($response->successful()) {
                return response($response->body())->header('Content-Type', 'application/json');
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Routing engine unreachable: ' . $e->getMessage()], 500);
        }

        return response()->json(['type' => 'FeatureCollection', 'features' => []]);
    }
}