<?php
/**
 * ownCloud
 *
 * @author Frank Karlitschek
 * @copyright 2012 Frank Karlitschek frank@owncloud.org
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

define('MDB2_SCHEMA_DUMP_STRUCTURE', '1');

class DatabaseException extends Exception {
	private $query;

	//FIXME getQuery seems to be unused, maybe use parent constructor with $message, $code and $previous
	public function __construct($message, $query = null){
		parent::__construct($message);
		$this->query = $query;
	}

	public function getQuery() {
		return $this->query;
	}
}

/**
 * This class manages the access to the database. It basically is a wrapper for
 * Doctrine with some adaptions.
 */
class OC_DB {
	const BACKEND_DOCTRINE=2;

	/**
	 * @var \Doctrine\DBAL\Connection
	 */
	static private $connection; //the preferred connection to use, only Doctrine
	static private $backend=null;
	/**
	 * @var \Doctrine\DBAL\Connection
	 */
	static private $DOCTRINE=null;

	static private $inTransaction=false;
	static private $type=null;

	/**
	 * check which backend we should use
	 * @return int BACKEND_DOCTRINE
	 */
	private static function getDBBackend() {
		return self::BACKEND_DOCTRINE;
	}

	/**
	 * @brief connects to the database
	 * @param int $backend
	 * @return bool true if connection can be established or false on error
	 *
	 * Connects to the database as specified in config.php
	 */
	public static function connect($backend=null) {
		if(self::$connection) {
			return true;
		}
		if(is_null($backend)) {
			$backend=self::getDBBackend();
		}
		if($backend==self::BACKEND_DOCTRINE) {
			$success = self::connectDoctrine();
			self::$connection=self::$DOCTRINE;
			self::$backend=self::BACKEND_DOCTRINE;
		}
		return $success;
	}

