<?php
/**
*
* @package dbal
* @version $Id$
* @copyright (c) 2007 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* Database Tools for handling cross-db actions such as altering columns, etc.
*
* @package dbal
*/
class phpbb_db_tools
{
	/**
	* @var object DB object
	*/
	public $db = NULL;

	/**
	* A list of types being unsigned for better reference in some db's
	* @var array
	*/
	public $unsigned_types = array('UINT', 'UINT:', 'USINT', 'BOOL', 'TIMESTAMP');

	/**
	* A list of supported DBMS. We change this class to support more DBMS, the DBMS itself only need to follow some rules.
	* @var array
	*/
	public $supported_dbms = array('mysql', 'mssql', 'sqlite', 'oracle', 'firebird', 'db2', 'postgres');

	/**
	* This is set to true if user only wants to return the 'to-be-executed' SQL statement(s) (as an array).
	* This mode has no effect on some methods (inserting of data for example). This is expressed within the methods command.
	*/
	public $return_statements = false;

	/**
	* Constructor. Set DB Object and set {@link $return_statements return_statements}.
	*
	* @param phpbb_dbal	$db					DBAL object
	* @param bool		$return_statements	True if only statements should be returned and no SQL being executed
	*/
	public function __construct(phpbb_dbal $db, $return_statements = false)
	{
		$this->db = $db;
		$this->return_statements = $return_statements;

		if (!in_array($this->db->dbms_type, $this->supported_dbms))
		{
			trigger_error('DBMS Type ' . $this->db->dbms_type . ' not supported by DB Tools.', E_USER_ERROR);
		}
	}

	/**
	* Create SQL Table
	*
	* @param string	$table_name	The table name to create
	* @param array	$table_data	Array containing table data. For a sample layout see {@example }
	* @return array	Statements if $return_statements is true.
	*/
	public function sql_create_table($table_name, $table_data)
	{
		// holds the DDL for a column
		$columns = $statements = array();

		// Begin transaction
		$statements[] = 'begin';

		// Determine if we have created a PRIMARY KEY in the earliest
		$primary_key_gen = false;

		// Determine if the table must be created with TEXTIMAGE
		$create_textimage = false;

		// Determine if the table requires a sequence
		$create_sequence = false;

		// Begin table sql statement
		switch ($this->sql_layer)
		{
			case 'mssql':
				$table_sql = 'CREATE TABLE [' . $table_name . '] (' . "\n";
			break;

			default:
				$table_sql = 'CREATE TABLE ' . $table_name . ' (' . "\n";
			break;
		}

		// Iterate through the columns to create a table
		foreach ($table_data['COLUMNS'] as $column_name => $column_data)
		{
			// here lies an array, filled with information compiled on the column's data
			$prepared_column = $this->sql_prepare_column_data($table_name, $column_name, $column_data);

			// here we add the definition of the new column to the list of columns
			switch ($this->sql_layer)
			{
				case 'mssql':
					$columns[] = "\t [{$column_name}] " . $prepared_column['column_type_sql_default'];
				break;

				default:
					$columns[] = "\t {$column_name} " . $prepared_column['column_type_sql'];
				break;
			}

			// see if we have found a primary key set due to a column definition if we have found it, we can stop looking
			if (!$primary_key_gen)
			{
				$primary_key_gen = isset($prepared_column['primary_key_set']) && $prepared_column['primary_key_set'];
			}

			// create textimage DDL based off of the existance of certain column types
			if (!$create_textimage)
			{
				$create_textimage = isset($prepared_column['textimage']) && $prepared_column['textimage'];
			}

			// create sequence DDL based off of the existance of auto incrementing columns
			if (!$create_sequence && isset($prepared_column['auto_increment']) && $prepared_column['auto_increment'])
			{
				$create_sequence = $column_name;
			}
		}

		// this makes up all the columns in the create table statement
		$table_sql .= implode(",\n", $columns);

		// Close the table for two DBMS and add to the statements
		switch ($this->db->dbms_type)
		{
			case 'firebird':
				$table_sql .= "\n);";
				$statements[] = $table_sql;
			break;

			case 'mssql':
				$table_sql .= "\n) ON [PRIMARY]" . (($create_textimage) ? ' TEXTIMAGE_ON [PRIMARY]' : '');
				$statements[] = $table_sql;
			break;
		}

		// we have yet to create a primary key for this table,
		// this means that we can add the one we really wanted instead
		if (!$primary_key_gen)
		{
			// Write primary key
			if (isset($table_data['PRIMARY_KEY']))
			{
				if (!is_array($table_data['PRIMARY_KEY']))
				{
					$table_data['PRIMARY_KEY'] = array($table_data['PRIMARY_KEY']);
				}

				switch ($this->db->dbms_type)
				{
					case 'mysql':
					case 'postgres':
					case 'db2':
					case 'sqlite':
						$table_sql .= ",\n\t PRIMARY KEY (" . implode(', ', $table_data['PRIMARY_KEY']) . ')';
					break;

					case 'firebird':
					case 'mssql':
						$primary_key_stmts = $this->sql_create_primary_key($table_name, $table_data['PRIMARY_KEY']);
						foreach ($primary_key_stmts as $pk_stmt)
						{
							$statements[] = $pk_stmt;
						}
					break;

					case 'oracle':
						$table_sql .= ",\n\t CONSTRAINT pk_{$table_name} PRIMARY KEY (" . implode(', ', $table_data['PRIMARY_KEY']) . ')';
					break;
				}
			}
		}

		// close the table
		switch ($this->db->dbms_type)
		{
			case 'mysql':
				// make sure the table is in UTF-8 mode
				$table_sql .= "\n) CHARACTER SET `utf8` COLLATE `utf8_bin`;";
				$statements[] = $table_sql;
			break;

			case 'postgres':
				// do we need to add a sequence for auto incrementing columns?
				if ($create_sequence)
				{
					$statements[] = "CREATE SEQUENCE {$table_name}_seq;";
				}

				$table_sql .= "\n);";
				$statements[] = $table_sql;
			break;

			case 'db2':
			case 'sqlite':
				$table_sql .= "\n);";
				$statements[] = $table_sql;
			break;

			case 'oracle':
				$table_sql .= "\n);";
				$statements[] = $table_sql;

				// do we need to add a sequence and a tigger for auto incrementing columns?
				if ($create_sequence)
				{
					// create the actual sequence
					$statements[] = "CREATE SEQUENCE {$table_name}_seq";

					// the trigger is the mechanism by which we increment the counter
					$trigger = "CREATE OR REPLACE TRIGGER t_{$table_name}\n";
					$trigger .= "BEFORE INSERT ON {$table_name}\n";
					$trigger .= "FOR EACH ROW WHEN (\n";
					$trigger .= "\tnew.{$create_sequence} IS NULL OR new.{$create_sequence} = 0\n";
					$trigger .= ")\n";
					$trigger .= "BEGIN\n";
					$trigger .= "\tSELECT {$table_name}_seq.nextval\n";
					$trigger .= "\tINTO :new.{$create_sequence}\n";
					$trigger .= "\tFROM dual\n";
					$trigger .= "END;";

					$statements[] = $trigger;
				}
			break;

			case 'firebird':
				if ($create_sequence)
				{
					$statements[] = "CREATE SEQUENCE {$table_name}_seq;";
				}
			break;
		}

		// Write Keys
		if (isset($table_data['KEYS']))
		{
			foreach ($table_data['KEYS'] as $key_name => $key_data)
			{
				if (!is_array($key_data[1]))
				{
					$key_data[1] = array($key_data[1]);
				}

				$old_return_statements = $this->return_statements;
				$this->return_statements = true;

				$key_stmts = ($key_data[0] == 'UNIQUE') ? $this->sql_create_unique_index($table_name, $key_name, $key_data[1]) : $this->sql_create_index($table_name, $key_name, $key_data[1]);

				foreach ($key_stmts as $key_stmt)
				{
					$statements[] = $key_stmt;
				}

				$this->return_statements = $old_return_statements;
			}
		}

		// Commit Transaction
		$statements[] = 'commit';

		return $this->_sql_run_sql($statements);
	}

