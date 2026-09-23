# ADR-0009 — Farm geometry as GeoJSON, measured in the application

**Status:** Accepted (2026-09-23, Phase 3)

## Context
Phase 3 adds blocks, sections, plots and locations with map boundaries
(requirements §8). The design documents (docs/03) assumed PostGIS
`geography` columns. But:

- SFMTP must run on MySQL 8 as well as PostgreSQL (docs/02 §6), and CI tests
  both. MySQL's spatial types behave differently (axis order, SRID handling,
  a smaller function set), so every spatial query would need two versions.
- Farm geometry is small: a farm has tens to a few hundred plots, and a
  boundary has at most a few hundred GPS points.
- What Phase 3 needs from geometry is modest: area, a centre for the map, a
  check that a plot lies inside its section, and a check that plots do not
  overlap. Imprecise GPS means those checks are **warnings**, not failures
  (docs/03 §3).

## Decision
1. **Storage.** `boundary` is a GeoJSON Polygon (RFC 7946, WGS 84,
   `[longitude, latitude]`) in a JSON column. Derived columns are written on
   every change: `area_ha`, `centroid_lat` / `centroid_lng`, and the bounding
   box (`bbox_min_*`, `bbox_max_*`, B-tree indexed). Locations also have a
   plain `latitude` / `longitude` point.
2. **Validation** (422 on failure): Polygon type, closed rings of at least
   4 positions, coordinates in range, no self-crossing ring, non-zero area,
   at most 1000 vertices and 20 holes. The shape is normalised: 7 decimals
   (about 1 cm), outer ring counter-clockwise, holes clockwise.
3. **Measurement** is in `FarmStructure\Domain\Geometry`. Area uses the
   spherical-excess formula on a sphere of radius 6,378,137 m (the same
   method as GeoJSON tooling such as Turf), which at farm scale is within a
   fraction of a percent of a geodesic area. Hectares are rounded to 4
   decimals.
4. **Warnings** are returned in `meta.warnings` on create and update:
   `outside_parent` (a vertex, or a location's pin, outside the parent's
   boundary by more than about 1 m) and `overlaps_sibling` (interiors of two
   nodes of the same type intersect; a shared edge does not count).
   Candidates come from a bounding-box query, capped at 200.
5. **Hierarchy and history.** Sections belong to a block; a plot belongs to a
   section or directly to the farm; a location may sit on a plot. All links
   are composite `(farm_id, …)` foreign keys, and so are the plot references
   from `trace_batches` and `trace_events`. Nodes are archived (soft delete)
   and never hard-deleted, and their codes are never reused within a farm, so
   old records stay unambiguous.

## Consequences
- The same code and the same tests run on both engines, with no spatial
  extension needed.
- There is no spatial index and no spatial SQL. That is fine at farm scale.
  When later phases need heavy spatial queries (worker GPS in a plot in
  Phase 6, activity heat maps in Phase 13), a PostgreSQL deployment can add a
  generated `geography` column and a GiST index next to the GeoJSON without
  changing the API. The PostGIS image in `infra/docker` stays for that.
- Clients send and receive GeoJSON as-is: the web map, a phone's GPS walk,
  or a file exported from a GIS tool.
