<?php

/*
 * Coding copyright Martin Lucas-Smith, University of Cambridge, 2003-17
 * Version 3.0.6
 * Uses prepared statements (see https://stackoverflow.com/questions/60174/how-can-i-prevent-sql-injection-in-php ) where possible
 * Distributed under the terms of the GNU Public Licence - https://www.gnu.org/copyleft/gpl.html
 * Requires PHP 4.1+ with register_globals set to 'off'
 * Download latest from: http://download.geog.cam.ac.uk/projects/database/
 */


# Class containing basic generalised database manipulation functions for PDO
class database
{
	# General class properties
	public $connection = NULL;
	private $preparedStatement = NULL;
	private $query = NULL;
	private $queryValues = NULL;
	private $strictWhere = false;
	private $fieldsCache = array ();
	
	# Error logger properties
	private $errorLoggerCallback = NULL;
	private $errorLoggerCustomCode = NULL;
	private $errorLoggerCustomCodeText = NULL;
	private $errorLoggerEntryFunction = NULL;
	
	
	# Function to connect to the database
	public function __construct ($hostname, $username, $password, $database = NULL, $vendor = 'mysql', $logFile = false, $userForLogging = false, $nativeTypes = false /* NB: a future release will change this to true */, $setNamesUtf8 = true, $driverOptions = array ())
	{
		# Assign the user for logging
		$this->logFile = $logFile;
		$this->userForLogging = $userForLogging;
		
		# Make attributes available for querying by calling applications
		$this->hostname = $hostname;
		$this->vendor = $vendor;
		
		# Convert localhost to 127.0.0.1
		if ($hostname == 'localhost') {
			if (version_compare (PHP_VERSION, '5.3.0', '>=')) {
				// Previously believed only to affect Windows Vista, but not the case. On PHP 5.3.x on Windows (Vista) see http://bugs.php.net/45150
				// if (substr (PHP_OS, 0, 3) == 'WIN') {
					$hostname = '127.0.0.1';
				// }
			}
		}
		
		# Enable native types if required; currently implemented and tested only for MySQL; note that this requires the pdo-mysqlnd driver to be installed
		if ($nativeTypes) {
			if ($vendor == 'mysql') {
				$driverOptions[PDO::ATTR_EMULATE_PREPARES] = false;		// #!# This seems to cause problems with e.g. "SHOW DATABASES LIKE"; see point 3 at: http://stackoverflow.com/a/10455228/180733 and http://stackoverflow.com/a/12202218/180733
				$driverOptions[PDO::ATTR_STRINGIFY_FETCHES] = false;	// This seems to be the default anyway
			}
		}
		
		# Enable exception throwing; see: http://php.net/pdo.error-handling
		$driverOptions[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
		
		# Connect to the database and return the status
		if ($vendor == 'sqlite') {
			$dsn = 'sqlite:' . $database;	// Database should be a filename with absolute path
			$setNamesUtf8 = false;
		} else {
			$dsn = "{$vendor}:host={$hostname}" . ($database ? ";dbname={$database}" : '');
		}
		try {
			$this->connection = new PDO ($dsn, $username, $password, $driverOptions);
		} catch (PDOException $e) {		// "PDO::__construct() will always throw a PDOException if the connection fails regardless of which PDO::ATTR_ERRMODE is currently set." noted at http://php.net/pdo.error-handling
			// error_log ("{$e} {$dsn}, {$username}, {$password}");		// Not enabled by default as $e can contain passwords which get dumped to the webserver's error log
			return false;
		}
		
		# Set transfers to UTF-8
		if ($setNamesUtf8) {
			$this->_execute ("SET NAMES 'utf8'");
			// # The following is a more portable version that could be used instead
			//$charset = $this->getVariable ('character_set_database');
			//$this->_execute ("SET NAMES '{$charset}';");
		}
	}
	
	
	# Function to disconnect from the database
	public function close ()
	{
		# Close the connection
		$this->connection = NULL;
	}
	
	
	# Function to enable whether automatically-constructed WHERE=... clauses do proper, exact comparisons, so that id="1 x" doesn't match against id value 1 in the database
	public function setStrictWhere ($boolean = true)
	{
		$this->strictWhere = $boolean;
	}
	
	
	# Function to register an error logger callback; the callback should either be a function name or specified as an array (object instance, publicly-visible method)
	public function registerErrorLogger ($callback)
	{
		# Register the callback
		$this->errorLoggerCallback = $callback;
	}
	
	
	# Function to set a custom error code and text that will be applied to the following call only
	public function errorCode ($code = NULL, $text = NULL)
	{
		# Register the code and text
		$this->errorLoggerCustomCode = $code;
		$this->errorLoggerCustomCodeText = $text;
	}
	
	
	# Function to reset any custom error code
	private function resetErrorCode ()
	{
		# Reset the values
		$this->errorCode ();
	}
	
	
	# Function to call the error logger, if it is defined; currently this supports only an external callback
	private function logError ($forcedErrorText = false)
	{
		# Ignore this functionality if no callback
		if (!$this->errorLoggerCallback) {return;}
		
		# Append forced error text if required
		if ($forcedErrorText) {
			$divider = ($this->errorLoggerCustomCodeText ? (substr ($this->errorLoggerCustomCodeText, -1) == '.' ? '' : ';') . ' ' : '');
			$this->errorLoggerCustomCodeText .= $divider . $forcedErrorText;
		}
		
		# Call the logger, sending back the called function (e.g. 'query', 'getData', 'select', etc.) and the error details
		if (is_array ($this->errorLoggerCallback)) {
			$class  = $this->errorLoggerCallback[0];
			$method = $this->errorLoggerCallback[1];
			$class->$method ($this->errorLoggerEntryFunction, $this->error (), $this->errorLoggerCustomCode, $this->errorLoggerCustomCodeText);
		} else {
			$callback = $this->errorLoggerCallback;
			$callback ($this->errorLoggerEntryFunction, $this->error (), $this->errorLoggerCustomCode, $this->errorLoggerCustomCodeText);
		}
		
		# Reset any custom error code and text
		$this->resetErrorCode ();
	}
	
	
	# Function to do a generic SQL query
	#!# Currently no ability to enable logging for write-based queries; need to allow external callers to specify this, but without this affecting internal use of this function
	public function query ($query, $preparedStatementValues = array (), $debug = false)
	{
		# Register this as the public entry point
		$this->errorLoggerEntryFunction = __FUNCTION__;
		
		# Hand off to the implementation
		$result = $this->_query ($query, $preparedStatementValues, $debug);
		
		# Reset any custom error code and text
		$this->resetErrorCode ();
    	
		# Return the result
		return $result;
	}
	
	
	# Implementation for query
	private function _query ($query, $preparedStatementValues = array (), $debug = false)
	{
		return $this->queryOrExecute (__FUNCTION__, $query, $preparedStatementValues, $debug);
	}
	
	
	# Function to execute a generic SQL query
	#!# Currently no ability to enable logging for write-based queries; need to allow external callers to specify this, but without this affecting internal use of this function
	public function execute ($query, $preparedStatementValues = array (), $debug = false)
	{
		# Register this as the public entry point
		$this->errorLoggerEntryFunction = __FUNCTION__;
		
		# Hand off to the implementation
		$result = $this->_execute ($query, $preparedStatementValues, $debug);
		
		# Reset any custom error code and text
		$this->resetErrorCode ();
		
		# Return the result
		return $result;
	}
	
	
	# Implementation for execute
	private function _execute ($query, $preparedStatementValues = array (), $debug = false)
	{
		return $this->queryOrExecute (__FUNCTION__, $query, $preparedStatementValues, $debug);
	}
	
	
	# Function used by both query and execute
	private function queryOrExecute ($mode, $query, $preparedStatementValues = array (), $debug = false)
	{
		# Global the query and any values
		$this->query = $query;
		$this->queryValues = $preparedStatementValues;
		
		# Show the query if debugging
		#!# Deprecate this
		if ($debug) {
			echo $query . "<br />";
		}
		
		# If using prepared statements, prepare then execute
		$this->preparedStatement = NULL;	// Always clear to avoid the error() function returning results of a previous statement
		if ($preparedStatementValues) {
			
			# Execute the statement (ending if there is an error in the query or parameters)
			try {
				$this->preparedStatement = $this->connection->prepare ($query);
				$result = $this->preparedStatement->execute ($preparedStatementValues);
			} catch (PDOException $e) {		// Enabled by PDO::ERRMODE_EXCEPTION in constructor
				$this->logError ();
				return false;
			}
			
			# In execute mode, get the number of affected rows
			if ($mode == '_execute') {
				$result = $this->preparedStatement->rowCount ();
			}
			
		} else {
			
			# Execute the query and get the number of affected rows
			$function = ($mode == '_query' ? 'query' : 'exec');
			try {
				$result = $this->connection->$function ($query);
			} catch (PDOException $e) {		// Enabled by PDO::ERRMODE_EXCEPTION in constructor
				if ($debug) {echo $e;}
				$this->logError ();
				return false;
			}
		}
		
		# Return the result (either boolean, or the number of affected rows)
  		return $result;
	}
	
	
	# Function to get the data where only one item will be returned; this function has the same signature as getData
	# Uses prepared statement approach if a fourth parameter providing the placeholder values is supplied
	public function getOne ($query, $associative = false, $keyed = true, $preparedStatementValues = array ())
	{
		# Register this as the public entry point
		$this->errorLoggerEntryFunction = __FUNCTION__;
		
		# Hand off to the implementation
		$result = $this->_getOne ($query, $associative, $keyed, $preparedStatementValues);
		
		# Reset any custom error code and text
		$this->resetErrorCode ();
		
		# Return the result
		return $result;
	}
	
	
	# Implementation for getOne
	private function _getOne ($query, $associative = false, $keyed = true, $preparedStatementValues = array (), $expectMode = false)
	{
		# Get the data; NB this is not done in expect mode as that is handled explicitly below with a more customised error message
		$data = $this->_getData ($query, $associative, $keyed, $preparedStatementValues, array ());
		
		# Ensure that only one item is returned
		if (count ($data) > 1) {
			$this->logError ("Query produces more than one result, in {$this->errorLoggerEntryFunction}().");
			return NULL;
		}
		if (count ($data) !== 1) {
			if ($expectMode) {
				$this->logError ("Expected exactly one result, in {$this->errorLoggerEntryFunction}().");
			}
			return false;
		}
		
		# Return the data, taking the first (and now confirmed as the only) item; $data[0] would fail when using $associative
		foreach ($data as $keyOrIndex => $item) {
			return $item;
		}
	}
	
	
	# Return the value of the field column from the single-result query
	public function getOneField ($query, $field, $preparedStatementValues = array ())
	{
		# Register this as the public entry point
		$this->errorLoggerEntryFunction = __FUNCTION__;
		
		# Hand off to the implementation
		$result = $this->_getOneField ($query, $field, $preparedStatementValues);
		
		# Reset any custom error code and text
		$this->resetErrorCode ();
		
		# Return the result
		return $result;
	}
	
	
	# Implementation for getOneField
	private function _getOneField ($query, $field, $preparedStatementValues = array (), $expectMode = false)
	{
		# Get the result or end (returning NULL or false)
		if (!$result = $this->_getOne ($query, false, true, $preparedStatementValues, $expectMode)) {
			return $result;
		}
		
		# If the field doesn't exist, return false
		if (!isSet ($result[$field])) {
			$this->logError ("Field '{$field}' doesn't exist.");
			return false;
		}
		
		# Return the field value
		return $result[$field];
	}
	
	
	# Gets results from the query, returning false if there are none (never an empty array)
	public function expectData ($query)
	{
		# Register this as the public entry point
		$this->errorLoggerEntryFunction = __FUNCTION__;
		
		# Get the data or end; expectMode will have caused a logError to have been thrown
		if (!$result = $this->_getData ($query, false, true, array (), array (), $expectMode = true)) {
			return false;
		}
		
		# Reset any custom error code and text
		$this->resetErrorCode ();
    	
		# Return the result
		return $result;
	}
	
	
	# A single row of data from the query is expected and returned; otherwise false is returned (never NULL)
	public function expectOne ($query)
	{
		# Register this as the public entry point
		$this->errorLoggerEntryFunction = __FUNCTION__;
		
		# Get the data or end; expectMode will have caused a logError to have been thrown
		if (!$result = $this->_getOne ($query, false, true, array (), $expectMode = true)) {
			return false;
		}
   		
		# Reset any custom error code and text
		$this->resetErrorCode ();
		
		# Return the result
		return $result;
	}
	
	
	# A single field of data from the query is expected and returned; otherwise false is returned (never NULL)
	public function expectOneField ($query, $field, $preparedStatementValues = array ())
	{
		# Register this as the public entry point
		$this->errorLoggerEntryFunction = __FUNCTION__;
		
		# Get the data
		$result = $this->_getOneField ($query, $field, $preparedStatementValues, $expectMode = true);
    	
		# NULL is an error condition indicating that there was more than one result; expectMode will have caused a logError to have been thrown
		if (is_null ($result)) {
			return false;
		}
		
		# Reset any custom error code and text
		$this->resetErrorCode ();
		
		# Return the result
		return $result;
	}
	
	
	# Function to get the data where either (i) only one column per item will be returned, resulting in index => value, or (ii) two columns are returned, resulting in col1 => col2
	# Uses prepared statement approach if a third parameter providing the placeholder values is supplied
	public function getPairs ($query, $unique = false, $preparedStatementValues = array ())
	{
		# Get the data
		$data = $this->_getData ($query, false, $keyed = false, $preparedStatementValues);
		
		# Convert to pairs
		$pairs = $this->toPairs ($data, $unique);
		
		# Return the data
		return $pairs;
	}
	
	
	# Helper function to convert data to pairs; assumes that the values in each item are not associative
	private function toPairs ($data, $unique = false)
	{
		# Loop through each item in the data to allocate a key/value pair
		$pairs = array ();
		foreach ($data as $key => $item) {
			
			# If more than one item, use the first two in the list as the key and value
			if (count ($item) == 1) {
				$value = $item[0];
			} else {
				$key = $item[0];
				$value = $item[1];
			}
			
			# Trim the value
			$value = trim ($value);
			
			# Add to output data
			$pairs[$key] = $value;
		}
		
		# Unique the data if necessary; note that this is unlikely to be wanted if the main keys are associative
		if ($unique) {$pairs = array_unique ($pairs);}
		
		# Return the data
		return $pairs;
	}
	
	
	# Function to get data from an SQL query and return it as an array; $associative should be false or a string "{$database}.{$table}" (which reindexes the data to the field containing the unique key) or a supplied fieldname to avoid a SHOW FULL FIELDS lookup
	# Uses prepared statement approach if a fourth parameter providing the placeholder values is supplied
	public function getData ($query, $associative = false, $keyed = true, $preparedStatementValues = array (), $onlyFields = array ())
	{
		# Register this as the public entry point
		$this->errorLoggerEntryFunction = __FUNCTION__;
		
		# Hand off to the implementation
		$data = $this->_getData ($query, $associative, $keyed, $preparedStatementValues, $onlyFields);
		
		# Reset any custom error code and text
		$this->resetErrorCode ();
		
		# Return the data
		return $data;
	}
	
	
	# Implementation for getData
	private function _getData ($query, $associative = false, $keyed = true, $preparedStatementValues = array (), $onlyFields = array (), $expectMode = false)
	{
		# Global the query and any values
		$this->query = $query;
		$this->queryValues = $preparedStatementValues;
		
		# Create an empty array to hold the data
		$data = array ();
		
		# Set fetch mode
		$mode = ($keyed ? PDO::FETCH_ASSOC : PDO::FETCH_NUM);
		
		# If using prepared statements, prepare then execute
		$this->preparedStatement = NULL;	// Always clear to avoid the error() function returning results of a previous statement
		if ($preparedStatementValues) {
			
			# Execute the statement (ending if there is an error in the query or parameters)
			try {
				$this->preparedStatement = $this->connection->prepare ($query);
				$this->preparedStatement->execute ($preparedStatementValues);
			} catch (PDOException $e) {		// Enabled by PDO::ERRMODE_EXCEPTION in constructor
				$this->logError ();
				return $data;
			}
			
			# Fetch the data
			$this->preparedStatement->setFetchMode ($mode);
			$data = $this->preparedStatement->fetchAll ();
			
		} else {
			
			# Assign the query
			try {
				$statement = $this->connection->query ($query);
			} catch (PDOException $e) {		// Enabled by PDO::ERRMODE_EXCEPTION in constructor
				$this->logError ();
				return $data;
			}
			
			# Loop through each row and add the data to it
			$statement->setFetchMode ($mode);
			while ($row = $statement->fetch ()) {
				$data[] = $row;
			}
		}
		
		# Reassign the keys to being the unique field's name, in associative mode
		if ($associative) {
			
			# Get the unique field name, looking it up if supplied as 'database.table'; otherwise use the id directly
			if (strpos ($associative, '.') !== false) {
				list ($database, $table) = explode ('.', $associative, 2);
				$uniqueField = $this->getUniqueField ($database, $table);
			} else {
				$uniqueField = $associative;
			}
			
			# Return as non-keyed data if no unique field
			if (!$uniqueField) {
				$this->logError ();
				return $data;
			}
			
			# Re-key with the field name
			$newData = array ();
			foreach ($data as $key => $attributes) {
				#!# This causes offsets if the key is not amongst the fields requested
				$newData[$attributes[$uniqueField]] = $attributes;
			}
			
			# Entirely replace the dataset; doing on a key-by-key basis doesn't work because the auto-generated keys can clash with real id key names
			$data = $newData;
		}
		
		# Filter only to specified fields if required
		if ($onlyFields) {
			foreach ($data as $index => $record) {
				foreach ($record as $key => $value) {
					if (!in_array ($key, $onlyFields)) {
						unset ($data[$index][$key]);
					}
				}
			}
		}
		
		# In expect mode, if there is no result, treat that as an error case
		if ($expectMode) {
			if (!$data) {
				$this->logError ("Expected result(s), but none were obtained, in {$this->errorLoggerEntryFunction}().");
				return $data;
			}
		}
		
		# Return the array
		return $data;
	}
	
	
	# Function to export data served as a CSV, optimised to use low memory; this is a combination of database::getData() and csv::serve
	public function serveCsv ($query, $preparedStatementValues = array (), $filenameBase = 'data', $timestamp = true, $headerLabels = array (), $zipped = false /* false, or true (zip), or 'zip'/'gz') */, $saveToDirectory = false /* or full directory path, slash-terminated */, $includeHeaderRow = true, $chunksOf = 500)
	{
		# Global the query and any values
		$this->query = $query;
		$this->queryValues = $preparedStatementValues;
		
		# Execute the statement (ending if there is an error in the query or parameters)
		try {
			$this->preparedStatement = $this->connection->prepare ($query);
			if ($this->vendor == 'mysql') {
				$this->connection->setAttribute (PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
			}
			$this->preparedStatement->execute ($preparedStatementValues);
		} catch (PDOException $e) {		// Enabled by PDO::ERRMODE_EXCEPTION in constructor
			return false;
		}
		
		# Add a timestamp to the filename if required
		if ($timestamp) {
			$filenameBase .= '_savedAt' . date ('Ymd-His');
		}
		
		# Determine the directory save location
		$directory = ($saveToDirectory ? $saveToDirectory : sys_get_temp_dir () . '/');
		
		# Determine filename; the routine always writes to a file, even if this is subsequently removed, to avoid over-length strings (internal string size is max 2GB)
		$filename = $filenameBase . '.csv';
		$file = $directory . $filename;
		
		# Delete any existing file, e.g. from an improperly-terminated run
		if (is_file ($file)) {
			unlink ($file);
		}
		
		# Add CSV processing support
		require_once ('csv.php');
		
		# Set chunking state
		$data = array ();
		$i = 0;
		
		# Fetch the data
		$this->preparedStatement->setFetchMode (PDO::FETCH_ASSOC);
		while ($row = $this->preparedStatement->fetch ()) {
			$data[] = $row;
			$i++;
			
			# Add data periodically by processing the chunk when limit required
			if ($i == $chunksOf) {
				$csvChunk = csv::dataToCsv ($data, '', ',', $headerLabels, $includeHeaderRow);
				file_put_contents ($file, $csvChunk, FILE_APPEND);
				
				# Reset chunking state
				$data = array ();
				$i = 0;
				$includeHeaderRow = false;	// Only the first iteration should have headers
			}
		}
		
		# Add residual data to the CSV if any left over (which will usually happen, unless the amount of data is exactly divisible by $chunksOf
		if ($data) {
			$csvChunk = csv::dataToCsv ($data, '', ',', $headerLabels, $includeHeaderRow);
			file_put_contents ($file, $csvChunk, FILE_APPEND);
		}
		
		# If zipped, emit the data in a zip enclosure
		#!# Note that this leaves the original CSV file present, which may or may not be desirable
		if ($zipped) {
			$supportedFormats = array ('zip', 'gz');
			$format = (is_string ($zipped) && in_array ($zipped, $supportedFormats) ? $zipped : $supportedFormats[0]);	// Default to first, zip
			require_once ('application.php');
			application::createZip ($file, $filename, $saveToDirectory, $format);
			return;
		}
		
		# If required to save the file, leave the generated file in place, return at this point
		if ($saveToDirectory) {
			return $file;
		}
		
		# Publish, by sending a header and then echoing the data
		header ('Content-type: application/octet-stream');
		header ('Content-Disposition: attachment; filename="' . $filename . '"');
		readfile ($file);
		
		# Delete the file
		unlink ($file);
	}
	
	
	# Function to do getData via pagination
	public function getDataViaPagination ($query, $associative = false /* or string as "{$database}.{$table}" */, $keyed = true, $preparedStatementValues = array (), $onlyFields = array (), $paginationRecordsPerPage, $page = 1, $searchResultsMaximumLimit = false, $knownTotalAvailable = false)
	{
		# Trim the query to ensure that placeholder matching works consistently
		$query = trim ($query);
		
		# If the total is already known, use that
		if ($knownTotalAvailable) {
			$totalAvailable = $knownTotalAvailable;
		} else {
			
			# Prepare the counting query; use a negative lookahead to match the section between SELECT ... FROM - see http://stackoverflow.com/questions/406230
			#!# "ORDER BY generatedcolumn, ..." will cause a failure, but we cannot wipe out '/\s+ORDER\s+BY\s+.+$/isU' because a LIMIT clause may follow
			#!# TRIM(... FROM ...) in the SELECT clause will a failure
			$placeholders = array (
				'/^SELECT\s+(?!\s+FROM\s).+\s+FROM/isU' => 'SELECT COUNT(*) AS total FROM',
				# This works but isn't in use anywhere, so enable if/when needed with more testing '/^SELECT\s+DISTINCT\(([^)]+)\)\s+(?!\s+FROM ).+\s+FROM/' => 'SELECT COUNT(DISTINCT(\1)) AS total FROM',
			);
			$countingQuery = preg_replace (array_keys ($placeholders), array_values ($placeholders), $query);
			
			# If any named placeholders are not now in the counting query, remove them from the list
			$countingPreparedStatementValues = $preparedStatementValues;
			foreach ($countingPreparedStatementValues as $key => $value) {
				if (substr_count ($query, ':' . $key) && !substr_count ($countingQuery, ':' . $key)) {
					unset ($countingPreparedStatementValues[$key]);
				}
			}
			
			# Perform a count first
			$totalAvailable = $this->_getOneField ($countingQuery, 'total', $countingPreparedStatementValues);
		}
		
		# Enforce a maximum limit if required, by overwriting the total available, which the pagination mechanism will automatically adjust to
		$actualMatchesReachedMaximum = false;
		if ($searchResultsMaximumLimit) {
			if ($totalAvailable > $searchResultsMaximumLimit) {
				$actualMatchesReachedMaximum = $totalAvailable;	// Assign the number of the actual total available, which will evaluate to true
				$totalAvailable = $searchResultsMaximumLimit;
			}
		}
		
		# Get the requested page and calculate the pagination
		require_once ('pagination.php');
		if (is_int ($page)) {$page = (string) $page;}	// If page is actually an int, ctype_digit would not properly detect it as numeric
		$requestedPage = (ctype_digit ($page) ? $page : 1);
		list ($totalPages, $offset, $items, $limitPerPage, $page) = pagination::getPagerData ($totalAvailable, $paginationRecordsPerPage, $requestedPage);
		
		# Now construct the main query
		$placeholders = array (
			'/;$/' => " LIMIT {$offset}, {$limitPerPage};",
		);
		$dataQuery = preg_replace (array_keys ($placeholders), array_values ($placeholders), $query);
		
		# Get the data
		$data = $this->_getData ($dataQuery, $associative, $keyed, $preparedStatementValues, $onlyFields);
		
		# Return the data and metadata
		return array ($data, (int) $totalAvailable, $totalPages, $page, $actualMatchesReachedMaximum);
	}
	
	
	# Function to count the number of records
	public function getTotal ($database, $table, $restrictionSql = '')
	{
		# Check that the table exists
		$tables = $this->getTables ($database);
		if (!in_array ($table, $tables)) {return false;}
		
		# Get the total
		#!# 'WHERE' should be within this here, not part of the supplied parameter
		$query = "SELECT COUNT(*) AS total FROM `{$database}`.`{$table}` {$restrictionSql};";
		$data = $this->_getOne ($query);
		
		# Return the value
		return $data['total'];
	}
	
	
	# Function to get fields
	public function getFields ($database, $table, $addSimpleType = false, $matchingRegexpNoForwardSlashes = false, $asTotal = false, $excludeAuto = false)
	{
		# If the raw fields list is already in the fields cache, use that to avoid a pointless SHOW FULL FIELDS lookup
		if (isSet ($this->fieldsCache[$database]) && isSet ($this->fieldsCache[$database][$table])) {
			$data = $this->fieldsCache[$database][$table];
		} else {
			
			# Cache the global query and its values, if either exist, so that they can be reinstated when this function is called by another function internally
			$cachedQuery = ($this->query ? $this->query : NULL);
			$cachedQueryValues = (!is_null ($this->queryValues) ? $this->queryValues : NULL);
			
			# Get the data
			if ($this->vendor == 'sqlite') {
				$query = "PRAGMA {$database}.table_info({$table});";
			} else {
				$query = "SHOW FULL FIELDS FROM `{$database}`.`{$table}`;";
			}
			$data = $this->_getData ($query);
			
			# Restablish the catched query and its values if there is one
			if (!is_null ($cachedQuery)) {$this->query = $cachedQuery;}
			if (!is_null ($cachedQuery)) {$this->queryValues = $cachedQueryValues;}
			
			# Add the result to the fields cache, in case there is another request for getFields for this database table
			$this->fieldsCache[$database][$table] = $data;
		}
		
		# For SQLite, map the structure to emulate the MySQL format
		if ($this->vendor == 'sqlite') {
			$data = $this->sqliteTableStructureEmulation ($data, $table);
		}
		
		# Convert the field name to be the key name
		$fields = array ();
		foreach ($data as $key => $attributes) {
			$fields[$attributes['Field']] = $attributes;
		}
		
		# Add a simple type description if required
		if ($addSimpleType) {
			foreach ($data as $key => $attributes) {
				$fields[$attributes['Field']]['_type'] = $this->simpleType ($attributes['Type']);
			}
		}
		
		# Expand ENUM/SET field values
		foreach ($data as $key => $attributes) {
			if (preg_match ('/^(enum|set)\(\'(.+)\'\)$/i', $attributes['Type'], $matches)) {
				$fields[$attributes['Field']]['_values'] = explode ("','", $matches[2]);
			} else {
				$fields[$attributes['Field']]['_values'] = NULL;
			}
		}
		
		# Filter by regexp if required
		if ($matchingRegexpNoForwardSlashes) {
			foreach ($fields as $field => $attributes) {
				if (!preg_match ("/{$matchingRegexpNoForwardSlashes}/", $field)) {
					unset ($fields[$field]);
				}
			}
		}
		
		# Exclude automatic fields if required
		if ($excludeAuto) {
			foreach ($fields as $field => $attributes) {
				if ($attributes['Extra'] == 'auto_increment' || $attributes['Default'] == 'CURRENT_TIMESTAMP') {
					unset ($fields[$field]);
				}
			}
		}
		
		# If returning as a total, convert to a count
		if ($asTotal) {
			$fields = count ($fields);
		}
		
		# Return the result
		return $fields;
	}
	
	
	# Function to emulate an SQLite table structure in MySQL format
	private function sqliteTableStructureEmulation ($data, $table)
	{
		# Obtain the comments and whether the field is unique by obtaining the original CREATE TABLE SQL
		$ddlQuery = "SELECT name, sql FROM sqlite_master WHERE type='table' AND name='{$table}' ORDER BY name;";
		$originalCreateTableQuery = $this->_getOneField ($ddlQuery, 'sql');
		$lines = explode ("\n", trim ($originalCreateTableQuery));
		$comments = array ();
		$unique = array ();
		foreach ($lines as $id => $line) {
			$line = str_replace ('`', '', trim ($line));
			if (preg_match ('/^([^\s]+)\s.+--\s(.+)$/', $line, $matches)) {
				$comments[$matches[1]] = $matches[2];
			}
			if (substr_count ($line, 'UNIQUE')) {
				$unique[$matches[1]] = true;
			}
		}
		
		# Map the structure, replacing the SQLite
		foreach ($data as $index => $field) {
			$data[$index] = array (
				'Field'			=> $field['name'],
				'Type'			=> $field['type'],
				'Collation'		=> NULL,		// No support for this in SQLite
				'Null'			=> !$field['notnull'],
				'Key'			=> ($field['pk'] == '1' ? 'PRI' : (isSet ($unique[$field['name']]) ? 'UNI' : false)),
				'Default'		=> $field['dflt_value'],
				'Extra'			=> ($field['type'] == 'INTEGER' && $field['pk'] == '1' ? 'auto_increment' : NULL),
				'Privileges'	=> NULL,		// No support for this in SQLite
				'Comment'		=> $comments[$field['name']],
			);
		}
		
		# Return the data
		return $data;
	}
	
	
	# Function to detect values that should not be quoted
	private function valueIsFunctionCall ($string)
	{
		# Normalise the string
		$string = strtoupper ($string);
		$string = str_replace (' (', '(', $string);
		
		# Detect keywords
		if ($string == 'NOW()') {return true;}
		if (preg_match ('/^(ST_)?(GEOMCOLL|GEOMETRYCOLLECTION|GEOM|GEOMETRY|LINE|LINESTRING|MLINE|MULTILINESTRING|MPOINT|MULTIPOINT|MPOLY|MULTIPOLYGON|POINT|POLY|POLYGON)FROMTEXT\(/', $string)) {return true;}
		// Add more here
		
		# Treat as standard string if not detected
		return false;
	}
	
	
	# Function to determine if the data is hierarchical
	public function isHierarchical ($database, $table)
	{
		# Determine if there is a parentId field and return whether it is present
		$fields = $this->getFields ($database, $table);
		return (isSet ($fields['parentId']));
	}
	
	
	# Function to create a simple type for fields
	private function simpleType ($type)
	{
		# Detect the type and give a simplified description of it
		switch (true) {
			case preg_match ('/^varchar/', $type):
				return 'string';
			case preg_match ('/text/', $type):
				return 'text';
			case preg_match ('/^(float|double|int)/', $type):
				return 'numeric';
			case preg_match ('/^(enum|set)/', $type):
				return 'list';
			case preg_match ('/^(date|year)/', $type):
				return 'date';
		}
		
		# Otherwise pass through the original
		return $type;
	}
	
	
	# Function to get the unique field name
	public function getUniqueField ($database, $table, $fields = false)
	{
		# Get the fields if not already supplied
		if (!$fields) {$fields = $this->getFields ($database, $table);}
		
		# Loop through to find the unique one
		foreach ($fields as $field => $attributes) {
			if ($attributes['Key'] == 'PRI') {
				return $field;
			}
		}
		
		# Otherwise return false, indicating no unique field
		return false;
	}
	
	
	# Function to get field names
	public function getFieldNames ($database, $table, $fields = false, $matchingRegexpNoForwardSlashes = false, $excludeAuto = false)
	{
		# Get the fields if not already supplied
		if (!$fields) {$fields = $this->getFields ($database, $table, false, $matchingRegexpNoForwardSlashes, false, $excludeAuto);}
		
		#!# Bug: $matchingRegexpNoForwardSlashes is not used if $fields is supplied
		
		# Get the array keys of the fields
		return array_keys ($fields);
	}
	
	
	# Function to get field descriptions as a simple associative array
	public function getHeadings ($database, $table, $fields = false, $useFieldnameIfEmpty = true, $commentsAsHeadings = true, $excludeAuto = false)
	{
		# Get the fields if not already supplied
		if (!$fields) {$fields = $this->getFields ($database, $table, false, false, false, $excludeAuto);}
		
		# Rearrange the data
		$headings = array ();
		foreach ($fields as $field => $attributes) {
			$headings[$field] = ((((empty ($attributes['Comment']) && $useFieldnameIfEmpty)) || !$commentsAsHeadings) ? $field : $attributes['Comment']);
		}
		
		# Return the headings
		return $headings;
	}
	
	
	# Function to obtain a list of databases on the server
	public function getDatabases ($omitReserved = array ('cluster', 'information_schema', 'mysql'))
	{
		# Get the data
		$query = "SHOW DATABASES;";
		$data = $this->_getData ($query);
		
		# Sort the list
		if ($data) {sort ($data);}
		
		# Rearrange
		$databases = array ();
		foreach ($data as $index => $attributes) {
			if ($omitReserved && in_array ($attributes['Database'], $omitReserved)) {continue;}
			$databases[] = $attributes['Database'];
		}
		
		# Return the data
		return $databases;
	}
	
	
	# Function to return whether a database (or match using %) exists (for which the caller has privileges)
	public function databaseExists ($database)
	{
		# Register this as the public entry point
		$this->errorLoggerEntryFunction = __FUNCTION__;
		
		# Hand off to the implementation
		$result = $this->_databaseExists ($database);
		
		# Reset any custom error code and text
		$this->resetErrorCode ();
    	
		# Return the result
		return $result;
	}
	
	
	# Implementation for databaseExists
	private function _databaseExists ($database)
	{
		# Get the data; note that this uses getData rather than getOne - getOne would return false if there was more than one match when using %; note that the caller will only be able to see those databases for which it has some kind of privilege, unless it has the global SHOW DATABASES privilege
		$query = "SHOW DATABASES LIKE :database;";
		$preparedStatementValues = array ('database' => $database);
		$data = $this->_getData ($query, false, true, $preparedStatementValues);
		
		# Return boolean result of whether there was a result (or more than one match)
		return (bool) $data;
	}
	
	
	# Function to obtain a list of tables in a database
	# $matchingRegexp enables filtering, e.g. '/tablename([0-9]+)/' ; if there is a capture (...) within this, then that will be used for the keys
	public function getTables ($database, $matchingRegexp = false)
	{
		# Get the data
		$query = "SHOW TABLES FROM `{$database}`;";
		$data = $this->_getData ($query);
		
		# Rearrange
		$tables = array ();
		foreach ($data as $index => $attributes) {
			$tables[] = $attributes["Tables_in_{$database}"];
		}
		
		# If a regexp is supplied, filter
		if ($matchingRegexp) {
			$tablesRaw = $tables;
			$tables = array ();
			foreach ($tablesRaw as $index => $table) {
				if (preg_match ($matchingRegexp, $table, $matches)) {
					if (isSet ($matches[1])) {	// If a capture is defined, use that as the key
						$key = $matches[1];
						$tables[$key] = $table;
					} else {
						$tables[] = $table;		// Auto-key
					}
				}
			}
		}
		
		# Return the data
		return $tables;
	}
	
	
	# Function to return whether a table (or match using %) in a specified database (NB matches not supported) exists (for which the caller has privileges)
	public function tableExists ($database, $table)
	{
		# Register this as the public entry point
		$this->errorLoggerEntryFunction = __FUNCTION__;
		
		# Disallow wildcards in the database specification
		if (substr_count ($database, '%') || substr_count ($database, '_')) {
			$this->resetErrorCode ();
			return false;
		}
		
		# Ensure the specified database exists; this is necessary to avoid SQL injection attacks in the query below
		if (!$this->_databaseExists ($database)) {
			$this->resetErrorCode ();
			return false;
		}
		
		# Get the data; note that this uses getData rather than getOne - getOne would return false if there was more than one match when using %; note that the caller will only be able to see those databases for which it has some kind of privilege, unless it has the global SHOW DATABASES privilege
		$query = "SHOW TABLES FROM {$database} LIKE :table;";
		$preparedStatementValues = array ('table' => $table);
		$data = $this->_getData ($query, false, true, $preparedStatementValues);
		
		# Reset any custom error code and text
		$this->resetErrorCode ();
    	
		# Return boolean result of whether there was a result (or more than one match)
		return (bool) $data;
	}
	
	
	# Function to get the ID generated from the previous insert operation
	#!# Rename this for consistency
	#!# Emulate away the problem that, in the case of an insertMany, MySQL returns the *first* automatically-generated ID! - see http://dev.mysql.com/doc/refman/5.1/en/mysql-insert-id.html
	public function getLatestId ()
	{
		# Return the latest ID
		#!# Does this need exception handling?
		return $this->connection->lastInsertId ();
	}
	
	
	# Function to clean data
	public function escape ($uncleanData, $cleanKeys = true)
	{
		# End if no data
		if (empty ($uncleanData)) {return $uncleanData;}
		
		# If the data is an string, return it directly
		if (is_string ($uncleanData)) {
			return addslashes ($uncleanData);
		}
		
		# Loop through the data
		$data = array ();
		foreach ($uncleanData as $key => $value) {
			if ($cleanKeys) {$key = $this->escape ($key);}
			$data[$key] = $this->escape ($value);
		}
		
		# Return the data
		return $data;
	}
	
	
	# Function to deal with quotation, i.e. escaping AND adding quotation marks around the item
	/* private */ public function quote ($string)
	{
		# Strip slashes if necessary
		if (get_magic_quotes_gpc ()) {
			$string = stripslashes ($string);
		}
		
		# Special case a timestamp indication as unquoted SQL
		if ($string == 'NOW()') {
			return $string;
		}
		
		# Quote the string by calling the PDO quoting method
		$string = $this->connection->quote ($string);
		
		# Undo (unwanted automatic) backlash quoting in PDO::quote, i.e replace \\ with \ in the string; see discussion at http://www.bitpapers.com/2012/03/php-escaping-quotes.html
		$string = str_replace ('\\\\', '\\', $string);
		
		# Return the quoted string
		return $string;
	}
	
	
	# Function to construct and execute a SELECT statement
	public function select ($database, $table, $conditions = array (), $columns = array (), $associative = true, $orderBy = false, $limit = false, $keyed = true, $like = false /* or true or array of fields */)
	{
		# Construct the WHERE clause
		$where = '';
		if ($conditions) {
			$where = array ();
			if (is_array ($conditions)) {
				foreach ($conditions as $key => $value) {
					if ($value === NULL) {		// Has to be set with a real NULL value, i.e. using $conditions['keyname'] = NULL;
						$where[] = '`' . $key . '`' . ' IS NULL';
						unset ($conditions[$key]);	// Remove the original placeholder as that will never be used, and contains an array
					} else if (is_array ($value)) {
						$i = 0;
						$conditionsThisGroup = array ();
						foreach ($value as $valueItem) {
							$valuesKey = $key . '_' . $i++;	// e.g. id_0, id_1, etc.; a numeric index is created as the values list might be associative with keys containing invalid characters
							$conditions[$valuesKey] = $valueItem;
							$conditionsThisGroup[$valuesKey] = $valueItem;
						}
						unset ($conditions[$key]);	// Remove the original placeholder as that will never be used, and contains an array
						$where[] = '`' . $key . '`' . ' IN(:' . implode (', :', array_keys ($conditionsThisGroup)) . ')';
					} else {
						$useLike = ($like === true || (is_array ($like) && in_array ($key, $like)));
						$operator = ($useLike ? 'LIKE' : '=');
						$where[] = ($this->strictWhere ? 'BINARY ' : '') . '`' . $key . '`' . " {$operator} :" . $key;
					}
				}
			} else if (is_string ($conditions)) {
				if (strlen ($conditions)) {
					$where[] = $conditions;
					$conditions = array ();	// Remove these, as there are no prepared statement values
				}
			}
			if ($where) {
				$where = ' WHERE ' . implode (' AND ', $where);
			} else {
				$where = '';
			}
		}
		
		# Construct the columns part; if the key is numeric, assume it's not a key=>value pair, but that the value is the fieldname
		$what = '*';
		if ($columns) {
			$what = array ();
			if (is_array ($columns)) {
				foreach ($columns as $key => $value) {
					if (is_numeric ($key)) {
						$what[] = $value;
					} else {
						$what[] = "{$key} AS {$value}";
					}
				}
			} else {	// Currently assumed to be a string if it's not an array
				$what[] = $columns;
			}
			$what = implode (',', $what);
		}
		
		# Construct the ordering
		$orderBy = ($orderBy ? " ORDER BY {$orderBy}" : '');
		
		# Construct the limit
		$limit = ($limit ? " LIMIT {$limit}" : '');
		
		# Prepare the statement
		$query = "SELECT {$what} FROM `{$database}`.`{$table}`{$where}{$orderBy}{$limit};\n";
		
		# Get the data
		$data = $this->_getData ($query, ($associative ? "{$database}.{$table}" : false), $keyed, $conditions);
		
		# Return the data
		return $data;
	}
	
	
	# Function to select the data where only one item will be returned (as per getOne); this function has the same signature as select, except for the default on associative
	public function selectOne ($database, $table, $conditions = array (), $columns = array (), $associative_ArgumentIgnored = false, $orderBy = false, $limit = false)
	{
		# Get the data
		$data = $this->select ($database, $table, $conditions, $columns, false, $orderBy, $limit);
		
		# Ensure that only one item is returned
		if (count ($data) > 1) {return NULL;}
		if (count ($data) !== 1) {return false;}
		
		# Return the data
		#!# This could be unset if it's associative
		#!# http://bugs.mysql.com/36824 could result in a value slipping through that is not strictly matched - see also strictWhere
		return $data[0];
	}
	
	
	# Function to select the data where only one field of item will be returned (as per getOneField); this function has the same signature as selectOne, except for the default on associative
	public function selectOneField ($database, $table, $field, $conditions = array (), $columns = array (), $associative_ArgumentIgnored = false, $orderBy = false, $limit = false)
	{
		# Get the data
		$data = $this->selectOne ($database, $table, $conditions, $columns, false, $orderBy, $limit);
		
		# End if no data
		if (!$data) {return false;}
		
		# End if field not present
		if (!array_key_exists ($field, $data)) {return false;}
		
		# Return the data
		return $data[$field];
	}
	
	
	# Function to select data and return as pairs
	public function selectPairs ($database, $table, $conditions = array (), $columns = array (), $associative = true, $orderBy = false, $limit = false)
	{
		# Get the data, unkeyed (so that each record contains array(0=>value,1=>2)) (which therefore requires associative=false
		$associative = false;
		$data = $this->select ($database, $table, $conditions, $columns, $associative, $orderBy, $limit, $keyed = false);
		
		# Convert to pairs
		$pairs = $this->toPairs ($data);
		
		# Return the data
		return $pairs;
	}
	
	
	# Function to construct and execute an INSERT statement
	public function insert ($database, $table, $data, $onDuplicateKeyUpdate = false, $emptyToNull = true, $safe = false, $showErrors = false, $statement = 'INSERT')
	{
		# Ensure the data is an array and that there is data
		if (!is_array ($data) || !$data) {return false;}
		
		# Assemble the field names
		$fields = '`' . implode ('`,`', array_keys ($data)) . '`';
		
		# Assemble the values
		$preparedValuePlaceholders = array ();
		foreach ($data as $key => $value) {
			if ($emptyToNull && ($data[$key] === '')) {$data[$key] = NULL;}	// Convert empty to NULL if required
			if ($this->valueIsFunctionCall ($data[$key])) {	// Special handling for keywords, which are not quoted
				$preparedValuePlaceholders[] = $data[$key];	// State the value directly rather than use a placeholder
				unset ($data[$key]);
				continue;
			}
			$preparedValuePlaceholders[] = ':' . $key;
		}
		$preparedValuePlaceholders = implode (', ', $preparedValuePlaceholders);
		
		# Handle ON DUPLICATE KEY UPDATE support
		$onDuplicateKeyUpdate = $this->onDuplicateKeyUpdate ($onDuplicateKeyUpdate, $data);
		
		# Assemble the query
		$query = "{$statement} INTO `{$database}`.`{$table}` ({$fields}) VALUES ({$preparedValuePlaceholders}){$onDuplicateKeyUpdate};\n";
		
		# In safe mode, only show the query
		if ($safe) {
			echo $query . "<br />";
			return true;
		}
		
		# Execute the query
		$rows = $this->_execute ($query, $data, $showErrors);
		
		# Determine the result
		$result = ($rows !== false);
		
		# Log the change
		$this->logChange ($result, true);
		
		# Return the result
		return $result;
	}
	
	
	# Function to implement the non-standard SQL 'REPLACE INTO' statement
	public function replace ($database, $table, $data, /* Ignored: */ $ignored_OnDuplicateKeyUpdate = false, $emptyToNull = true, $safe = false, $showErrors = false)
	{
		# Limit to specific vendors
		switch ($this->vendor) {
			case 'mysql':
				$replaceStatement = 'REPLACE';
				break;
			case 'sqlite':
				$replaceStatement = 'REPLACE';	// 'INSERT OR REPLACE' is the SQLite standard, but 'REPLACE' also works; see: http://stackoverflow.com/a/690679/180733
				break;
			default:
				// Return false, as will never succeed
				return false;
		}
		
		# Delegate to insert() as the behaviour is all the same
		return $this->insert ($database, $table, $data, false, $emptyToNull, $safe, $showErrors, $replaceStatement);
	}
	
	
	# Processing of the (non-standard SQL) 'ON DUPLICATE KEY UPDATE' clause - see: http://dev.mysql.com/doc/refman/5.1/en/insert-on-duplicate.html
	private function onDuplicateKeyUpdate ($onDuplicateKeyUpdate, $data)
	{
		# End if not required
		if (!$onDuplicateKeyUpdate) {return '';}
		
		# If boolean true (rather than a string), compile the supplied data to a string first
		if ($onDuplicateKeyUpdate === true) {
			foreach ($data as $key => $value) {
				$clauses[] = "`{$key}`=VALUES(`{$key}`)";
			}
			$onDuplicateKeyUpdate = implode (',', $clauses);
		}
		
		# Assemble the string
		$sqlString = ' ON DUPLICATE KEY UPDATE ' . $onDuplicateKeyUpdate;
		
		# Result
		return $sqlString;
	}
	
	
	# Function to construct and execute an INSERT statement containing many items
	public function insertMany ($database, $table, $dataSet, $chunking = false, $onDuplicateKeyUpdate = false, $emptyToNull = true, $safe = false, $showErrors = false, $statement = 'INSERT')
	{
		# Ensure the data is an array and that there is data
		if (!is_array ($dataSet) || !$dataSet) {return false;}
		
		# Determine the number of fields in the data by checking against the first item in the dataset
		require_once ('application.php');
		if (!$fields = application::arrayFieldsConsistent ($dataSet)) {
			#!# This needs to set an error so that a subsequent ->error() call shows useful information
			return false;
		}
		
		# Assemble the field names
		$fields = '`' . implode ('`,`', $fields) . '`';
		
		# Chunk the records if required; if not, the entire set will be put into a single container
		$dataSetChunked = array_chunk ($dataSet, ($chunking ? $chunking : count ($dataSet)), true);
		
		# Loop through each chunk (which may be a single chunk containing the whole dataset if chunking is disabled)
		foreach ($dataSetChunked as $dataSet) {
			
			# Loop through each set of data
			$valuesPreparedSet = array ();
			$preparedStatementValues = array ();
			foreach ($dataSet as $index => $data) {
				
				# Ensure the data is an array and that there is data
				if (!is_array ($data) || !$data) {return false;}
				
				# Assemble the values
				$preparedValuePlaceholders = array ();
				foreach ($data as $key => $value) {
					if ($emptyToNull && ($data[$key] === '')) {$data[$key] = NULL;}	// Convert empty to NULL if required
					if ($this->valueIsFunctionCall ($data[$key])) {	// Special handling for keywords, which are not quoted
						$preparedValuePlaceholders[] = $data[$key];	// State the value directly rather than use a placeholder
						unset ($data[$key]);
						continue;
					}
					$placeholder = ":{$index}_{$key}";
					$preparedValuePlaceholders[] = ' ' . $placeholder;
					$preparedStatementValues[$placeholder] = $data[$key];
				}
				$valuesPreparedSet[$index] = implode (',', $preparedValuePlaceholders);
			}
			
			# Handle ON DUPLICATE KEY UPDATE support
			$dataSetValues = array_values ($dataSet);	// This temp has to be used to avoid "Strict Standards: Only variables should be passed by reference"
			$firstData = array_shift ($dataSetValues);
			$onDuplicateKeyUpdateThisChunk = $this->onDuplicateKeyUpdate ($onDuplicateKeyUpdate, $firstData);
			
			# Assemble the query
			$query = "{$statement} INTO `{$database}`.`{$table}` ({$fields}) VALUES (" . implode ('),(', $valuesPreparedSet) . "){$onDuplicateKeyUpdateThisChunk};\n";
			
			# Prevent submission of over-long queries
			if ($maxLength = $this->getVariable ('max_allowed_packet')) {
				if (strlen ($query) > (int) $maxLength) {
					return false;
				}
			}
			
			# In safe mode, only show the query
			if ($safe) {
				echo $query . "<br />";
				return true;
			}
			
			# Execute the query
			$rows = $this->_execute ($query, $preparedStatementValues, $showErrors);
			
			#!# Needs to report failure if one execution in a chunk failed; detect using $this->error () perhaps
			// application::dumpData ($this->error ());
			
			# Determine the result
			$result = ($rows !== false);
			
			# Log the change
			$this->logChange ($result, true);
		}
		
		# Return the (last) result
		return $result;
	}
	
	
	# Function to implement the non-standard SQL 'REPLACE INTO' statement for many replace-inserts
	public function replaceMany ($database, $table, $dataSet, $chunking = false, /* Ignored: */ $ignored_OnDuplicateKeyUpdate = false, $emptyToNull = true, $safe = false, $showErrors = false)
	{
		# Limit to specific vendors
		switch ($this->vendor) {
			case 'mysql':
				$replaceStatement = 'REPLACE';
				break;
			case 'sqlite':
				$replaceStatement = 'REPLACE';	// 'INSERT OR REPLACE' is the SQLite standard, but 'REPLACE' also works; see: http://stackoverflow.com/a/690679/180733
				break;
			default:
				// Return false, as will never succeed
				return false;
		}
		
		# Delegate to insertMany() as the behaviour is all the same
		return $this->insertMany ($database, $table, $dataSet, $chunking, false, $emptyToNull, $safe, $showErrors, $replaceStatement);
	}
	
	
	# Function to construct and execute an UPDATE statement
	public function update ($database, $table, $data, $conditions = array (), $emptyToNull = true, $safe = false, $returnRowCount = false)
	{
		# Ensure the data is an array and that there is data
		if (!is_array ($data) || !$data) {return false;}
		
		# Start an array of placeholder=>value data that will contain both values and conditions
		$dataUniqued = array ();
		
		# Assemble the pairs
		$preparedValueUpdates = array ();
		foreach ($data as $key => $value) {
			
			# Add the data
			if ($emptyToNull && ($data[$key] === '')) {$data[$key] = NULL;}	// Convert empty to NULL if required
			if ($data[$key] == 'NOW()') {	// Special handling for keywords, which are not quoted
				$preparedValueUpdates[] = "`{$key}`= " . $data[$key];
				unset ($data[$key]);
				continue;
			}
			$placeholder = "data_" . $key;	// The prefix ensures namespaced uniqueness within $dataUniqued
			$preparedValueUpdates[] = "`{$key}`= :" . $placeholder;
			
			# Save the data using the new placeholder
			$dataUniqued[$placeholder] = $data[$key];
		}
		$preparedValueUpdates = implode (',', $preparedValueUpdates);
		
		# Construct the WHERE clause
		$where = '';
		if ($conditions) {
			$where = array ();
			foreach ($conditions as $key => $value) {
				$placeholder = 'conditions_' . $key;	// The prefix ensures namespaced uniqueness within $dataUniqued
				$where[] = ($this->strictWhere ? 'BINARY ' : '') . '`' . $key . '` = :' . $placeholder;
				
				# Save the data using the new placeholder
				$dataUniqued[$placeholder] = $value;
			}
			$where = ' WHERE ' . implode (' AND ', $where);
		}
		
		# Assemble the query
		$query = "UPDATE `{$database}`.`{$table}` SET {$preparedValueUpdates}{$where};\n";
		
		# In safe mode, only show the query
		if ($safe) {
			echo $query . "<br />";
			return true;
		}
		
		# Execute the query
		$rows = $this->_execute ($query, $dataUniqued);
		
		# Determine the result
		$result = ($rows !== false);
		
		# Log the change
		$this->logChange ($result);
		
		# Return the row count instead if required
		if ($returnRowCount) {
			$result = $rows;
		}
		
		# Return the result
		return $result;
	}
	
	
	# Function to update many rows at once; see this good overview: http://www.karlrixon.co.uk/writing/update-multiple-rows-with-different-values-and-a-single-sql-query/
	public function updateMany ($database, $table, $dataSet, $chunking = false, $uniqueField = false, $emptyToNull = true, $safe = false, $showErrors = false)
	{
		# Ensure the data is an array and that there is data
		if (!is_array ($dataSet) || !$dataSet) {return false;}
		
		# Determine the number of fields in the data by checking against the first item in the dataset
		require_once ('application.php');
		if (!$fields = application::arrayFieldsConsistent ($dataSet)) {return false;}
		
		# Get the key field if not explicitly supplied
		if (!$uniqueField) {
			$uniqueField = $this->getUniqueField ($database, $table);
		}
		
		# Chunk the records if required; if not, the entire set will be put into a single container
		$dataSetChunked = array_chunk ($dataSet, ($chunking ? $chunking : count ($dataSet)), true);
		
		# Loop through each chunk (which may be a single chunk containing the whole dataset if chunking is disabled)
		foreach ($dataSetChunked as $dataSet) {
			
			# Build the inner "SET %fieldname = CASE id WHEN foo THEN bar WHEN ..." statements, field by field
			$querySetCaseBlocks = array ();
			$preparedStatementValues = array ();
			$keyPlaceholders = array ();
			foreach ($fields as $index => $field) {
				$querySetCaseBlocks[$field]  = "`{$field}` = CASE id";
				$keyPlaceholderId = 0;	// These can be reused
				foreach ($dataSet as $key => $data) {
					$value = $data[$field];
					
					# Create a placeholder for the key
					$keyPlaceholder = "k{$keyPlaceholderId}";	// Uses numeric values to be sure it is valid
					$preparedStatementValues[$keyPlaceholder] = $key;
					
					# Register this data key in the keys list; this is done only once, rather than pointlessly for each field
					if ($index == 0) {
						$keyPlaceholders[$key] = ':' . $keyPlaceholder;
					}
					
					# Create a placeholder (or, for special keywords, a string)
					$valuePlaceholder = "v{$index}_{$keyPlaceholderId}";	// Uses numeric values to be sure it is valid
					if ($emptyToNull && ($value === '')) {	// Convert empty to NULL if required
						$value = 'NULL';	// i.e. an (unquoted) 'real' SQL NULL
						$valuePlaceholder = false;
					}
					if ($value == 'NOW()') {	// Special handling for keywords, which are not quoted
						$valuePlaceholder = false;
					}
					if ($valuePlaceholder) {
						$preparedStatementValues[$valuePlaceholder] = $value;
					}
					
					# Add the component
					$querySetCaseBlocks[$field] .= "\n\t\tWHEN :k{$keyPlaceholderId} THEN " . ($valuePlaceholder ? ":{$valuePlaceholder}" : $value);
					
					# Advance counters
					$keyPlaceholderId++;
				}
				$querySetCaseBlocks[$field] .= "\n\tEND";
			}
			
			# Assemble the overall query
			$query  = "UPDATE `{$database}`.`{$table}`";
			$query .= "\n\tSET " . implode (",\n\t", $querySetCaseBlocks);
			$query .= "\nWHERE `{$uniqueField}` IN (" . implode (', ', $keyPlaceholders) . ')';
			
			# Prevent submission of over-long queries
			if ($maxLength = $this->getVariable ('max_allowed_packet')) {
				if (strlen ($query) > (int) $maxLength) {
					return false;
				}
			}
			
			# In safe mode, only show the query
			if ($safe) {
				echo '<pre>' . $query . "</pre><br />";
				return true;
			}
			
			# Execute the query
			$rows = $this->_execute ($query, $preparedStatementValues, $showErrors);
			
			# Determine the result
			$result = ($rows !== false);
			
			# Log the change
			$this->logChange ($result);
		}
		
		# Return the (last) result
		return $result;
	}
	
	
	# Function to delete data
	public function delete ($database, $table, $conditions, $limit = false)
	{
		# Ensure the data is an array and that there is data
		if (!is_array ($conditions) || !$conditions) {return false;}
		
		# Construct the WHERE clause
		$where = '';
		if ($conditions) {
			$where = array ();
			foreach ($conditions as $key => $value) {
				$where[] = ($this->strictWhere ? 'BINARY ' : '') . '`' . $key . '`' . ' = :' . $key;
			}
			$where = ' WHERE ' . implode (' AND ', $where);
		}
		
		# Determine any limit
		$limit = ($limit ? " LIMIT {$limit}" : '');
		
		# Assemble the query
		$query = "DELETE FROM `{$database}`.`{$table}`{$where}{$limit};\n";
		
		# Execute the query
		#!# Currently unable to distinguish syntax error vs nothing to delete
		$result = $this->_execute ($query, $conditions);
		
		# Log the change
		$this->logChange ($result);
		
		# Return the result
		return $result;
	}
	
	
	# Function to delete a set of IDs
	public function deleteIds ($database, $table, $values, $field = 'id')
	{
		# End if no items
		if (!$values || !is_array ($values)) {return false;}
		
		# Create placeholders
		$placeholders = array ();
		$placeholderValues = array ();
		$i = 0;
		foreach ($values as $key => $value) {
			$placeholderName = "p{$i}";
			$placeholders[$i] = ':' . $placeholderName;
			$placeholderValues[$placeholderName] = $value;
			$i++;
		}
		
		# Assemble the query
		$query = "DELETE FROM `{$database}`.`{$table}` WHERE " . ($this->strictWhere ? 'BINARY ' : '') . "`{$field}` IN (" . implode (', ', $placeholders) . ");";
		
		# Execute the query
		$rows = $this->_execute ($query, $placeholderValues);
		
		# Log the change
		$this->logChange ($rows);
		
		# Return the number of affected rows
		return $rows;
	}
	
	
	# Function to create a table from a list of fields
	public function createTable ($database, $table, $fields, $ifNotExists = true, $type = 'InnoDB')
	{
		# Construct the list of fields
		$fieldsSql = array ();
		foreach ($fields as $fieldname => $field) {	// where $field contains the specification, either as a string like VARCHAR(255) NOT NULL, or an array containing those parts
			
			# Create a list of fields, building up a string for each equivalent to the per-field specification in a CREATE TABLE query
			if (is_array ($field)) {
				$key = $field['Field'];
				$specification  = strtoupper ($field['Type']);
				if (strlen ($field['Collation'])) {$specification .= ' collate ' . $field['Collation'];}
				if (strtoupper ($field['Null']) == 'NO') {$specification .= ' NOT NULL';}
				if (strtoupper ($field['Key']) == 'PRI') {$specification .= ' PRIMARY KEY';}
				if (strlen ($field['Default'])) {$specification .= ' DEFAULT ' . $field['Default'];}
				$field = $specification;
			}
			
			# Add the field
			$fieldsSql[] = "{$fieldname} {$field}";
		}
		
		# Compile the overall SQL; type is deliberately set to InnoDB so that rows are physically stored in the unique key order
		$query = 'CREATE TABLE' . ($ifNotExists ? ' IF NOT EXISTS' : '') . " `{$database}`.`{$table}` (" . implode (', ', $fieldsSql) . ") ENGINE={$type} CHARACTER SET utf8 COLLATE utf8_unicode_ci;";
		
		# Create the table
		if ($this->_execute ($query) === false) {return false;}
		
		# Signal success
		return true;
	}
	
	
	# Function to get table metadata
	public function getTableStatus ($database, $table, $getOnly = false /*array ('Comment')*/)
	{
		# Define the query
		$query = "SHOW TABLE STATUS FROM `{$database}` LIKE '{$table}';";
		
		# Get the results
		$data = $this->_getOne ($query);
		
		# If only needing certain columns, return only those
		if ($getOnly && is_array ($getOnly)) {
			foreach ($getOnly as $field) {
				if (isSet ($data[$field])) {
					$attributes[$field] = $data[$field];
				}
			}
		} else {
			$attributes = $data;
		}
		
		# Return the results
		return $attributes;
	}
	
	
	# Function to truncate a table
	public function truncate ($database, $table, $limitedPrivilegesAvailable = false)
	{
		# Determine the query
		if ($limitedPrivilegesAvailable) {
			$query = "DELETE FROM {$database}.{$table};";	// i.e. delete everything
		} else {
			$query = "TRUNCATE {$database}.{$table};";
		}
		
		# Run the query, capturing the rows changed
		$rows = $this->_query ($query);
		
		# Determine the result
		$result = ($rows !== false);
		
		# Log the change
		$this->logChange ($result);
		
		# Return the result
		return $result;
	}
	
	
	# Function to set the table comment
	public function setTableComment ($database, $table, $tableComment, &$error = false)
	{
		# Ensure the string length is up to 60 characters long, as defined at: http://dev.mysql.com/doc/refman/5.1/en/create-table.html
		$maxLength = 60;	// Obviously this is currently MySQL-specific implementation
		if (strlen ($tableComment) > $maxLength) {
			$error = "The table comment must not be longer than {$maxLength} characters.";
			return false;
		}
		
		# Compile the query
		$query = "ALTER TABLE {$database}.{$table} COMMENT = '{$tableComment}';";	// Requires ALTER privilege
		
		# Run the query, capturing the rows changed
		$rows = $this->_query ($query);
		
		# Determine the result
		$result = ($rows !== false);
		
		# Log the change
		$this->logChange ($result);
		
		# Return the result
		return $result;
	}
	
	
	# Function to get the table comment
	public function getTableComment ($database, $table)
	{
		# Get the table status and return the comment part
		if (!$tableStatus = $this->getTableStatus ($database, $table, array ('Comment'))) {return false;}
		return $tableStatus['Comment'];
	}
	
	
	# Function to get error information
	public function error ()
	{
		# Get the error details
		if ($this->connection) {
			if ($this->preparedStatement) {
				$error = $this->preparedStatement->errorInfo ();
			} else {
				$error = $this->connection->errorInfo ();
			}
		} else {
			$error = array ('error' => 'No database connection available');
		}
		
		# Add in the SQL statement
		$error['query'] = $this->getQuery (true);
		$error['queryEmulated'] = $this->getQuery (false);
		
		# Return the details
		return $error;
	}
	
	
	# Define a lookup function used to join fields in the format targettableId fieldname__JOIN__targetDatabase__targetTable__reserved
	#!# Caching mechanism needed for repeated fields (and fieldnames as below), one level higher in the calling structure
	public static function lookup ($databaseConnection, $fieldname, $fieldType, $simpleJoin = false, $showKeys = NULL, $orderby = false, $sort = true, $group = false, $firstOnly = false, $showFields = array (), $tableMonikerTranslations = array ())
	{
		# Determine if it's a special JOIN field
		$values = array ();
		$targetDatabase = NULL;
		$targetTable = NULL;
		$targetTableMoniker = NULL;
		if ($matches = self::convertJoin ($fieldname, $simpleJoin)) {
			
			# Load required libraries
			require_once ('application.php');
			
			# Assign the new fieldname
			$fieldname = $matches['field'];
			$targetDatabase = $matches['database'];
			$targetTable = $matches['table'];
			
			# Determine the table moniker for the target table, which is normally the same; this is useful if the client application has a table such as 'fooNames' but this maps to a nicer URL of 'foo'
			$targetTableMoniker = ($tableMonikerTranslations && isSet ($tableMonikerTranslations[$targetTable]) ? $tableMonikerTranslations[$targetTable] : $targetTable);
			
			# Get the fields of the target table
			$fields = $databaseConnection->getFieldNames ($targetDatabase, $targetTable);
			
			# Deal with ordering
			$orderbySql = '';
			if ($orderby) {
				
				# Get those fields in the orderby list that exist in the table being linked to
				$orderby = application::ensureArray ($orderby);
				$fieldsPresent = array_intersect ($orderby, $fields);
				
				# Compile the SQL
				$orderbySql = ' ORDER BY ' . implode (',', $fieldsPresent);
			}
			
			# Get the data
			#!# Enable recursive lookups
			$query = "SELECT * FROM {$targetDatabase}.{$targetTable}{$orderbySql};";
			if (!$data = $databaseConnection->_getData ($query, "{$targetDatabase}.{$targetTable}")) {
				return array ($fieldname, array (), $targetDatabase, $targetTableMoniker);
			}
			
			# Sort
			if ($sort) {ksort ($data);}
			
			# Determine whether to show keys (defaults to showing keys if the field is not numeric)
			$showKey = ($showKeys === NULL ? (!strstr ($fieldType, 'int(')) : $showKeys);
			
			# Deal with grouping if required
			$grouped = false;
			if ($group) {
				
				# Determine the field to attempt to use, either a supplied fieldname or the second (first non-key) field. If the group 'name' supplied is a number, treat as an index (e.g. second key name)
				$groupField = (($group === true || is_numeric ($group)) ? application::arrayKeyName ($data, (is_numeric ($group) ? $group : 2), true) : $group);
				
				# Confirm existence of that field
				if ($groupField && in_array ($groupField, $fields)) {
					
					# Find if any group field values are unique; if so, regroup the whole dataset; if not, don't regroup
					$groupValues = array ();
					foreach ($data as $key => $rowData) {
						$groupFieldValue = $rowData[$groupField];
						if (!in_array ($groupFieldValue, $groupValues)) {
							$groupValues[$key] = $groupFieldValue;
						} else {
							
							# Regroup the data and flag this
							$data = application::regroup ($data, $groupField, false);
							$grouped = true;
							break;
						}
					}
				}
			}
			
			# Convert the data into a single key/value pair, removing repetition of the key if required
			if ($grouped) {
				foreach ($data as $groupKey => $groupData) {
					foreach ($groupData as $key => $rowData) {
						#!# Duplicated code in these two sections
						#!# This assumes the key is the first ...
						array_shift ($rowData);
						/*
						unset ($rowData[$groupField]);
						if (application::allArrayElementsEmpty ($rowData)) {
							array_unshift ($rowData, "{{$groupKey}}");
						}
						*/
						$values[$groupKey][$key]  = ($showKey ? "{$key}: " : '');
						$useFields = $rowData;
						if ($showFields) {
							require_once ('application.php');
							$useFields = application::arrayFields ($rowData, $showFields);	// Filters down to the $showFields fields only
						}
						$set = array_values ($useFields);
						$values[$groupKey][$key] .= ($firstOnly ? $set[0] : implode (' - ', $set));
					}
				}
			} else {
				foreach ($data as $key => $rowData) {
//					application::dumpData ($rowData);
					#!# This assumes the key is the first ...
					array_shift ($rowData);
					$values[$key]  = ($showKey ? "{$key}: " : '');
					$useFields = $rowData;
					if ($showFields) {
						require_once ('application.php');
						$useFields = application::arrayFields ($rowData, $showFields);	// Filters down to the $showFields fields only
					}
					$set = array_values ($useFields);
					$values[$key] .= ($firstOnly ? $set[0] : implode (' - ', $set));
				}
			}
		}
		
		# Return the field name and the lookup values
		return array ($fieldname, $values, $targetDatabase, $targetTableMoniker);
	}
	
	
	# Function to convert joins
	public static function convertJoin ($fieldname, $simpleJoin = false /* or array(currentDatabase,currentTable,array(tables)) */)
	{
		# Simple join mode, e.g. targetId joins to database=$simpleJoin[0],table=target, and the field is fixed as 'id'
		if ($simpleJoin) {
			if (preg_match ('/^([a-zA-Z0-9]+)Id$/', $fieldname, $matches)) {
				list ($currentDatabase, $currentTable, $tables) = $simpleJoin;
				
				# Determine the target table
				switch (true) {
					case ($matches[1] == 'parent'):	// Special-case: if field is 'parentId' then treat as self-join to current table
						$table = $currentTable;
						break;
					case (in_array (preg_replace ('/ss$/', 'sses', $matches[1]), $tables)):	// Pluraliser for ~ss => ~sses, e.g. addressId => addresses
						$table =    preg_replace ('/ss$/', 'sses', $matches[1]);
						break;
					case (in_array ($matches[1] . 's', $tables)):	// Simple pluraliser, e.g. for a field 'caseId' look for a table 'cases'; if not present, it will assume 'case'
						$table = $matches[1] . 's';
						break;
					case (in_array (preg_replace ('/y$/', 'ies', $matches[1]), $tables)):	// Pluraliser for ~y => ~ies, e.g. countryId => countries
						$table =    preg_replace ('/y$/', 'ies', $matches[1]);
						break;
					default:
						$table = $matches[1];
						break;
				}
				
				# Return the result
				return array (
					'field' => 'id',	// Fixed - nothing to do with the supplied fieldname ending 'Id'
					'database' => $currentDatabase,
					'table' => $table,
				);
			}
			
		# Otherwise use the fieldname__JOIN__table__database__reserved format
		} else {
			if (preg_match ('/^([a-zA-Z0-9]+)__JOIN__([a-zA-Z0-9]+)__([-_a-zA-Z0-9]+)__reserved$/', $fieldname, $matches)) {
				return array (
					'field' => $matches[1],
					'database' => $matches[2],
					'table' => $matches[3],
				);
			}
		}
		
		# Otherwise return false;
		return false;
	}
	
	
	# Function to substitute lookup values for their names
	public function substituteJoinedData ($dataset, $database, $table /* for targetId fieldname format, or false to use older format, i.e. fieldname__JOIN__databasename__tablename__reserved */, $targetField = true /* i.e. take id and next field; or set named field, e.g. 'name' */)
	{
		# If no data, return the value unchanged
		if (!$dataset) {return $dataset;}
		
		# Determine whether to use the simple join method, and if so assemble the simpleJoin parameter
		$simpleJoin = false;
		if ($table) {
			$tables = $this->getTables ($database);
			$simpleJoin = array ($database, $table, $tables);
		}
		
		# Get the fields in the current dataset
		$fields = array_keys (reset ($dataset));
		
		# Determine which fields are lookups
		$lookupFields = array ();
		foreach ($fields as $field) {
			if ($matches = self::convertJoin ($field, $simpleJoin)) {
				$lookupFields[$field] = $matches['table'];
			}
		}
		
		# Take no further action if no fields are lookups
		if (!$lookupFields) {return $dataset;}
		
		# Get the values in use for each of the lookup fields in the data
		$lookupValues = array ();
		foreach ($lookupFields as $field => $table) {
			foreach ($dataset as $key => $record) {
				$lookupValues[$field][] = $record[$field];
			}
			$lookupValues[$field] = array_unique ($lookupValues[$field]);
		}
		
		# If required, determine the target field which contains the looked-up data
		$targetFields = array ();
		if ($targetField === true) {
			foreach ($lookupFields as $field => $table) {
				$fields = $this->getFieldNames ($database, $table);
				$targetFields[$field] = $fields[1];	// 2nd field, i.e. the one after the key
			}
		}
		
		# Lookup the values
		$lookupResults = array ();
		foreach ($lookupValues as $field => $values) {
			$targetField = ($targetFields ? $targetFields[$field] : $targetField);
			$lookupResults[$field] = $this->selectPairs ($database, $lookupFields[$field], array ('id' => $values), array ('id', $targetField));
		}
		
		# Substitute in the values, retaining the originals where no lookup exists
		foreach ($dataset as $key => $record) {
			foreach ($lookupResults as $field => $lookups) {
				if (array_key_exists ($record[$field], $lookups)) {
					$lookedUpValue = $record[$field];
					$dataset[$key][$field] = $lookups[$lookedUpValue];
				}
			}
		}
		
		# Return the amended dataset
		return $dataset;
	}
	
	
	# Function to log a change
	#!# Ideally have some way to throw an error if the logfile is not writable
	public function logChange ($result, $insertId = false)
	{
		# End if logging disabled
		if (!$this->logFile) {return false;}
		
		# Get the query
		$query = $this->getQuery ();
		
		# Ensure the query ends with a newline
		$query = trim ($query);
		
		# End if the file is not writable, or the containing directory is not if the file does not exist
		if (file_exists ($this->logFile)) {
			if (!is_writable ($this->logFile)) {return false;}
		} else {
			$directory = dirname ($this->logFile);
			if (!is_writable ($directory)) {return false;}
		}
		
		# Create the log entry
		$logEntry = '/* ' . ($result ? 'Success' : 'Failure') . ' ' . date ('Y-m-d H:i:s') . ' by ' . $this->userForLogging . ' */ ' . trim (str_replace ("\r\n", '\\r\\n', $query));
		
		# Append the insert ID as a comment if required
		if ($insertId) {
			$insertId = $this->getLatestId ();
			if ($insertId != 0) {	// Non- auto-increment will have 0 returned; considered unlikely that a real application would start at 0
				$logEntry .= "\t// RETURNING {$insertId}";
			}
		}
		
		# Add newline
		$logEntry .= "\n";
		
		# Log the change
		file_put_contents ($this->logFile, $logEntry, FILE_APPEND);
	}
	
	
	# Function to notify the admin of a connection error
	public function reportError ($administratorEmail, $applicationName, $filename = false, $errorMessage = 'A database connection could not be established.')
	{
		# Tell the user
		$html = "\n<p class=\"warning\">Error: This facility is temporarily unavailable. Please check back shortly. The administrator has been notified of this problem.</p>";
		
		# Determine the filename to use
		if (!$filename) {
			$filename = getcwd () . '/' . 'errornotifiedflagfile';
		}
		
		# If there is not a flag file, write one, then report the error by e-mail
		if (!file_exists ($filename)) {
			
			# Attempt to write the notification file
			$directory = dirname ($filename);
			if (is_writable ($directory)) {
				umask (002);
				file_put_contents ($filename, date ('r'));
				$errorMessage .= "\n\nWhen the error has been corrected, you must delete the error notification flag file at\n{$filename}";
			} else {
				$errorMessage .= "\n\nAdditionally, an errornotifiedflagfile could not be written, so further e-mails like this will continue.";
			}
			
			# Add the URL
			require_once ('application.php');
			$errorMessage .= "\n\n\n---\nGenerated at URL: {$_SERVER['_PAGE_URL']}";
			
			# Mail the admin
			$mailheaders = "From: {$applicationName} <" . $administratorEmail . ">\n";
			application::utf8Mail ($administratorEmail, 'Data access error: ' . $applicationName, wordwrap ($errorMessage), $mailheaders);
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Accessor function to get the query
	public function getQuery ($showRawPreparedQuery = false)
	{
		# Return the direct query if emulation of what the prepared statement is not required
		if ($showRawPreparedQuery) {
			return $this->query;
		}
		
		# If there are no query values, return the prepared statement
		if (!$this->queryValues) {
			return $this->query;
		}
		
		# Determine whether the query uses named parameters (see http://www.php.net/pdo.prepared-statements ) rather than ?
		$usingNamedParameters = (!substr_count ($this->query, '?'));
		
		# Add colons to each (where necessary) and, where necessary, quote the values, dealing with special cases like NULL and NOW()
		$values = array ();
		foreach ($this->queryValues as $key => $value) {
			if ($usingNamedParameters) {
				$key = ':' . $key;
			}
			switch (true) {
				case ctype_digit ($value):
					$values[$key] = $value;
					break;
				case is_null ($value):
					$values[$key] = 'NULL';
					break;
				case $value == 'NOW()':
					$values[$key] = 'NOW()';
					break;
				default:
					$values[$key] = $this->quote ($value);
			}
		}
		
		# Do replacement
		if ($usingNamedParameters) {
			krsort ($values);	// Sort by key reversed, so that longer key names come first to avoid overlapping replacements
			$query = strtr ($this->query, $values);
		} else {
			$query = $this->query;
			foreach ($values as $value) {
				$query = preg_replace ('/\?/', str_replace ('\\', '\\\\', $value), $query, 1);	// Do replacement of each ? in order, using the limit=1 technique as per http://stackoverflow.com/questions/4863863 ; the str_replace must be used to replace a literal backslash \ to \\ in the replacement string
			}
		}
		
		# Return the query
		return $query;
	}
	
	
	# Function to get the session status values; see: https://dev.mysql.com/doc/refman/5.7/en/server-status-variables.html
	public function getSessionStatus ()
	{
		# Get the data and return the value
		return $this->getPairs ('SHOW SESSION STATUS;');
	}
	
	
	# Function to get a variable
	public function getVariable ($variable)
	{
		# Get the data and return the value
		$data = $this->_getOne ("SHOW VARIABLES LIKE '{$variable}';");
		
		# End if none
		if (!isSet ($data['Value'])) {return false;}
		
		# Return the value
		return $data['Value'];
	}
	
	
	# Function to do sort trimming of a field name, to be put in an ORDER BY clause
	public function trimSql ($fieldname, $additionalTokens = array ())
	{
		# Assemble the fieldname quoted
		$fieldname = '`' . str_replace ('.', '`.`', $fieldname) . '`';
		
		# Define strings to trim
		$strings = array (
			'the ',
			'an ',
			'a ',
			'@',
			"'",
			'"',
			'[',
			'(',
			'}',
			'{',
		);
		
		# Add additional tokens
		if ($additionalTokens) {
			$strings = array_merge ($strings, $additionalTokens);
		}
		
		# Assemble the SQL
		$sql = "LOWER( {$fieldname} )";
		foreach ($strings as $string) {
			$sql = "TRIM( LEADING '" . str_replace ("'", "\'", $string) . "' FROM {$sql} )";
		}
		
		# Return the SQL
		return $sql;
	}
	
	
	# Function to assemble a REPLACE() phrase from multiple string replacements; the enclosing quote mark (' or ") must be specified (or false, if the caller has already quoted all keys and values)
	public function replaceSql ($pairs, $field, $quoteMark)
	{
		# Build the SQL string
		$sql = $field;
		foreach ($pairs as $find => $replace) {
			if ($quoteMark) {
				$find = $quoteMark . $find . $quoteMark;
				$replace = $quoteMark . $replace . $quoteMark;
			}
			$sql = "REPLACE({$sql},{$find},{$replace})";
		}
		
		# Return the string
		return $sql;
	}
	
	
	# Function to split any records in a set into multiple records where a SET field has multiple entries; note this will destroy all keys
	public function splitSetToMultipleRecords ($records, $field, $namespace = '_')
	{
		# Work through each record
		foreach ($records as $id => $record) {
			
			# Skip as unmodified if empty string
			if (!strlen ($record[$field])) {continue;}
			
			# Get the values; this is applied consistently even when no commas
			$values = explode (',', $record[$field]);
			
			# Add the values as new records with a namespaced ID
			foreach ($values as $value) {
				$createdId = $id . $namespace . $value;
				$records[$createdId] = $record;
				$records[$createdId][$field] = $value;
			}
			
			# Remove the original record
			unset ($records[$id]);
		}
		
		# Return the records
		return $records;
	}
	
	
	# Function to add a value to a SET; see http://dev.mysql.com/doc/refman/5.5/en/set.html#c6846
	public function addToSet ($database, $table, $field, $value, $whereField, $whereValue)
	{
		# Define the query
		$query = "UPDATE
			`{$database}`.`{$table}`
			SET `{$field}` = CONCAT_WS(',', IF(`{$field}` = '', NULL, `{$field}`), :value)
			WHERE `{$whereField}` = :id
		;";
		$preparedStatementValues = array (
			'id'	=> $whereValue,
			'value'	=> $value,
		);
		
		# Execute the query
		$rows = $this->_execute ($query, $preparedStatementValues);
		
		# Determine the result
		$result = ($rows !== false);
		
		# Log the change
		$this->logChange ($result);
		
		# Return the result
		return $result;
	}
	
	
	# Function to remove a value from a SET; see http://dev.mysql.com/doc/refman/5.5/en/set.html#c6846
	public function removeFromSet ($database, $table, $field, $value, $whereField, $whereValue)
	{
		# Define the query
		$query = "UPDATE
			`{$database}`.`{$table}`
			SET `{$field}` = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', `{$field}`, ','), CONCAT(',', :value, ','), ','))
			WHERE `{$whereField}` = :id
		;";
		$preparedStatementValues = array (
			'id'	=> $whereValue,
			'value'	=> $value,
		);
		
		# Execute the query
		$rows = $this->_execute ($query, $preparedStatementValues);
		
		# Determine the result
		$result = ($rows !== false);
		
		# Log the change
		$this->logChange ($result);
		
		# Return the result
		return $result;
	}
	
	
	# Function to execute SQL into MySQL
	public function runSql ($settings, $input, $isFile = true, &$outputText = false)
	{
		# Determine the input
		if ($isFile) {
			$input = "< \"{$input}\"";
		} else {
			// $input = "-e \"{$input}\"";
			$input = '-e "' . str_replace ('"', '\\"', $input) . '"';
		}
		
		# Compile the command; "2>&1" needed to capture error output - see http://stackoverflow.com/a/8940800
		$command = "mysql --max_allowed_packet=1000M --local-infile=1 -h {$settings['hostname']} -u {$settings['username']} --password={$settings['password']} {$settings['database']} {$input} 2>&1";
		
		# Execute the command
		exec ($command, $outputLines, $shellResult);
		
		# Assemble the output lines
		$outputText = implode ("\n", $outputLines);
		
		# Convert the result from shell output (0 = OK, other number = problem) to PHP true/false
		$result = (!$shellResult);
		
		# Return the result (true or false)
		return $result;
	}
}

?>