	/**
	* Handle passed database update array. Update table schema.
	*
	* Key being one of the following
	* <pre>
	* change_columns - Column changes (only type, not name)
	* add_columns - Add columns to a table
	* drop_keys - Dropping keys
	* drop_columns - Removing/Dropping columns
	* add_primary_keys - adding primary keys
	* add_unique_index - adding an unique index
	* add_index - adding an index
	* </pre>
	*
	* For a complete definition of the layout please see {@example }
	*
	* @param array	$schema_changes	The schema array
	*/
	public function sql_schema_changes($schema_changes)
	{
		if (empty($schema_changes))
		{
			return;
		}

		$statements = array();

		// Change columns?
		if (!empty($schema_changes['change_columns']))
		{
			foreach ($schema_changes['change_columns'] as $table => $columns)
			{
				foreach ($columns as $column_name => $column_data)
				{
					// If the column exists we change it, else we add it ;)
					if ($this->sql_column_exists($table, $column_name))
					{
						$result = $this->sql_column_change($table, $column_name, $column_data);
					}
					else
					{
						$result = $this->sql_column_add($table, $column_name, $column_data);
					}

					if ($this->return_statements)
					{
						$statements = array_merge($statements, $result);
					}
				}
			}
		}

		// Add columns?
		if (!empty($schema_changes['add_columns']))
		{
			foreach ($schema_changes['add_columns'] as $table => $columns)
			{
				foreach ($columns as $column_name => $column_data)
				{
					// Only add the column if it does not exist yet, else change it (to be consistent)
					if ($this->sql_column_exists($table, $column_name))
					{
						$result = $this->sql_column_change($table, $column_name, $column_data);
					}
					else
					{
						$result = $this->sql_column_add($table, $column_name, $column_data);
					}

					if ($this->return_statements)
					{
						$statements = array_merge($statements, $result);
					}
				}
			}
		}

		// Remove keys?
		if (!empty($schema_changes['drop_keys']))
		{
			foreach ($schema_changes['drop_keys'] as $table => $indexes)
			{
				foreach ($indexes as $index_name)
				{
					$result = $this->sql_index_drop($table, $index_name);

					if ($this->return_statements)
					{
						$statements = array_merge($statements, $result);
					}
				}
			}
		}

		// Drop columns?
		if (!empty($schema_changes['drop_columns']))
		{
			foreach ($schema_changes['drop_columns'] as $table => $columns)
			{
				foreach ($columns as $column)
				{
					// Only remove the column if it exists...
					if ($this->sql_column_exists($table, $column))
					{
						$result = $this->sql_column_remove($table, $column);

						if ($this->return_statements)
						{
							$statements = array_merge($statements, $result);
						}
					}
				}
			}
		}

		// Add primary keys?
		if (!empty($schema_changes['add_primary_keys']))
		{
			foreach ($schema_changes['add_primary_keys'] as $table => $columns)
			{
				$result = $this->sql_create_primary_key($table, $columns);

				if ($this->return_statements)
				{
					$statements = array_merge($statements, $result);
				}
			}
		}

		// Add unqiue indexes?
		if (!empty($schema_changes['add_unique_index']))
		{
			foreach ($schema_changes['add_unique_index'] as $table => $index_array)
			{
				foreach ($index_array as $index_name => $column)
				{
					$result = $this->sql_create_unique_index($table, $index_name, $column);

					if ($this->return_statements)
					{
						$statements = array_merge($statements, $result);
					}
				}
			}
		}

		// Add indexes?
		if (!empty($schema_changes['add_index']))
		{
			foreach ($schema_changes['add_index'] as $table => $index_array)
			{
				foreach ($index_array as $index_name => $column)
				{
					$result = $this->sql_create_index($table, $index_name, $column);

					if ($this->return_statements)
					{
						$statements = array_merge($statements, $result);
					}
				}
			}
		}

		if ($this->return_statements)
		{
			return $statements;
		}
	}

