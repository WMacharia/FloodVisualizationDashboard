"""
PHASE 8: PHYSICS-BASED FLOOD SCENARIOS (label generation for the U-Net surrogate)

Simulates pluvial (surface-water) flooding over Nairobi for a grid of design storms
and saves the maximum water depth of each run. These depth maps are the training
labels for the U-Net, which learns to emulate this simulator.

Hydrodynamics : local inertial approximation of the 2D shallow water equations
                (Bates, Horritt & Fewtrell, 2010, Journal of Hydrology 387, 33-45),
                the scheme used by LISFLOOD-FP.
Runoff        : SCS Curve Number method (USDA-NRCS TR-55, 1986), CN from ESA WorldCover,
                area-weighted with roofs (CN 98) by building coverage (composite CN).
Buildings     : sub-grid porosity (Defina, 2000; Sanders et al., 2008). Building coverage f
                from 08c_building_fraction.py; storage porosity phi = 1 - f, and flow through
                a face is limited to its open part, min(phi_a, phi_b). Depths are open-space
                (street-level) depths.
Assumptions   : Hydrologic Soil Group C everywhere, no storm-drain capacity,
                no inflow from catchments outside the bounding box, free outflow at edges.

Usage:
    python 08_simulate_flood_scenarios.py                 # all scenarios
    python 08_simulate_flood_scenarios.py --test 600      # time a 600 s run of one storm
    python 08_simulate_flood_scenarios.py --no-buildings  # bare-earth version (no porosity)
"""
import os
import time
import argparse
import numpy as np
import rasterio
from numba import njit, prange

script_dir = os.path.dirname(os.path.abspath(__file__))
aligned_dir = os.path.join(script_dir, "data", "aligned")
out_dir = os.path.join(script_dir, "data", "scenarios")
os.makedirs(out_dir, exist_ok=True)

G = 9.81
H_MIN = 1e-3          # depth below which a face carries no flow (m)
ALPHA = 0.7           # CFL safety factor
DT_MAX = 10.0         # s
POST_STORM_S = 3600   # keep simulating 1 h after rain stops so water can collect
THETA = 0.7           # de Almeida et al. (2012) flux weighting; 1.0 = original Bates scheme
FROUDE_MAX = 1.0      # cap on flow speed relative to wave celerity
ROOF_CN = 98          # TR-55 impervious surfaces
MAX_BUILDING_FRAC = 0.9  # keeps porosity >= 0.1 so fully built cells still pass water

# Design storms: intensity (mm/hr) x duration (hr)
INTENSITIES = [10, 20, 35, 50, 70, 90, 120]
DURATIONS = [1, 2, 3, 6]

# ESA WorldCover class -> (Curve Number, HSG C, TR-55 Table 2-2) and Manning's n
#   10 Tree cover        woods, fair            CN 73   n 0.10
#   20 Shrubland         brush, fair            CN 70   n 0.07
#   30 Grassland         pasture, fair          CN 79   n 0.035
#   40 Cropland          row crops, SR, good    CN 85   n 0.035
#   50 Built-up          commercial/business    CN 94   n 0.015
#   60 Bare/sparse       newly graded areas     CN 91   n 0.025
#   80 Permanent water   all rain is runoff     CN 100  n 0.03
#   90 Herbaceous wetland                       CN 98   n 0.06
LANDCOVER = {
    10: (73, 0.10), 20: (70, 0.07), 30: (79, 0.035), 40: (85, 0.035),
    50: (94, 0.015), 60: (91, 0.025), 80: (100, 0.03), 90: (98, 0.06),
}
DEFAULT_CN_N = (79, 0.035)  # for nodata (class 0) cells


