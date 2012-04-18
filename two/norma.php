<?php 

/*

NEW GOALS
- caches all data pulled from DB in $this->data
- when you request an object from data that is actually the name of a relation, it'll instantiate the appropriate class for you
	- yikes, but this means we'll have a copy of data in our nested $data, but after instantiation, the related object will be operating on a different array ... guess i shouldn't do things that way
	- guess to_array() needs to walk these relations and return the proper nested structure (without cycles, if those are possible)
- this means we sould be able to restore state simply by passing an array of fields in the constructor, which changes the way we handle primary keys slightly

- use dbFacile or other for getting last id back from inserts
- make sure updates work
- escaping properly
- quoting properly

- make it easy to cache ... preferrably a whole tree of objects that have been used for a request
	- __toArray() returns this structure that can be used by future instances

*/

abstract class Norma {
	public static $dbFacile;
	// You should declare:
	public static $aliases = array();
	public static $keys = array();
	public static $pk = 'ID';
	public static $relationships = array();
	public static $foreignAliases = array();
	
	// So we only include fields that have changed in UPDATE sql
	protected $changed = array();
	// This is where all of the database data lives ... ORIGINAL FIELD NAMES, NOT ALIASES
	protected $data = array();

	public function __construct($data = array()) {
		if (is_array($data)) $this->data = $data; // merge in data
	}

	public function __get($name) {
		/*
		Perhaps order should be:
		- check $this->cache
		- check $this->relationship
		- check foreign aliases

		Needs thinking.
		*/
		// Foreign alias?
		if (array_key_exists($name, static::$foreignAliases)) {
			$data = static::$foreignAliases[ $name ];
			$localAlias = $data[0];
			$foreignAlias = $data[1];
			// Go through our existing means for pulling and caching relations,
			// then return the specific field
			$a = $this->$localAlias;
			return $a->$foreignAlias;
		}
		// Relationship
		if (array_key_exists($name, static::$relationships)) {
			if (array_key_exists($name, $this->data)) { // something's in the cache with this name
				$value = $this->data[ $name ];
				if (is_object($value)) return $value; // object has already been pulled and is cached
				if (is_array($value)) { // looks like row data, but we need to return an object
					$relation = static::$relationships[ $name ];
					$className = $relation[1];
					$a = new $className($value);
					$this->data[ $name ] = $a;
					return $a;
				}
				// uhhh
			} else {
				$relation = static::$relationships[ $name ];
				$className = $relation[1];
				$key = $relation[2];
				$v = $this->data[ static::$aliases[ $relation[0] ] ];
				// load using primary key
				$a = $className::$key( $v );
				$this->data[ $name ] = $a;
			}
			// Should be loaded
			// but what if not? return false?
			return $a;
		}
		// Local field?
		if (array_key_exists($name, static::$aliases)) {
			// might not have been pulled, but we don't care
			return $this->data[ static::$aliases[ $name ] ];
		}
		return null;
	}

	public function __set($name, $value) {
		// I don't like loading via primary/unique key using an assignment.
		// Plus, it's hard to report "row not found" using that technique ...
		// I didn't want to throw an exception.
		// Article::ID(1234) is the way we load now
		if (array_key_exists($name, static::$aliases)) { // Set field value
			$field = static::$aliases[ $name ];
			$this->changed[] = $field; // So we can save only the fields that have changed
			$this->data[ $field ] = $value;
		}
	}

	// Used to access related objects via join so we can jump through to
	// a far away related object using only 1 query
	public function __call($name, $args) {
		if (array_key_exists($name, static::$relationships)) {
			$relationship = static::$relationships[ $name ];
			$className = $relationship[1];
			// Pass in current join fields and values
			$where = array(
				// Get table name from foreign class
				$className::$table,
				// Get foreign field name from foreign class
				$className::$aliases[ $relationship[2] ],
				// Value from local object
				$this->data[ static::$aliases[ $relationship[0] ] ]
			);
			/*
			Would like where to be like this, so we can do proper quoting with dbFacile later:
			array(
				array(TargetField, TargetField, LocalTable, LocalField)
				array(TargetField, TargetField, VALUE)
			);
			*/
			return new NormaChain($className, $where );
		}
		return null;
	}

