<?php

namespace Foolz\SphinxQL;

use Foolz\SphinxQL\Drivers\ConnectionInterface;
use Foolz\SphinxQL\Exception\SphinxQLException;
use Foolz\SphinxQL\Exception\UnsupportedFeatureException;

/**
 * SQL queries that don't require "query building"
 * These return a valid SphinxQL that can even be enqueued
 */
class Helper
{
    /**
     * @var ConnectionInterface
     */
    protected ConnectionInterface $connection;

    /**
     * @var Capabilities|null
     */
    protected ?Capabilities $capabilities = null;

    /**
     * @var array<string,bool>
     */
    protected array $feature_support_cache = array();

    /**
     * @var null|array<int,string>
     */
    protected ?array $probe_tables_cache = null;

    /**
     * @param ConnectionInterface $connection
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Returns a new SphinxQL instance
     *
     * @return SphinxQL
     */
    protected function getSphinxQL(): SphinxQL
    {
        return new SphinxQL($this->connection);
    }

    /**
     * Prepares a query in SphinxQL (not executed)
     *
     * @param $sql
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    protected function query(string $sql): SphinxQL
    {
        return $this->getSphinxQL()->query($sql);
    }

    /**
     * Converts the columns from queries like SHOW VARIABLES to simpler key-value
     *
     * @param array $result The result of an executed query
     *
     * @return array Associative array with Variable_name as key and Value as value
     * @todo make non static
     */
    public static function pairsToAssoc(array $result): array
    {
        $ordered = array();

        foreach ($result as $item) {
            $ordered[$item['Variable_name']] = $item['Value'];
        }

        return $ordered;
    }

    /**
     * Runs query: SHOW META
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function showMeta(): SphinxQL
    {
        return $this->query('SHOW META');
    }

    /**
     * Runs query: SHOW WARNINGS
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function showWarnings(): SphinxQL
    {
        return $this->query('SHOW WARNINGS');
    }

    /**
     * Runs query: SHOW STATUS
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function showStatus(): SphinxQL
    {
        return $this->query('SHOW STATUS');
    }

    /**
     * Runs query: SHOW PROFILE
     *
     * @return SphinxQL
     */
    public function showProfile(): SphinxQL
    {
        return $this->query('SHOW PROFILE');
    }

    /**
     * Runs query: SHOW PLAN
     *
     * @return SphinxQL
     */
    public function showPlan(): SphinxQL
    {
        return $this->query('SHOW PLAN');
    }

    /**
     * Runs query: SHOW THREADS
     *
     * @return SphinxQL
     */
    public function showThreads(): SphinxQL
    {
        return $this->query('SHOW THREADS');
    }

    /**
     * Runs query: SHOW VERSION
     *
     * @return SphinxQL
     */
    public function showVersion(): SphinxQL
    {
        return $this->query('SHOW VERSION');
    }

    /**
     * Runs query: SHOW PLUGINS
     *
     * @return SphinxQL
     */
    public function showPlugins(): SphinxQL
    {
        return $this->query('SHOW PLUGINS');
    }

    /**
     * Runs query: SHOW AGENT STATUS
     *
     * @return SphinxQL
     */
    public function showAgentStatus(): SphinxQL
    {
        return $this->query('SHOW AGENT STATUS');
    }

    /**
     * Runs query: SHOW SCROLL
     *
     * @return SphinxQL
     */
    public function showScroll(): SphinxQL
    {
        return $this->query('SHOW SCROLL');
    }

    /**
     * Runs query: SHOW DATABASES
     *
     * @return SphinxQL
     */
    public function showDatabases(): SphinxQL
    {
        return $this->query('SHOW DATABASES');
    }

    /**
     * Runs query: SHOW CHARACTER SET
     *
     * @return SphinxQL
     */
    public function showCharacterSet(): SphinxQL
    {
        return $this->query('SHOW CHARACTER SET');
    }

    /**
     * Runs query: SHOW COLLATION
     *
     * @return SphinxQL
     */
    public function showCollation(): SphinxQL
    {
        return $this->query('SHOW COLLATION');
    }