def load_inputs(use_buildings=True):
    # Prefer bare-earth FABDEM (08a_download_fabdem.py); ALOS DSM contains building/tree pits
    dem_path = os.path.join(aligned_dir, "aligned_fabdem.tif")
    if not os.path.exists(dem_path):
        print("[WARN] aligned_fabdem.tif not found - falling back to ALOS DSM (expect artificial pits)")
        dem_path = os.path.join(aligned_dir, "aligned_dem.tif")
    with rasterio.open(dem_path) as src:
        z = src.read(1).astype(np.float64)
        profile = src.profile
        transform = src.transform
    with rasterio.open(os.path.join(aligned_dir, "aligned_esa.tif")) as src:
        esa = src.read(1)

    cn = np.full(esa.shape, DEFAULT_CN_N[0], dtype=np.float64)
    n = np.full(esa.shape, DEFAULT_CN_N[1], dtype=np.float64)
    for cls, (c, m) in LANDCOVER.items():
        cn[esa == cls] = c
        n[esa == cls] = m

    bldg_path = os.path.join(aligned_dir, "aligned_building_frac.tif")
    if use_buildings and os.path.exists(bldg_path):
        with rasterio.open(bldg_path) as src:
            f = np.clip(src.read(1).astype(np.float64), 0.0, MAX_BUILDING_FRAC)
        cn = (1.0 - f) * cn + f * ROOF_CN
    else:
        if use_buildings:
            print("[WARN] aligned_building_frac.tif not found - run 08c_building_fraction.py; simulating without buildings")
        f = np.zeros_like(cn)
    phi = 1.0 - f

    # Cell size in metres (grid is in degrees, EPSG:4326)
    rows = z.shape[0]
    lat_c = transform.f + transform.e * rows / 2.0
    dx = abs(transform.a) * 111320.0 * np.cos(np.radians(lat_c))
    dy = abs(transform.e) * 110574.0
    return z, cn, n, phi, float(dx), float(dy), profile


@njit(cache=True)
def scs_runoff(p_mm, s_mm):
    """Cumulative SCS-CN runoff (mm) for cumulative rainfall p_mm, Ia = 0.2 S."""
    ia = 0.2 * s_mm
    if p_mm <= ia:
        return 0.0
    return (p_mm - ia) ** 2 / (p_mm + 0.8 * s_mm)


@njit(cache=True)
def face_flux(q, q_prev, q_next, h_a, h_b, z_a, z_b, n_a, n_b, dt, d_along):
    """
    Local inertial flux (m2/s) across the face from cell a to cell b, with
      - de Almeida et al. (2012) theta-weighting for stability on low-friction surfaces,
      - a Froude-number limit (Fr <= FROUDE_MAX),
      - a volume limit so no cell can lose more than 1/4 of its water through one face.
    """
    eta_a = h_a + z_a
    eta_b = h_b + z_b
    hf = max(eta_a, eta_b) - max(z_a, z_b)
    if hf <= H_MIN:
        return 0.0
    nn = 0.5 * (n_a + n_b)
    q_w = THETA * q + 0.5 * (1.0 - THETA) * (q_prev + q_next)
    q_new = (q_w - G * hf * dt * (eta_b - eta_a) / d_along) / \
            (1.0 + G * dt * nn * nn * abs(q) / hf ** (7.0 / 3.0))

    q_froude = FROUDE_MAX * hf * np.sqrt(G * hf)
    if q_new > q_froude:
        q_new = q_froude
    elif q_new < -q_froude:
        q_new = -q_froude

    if q_new > 0.0:
        q_cap = h_a * d_along / (4.0 * dt)
        if q_new > q_cap:
            q_new = q_cap
    else:
        q_cap = h_b * d_along / (4.0 * dt)
        if -q_new > q_cap:
            q_new = -q_cap
    return q_new


