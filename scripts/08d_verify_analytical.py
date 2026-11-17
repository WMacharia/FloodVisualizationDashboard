"""
PHASE 8d: VERIFY THE FLOOD SIMULATOR AGAINST AN ANALYTICAL SOLUTION

Runs the actual numerical kernels of 08_simulate_flood_scenarios.py (face_flux / step,
imported unchanged) on the standard verification problem for this kind of scheme: a flood
wave advancing over a flat, dry, frictional plane at constant velocity u
(Hunter et al., 2005, Advances in Water Resources 28; used to test the local inertial scheme
by Bates, Horritt & Fewtrell, 2010, and de Almeida et al., 2012).

Analytical solution (derived here, not copied):
  Neglecting inertia, momentum reduces to  g h dh/dx = -g n^2 q|q| / h^(7/3).
  For a wave travelling at constant velocity u, q = u h and h = h(x - u t), which also
  satisfies continuity. Then  h^(4/3) dh = -n^2 u^2 dx, so
      (3/7) h^(7/3) = n^2 u^2 (u t - x) + C.
  With C = 0 the wave front starts at x = 0 when t = 0:
      h(x, t) = [ (7/3) n^2 u^2 (u t - x) ]^(3/7)     for x <= u t, and 0 beyond.
  Not verified against the original paper text (it could not be retrieved). The derivation
  above is self-contained. The test parameters (u, n, run time, domain) are the commonly
  quoted ones but were also not confirmed from the papers.

Setup
  - 1-D strip: one active row between two walls (raised bed), flat bed z = 0, porosity 1, no rain.
  - Upstream boundary: the ghost cell at x = 0 is set to the analytical depth h(0, t) before
    every step; the scheme computes the inflow itself, so wave speed and shape are emergent.
  - Time step: the same rule as 08 (ALPHA * dx / sqrt(g h_max), capped at DT_MAX).
  - theta = 0.7 is what 08 uses. theta = 1.0 (original Bates et al. 2010 scheme) is run from a
    copy of 08's source with only that constant changed.

Note: the analytical solution neglects inertia, whereas the simulator keeps local acceleration,
and the scheme caps the Froude number at 1. Near the wet/dry front the depth tends to zero, so
the flow there is supercritical and some difference is expected (e.g. Martins et al., 2016).

Usage:
    python 08d_verify_analytical.py
Outputs:
    data/validation/verification_flat_plane.json
    models/figures/verification_flat_plane.png
"""
import os
import json
import types
import importlib.util
import numpy as np

script_dir = os.path.dirname(os.path.abspath(__file__))
SIM_PATH = os.path.join(script_dir, "08_simulate_flood_scenarios.py")
val_dir = os.path.join(script_dir, "data", "validation")
fig_dir = os.path.join(script_dir, "models", "figures")
os.makedirs(val_dir, exist_ok=True)
os.makedirs(fig_dir, exist_ok=True)

T_END = 3600.0           # s
REPORT_TIMES = [900.0, 1800.0, 2700.0, 3600.0]
FRONT_DEPTH = 0.01       # m, depth that marks the wave front (same rule for model and solution)
WALL = 100.0             # m, bed height of the side walls

# (label, u m/s, Manning n, dx, theta)
# u = 1 m/s is the usual setting; there inertia (kept by the scheme, dropped by the solution) is
# about 10 % of the pressure term (u^2 / g h). u = 0.1 m/s makes it < 1 %, so the solution applies
# almost exactly and the remaining error is numerical; dx 30 / 10 / 5 m shows whether it converges.
CASES = [
    ("u1.0_n0.010_dx30_theta0.7", 1.0, 0.010, 30.0, 0.7),
    ("u1.0_n0.030_dx30_theta0.7", 1.0, 0.030, 30.0, 0.7),
    ("u1.0_n0.010_dx10_theta0.7", 1.0, 0.010, 10.0, 0.7),
    ("u1.0_n0.010_dx30_theta1.0", 1.0, 0.010, 30.0, 1.0),
    ("u0.1_n0.030_dx30_theta0.7", 0.1, 0.030, 30.0, 0.7),
    ("u0.1_n0.030_dx10_theta0.7", 0.1, 0.030, 10.0, 0.7),
    ("u0.1_n0.030_dx5_theta0.7", 0.1, 0.030, 5.0, 0.7),
]