    /**
     * Runs query: SHOW TABLES
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     * @throws Exception\ConnectionException
     * @throws Exception\DatabaseException
     */
    public function showTables($index = null): SphinxQL
    {
        if ($index === null) {
            return $this->query('SHOW TABLES');
        }

        if (!is_string($index)) {
            throw new SphinxQLException('showTables() index must be null or a string.');
        }

        if (trim($index) === '') {
            return $this->query('SHOW TABLES');
        }

        return $this->query('SHOW TABLES LIKE '.$this->connection->quote($index));
    }

    /**
     * Runs query: SHOW VARIABLES
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function showVariables(): SphinxQL
    {
        return $this->query('SHOW VARIABLES');
    }

    /**
     * Returns detected runtime capabilities.
     *
     * @return Capabilities
     */
    public function getCapabilities(): Capabilities
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }

        $version = $this->detectVersionString();
        $engine = $this->detectEngine($version);
        $buddy = ($engine === 'MANTICORE' && $this->supportsCommand('SHOW VERSION'));

        $features = array(
            // Builder-level features are available in this library regardless of backend.
            'grouped_where' => true,
            'grouped_having' => true,
            'joins' => true,
            'order_by_knn_builder' => true,

            // Engine/runtime-facing features.
            'manticore' => ($engine === 'MANTICORE'),
            'sphinx2' => ($engine === 'SPHINX2'),
            'sphinx3' => ($engine === 'SPHINX3'),
            'buddy' => $buddy,
            'call_qsuggest' => $buddy,
            'call_autocomplete' => $buddy,
        );

        $this->feature_support_cache = $features;
        $this->capabilities = new Capabilities($engine, $version, $features);

        return $this->capabilities;
    }

    /**
     * Checks whether a named feature is supported.
     *
     * @param string $feature
     *
     * @return bool
     * @throws SphinxQLException
     */
    public function supports($feature): bool
    {
        if (!is_string($feature) || trim($feature) === '') {
            throw new SphinxQLException('supports() feature must be a non-empty string.');
        }

        $normalized = $this->normalizeFeatureName($feature);
        $known = array(
            'grouped_where',
            'grouped_having',
            'joins',
            'order_by_knn_builder',
            'manticore',
            'sphinx2',
            'sphinx3',
            'buddy',
            'show_profile',
            'show_plan',
            'show_threads',
            'show_plugins',
            'show_queries',
            'show_character_set',
            'show_collation',
            'show_table_settings',
            'show_table_indexes',
            'call_suggest',
            'call_qsuggest',
            'call_autocomplete',
        );

        if (!in_array($normalized, $known, true)) {
            throw new SphinxQLException('Unknown feature "'.$feature.'".');
        }

        if (!array_key_exists($normalized, $this->feature_support_cache)) {
            $this->getCapabilities();
        }

        if (!array_key_exists($normalized, $this->feature_support_cache)) {
            $probes = array(
                'show_profile' => 'SHOW PROFILE',
                'show_plan' => 'SHOW PLAN',
                'show_threads' => 'SHOW THREADS',
                'show_plugins' => 'SHOW PLUGINS',
                'show_queries' => 'SHOW QUERIES',
                'show_character_set' => 'SHOW CHARACTER SET',
                'show_collation' => 'SHOW COLLATION',
            );

            if (array_key_exists($normalized, $probes)) {
                $this->feature_support_cache[$normalized] = $this->supportsCommand($probes[$normalized]);
            } elseif ($normalized === 'show_table_settings') {
                $this->feature_support_cache[$normalized] = $this->supportsTableProbe('SHOW TABLE %s SETTINGS');
            } elseif ($normalized === 'show_table_indexes') {
                $this->feature_support_cache[$normalized] = $this->supportsTableProbe('SHOW TABLE %s INDEXES');
            } elseif ($normalized === 'call_suggest') {
                $this->feature_support_cache[$normalized] = $this->supportsTableProbe("CALL SUGGEST('teh', '%s')");
            } else {
                $this->feature_support_cache[$normalized] = false;
            }

            $this->capabilities = new Capabilities(
                $this->capabilities->getEngine(),
                $this->capabilities->getVersion(),
                $this->feature_support_cache
            );
        }

        return !empty($this->feature_support_cache[$normalized]);
    }

    /**
     * Throws when a named feature is not supported.
     *
     * @param string $feature
     * @param string $context
     *
     * @return self
     * @throws UnsupportedFeatureException
     */
    public function requireSupport($feature, $context = ''): self
    {
        if (!$this->supports($feature)) {
            $caps = $this->getCapabilities();
            $prefix = $context !== '' ? $context.' ' : '';
            throw new UnsupportedFeatureException(
                $prefix.'requires feature "'.$feature.'" (engine='.$caps->getEngine().', version='.$caps->getVersion().').'
            );
        }

        return $this;
    }

    /**
     * Runs query: SHOW CREATE TABLE
     *
     * @param string $table
     *
     * @return SphinxQL
     */
    public function showCreateTable($table): SphinxQL
    {
        $this->assertNonEmptyString($table, 'showCreateTable() table');

        return $this->query('SHOW CREATE TABLE '.$table);
    }

    /**
     * Runs query: SHOW TABLE STATUS
     *
     * @param string|null $table
     *
     * @return SphinxQL
     */
    public function showTableStatus($table = null): SphinxQL
    {
        if ($table === null) {
            return $this->query('SHOW TABLE STATUS');
        }

        $this->assertNonEmptyString($table, 'showTableStatus() table');

        return $this->query('SHOW TABLE '.$table.' STATUS');
    }

    /**
     * Runs query: SHOW TABLE STATUS LIKE
     *
     * @param string $table
     * @param string $pattern
     *
     * @return SphinxQL
     */
    public function showTableStatusLike($table, $pattern): SphinxQL
    {
        $this->assertNonEmptyString($table, 'showTableStatusLike() table');
        $this->assertNonEmptyString($pattern, 'showTableStatusLike() pattern');

        return $this->query('SHOW TABLE '.$table.' STATUS LIKE '.$this->connection->quote($pattern));
    }

    /**
     * Runs query: SHOW TABLE SETTINGS
     *
     * @param string $table
     *
     * @return SphinxQL
     */
    public function showTableSettings($table): SphinxQL
    {
        $this->assertNonEmptyString($table, 'showTableSettings() table');

        return $this->query('SHOW TABLE '.$table.' SETTINGS');
    }

    /**
     * Runs query: SHOW TABLE SETTINGS LIKE
     *
     * @param string $table
     * @param string $pattern
     *
     * @return SphinxQL
     */
    public function showTableSettingsLike($table, $pattern): SphinxQL
    {
        $this->assertNonEmptyString($table, 'showTableSettingsLike() table');
        $this->assertNonEmptyString($pattern, 'showTableSettingsLike() pattern');

        return $this->query('SHOW TABLE '.$table.' SETTINGS LIKE '.$this->connection->quote($pattern));
    }

    /**
     * Runs query: SHOW TABLE INDEXES
     *
     * @param string $table
     *
     * @return SphinxQL
     */
    public function showTableIndexes($table): SphinxQL
    {
        $this->assertNonEmptyString($table, 'showTableIndexes() table');

        return $this->query('SHOW TABLE '.$table.' INDEXES');
    }

    /**
     * Runs query: SHOW TABLE INDEXES LIKE
     *
     * @param string $table
     * @param string $pattern
     *
     * @return SphinxQL
     */
    public function showTableIndexesLike($table, $pattern): SphinxQL
    {
        $this->assertNonEmptyString($table, 'showTableIndexesLike() table');
        $this->assertNonEmptyString($pattern, 'showTableIndexesLike() pattern');

        return $this->query('SHOW TABLE '.$table.' INDEXES LIKE '.$this->connection->quote($pattern));
    }

    /**
     * Runs query: SHOW QUERIES
     *
     * @return SphinxQL
     */
    public function showQueries(): SphinxQL
    {
        return $this->query('SHOW QUERIES');
    }

    /**
     * SET syntax
     *
     * @param string $name   The name of the variable
     * @param mixed  $value  The value of the variable
     * @param bool   $global True if the variable should be global, false otherwise
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     * @throws Exception\ConnectionException
     * @throws Exception\DatabaseException
     */
    public function setVariable($name, $value, $global = false): SphinxQL
    {
        if (!is_bool($global)) {
            throw new SphinxQLException('setVariable() global flag must be boolean.');
        }
        $this->assertNonEmptyString($name, 'setVariable() name');

        $query = 'SET ';

        if ($global) {
            $query .= 'GLOBAL ';
        }

        $user_var = strpos($name, '@') === 0;
        if ($user_var) {
            if (!preg_match('/^@[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw new SphinxQLException('setVariable() user variable name is invalid.');
            }
        } elseif (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new SphinxQLException('setVariable() variable name is invalid.');
        }

        $query .= $name.' ';

        // user variables must always be processed as arrays
        if ($user_var && !is_array($value)) {
            $query .= '= ('.$this->connection->quote($value).')';
        } elseif (is_array($value)) {
            if (count($value) === 0) {
                throw new SphinxQLException('setVariable() array value cannot be empty.');
            }
            $query .= '= ('.implode(', ', $this->connection->quoteArr($value)).')';
        } else {
            $query .= '= '.$this->connection->quote($value);
        }

        return $this->query($query);
    }

    /**
     * CALL SNIPPETS syntax
     *
     * @param string|array $data    The document text (or documents) to search
     * @param string       $index
     * @param string       $query   Search query used for highlighting
     * @param array        $options Associative array of additional options
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     * @throws Exception\ConnectionException
     * @throws Exception\DatabaseException
     */
    public function callSnippets($data, $index, $query, $options = array()): SphinxQL
    {
        if (!is_array($data) && !is_string($data)) {
            throw new SphinxQLException('callSnippets() data must be a string or array of strings.');
        }
        if (is_string($data) && trim($data) === '') {
            throw new SphinxQLException('callSnippets() data string cannot be empty.');
        }
        if (is_array($data)) {
            if (count($data) === 0) {
                throw new SphinxQLException('callSnippets() data array cannot be empty.');
            }
            foreach ($data as $item) {
                if (!is_string($item)) {
                    throw new SphinxQLException('callSnippets() data array must contain strings only.');
                }
            }
        }
        $this->assertNonEmptyString($index, 'callSnippets() index');
        $this->assertNonEmptyString($query, 'callSnippets() query');
        if (!is_array($options)) {
            throw new SphinxQLException('callSnippets() options must be an associative array.');
        }

        $documents = array();
        if (is_array($data)) {
            $documents[] = '('.implode(', ', $this->connection->quoteArr($data)).')';
        } else {
            $documents[] = $this->connection->quote($data);
        }

        array_unshift($options, $index, $query);

        $arr = $this->connection->quoteArr($options);
        foreach ($arr as $key => &$val) {
            if (is_string($key)) {
                $val .= ' AS '.$key;
            }
        }

        return $this->query('CALL SNIPPETS('.implode(', ', array_merge($documents, $arr)).')');
    }

    /**
     * CALL KEYWORDS syntax
     *
     * @param string      $text
     * @param string      $index
     * @param null|string $hits
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     * @throws Exception\ConnectionException
     * @throws Exception\DatabaseException
     */
    public function callKeywords($text, $index, $hits = null): SphinxQL
    {
        $this->assertNonEmptyString($text, 'callKeywords() text');
        $this->assertNonEmptyString($index, 'callKeywords() index');
        if ($hits !== null && !in_array($hits, array(0, 1, '0', '1'), true)) {
            throw new SphinxQLException('callKeywords() hits must be 0, 1, or null.');
        }

        $arr = array($text, $index);
        if ($hits !== null) {
            $arr[] = $hits;
        }

        return $this->query('CALL KEYWORDS('.implode(', ', $this->connection->quoteArr($arr)).')');
    }

    /**
     * CALL QSUGGEST syntax (Manticore Buddy)
     *
     * @param string $text
     * @param string $index
     * @param array  $options
     *
     * @return SphinxQL
     */
    public function callQSuggest($text, $index, array $options = array()): SphinxQL
    {
        $this->requireSupport('call_qsuggest', 'callQSuggest()');
        $this->assertNonEmptyString($text, 'callQSuggest() text');
        $this->assertNonEmptyString($index, 'callQSuggest() index');

        return $this->query($this->buildCallWithOptions(
            'QSUGGEST',
            array($text, $index),
            $this->normalizeCallOptions('callQSuggest()', 'QSUGGEST', $options)
        ));
    }

    /**
     * CALL SUGGEST syntax
     *
     * @param string $text
     * @param string $index
     * @param array  $options
     *
     * @return SphinxQL
     */
    public function callSuggest($text, $index, array $options = array()): SphinxQL
    {
        $this->assertNonEmptyString($text, 'callSuggest() text');
        $this->assertNonEmptyString($index, 'callSuggest() index');

        return $this->query($this->buildCallWithOptions(
            'SUGGEST',
            array($text, $index),
            $this->normalizeCallOptions('callSuggest()', 'SUGGEST', $options)
        ));
    }

    /**
     * CALL AUTOCOMPLETE syntax (Manticore Buddy)
     *
     * @param string $text
     * @param string $index
     * @param array  $options
     *
     * @return SphinxQL
     */
    public function callAutocomplete($text, $index, array $options = array()): SphinxQL
    {
        $this->requireSupport('call_autocomplete', 'callAutocomplete()');
        $this->assertNonEmptyString($text, 'callAutocomplete() text');
        $this->assertNonEmptyString($index, 'callAutocomplete() index');

        return $this->query($this->buildCallWithOptions(
            'AUTOCOMPLETE',
            array($text, $index),
            $this->normalizeCallOptions('callAutocomplete()', 'AUTOCOMPLETE', $options)
        ));
    }

    /**
     * DESCRIBE syntax
     *
     * @param string $index The name of the index
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function describe($index): SphinxQL
    {
        $this->assertNonEmptyString($index, 'describe() index');

        return $this->query('DESCRIBE '.$index);
    }

    /**
     * CREATE FUNCTION syntax
     *
     * @param string $udf_name
     * @param string $returns  Whether INT|BIGINT|FLOAT|STRING
     * @param string $so_name
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     * @throws Exception\ConnectionException
     * @throws Exception\DatabaseException
     */
    public function createFunction($udf_name, $returns, $so_name): SphinxQL
    {
        $this->assertNonEmptyString($udf_name, 'createFunction() udf_name');
        $this->assertNonEmptyString($returns, 'createFunction() returns');
        $this->assertNonEmptyString($so_name, 'createFunction() so_name');

        $normalizedReturn = strtoupper(trim($returns));
        if (!in_array($normalizedReturn, array('INT', 'UINT', 'BIGINT', 'FLOAT', 'STRING'), true)) {
            throw new SphinxQLException('createFunction() returns must be one of: INT, UINT, BIGINT, FLOAT, STRING.');
        }

        return $this->query('CREATE FUNCTION '.$udf_name.
            ' RETURNS '.$normalizedReturn.' SONAME '.$this->connection->quote($so_name));
    }

    /**
     * DROP FUNCTION syntax
     *
     * @param string $udf_name
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function dropFunction($udf_name): SphinxQL
    {
        $this->assertNonEmptyString($udf_name, 'dropFunction() udf_name');

        return $this->query('DROP FUNCTION '.$udf_name);
    }

    /**
     * ATTACH INDEX * TO RTINDEX * syntax
     *
     * @param string $disk_index
     * @param string $rt_index
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function attachIndex($disk_index, $rt_index): SphinxQL
    {
        $this->assertNonEmptyString($disk_index, 'attachIndex() disk_index');
        $this->assertNonEmptyString($rt_index, 'attachIndex() rt_index');

        return $this->query('ATTACH INDEX '.$disk_index.' TO RTINDEX '.$rt_index);
    }

    /**
     * FLUSH RTINDEX syntax
     *
     * @param string $index
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function flushRtIndex($index): SphinxQL
    {
        $this->assertNonEmptyString($index, 'flushRtIndex() index');

        return $this->query('FLUSH RTINDEX '.$index);
    }

    /**
     * TRUNCATE RTINDEX syntax
     *
     * @param string $index
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function truncateRtIndex($index): SphinxQL
    {
        $this->assertNonEmptyString($index, 'truncateRtIndex() index');

        return $this->query('TRUNCATE RTINDEX '.$index);
    }

    /**
     * OPTIMIZE INDEX syntax
     *
     * @param string $index
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function optimizeIndex($index): SphinxQL
    {
        $this->assertNonEmptyString($index, 'optimizeIndex() index');

        return $this->query('OPTIMIZE INDEX '.$index);
    }

    /**
     * SHOW INDEX STATUS syntax
     *
     * @param $index
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function showIndexStatus($index): SphinxQL
    {
        $this->assertNonEmptyString($index, 'showIndexStatus() index');

        return $this->query('SHOW INDEX '.$index.' STATUS');
    }

    /**
     * SHOW INDEX STATUS LIKE syntax
     *
     * @param string $index
     * @param string $pattern
     *
     * @return SphinxQL
     */
    public function showIndexStatusLike($index, $pattern): SphinxQL
    {
        $this->assertNonEmptyString($index, 'showIndexStatusLike() index');
        $this->assertNonEmptyString($pattern, 'showIndexStatusLike() pattern');

        return $this->query('SHOW INDEX '.$index.' STATUS LIKE '.$this->connection->quote($pattern));
    }

    /**
     * FLUSH RAMCHUNK syntax
     *
     * @param $index
     *
     * @return SphinxQL A SphinxQL object ready to be ->execute();
     */
    public function flushRamchunk($index): SphinxQL
    {
        $this->assertNonEmptyString($index, 'flushRamchunk() index');

        return $this->query('FLUSH RAMCHUNK '.$index);
    }

    /**
     * FLUSH ATTRIBUTES syntax.
     *
     * @return SphinxQL
     */
    public function flushAttributes(): SphinxQL
    {
        return $this->query('FLUSH ATTRIBUTES');
    }

    /**
     * FLUSH HOSTNAMES syntax.
     *
     * @return SphinxQL
     */
    public function flushHostnames(): SphinxQL
    {
        return $this->query('FLUSH HOSTNAMES');
    }

    /**
     * FLUSH LOGS syntax.
     *
     * @return SphinxQL
     */
    public function flushLogs(): SphinxQL
    {
        return $this->query('FLUSH LOGS');
    }

    /**
     * RELOAD PLUGINS syntax.
     *
     * @return SphinxQL
     */
    public function reloadPlugins(): SphinxQL
    {
        return $this->query('RELOAD PLUGINS');
    }

    /**
     * KILL syntax.
     *
     * @param int|string $queryId
     *
     * @return SphinxQL
     */
    public function kill($queryId): SphinxQL
    {
        if (filter_var($queryId, FILTER_VALIDATE_INT) === false || (int) $queryId <= 0) {
            throw new SphinxQLException('kill() queryId must be a positive integer.');
        }

        return $this->query('KILL '.((int) $queryId));
    }

    /**
     * @param mixed  $value
     * @param string $field
     *
     * @throws SphinxQLException
     */
    private function assertNonEmptyString($value, $field): void
    {
        if (!is_string($value) || trim($value) === '') {
            throw new SphinxQLException($field.' must be a non-empty string.');
        }
    }

    /**
     * @param string $methodName
     * @param string $callName
     * @param array  $options
     *
     * @return array
     */
    private function normalizeCallOptions($methodName, $callName, array $options): array
    {
        $schema = $this->getCallOptionSchema($callName);

        if ($callName === 'AUTOCOMPLETE'
            && array_key_exists('fuzzy', $options)
            && array_key_exists('fuzziness', $options)
        ) {
            throw new SphinxQLException($methodName.' options "fuzzy" and "fuzziness" cannot be used together.');
        }

        $normalized = array();
        foreach ($options as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                throw new SphinxQLException($methodName.' options must have non-empty string keys.');
            }

            if (!array_key_exists($key, $schema)) {
                throw new SphinxQLException(
                    $methodName.' unknown option "'.$key.'". Allowed options: '.implode(', ', array_keys($schema)).'.'
                );
            }

            $rule = $schema[$key];
            if ($rule['type'] === 'bool') {
                $normalized[$key] = $this->normalizeBooleanOption($methodName, $key, $value);
                continue;
            }

            if ($rule['type'] === 'int') {
                $normalized[$key] = $this->normalizeIntegerOption(
                    $methodName,
                    $key,
                    $value,
                    $rule['min'] ?? null,
                    $rule['max'] ?? null
                );
                continue;
            }

            if ($rule['type'] === 'string') {
                $normalized[$key] = $this->normalizeStringOption(
                    $methodName,
                    $key,
                    $value,
                    $rule['allow_empty'] ?? false
                );
                continue;
            }

            if ($rule['type'] === 'enum_string') {
                $normalized[$key] = $this->normalizeEnumStringOption(
                    $methodName,
                    $key,
                    $value,
                    $rule['allowed']
                );
                continue;
            }
        }

        return $normalized;
    }

    /**
     * @param string $callName
     *
     * @return array<string,array<string,mixed>>
     */
    private function getCallOptionSchema($callName): array
    {
        if ($callName === 'SUGGEST' || $callName === 'QSUGGEST') {
            return array(
                'limit' => array('type' => 'int', 'min' => 0),
                'max_edits' => array('type' => 'int', 'min' => 0),
                'result_stats' => array('type' => 'bool'),
                'delta_len' => array('type' => 'int', 'min' => 0),
                'max_matches' => array('type' => 'int', 'min' => 0),
                'reject' => array('type' => 'bool'),
                'result_line' => array('type' => 'bool'),
                'non_char' => array('type' => 'bool'),
                'sentence' => array('type' => 'bool'),
                'force_bigrams' => array('type' => 'bool'),
                'search_mode' => array('type' => 'enum_string', 'allowed' => array('phrase', 'words')),
            );
        }

        if ($callName === 'AUTOCOMPLETE') {
            return array(
                'layouts' => array('type' => 'string', 'allow_empty' => true),
                'fuzzy' => array('type' => 'int', 'min' => 0, 'max' => 2),
                'fuzziness' => array('type' => 'int', 'min' => 0, 'max' => 2),
                'prepend' => array('type' => 'bool'),
                'append' => array('type' => 'bool'),
                'preserve' => array('type' => 'bool'),
                'expansion_len' => array('type' => 'int', 'min' => 0),
                'force_bigrams' => array('type' => 'bool'),
            );
        }

        throw new SphinxQLException('Unknown CALL option schema for "'.$callName.'".');
    }

    /**
     * @param string $methodName
     * @param string $option
     * @param mixed  $value
     *
     * @return int
     */
    private function normalizeBooleanOption($methodName, $option, $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (in_array($value, array(0, 1, '0', '1'), true)) {
            return (int) $value;
        }

        throw new SphinxQLException(
            $methodName.' option "'.$option.'" must be boolean (true/false) or 0/1.'
        );
    }

    /**
     * @param string   $methodName
     * @param string   $option
     * @param mixed    $value
     * @param int|null $min
     * @param int|null $max
     *
     * @return int
     */
    private function normalizeIntegerOption($methodName, $option, $value, $min = null, $max = null): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT);
        if ($normalized === false) {
            throw new SphinxQLException($methodName.' option "'.$option.'" must be an integer.');
        }

        $normalized = (int) $normalized;
        if ($min !== null && $normalized < $min) {
            throw new SphinxQLException($methodName.' option "'.$option.'" must be >= '.$min.'.');
        }
        if ($max !== null && $normalized > $max) {
            throw new SphinxQLException($methodName.' option "'.$option.'" must be <= '.$max.'.');
        }

        return $normalized;
    }

    /**
     * @param string $methodName
     * @param string $option
     * @param mixed  $value
     * @param bool   $allowEmpty
     *
     * @return string
     */
    private function normalizeStringOption($methodName, $option, $value, $allowEmpty = false): string
    {
        if (!is_string($value)) {
            throw new SphinxQLException($methodName.' option "'.$option.'" must be a string.');
        }

        if (!$allowEmpty && trim($value) === '') {
            throw new SphinxQLException($methodName.' option "'.$option.'" cannot be empty.');
        }

        return $value;
    }

    /**
     * @param string $methodName
     * @param string $option
     * @param mixed  $value
     * @param array  $allowed
     *
     * @return string
     */
    private function normalizeEnumStringOption($methodName, $option, $value, array $allowed): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new SphinxQLException($methodName.' option "'.$option.'" must be a non-empty string.');
        }

        $normalized = strtolower(trim($value));
        if (!in_array($normalized, $allowed, true)) {
            throw new SphinxQLException(
                $methodName.' option "'.$option.'" must be one of: '.implode(', ', $allowed).'.'
            );
        }

        return $normalized;
    }

    /**
     * @param string $callName
     * @param array  $requiredArgs
     * @param array  $options
     *
     * @return string
     * @throws SphinxQLException
     */
    private function buildCallWithOptions($callName, array $requiredArgs, array $options = array()): string
    {
        if (!is_array($options)) {
            throw new SphinxQLException($callName.' options must be an associative array.');
        }

        $quoted = $this->connection->quoteArr(array_values($requiredArgs));
        $optionValues = $this->connection->quoteArr($options);
        foreach ($optionValues as $key => &$value) {
            if (!is_string($key) || trim($key) === '') {
                throw new SphinxQLException($callName.' options must have non-empty string keys.');
            }
            $value .= ' AS '.$key;
        }

        $args = implode(', ', array_merge($quoted, $optionValues));

        return 'CALL '.$callName.'('.$args.')';
    }

    /**
     * @return string
     */
    private function detectVersionString(): string
    {
        try {
            $rows = $this->connection->query('SELECT VERSION()')->getStored();
            $firstRow = isset($rows[0]) ? $rows[0] : array();

            return (string) reset($firstRow);
        } catch (\Exception $exception) {
            return 'unknown';
        }
    }

    /**
     * @param string $version
     *
     * @return string
     */
    private function detectEngine($version): string
    {
        $versionLower = strtolower((string) $version);

        if (strpos($versionLower, 'manticore') !== false) {
            return 'MANTICORE';
        }
        if (preg_match('/^3\./', (string) $version) || strpos($versionLower, 'sphinx 3') !== false) {
            return 'SPHINX3';
        }
        if (preg_match('/^2\./', (string) $version) || strpos($versionLower, 'sphinx') !== false) {
            return 'SPHINX2';
        }

        return 'UNKNOWN';
    }

    /**
     * @param string $feature
     *
     * @return string
     */
    private function normalizeFeatureName($feature): string
    {
        $normalized = strtolower(trim($feature));
        $normalized = str_replace(array('-', ' '), '_', $normalized);

        return $normalized;
    }

    /**
     * @param string $sqlProbe
     *
     * @return bool
     */
    private function supportsCommand($sqlProbe): bool
    {
        try {
            $this->connection->query($sqlProbe)->getStored();

            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    /**
     * @param string $sqlProbeTemplate Template with a single `%s` table placeholder.
     *
     * @return bool
     */
    private function supportsTableProbe($sqlProbeTemplate): bool
    {
        foreach ($this->getProbeTables() as $table) {
            if ($this->supportsCommand(sprintf($sqlProbeTemplate, $table))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function getProbeTables(): array
    {
        if ($this->probe_tables_cache !== null) {
            return $this->probe_tables_cache;
        }

        $tables = array('rt');

        try {
            $rows = $this->connection->query('SHOW TABLES')->getStored();
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $table = $this->extractProbeTableName($row);
                    if ($table !== null) {
                        $tables[] = $table;
                    }
                }
            }
        } catch (\Exception $exception) {
            // Fall back to the default probe table.
        }

        $this->probe_tables_cache = array_values(array_unique($tables));

        return $this->probe_tables_cache;
    }

    /**
     * @param mixed $row
     *
     * @return null|string
     */
    private function extractProbeTableName($row): ?string
    {
        if (!is_array($row) || empty($row)) {
            return null;
        }

        if (array_key_exists('Index', $row) && is_string($row['Index']) && trim($row['Index']) !== '') {
            return $row['Index'];
        }

        foreach ($row as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
