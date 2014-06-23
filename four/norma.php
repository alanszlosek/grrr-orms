<?php
/*
Create single insta

THOUGHTS
- Kinda sucks that data is indexed by db table name, not alias name. But that's because i wanted it to be easy to push data into an instance. Probably should index $this->data by alias in the near future, since that should shave some time off lookups for getting and setting. We'll be getting and setting fields more frequently than loading from the DB, so that might be a nice gain. I'm sure there was some reason that I went the other route, but can't recall now. Reminds me, I should probably
  push the lookup into a method so I can easily change and test later.
*/

abstract class Norma {
	public static $dbFacile;
	// You should declare these in derivative classes:
	protected static $table = null;
	protected static $aliases = array(); // key/value pairs: alias=>field_name_in_table
	protected static $keys = array();
	protected static $pk = 'ID'; // Default alias. Will get converted to an array in static::GetPK().
	protected static $relationships = array();
	protected static $foreignAliases = array();
	protected static $deleteTheseFirst = array();
	
	// $changed allows us to perform UPDATE queries on only those fields that have changed
	protected $changed = array(); // Holds DB FIELD NAMES, NOT ALIASES
	// This is where all of the database data lives ... keyed by DB FIELD NAMES, NOT ALIASES
	protected $data = array();

	public static $debug = false;

	public function __construct($data = array(), $flagAsChanged = true) {
		if (is_array($data)) {
			$this->data = $data; // merge in data
			// In __callStatic we pass false for this field, since we're loading directly from DB
			// In this case, data will be keyed by actual DB field name, right?
			if ($flagAsChanged) {
				// Ensure changed only contains actual db field names
				$this->changed = array_intersect(
					array_values(static::$aliases),
					array_keys($data)
				);
			}
		}
	}

	protected static function GetAliasMap($name) {
		return static::$aliases[ $name ];
	}
	protected function GetData($name) {
		return $this->data[ static::GetAliasMap($name) ];
	}
	public function __get($name) {
		// Check data cache first, so arbitrary things can be attached to instances if need be
		if (array_key_exists($name, $this->data)) {
			return $this->data[ $name ];

		// Local field?
		} elseif (array_key_exists($name, static::$aliases)) {
			// might not have been pulled, but we don't care
			return $this->GetData($name);

		// Relationship?
		} elseif (array_key_exists($name, static::$relationships)) {
			$relation = static::$relationships[ $name ];
			$v = $this->GetData($relation[0]);
			$className = $relation[1];
			$key = $relation[2];
			// Load using specified key ... hope it's been declared as one
			$a = $className::$key( $v );
			if ($a) return $this->data[ $name ] = $a;

		// Foreign alias?
		} elseif (array_key_exists($name, static::$foreignAliases)) {
			$foreign = static::$foreignAliases[ $name ];
			$localAlias = $foreign[0];
			$foreignAlias = $foreign[1];
			// Go through our existing means for pulling and caching relations,
			// so it gets cached in $this->data, then return the specific field
			return $this->$localAlias->$foreignAlias;
		}
		return null;
	}

	public function __set($field, $value) {
		// Perhaps prevent modifying primary key field(s), but that feels like baby-sitting ...
		if (array_key_exists($field, static::$aliases)) { // Set field value
			$field = $this->GetAliasMap($field);
			$this->changed[] = $field; // Keep track of the actual DB field that we need to write
		}
		// This assignment is not within the above conditional so programmers can
		// annotate this Norma instance with extra data if need be. It's handy.
		$this->data[ $field ] = $value;
	}

	/*
	Chain through to related table rows using method calls:
	$a = Article::ID(1);
	$users = $a->User()->GetAll();
	*/
	public function __call($name, $args) {
		if (array_key_exists($name, static::$relationships)) {
			$relationship = static::$relationships[ $name ];
			// Pass in current join fields and values
			$whereHash = array();
			// Assume each arg is a whereHash
			foreach ($args as $arg) {
				$whereHash = array_merge($whereHash, $arg);
			}
			// Value from local object
			// We probably need a $this->dataByAlias($relationship[0]) method
			$whereHash [ $relationship[2] ] = $this->GetData($relationship[0]);
			// Pass relationship class name as first param
			return new NormaFind($relationship[1], $whereHash);
		}
		return null;
	}

