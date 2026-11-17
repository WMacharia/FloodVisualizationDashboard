"""
PHASE 10: STATIC LAYERS FOR THE DASHBOARD (run once; model_api.py loads the outputs)

Builds, on the same 30 m grid as the U-Net:
  1. Road graph for flood-aware routing      -> data/dashboard/road_graph.npz
     OpenStreetMap roads (Geofabrik Kenya extract), split at junctions, with the grid
     cells each road segment passes through (sampled every <= 10 m).
  2. Building count per cell                  -> data/dashboard/layers.npz (bcount)
     Microsoft Global ML Building Footprints, one count per footprint centroid.
  3. Sub-county id per cell                   -> data/dashboard/layers.npz (subcounty)
     HDX COD-AB admin2 (17 Nairobi sub-counties), 0 = outside the county.
  4. Critical facilities                       -> data/dashboard/facilities.json
     OSM health, education and emergency points/areas.
  5. Place names for labelling flood hotspots -> data/dashboard/places.json

Travel speeds use OSM maxspeed where tagged (about 3 % of segments) and otherwise a
default per road class (assumption; congestion is not modelled).
"""
import os
import json
import math
import numpy as np
import pyogrio
import shapely
import rasterio
from rasterio.features import rasterize
from scipy.sparse import coo_matrix
from scipy.sparse.csgraph import connected_components

script_dir = os.path.dirname(os.path.abspath(__file__))
osm_dir = os.path.join(script_dir, "data", "raw", "kenya_waterways")   # Geofabrik kenya-latest-free.shp.zip
out_dir = os.path.join(script_dir, "data", "dashboard")
os.makedirs(out_dir, exist_ok=True)

SAMPLE_STEP_M = 10.0
DRIVABLE_SPEED_KMH = {
    "motorway": 80, "motorway_link": 50, "trunk": 60, "trunk_link": 40,
    "primary": 50, "primary_link": 35, "secondary": 40, "secondary_link": 30,
    "tertiary": 30, "tertiary_link": 25, "unclassified": 25, "residential": 20,
    "living_street": 10, "service": 15, "track": 15, "track_grade1": 15,
    "track_grade2": 12, "track_grade3": 10, "track_grade4": 8, "track_grade5": 6,
}
FACILITY_GROUPS = {
    "hospital": "health", "clinic": "health", "doctors": "health",
    "school": "education", "kindergarten": "education", "college": "education", "university": "education",
    "police": "emergency", "fire_station": "emergency",
}

with rasterio.open(os.path.join(script_dir, "data", "aligned", "aligned_fabdem.tif")) as src:
    T = src.transform
    H, W = src.shape
bounds = (T.c, T.f + T.e * H, T.c + T.a * W, T.f)
LAT0 = T.f + T.e * H / 2.0
MX = 111320.0 * math.cos(math.radians(LAT0))   # metres per degree of longitude
MY = 110574.0                                   # metres per degree of latitude


def to_cells(lng, lat):
    """Flat cell index for each point (-1 outside the grid)."""
    col = np.floor((lng - T.c) / T.a).astype(np.int64)
    row = np.floor((lat - T.f) / T.e).astype(np.int64)
    ok = (row >= 0) & (row < H) & (col >= 0) & (col < W)
    return np.where(ok, row * W + col, -1)


