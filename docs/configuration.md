---
title: Configuration
slug: configuration
order: 20
summary: Settings, the blueprint directory, pruning, snapshots and logging.
---

## Settings

Archie's settings are at **Archie → Settings**, or in `config/archie.php` if you would rather keep
them in version control:

```php
<?php

return [
    'blueprintPath' => 'config/archie',
    'defaultFormat' => 'yaml',
    'allowPrune' => false,
    'keepSnapshots' => 25,
    'generateMissingHandles' => true,
    'logLevel' => 'info',
];
```

A `config/archie.php` file wins over whatever is stored in the CP, and the affected fields are shown
as read-only there — the same convention every other Craft plugin follows.

## `blueprintPath`

**Default: `config/archie`.** The directory on-disk blueprints are read from and written to,
relative to the Craft base path.

The default puts blueprints beside the project config they produce, which is where they belong: a
blueprint is source, it describes what your content model should be, and it should be reviewed in a
pull request like anything else that changes the shape of your site.

The path may not walk up out of the project, and may only contain letters, numbers, dots, dashes,
underscores and slashes.

## `defaultFormat`

**Default: `yaml`.** Either `yaml` or `json`. Used when exporting or converting without an explicit
`--format`.

Archie reads both regardless of this setting — it decides by looking at the first character of the
document, so a leading `{` or `[` is parsed as JSON and anything else as YAML. You never have to
tell it which one you have handed it.

## `allowPrune`

**Default: `false`.** Whether an apply is ever allowed to delete live components the blueprint does
not mention.

Off is the right default and you should think hard before changing it. A blueprint that mentions
three fields is not asking for every other field on the site to be deleted — it is describing three
fields. Pruning has to be enabled **here** *and* requested **on the run** (`--prune`), which is two
deliberate acts rather than one flag someone leaves on.

Pruning never touches sites, whatever the settings say. See
[What it will not do](usage#what-it-will-not-do).

## `keepSnapshots`

**Default: `25`.** How many apply snapshots to keep. Older ones are pruned after each apply. `0`
keeps all of them.

A snapshot is what each component looked like before a run touched it — it is what makes
[rollback](usage#rolling-back) possible. Dropping this to a small number on a site with a long
history saves very little and costs you the ability to undo anything older.

## `generateMissingHandles`

**Default: `true`.** Whether entry types, fields and volumes created by Archie get a handle derived
from their name when the blueprint does not give one.

Turn it off if you would rather a missing handle were a lint error. On a hand-written blueprint the
generated handle is usually what you would have typed anyway; on a converted Craft 4 document it is
often the only thing standing between you and a failed run.

## `logLevel`

**Default: `info`.** The level at or above which Archie writes to `storage/logs/archie.log`. Accepts
the usual PSR-3 levels — `debug`, `info`, `notice`, `warning`, `error` and up.

`debug` includes the resolved settings of every component as it is written, which is the level worth
turning on when a plan and an apply disagree about something.

## Environment overrides

Every setting can be driven from `.env` in the usual Craft way, which is the sane way to keep
pruning available in a sandbox and unavailable everywhere else:

```php
// config/archie.php
return [
    'allowPrune' => App::env('ARCHIE_ALLOW_PRUNE') ?? false,
];
```

## What Archie does not have settings for

There is no setting that lets an apply run where `allowAdminChanges` is off, and no setting that
turns off the plan step before an apply. Both are load-bearing rather than conservative defaults:
the first is Craft's own rule about which environments own the content model, and the second is the
entire difference between this and running raw project-config edits.
