---
title: Installation
slug: installation
order: 10
summary: Requirements, install, permissions, and your first blueprint.
---

## Requirements

Craft CMS 5.3 or later, and PHP 8.2 or later. Archie has no runtime dependencies beyond Craft
itself — no queue, no extra tables you have to care about, no third-party services.

## Install

```sh
composer require justinholtweb/craft-archie
php craft plugin/install archie
```

Or find **Archie** in the Plugin Store and install it from there.

Archie is free, under the [Craft License](https://craftcms.github.io/license/).

## Who can use it

Archie is **admin-only**. Every screen and every console command requires an admin account, because
everything it does is something Craft itself only lets an admin do.

Applying additionally requires `allowAdminChanges` to be on — the same rule Craft applies to editing
fields and sections. That means:

| | `allowAdminChanges: true` | `allowAdminChanges: false` |
|---|---|---|
| Lint | ✅ | ✅ |
| Plan | ✅ | ✅ |
| Export | ✅ | ✅ |
| Convert | ✅ | ✅ |
| Apply | ✅ | ❌ |
| Roll back | ✅ | ❌ |

This is deliberate rather than restrictive. Planning and exporting work **anywhere, including
production**, which is exactly where you want to be able to capture a model or check what a deploy
would do. Writing is limited to the environments Craft already lets you write in.

The linter will warn you when it can see that applying would be refused, rather than letting you
find out at the end of a run.

## Your first blueprint

Blueprints live in `config/archie/`, beside the project config they produce. Create the directory
and put something in it:

```sh
mkdir -p config/archie
php craft archie/recipes/copy blog
```

That copies the bundled **blog** recipe to `config/archie/blog.yaml`. Open it, change what you want,
then look at what it would do:

```sh
php craft archie/blueprint/plan blog.yaml
```

Nothing has been written yet. When the plan says what you expect:

```sh
php craft archie/blueprint/apply blog.yaml
```

Run the plan again afterwards and it should report everything unchanged. That round trip — apply,
then plan clean — is the check worth doing on any blueprint you write, because a plan that still
reports a change after an apply means the blueprint and Craft disagree about something.

## Where things are

| Path | What |
|---|---|
| `config/archie/` | Your blueprints. Commit these. |
| `storage/logs/archie.log` | What Archie did, at the log level in the settings. |
| **Archie → History** | Every apply, with the snapshot needed to undo it. |

The blueprint directory is configurable — see [Configuration](configuration).

## In the control panel

**Archie** appears in the CP nav with five screens:

- **Blueprints** — the blueprints in your project, plus a box to paste one into
- **Recipes** — the seven bundled starting points
- **Export** — read the live content model back out as a blueprint
- **History** — every apply, with an undo
- **Settings** — the plugin settings

Planning is always the step between choosing a blueprint and applying it. There is no button
anywhere that applies one without showing you the plan first.
