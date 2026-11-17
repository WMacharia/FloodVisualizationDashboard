"""
PHASE 11: SAFETY MARGINS FOR VEHICLE ROUTING

The U-Net misses some road cells the simulator calls unsafe (recall ~0.83 at the published limits).
Missing an unsafe road is worse than an unnecessary detour, so the dashboard applies each vehicle's
limit with a safety margin:

  cars / lorries : a cell is unsafe if predicted depth > f * depth limit  OR  predicted DV > f * DV limit
  boda boda      : a cell is unsafe if it is flooded at a lower probability threshold p (its limit is
                   "any flooding", so there is no depth to scale)

f (or p) is the least strict value whose recall on the VALIDATION blocks, over all training storms,
reaches TARGET_RECALL. It is then scored once on the TEST blocks (training storms and the two held-out
storms), which were not used to choose it. Truth uses the notebook's rule (cell 12b): unsafe if the
simulated depth > 0.15 m and (depth > limit or DV > DV limit).

Uses the deployed model exactly as the dashboard runs it (model_api.predict_full).
Output: data/validation/vehicle_margins.json (read by model_api.py at start-up)

Usage:
    python 11_calibrate_vehicle_margins.py
"""
import os
import json
import time
import numpy as np

import model_api as api

TARGET_RECALL = 0.90
FACTORS = np.round(np.arange(1.0, 0.195, -0.025), 3)        # 1.0 = published limit
PROB_THRESHOLDS = np.round(np.arange(0.70, 0.045, -0.025), 3)  # boda boda

script_dir = os.path.dirname(os.path.abspath(__file__))
out_path = os.path.join(script_dir, "data", "validation", "vehicle_margins.json")


def unsafe(depth, dv, flooded, v, f=1.0):
    if v["dv_m2s"] is None:
        return flooded
    return flooded & ((depth > f * v["depth_m"]) | (dv > f * v["dv_m2s"]))


def scores(pred, true):
    tp, fp, fn = int(np.sum(pred & true)), int(np.sum(pred & ~true)), int(np.sum(~pred & true))
    return {"recall": tp / max(tp + fn, 1), "precision": tp / max(tp + fp, 1), "f1": 2 * tp / max(2 * tp + fp + fn, 1)}


def main():
    api.load_everything()
    assert api.S.has_dv, "This calibration needs the depth x velocity output"
    d = np.load(os.path.join(script_dir, "data", "surrogate", "nairobi_surrogate_dataset.npz"))
    storms, split, holdout = d["storms"], d["split"], d["holdout"]
    show = api.S.show
    masks = {"val": (split == 1) & show, "test": (split == 2) & show}

    # Model outputs and simulator truth for validation and test cells, every storm
    rows = {"val": [], "test": []}
    t0 = time.time()
    for s, (i, dh) in enumerate(storms):
        m = api.predict_full(float(i), float(dh))
        dep_true, dv_true = d["depth"][s].astype(np.float32), d["dv"][s].astype(np.float32)
        for name, mk in masks.items():
            if name == "val" and holdout[s]:
                continue   # held-out storms are never used for choosing anything
            rows[name].append(dict(held=bool(holdout[s]), prob=m["prob"][mk], dep=m["depth"][mk], dv=m["dv"][mk],
                                   dep_t=dep_true[mk], dv_t=dv_true[mk]))
        print(f"  storm {i:.0f} mm/h x {dh:g} h done ({time.time() - t0:.0f} s)", flush=True)

    cat = lambda name, key, sel=lambda r: True: np.concatenate([r[key] for r in rows[name] if sel(r)])
    thr, fd = api.S.threshold, api.S.flood_depth
    # best_val_iou identifies the model these margins belong to (model_api ignores them for any other model)
    result = {"target_recall": TARGET_RECALL, "flood_threshold": thr,
              "model_best_val_iou": api.S.cfg.get("best_val_iou"), "vehicles": {}}

    for key, v in api.S.vehicles.items():
        val = {k: cat("val", k) for k in ("prob", "dep", "dv", "dep_t", "dv_t")}
        truth = unsafe(val["dep_t"], val["dv_t"], val["dep_t"] > fd, v)
        if v["dv_m2s"] is None:
            sweep = [(p, scores(val["prob"] >= p, truth)) for p in PROB_THRESHOLDS]
            choice = next((p for p, sc in sweep if sc["recall"] >= TARGET_RECALL), PROB_THRESHOLDS[-1])
            rule = {"prob_threshold": float(choice), "factor": 1.0}
        else:
            flooded = val["prob"] >= thr
            sweep = [(f, scores(unsafe(val["dep"], val["dv"], flooded, v, f), truth)) for f in FACTORS]
            choice = next((f for f, sc in sweep if sc["recall"] >= TARGET_RECALL), FACTORS[-1])
            rule = {"prob_threshold": thr, "factor": float(choice)}

        def test_scores(sel, r_):
            t = {k: cat("test", k, sel) for k in ("prob", "dep", "dv", "dep_t", "dv_t")}
            truth_t = unsafe(t["dep_t"], t["dv_t"], t["dep_t"] > fd, v)
            pred = unsafe(t["dep"], t["dv"], t["prob"] >= r_["prob_threshold"], v, r_["factor"])
            return {k: round(x, 4) for k, x in scores(pred, truth_t).items()}

        published = {"prob_threshold": thr, "factor": 1.0}
        result["vehicles"][key] = {
            **rule,
            "decision_depth_m": round(rule["factor"] * v["depth_m"], 3),
            "decision_dv_m2s": round(rule["factor"] * v["dv_m2s"], 3) if v["dv_m2s"] is not None else None,
            "validation_sweep": [{"value": float(x), **{k: round(y, 4) for k, y in sc.items()}} for x, sc in sweep],
            "test": {
                "published_limits": {"training_storms": test_scores(lambda r: not r["held"], published),
                                     "heldout_storms": test_scores(lambda r: r["held"], published)},
                "with_margin": {"training_storms": test_scores(lambda r: not r["held"], rule),
                                "heldout_storms": test_scores(lambda r: r["held"], rule)},
            },
        }
        tm = result["vehicles"][key]["test"]
        print(f"{v['label']:24s} rule {rule} | test recall {tm['published_limits']['training_storms']['recall']:.3f} -> "
              f"{tm['with_margin']['training_storms']['recall']:.3f}, precision {tm['published_limits']['training_storms']['precision']:.3f} -> "
              f"{tm['with_margin']['training_storms']['precision']:.3f} (held-out recall {tm['with_margin']['heldout_storms']['recall']:.3f})")

    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    with open(out_path, "w", encoding="utf-8") as fh:
        json.dump(result, fh, indent=1)
    print(f"Saved {out_path}")


if __name__ == "__main__":
    main()