	// So you can do: $u = User::ID(1)
	// Loads User record with primary key of 1 from database and returns an instance of User with that data
	public static function __callStatic($name, $args) {
		// We do allow lookup by more than 1 field, but that assumes our aliases don't have underscores
		$fields = explode('_', $name);
		// Maybe we need static::getPK() so we can always return an array, and make sure it's set as such
		$staticPK = static::GetPK();
		$a = array_intersect($staticPK, $fields);
		$b = array_intersect(static::$keys, $fields); // lookup by other keys? i'm confused
		$where = array();
		$parameters = array();
		// If any of the PKs are matched ... fail if not all are provided
		if (sizeof($a) == sizeof($staticPK)) {
			foreach($staticPK as $i => $key) {
				$where[] = $this->quoteField(static::GetAliasMap($key)) . '=?';
				$parameters[] = $args[ $i ];
			}
		}
		if (sizeof($b) == sizeof(static::$keys)){
			foreach(static::$keys as $i => $key) {
				$where[] = $this->quoteField(static::GetAliasMap($key)) . '=?';
				$parameters[] = $args[ $i ];
			}
		}
		if ($where) {
			// list fields rather than *?
			$sql = 'SELECT * FROM ' . $this->quoteField(static::$table) . ' WHERE ' . implode(' AND ', $where);
			if (Norma::$debug) trigger_error('Norma SQL: ' . $sql, E_USER_NOTICE);
			$row = Norma::$dbFacile->fetchRow($sql, $parameters);
			if (!$row) {
				return null;
			} else {
				return new static($row, false);
			}
		} else {
			throw new Exception('Expected ' . $name . ' to be primary or unique key');
		}
		return null;
	}

	/*
	WORK IN PROGRESS
	So you can do:
		$where = array('
		$article = Article::Find(
	*/
	// need to accept parameters
	public static function Find($where = array(), $limit = null, $order = null) {
		return new NormaFind(get_called_class(), $where);
	}

	// $sql...
	public static function FindQuery() {
		$n = new NormaFind(get_called_class());
		$n->Query(func_get_args());
		return $n;
	}
	public static function FromQuery($sql, $parameters = array()) {
		if (Norma::$debug) trigger_error('Norma SQL: ' . $sql, E_USER_NOTICE);
		$row = Norma::$dbFacile->fetchRow($sql, $parameters);
		return new static($row, false);
	}
	
	public function FromArray($data) {
		foreach ($data as $key => $value) {
			$this->$key = $value;
		}
	}
	public function Merge($data) {
		$this->FromArray($data);
	}

	// NOT SURE HOW THIS SHOULD FUNCTION YET
	// Might need two separate functions ... we have aliases to translate or not. Hmm.
	public function toArray() {
		// want this to only contain arrays and scalars, no objects
		$data = array();
		foreach ($this->data as $key => $value) {
			if (
				in_array($key, static::$aliases)
				||
				array_key_exists($key, static::$relationships)
				||
				array_key_exists($key, static::$foreignAliases)
			) {
				$data[ $key ] = $value;
			}
		}
		return $data;
	}

	// Alphabetical
	protected function ChangedData() {
		$data = array();
		foreach ($this->changed as $field) {
			// If a field was updated more than once, $this->changed might have duplicate values
			if (array_key_exists($field, $data)) {
				continue;
			}
			$data[ $field ] = $this->data[ $field ];
		}
		return $data;
	}
	
