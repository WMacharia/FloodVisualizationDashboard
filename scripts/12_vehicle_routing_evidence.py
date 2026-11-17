"""
PHASE 12: EVIDENCE THAT ROUTING SHOULD DEPEND ON THE VEHICLE

Three checks, written to data/validation/vehicle_routing_evidence.json:

A. Simulator (physics only, no model): on cells crossed by roads in Nairobi County, how much road is unsafe
   for each vehicle class (ARR Project 10 limits, notebook cell 12b rule), and how much of it is unsafe only
   because of water speed (DV over the limit while the depth is within it).
B. Deployed model (with safety margins): kilometres of road the router closes for each vehicle.
C. Routes: random trips across the county; how often vehicles get different outcomes for the same trip.

Usage:
    python 12_vehicle_routing_evidence.py
"""
import os
import json
import time
import numpy as np

import model_api as api

STORMS = [(20, 2), (35, 2), (50, 3), (90, 3)]
ROUTE_STORM = (35, 2)
N_TRIPS = 25
TRIP_KM = (3.0, 15.0)
SEED = 7
ROUTE_VEHICLES = ["boda_boda", "saloon", "large_car", "suv_4x4"]   # lorry_bus has the same limits as suv_4x4

script_dir = os.path.dirname(os.path.abspath(__file__))
out_path = os.path.join(script_dir, "data", "validation", "vehicle_routing_evidence.json")


def sim_unsafe(depth, dv, v):
    """Notebook cell 12b rule, on simulator output."""
    wet = depth > api.S.flood_depth
    if v["dv_m2s"] is None:
        return wet, np.zeros_like(wet)
    too_deep = depth > v["depth_m"]
    too_fast = dv > v["dv_m2s"]
    return wet & (too_deep | too_fast), wet & too_fast & ~too_deep


def main():
    t0 = time.time()
    api.load_everything()
    S, g = api.S, api.S.g
    d = np.load(os.path.join(script_dir, "data", "surrogate", "nairobi_surrogate_dataset.npz"))
    storms = [tuple(map(float, s)) for s in d["storms"]]

    # Road cells inside the county, each weighted by the road length that crosses it
    ci = g["cell_idx"]
    edge_of_entry = np.repeat(np.arange(len(g["edge_u"])), S.edge_ncells)
    per_entry_len = g["edge_len_m"][edge_of_entry] / np.maximum(S.edge_ncells[edge_of_entry], 1)
    road_m = np.bincount(ci, weights=per_entry_len, minlength=S.H * S.W)
    road_m = np.where(S.county.ravel(), road_m, 0.0)
    total_road_km = road_m.sum() / 1000

    out = {"road_km_in_county": round(total_road_km, 1), "simulator": {}, "model": {}, "routes": {}}

    # A. Simulator
    for i, dh in STORMS:
        s = storms.index((float(i), float(dh)))
        dep, dv = d["depth"][s].astype(np.float32).ravel(), d["dv"][s].astype(np.float32).ravel()
        row = {}
        for key, v in S.vehicles.items():
            unsafe, speed_only = sim_unsafe(dep, dv, v)
            row[key] = {"unsafe_road_km": round(road_m[unsafe].sum() / 1000, 1),
                        "speed_only_km": round(road_m[speed_only].sum() / 1000, 1),
                        "speed_only_share": round(road_m[speed_only].sum() / max(road_m[unsafe].sum(), 1e-9), 3)}
        out["simulator"][f"{i}x{dh}"] = row
        print(f"A {i} mm/h x {dh} h: " + ", ".join(f"{k} {r['unsafe_road_km']} km ({r['speed_only_share'] * 100:.0f}% speed only)"
                                                  for k, r in row.items()), flush=True)

    # B. Deployed model, as the router sees it
    county_edge = np.zeros(len(g["edge_u"]), bool)
    np.logical_or.at(county_edge, edge_of_entry, S.county.ravel()[ci])
    for i, dh in STORMS:
        hz = api.edge_hazard(api.get_maps(i, float(dh)))
        row = {}
        for key in S.vehicles:
            unsafe, _ = api.edge_states(hz, key)
            row[key] = round(float(g["edge_len_m"][unsafe & county_edge].sum()) / 1000, 1)
        out["model"][f"{i}x{dh}"] = {"closed_road_km": row}
        print(f"B {i} mm/h x {dh} h closed km: {row}", flush=True)

    # C. Routes: random trips between main-network nodes inside the county
    rng = np.random.default_rng(SEED)
    main = np.flatnonzero(g["node_main"])
    xy = g["node_xy"][main]
    in_county = S.county.ravel()[np.maximum(api.cells_of(xy[:, 0], xy[:, 1]), 0)] & (api.cells_of(xy[:, 0], xy[:, 1]) >= 0)
    cand = main[in_county]
    m_xy = api.to_metres(g["node_xy"][cand, 0], g["node_xy"][cand, 1])
    trips = []
    while len(trips) < N_TRIPS:
        a, b = rng.choice(len(cand), 2, replace=False)
        km = np.hypot(*(m_xy[a] - m_xy[b])) / 1000
        if TRIP_KM[0] <= km <= TRIP_KM[1]:
            trips.append((g["node_xy"][cand[a]], g["node_xy"][cand[b]], round(km, 1)))

    i, dh = ROUTE_STORM
    results = []
    for (lng_a, lat_a), (lng_b, lat_b), km in trips:
        r = {"straight_km": km}
        for key in ROUTE_VEHICLES:
            try:
                res = api.route(lat_a, lng_a, lat_b, lng_b, vehicle=key, intensity=i, duration=dh)
                p = res["properties"]
                safe = next((f["properties"] for f in res["features"] if f["properties"].get("route_type") == "safe"), None)
                r[key] = {"status": p["status"], "km": (safe or p["direct"])["distance_km"] if p["status"] != "no_safe_route" else None}
            except Exception as e:   # e.g. no road connection
                r[key] = {"status": "error", "detail": str(getattr(e, "detail", e))}
        results.append(r)
        print(f"C trip {len(results)}/{N_TRIPS} ({km} km): " + ", ".join(f"{k}={r[k]['status']}" for k in ROUTE_VEHICLES), flush=True)

    ok = [r for r in results if all(r[k]["status"] != "error" for k in ROUTE_VEHICLES)]
    differ = [r for r in ok if len({(r[k]["status"], r[k]["km"]) for k in ROUTE_VEHICLES}) > 1]
    status_counts = {k: {s: sum(r[k]["status"] == s for r in ok) for s in ("clear", "detour", "no_safe_route")} for k in ROUTE_VEHICLES}
    out["routes"] = {
        "storm": f"{i} mm/h x {dh} h", "trips": len(ok), "trips_with_different_outcomes": len(differ),
        "status_counts": status_counts,
        "boda_blocked_but_suv_can_go": sum(r["boda_boda"]["status"] == "no_safe_route" and r["suv_4x4"]["status"] != "no_safe_route" for r in ok),
        "saloon_detour_but_suv_direct": sum(r["saloon"]["status"] == "detour" and r["suv_4x4"]["status"] == "clear" for r in ok),
        "trips": results,
    }
    print(f"C: {len(differ)}/{len(ok)} trips get a different outcome depending on the vehicle; {status_counts}")

    with open(out_path, "w", encoding="utf-8") as fh:
        json.dump(out, fh, indent=1)
    print(f"Saved {out_path} ({time.time() - t0:.0f} s)")


if __name__ == "__main__":
    main()