	/**
	 * connect to the database using doctrine
	 *
	 * @return bool
	 */
	public static function connectDoctrine() {
		if(self::$connection) {
			if(self::$backend!=self::BACKEND_DOCTRINE) {
				self::disconnect();
			} else {
				return true;
			}
		}
		// The global data we need
		$name = OC_Config::getValue( "dbname", "owncloud" );
		$host = OC_Config::getValue( "dbhost", "" );
		$user = OC_Config::getValue( "dbuser", "" );
		$pass = OC_Config::getValue( "dbpassword", "" );
		$type = OC_Config::getValue( "dbtype", "sqlite" );
		if(strpos($host, ':')) {
			list($host, $port)=explode(':', $host, 2);
		} else {
			$port=false;
		}

		// do nothing if the connection already has been established
		if(!self::$DOCTRINE) {
			$config = new \Doctrine\DBAL\Configuration();
			switch($type) {
				case 'sqlite':
				case 'sqlite3':
					$datadir=OC_Config::getValue( "datadirectory", OC::$SERVERROOT.'/data' );
					$connectionParams = array(
							'user' => $user,
							'password' => $pass,
							'path' => $datadir.'/'.$name.'.db',
							'driver' => 'pdo_sqlite',
					);
					$connectionParams['adapter'] = '\OC\DB\AdapterSqlite';
					break;
				case 'mysql':
					$connectionParams = array(
							'user' => $user,
							'password' => $pass,
							'host' => $host,
							'port' => $port,
							'dbname' => $name,
							'charset' => 'UTF8',
							'driver' => 'pdo_mysql',
					);
					$connectionParams['adapter'] = '\OC\DB\Adapter';
					break;
				case 'pgsql':
					$connectionParams = array(
							'user' => $user,
							'password' => $pass,
							'host' => $host,
							'port' => $port,
							'dbname' => $name,
							'driver' => 'pdo_pgsql',
					);
					$connectionParams['adapter'] = '\OC\DB\AdapterPgSql';
					break;
				case 'oci':
					$connectionParams = array(
							'user' => $user,
							'password' => $pass,
							'host' => $host,
							'dbname' => $name,
							'charset' => 'AL32UTF8',
							'driver' => 'oci8',
					);
					if (!empty($port)) {
						$connectionParams['port'] = $port;
					}
					$connectionParams['adapter'] = '\OC\DB\AdapterOCI8';
					break;
				case 'mssql':
					$connectionParams = array(
							'user' => $user,
							'password' => $pass,
							'host' => $host,
							'port' => $port,
							'dbname' => $name,
							'charset' => 'UTF8',
							'driver' => 'pdo_sqlsrv',
					);
					$connectionParams['adapter'] = '\OC\DB\AdapterSQLSrv';
					break;
				default:
					return false;
			}
			$connectionParams['wrapperClass'] = 'OC\DB\Connection';
			$connectionParams['table_prefix'] = OC_Config::getValue( "dbtableprefix", "oc_" );
			try {
				self::$DOCTRINE = \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);
				if ($type === 'sqlite' || $type === 'sqlite3') {
					// Sqlite doesn't handle query caching and schema changes
					// TODO: find a better way to handle this
					self::$connection->disableQueryStatementCaching();
				}
			} catch(\Doctrine\DBAL\DBALException $e) {
				OC_Log::write('core', $e->getMessage(), OC_Log::FATAL);
				OC_User::setUserId(null);

				// send http status 503
				header('HTTP/1.1 503 Service Temporarily Unavailable');
				header('Status: 503 Service Temporarily Unavailable');
				OC_Template::printErrorPage('Failed to connect to database');
				die();
			}
		}
		return true;
	}

	/**
	 * @brief Prepare a SQL query
	 * @param string $query Query string
	 * @param int $limit
	 * @param int $offset
	 * @throws DatabaseException
	 * @return \Doctrine\DBAL\Statement prepared SQL query
	 *
	 * SQL query via Doctrine prepare(), needs to be execute()'d!
	 */
	static public function prepare( $query, $limit=null, $offset=null ) {
		self::connect();
		// return the result
		if (self::$backend == self::BACKEND_DOCTRINE) {
			try {
				$result=self::$connection->prepare($query, $limit, $offset);
			} catch(\Doctrine\DBAL\DBALException $e) {
				throw new \DatabaseException($e->getMessage(), $query);
			}
			$result=new OC_DB_StatementWrapper($result);
		}
		return $result;
	}

	/**
	 * @brief execute a prepared statement, on error write log and throw exception
	 * @param mixed $stmt OC_DB_StatementWrapper,
	 *					  an array with 'sql' and optionally 'limit' and 'offset' keys
	 *					.. or a simple sql query string
	 * @param array $parameters
	 * @return result
	 * @throws DatabaseException
	 */
	static public function executeAudited( $stmt, array $parameters = null) {
		if (is_string($stmt)) {
			// convert to an array with 'sql'
			if (stripos($stmt,'LIMIT') !== false) { //OFFSET requires LIMIT, se we only neet to check for LIMIT
				// TODO try to convert LIMIT OFFSET notation to parameters, see fixLimitClauseForMSSQL
				$message = 'LIMIT and OFFSET are forbidden for portability reasons,'
						 . ' pass an array with \'limit\' and \'offset\' instead';
				throw new DatabaseException($message);
			}
			$stmt = array('sql' => $stmt, 'limit' => null, 'offset' => null);
		}
		if (is_array($stmt)){
			// convert to prepared statement
			if ( ! array_key_exists('sql', $stmt) ) {
				$message = 'statement array must at least contain key \'sql\'';
				throw new DatabaseException($message);
			}
			if ( ! array_key_exists('limit', $stmt) ) {
				$stmt['limit'] = null;
			}
			if ( ! array_key_exists('limit', $stmt) ) {
				$stmt['offset'] = null;
			}
			$stmt = self::prepare($stmt['sql'], $stmt['limit'], $stmt['offset']);
		}
		self::raiseExceptionOnError($stmt, 'Could not prepare statement');
		if ($stmt instanceof OC_DB_StatementWrapper) {
			$result = $stmt->execute($parameters);
			self::raiseExceptionOnError($result, 'Could not execute statement');
		} else {
			if (is_object($stmt)) {
				$message = 'Expected a prepared statement or array got ' . get_class($stmt);
			} else {
				$message = 'Expected a prepared statement or array got ' . gettype($stmt);
			}
			throw new DatabaseException($message);
		}
		return $result;
	}

	/**
	 * @brief gets last value of autoincrement
	 * @param string $table The optional table name (will replace *PREFIX*) and add sequence suffix
	 * @return int id
	 * @throws DatabaseException
	 *
	 * \Doctrine\DBAL\Connection lastInsertId
	 *
	 * Call this method right after the insert command or other functions may
	 * cause trouble!
	 */
	public static function insertid($table=null) {
		self::connect();
		return self::$connection->lastInsertId($table);
	}

	/**
	 * @brief Disconnect
	 * @return bool
	 *
	 * This is good bye, good bye, yeah!
	 */
	public static function disconnect() {
		// Cut connection if required
		if(self::$connection) {
			self::$connection->close();
		}

		return true;
	}

	/** else {
	 * @brief saves database scheme to xml file
	 * @param string $file name of file
	 * @param int $mode
	 * @return bool
	 *
	 * TODO: write more documentation
	 */
	public static function getDbStructure( $file, $mode=MDB2_SCHEMA_DUMP_STRUCTURE) {
		self::connectDoctrine();
		return OC_DB_Schema::getDbStructure(self::$DOCTRINE, $file);
	}

	/**
	 * @brief Creates tables from XML file
	 * @param string $file file to read structure from
	 * @return bool
	 *
	 * TODO: write more documentation
	 */
	public static function createDbFromStructure( $file ) {
		self::connectDoctrine();
		return OC_DB_Schema::createDbFromStructure(self::$DOCTRINE, $file);
	}

	/**
	 * @brief update the database scheme
	 * @param string $file file to read structure from
	 * @throws Exception
	 * @return bool
	 */
	public static function updateDbFromStructure($file) {
		self::connectDoctrine();
		try {
			$result = OC_DB_Schema::updateDbFromStructure(self::$DOCTRINE, $file);
		} catch (Exception $e) {
			OC_Log::write('core', 'Failed to update database structure ('.$e.')', OC_Log::FATAL);
			throw $e;
		}
		return $result;
	}

	/**
	 * @brief Insert a row if a matching row doesn't exists.
	 * @param string $table. The table to insert into in the form '*PREFIX*tableName'
	 * @param array $input. An array of fieldname/value pairs
	 * @return bool return value from OC_DB_StatementWrapper->execute()
	 */
	public static function insertIfNotExist($table, $input) {
		self::connect();
		$table = self::$connection->replaceTablePrefix( $table );

		if(is_null(self::$type)) {
			self::$type=OC_Config::getValue( "dbtype", "sqlite" );
		}
		$type = self::$type;

		$query = '';
		$inserts = array_values($input);
		// differences in escaping of table names ('`' for mysql) and getting the current timestamp
		if( $type == 'sqlite' || $type == 'sqlite3' ) {
			// NOTE: For SQLite we have to use this clumsy approach
			// otherwise all fieldnames used must have a unique key.
			$query = 'SELECT * FROM `' . $table . '` WHERE ';
			foreach($input as $key => $value) {
				$query .= '`' . $key . '` = ? AND ';
			}
			$query = substr($query, 0, strlen($query) - 5);
			try {
				$result = self::executeAudited($query, $inserts);
			} catch(DatabaseException $e) {
				OC_Template::printExceptionErrorPage( $e );
			}

			if((int)$result->numRows() === 0) {
				$query = 'INSERT INTO `' . $table . '` (`'
					. implode('`,`', array_keys($input)) . '`) VALUES('
					. str_repeat('?,', count($input)-1).'? ' . ')';
			} else {
				return true;
			}
		} elseif( $type == 'pgsql' || $type == 'oci' || $type == 'mysql' || $type == 'mssql') {
			$query = 'INSERT INTO `' .$table . '` (`'
				. implode('`,`', array_keys($input)) . '`) SELECT '
				. str_repeat('?,', count($input)-1).'? ' // Is there a prettier alternative?
				. 'FROM `' . $table . '` WHERE ';

			foreach($input as $key => $value) {
				$query .= '`' . $key . '` = ? AND ';
			}
			$query = substr($query, 0, strlen($query) - 5);
			$query .= ' HAVING COUNT(*) = 0';
			$inserts = array_merge($inserts, $inserts);
		}

		try {
			$result = self::executeAudited($query, $inserts);
		} catch(\Doctrine\DBAL\DBALException $e) {
			OC_Template::printExceptionErrorPage( $e );
		}

		return $result;
	}

	/**
	 * @brief drop a table
	 * @param string $tableName the table to drop
	 */
	public static function dropTable($tableName) {
		self::connectDoctrine();
		OC_DB_Schema::dropTable(self::$DOCTRINE, $tableName);
	}

	/**
	 * remove all tables defined in a database structure xml file
	 * @param string $file the xml file describing the tables
	 */
	public static function removeDBStructure($file) {
		self::connectDoctrine();
		OC_DB_Schema::removeDBStructure(self::$DOCTRINE, $file);
	}

	/**
	 * @brief replaces the ownCloud tables with a new set
	 * @param $file string path to the MDB2 xml db export file
	 */
	public static function replaceDB( $file ) {
		self::connectDoctrine();
		OC_DB_Schema::replaceDB(self::$DOCTRINE, $file);
	}

	/**
	 * Start a transaction
	 * @return bool
	 */
	public static function beginTransaction() {
		self::connect();
		self::$connection->beginTransaction();
		self::$inTransaction=true;
		return true;
	}

	/**
	 * Commit the database changes done during a transaction that is in progress
	 * @return bool
	 */
	public static function commit() {
		self::connect();
		if(!self::$inTransaction) {
			return false;
		}
		self::$connection->commit();
		self::$inTransaction=false;
		return true;
	}

	/**
	 * check if a result is an error, works with Doctrine
	 * @param mixed $result
	 * @return bool
	 */
	public static function isError($result) {
		if(!$result) {
			return true;
		} else {
			return false;
		}
	}
	/**
	 * check if a result is an error and throws an exception, works with \Doctrine\DBAL\DBALException
	 * @param mixed $result
	 * @param string $message
	 * @return void
	 * @throws DatabaseException
	 */
	public static function raiseExceptionOnError($result, $message = null) {
		if(self::isError($result)) {
			if ($message === null) {
				$message = self::getErrorMessage($result);
			} else {
				$message .= ', Root cause:' . self::getErrorMessage($result);
			}
			throw new DatabaseException($message, self::getErrorCode($result));
		}
	}

	public static function getErrorCode($error) {
		$code = self::$connection->errorCode();
		return $code;
	}
	/**
	 * returns the error code and message as a string for logging
	 * works with DoctrineException
	 * @param mixed $error
	 * @return string
	 */
	public static function getErrorMessage($error) {
		if (self::$backend==self::BACKEND_DOCTRINE and self::$DOCTRINE) {
			$msg = self::$DOCTRINE->errorCode() . ': ';
			$errorInfo = self::$DOCTRINE->errorInfo();
			if (is_array($errorInfo)) {
				$msg .= 'SQLSTATE = '.$errorInfo[0] . ', ';
				$msg .= 'Driver Code = '.$errorInfo[1] . ', ';
				$msg .= 'Driver Message = '.$errorInfo[2];
			} else {
				$msg = '';
			}
		} else {
			$msg = '';
		}
		return $msg;
	}

	/**
	 * @param bool $enabled
	 */
	static public function enableCaching($enabled) {
		if ($enabled) {
			self::$connection->enableQueryStatementCaching();
		} else {
			self::$connection->disableQueryStatementCaching();
		}
	}
}
