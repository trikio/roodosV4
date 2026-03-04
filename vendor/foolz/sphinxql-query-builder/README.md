Query Builder for SphinxQL
==========================

[![CI](https://github.com/FoolCode/SphinxQL-Query-Builder/actions/workflows/ci.yml/badge.svg)](https://github.com/FoolCode/SphinxQL-Query-Builder/actions/workflows/ci.yml)
[![Documentation](https://github.com/FoolCode/SphinxQL-Query-Builder/actions/workflows/docs.yml/badge.svg)](https://github.com/FoolCode/SphinxQL-Query-Builder/actions/workflows/docs.yml)
[![Latest Stable Version](https://poser.pugx.org/foolz/sphinxql-query-builder/v/stable)](https://packagist.org/packages/foolz/sphinxql-query-builder)
[![Latest Unstable Version](https://poser.pugx.org/foolz/sphinxql-query-builder/v/unstable)](https://packagist.org/packages/foolz/sphinxql-query-builder)
[![Total Downloads](https://poser.pugx.org/foolz/sphinxql-query-builder/downloads)](https://packagist.org/packages/foolz/sphinxql-query-builder)

## About

This is a query builder for SphinxQL/ManticoreQL, the SQL dialect used by
Sphinx Search and Manticore Search. It maps most common query-builder use cases
and supports both `mysqli` and `PDO` drivers.

This Query Builder has no dependencies except PHP 8.2 or later, `\MySQLi` extension, `PDO`, and [Sphinx](http://sphinxsearch.com)/[Manticore](https://manticoresearch.com).

### Missing methods?

SphinxQL and ManticoreQL evolve fast. This library provides fluent builders for
core query composition and helper wrappers for common operational commands.

If any feature is still unreachable through this library, open an issue or send
a pull request.

## Code Quality

The majority of the methods in the package have been unit tested.

Helper methods and engine compatibility scenarios are covered by the test suite.

## Documentation

The docs are built with modern Sphinx + Furo styling.

Build locally:

```bash
python3 -m pip install -r docs/requirements.txt
sphinx-build --fail-on-warning --keep-going -b html docs docs/_build/html
```

CI builds docs for pull requests and deploys the rendered site to GitHub Pages
on pushes to `master`.

## How to Contribute

### Pull Requests

1. Fork the SphinxQL Query Builder repository
2. Create a new branch for each feature or improvement
3. Submit a pull request from each branch to the repository default branch

It is very important to separate new features or improvements into separate feature branches, and to send a pull
request for each branch. This allows me to review and pull in new features or improvements individually.

### Style Guide

All pull requests should follow PSR-12-compatible formatting.

### Unit Testing

All pull requests must be accompanied by passing unit tests and complete code coverage. The SphinxQL Query Builder uses
`phpunit` for testing.

[Learn about PHPUnit](https://github.com/sebastianbergmann/phpunit/)

## Installation

This is a Composer package. You can install this package with the following command: `composer require foolz/sphinxql-query-builder`

## Usage

The following examples will omit the namespace.

```php
<?php
use Foolz\SphinxQL\SphinxQL;
use Foolz\SphinxQL\Drivers\Mysqli\Connection;

// create a SphinxQL Connection object to use with SphinxQL
$conn = new Connection();
$conn->setParams(array('host' => 'domain.tld', 'port' => 9306));

$query = (new SphinxQL($conn))->select('column_one', 'colume_two')
    ->from('index_ancient', 'index_main', 'index_delta')
    ->match('comment', 'my opinion is superior to yours')
    ->where('banned', '=', 1);

$result = $query->execute();
```

### Drivers

We support the following database connection drivers:

* Foolz\SphinxQL\Drivers\Mysqli\Connection
* Foolz\SphinxQL\Drivers\Pdo\Connection

### Engine Compatibility Matrix

| Engine | Query Builder | Helper APIs | Notes |
| --- | --- | --- | --- |
| Sphinx 2.x | Supported | Supported | Full CI lane |
| Sphinx 3.x | Supported | Supported with engine-specific assertions | Full CI lane |
| Manticore | Supported | Supported + Percolate | Full CI lane |

Detailed feature-level support is tracked in [`docs/feature-matrix.yml`](docs/feature-matrix.yml).

### Migration to 4.0

See [`MIGRATING-4.0.md`](MIGRATING-4.0.md) for the complete migration checklist,
including strict runtime validation behavior introduced in the 4.0 line.

### Connection

* __$conn = new Connection()__

	Create a new Connection instance to be used with the following methods or SphinxQL class.

* __$conn->setParams($params = array('host' => '127.0.0.1', 'port' => 9306))__

	Sets the connection parameters used to establish a connection to the server. Supported parameters: 'host', 'port', 'socket', 'username', 'password', 'options'.

* __$conn->query($query)__

	Performs the query on the server. Returns a [`ResultSet`](#resultset) object containing the query results.

_More methods are available in the Connection class, but usually not necessary as these are handled automatically._

### SphinxQL

* __new SphinxQL($conn)__

	Creates a SphinxQL instance used for generating queries.

#### Bypass Query Escaping

Often, you would need to call and run SQL functions that shouldn't be escaped in the query. You can bypass the query escape by wrapping the query in an `\Expression`.

* __SphinxQL::expr($string)__

	Returns the string without being escaped.

#### Query Escaping

There are cases when an input __must__ be escaped in the SQL statement. SQL value escaping is handled by the active connection object:

* __$conn->escape($value)__

	Returns the escaped value. This is processed with the `\MySQLi::real_escape_string()` function.

* __$conn->quote($value)__

	Adds quotes to the value and escapes it. For array elements, use `$conn->quoteArr($arr)`.

`SphinxQL` itself exposes MATCH helpers:

* __$sq->escapeMatch($value)__

	Escapes the string to be used in `MATCH`.

* __$sq->halfEscapeMatch($value)__

	Escapes the string to be used in `MATCH`. The following characters are allowed: `-`, `|`, and `"`.

	_Refer to `$sq->match()` for more information._

There is no dedicated `quoteIdentifier()` helper; pass only trusted index/column identifiers.

#### Strict Validation in 4.0

4.0 performs fail-fast validation for invalid query-shape input. Examples:

* invalid `setType()` values
* invalid `ORDER BY` direction values
* negative `limit()`/`offset()`
* invalid value shapes for `IN`/`BETWEEN`

#### SELECT

* __$sq = (new SphinxQL($conn))->select($column1, $column2, ...)->from($index1, $index2, ...)__

	Begins a `SELECT` query statement. If no column is specified, the statement defaults to using `*`. Both `$column1` and `$index1` can be arrays.

#### INSERT, REPLACE

This will return an `INT` with the number of rows affected.

* __$sq = (new SphinxQL($conn))->insert()->into($index)__

	Begins an `INSERT`.

* __$sq = (new SphinxQL($conn))->replace()->into($index)__

	Begins an `REPLACE`.

* __$sq->set($associative_array)__

	Inserts an associative array, with the keys as the columns and values as the value for the respective column.

* __$sq->value($column1, $value1)->value($column2, $value2)->value($column3, $value3)__

	Sets the value of each column individually.

* __$sq->columns($column1, $column2, $column3)->values($value1, $value2, $value3)->values($value11, $value22, $value33)__

	Allows the insertion of multiple arrays of values in the specified columns.

	Both `$column1` and `$index1` can be arrays.

MVA attributes are inserted/updated by passing arrays as values:

```php
<?php
(new SphinxQL($conn))
    ->replace()
    ->into('rt')
    ->set(array(
        'id' => 123,
        'title' => 'example',
        'rubrics' => array(10, 20),
        'districts' => array(1, 3, 5),
    ))
    ->execute();
```

#### UPDATE

This will return an `INT` with the number of rows affected.

* __$sq = (new SphinxQL($conn))->update($index = null)__

	Begins an `UPDATE`. You can pass the index immediately or set it later with `->into($index)`.

* __$sq->value($column1, $value1)->value($column2, $value2)__

	Updates the selected columns with the respective value.

* __$sq->set($associative_array)__

	Inserts the associative array, where the keys are the columns and the respective values are the column values.

#### DELETE

Will return an array with an `INT` as first member, the number of rows deleted.

* __$sq = (new SphinxQL($conn))->delete()->from($index)->where(...)__

	Begins a `DELETE`.

#### WHERE

* __$sq->where($column, $operator, $value)__

	Standard WHERE, extended to work with Sphinx filters and full-text.

    ```php
    <?php
    // WHERE `column` = 'value'
    $sq->where('column', 'value');

    // WHERE `column` = 'value'
    $sq->where('column', '=', 'value');

    // WHERE `column` >= 'value'
    $sq->where('column', '>=', 'value');

    // WHERE `column` IN ('value1', 'value2', 'value3')
    $sq->where('column', 'IN', array('value1', 'value2', 'value3'));

    // WHERE `column` NOT IN ('value1', 'value2', 'value3')
    $sq->where('column', 'NOT IN', array('value1', 'value2', 'value3'));

    // WHERE `column` BETWEEN 'value1' AND 'value2'
    // WHERE `example` BETWEEN 10 AND 100
    $sq->where('column', 'BETWEEN', array('value1', 'value2'));
	```

		You can compose grouped boolean filters with:
		`orWhere()`, `whereOpen()`, `orWhereOpen()`, and `whereClose()`.
		The same grouped API exists for `HAVING` via `having()`, `orHaving()`,
		`havingOpen()`, `orHavingOpen()`, and `havingClose()`.
		Repeated `having()` calls are additive (`AND`) unless you explicitly use
		`orHaving()` or grouped clauses.

#### MATCH

* __$sq->match($column, $value, $half = false)__

	Search in full-text fields. Can be used multiple times in the same query. Column can be an array. Value can be an Expression to bypass escaping (and use your own custom solution).

    ```php
    <?php
    $sq->match('title', 'Otoshimono')
        ->match('character', 'Nymph')
        ->match(array('hates', 'despises'), 'Oregano');
    ```

	By default, all inputs are escaped. The usage of `SphinxQL::expr($value)` is required to bypass the default escaping and quoting function.

	The `$half` argument, if set to `true`, will not escape and allow the usage of the following characters: `-`, `|`, `"`. If you plan to use this feature and expose it to public interfaces, it is __recommended__ that you wrap the query in a `try catch` block as the character order may `throw` a query error.

    ```php
    <?php
    use Foolz\SphinxQL\SphinxQL;

    try
    {
        $result = (new SphinxQL($conn))
            ->select()
            ->from('rt')
            ->match('title', 'Sora no || Otoshimono', true)
            ->match('title', SphinxQL::expr('"Otoshimono"/3'))
            ->match('loves', SphinxQL::expr(custom_escaping_fn('(you | me)')));
            ->execute();
    }
    catch (\Foolz\SphinxQL\DatabaseException $e)
    {
        // an error is thrown because two `|` one after the other aren't allowed
    }
	```

#### GROUP, WITHIN GROUP, ORDER, OFFSET, LIMIT, OPTION

* __$sq->groupBy($column)__

	`GROUP BY $column`

* __$sq->withinGroupOrderBy($column, $direction = null)__

	`WITHIN GROUP ORDER BY $column [$direction]`

	Direction can be omitted with `null`, or be `ASC` or `DESC` case insensitive.

* __$sq->orderBy($column, $direction = null)__

	`ORDER BY $column [$direction]`

	Direction can be omitted with `null`, or be `ASC` or `DESC` case insensitive.

* __$sq->orderByKnn($field, $k, array $vector, $direction = 'ASC')__

	`ORDER BY KNN($field, $k, $vector) [$direction]`

#### JOIN

* __$sq->join($table, $left, $operator, $right, $type = 'INNER')__
* __$sq->innerJoin($table, $left, $operator, $right)__
* __$sq->leftJoin($table, $left, $operator, $right)__
* __$sq->rightJoin($table, $left, $operator, $right)__
* __$sq->crossJoin($table)__

* __$sq->offset($offset)__

	`LIMIT $offset, 9999999999999`

	Set the offset. Since SphinxQL doesn't support the `OFFSET` keyword, `LIMIT` has been set at an extremely high number.

* __$sq->limit($limit)__

	`LIMIT $limit`

* __$sq->limit($offset, $limit)__

	`LIMIT $offset, $limit`

* __$sq->option($name, $value)__

	`OPTION $name = $value`

	Set a SphinxQL option such as `max_matches` or `reverse_scan` for the query.

#### TRANSACTION

* __(new SphinxQL($conn))->transactionBegin()__

	Begins a transaction.

* __(new SphinxQL($conn))->transactionCommit()__

	Commits a transaction.

* __(new SphinxQL($conn))->transactionRollback()__

	Rollbacks a transaction.

#### Executing and Compiling

* __$sq->execute()__

	Compiles, executes, and __returns__ a [`ResultSet`](#resultset) object containing the query results.

* __$sq->executeBatch()__

	Compiles, executes, and __returns__ a [`MultiResultSet`](#multiresultset) object containing the multi-query results.

* __$sq->compile()__

	Compiles the query.

* __$sq->getCompiled()__

	Returns the last query compiled.

* __$sq->getResult()__

	Returns the [`ResultSet`](#resultset) or [` MultiResultSet`](#multiresultset) object, depending on whether single or multi-query have been executed last.

#### Multi-Query

* __$sq->enqueue(SphinxQL $next = null)__

	Queues the query. If a $next is provided, $next is appended and returned, otherwise a new SphinxQL object is returned.

* __$sq->executeBatch()__

	Returns a [`MultiResultSet`](#multiresultset) object containing the multi-query results.

```php
<?php
use Foolz\SphinxQL\SphinxQL;

$result = (new SphinxQL($this->conn))
    ->select()
    ->from('rt')
    ->match('title', 'sora')
    ->enqueue((new SphinxQL($this->conn))->query('SHOW META')) // this returns the object with SHOW META query
    ->enqueue() // this returns a new object
    ->select()
    ->from('rt')
    ->match('content', 'nymph')
    ->executeBatch();
```

`$result` will contain [`MultiResultSet`](#multiresultset) object. Sequential calls to the `$result->getNext()` method allow you to get a [`ResultSet`](#resultset) object containing the results of the next enqueued query.


#### Query results

##### ResultSet

Contains the results of the query execution.

* __$result->fetchAllAssoc()__

	Fetches all result rows as an associative array.

* __$result->fetchAllNum()__

	Fetches all result rows as a numeric array.

* __$result->fetchAssoc()__

	Fetch a result row as an associative array.

* __$result->fetchNum()__

	Fetch a result row as a numeric array.

* __$result->getAffectedRows()__

	Returns the number of affected rows in the case of a DML query.

##### MultiResultSet

Contains the results of the multi-query execution.

* __$result->getNext()__
	
	Returns a [`ResultSet`](#resultset) object containing the results of the next query.


### Helper

The `Helper` class contains useful methods that don't need "query building".

Remember to `->execute()` to get a result.

* __Helper::pairsToAssoc($result)__

	Takes the pairs from a SHOW command and returns an associative array key=>value

* __$helper->getCapabilities()__

	Returns a `Capabilities` object with detected engine/version and feature flags.

* __$helper->supports($feature)__

	Checks whether a named feature is supported by the current backend/runtime.

* __$helper->requireSupport($feature, $context = '')__

	Throws `UnsupportedFeatureException` when the requested feature is not available.

`SphinxQL` also exposes capability helpers:

* __$sphinxql->getCapabilities()__
* __$sphinxql->supports($feature)__
* __$sphinxql->requireSupport($feature, $context = '')__

The following methods return a prepared `SphinxQL` object. You can also use `->enqueue($next_object)`:

```php
<?php
use Foolz\SphinxQL\SphinxQL;

$result = (new SphinxQL($this->conn))
    ->select()
    ->from('rt')
    ->where('gid', 9003)
    ->enqueue((new Helper($this->conn))->showMeta()) // this returns the object with SHOW META query prepared
    ->enqueue() // this returns a new object
    ->select()
    ->from('rt')
    ->where('gid', 201)
    ->executeBatch();
```

* `(new Helper($conn))->showMeta() => 'SHOW META'`
* `(new Helper($conn))->showWarnings() => 'SHOW WARNINGS'`
* `(new Helper($conn))->showStatus() => 'SHOW STATUS'`
* `(new Helper($conn))->showProfile() => 'SHOW PROFILE'`
* `(new Helper($conn))->showPlan() => 'SHOW PLAN'`
* `(new Helper($conn))->showThreads() => 'SHOW THREADS'`
* `(new Helper($conn))->showVersion() => 'SHOW VERSION'`
* `(new Helper($conn))->showPlugins() => 'SHOW PLUGINS'`
* `(new Helper($conn))->showAgentStatus() => 'SHOW AGENT STATUS'`
* `(new Helper($conn))->showScroll() => 'SHOW SCROLL'`
* `(new Helper($conn))->showDatabases() => 'SHOW DATABASES'`
* `(new Helper($conn))->showCharacterSet() => 'SHOW CHARACTER SET'`
* `(new Helper($conn))->showCollation() => 'SHOW COLLATION'`
* `(new Helper($conn))->showTables($index = null) => 'SHOW TABLES' (null/empty) or 'SHOW TABLES LIKE <quoted index>'`
* `(new Helper($conn))->showVariables() => 'SHOW VARIABLES'`
* `(new Helper($conn))->showCreateTable($table)`
* `(new Helper($conn))->showTableStatus($table = null) => 'SHOW TABLE STATUS' or 'SHOW TABLE <table> STATUS'`
* `(new Helper($conn))->showTableSettings($table)`
* `(new Helper($conn))->showTableIndexes($table)`
* `(new Helper($conn))->showQueries()`
* `(new Helper($conn))->setVariable($name, $value, $global = false)`
* `(new Helper($conn))->callSnippets($data, $index, $query, $options = array())`
* `(new Helper($conn))->callKeywords($text, $index, $hits = null)`
* `(new Helper($conn))->callSuggest($text, $index, $options = array())`
* `(new Helper($conn))->callQSuggest($text, $index, $options = array())`
* `(new Helper($conn))->callAutocomplete($text, $index, $options = array())`
* `(new Helper($conn))->describe($index)`
* `(new Helper($conn))->createFunction($udf_name, $returns, $soname)`
* `(new Helper($conn))->dropFunction($udf_name)`
* `(new Helper($conn))->attachIndex($disk_index, $rt_index)`
* `(new Helper($conn))->flushRtIndex($index)`
* `(new Helper($conn))->optimizeIndex($index)`
* `(new Helper($conn))->showIndexStatus($index)`
* `(new Helper($conn))->flushRamchunk($index)`
* `(new Helper($conn))->flushAttributes()`
* `(new Helper($conn))->flushHostnames()`
* `(new Helper($conn))->flushLogs()`
* `(new Helper($conn))->reloadPlugins()`
* `(new Helper($conn))->kill($queryId)`

Suggest-family option contract and capability behavior:

* `callSuggest()`, `callQSuggest()`, and `callAutocomplete()` accept `$options` as an associative array.
* Option keys must be non-empty strings. Each option is compiled as `<quoted_value> AS <key>`.
* Option values are quoted by the active connection (`quote()`/`quoteArr()`), covering scalar values, `null`, `Expression`, and arrays.
* Repository-tested option keys are `limit` (numeric) and `fuzzy` (numeric, `callAutocomplete()`).
* `callQSuggest()` and `callAutocomplete()` are feature-gated and throw `UnsupportedFeatureException` when unavailable.
* `callSuggest()` is runtime-conditional by backend support; use `$helper->supports('call_suggest')` for portable flows.

### Percolate
 The `Percolate` class provides methods for the "Percolate query" feature of Manticore Search.
 For more information about percolate queries refer the [Percolate Query](https://docs.manticoresearch.com/latest/html/searching/percolate_query.html) documentation.

#### INSERT

The Percolate class provide a dedicated helper for inserting queries in a `percolate` index. 

```php
<?php
use Foolz\SphinxQL\Percolate;

$query = (new Percolate($conn))
     ->insert('full text query terms',false)      
     ->into('pq')                                              
     ->tags(['tag1','tag2'])                                  
     ->filter('price>3')                                      
     ->execute();
 ```

* __`$pq = (new Percolate($conn))->insert($query,$noEscape)`__

    Begins an ``INSERT``. A single query is allowed to be added per insert. By default, the query string is escaped. Optional second parameter  `$noEscape` can be set to  `true` for not applying the escape.

* __`$pq->into($index)`__

   Set the percolate index for insert.

* __`$pq->tags($tags)`__

   Set a list of tags per query. Accepts array of strings or string delimited by comma

* __`$pq->filter($filter)`__
   Sets an attribute filtering string. The string must look the same as string of an WHERE attribute filters clause

* __`$pq->execute()`__

   Execute the `INSERT`.

#### CALLPQ

  Searches for stored queries that provide matching for input documents.
  
```php
<?php
use Foolz\SphinxQL\Percolate;
$query = (new Percolate($conn))
     ->callPQ()
     ->from('pq')                                              
     ->documents(['multiple documents', 'go this way'])        
     ->options([                                               
           Percolate::OPTION_VERBOSE => 1,
           Percolate::OPTION_DOCS_JSON => 1
     ])
     ->execute();
 ```

* __`$pq = (new Percolate($conn))->callPQ()`__

   Begins a `CALL PQ`

* __`$pq->from($index)`__

   Set percolate index.

* __`$pq->documents($docs)`__

   Set the incoming documents. $docs can be:
   
  - a single plain string (requires `Percolate::OPTION_DOCS_JSON` set to 0)
  - array of plain strings (requires `Percolate::OPTION_DOCS_JSON` set to 0)
  - a single JSON document
  - an array of JSON documents
  - a JSON object containing an  array of JSON objects
   

* __`$pq->options($options)`__

    Set options of `CALL PQ`. Refer the Manticore docs for more information about the `CALL PQ` parameters.
    
  - __Percolate::OPTION_DOCS_JSON__ (`as docs_json`) default to 1 (docs are json objects). Needs to be set to 0 for plain string documents.
        Documents added as associative arrays will be converted to JSON when sending the query to Manticore.
   - __Percolate::OPTION_VERBOSE__ (`as verbose`) more information is printed by following `SHOW META`, default is 0
   - __Percolate::OPTION_QUERY__  (`as query`) returns all stored queries fields , default is 0
   - __Percolate::OPTION_DOCS__  (`as docs`) provide result set as per document matched (instead of per query), default is 0

* `$pq->execute()`

   Execute the `CALL PQ`.

## Laravel

Laravel's dependency injection and realtime facades brings more convenience to SphinxQL Query Builder usage.

```php
// Register connection:
use Foolz\SphinxQL\Drivers\ConnectionInterface;
use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(ConnectionInterface::class, function ($app) {
            $conn = new Connection();
            $conn->setParams(['host' => 'domain.tld', 'port' => 9306]);
            return $conn;
        });
    }
}

// In another file:
use Facades\Foolz\SphinxQL\SphinxQL;

$result = SphinxQL::select('column_one', 'colume_two')
    ->from('index_ancient', 'index_main', 'index_delta')
    ->match('comment', 'my opinion is superior to yours')
    ->where('banned', '=', 1)
    ->execute();
```

Facade access also works with `Helper` and `Percolate`.