@njit(parallel=True, cache=True)
def step(z, h, qx, qy, qx_old, qy_old, n, s_mm, phi, psix, psiy, p_prev, p_now, dt, dx, dy):
    """
    Advance one time step. Returns (rain excess added, volume lost at edges) in m3.
    h is the open-space depth; a cell stores phi * h * dx * dy. Face fluxes are open-space
    unit-width fluxes, scaled by face porosity psi when they move water between cells.
    psi = min(phi_a, phi_b) <= phi of the donor cell, so the volume limit in face_flux still holds.
    """
    H, W = h.shape
    qx_old[:, :] = qx
    qy_old[:, :] = qy

    # Flux across vertical faces: qx[i, j] is between cell (i, j-1) and (i, j)
    for i in prange(H):
        for j in range(1, W):
            qx[i, j] = face_flux(qx_old[i, j], qx_old[i, j - 1], qx_old[i, j + 1],
                                 h[i, j - 1], h[i, j], z[i, j - 1], z[i, j],
                                 n[i, j - 1], n[i, j], dt, dx)

    # Flux across horizontal faces: qy[i, j] is between cell (i-1, j) and (i, j)
    for i in prange(1, H):
        for j in range(W):
            qy[i, j] = face_flux(qy_old[i, j], qy_old[i - 1, j], qy_old[i + 1, j],
                                 h[i - 1, j], h[i, j], z[i - 1, j], z[i, j],
                                 n[i - 1, j], n[i, j], dt, dy)

    # Continuity + rainfall excess
    rain_vol = 0.0
    for i in prange(H):
        row_rain = 0.0
        for j in range(W):
            excess = (scs_runoff(p_now, s_mm[i, j]) - scs_runoff(p_prev, s_mm[i, j])) / 1000.0
            dq = (psix[i, j] * qx[i, j] - psix[i, j + 1] * qx[i, j + 1]) / dx + \
                 (psiy[i, j] * qy[i, j] - psiy[i + 1, j] * qy[i + 1, j]) / dy
            # Rain on roofs drains into the open part of the cell
            hv = h[i, j] + (dt * dq + excess) / phi[i, j]
            h[i, j] = hv if hv > 0.0 else 0.0
            row_rain += excess
        rain_vol += row_rain

    # Free outflow: edges act as sinks
    edge = 0.0
    for j in range(W):
        edge += phi[0, j] * h[0, j] + phi[H - 1, j] * h[H - 1, j]
        h[0, j] = 0.0
        h[H - 1, j] = 0.0
    for i in range(1, H - 1):
        edge += phi[i, 0] * h[i, 0] + phi[i, W - 1] * h[i, W - 1]
        h[i, 0] = 0.0
        h[i, W - 1] = 0.0
    return rain_vol * dx * dy, edge * dx * dy


@njit(parallel=True, cache=True)
def update_max(h, qx, qy, hmax_map, dvmax_map):
    """
    Track maximum depth and maximum depth x velocity (DV, m2/s) per cell.
    DV is the magnitude of the cell-centred unit discharge (average of opposite faces),
    the quantity used in vehicle/people stability criteria (e.g. ARR Project 10).
    """
    H, W = h.shape
    for i in prange(H):
        for j in range(W):
            if h[i, j] > hmax_map[i, j]:
                hmax_map[i, j] = h[i, j]
            if h[i, j] > H_MIN:
                qcx = 0.5 * (qx[i, j] + qx[i, j + 1])
                qcy = 0.5 * (qy[i, j] + qy[i + 1, j])
                dv = np.sqrt(qcx * qcx + qcy * qcy)
                if dv > dvmax_map[i, j]:
                    dvmax_map[i, j] = dv


def simulate(z, cn, n, phi, dx, dy, intensity_mmhr, duration_hr, t_end=None, verbose=True):
    H, W = z.shape
    h = np.zeros((H, W))
    qx = np.zeros((H, W + 1))
    qy = np.zeros((H + 1, W))
    qx_old = np.zeros_like(qx)
    qy_old = np.zeros_like(qy)
    hmax_map = np.zeros((H, W))
    dvmax_map = np.zeros((H, W))
    s_mm = 25400.0 / cn - 254.0
    # Face porosity: open fraction shared by both neighbours (outer faces carry no flow)
    psix = np.ones((H, W + 1))
    psix[:, 1:W] = np.minimum(phi[:, :-1], phi[:, 1:])
    psiy = np.ones((H + 1, W))
    psiy[1:H, :] = np.minimum(phi[:-1, :], phi[1:, :])

    storm_s = duration_hr * 3600.0
    t_end = t_end if t_end is not None else storm_s + POST_STORM_S
    t, steps, cur_hmax = 0.0, 0, 0.0
    vol_in, vol_out = 0.0, 0.0
    last_print = time.time()

    while t < t_end:
        dt = DT_MAX if cur_hmax < H_MIN else min(DT_MAX, ALPHA * min(dx, dy) / np.sqrt(G * cur_hmax))
        dt = min(dt, t_end - t)
        p_prev = intensity_mmhr * min(t, storm_s) / 3600.0
        p_now = intensity_mmhr * min(t + dt, storm_s) / 3600.0
        v_rain, v_edge = step(z, h, qx, qy, qx_old, qy_old, n, s_mm, phi, psix, psiy,
                              p_prev, p_now, dt, dx, dy)
        vol_in += v_rain
        vol_out += v_edge
        update_max(h, qx, qy, hmax_map, dvmax_map)
        cur_hmax = float(h.max())
        t += dt
        steps += 1
        if verbose and time.time() - last_print > 30:
            print(f"    t={t / 60:6.1f}/{t_end / 60:.0f} min  dt={dt:5.2f}s  max depth={cur_hmax:5.2f} m")
            last_print = time.time()

    vol_stored = float((phi * h).sum()) * dx * dy
    mass_error = (vol_stored + vol_out - vol_in) / max(vol_in, 1.0)
    balance = {"rain_excess_m3": vol_in, "edge_outflow_m3": vol_out,
               "stored_m3": vol_stored, "mass_error_pct": mass_error * 100.0}
    if verbose:
        print(f"    mass balance: in {vol_in:,.0f} m3 | out {vol_out:,.0f} m3 | "
              f"stored {vol_stored:,.0f} m3 | error {mass_error * 100:+.3f}%")
    return hmax_map.astype(np.float32), dvmax_map.astype(np.float32), steps, balance