	// Still torn about whether this should go through Save() ...
	public function Create() {
		$data = $this->ChangedData();
		if (Norma::$debug) trigger_error('Norma Insert: ' . json_encode($data), E_USER_NOTICE);
		$id = Norma::$dbFacile->insert(static::$table, $data);
		// $id will be false if insert fails. Up to programmer to care.
		// There should be auto-key-gen if PK isn't 1, right?
		$staticPK = static::GetPK();
		if ($id !== false && sizeof($staticPK) == 1) {
			$pk = static::GetAliasMap($staticPK);
			// $id will be true if insert succeeded but didn't generate an id
			if ($id !== true) {
				// why would we not always want to do this?
				//if (static::$pk) 
				$this->data[ $pk ] = $id;
				$this->changed = array();
			}
		}
		return $id;
	}
	
	public function Delete() {
		foreach (static::$deleteTheseFirst as $relation) {
			foreach ($this->$relation() as $a) {
				$a->Delete();
			}
		}

		// Support single and multi-field primary keys
		$where = array();
		$staticPK = static::GetPK();
		foreach($staticPK as $key) {
			$where[ $key ] = $this->GetData($key);
		}
		if (Norma::$debug) trigger_error('Deleting ' . static::$table . ' ' . json_encode($where), E_USER_NOTICE);
		return Norma::$dbFacile->Delete(static::$table, $where);
	}

	public function Error() {
		return Norma::$dbFacile->error();
	}

	// Torn between Create() and Update() or just Save()
	public function Save() {
		// We support Create() for objects without a primary key, but not Save()
		if (static::GetPK() == false) return false;
		$data = $this->ChangedData();
		// There was nothing to save
		if (sizeof($data) == 0) return false;

		// Support single and multi-field primary keys
		$where = array();
		$staticPK = static::GetPK();
		foreach($staticPK as $key) {
			$where[ $key ] = $this->GetData($key);
		}

		// dbFacile update() returns affected rows
		if (Norma::$debug) trigger_error('Norma update: ' . json_encode($data), E_USER_NOTICE);
		$a = Norma::$dbFacile->update(static::$table, $data, $where);
		return $a;
	}
	
	public static function Relationship( $name ) {
		return static::$relationships[ $name ];
	}
	public static function Table() {
		return static::$table;
	}
	public static function Alias($name) {
		return static::$aliases[ $name ];
	}
	public static function Aliases() {
		return static::$aliases;
	}
	public static function GetPK() {
		if (static::$pk !== false && !is_array(static::$pk)) {
			static::$pk = array(static::$pk);
		}
		return static::$pk;
	}
}

// Grr, this needs outside access to relationships and table, etc

/*
TODO
* Allow chaining through to same Class more than once, passing additional where clauses
* Should we allow string-based where clauses? ... really don't want to parse them in order to prepend table name
* Use NormaChain as Find() ... Article::Find()
	Find() accepts where clause
	* Can then chain to perform joins
	* How to specify the class that should ultimately be instantiated for each resulting row?

*/
class NormaFind implements Iterator {
	protected $className = null;
	protected $joins = array();
	protected $whereHash = array();
	protected $_where = array();
	protected $_limit;
	protected $_orderBy;
	
	protected $data = null; // holds results of the query
	public function __construct($className, $whereHash = array(), $limit = null, $order = null) {
		$this->className = $className;
		// Fixup where hash with correct table names
		if ($whereHash) $this->MergeWhereHash($whereHash);
		$this->_limit = $limit; // String
		$this->_orderBy = $order; // String
	}
	
	public function __get($name) {
		die('Nope');
	}
	public function __set($name, $value) {
		die('Nope');
	}

	// the logic here is screwy
	// some of these are probably overkill
	/*
	Handles:
	Article::ID(1)->Author()
	FUCK QUERY STRINGS IN JOINS
	AND FUCK Where() syntax
	*/
	public function __call($name, $args) {
		// get current class
		$className = $this->className;
		// Tie previous class to new
		$relationship = $className::$relationships[ $name ];
		$className2 = $relationship[1];
		$r = array(
			$className::Table(),
			$className::Alias( $relationship[0] ),
			$className2::Table(),
			$className2::Alias( $relationship[2] ),
			
		);
		$this->joins[] = $r;
		// New join table designates final output class, so update it
		$this->className = $className2;
		$className = $this->className;

		if ($args) {
			foreach ($args as $arg) {
				$this->MergeWhereHash($arg);
			}
		}
		return $this;
	}
	
