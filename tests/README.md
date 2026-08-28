# Tests

## Unit suite

```sh
composer test
```

41 tests covering the parts of Archie that are pure logic: the diff engine, the blueprint parser,
the Craft 4 Matrix rewrite, type shorthand resolution and route URI compilation. They do not boot
Craft, and they should not need to — keeping this logic testable without a database is what keeps
it honest.

## Checking against a real Craft

The behaviour worth verifying against a live install is the round trip, and it needs no test
harness:

```sh
php craft archie/blueprint/apply my-blueprint.yaml
php craft archie/blueprint/plan my-blueprint.yaml     # must report 0 to create, 0 to update
```

A second plan reporting any change means `dump()` and `apply()` disagree somewhere, which is the
failure mode that matters. The same check run against an export is stronger still:

```sh
php craft archie/blueprint/export --only=sections --handles=blog --out=/tmp/roundtrip.yaml
php craft archie/blueprint/plan /tmp/roundtrip.yaml   # must report everything unchanged
```

Then roll it back and confirm the plan returns to all-creates:

```sh
php craft archie/history/rollback <id>
php craft archie/blueprint/plan my-blueprint.yaml
```
