# Archie

**Author a Craft content model as a YAML or JSON blueprint — then lint it, plan the diff, apply it, and roll it back.**

Archie is a Craft 5 replacement for [Architect](https://github.com/pennebaker/craft-architect), which
stopped at Craft 4. It does what Architect did — build sections, entry types, fields, volumes,
category groups, transforms, user groups and routes from a document you can write and commit — and
adds the four things that were missing.

Project config already syncs a content model between environments. It has never been able to
*author* one. That is the gap Archie fills.

Archie is free. It is licensed under the [Craft License](LICENSE.md), like every plugin in this
family — free, not paid, and not MIT.

---

## What it does that Architect didn't

**It shows you the diff first.** `plan` compares the blueprint against the live site and prints
exactly what would be created, what already exists, and which attributes differ — before anything is
written. Re-running a blueprint is safe and normal: applying it twice does nothing the second time.

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

**It can be undone.** Every apply records what each component looked like beforehand. Roll a run
back and Archie deletes what it created and restores what it changed, newest first — and tells you
plainly which of those deletions take content with them, because that part does not come back.

**It reads your Craft 4 blueprints.** Point Archie at an Architect document and it reads it:
section-nested entry types are hoisted, the tab-map field layout shape is understood, field groups
are dropped with an explanation, and Craft 4 Matrix block types are rewritten into Craft 5 entry
types with their inner fields promoted to real fields. `archie/blueprint/convert` writes the result
back out as idiomatic Archie YAML so you can read it before you trust it.

**It checks the blueprint before it runs.** Unknown field types, ambiguous shorthands, reserved
handles, duplicate handles, layouts naming fields that do not exist, a section pointing at a missing
entry type, credentials written into a filesystem in plain text, an environment with
`allowAdminChanges` off — all reported as errors or warnings up front rather than as a half-built
content model.

It also ships **seven recipes** — blog, page builder, team, FAQ, events, SEO fields, media volume —
as ordinary blueprints you copy into your project and edit.

---

## Requirements

Craft CMS 5.3+ and PHP 8.2+. No runtime dependencies beyond Craft itself.

## Installation

```sh
composer require justinholtweb/craft-archie
php craft plugin/install archie
```

Archie is admin-only, and applying additionally requires `allowAdminChanges` — the same rule Craft
applies to editing fields and sections. Planning and exporting work anywhere, including production,
which is exactly where you want to be able to capture a model or check what a deploy would do.

---

## A blueprint

Blueprints live in `config/archie/`, beside the project config they produce.

```yaml
archie: 1
name: Blog

vars:
  handle: blog

fields:
  - handle: summary
    name: Summary
    type: plainText
    instructions: One or two sentences, used in listings.
    searchable: true
    settings:
      multiline: true
      charLimit: 300

  - handle: featuredImage
    type: assets
    settings:
      sources: '*'
      maxRelations: 1

entryTypes:
  - handle: article
    name: Article
    icon: newspaper
    color: blue
    fieldLayout:
      - name: Content
        fields:
          - handle: summary
            required: true
      - name: Media
        fields: [featuredImage]

sections:
  - handle: '{{ handle }}'
    name: Blog
    type: channel
    entryTypes: [article]
    uriFormat: '{{ handle }}/{slug}'
    template: '{{ handle }}/_entry'
```

Three things make that readable rather than merely valid, and they are the difference between a
blueprint and a chunk of project config:

- **Everything is a handle.** `sources: [blog]` rather than `sources: ['section:8f2c…']`. Archie
  translates handles to UIDs on the way in and back to handles on the way out, so a blueprint means
  the same thing on every install.
- **Types have shorthands.** `plainText`, `matrix`, `assets`, `ckeditor` — derived from the
  registered classes themselves, so a plugin's field type gets a shorthand the day it is installed.
  The full class name always works too.
- **A blueprint is partial.** It states what should exist and how those things should be
  configured. Anything it does not mention is left alone — which is why it can describe one section
  of a large site without claiming anything about the rest.

Full format reference: **[docs/FORMAT.md](docs/FORMAT.md)**.

---

## Using it

### In the control panel

**Archie → Blueprints** lists the blueprints in your project and lets you write, upload or paste
one. **Recipes** are the bundled starting points. **Export** reads the live model back out.
**History** is every apply, with an undo. Planning is always the step between choosing a blueprint
and applying it; there is no way to apply one without seeing the plan.

### On the command line

```sh
# Check a blueprint without touching anything
php craft archie/blueprint/lint blog.yaml

# See what it would do
php craft archie/blueprint/plan blog.yaml

# Do it
php craft archie/blueprint/apply blog.yaml

# Unattended, for CI
php craft archie/blueprint/apply blog.yaml --interactive=0

# Read the live model back out
php craft archie/blueprint/export --only=sections --handles=blog --out=config/archie/blog.yaml

# Rewrite an Architect blueprint as an Archie one
php craft archie/blueprint/convert old-architect.json --out=config/archie/blog.yaml

# Recipes
php craft archie/recipes
php craft archie/recipes/copy blog

# Undo
php craft archie/history
php craft archie/history/rollback 4
```

`lint` and `plan` exit non-zero when the blueprint could not be applied cleanly, which is what makes
them useful in a pipeline: run `plan` on a pull request and it fails if the content model the branch
describes cannot be built.

Variables are supplied with `--vars`:

```sh
php craft archie/blueprint/plan blog.yaml --vars="handle=news,name=Newsroom"
```

---

## How it decides the order

An apply runs in dependency order: site groups, sites, filesystems, transforms, fields, entry types,
sections, volumes, category groups, tag groups, global sets, user groups, routes.

That order has a cycle in it, and it is the defining one in Craft 5: a Matrix field points at entry
types, and those entry types' field layouts point back at fields. Archie breaks it by saving such a
field twice — once without the references it cannot yet resolve, and once more at the end of the run
when everything exists. You do not have to order your blueprint to suit; write it however it reads
best.

---

## What it will not do

- **Delete a site.** Deleting a site deletes the content that only lived there. No blueprint should
  be able to do that as a side effect.
- **Prune by default.** A blueprint that mentions three fields is not asking for every other field
  to be deleted. Pruning has to be enabled in the settings *and* requested on the run, and it never
  touches sites.
- **Change a field's type quietly.** That rewrites the field's storage and the old values do not
  come back, so it is a blocked conflict until you pass `--force`.
- **Bring content back.** Rolling back a run that created a section deletes that section, and its
  entries go with it. Archie says so before it does it.

---

## Extending it

Archie is built out of component handlers — one class per kind of thing a blueprint can declare,
each owning how that thing is found, read, written, linted and deleted. A plugin can add its own:

```php
use justinholtweb\archie\services\Handlers;
use justinholtweb\archie\events\RegisterComponentHandlersEvent;

Event::on(Handlers::class, Handlers::EVENT_REGISTER_COMPONENT_HANDLERS,
    function(RegisterComponentHandlersEvent $event) {
        $event->handlers[] = MyComponentHandler::class;
    }
);
```

See **[docs/EXTENDING.md](docs/EXTENDING.md)**.

---

## Support

Issues and questions: <https://github.com/justinholtweb/craft-archie/issues>
