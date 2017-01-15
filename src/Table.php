<?php
namespace Schema;

use Cake\Database\Connection;
use Cake\Database\Schema\Table as SchemaTable;
use Cake\Datasource\ConnectionInterface;

/**
 * Custom table object for better manipulation with foreign keys.
 */
class Table extends SchemaTable
{
    /**
     * Foreign keys constraints
     *
     * @var array
     */
    protected $_foreignKeys = [];

    /**
     * Foreign keys constraints represented as SQL statements
     *
     * @var array
     */
    protected $_foreignKeysSql = [];

    /**
     * Generate the SQL to create the Table without foreign keys.
     *
     * Uses the connection to access the schema dialect
     * to generate platform specific SQL.
     *
     * @param Connection $connection The connection to generate SQL for.
     * @return array List of SQL statements to create the table and the
     *    required indexes.
     */
    public function createSql(ConnectionInterface $connection)
    {
        $this->_extractForeignKeys($connection);
        return parent::createSql($connection);
    }

    /**
     * Returns list of ALTER TABLE statements to add foreign key constraints.
     *
     * @param  Connection $connection The connection to generate SQL for.
     * @return array List of SQL statements to create the foreign keys.
     */
    public function foreignKeysSql(Connection $connection)
    {
        $constraints = [];
        foreach ($this->_foreignKeysSql as $statement) {
            // TODO: Move this to the driver. SQLite is not supported.
            $constraints[] = sprintf(
                'ALTER TABLE %s ADD %s',
                $connection->quoteIdentifier($this->name()),
                $statement
            );
        }
        return $constraints;
    }

    /**
     * Refresh the protected foreign keys variable.
     * All foreign keys are removed from the original constraints.
     *
     * @return void
     */
    protected function _extractForeignKeys(Connection $connection)
    {
        $dialect = $connection->driver()->schemaDialect();

        foreach ($this->_constraints as $name => $attrs) {
            if ($attrs['type'] === static::CONSTRAINT_FOREIGN) {
                $this->_foreignKeys[$name] = $attrs;
                $this->_foreignKeysSql[$name] = $dialect->constraintSql($this, $name);
                unset($this->_constraints[$name]);
            }
        }
    }
}