	// So you can do: $u = User::ID(1)
	// Loads User record with primary key of 1 from database and returns an instance of User with that data
	// THE ONLY WAY TO LOAD A ROW FROM THE DB
	public static function __callStatic($name, $args) {
		if ($name == static::$pk || in_array($name, static::$keys)) {
			$sql = static::MakeSql($name, $args[0]);
			$row = Norma::$dbFacile->fetchRow($sql);
			if (!$row) {
				return null;
			} else {
				return new static($row);
			}
		}
		return null;
	}
	
	// really should build offbase array classes so casting works
	public function toArray() {
		// walk $data, add data from relationCache too
		// want this to only contain arrays and scalars, no objects

		// Maps these values to aliases?
		$data = array();
		foreach ($this->data as $key => $value) {
			// Normal field data is NOT stored by alias, but we should return it as such
			$alias = array_search($key, static::$aliases);
			if ($alias !== false) {
				$data[ $alias ] = $value;
			} elseif (array_key_exists($key, static::$relationships)) {
				// If object, use toArray() on it
				$data[ $key ] = $value;
			}
		}
		return $data;
	}

	// Alphabetical
	protected function ChangedData() {
		$changed = array_unique($this->changed);
		// remove $pk from changed
		$data = array();
		foreach ($this->changed as $field) {
			$data[ $field ] = $this->data[ $field ];
		}
		return $data;
	}
	
	public function Create() {
		$data = $this->ChangedData();
		// Remove primary key
		if (static::$pk) unset( $data[ static::$aliases[ static::$pk ] ] );
		$id = Norma::$dbFacile->insert($data, static::$table);
		if (static::$pk) $this->data[ static::$aliases[ static::$pk ] ] = $id;
		$this->changed = array();
		return $id;
	}
	
	public function Delete() {
		$pk = static::$aliases[ static::$pk ];
		$data = array(
			$pk => $this->data[ $pk ]
		);
		return Norma::$dbFacile->Delete(static::$table, $data);
	}
	
	protected static function MakeSql($alias, $value) {
		// To hell with doing field mappings in the SQL ... makes it hard to write custom queries and benefit from ORM
		$sql = 'SELECT * FROM ' . static::$table;
		$sql .= ' WHERE ';
		$sql .= '`' . static::$aliases[ $alias ] . '`=' . $value;
		return $sql;	
	}
	
	// Torn between Create() and Update() or just Save()
	public function Save() {
		$pk = static::$aliases[ static::$pk ];
		$data = $this->ChangedData();
		// new or not?
		$a = Norma::$dbFacile->update($data, static::$table, array($pk => $this->data[ $pk ]));
		
		return $a;
	}
}

// Grr, this needs outside access to relationships and table, etc
class NormaChain {
	protected $className = null;
	protected $where = array();
	public function __construct($name, $where) {
		$this->className = $name;
		$this->where[] = $where;
	}
	public function __call($name, $args) {
		// get current class
		$className = $this->className;
		// Tie previous class to new
		$relationship = $className::$relationships[ $name ];
		$className2 = $relationship[1];
		$r = array(
			$className::$table,
			$className::$aliases[ $relationship[0] ],
			$className2::$table,
			$className2::$aliases[ $relationship[2] ],
			
		);
		array_push($this->where, $r);
		$this->className = $className2;
		return $this;
	}

	public function Done() {
		$className = $this->className;
		$table = $className::$table;

		// What a pain to have to do field aliases in the SQL. ugh
		$parameters = array();
		// backtick these values
		$sql = 'SELECT ' . $table . '.* from ' . $table;

		$fin = array_shift($this->where); // last where will be part of where clause

		foreach ($this->where as $where) {
			$sql .= ' LEFT JOIN ' . $where[0] . ' ON ('
				. $where[0] . '.' . $where[1]
				. '='
				. $where[2] . '.' . $where[3]
				. ')';
		}
		$sql .= ' WHERE ' . $fin[0] . '.' . $fin[1] . '=?';
		$parameters[] = $fin[2];

		$rows = Norma::$dbFacile->fetchAll($sql, $parameters);
		$pk = $className::$pk;
		$out = array();
		foreach ($rows as $row) {
			$out[] = new $className($row);
		}
		return $out;
	}
}