	/**
	* Insert SQL data into tables (INSERT). This function DOES NOT SUPPORT $return_statements. SQL queries are executed as is.
	* For a complete definition of the layout see {@example }
	*
	* @param array	$schema_data	The schema data array
	* @param array	&$data			A replacement array for replacing template variables within the schema
	*/
	public function sql_insert_data($schema_data, &$data)
	{
		// Go through the columns and define our type and column name for each column
		$keys = $types = array();

		foreach ($schema_data['columns'] as $column)
		{
			if (strpos($column, ':') === false)
			{
				$types[] = false;
				$keys[] = $column;
				continue;
			}

			list($type, $column) = explode(':', $column, 2);
			$types[] = $type;
			$keys[] = $column;
		}

		$size = sizeof($keys);

		// Go through the data array...
		foreach ($schema_data['data'] as $key => $row)
		{
			// Get special values
			foreach ($row as $_key => $value)
			{
				// Special case...
				$row[$_key] = $this->_sql_get_special_row($value, $data);

				if ($types[$_key] === false)
				{
					settype($row[$_key], gettype($row[$_key]));
				}
				else
				{
					settype($row[$_key], $types[$_key]);
				}
			}

			// Build SQL array for INSERT
			$sql = 'INSERT INTO ' . $schema_data['table'] . ' ' . $this->db->sql_build_array('INSERT', array_combine($keys, $row));
			$this->db->sql_query($sql);

			if (!empty($schema_data['store_auto_increment']))
			{
				$this->stored_increments[$schema_data['store_auto_increment']][$key] = $this->db->sql_nextid();
			}
		}
	}

	/**
	* Update SQL data in tables (UPDATE). This function DOES NOT SUPPORT $return_statements. SQL queries are executed as is.
	* For a complete definition of the layout see {@example }
	*
	* @param array	$schema_data	The schema data array
	* @param array	&$data			A replacement array for replacing template variables within the schema
	*/
	public function sql_update_data($schema_data, &$data)
	{
		// Go through the data array...
		$row = $schema_data['data'];

		// Get special values
		foreach ($row as $key => $value)
		{
			$row[$key] = $this->_sql_get_special_row($value, $data);
		}

		// Build SQL array for UPDATE
		$sql_ary = array_combine(array_values($schema_data['columns']), $row);

		$sql = 'UPDATE ' . $schema_data['table'] . ' SET ' . $this->db->sql_build_array('UPDATE', $sql_ary);

		// Is WHERE statement there?
		if (!empty($schema_data['where']))
		{
			$where_statements = array();
			foreach ($schema_data['where'] as $_key => $array)
			{
				foreach ($array as $key => $value)
				{
					$value = $this->_sql_get_special_row($value, $data);

					if (is_string($value))
					{
						$where_statements[] = $key . " = '" . $this->db->sql_escape($value) . "'";
					}
					else
					{
						$where_statements[] = $key . ' = ' . $value;
					}
				}
			}

			if (sizeof($where_statements))
			{
				$sql .= ' WHERE ' . implode(' AND ', $where_statements);
			}
		}

		$this->db->sql_query($sql);
	}

