# Blueprint format

A blueprint is a YAML or JSON document describing part of a Craft content model. Archie decides
which by looking at the first character: a `{` or `[` means JSON, anything else is parsed as YAML.

## The document

```yaml
archie: 1                  # schema version; optional, defaults to 1
name: Blog                 # shown in the CP and in run history
description: …             # optional
vars:                      # values for {{ placeholders }} below
  handle: blog
fields: […]                # components, by type
entryTypes: […]
sections: […]
```

Every other top-level key is a component type. These are the canonical names, in the order an apply
runs them:

`siteGroups`, `sites`, `filesystems`, `transforms`, `fields`, `entryTypes`, `sections`, `volumes`,
`categoryGroups`, `tagGroups`, `globalSets`, `userGroups`, `routes`

Singular spellings work, as do a few natural aliases (`globals`, `categories`, `tags`, `fs`,
`imageTransforms`). Unknown keys are ignored with a notice rather than an error, so a document
carrying an extra key from somewhere else still runs.

## Components

A component type takes either a list or a handle-keyed mapping. These are the same:

```yaml
fields:
  - handle: summary
    type: plainText
```
```yaml
fields:
  summary:
    type: plainText
```

Every component needs a handle. If you give a `name` and no `handle`, one is derived from it; if you
give a `handle` and no `name`, the name is derived from that. Deriving is not the same as stating:
a derived value is used when creating a component and ignored when comparing against an existing
one, so a blueprint that never mentions a name will never rename anything.

## Blueprints are partial

A blueprint says what should exist and how it should be configured. It does not claim to be a
complete inventory, and Archie only ever compares the keys you actually wrote. A blueprint that says
nothing about `searchable` is not asking for `searchable: false`.

A key that the component has no equivalent for — a Craft 4 leftover, or a setting belonging to a
different field type — is reported in the plan as *ignored* rather than treated as a difference,
because a difference no apply could resolve is a plan that never comes clean.

## Variables

```yaml
vars:
  handle: blog
sections:
  - handle: '{{ handle }}'
    uriFormat: '{{ handle }}/{slug}'
```

Supply or override them with `--vars="handle=news"` on the console, or in the CP's variables box
(one `name = value` per line). A value that is *only* a placeholder takes the variable's real type,
so `enableVersioning: '{{ live }}'` with `live: false` gives a boolean, not the string `"false"`.

A placeholder nothing supplies a value for is a lint error, not a silent empty string.

## References

Everything a blueprint points at is named by handle. Archie translates to UIDs on the way in and
back on the way out.

```yaml
fields:
  - handle: relatedPosts
    type: entries
    settings:
      sources: [blog, news]        # section handles

  - handle: pageBuilder
    type: matrix
    settings:
      entryTypes: [textBlock, imageBlock]

sections:
  - handle: blog
    entryTypes: [article]          # entry type handles

volumes:
  - handle: media
    fs: media                      # filesystem handle
```

The prefixed form (`section:blog`, `volume:media`, `group:topics`) is accepted too, and is what
exports write, since a bare handle is ambiguous once more than one kind of source is possible.

## Field types

`type` takes a shorthand or a full class name:

```yaml
type: plainText                    # craft\fields\PlainText
type: matrix                       # craft\fields\Matrix
type: ckeditor                     # craft\ckeditor\Field
type: craft\fields\PlainText       # always works
```

Shorthands are derived from the registered classes: the short class name with a redundant `Field`
suffix removed, falling back to the plugin's namespace segment when the class is called plain
`Field`. A plugin's field type therefore gets a shorthand the day it is installed. If two plugins
derive the same shorthand, Archie says so and asks for the full class name.

The same applies to filesystem types (`local`).

## Field layouts

Three shapes are accepted, and they mean the same thing:

```yaml
fieldLayout: [summary, body]                     # one “Content” tab
```
```yaml
fieldLayout:                                     # Architect's tab map
  Content: [summary]
  SEO: [metaTitle]
```
```yaml
fieldLayout:                                     # Archie's own
  - name: Content
    fields:
      - summary                                  # bare handle
      - handle: body                             # or with attributes
        required: true
        width: 50
        label: Article body
        instructions: …
      - ui: heading                              # a UI element
        heading: SEO
      - ui: rule
      - native: entryTitle                       # a native element
```

UI elements: `heading`, `tip`, `rule`, `lineBreak`, `template`, `markdown`, `html`.

`hasTitleField` on an entry type is handled for you. Craft derives it from whether the layout
contains the title element rather than storing what you set, so Archie adds or removes that element
to match what you asked for.

A layout naming a field that does not exist and is not created by the blueprint is a lint error.
This matters more than it looks: Craft's own layout builder drops unknown elements without a word,
so without the check a typo produces a silently shorter layout and no error anywhere.

## Sections

```yaml
sections:
  - handle: blog
    name: Blog
    type: channel                  # channel | structure | single
    enableVersioning: true
    propagationMethod: all
    defaultPlacement: end
    maxLevels: 3                   # structures only
    entryTypes: [article]

    # Per-site settings, keyed by site handle
    siteSettings:
      default:
        uriFormat: 'blog/{slug}'
        template: 'blog/_entry'
        enabledByDefault: true
      second: false                # turn the section off for this site

    # …or, for every site at once:
    uriFormat: 'blog/{slug}'
    template: 'blog/_entry'
```

The top-level shorthand and the `'*'` key both mean "every site", and are expanded to per-site
entries before anything is compared. `hasUrls` is inferred from whether a `uriFormat` was given.

A site the blueprint does not mention keeps whatever settings it already has.

## Routes

```yaml
routes:
  - uri: 'blog/{slug}'
    template: 'blog/_entry'
    site: default                  # optional
  - uri: 'news/{year:\d{4}}/{slug}'
    template: 'news/_entry'
```

`{name}` matches Craft's default of `[^\/]+`; `{name:pattern}` sets the pattern explicitly.

## Filesystems

Credentials do not belong in a blueprint. Use environment variables; Archie warns when a setting
whose name looks like a secret holds a literal value.

```yaml
filesystems:
  - handle: media
    type: local
    hasUrls: true
    url: '@web/uploads/media'
    settings:
      path: '@webroot/uploads/media'
```

## Reading Craft 4 blueprints

Archie reads Architect documents directly. It:

- hoists entry types defined inside a section up to the top level, where Craft 5 keeps them;
- reads the tab-map field layout shape;
- maps `field_type` → `type` and `typesettings` → `settings`;
- drops field groups, which Craft 5 removed, with a notice naming each one;
- drops `titleLabel` and other retired keys, with a notice;
- strips `id`, `uid`, `sortOrder` and other keys that only mean something on one install;
- rewrites Craft 4 Matrix `blockTypes` into Craft 5 entry types, promoting each block's fields to
  real fields and pointing the Matrix field at the new entry types by handle.

That last one is the reason a Craft 4 blueprint cannot simply be re-run: the most common thing in it
no longer exists. Where a block handle or an inner field handle is already taken at the top level —
they only had to be unique within their own field in Craft 4 — Archie qualifies it with the Matrix
field's handle and tells you which name it chose.

`php craft archie/blueprint/convert old.json --out=new.yaml` writes the converted document out so
you can read it before applying it.