def load_sim(theta):
    """Import 08 unchanged; for another theta, compile a copy with only THETA changed."""
    if theta == 0.7:
        spec = importlib.util.spec_from_file_location("sim08", SIM_PATH)
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        assert mod.THETA == 0.7
        return mod
    src = open(SIM_PATH, encoding="utf-8").read()
    assert "THETA = 0.7" in src
    src = src.replace("THETA = 0.7", f"THETA = {theta}").replace("cache=True", "cache=False")
    mod = types.ModuleType(f"sim08_theta{theta}")
    mod.__file__ = SIM_PATH
    exec(compile(src, SIM_PATH, "exec"), mod.__dict__)
    return mod


def analytical(x, t, n, u):
    a = (7.0 / 3.0) * n * n * u * u * (u * t - x)
    return np.where(a > 0, np.maximum(a, 0) ** (3.0 / 7.0), 0.0)


def front(x, h):
    wet = np.where(h > FRONT_DEPTH)[0]
    return float(x[wet[-1]]) if wet.size else 0.0


def run_case(sim, u, n_val, dx, report_times):
    length = 1.4 * u * T_END
    W = int(length / dx) + 2          # ghost cell + domain + outflow edge cell
    H = 3                              # wall / active row / wall
    z = np.zeros((H, W)); z[0, :] = WALL; z[2, :] = WALL
    h = np.zeros((H, W))
    qx = np.zeros((H, W + 1)); qy = np.zeros((H + 1, W))
    qx_old = np.zeros_like(qx); qy_old = np.zeros_like(qy)
    n = np.full((H, W), n_val)
    phi = np.ones((H, W)); psix = np.ones((H, W + 1)); psiy = np.ones((H + 1, W))
    s_mm = np.zeros((H, W))            # no rain (p_prev = p_now = 0)
    x = np.arange(W) * dx              # cell centres; the ghost cell sits at x = 0

    t, vol_in, cur_hmax, steps = 0.0, 0.0, 0.0, 0
    out, pending = {}, list(report_times)
    while t < T_END - 1e-9:
        h[1, 0] = analytical(np.array([0.0]), t, n_val, u)[0]   # upstream boundary
        cur_hmax = max(cur_hmax, h[1, 0])
        dt = sim.DT_MAX if cur_hmax < sim.H_MIN else min(sim.DT_MAX, sim.ALPHA * dx / np.sqrt(sim.G * cur_hmax))
        if pending:
            dt = min(dt, pending[0] - t)
        sim.step(z, h, qx, qy, qx_old, qy_old, n, s_mm, phi, psix, psiy, 0.0, 0.0, dt, dx, dx)
        vol_in += qx[1, 1] * dt * dx   # inflow through the boundary face (m3, strip width dx)
        cur_hmax = float(h[1, 1:].max())
        t += dt; steps += 1
        if pending and abs(t - pending[0]) < 1e-6:
            out[pending.pop(0)] = h[1, :].copy()

    stored = float(h[1, 1:W - 1].sum()) * dx * dx
    edge_out = 0.0  # the wave never reaches the outflow cell (checked below)
    assert h[1, W - 2] < 1e-9, "wave reached the downstream edge; lengthen the domain"
    mass_err = (stored + edge_out - vol_in) / vol_in * 100.0

    res = {"steps": steps, "mass_error_pct": mass_err, "times": {}}
    for tr, prof in out.items():
        xd, hs = x[1:W - 1], prof[1:W - 1]
        ha = analytical(xd, tr, n_val, u)
        fa, fs = front(xd, ha), front(xd, hs)
        m = (xd <= max(fa, fs) + 5 * dx)   # wetted region plus a margin
        err = hs[m] - ha[m]
        res["times"][str(int(tr))] = {
            "rmse_m": float(np.sqrt(np.mean(err ** 2))),
            "max_abs_err_m": float(np.max(np.abs(err))),
            "mean_err_m": float(np.mean(err)),
            "h_boundary_m": float(analytical(np.array([0.0]), tr, n_val, u)[0]),
            "front_analytical_m": fa, "front_model_m": fs, "front_error_m": fs - fa,
            "front_error_pct": (fs - fa) / fa * 100 if fa else None,
            "inertia_ratio_u2_over_gh0": u * u / (9.81 * analytical(np.array([0.0]), tr, n_val, u)[0]),
            "rmse_pct_of_boundary_depth": float(np.sqrt(np.mean(err ** 2)) / analytical(np.array([0.0]), tr, n_val, u)[0] * 100),
        }
    return res, x, out