	public function OrderBy($str) {
		$this->_orderBy = $str;
		return $this;
	}
	public function Limit($limit, $offset = 0) {
		$this->_limit = $offset . ',' . $limit;
		return $this;
	}
	
	public function Objects() {
		$this->Done();
		return $this->data;
	}
	public function Rows() {
		$this->Done();
		return $this->data;
	}
	
	public function End() {
		// Placeholder
		return $this;
	}

	// Fixup where clause according to class name
	protected function MergeWhereHash($whereHash, $className = null) {
		if (!$className) $className = $this->className;
		foreach ($whereHash as $key => $value) {
			// need to do proper escaping and quoting here
			$field = $this->escapeField($className::Table(), $className::Alias($key));
			$this->whereHash[ $field ] = $value;
		}
	}
	
	// Need a way to get the SQL

	// Done with Find() and method chaining
	public function MakeQueryParts() {
		$className = $this->className;
		$table = $className::Table();

		// What a pain to have to do field aliases in the SQL. ugh
		// backtick these values
		$sql = 'SELECT ' . $this->quoteField($table) . '.* from ' . $this->quoteField($table);
		/*
		Thinking I can support all I need by identifying the type of where clause
		4 elements - join
		3 elements - table, field, scalar
		2 elements - string clause, parameters
		*/

		$wheres = array();
		foreach ($this->joins as $where) {
			$sz = sizeof($where);
			if ($sz == 4) {
				$sql .= ' LEFT JOIN ' . $this->quoteField($where[0]) . ' ON ('
					. $this->quoteField($where[0], $where[1])
					. '='
					. $this->quoteField($where[2], $where[3])
					. ')';
			} elseif ($sz == 3) {
				$sql .= ' LEFT JOIN ' . $this->quoteField($where[0]);
				$wheres[] = $this->quoteField($where[0], $where[1]) = '=';
				$wheres[] = $where[2];
			}
		}
		// need to add where IN support to dbFacile

		// bah, fucking string building
		if ($wheres) {
			$sql .= ' WHERE ' . implode(' AND ', $wheres);
			/*
			array(
				'select ... WHERE firstfield=',
				'value'
			*/
		}
		// be careful sql injection
		if ($this->_orderBy) $sql .= ' ORDER BY ' . $this->_orderBy;
		if ($this->_limit) $sql .= ' LIMIT ' . $this->_limit;

		//var_dump($sql);var_dump($parameters);exit;
		return array($sql, $parameters);
	}
	
	// Done with Find() and method chaining
	protected function Finalize() {
		
		$parts = $this->MakeQueryParts();
		if (Norma::$debug) trigger_error('Norma SQL: ' . $sql, E_USER_NOTICE);

		$this->Query($parts);
	}

	public function Query($queryParts) {
		$className = $this->className;
		$rows = call_user_func_array(array(Norma::$dbFacile, 'fetchAll'), $queryParts);
		$out = array();
		foreach ($rows as $row) {
			$out[] = new $className($row, false);
		}
		$this->data = $out;
	}

	protected function quoteField($field, $field2 = null) {
		if ($field2) {
			return Norma::$dbFacile->quoteField($field) . '.' . Norma::$dbFacile->quoteField($field2);
		} else {
			return Norma::$dbFacile->quoteField($field);
		}
	}


	protected function Done() {
		if ($this->data === null) $this->Finalize();
	}
	
	// Iterator implementation methods
	public function rewind() {
		$this->Done();
		reset($this->data);
	}

	public function current() {
		$this->Done();
		$var = current($this->data);
		return $var;
	}

	public function key() {
		$this->Done();
		$var = key($this->data);
		return $var;
	}

	public function next() {
		$this->Done();
		$var = next($this->data);
		return $var;
	}

	public function valid() {
		$this->Done();
		$var = $this->current() !== false;
		return $var;
	}
}
