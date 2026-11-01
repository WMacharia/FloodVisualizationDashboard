<?php

namespace App\Http\Controllers;

use App\Models\SavedPlace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Throwable;

// A resident's saved places (home, work, school). Their sub-county decides which advisories reach them.
class PlaceController extends Controller
{
    public function index()
    {
        return response()->json(Auth::user()->places()->orderBy('created_at')->get(['id', 'name', 'lat', 'lng', 'subcounty']));
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'name' => 'required|string|max:60',
            'lat' => 'required|numeric|between:-1.6,-1.0',
            'lng' => 'required|numeric|between:36.5,37.3',
        ]);
        $user = Auth::user();
        if ($user->places()->count() >= SavedPlace::MAX_PER_USER) {
            return response()->json(['error' => 'You can save up to ' . SavedPlace::MAX_PER_USER . ' places. Remove one first.'], 422);
        }

        // The model server knows which sub-county a point is in
        $subcounty = null;
        try {
            $r = Http::timeout(30)->get(rtrim(config('services.flood_model.url'), '/') . '/point',
                ['lat' => $v['lat'], 'lng' => $v['lng'], 'intensity' => 10, 'duration' => 1]);
            $subcounty = $r->json('subcounty');
        } catch (Throwable $e) {
        }
        if (!$subcounty) {
            return response()->json(['error' => 'That location is outside Nairobi County, or the model server is unavailable.'], 422);
        }

        $place = $user->places()->create($v + ['subcounty' => $subcounty]);
        return response()->json($place->only(['id', 'name', 'lat', 'lng', 'subcounty']), 201);
    }

    public function destroy(SavedPlace $place)
    {
        abort_unless($place->user_id === Auth::id(), 404);
        $place->delete();
        return response()->json(['ok' => true]);
    }
}