def build_road_graph():
    print("1. Road graph from OpenStreetMap...")
    roads = pyogrio.read_dataframe(os.path.join(osm_dir, "gis_osm_roads_free_1.shp"), bbox=bounds)
    roads = roads[roads.fclass.isin(DRIVABLE_SPEED_KMH.keys())].reset_index(drop=True)
    roads = roads.explode(index_parts=False).reset_index(drop=True)   # any MultiLineStrings -> LineStrings
    print(f"   {len(roads):,} drivable ways")

    xy, line_of = shapely.get_coordinates(roads.geometry.values, return_index=True)
    keys = np.round(xy * 1e7).astype(np.int64)
    _, vid, n_lines_at = np.unique(keys, axis=0, return_inverse=True, return_counts=True)
    vid = vid.ravel()
    # A vertex is a junction if it is used by more than one way (or twice by the same way)
    starts = np.r_[0, np.flatnonzero(np.diff(line_of)) + 1]
    ends = np.r_[starts[1:], len(line_of)] - 1
    is_split = n_lines_at[vid] > 1
    is_split[starts] = True
    is_split[ends] = True

    node_of_vertex = {}
    node_xy = []
    eu, ev, elen, etime, eclass, ename, ebridge, eoneway = [], [], [], [], [], [], [], []
    geom_ptr, geom_xy = [0], []
    cell_ptr, cell_idx = [0], []

    # first coordinate of each unique vertex (for node positions)
    first_pos = np.full(vid.max() + 1, -1, np.int64)
    first_pos[vid[::-1]] = np.arange(len(vid))[::-1]

    fclass = roads.fclass.values
    names = roads.name.fillna("").values
    oneway = roads.oneway.values
    maxspeed = roads.maxspeed.values
    bridge = (roads.bridge.values == "T") | (roads.tunnel.values == "T")

    for li in range(len(roads)):
        a, b = starts[li], ends[li]
        split_pos = np.flatnonzero(is_split[a:b + 1]) + a
        speed = float(maxspeed[li]) if maxspeed[li] and maxspeed[li] > 0 else DRIVABLE_SPEED_KMH[fclass[li]]
        for p, q in zip(split_pos[:-1], split_pos[1:]):
            seg = xy[p:q + 1]
            dx = np.diff(seg[:, 0]) * MX
            dy = np.diff(seg[:, 1]) * MY
            seg_len = np.hypot(dx, dy)
            length = float(seg_len.sum())
            if length <= 0:
                continue
            for v in (vid[p], vid[q]):
                if v not in node_of_vertex:
                    node_of_vertex[v] = len(node_xy)
                    node_xy.append(xy[first_pos[v]])
            eu.append(node_of_vertex[vid[p]]); ev.append(node_of_vertex[vid[q]])
            elen.append(length); etime.append(length / (speed / 3.6))
            eclass.append(fclass[li]); ename.append(names[li])
            ebridge.append(bool(bridge[li])); eoneway.append(oneway[li])
            geom_xy.append(seg); geom_ptr.append(geom_ptr[-1] + len(seg))
            # Cells crossed: sample every <= SAMPLE_STEP_M along each straight piece
            pts = [seg[:1]]
            for k in range(len(seg) - 1):
                n = max(1, int(math.ceil(seg_len[k] / SAMPLE_STEP_M)))
                t = np.linspace(0, 1, n + 1)[1:, None]
                pts.append(seg[k] + t * (seg[k + 1] - seg[k]))
            pts = np.vstack(pts)
            cells = np.unique(to_cells(pts[:, 0], pts[:, 1]))
            cells = cells[cells >= 0]
            cell_idx.append(cells); cell_ptr.append(cell_ptr[-1] + len(cells))

    node_xy = np.array(node_xy)
    eu, ev = np.array(eu, np.int32), np.array(ev, np.int32)
    n_nodes = len(node_xy)

    # Largest connected component (undirected): start/end points snap only to these nodes
    g = coo_matrix((np.ones(len(eu)), (eu, ev)), shape=(n_nodes, n_nodes))
    n_comp, comp = connected_components(g, directed=False)
    main = comp == np.bincount(comp).argmax()
    print(f"   {n_nodes:,} junctions, {len(eu):,} road segments, "
          f"largest connected network {main.mean() * 100:.1f}% of junctions ({n_comp} components)")
    print(f"   bridges/tunnels: {int(np.sum(ebridge)):,} segments, one-way: {int(np.sum(np.array(eoneway) != 'B')):,}")

    np.savez_compressed(
        os.path.join(out_dir, "road_graph.npz"),
        node_xy=node_xy, node_main=main,
        edge_u=eu, edge_v=ev, edge_len_m=np.array(elen, np.float32), edge_time_s=np.array(etime, np.float32),
        edge_class=np.array(eclass), edge_name=np.array(ename), edge_bridge=np.array(ebridge),
        edge_oneway=np.array(eoneway),
        geom_ptr=np.array(geom_ptr, np.int64), geom_xy=np.vstack(geom_xy),
        cell_ptr=np.array(cell_ptr, np.int64), cell_idx=np.concatenate(cell_idx).astype(np.int32),
    )