def main():
    results, profiles = {}, {}
    sims = {}
    for label, u, n_val, dx, theta in CASES:
        if theta not in sims:
            sims[theta] = load_sim(theta)
        res, x, out = run_case(sims[theta], u, n_val, dx, REPORT_TIMES)
        res.update(u_m_s=u, manning_n=n_val, dx_m=dx, theta=theta)
        results[label] = res
        profiles[label] = (x, out, n_val, u)
        print(f"\n{label}: {res['steps']} steps, mass error {res['mass_error_pct']:+.4f}%")
        for tr, r in res["times"].items():
            print(f"  t={tr:>5}s  RMSE {r['rmse_m'] * 100:6.2f} cm ({r['rmse_pct_of_boundary_depth']:5.1f}% of h0={r['h_boundary_m']:.3f} m)  "
                  f"max |err| {r['max_abs_err_m'] * 100:6.2f} cm  front {r['front_model_m']:7.0f} m vs {r['front_analytical_m']:7.0f} m "
                  f"({r['front_error_m']:+.0f} m, {r['front_error_pct']:+.1f}%)  u2/gh0 {r['inertia_ratio_u2_over_gh0']:.3f}")

    meta = {
        "test": "1-D flood wave over a flat frictional plane (Hunter et al., 2005 analytical solution)",
        "formula": "h(x,t) = [ (7/3) n^2 u^2 (u t - x) ]^(3/7) for x <= u t, else 0",
        "t_end_s": T_END, "domain": "1.4 x u x t_end", "front_depth_threshold_m": FRONT_DEPTH,
        "kernels": "08_simulate_flood_scenarios.step / face_flux (unchanged)",
        "cases": results,
    }
    with open(os.path.join(val_dir, "verification_flat_plane.json"), "w") as f:
        json.dump(meta, f, indent=2)

    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    show = [CASES[0][0], CASES[5][0]]
    fig, axes = plt.subplots(1, len(show), figsize=(12, 4.2), sharey=False)
    colors = ["#1b9e77", "#d95f02", "#7570b3", "#e7298a"]
    for ax, label in zip(axes, show):
        x, out, n_val, u = profiles[label]
        xf = np.linspace(0, 1.4 * u * T_END, 2000)
        for k, tr in enumerate(REPORT_TIMES):
            ax.plot(xf, analytical(xf, tr, n_val, u), "-", color=colors[k], lw=1.6, label=f"analytical t={int(tr)} s")
            ax.plot(x[1:-1], out[tr][1:-1], "o", color=colors[k], ms=2.6, label=f"simulator t={int(tr)} s")
        ax.set_xlim(0, u * T_END * 1.15)
        ax.set_xlabel("Distance from inflow (m)")
        ax.set_ylabel("Water depth (m)")
        ax.set_title(f"u = {u} m/s, Manning n = {n_val}, dx = {results[label]['dx_m']:.0f} m, theta = {results[label]['theta']}")
        ax.grid(alpha=.3)
    axes[0].legend(fontsize=7, ncol=2)
    fig.suptitle("Simulator (08) vs analytical solution: flood wave over a flat plane")
    fig.tight_layout()
    fig.savefig(os.path.join(fig_dir, "verification_flat_plane.png"), dpi=150)
    print("\nSaved data/validation/verification_flat_plane.json and models/figures/verification_flat_plane.png")


if __name__ == "__main__":
    main()
