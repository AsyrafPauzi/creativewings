# Accurate World Map Gallery Design

**Date:** 2026-07-20  
**Status:** Implemented in 11.0.85

## Goal

Replace the decorative polygon map with an accurate world map and display one
stable pin for every real public submission, scaling safely to KPI targets such
as 5,000.

## Map

- Use a locally hosted, equirectangular SVG based on accurate world geography.
- Display recognisable country shapes and country borders.
- Do not use Google Maps, remote APIs, iframes, or runtime network requests.
- Preserve the existing responsive 2:1 map stage.
- Preserve the existing gallery layout choice: Basic gallery or World map.

## Pin assignment

- Every approved public submission contributes exactly one map point.
- A submission is assigned to a stable pseudo-random country using its entry ID.
- The same entry always appears in the same country.
- Coordinates come from a curated country-centroid list, with small deterministic
  jitter to prevent all points in a country overlapping.
- Jitter must remain inside a conservative radius around the centroid so points
  do not visibly move into oceans or neighbouring continents.
- The random country is decorative and must not be presented as the participant's
  real location.

## KPI behaviour

- Point count follows the real approved submission count, not the KPI target.
- Example: KPI 5,000 and 1,000 submissions renders 1,000 points and 20% progress.
- KPI 5,000 and 5,000 submissions renders 5,000 points and 100% progress.
- Counts above the target continue to render, while progress remains capped at
  100%.
- Never generate fake points to fill a KPI.

## Rendering architecture

### Canvas point layer

- Render all submission points on one HTML canvas over the map.
- Canvas uses device-pixel-ratio scaling for sharp dots.
- Redraw on initial load and debounced resize.
- Use compact coordinate data only; do not create thousands of DOM nodes.
- Canvas points are visual and non-interactive.

### Interactive recent submissions

- Keep at most 150 recent submissions as accessible HTML button pins.
- These buttons retain the current lightbox behaviour, keyboard focus, message
  colour, and anonymous submission rules.
- Remaining submissions are represented by canvas dots.
- Show copy explaining that highlighted pins are recent submissions and the map
  contains all approved submissions.

## Data and query strategy

- Do not fetch thousands of full WordPress posts and post-meta rows.
- Query compact entry IDs for the complete point layer, with a hard safety ceiling
  of 10,000 points per page render.
- Query full artwork/message data only for the latest 150 interactive entries.
- If approved submissions exceed 10,000, deterministically sample 10,000 canvas
  points and show the full submission count in the KPI text.
- Cache compact coordinates per campaign and invalidate when campaign entries
  are created, approved, unpublished, or deleted.

## Visual treatment

- Accurate land shapes with subtle country borders.
- Light blue ocean, slate land, and accessible contrast.
- Canvas points use the campaign accent colour at low opacity.
- Interactive pins remain visually stronger than canvas points.
- KPI completion overlay remains subtle and must not obscure country borders.

## Accessibility

- The SVG remains decorative with an accurate “World map” accessible label.
- Canvas includes fallback text stating the number of plotted submissions.
- Only interactive HTML pins enter keyboard navigation.
- Reduced-motion preference disables pin entrance animation.
- The lightbox remains anonymous and unchanged.

## Error handling

- If canvas is unavailable, retain the latest 150 interactive pins.
- If coordinate data is missing, skip malformed points without breaking the map.
- If the accurate SVG fails to load, retain the stage background and submission
  count rather than hiding the gallery.

## Performance requirements

- No more than 150 interactive DOM pins.
- No more than 10,000 canvas points per page.
- One canvas draw call pass, with no animation loop.
- Coordinate payload should remain compact arrays rather than verbose objects.
- No remote map library or geographic API.

## Acceptance criteria

1. The map is recognisably and geographically accurate.
2. Pins appear on countries rather than arbitrary ocean coordinates.
3. Each approved submission contributes one point up to the 10,000 render safety
   ceiling.
4. KPI progress uses the full approved submission count and caps at 100%.
5. A KPI target of 5,000 can display 5,000 lightweight points without creating
   5,000 DOM buttons.
6. Up to 150 recent pins open the existing anonymous lightbox.
7. Basic gallery mode remains unchanged.
8. Mobile resize, reduced motion, empty state, and anonymous privacy still work.
