<?php 

abstract class Norma {
	public static $dbFacile;
	// You should declare these in derivative classes:
	public static $aliases = array();
	public static $keys = array();
	public static $pk = 'ID'; // Default alias
	public static $relationships = array();
	public static $foreignAliases = array();
	
	// So we only include fields that have changed in UPDATE sql
	protected $changed = array();
	// This is where all of the database data lives ... keyed by DB FIELD NAMES, NOT ALIASES
	protected $data = array();

	public function __construct($data = array(), $flagAsChanged = true) {
		if (is_array($data)) {
			$this->data = $data; // merge in data
			// In __callStatic we pass false for this field, since we're loading directly from DB
			if ($flagAsChanged) $this->changed = array_keys($data);
		}
	}

	public function __get($name) {
		// Check data cache first, so arbitrary things can be attached to instances if need be
		if (array_key_exists($name, $this->data)) {
			return $this->data[ $name ];

		// Local field?
		} elseif (array_key_exists($name, static::$aliases)) {
			// might not have been pulled, but we don't care
			return $this->data[ static::$aliases[ $name ] ];

		// Relationship?
		} elseif (array_key_exists($name, static::$relationships)) {
			$relation = static::$relationships[ $name ];
			$className = $relation[1];
			$key = $relation[2];
			$v = $this->data[ static::$aliases[ $relation[0] ] ];
			// Load using specified key ... hope it's been declared as one
			$a = $className::$key( $v );
			if ($a) return $this->data[ $name ] = $a;

		// Foreign alias?
		} elseif (array_key_exists($name, static::$foreignAliases)) {
			$data = static::$foreignAliases[ $name ];
			$localAlias = $data[0];
			$foreignAlias = $data[1];
			// Go through our existing means for pulling and caching relations,
			// so it gets cached in $this->data, then return the specific field
			$a = $this->$localAlias;
			return $a->$foreignAlias;
		}
		return null;
	}

