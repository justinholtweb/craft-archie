# Release Notes for Archie

## 5.0.1 - 2026-09-14

### Changed

- New icon, traced from the supplied artwork. The Plugin Store icon is the full mark on a rounded
  tile in the plugin's accent, now teal (`#08818A`); the control-panel nav icon is the silhouette
  with a single knockout for the eye, because the crosshatch and the ear are below a pixel at the
  size that nav actually renders.

### Fixed

- Ordinary field settings are no longer read as source handles. On a relation field, whose bare
  `sources` handles stand for volumes, sections or groups, every other string setting was being
  looked up the same way — so `viewMode: large` and `allowedKinds: [image]` on an Assets field
  produced “still refers to volume “large”, volume “image”, which does not exist” and were left
  unset. A bare handle now only means a source inside `sources`; a prefixed `volume:images` is
  still resolved wherever it appears.
- A relation field's `viewMode` is written the way Craft stores it. Craft rewrites `large` to
  `thumbs`, and `cards` with `showCardsInGrid` to `cards-grid`, before saving — so a blueprint
  saying either of the old things, which is exactly what an Architect document carries, planned as
  a change on every run and never converged.

## 5.0.0 - 2026-08-28

Initial release. A Craft 5 replacement for Architect.

### Added

- Blueprints in YAML or JSON describing sites, site groups, filesystems, image transforms, fields,
  entry types, sections, volumes, category groups, tag groups, global sets, user groups and routes.
- `plan` — a full diff of what applying a blueprint would do, down to individual attributes and
  field layout elements, before anything is written. Applies are idempotent: running a blueprint
  twice does nothing the second time.
- Rollback. Every apply records what each component looked like beforehand, and can be undone from
  the CP or the console, with destructive steps called out first.
- A linter covering unknown and ambiguous field types, reserved and duplicate handles, layouts
  naming fields that do not exist, sections pointing at missing entry types, settings a field type
  does not have, credentials written in plain text, and environments where `allowAdminChanges` is
  off.
- Reading of Architect's Craft 4 blueprints, including rewriting Matrix block types into Craft 5
  entry types, and `archie/blueprint/convert` to write the result back out.
- Export: read the live content model out as a blueprint, pulling in everything the selection needs
  to be applicable on its own.
- Seven bundled recipes — blog, page builder, team, FAQ, events, SEO fields and media volume.
- Console commands under `archie/blueprint`, `archie/recipes` and `archie/history`, with exit codes
  suitable for CI.
- A `RegisterComponentHandlersEvent` so a plugin can teach Archie about a component type of its own.
