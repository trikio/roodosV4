<?php

namespace Foolz\SphinxQL\Drivers\Mysqli;

use Foolz\SphinxQL\Drivers\ConnectionBase;
use Foolz\SphinxQL\Drivers\MultiResultSet;
use Foolz\SphinxQL\Drivers\ResultSet;
use Foolz\SphinxQL\Exception\ConnectionException;
use Foolz\SphinxQL\Exception\DatabaseException;
use Foolz\SphinxQL\Exception\SphinxQLException;
use mysqli_sql_exception;

/**
 * SphinxQL connection class utilizing the MySQLi extension.
 * It also contains escaping and quoting functions.
 */
class Connection extends ConnectionBase
{
    /**
     * Internal Encoding
     *
     * @var string
     */
    protected ?string $internal_encoding = null;

    /**
     * Returns the internal encoding.
     *
     * @return string current multibyte internal encoding
     */
    public function getInternalEncoding(): ?string
    {
        return $this->internal_encoding;
    }

    /**
     * @inheritdoc
     */
    public function connect(): bool
    {
        $data = $this->getParams();
        $conn = mysqli_init();
        $username = array_key_exists('username', $data) ? $data['username'] : null;
        $password = array_key_exists('password', $data) ? $data['password'] : null;

        if (!empty($data['options'])) {
            foreach ($data['options'] as $option => $value) {
                $conn->options($option, $value);
            }
        }

        set_error_handler(function () {});
        try {
            if (!$conn->real_connect($data['host'], $username, $password, null, (int) $data['port'], $data['socket'])) {
                throw new ConnectionException(
                    '[mysqli][connect]['.$conn->connect_errno.'] '.$conn->connect_error
                    .' [host='.(string) $data['host'].', port='.(int) $data['port'].']'
                );
            }
        } catch (\TypeError $exception) {
            throw new ConnectionException(
                '[mysqli][connect][0] '.$exception->getMessage()
                .' [host='.(string) $data['host'].', port='.(int) $data['port'].']',
                0,
                $exception
            );
        } catch (mysqli_sql_exception $exception) {
            throw new ConnectionException(
                '[mysqli][connect]['.$exception->getCode().'] '.$exception->getMessage()
                .' [host='.(string) $data['host'].', port='.(int) $data['port'].']',
                (int) $exception->getCode(),
                $exception
            );
        } finally {
            restore_error_handler();
        }

        $conn->set_charset('utf8');
        $this->connection = $conn;
        $this->mbPush();

        return true;
    }

    /**
     * Pings the Sphinx server.
     *
     * @return bool True if connected, false otherwise
     * @throws ConnectionException
     */
    public function ping(): bool
    {
        $this->ensureConnection();

        return $this->getConnection()->ping();
    }

    /**
     * @inheritdoc
     */
    public function close(): self
    {
        $this->mbPop();
        $this->getConnection()->close();

        return parent::close();
    }

    /**
     * @inheritdoc
     */
    public function query(string $query): ResultSet
    {
        $this->ensureConnection();

        set_error_handler(function () {});
        try {
            /**
             * ManticoreSearch/Sphinx silence warnings thrown by php mysqli/mysqlnd
             *
             * unknown command (code=9) - status() command not implemented by Sphinx/ManticoreSearch
             * ERROR mysqli::prepare(): (08S01/1047): unknown command (code=22) - prepare() not implemented by Sphinx/Manticore
             */
            $resource = @$this->getConnection()->query($query);
        } catch (mysqli_sql_exception $exception) {
            throw new DatabaseException(
                '[mysqli][query]['.$exception->getCode().'] '.$exception->getMessage().' [ '.$query.' ]',
                (int) $exception->getCode(),
                $exception
            );
        } finally {
            restore_error_handler();
        }

        if ($this->getConnection()->error) {
            throw new DatabaseException('[mysqli][query]['.$this->getConnection()->errno.'] '.
                $this->getConnection()->error.' [ '.$query.' ]');
        }

        return new ResultSet(new ResultSetAdapter($this, $resource));
    }

    /**
     * @inheritdoc
     */
    public function multiQuery(array $queue): MultiResultSet
    {
        $count = count($queue);

        if ($count === 0) {
            throw new SphinxQLException('The Queue is empty.');
        }

        $this->ensureConnection();

        try {
            $this->getConnection()->multi_query(implode(';', $queue));
        } catch (mysqli_sql_exception $exception) {
            throw new DatabaseException(
                '[mysqli][multi_query]['.$exception->getCode().'] '.$exception->getMessage().' [ '.implode(';', $queue).' ]',
                (int) $exception->getCode(),
                $exception
            );
        }

        if ($this->getConnection()->error) {
            throw new DatabaseException('[mysqli][multi_query]['.$this->getConnection()->errno.'] '.
                $this->getConnection()->error.' [ '.implode(';', $queue).' ]');
        }

        return new MultiResultSet(new MultiResultSetAdapter($this));
    }

    /**
     * Escapes the input with \MySQLi::real_escape_string.
     * Based on FuelPHP's escaping function.
     * @inheritdoc
     */
    public function escape(string $value): string
    {
        $this->ensureConnection();

        try {
            $value = $this->getConnection()->real_escape_string((string) $value);
        } catch (mysqli_sql_exception $exception) {
            throw new DatabaseException(
                '[mysqli][escape]['.$exception->getCode().'] '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception
            );
        }

        if ($value === false) {
            // @codeCoverageIgnoreStart
            throw new DatabaseException(
                '[mysqli][escape]['.$this->getConnection()->errno.'] '.$this->getConnection()->error,
                $this->getConnection()->errno
            );
            // @codeCoverageIgnoreEnd
        }

        return "'".$value."'";
    }

    /**
     * Enter UTF-8 multi-byte workaround mode.
     */
    public function mbPush(): self
    {
        $this->internal_encoding = mb_internal_encoding();
        mb_internal_encoding('UTF-8');

        return $this;
    }

    /**
     * Exit UTF-8 multi-byte workaround mode.
     */
    public function mbPop(): self
    {
        // TODO: add test case for #155
        if ($this->getInternalEncoding()) {
            mb_internal_encoding($this->getInternalEncoding());
            $this->internal_encoding = null;
        }

        return $this;
    }
}