def build_layers():
    print("2. Building count per cell...")
    bld = pyogrio.read_dataframe(os.path.join(script_dir, "data", "interim", "nairobi_buildings.gpkg"))
    cen = shapely.centroid(bld.geometry.values)
    cells = to_cells(shapely.get_x(cen), shapely.get_y(cen))
    cells = cells[cells >= 0]
    bcount = np.bincount(cells, minlength=H * W).reshape(H, W).astype(np.uint16)
    print(f"   {int(bcount.sum()):,} buildings on the grid")

    print("3. Sub-county raster...")
    sc = json.load(open(os.path.join(script_dir, "data", "raw", "nairobi_subcounties.geojson"), encoding="utf-8"))
    feats = sorted(sc["features"], key=lambda f: f["properties"]["subcounty"])
    shapes = [(shapely.geometry.shape(f["geometry"]), i + 1) for i, f in enumerate(feats)]
    subcounty = rasterize(shapes, out_shape=(H, W), transform=T, fill=0, dtype="uint8")
    names = [f["properties"]["subcounty"] for f in feats]
    print(f"   {len(names)} sub-counties, {np.mean(subcounty > 0) * 100:.1f}% of grid inside Nairobi County")

    # Simplified outlines for the map (about 30 m tolerance)
    outlines = {"type": "FeatureCollection", "features": [
        {"type": "Feature", "properties": {"id": i + 1, "name": f["properties"]["subcounty"],
                                           "pcode": f["properties"]["pcode"],
                                           "area_sqkm": f["properties"]["area_sqkm"]},
         "geometry": shapely.geometry.mapping(shapely.geometry.shape(f["geometry"]).simplify(0.0003))}
        for i, f in enumerate(feats)]}
    with open(os.path.join(out_dir, "subcounties.geojson"), "w", encoding="utf-8") as f:
        json.dump(outlines, f)
    np.savez_compressed(os.path.join(out_dir, "layers.npz"), bcount=bcount, subcounty=subcounty,
                        subcounty_names=np.array(names))


def build_points():
    print("4. Critical facilities...")
    out = []
    for fn in ("gis_osm_pois_free_1.shp", "gis_osm_pois_a_free_1.shp"):
        df = pyogrio.read_dataframe(os.path.join(osm_dir, fn), bbox=bounds)
        df = df[df.fclass.isin(FACILITY_GROUPS.keys())]
        pts = shapely.point_on_surface(df.geometry.values)
        for (_, r), p in zip(df.iterrows(), pts):
            out.append({"name": r["name"] or r.fclass.replace("_", " ").title(), "type": r.fclass,
                        "group": FACILITY_GROUPS[r.fclass], "lng": round(p.x, 6), "lat": round(p.y, 6)})
    with open(os.path.join(out_dir, "facilities.json"), "w", encoding="utf-8") as f:
        json.dump(out, f)
    print(f"   {len(out):,} facilities")

    print("5. Place names...")
    pl = pyogrio.read_dataframe(os.path.join(osm_dir, "gis_osm_places_free_1.shp"), bbox=bounds)
    pl = pl[pl.fclass.isin(["suburb", "village", "locality", "town", "hamlet"]) & pl.name.notna()]
    places = [{"name": r["name"], "type": r.fclass, "lng": round(r.geometry.x, 6), "lat": round(r.geometry.y, 6)}
              for _, r in pl.iterrows()]
    with open(os.path.join(out_dir, "places.json"), "w", encoding="utf-8") as f:
        json.dump(places, f)
    print(f"   {len(places)} places")


if __name__ == "__main__":
    build_road_graph()
    build_layers()
    build_points()
    print(f"\nSaved dashboard layers to {out_dir}")
