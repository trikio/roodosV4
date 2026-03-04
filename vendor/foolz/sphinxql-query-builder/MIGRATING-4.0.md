# Migrating to 4.0

This guide covers migration from the 3.x line to the 4.0 line.

## Baseline Requirements

- PHP 8.2+
- `mysqli` or `pdo_mysql` extension

## Major Behavioral Changes

4.0 introduces strict runtime validation for query builder and helper input.
Invalid query-shape arguments now fail fast with `Foolz\SphinxQL\Exception\SphinxQLException`.

### SphinxQL builder strict validation

The following now throw on invalid input:

- `setType()` unknown query type
- `compile()` with no selected query type
- `from()` with empty or invalid index input
- `facet()` with non-`Facet` argument
- `orderBy()` / `withinGroupOrderBy()` invalid direction (must be `ASC` or `DESC`)
- `limit()` / `offset()` negative or invalid integer values
- `groupNBy()` non-positive values
- `where()` / `having()` invalid filter value shape for `IN`/`NOT IN`/`BETWEEN`
- `into()`, `columns()`, `values()`, `value()`, `set()` invalid/empty input
- `update()->compile()` / `update()->execute()` without `into($index)`
- `setQueuePrev()` non-`SphinxQL` argument

### Facet strict validation

- empty `facet()` input
- empty function/params in `facetFunction()` and `orderByFunction()`
- invalid direction in `orderBy()` / `orderByFunction()`
- negative or invalid `limit()` / `offset()`

### Helper strict validation

Helper methods now validate required identifiers and argument shapes:

- `showTables()` accepts `null`/empty for unfiltered `SHOW TABLES`, and validates non-string filters
- `describe()`, `showIndexStatus()`, `flushRtIndex()`,
  `truncateRtIndex()`, `optimizeIndex()`, `flushRamchunk()`, etc.
- `setVariable()` validates variable names and array values
- `callSnippets()` and `callKeywords()` validate required arguments
- `createFunction()` validates return type (`INT`, `UINT`, `BIGINT`, `FLOAT`, `STRING`)
- capability-aware APIs are available via `getCapabilities()`/`supports()`
- feature-gated methods may throw `UnsupportedFeatureException` when unsupported

### Percolate strict validation

Percolate input now rejects invalid payload types earlier instead of relying on
implicit coercion, and string sanitization paths are null-safe.

## Exception Message Format

Driver-level connection/query exceptions now include a source prefix:

- `[mysqli][connect]...`
- `[mysqli][query]...`
- `[pdo][connect]...`
- `[pdo][query]...`

If your code matches exact exception strings, update those checks to match
message fragments or exception classes.

## Migration Tips

1. Validate user input before passing it to query builder methods.
2. Replace implicit coercions with explicit typing/casting in your app layer.
3. Prefer exception-class checks over exact message equality.
4. Run your integration tests against your target engine (`Sphinx2`, `Sphinx3`, `Manticore`).
5. Prefer `supports($feature)` checks before engine-specific helper calls.
