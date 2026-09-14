---
title: FAQ
slug: faq
order: 50
summary: How Archie relates to project config, to Architect, and to your deploy.
---

## Is Archie free?

Yes, and it is not a trial. It is licensed under the
[Craft License](https://craftcms.github.io/license/) — free, not paid, and not MIT, like every
plugin in this family.

## Doesn't project config already do this?

Project config **syncs** a content model between environments. It has never been able to **author**
one. That is the whole distinction.

Project config is a serialisation: a faithful record of what Craft currently has, written in UIDs,
produced by the CP and consumed by `craft up`. It is not a document you write. Nobody opens
`project/fields/8f2c….yaml` and types a new field into it, and if they did they would be
hand-editing a generated file that the next CP save overwrites.

A blueprint is source. It is written by a person, in handles, describing what should exist — and
Archie turns it into the project-config changes that make it so. The two are complements: Archie
puts a model into an environment, and project config carries it to the others.

## Is this Architect?

It is the Craft 5 replacement for it. [Architect](https://github.com/pennebaker/craft-architect)
stopped at Craft 4 and is not affiliated with this plugin.

Archie does what Architect did — build sections, entry types, fields, volumes, category groups,
transforms, user groups and routes from a document you can write and commit — and adds the four
things that were missing: it shows you the diff first, it can be undone, it lints before it runs,
and it reads Architect's own Craft 4 documents.

## Can I use my old Architect blueprints?

Yes, directly. Point Archie at one and it reads it: section-nested entry types are hoisted, the
tab-map field layout shape is understood, field groups are dropped with an explanation, and Craft 4
Matrix block types are rewritten into Craft 5 entry types with their inner fields promoted to real
fields.

`archie/blueprint/convert` writes the result back out as idiomatic Archie YAML, so you can read it
before you trust it.

## Is it safe to run on production?

Linting, planning, exporting and converting are safe anywhere — they read, and write nothing.
Running `plan` on production is a genuinely good idea: it is how you find out what a deploy would do
to the content model before it does it.

Applying is limited to environments where `allowAdminChanges` is on, which is Craft's own rule about
which environments own the content model.

## What happens if I apply the same blueprint twice?

Nothing, the second time. Applies are idempotent: the plan compares the blueprint against what is
actually there, and a component that already matches is left alone. Re-running a blueprint is normal
and expected, which is what makes it usable in a deploy script.

## Will it delete things I did not mention?

No, unless you ask twice. A blueprint that mentions three fields is not asking for every other field
to be deleted — it describes three fields and says nothing about the rest.

Pruning has to be switched on in the settings *and* requested on the run with `--prune`. Even then
it never touches sites, because deleting a site deletes the content that only lived there.

## Can I undo an apply?

Yes. Every apply records what each component looked like beforehand. Roll the run back — from
**Archie → History** or `archie/history/rollback <id>` — and Archie deletes what it created and
restores what it changed, newest first.

What it cannot undo is content. Rolling back a run that created a section deletes that section, and
its entries go with it. Archie names every such deletion before it does anything.

## Does it work in CI?

Yes, and that is one of the better reasons to use it. `lint` and `plan` exit non-zero when the
blueprint could not be applied cleanly, so:

```sh
php craft archie/blueprint/plan config/archie/*.yaml
```

on a pull request fails the build if the content model the branch describes cannot be built. Applies
take `--interactive=0` for unattended runs.

## Do I have to order the blueprint correctly?

No. An apply runs in dependency order regardless of how the document is written, and Archie handles
the one genuine cycle in Craft 5 — a Matrix field pointing at entry types whose layouts point back
at fields — by saving such a field twice, once without the references it cannot yet resolve and once
more at the end of the run.

Write the blueprint however it reads best.

## Does a blueprint have to describe the whole site?

No, and it should not. A blueprint is partial: it states what should exist and how those things
should be configured, and anything it does not mention is left alone.

That is what lets one blueprint describe a single section of a large site without claiming anything
about the rest of it, and what makes it reasonable to keep several small blueprints rather than one
enormous one.

## Why handles instead of UIDs?

Because a blueprint is meant to be read, reviewed and reused. `sources: [blog]` means the same thing
on every install; `sources: ['section:8f2c…']` means something only on the install it came from.

Archie translates handles to UIDs on the way in and back to handles on the way out, so the document
stays portable in both directions.

## Can it handle my plugin's field type?

Yes. Type shorthands are derived from the registered classes themselves, so a plugin's field type
gets a shorthand the day it is installed, and the full class name always works.

If your plugin owns a whole kind of *component* rather than a field type, it can register a
component handler of its own and Archie will plan, apply, lint, export and roll it back alongside
everything else. See [Extending Archie](extending).

## What about Commerce, or other plugins' components?

Anything exposed as a field type works today. Whole component types belonging to a plugin — product
types, for instance — need that plugin to register a handler, which is a small class. The extension
point exists precisely so that does not have to live in Archie.