	public function __set($name, $value) {
		// I don't like loading via primary/unique key using an assignment.
		// Plus, it's hard to report "row not found" using that technique
		// and I didn't want to throw an exception.
		// Article::ID(1234) is the way we load now

		// Perhaps prevent modifying primary key field(s), but that feels like baby-sitting ...
		if (array_key_exists($name, static::$aliases)) { // Set field value
			$field = static::$aliases[ $name ];
			$this->changed[] = $field; // So our update SQL contains only changed fields
		} else $field = $name;
		// This assignment is not within the above conditional so programmers can
		// annotate this Norma instance with extra data if need be. It's handy.
		$this->data[ $field ] = $value;
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
			return new NormaFind($className, $where );
		}
		return null;
	}

	// So you can do: $u = User::ID(1)
	// Loads User record with primary key of 1 from database and returns an instance of User with that data
	// THE ONLY WAY TO LOAD A ROW FROM THE DB
	public static function __callStatic($name, $args) {
		// Multiple fields in $name?
		$fields = explode('_', $name);
		$staticPK = (is_array(static::$pk) ? static::$pk : array(static::$pk));
		$a = array_intersect($staticPK, $fields);
		$b = array_intersect(static::$keys, $fields);
		/*
		if ($name == static::$pk || in_array($name, static::$keys)) {
			$sql = static::MakeSql($name, $args[0]);
		*/
		$where = array();
		$parameters = array();
		if (sizeof($a) == sizeof($staticPK)) {
			foreach($staticPK as $i => $key) {
				$where[] = '`' . static::$aliases[ $key ] . '`=?';
				$parameters[] = $args[ $i ];
			}
		}
		if (sizeof($b) == sizeof(static::$keys)){
			foreach(static::$keys as $i => $key) {
				$where[] = '`' . static::$aliases[ $key ] . '`';
				$parameters[] = $args[ $i ];
			}
		}
		if ($where) {
			$sql = 'SELECT * FROM ' . static::$table . ' WHERE ' . implode(' AND ', $where);
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
	public static function Find() {
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
		$changed = array_unique($this->changed);
		$changed = array_intersect( array_values(static::$aliases), $changed);
		// remove $pk from changed
		$data = array();
		foreach ($changed as $field) {
			$data[ $field ] = $this->data[ $field ];
		}
		return $data;
	}
	
	// Still torn about whether this should go through Save() ...
	public function Create() {
		$data = $this->ChangedData();
		// This is only valid for instances where primary key is auto-generated. For
		// multi-field primary key it makes no sense.
		if (static::$pk && !is_array(static::$pk)) {
			$pk = static::$aliases[ static::$pk ];
			// Remove primary key so database can auto-generate it
			unset( $data[ $pk ] );
		}
		// Is this correct to do?
		// Don't some DBMSes support insert without values?
		if (sizeof($data) == 0) return false; // If nothing to save, don't even try ... hmmm,
		$id = Norma::$dbFacile->insert($data, static::$table);
		// $id will be false if insert fails. Up to programmer to care.
		if ($id !== false && static::$pk && !is_array(static::$pk)) {
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
		$pk = static::$aliases[ static::$pk ];
		$data = array(
			$pk => $this->data[ $pk ]
		);
		return Norma::$dbFacile->Delete(static::$table, $data);
	}

	public function Error() {
		return Norma::$dbFacile->error();
	}
	
	protected static function MakeSql($alias, $value) {
		// To hell with doing field mappings in the SQL, because:
		// If we're doing something special under the hood, we can't allow programmers to
		// customize the SQL and still have it work with Norma. Want to keep it simple,
		// and not do anything unexpected. If a coder wants to write a custom WHERE
		// clause and pass it to Norma, I'd like to let him. Granted, we have no mechanism
		// for doing so yet.
		$sql = 'SELECT * FROM ' . static::$table;
		$sql .= ' WHERE ';
		$sql .= '`' . static::$aliases[ $alias ] . '`=' . $value;
		return $sql;	
	}

	// Torn between Create() and Update() or just Save()
	public function Save() {
		// We support Create() for objects without a primary key, but not Save()
		$staticPK = static::$pk;
		if ($staticPK == false) return false;
		$data = $this->ChangedData();
		// There was nothing to save
		if (sizeof($data) == 0) return false;

		// Support single and multi-field primary keys
		$where = array();
		if (!is_array($staticPK)) {
			$staticPK = array($staticPK);
		}
		foreach($staticPK as $key) {
			$where[ $key ] = $this->data[ static::$aliases[$key] ];
		}

		// dbFacile update() returns affected rows
		$a = Norma::$dbFacile->update($data, static::$table, $where);
		return $a;
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
class NormaFind {
	protected $className = null;
	protected $where = array();
	public function __construct($name, $where) {
		$this->className = $name;
		$this->where[] = $where;
	}

	// the logic here is screwy
	// some of these are probably overkill
	/*
	Handles:
	Article::Find(1)->Author()
	Article::Find(1)->Author('T.Permission>?', 2)
	Article::Find(1)->Author()->Where('T.Permission>?', 2)
	Article::Find(1)->Author()->Files()->Where_Author('T.Permission>?', 2)
	*/
	public function __call($name, $args) {

		$parts = explode('_', $name);
		if ($parts[0] != 'Where') { // Joining
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
			// New join table designates final output class, so update it
			$this->className = $className2;
			$className = $this->className;
		} else {
			if ($name == 'Where') $className = $this->className;
			else $className = implode('_', array_slice($parts, 1));
		}
		/*
		Could also support $a->Where_FileUploads() to specify where clause for previously joined tables
		*/
		// Filtering previous join, or where while joining
		if ($args) {
			$where = $args;
			// Grr, having to use non-aliases creates a crappy dependency
			$from = array();
			$to = array();
			foreach (array_keys($className::$aliases) as $alias) {
				$from[] = 'T.' . $alias;
				// escape this
				$to[] = $className::$table . '.' . $className::$aliases[ $alias];
			}
//var_dump($from);var_dump($to);exit;
			$where[0] = str_replace($from, $to, $where[0]);
			$this->where[] = $where;
		}
		return $this;
	}

	public function Done() {
		$className = $this->className;
		$table = $className::$table;

		// What a pain to have to do field aliases in the SQL. ugh
		// backtick these values
		$sql = 'SELECT ' . $table . '.* from ' . $table;
		/*
		Thinking I can support all I need by identifying the type of where clause
		4 elements - join
		3 elements - table, field, scalar
		2 elements - string clause, parameters
		*/

		$wheres = array();
		$parameters = array();
		foreach ($this->where as $where) {
			$sz = sizeof($where);
			if ($sz == 4) {
				$sql .= ' LEFT JOIN ' . $where[0] . ' ON ('
					. $where[0] . '.' . $where[1]
					. '='
					. $where[2] . '.' . $where[3]
					. ')';
			} elseif ($sz == 3) {
				$wheres[] = $where[0] . '.' . $where[1] . '=?';
				$parameters[] = $where[2];
			} elseif ($sz == 2) {
				$wheres[] = $where[0];
				$parameters[] = $where[1];
			}
		}
		if ($wheres) $sql .= ' WHERE ' . implode(' AND ', $wheres);
		//var_dump($sql);var_dump($parameters);exit;

		$rows = Norma::$dbFacile->fetchAll($sql, $parameters);
		$pk = $className::$pk;
		$out = array();
		foreach ($rows as $row) {
			$out[] = new $className($row, false);
		}
		return $out;
	}
}