	/**
	* Check if a specified column exist
	*
	* @param string	$table			Table to check the column at
	* @param string	$column_name	The column to check
	*
	* @return bool True if column exists, else false
	*/
	public function sql_column_exists($table, $column_name)
	{
		switch ($this->db->dbms_type)
		{
			case 'mysql':

				$sql = "SHOW COLUMNS FROM $table";
				$result = $this->db->sql_query($sql);

				while ($row = $this->db->sql_fetchrow($result))
				{
					// lower case just in case
					if (strtolower($row['Field']) == $column_name)
					{
						$this->db->sql_freeresult($result);
						return true;
					}
				}
				$this->db->sql_freeresult($result);
				return false;
			break;

			// PostgreSQL has a way of doing this in a much simpler way but would
			// not allow us to support all versions of PostgreSQL
			case 'postgres':
				$sql = "SELECT a.attname
					FROM pg_class c, pg_attribute a
					WHERE c.relname = '{$table}'
						AND a.attnum > 0
						AND a.attrelid = c.oid";
				$result = $this->db->sql_query($sql);
				while ($row = $this->db->sql_fetchrow($result))
				{
					// lower case just in case
					if (strtolower($row['attname']) == $column_name)
					{
						$this->db->sql_freeresult($result);
						return true;
					}
				}
				$this->db->sql_freeresult($result);

				return false;
			break;

			// same deal with PostgreSQL, we must perform more complex operations than
			// we technically could
			case 'mssql':
				$sql = "SELECT c.name
					FROM syscolumns c
					LEFT JOIN sysobjects o ON c.id = o.id
					WHERE o.name = '{$table}'";
				$result = $this->db->sql_query($sql);
				while ($row = $this->db->sql_fetchrow($result))
				{
					// lower case just in case
					if (strtolower($row['name']) == $column_name)
					{
						$this->db->sql_freeresult($result);
						return true;
					}
				}
				$this->db->sql_freeresult($result);
				return false;
			break;

			case 'oracle':
				$sql = "SELECT column_name
					FROM user_tab_columns
					WHERE table_name = '{$table}'";
				$result = $this->db->sql_query($sql);
				while ($row = $this->db->sql_fetchrow($result))
				{
					// lower case just in case
					if (strtolower($row['column_name']) == $column_name)
					{
						$this->db->sql_freeresult($result);
						return true;
					}
				}
				$this->db->sql_freeresult($result);
				return false;
			break;

			case 'firebird':
				$sql = "SELECT RDB\$FIELD_NAME as FNAME
					FROM RDB\$RELATION_FIELDS
					WHERE RDB\$RELATION_NAME = '{$table}'";
				$result = $this->db->sql_query($sql);
				while ($row = $this->db->sql_fetchrow($result))
				{
					// lower case just in case
					if (strtolower($row['fname']) == $column_name)
					{
						$this->db->sql_freeresult($result);
						return true;
					}
				}
				$this->db->sql_freeresult($result);
				return false;
			break;

			case 'db2':
				$sql = "SELECT colname
					FROM syscat.columns
					WHERE tabname = '$table'";
				$result = $this->db->sql_query($sql);
				while ($row = $this->db->sql_fetchrow($result))
				{
					// lower case just in case
					if (strtolower($row['colname']) == $column_name)
					{
						$this->db->sql_freeresult($result);
						return true;
					}
				}
				$this->db->sql_freeresult($result);
				return false;
			break;

			// ugh, SQLite
			case 'sqlite':
				$sql = "SELECT sql
					FROM sqlite_master
					WHERE type = 'table'
						AND name = '{$table}'";
				$result = $this->db->sql_query($sql);

				if (!$result)
				{
					return false;
				}

				$row = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);

				preg_match('#\((.*)\)#s', $row['sql'], $matches);

				$cols = trim($matches[1]);
				$col_array = preg_split('/,(?![\s\w]+\))/m', $cols);

				foreach ($col_array as $declaration)
				{
					$entities = preg_split('#\s+#', trim($declaration));
					if ($entities[0] == 'PRIMARY')
					{
						continue;
					}

					if (strtolower($entities[0]) == $column_name)
					{
						return true;
					}
				}
				return false;
			break;
		}
	}

	/**
	* Add new column
	*/
	public function sql_column_add($table_name, $column_name, $column_data)
	{
		$column_data = $this->sql_prepare_column_data($table_name, $column_name, $column_data);
		$statements = array();

		switch ($this->db->dbms_type)
		{
			case 'firebird':
				$statements[] = 'ALTER TABLE "' . $table_name . '" ADD "' . $column_name . '" ' . $column_data['column_type_sql'];
			break;

			case 'mssql':
				$statements[] = 'ALTER TABLE [' . $table_name . '] ADD [' . $column_name . '] ' . $column_data['column_type_sql_default'];
			break;

			case 'mysql':
				$statements[] = 'ALTER TABLE `' . $table_name . '` ADD COLUMN `' . $column_name . '` ' . $column_data['column_type_sql'];
			break;

			case 'oracle':
				$statements[] = 'ALTER TABLE ' . $table_name . ' ADD ' . $column_name . ' ' . $column_data['column_type_sql'];
			break;

			case 'postgres':
				$statements[] = 'ALTER TABLE ' . $table_name . ' ADD COLUMN "' . $column_name . '" ' . $column_data['column_type_sql'];
			break;

			case 'db2':
				$statements[] = 'ALTER TABLE ' . $table_name . ' ADD ' . $column_name . ' ' . $column_data['column_type_sql'];
			break;

			case 'sqlite':
				if (version_compare(sqlite_libversion(), '3.0') == -1)
				{
					$sql = "SELECT sql
						FROM sqlite_master
						WHERE type = 'table'
							AND name = '{$table_name}'
						ORDER BY type DESC, name;";
					$result = $this->db->sql_query($sql);

					if (!$result)
					{
						break;
					}

					$row = $this->db->sql_fetchrow($result);
					$this->db->sql_freeresult($result);

					$statements[] = 'begin';

					// Create a backup table and populate it, destroy the existing one
					$statements[] = preg_replace('#CREATE\s+TABLE\s+"?' . $table_name . '"?#i', 'CREATE TEMPORARY TABLE ' . $table_name . '_temp', $row['sql']);
					$statements[] = 'INSERT INTO ' . $table_name . '_temp SELECT * FROM ' . $table_name;
					$statements[] = 'DROP TABLE ' . $table_name;

					preg_match('#\((.*)\)#s', $row['sql'], $matches);

					$new_table_cols = trim($matches[1]);
					$old_table_cols = preg_split('/,(?![\s\w]+\))/m', $new_table_cols);
					$column_list = array();

					foreach ($old_table_cols as $declaration)
					{
						$entities = preg_split('#\s+#', trim($declaration));
						if ($entities[0] == 'PRIMARY')
						{
							continue;
						}
						$column_list[] = $entities[0];
					}

					$columns = implode(',', $column_list);

					$new_table_cols = $column_name . ' ' . $column_data['column_type_sql'] . ',' . $new_table_cols;

					// create a new table and fill it up. destroy the temp one
					$statements[] = 'CREATE TABLE ' . $table_name . ' (' . $new_table_cols . ');';
					$statements[] = 'INSERT INTO ' . $table_name . ' (' . $columns . ') SELECT ' . $columns . ' FROM ' . $table_name . '_temp;';
					$statements[] = 'DROP TABLE ' . $table_name . '_temp';

					$statements[] = 'commit';
				}
				else
				{
					$statements[] = 'ALTER TABLE ' . $table_name . ' ADD ' . $column_name . ' [' . $column_data['column_type_sql'] . ']';
				}
			break;
		}

		return $this->_sql_run_sql($statements);
	}

	/**
	* Drop column
	*/
	public function sql_column_remove($table_name, $column_name)
	{
		$statements = array();

		switch ($this->db->dbms_type)
		{
			case 'firebird':
				$statements[] = 'ALTER TABLE "' . $table_name . '" DROP "' . $column_name . '"';
			break;

			case 'mssql':
				$statements[] = 'ALTER TABLE [' . $table_name . '] DROP COLUMN [' . $column_name . ']';
			break;

			case 'mysql':
				$statements[] = 'ALTER TABLE `' . $table_name . '` DROP COLUMN `' . $column_name . '`';
			break;

			case 'oracle':
				$statements[] = 'ALTER TABLE ' . $table_name . ' DROP ' . $column_name;
			break;

			case 'postgres':
				$statements[] = 'ALTER TABLE ' . $table_name . ' DROP COLUMN "' . $column_name . '"';
			break;

			case 'db2':
				$statements[] = 'ALTER TABLE ' . $table_name . ' DROP ' . $column_name;
			break;

			case 'sqlite':
				if (version_compare(sqlite_libversion(), '3.0') == -1)
				{
					$sql = "SELECT sql
						FROM sqlite_master
						WHERE type = 'table'
							AND name = '{$table_name}'
						ORDER BY type DESC, name;";
					$result = $this->db->sql_query($sql);

					if (!$result)
					{
						break;
					}

					$row = $this->db->sql_fetchrow($result);
					$this->db->sql_freeresult($result);

					$statements[] = 'begin';

					// Create a backup table and populate it, destroy the existing one
					$statements[] = preg_replace('#CREATE\s+TABLE\s+"?' . $table_name . '"?#i', 'CREATE TEMPORARY TABLE ' . $table_name . '_temp', $row['sql']);
					$statements[] = 'INSERT INTO ' . $table_name . '_temp SELECT * FROM ' . $table_name;
					$statements[] = 'DROP TABLE ' . $table_name;

					preg_match('#\((.*)\)#s', $row['sql'], $matches);

					$new_table_cols = trim($matches[1]);
					$old_table_cols = preg_split('/,(?![\s\w]+\))/m', $new_table_cols);
					$column_list = array();

					foreach ($old_table_cols as $declaration)
					{
						$entities = preg_split('#\s+#', trim($declaration));
						if ($entities[0] == 'PRIMARY' || $entities[0] === $column_name)
						{
							continue;
						}
						$column_list[] = $entities[0];
					}

					$columns = implode(',', $column_list);

					$new_table_cols = $new_table_cols = preg_replace('/' . $column_name . '[^,]+(?:,|$)/m', '', $new_table_cols);

					// create a new table and fill it up. destroy the temp one
					$statements[] = 'CREATE TABLE ' . $table_name . ' (' . $new_table_cols . ');';
					$statements[] = 'INSERT INTO ' . $table_name . ' (' . $columns . ') SELECT ' . $columns . ' FROM ' . $table_name . '_temp;';
					$statements[] = 'DROP TABLE ' . $table_name . '_temp';

					$statements[] = 'commit';
				}
				else
				{
					$statements[] = 'ALTER TABLE ' . $table_name . ' DROP COLUMN ' . $column_name;
				}
			break;
		}

		return $this->_sql_run_sql($statements);
	}

	/**
	* Drop Index
	*/
	public function sql_index_drop($table_name, $index_name)
	{
		$statements = array();

		switch ($this->db->dbms_type)
		{
			case 'mssql':
				$statements[] = 'DROP INDEX ' . $table_name . '.' . $index_name;
			break;

			case 'mysql':
				$statements[] = 'DROP INDEX ' . $index_name . ' ON ' . $table_name;
			break;

			case 'firebird':
			case 'oracle':
			case 'postgres':
			case 'sqlite':
			case 'db2':
				$statements[] = 'DROP INDEX ' . $table_name . '_' . $index_name;
			break;
		}

		return $this->_sql_run_sql($statements);
	}

	/**
	* Drop Table
	*/
	public function sql_table_drop($table_name)
	{
		$statements = array();

		// the most basic operation, get rid of the table
		$statements[] = 'DROP TABLE ' . $table_name;

		switch ($this->db->dbms_type)
		{
			case 'firebird':
				$sql = 'SELECT RDB$GENERATOR_NAME as gen
					FROM RDB$GENERATORS
					WHERE RDB$SYSTEM_FLAG = 0
						AND RDB$GENERATOR_NAME = \'' . strtoupper($table_name) . "_GEN'";
				$result = $this->db->sql_query($sql);

				// does a generator exist?
				if ($row = $this->db->sql_fetchrow($result))
				{
					$statements[] = "DROP GENERATOR {$row['gen']};";
				}
				$this->db->sql_freeresult($result);
			break;

			case 'oracle':
				$sql = 'SELECT A.REFERENCED_NAME
					FROM USER_DEPENDENCIES A, USER_TRIGGERS B
					WHERE A.REFERENCED_TYPE = \'SEQUENCE\'
						AND A.NAME = B.TRIGGER_NAME
						AND B.TABLE_NAME = \'' . strtoupper($table_name) . "'";
				$result = $this->db->sql_query($sql);

				// any sequences ref'd to this table's triggers?
				while ($row = $this->db->sql_fetchrow($result))
				{
					$statements[] = "DROP SEQUENCE {$row['referenced_name']}";
				}
				$this->db->sql_freeresult($result);

			case 'postgres':
				// PGSQL does not "tightly" bind sequences and tables, we must guess...
				$sql = "SELECT relname
					FROM pg_class
					WHERE relkind = 'S'
						AND relname = '{$table_name}_seq'";
				$result = $this->db->sql_query($sql);

				// We don't even care about storing the results. We already know the answer if we get rows back.
				if ($this->db->sql_fetchrow($result))
				{
					$statements[] =  "DROP SEQUENCE {$table_name}_seq;\n";
				}
				$this->db->sql_freeresult($result);
			break;
		}

		return $this->_sql_run_sql($statements);
	}

	/**
	* Add primary key
	*/
	public function sql_create_primary_key($table_name, $column)
	{
		$statements = array();

		switch ($this->db->dbms_type)
		{
			case 'firebird':
			case 'postgres':
			case 'mysql':
			case 'db2':
				$statements[] = 'ALTER TABLE ' . $table_name . ' ADD PRIMARY KEY (' . implode(', ', $column) . ')';
			break;

			case 'mssql':
				$sql = "ALTER TABLE [{$table_name}] WITH NOCHECK ADD ";
				$sql .= "CONSTRAINT [PK_{$table_name}] PRIMARY KEY  CLUSTERED (";
				$sql .= '[' . implode("],\n\t\t[", $column) . ']';
				$sql .= ') ON [PRIMARY]';

				$statements[] = $sql;
			break;

			case 'oracle':
				$statements[] = 'ALTER TABLE ' . $table_name . 'add CONSTRAINT pk_' . $table_name . ' PRIMARY KEY (' . implode(', ', $column) . ')';
			break;

			case 'sqlite':
				$sql = "SELECT sql
					FROM sqlite_master
					WHERE type = 'table'
						AND name = '{$table_name}'
					ORDER BY type DESC, name;";
				$result = $this->db->sql_query($sql);

				if (!$result)
				{
					break;
				}

				$row = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);

				$statements[] = 'begin';

				// Create a backup table and populate it, destroy the existing one
				$statements[] = preg_replace('#CREATE\s+TABLE\s+"?' . $table_name . '"?#i', 'CREATE TEMPORARY TABLE ' . $table_name . '_temp', $row['sql']);
				$statements[] = 'INSERT INTO ' . $table_name . '_temp SELECT * FROM ' . $table_name;
				$statements[] = 'DROP TABLE ' . $table_name;

				preg_match('#\((.*)\)#s', $row['sql'], $matches);

				$new_table_cols = trim($matches[1]);
				$old_table_cols = preg_split('/,(?![\s\w]+\))/m', $new_table_cols);
				$column_list = array();

				foreach ($old_table_cols as $declaration)
				{
					$entities = preg_split('#\s+#', trim($declaration));
					if ($entities[0] == 'PRIMARY')
					{
						continue;
					}
					$column_list[] = $entities[0];
				}

				$columns = implode(',', $column_list);

				// create a new table and fill it up. destroy the temp one
				$statements[] = 'CREATE TABLE ' . $table_name . ' (' . $new_table_cols . ', PRIMARY KEY (' . implode(', ', $column) . '));';
				$statements[] = 'INSERT INTO ' . $table_name . ' (' . $columns . ') SELECT ' . $columns . ' FROM ' . $table_name . '_temp;';
				$statements[] = 'DROP TABLE ' . $table_name . '_temp';

				$statements[] = 'commit';
			break;
		}

		return $this->_sql_run_sql($statements);
	}

	/**
	* Add unique index
	*/
	public function sql_create_unique_index($table_name, $index_name, $column)
	{
		$statements = array();

		switch ($this->db->dbms_type)
		{
			case 'firebird':
			case 'postgres':
			case 'oracle':
			case 'sqlite':
			case 'db2':
				$statements[] = 'CREATE UNIQUE INDEX ' . $table_name . '_' . $index_name . ' ON ' . $table_name . '(' . implode(', ', $column) . ')';
			break;

			case 'mysql':
				$statements[] = 'CREATE UNIQUE INDEX ' . $index_name . ' ON ' . $table_name . '(' . implode(', ', $column) . ')';
			break;

			case 'mssql':
				$statements[] = 'CREATE UNIQUE INDEX ' . $index_name . ' ON ' . $table_name . '(' . implode(', ', $column) . ') ON [PRIMARY]';
			break;
		}

		return $this->_sql_run_sql($statements);
	}

	/**
	* Add index
	*/
	public function sql_create_index($table_name, $index_name, $column)
	{
		$statements = array();

		switch ($this->db->dbms_type)
		{
			case 'firebird':
			case 'postgres':
			case 'oracle':
			case 'sqlite':
			case 'db2':
				$statements[] = 'CREATE INDEX ' . $table_name . '_' . $index_name . ' ON ' . $table_name . '(' . implode(', ', $column) . ')';
			break;

			case 'mysql':
				$statements[] = 'CREATE INDEX ' . $index_name . ' ON ' . $table_name . '(' . implode(', ', $column) . ')';
			break;

			case 'mssql':
				$statements[] = 'CREATE INDEX ' . $index_name . ' ON ' . $table_name . '(' . implode(', ', $column) . ') ON [PRIMARY]';
			break;
		}

		return $this->_sql_run_sql($statements);
	}

	/**
	* List all of the indices that belong to a table,
	* does not count:
	* * UNIQUE indices
	* * PRIMARY keys
	*/
	public function sql_list_index($table_name)
	{
		$index_array = array();

		if ($this->db->dbms_type == 'mssql')
		{
			$sql = "EXEC sp_statistics '$table_name'";
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				if ($row['TYPE'] == 3)
				{
					$index_array[] = $row['INDEX_NAME'];
				}
			}
			$this->db->sql_freeresult($result);
		}
		else
		{
			switch ($this->db->dbms_type)
			{
				case 'firebird':
					$sql = "SELECT LOWER(RDB\$INDEX_NAME) as index_name
						FROM RDB\$INDICES
						WHERE RDB\$RELATION_NAME = " . strtoupper($table_name) . "
							AND RDB\$UNIQUE_FLAG IS NULL
							AND RDB\$FOREIGN_KEY IS NULL";
					$col = 'index_name';
				break;

				case 'postgres':
					$sql = "SELECT ic.relname as index_name
						FROM pg_class bc, pg_class ic, pg_index i
						WHERE (bc.oid = i.indrelid)
							AND (ic.oid = i.indexrelid)
							AND (bc.relname = '" . $table_name . "')
							AND (i.indisunique != 't')
							AND (i.indisprimary != 't')";
					$col = 'index_name';
				break;

				case 'mysql':
					$sql = 'SHOW KEYS
						FROM ' . $table_name;
					$col = 'Key_name';
				break;

				case 'oracle':
					$sql = "SELECT index_name
						FROM user_indexes
						WHERE table_name = '" . $table_name . "'
							AND generated = 'N'";
					$col = 'index_name';
				break;

				case 'sqlite':
					$sql = "PRAGMA index_info('" . $table_name . "');";
					$col = 'name';
				break;

				case 'db2':
					$sql = "SELECT indname
						FROM SYSCAT.INDEXES
						WHERE TABNAME = '$table_name'
							AND UNIQUERULE <> 'P'";
					$col = 'name';
			}

			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				if ($this->db->dbms_type == 'mysql' && !$row['Non_unique'])
				{
					continue;
				}

				switch ($this->db->dbms_type)
				{
					case 'firebird':
					case 'oracle':
					case 'postgres':
					case 'sqlite':
					case 'db2':
						$row[$col] = substr($row[$col], strlen($table_name) + 1);
					break;
				}

				$index_array[] = $row[$col];
			}
			$this->db->sql_freeresult($result);
		}

		return array_map('strtolower', $index_array);
	}

	/**
	* Change column type (not name!)
	*/
	public function sql_column_change($table_name, $column_name, $column_data)
	{
		$column_data = $this->sql_prepare_column_data($table_name, $column_name, $column_data);
		$statements = array();

		switch ($this->db->dbms_type)
		{
			case 'firebird':
				// Change type...
				$statements[] = 'ALTER TABLE "' . $table_name . '" ALTER COLUMN "' . $column_name . '" TYPE ' . ' ' . $column_data['column_type_sql'];
			break;

			case 'mssql':
				$statements[] = 'ALTER TABLE [' . $table_name . '] ALTER COLUMN [' . $column_name . '] ' . $column_data['column_type_sql'];
			break;

			case 'mysql':
				$statements[] = 'ALTER TABLE `' . $table_name . '` CHANGE `' . $column_name . '` `' . $column_name . '` ' . $column_data['column_type_sql'];
			break;

			case 'oracle':
				$statements[] = 'ALTER TABLE ' . $table_name . ' MODIFY ' . $column_name . ' ' . $column_data['column_type_sql'];
			break;

			case 'db2':
				$statements[] = 'ALTER TABLE ' . $table_name . ' ALTER ' . $column_name . ' SET DATA TYPE ' . $column_data['column_type_sql'];
			break;

			case 'postgres':
				$sql = 'ALTER TABLE ' . $table_name . ' ';

				$sql_array = array();
				$sql_array[] = 'ALTER COLUMN ' . $column_name . ' TYPE ' . $column_data['column_type'];

				if (isset($column_data['null']))
				{
					if ($column_data['null'] == 'NOT NULL')
					{
						$sql_array[] = 'ALTER COLUMN ' . $column_name . ' SET NOT NULL';
					}
					else if ($column_data['null'] == 'NULL')
					{
						$sql_array[] = 'ALTER COLUMN ' . $column_name . ' DROP NOT NULL';
					}
				}

				if (isset($column_data['default']))
				{
					$sql_array[] = 'ALTER COLUMN ' . $column_name . ' SET DEFAULT ' . $column_data['default'];
				}

				// we don't want to double up on constraints if we change different number data types
				if (isset($column_data['constraint']))
				{
					$constraint_sql = "SELECT consrc as constraint_data
								FROM pg_constraint, pg_class bc
								WHERE conrelid = bc.oid
									AND bc.relname = '{$table_name}'
									AND NOT EXISTS (
										SELECT *
											FROM pg_constraint as c, pg_inherits as i
											WHERE i.inhrelid = pg_constraint.conrelid
												AND c.conname = pg_constraint.conname
												AND c.consrc = pg_constraint.consrc
												AND c.conrelid = i.inhparent
									)";

					$constraint_exists = false;

					$result = $this->db->sql_query($constraint_sql);
					while ($row = $this->db->sql_fetchrow($result))
					{
						if (trim($row['constraint_data']) == trim($column_data['constraint']))
						{
							$constraint_exists = true;
							break;
						}
					}
					$this->db->sql_freeresult($result);

					if (!$constraint_exists)
					{
						$sql_array[] = 'ADD ' . $column_data['constraint'];
					}
				}

				$sql .= implode(', ', $sql_array);

				$statements[] = $sql;
			break;

			case 'sqlite':
				$sql = "SELECT sql
					FROM sqlite_master
					WHERE type = 'table'
						AND name = '{$table_name}'
					ORDER BY type DESC, name;";
				$result = $this->db->sql_query($sql);

				if (!$result)
				{
					break;
				}

				$row = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);

				$statements[] = 'begin';

				// Create a temp table and populate it, destroy the existing one
				$statements[] = preg_replace('#CREATE\s+TABLE\s+"?' . $table_name . '"?#i', 'CREATE TEMPORARY TABLE ' . $table_name . '_temp', $row['sql']);
				$statements[] = 'INSERT INTO ' . $table_name . '_temp SELECT * FROM ' . $table_name;
				$statements[] = 'DROP TABLE ' . $table_name;

				preg_match('#\((.*)\)#s', $row['sql'], $matches);

				$new_table_cols = trim($matches[1]);
				$old_table_cols = preg_split('/,(?![\s\w]+\))/m', $new_table_cols);
				$column_list = array();

				foreach ($old_table_cols as $key => $declaration)
				{
					$entities = preg_split('#\s+#', trim($declaration));
					$column_list[] = $entities[0];
					if ($entities[0] == $column_name)
					{
						$old_table_cols[$key] = $column_name . ' ' . $column_data['column_type_sql'];
					}
				}

				$columns = implode(',', $column_list);

				// create a new table and fill it up. destroy the temp one
				$statements[] = 'CREATE TABLE ' . $table_name . ' (' . implode(',', $old_table_cols) . ');';
				$statements[] = 'INSERT INTO ' . $table_name . ' (' . $columns . ') SELECT ' . $columns . ' FROM ' . $table_name . '_temp;';
				$statements[] = 'DROP TABLE ' . $table_name . '_temp';

				$statements[] = 'commit';

			break;
		}

		return $this->_sql_run_sql($statements);
	}

	private function _sql_get_special_row($value, &$data)
	{
		if (is_array($value))
		{
			if (isset($value['auto_increment']))
			{
				$auto_key = explode(':', $value['auto_increment'], 2);
				$value_key = $auto_key[0];
				$auto_key = (int) $auto_key[1];

				if (isset($this->stored_increments[$value_key][$auto_key]))
				{
					$value = $this->stored_increments[$value_key][$auto_key];
				}
			}
			else
			{
				$value = NULL;
			}
		}
		else if (strpos($value, '{') === 0 && strpos($value, '}') === strlen($value) - 1)
		{
			if (strpos($value, '{L_') === 0 && isset(phpbb::$user->lang[substr($value, 3, -1)]))
			{
				$value = phpbb::$user->lang[substr($value, 3, -1)];
			}
			else if (isset($data[substr($value, 1, -1)]))
			{
				$value = $data[substr($value, 1, -1)];
			}
		}

		return $value;
	}

	/**
	* Private method for performing sql statements (either execute them or return them)
	* @access private
	*/
	private function _sql_run_sql($statements)
	{
		if ($this->return_statements)
		{
			return $statements;
		}

		// We could add error handling here...
		foreach ($statements as $sql)
		{
			if ($sql === 'begin')
			{
				$this->db->sql_transaction('begin');
			}
			else if ($sql === 'commit')
			{
				$this->db->sql_transaction('commit');
			}
			else
			{
				$this->db->sql_query($sql);
			}
		}

		return true;
	}

	/**
	* Function to prepare some column information for better usage
	* @access private
	*/
	private function sql_prepare_column_data($table_name, $column_name, $column_data)
	{
		// Get type
		if (strpos($column_data[0], ':') !== false)
		{
			list($orig_column_type, $column_length) = explode(':', $column_data[0]);

			if (!is_array($this->db->dbms_type_map[$orig_column_type . ':']))
			{
				$column_type = sprintf($this->db->dbms_type_map[$orig_column_type . ':'], $column_length);
			}
			else
			{
				if (isset($this->db->dbms_type_map[$orig_column_type . ':']['rule']))
				{
					switch ($this->db->dbms_type_map[$orig_column_type . ':']['rule'][0])
					{
						case 'div':
							$column_length /= $this->db->dbms_type_map[$orig_column_type . ':']['rule'][1];
							$column_length = ceil($column_length);
							$column_type = sprintf($this->db->dbms_type_map[$orig_column_type . ':'][0], $column_length);
						break;
					}
				}

				if (isset($this->db->dbms_type_map[$orig_column_type . ':']['limit']))
				{
					switch ($this->db->dbms_type_map[$orig_column_type . ':']['limit'][0])
					{
						case 'mult':
							$column_length *= $this->db->dbms_type_map[$orig_column_type . ':']['limit'][1];
							if ($column_length > $this->db->dbms_type_map[$orig_column_type . ':']['limit'][2])
							{
								$column_type = $this->db->dbms_type_map[$orig_column_type . ':']['limit'][3];
							}
							else
							{
								$column_type = sprintf($this->db->dbms_type_map[$orig_column_type . ':'][0], $column_length);
							}
						break;
					}
				}
			}

			$orig_column_type .= ':';
		}
		else
		{
			$orig_column_type = $column_data[0];
			$column_type = $this->db->dbms_type_map[$column_data[0]];
		}

		// Adjust default value if db-dependant specified
		if (is_array($column_data[1]))
		{
			$column_data[1] = (isset($column_data[1][$this->db->dbms_type])) ? $column_data[1][$this->db->dbms_type] : $column_data[1]['default'];
		}

		$sql = '';

		$return_array = array();

		switch ($this->db->dbms_type)
		{
			case 'firebird':
				$sql .= " {$column_type} ";

				if (!is_null($column_data[1]))
				{
					$sql .= 'DEFAULT ' . ((is_numeric($column_data[1])) ? $column_data[1] : "'{$column_data[1]}'") . ' ';
				}

				$sql .= 'NOT NULL';

				// This is a UNICODE column and thus should be given it's fair share
				if (preg_match('/^X?STEXT_UNI|VCHAR_(CI|UNI:?)/', $column_data[0]))
				{
					$sql .= ' COLLATE UNICODE';
				}

				$return_array['auto_increment'] = false;
				if (isset($column_data[2]) && $column_data[2] == 'auto_increment')
				{
					$return_array['auto_increment'] = true;
				}

			break;

			case 'mssql':
				$sql .= " {$column_type} ";
				$sql_default = " {$column_type} ";

				// For adding columns we need the default definition
				if (!is_null($column_data[1]))
				{
					// For hexadecimal values do not use single quotes
					if (strpos($column_data[1], '0x') === 0)
					{
						$sql_default .= 'DEFAULT (' . $column_data[1] . ') ';
					}
					else
					{
						$sql_default .= 'DEFAULT (' . ((is_numeric($column_data[1])) ? $column_data[1] : "'{$column_data[1]}'") . ') ';
					}
				}

				if (isset($column_data[2]) && $column_data[2] == 'auto_increment')
				{
//					$sql .= 'IDENTITY (1, 1) ';
					$sql_default .= 'IDENTITY (1, 1) ';
				}

				$return_array['textimage'] = $column_type === '[text]';

				$sql .= 'NOT NULL';
				$sql_default .= 'NOT NULL';

				$return_array['column_type_sql_default'] = $sql_default;
			break;

			case 'mysql':
				$sql .= " {$column_type} ";

				// For hexadecimal values do not use single quotes
				if (!is_null($column_data[1]) && substr($column_type, -4) !== 'text' && substr($column_type, -4) !== 'blob')
				{
					$sql .= (strpos($column_data[1], '0x') === 0) ? "DEFAULT {$column_data[1]} " : "DEFAULT '{$column_data[1]}' ";
				}
				$sql .= 'NOT NULL';

				if (isset($column_data[2]))
				{
					if ($column_data[2] == 'auto_increment')
					{
						$sql .= ' auto_increment';
					}
					else if ($column_data[2] == 'true_sort')
					{
						$sql .= ' COLLATE utf8_unicode_ci';
					}
				}

			break;

			case 'oracle':
				$sql .= " {$column_type} ";
				$sql .= (!is_null($column_data[1])) ? "DEFAULT '{$column_data[1]}' " : '';

				// In Oracle empty strings ('') are treated as NULL.
				// Therefore in oracle we allow NULL's for all DEFAULT '' entries
				// Oracle does not like setting NOT NULL on a column that is already NOT NULL (this happens only on number fields)
				if (!preg_match('/number/i', $column_type))
				{
					$sql .= ($column_data[1] === '') ? '' : 'NOT NULL';
				}

				$return_array['auto_increment'] = false;
				if (isset($column_data[2]) && $column_data[2] == 'auto_increment')
				{
					$return_array['auto_increment'] = true;
				}
			break;

			case 'postgres':
				$return_array['column_type'] = $column_type;

				$sql .= " {$column_type} ";

				$return_array['auto_increment'] = false;
				if (isset($column_data[2]) && $column_data[2] == 'auto_increment')
				{
					$default_val = "nextval('{$table_name}_seq')";
					$return_array['auto_increment'] = true;
				}
				else if (!is_null($column_data[1]))
				{
					$default_val = "'" . $column_data[1] . "'";
					$return_array['null'] = 'NOT NULL';
					$sql .= 'NOT NULL ';
				}

				$return_array['default'] = $default_val;

				$sql .= "DEFAULT {$default_val}";

				// Unsigned? Then add a CHECK contraint
				if (in_array($orig_column_type, $this->unsigned_types))
				{
					$return_array['constraint'] = "CHECK ({$column_name} >= 0)";
					$sql .= " CHECK ({$column_name} >= 0)";
				}
			break;

			case 'sqlite':
				$return_array['primary_key_set'] = false;
				if (isset($column_data[2]) && $column_data[2] == 'auto_increment')
				{
					$sql .= ' INTEGER PRIMARY KEY';
					$return_array['primary_key_set'] = true;
				}
				else
				{
					$sql .= ' ' . $column_type;
				}

				$sql .= ' NOT NULL ';
				$sql .= (!is_null($column_data[1])) ? "DEFAULT '{$column_data[1]}'" : '';
			break;

			case 'db2':
				$sql .= " {$column_type} NOT NULL";

				if (isset($column_data[2]) && $column_data[2] == 'auto_increment')
				{
					$sql .= ' GENERATED BY DEFAULT AS IDENTITY (START WITH 1, INCREMENT BY 1)';
				}
				else
				{
					if (preg_match('/^(integer|smallint|float)$/', $column_type))
					{
						$sql .= " DEFAULT {$column_data[1]}";
					}
					else
					{
						$sql .= " DEFAULT '{$column_data[1]}'";
					}
				}
			break;
		}

		$return_array['column_type_sql'] = $sql;

		return $return_array;
	}
}

?>