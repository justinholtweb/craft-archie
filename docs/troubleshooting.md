---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Plans that never converge, blocked changes, unresolved references and failed runs.
---

## A plan keeps reporting the same change

You apply, it says it wrote the change, and the next plan reports the same difference again. This is
the failure mode worth caring about most, because nothing is obviously broken — the run succeeds
every time and quietly does nothing.

It means Archie wrote one thing and Craft stored another. Three usual causes:

**The setting is not a setting of that field type.** Craft ignores a setting a field does not
define, so a typo produces no error anywhere. The linter catches this:

```
warning  field “summary”: `settings.charLimmit` is not a setting of this field type,
         so Craft will ignore it.
```

**Craft normalises the value on the way in.** Some settings are rewritten before they are saved —
Craft 4's `viewMode: large` on a relation field becomes `thumbs`, and `cards` with
`showCardsInGrid` becomes `cards-grid`. Archie canonicalises the ones it knows about, so a blueprint
saying either of the old things converges. If you hit one it does not know about, the fix is to
write what Craft actually stores — export the component and look:

```sh
php craft archie/blueprint/export --only=fields --handles=summary
```

**The change is blocked rather than applied.** Look for a `!` in the plan, not just the summary line
at the bottom.

## “Already exists as a … field. Changing it to … discards the content”

Archie refuses to change a field's type on the strength of a blueprint alone, because that rewrites
the field's storage and the old values do not come back.

If you mean it:

```sh
php craft archie/blueprint/apply blog.yaml --force
```

If you do not, give the new field a different handle. That is almost always what you actually wanted
— you get the new field, the old content stays where it is, and you can migrate between them on your
own schedule.

## “Field … still refers to …, which does not exist”

A field's `sources` name something the blueprint does not create and the site does not have. The
references are left unset rather than written broken, and the field is saved without them.

Check the handle. Remember that sources are handles, not UIDs, and that a bare handle inside
`sources` means "of this field's own kind" — a volume on an Assets field, a section on an Entries
field, a group on a Categories or Users field. Prefix it if you want to be explicit:

```yaml
settings:
  sources: ['volume:images']
```

If the thing it points at *is* in the blueprint, this warning means the two-pass relink did not
resolve it either — which is a bug. Please report it with the blueprint.

## “The type … is ambiguous”

```
error  field “links”: The type “table” is ambiguous — it could be craft\fields\Table or
       justinholtweb\legs\fields\TableField. Use the full class name.
```

Two installed plugins provide a field whose shorthand is the same. Shorthands are derived from the
registered classes themselves, so this can appear the day you install an unrelated plugin. Write the
full class name:

```yaml
type: craft\fields\Table
```

The full class name always works, for every type, and is worth reaching for in any blueprint you
expect to apply on installs you do not control.

## “Unknown field type”

The plugin that provides it is not installed on this environment. Archie will not invent a field
type, and a `MissingField` placeholder is worse than an error. Install the plugin, or take the field
out of the blueprint.

## An apply is refused

Applying requires an admin account **and** `allowAdminChanges`. Planning, linting, exporting and
converting do not. If `allowAdminChanges` is off, the linter says so up front:

```
warning  `allowAdminChanges` is off in this environment, so applying will be refused.
```

This is Craft's rule about which environments own the content model, not Archie's, and there is no
setting that overrides it.

## A run failed halfway

Look at **Archie → History**. A failed run is recorded like any other, with what it managed to write
before it stopped, and it can be rolled back the same way.

Then look at `storage/logs/archie.log`. Turning `logLevel` down to `debug` records the resolved
settings of every component as it is written, which is normally enough to see which one Craft
rejected and why.

## Matrix fields come out empty

If you applied a Craft 4 Architect document as-is, this is expected and the linter should have
stopped you:

```
error  field “blocks”: This field declares `blockTypes`, which Craft 4 used for Matrix blocks…
```

Craft 5 replaced Matrix block types with shared entry types. Run the document through `convert`
rather than editing around it:

```sh
php craft archie/blueprint/convert old.json --out=config/archie/new.yaml
```

## An exported blueprint will not plan clean

Export a component and plan the result straight back; it should report everything unchanged. If it
does not, `dump()` and `apply()` disagree somewhere in Archie, and that is a bug rather than
something to work around. Please open an issue with the exported blueprint attached — it is the
whole reproduction.

## Nothing at all happens

Check the blueprint directory. `blueprintPath` defaults to `config/archie`, and a blueprint
somewhere else has to be given as a path:

```sh
php craft archie/blueprint/plan /full/path/to/blog.yaml
```

And check that the top-level keys are component types Archie knows. Unknown keys are ignored with a
notice rather than an error — deliberately, so a document carrying an extra key from somewhere else
still runs — but a whole blueprint of unknown keys plans as nothing.