def save_depth(arr, profile, path, tags):
    prof = profile.copy()
    prof.update(dtype=rasterio.float32, count=1, compress="lzw", nodata=None)
    with rasterio.open(path, "w", **prof) as dst:
        dst.write(arr, 1)
        dst.update_tags(**{k: str(v) for k, v in tags.items()})


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--test", type=float, default=None, help="simulate only this many seconds of a 50 mm/hr storm")
    parser.add_argument("--overwrite", action="store_true")
    parser.add_argument("--no-buildings", action="store_true", help="bare-earth run without building porosity")
    args = parser.parse_args()

    z, cn, n, phi, dx, dy, profile = load_inputs(use_buildings=not args.no_buildings)
    print(f"Grid {z.shape[0]}x{z.shape[1]}, cell {dx:.1f} m x {dy:.1f} m, "
          f"mean porosity {phi.mean():.3f}")

    if args.test:
        t0 = time.time()
        hmax, dvmax, steps, _ = simulate(z, cn, n, phi, dx, dy, 50, 2, t_end=args.test)
        el = time.time() - t0
        print(f"[TEST] {args.test:.0f} s simulated in {el:.1f} s wall ({steps} steps), "
              f"max depth {hmax.max():.2f} m, max DV {dvmax.max():.2f} m2/s")
        return

    for d in DURATIONS:
        for i in INTENSITIES:
            path = os.path.join(out_dir, f"depth_i{i:03d}_d{d}.tif")
            dv_path = os.path.join(out_dir, f"dv_i{i:03d}_d{d}.tif")
            if os.path.exists(path) and os.path.exists(dv_path) and not args.overwrite:
                print(f"skip {os.path.basename(path)} (exists)")
                continue
            print(f"Simulating {i} mm/hr for {d} h ...")
            t0 = time.time()
            hmax, dvmax, steps, balance = simulate(z, cn, n, phi, dx, dy, i, d)
            tags = {
                "intensity_mmhr": i, "duration_hr": d, "steps": steps,
                "model": "local-inertial SWE (theta=%.1f) + SCS-CN (HSG C)" % THETA,
                "buildings": "none" if args.no_buildings else "porosity (1 - building fraction)",
                **{k: round(v, 3) for k, v in balance.items()},
            }
            save_depth(hmax, profile, path, tags)
            save_depth(dvmax, profile, dv_path, {**tags, "quantity": "max depth x velocity (m2/s)"})
            wet = hmax > 0.15
            print(f"  saved {os.path.basename(path)}  max {hmax.max():.2f} m, "
                  f"cells >0.15 m: {wet.mean() * 100:.2f}%, DV p95 in wet cells "
                  f"{np.percentile(dvmax[wet], 95) if wet.any() else 0:.2f} m2/s  ({time.time() - t0:.0f} s)")


if __name__ == "__main__":
    main()
