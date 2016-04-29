<?php
namespace Schema\Shell\Task;

use Cake\Console\Shell;
use Cake\Database\Driver\Sqlserver;
use Cake\Database\Schema\Table;
use Cake\Datasource\ConnectionManager;
use Cake\Filesystem\File;
use Exception;

class SeedTask extends Shell
{
    /**
     * Default configuration.
     *
     * @var array
     */
    private $_config = [
        'connection' => 'test',
        'seed' => 'config/seed.php',
        'truncate' => true,
    ];

    /**
     * Insert data from seed.php file into database.
     *
     * @param array $options Set connection name and path to the seed.php file.
     * @return void
     */
    public function seed($options = [])
    {
        $this->_config = array_merge($this->_config, $this->params, $options);

        $data = $this->_readSeed($this->_config['seed']);
        $db = $this->_connection();

        $this->_truncate($db, $data);
        $this->_insert($db, $data);
    }

    /**
     * Truncate the tables if requested. Because of postgres it must run in separate transaction.
     *
     * @param \Cake\Database\Connection $db Connection to run the SQL queries on.
     * @param  array $data List tables and rows.
     * @return void
     */
    protected function _truncate($db, $data = null)
    {
        if ($this->_config['truncate']) {
            $this->_io->out('Truncating ', 0);

            $operation = function ($db) use ($data) {
                foreach ($data as $table => $rows) {
                    $this->_io->out('.', 0);
                    $this->_truncateTable($db, $table);
                }
            };

            $this->_runOperation($db, $operation);
            $this->_io->out(); // New line
        }
    }

    /**
     * Truncates table. Deletes all rows in the table.
     *
     * @param  \Cake\Datasource\Connection $db Connection where table is stored.
     * @param  string $table Table name.
     * @return void
     */
    protected function _truncateTable($db, $table)
    {
        $schema = $db->schemaCollection()->describe($table);
        $truncateSql = $schema->truncateSql($db);
        foreach ($truncateSql as $statement) {
            $db->execute($statement)->closeCursor();
        }
    }

    /**
     * Insert data into tables.
     *
     * @param \Cake\Database\Connection $db Connection to run the SQL queries on.
     * @param  array $data List tables and rows.
     * @return void
     */
    protected function _insert($db, $data = null)
    {
        $this->_io->out('Seeding ', 0);

        $operation = function ($db) use ($data) {
            $db->disableForeignKeys();
            foreach ($data as $table => $rows) {
                $this->_io->out('.', 0);

                $this->_beforeTableInsert($db, $table);
                $this->_insertTable($db, $table, $rows);
                $this->_afterTableInsert($db, $table);
            }
            $db->enableForeignKeys();
        };

        $this->_runOperation($db, $operation);
        $this->_io->out(); // New line
    }

    /**
     * Insert data into table.
     *
     * @param  \Cake\Datasource\Connection $db Connection where table is stored.
     * @param  string $table Table name.
     * @param  array $rows Data to be stored.
     * @return void
     */
    protected function _insertTable($db, $table, $rows)
    {
        try {
            $schema = $db->schemaCollection()->describe($table);
            list($fields, $values, $types) = $this->_getRecords($schema, $rows);
            $query = $db->newQuery()
                ->insert($fields, $types)
                ->into($table);

            foreach ($values as $row) {
                $query->values($row);
            }

            $query->execute()->closeCursor();
        } catch (Exception $e) {
            $this->_io->err($e->getMessage());
            exit(1);
        }
    }

    /**
     * Runs operation in the SQL transaction with disabled logging.
     *
     * @param  \Cake\Datasource\Connection $db Connection to run the transaction on.
     * @param  callable $operation Operation to run.
     * @return void
     */
    protected function _runOperation($db, $operation)
    {
        $logQueries = $db->logQueries();
        if ($logQueries) {
            $db->logQueries(false);
        }

        $db->transactional($operation);

        if ($logQueries) {
            $db->logQueries(true);
        }
    }

    /**
     * Converts the internal records into data used to generate a query
     * for given table schema.
     *
     * @param \Schema\Table $schema Table schema.
     * @param  array $records Internal records.
     * @return array Fields, values and types.
     */
    protected function _getRecords(Table $schema, $records)
    {
        $fields = $values = $types = [];
        $columns = $schema->columns();
        foreach ($records as $record) {
            $fields = array_merge($fields, array_intersect(array_keys($record), $columns));
        }
        $fields = array_values(array_unique($fields));
        foreach ($fields as $field) {
            $types[$field] = $schema->columnType($field);
        }
        $default = array_fill_keys($fields, null);
        foreach ($records as $record) {
            $values[] = array_merge($default, $record);
        }
        return [$fields, $values, $types];
    }

    /**
     * Prepare table for data insertion.
     *
     * @return void
     */
    protected function _beforeTableInsert($db, $table)
    {
        // TODO: Move this into the driver
        if ($db->driver() instanceof Sqlserver) {
            $table = $db->quoteIdentifier($table);
            $db->query(sprintf('SET IDENTITY_INSERT %s ON', $table))->closeCursor();
        }
    }

    /**
     * Clean after inserting.
     *
     * @return void
     */
    protected function _afterTableInsert($db, $table)
    {
        // TODO: Move this into the driver
        if ($db->driver() instanceof Sqlserver) {
            $table = $db->quoteIdentifier($table);
            $db->query(sprintf('SET IDENTITY_INSERT %s OFF', $table))->closeCursor();
        }
    }

    /**
     * Returns the database connection.
     *
     * @return \Cake\Database\Connection Object.
     * @throws  \RuntimeException If the connection does not implement schemaCollection()
     */
    protected function _connection()
    {
        $db = ConnectionManager::get($this->_config['connection'], false);
        if (!method_exists($db, 'schemaCollection')) {
            throw new \RuntimeException(
                'Cannot generate fixtures for connections that do not implement schemaCollection()'
            );
        }
        return $db;
    }

    /**
     * Returns the data array.
     *
     * @param  string $path Path to the seed.php file.
     * @return array Data array.
     */
    protected function _readSeed($path)
    {
        if (file_exists($path)) {
            $return = include $path;
            if (is_array($return)) {
                return $return;
            }
        }

        return [];
    }
}
