---
title: Usage
slug: usage
order: 30
summary: Lint, plan, apply, export, convert and roll back — in the CP and on the command line.
---

## The loop

Archie has one shape, and everything else is a variation on it:

1. **Write** a blueprint, or copy a recipe, or export one out of a live site.
2. **Lint** it — problems Archie can see without touching anything.
3. **Plan** it — exactly what would change, against this install.
4. **Apply** it.
5. **Roll back** if it was not what you wanted.

Steps 2 and 3 are free and safe to run anywhere, including production.

## In the control panel

**Archie → Blueprints** lists the blueprints in `config/archie/` and lets you write, upload or paste
one. Each has a **Plan** button; there is no **Apply** button until you have seen a plan.

**Recipes** are the seven bundled starting points. **Copy into my project** writes one to your
blueprint directory, where you edit it and it becomes yours — they are ordinary blueprints, not
templates locked inside a plugin.

**Export** reads the live model back out. **History** is every apply, with an undo.

## On the command line

```sh
# Check a blueprint without touching anything
php craft archie/blueprint/lint blog.yaml

# See what it would do
php craft archie/blueprint/plan blog.yaml

# Do it
php craft archie/blueprint/apply blog.yaml

# Unattended, for CI
php craft archie/blueprint/apply blog.yaml --interactive=0
```

The file argument is either a path or the name of a blueprint in your blueprint directory, so
`blog.yaml` and `config/archie/blog.yaml` both work.

`lint` and `plan` **exit non-zero** when the blueprint could not be applied cleanly. That is what
makes them useful in a pipeline: run `plan` on a pull request and it fails if the content model the
branch describes cannot be built.

### Variables

A blueprint's `{{ placeholders }}` are filled from its own `vars` block, and overridden per run:

```sh
php craft archie/blueprint/plan blog.yaml --vars="handle=news,name=Newsroom"
```

This is what lets one blueprint describe a shape rather than a specific instance of it — the same
document builds `blog` on one site and `news` on another.

### Narrowing a run

```sh
# Only these component types
php craft archie/blueprint/plan blog.yaml --types=fields,sections
```

## Reading the plan

```
Fields
  +  summary                       Will be created
  ~  articleBody                   2 changes: instructions, settings.initialRows
       instructions: — → The article itself.
       settings.initialRows: 4 → 12
     featuredImage                 Already matches

Entry types
  ~  article                       1 change: fieldLayout.summary
       fieldLayout.summary: — → Content  (added to the “Content” tab)

Plan: 1 to create, 2 to update, 1 unchanged.
```

| Marker | Means |
|---|---|
| `+` | Does not exist; will be created |
| `~` | Exists and differs; the changed attributes are listed under it |
| *(none)* | Exists and already matches |
| `!` | **Blocked** — a change Archie refuses to make without `--force` |

A blocked line looks like this:

```
  !  articleBody   Already exists as a plainText field. Changing it to ckeditor
                   discards the content already stored in it.
```

Applying twice does nothing the second time. Re-running a blueprint is normal and safe, and a plan
that *still* reports a change immediately after an apply is a bug worth reporting — it means
Archie's idea of what it wrote and Craft's idea of what it stored disagree.

## Applying

An apply runs in dependency order: site groups, sites, filesystems, transforms, fields, entry types,
sections, volumes, category groups, tag groups, global sets, user groups, routes.

That order contains a cycle, and it is the defining one in Craft 5: a Matrix field points at entry
types, and those entry types' field layouts point back at fields. Archie breaks it by saving such a
field twice — once without the references it cannot yet resolve, and once more at the end of the run
when everything exists.

**You do not have to order your blueprint to suit.** Write it however it reads best.

The plan is always rebuilt immediately before anything is written, so a plan you looked at ten
minutes ago is never the thing that gets applied.

## Rolling back

Every apply records what each component looked like beforehand.

```sh
php craft archie/history
php craft archie/history/rollback 4
```

Archie deletes what it created and restores what it changed, **newest first** — the reverse of the
order it built them in, because a section cannot be deleted after the entry type it points at.

Before it does anything it tells you plainly which of those deletions take content with them:

> Rolling this back will:
> - Delete the section "team"
> - Delete the entry type "person"
> - Delete the field "personPhoto"
>
> 3 of these delete something that holds content. Any entries, categories or assets inside them go
> too, and rolling back does not bring them back.

Rollback undoes the *content model*. It does not, and cannot, undo the content.

## Exporting

Reads the live model back out as a blueprint:

```sh
php craft archie/blueprint/export --only=sections --handles=blog --out=config/archie/blog.yaml
```

| Option | What |
|---|---|
| `--only` | Component types, comma-separated. Defaults to everything. |
| `--handles` | Handles, comma-separated. Defaults to everything of the chosen types. |
| `--out` | Where to write it. Prints to stdout when omitted. |
| `--format` | `yaml` or `json`. Defaults to the `defaultFormat` setting. |
| `--bare` | Leave out the components the selection depends on. |

By default an export pulls in everything the selection *needs* in order to be applicable on its own
— export a section and you get its entry types and their fields too. `--bare` turns that off when
you want exactly what you asked for and nothing else.

The strongest check you can run on an export is to plan it straight back:

```sh
php craft archie/blueprint/export --only=sections --handles=blog --out=/tmp/roundtrip.yaml
php craft archie/blueprint/plan /tmp/roundtrip.yaml   # must report everything unchanged
```

## Converting an Architect blueprint

```sh
php craft archie/blueprint/convert old-architect.json --out=config/archie/blog.yaml
```

Archie reads Architect's Craft 4 documents directly — you can plan one without converting it. The
`convert` command exists so you can *read* the result before you trust it, as idiomatic Archie YAML
rather than as a Craft 4 document Archie happens to understand.

| Craft 4 shape | Becomes |
|---|---|
| Entry types nested inside a section | Hoisted to top-level `entryTypes` |
| The tab-map field layout shape | `fieldLayout` tabs |
| Matrix `blockTypes` | Craft 5 entry types, inner fields promoted to real fields |
| Field groups | Dropped, with an explanation — Craft 5 has no field groups |

## Recipes

```sh
php craft archie/recipes
php craft archie/recipes/copy blog
```

Seven of them: blog, page builder, team, FAQ, events, SEO fields and media volume. They are ordinary
blueprints. Copy one into your project, edit it until it describes what you actually want, and apply
it from there — so the source of truth for your content model lives in your repository rather than
inside a plugin.

## What it will not do

- **Delete a site.** Deleting a site deletes the content that only lived there. No blueprint should
  be able to do that as a side effect, so pruning never touches sites regardless of settings.
- **Prune by default.** See [`allowPrune`](configuration#allowprune).
- **Change a field's type quietly.** That rewrites the field's storage and the old values do not
  come back. It is a blocked conflict until you pass `--force`.
- **Bring content back.** Rolling back a run that created a section deletes that section, and its
  entries go with it. Archie says so before it does it.
