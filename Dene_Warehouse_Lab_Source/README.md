# Dene Warehouse Lab — two-floor prototype
A standalone Three.js warehouse simulation with the downstairs layout and upstairs mezzanine. No Laravel application files are modified.

## Run locally
Extract the package and open a terminal in the folder containing dist.
With PHP installed: `php -S 127.0.0.1:8080 -t dist`
Open http://127.0.0.1:8080. Stop with Ctrl+C.
Alternatively: `python -m http.server 8080 --bind 127.0.0.1 --directory dist`
Do not open index.html directly: JavaScript modules need an HTTP server.
All dependencies are included; no npm install or build required.

## Using the prototype
The initial view shows the mezzanine. Drag to orbit, scroll to zoom, or select Top view. Click a rack section, then Add to pick list. Upstairs locations represent all reachable shelves in that section and do not incur ladder time. Downstairs shelf levels can be selected separately.
The sample order contains 13 picks across both floors and a drugs room. Play begins from packing; when starting in the mezzanine view it switches to Follow picker so the ground-floor start is visible. Choose a fixed floor view or Both floors at any time. Both floors uses a translucent mezzanine deck for visibility. A floor visibility change does not change routes or timings.
Pause, replay or scrub the timeline. Compare List order with Group by floor / aisle. The latter is a heuristic, not a globally optimal route. Stair trips and stair time are reported separately.

## Geometry and assumptions
Approximate overall plan: 100 x 30 metres. The upstairs image is registered using the same image origin and scale as the original ground-floor drawing. Ground-floor geometry remains the earlier schematic approximation. The yellow mezzanine footprint, its central cutout, stairs area and upstairs cross-walkways follow the new diagram.
Mezzanine elevation is assumed to be 6 m (H in dist/mezzanine.mjs). Stair shape, height, individual bay dimensions, and timing settings are illustrative, not surveyed. The staircase joins the upper-left ground area to the upstairs stairs box. Its continuous slope is used for time/distance; animation is a simplified marker, not a detailed gait simulation. The elevated landing geometry is schematic.
Downstairs aisles 4–8 have bottom access only; all paths are two-way. The drugs corridor is accessible only via the top of aisle 9. Upstairs has top and lower cross-walkways as drawn; routes use shortest paths on that network, never through racks or the central opening.
Upper sections: 11 LA–LI/RA–RG; 12 LA–LG/RA–RH; 13 LA–LH/RA–RH; 14 LA–LH/RA–RC; 15–17 LA–LC/RA–RC; 18 LA–LC/RA–RS; 19 LA–LR only. A starts at the top. UP:11:FA and UP:12:B, UP:13:B, UP:14:B are included (120 upstairs locations total).
No congestion, stock quantities, capacity limits, shared ladder setup, goods lift, product slotting optimisation or real orders yet. Changes in the UI reset on refresh. Ladder time is per downstairs elevated pick only.

## Source files
- dist/index.html: interface and styles
- dist/app.js: 3D scene, controls and animation
- dist/mezzanine.mjs: upstairs dimensions, rack sections, locations and path segments
- dist/engine.mjs: routing and timing
- dist/vendor/: vendored Three.js 0.180.0 plus licence
- test-engine.mjs: run with `node test-engine.mjs`

## Laravel/Livewire integration
For an initial sample-data demo, copy dist contents into public/warehouse-lab and visit /warehouse-lab/index.html, or embed that page in an iframe in the admin panel. Static public files are directly accessible: protect real warehouse/order data with authenticated routes before connecting it.
For native integration, place the viewport inside wire:ignore, initialise Three.js once and dispose controls/animation on component removal. Use authenticated Laravel endpoints to populate locations and orders. Keep simulation timestamps separate from browser playback speed. This is a standalone prototype, not an installable Laravel module.

## Validation
Automated checks cover downstairs regressions, all 120 upstairs destinations, legal walkway segments, stair-only floor transitions, bottom cross-walkway shortcuts, no upstairs ladder penalty and timing. Browser QA covered upstairs selection, automatic floor switching, stair traversal and return to packing using SVG fallback. The QA browser has no WebGL; the preferred WebGL path is not visually verified in this environment.

## Full location catalogue (Prototype 03)

The 3,731 unique full codes are embedded in `dist/location-catalogue.json`, imported from the user's pasted location list. Search by full or partial code in the sidebar, select a shelf level and individual bin, then add it to the run. `dist/location-review.html` provides the complete mapping audit and placement assumptions. The final code component is preserved for identification but shares the section access point.

- UP identifies the mezzanine; other building prefixes do not split main ground-floor racks.
- Downstairs numeric shelf levels 02 and above add the configurable ladder penalty. Every upstairs shelf is reachable without a ladder, regardless of its coded level.
- Upstairs 20, 21, 22 and 25 are four approximate access points in the open area to the right. Their coloured floor markers are conceptual areas, not surveyed boundaries or racks.
- FA codes are at the corresponding aisle front. 00 codes use the nearest identifiable aisle front; unknown aisle 00 defaults to 4 downstairs, 9 for 7L, or 11 upstairs. UP:00 pallet/trolley codes use upstairs 11.
- Nonstandard special codes (including UP:25:OF:FI:CE) are placed beside packing per the user's instruction. This is a modelling placeholder, not a physical relocation recommendation.
- Small storage, B zones and drugs rooms retain one shared access point each. Internal movements are unmeasured. Downstairs shelf heights and the 6 m mezzanine elevation are illustrative.

Regenerate after editing `dist/location-source.json` or placement rules:

```sh
node build-catalogue.mjs
node build-review.mjs
node test-engine.mjs
node test-catalogue.mjs
```

`placements.mjs` defines the approved approximate geometry; `catalogue.mjs` classifies complete codes and resolves them to access points. Route planning remains a simple floor/aisle grouping heuristic, not a proven optimal route or product slotting optimiser. No product/order history or real pick-time calibration is included yet.
