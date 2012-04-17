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
	/*
	public static $aliases = array();
	public static $keys = array();
	public static $pk = '';
	public static $relationships = array();
	public static $foreignAliases = array();
	*/
	
	// so we only update fields that have changed
	protected $changed = array();
	// This is where all of the database data lives
	protected $data = array();

	public function __construct($data = array()) {
		if ($data) $this->data = $data; // merge in data
	}

	public function __get($prop) {
		/*
		Perhaps order should be:
		- check $this->cache
		- check $this->relationship
		- check foreign aliases

		Needs thinking.
		*/
		// Foreign alias?
		if (array_key_exists($prop, static::$foreignAliases)) {
			$data = static::$foreignAliases[ $prop ];
			$localAlias = $data[0];
			$foreignAlias = $data[1];
			// Go through our existing means for pulling and caching relations,
			// then return the specific field
			$a = $this->$localAlias;
			return $a->$foreignAlias;
		}
		// Relationship
		if (array_key_exists($prop, static::$relationships)) {
			if (array_key_exists($prop, $this->data)) { // something's in the cache with this name
				$value = $this->data[ $prop ];
				if (is_object($value)) return $value; // object has already been pulled and is cached
				if (is_array($value)) { // looks like row data, but we need to return an object
					$relation = static::$relationships[ $prop ];
					$className = $relation[1];
					$a = new $className($value);
					$this->data[ $prop ] = $a;
					return $a;
				}
				// uhhh
			} else {
				$relation = static::$relationships[ $prop ];
				$className = $relation[1];
				$key = $relation[2];
				$a = new $className();
				// load using primary key
				$a->$key = $this->data[ $relation[0] ];
				$this->data[ $prop ] = $a;
			}
			// Should be loaded
			// but what if not? return false?
			return $a;
		}
		// Local field?
		if (array_key_exists($prop, static::$aliases)) {
			// might not have been pulled, but we don't care
			return $this->data[$prop];
		}
		return null;
	}

	public function __set($prop, $value) {
		if ($prop == static::$pk || in_array($prop, static::$keys)) { // Open ... MAKE SURE WE'RE NOT ALREADY LOADED or do we care?
			// We can check memcache before generating the SQL
			$sql = $this->MakeSql( $prop, $value );
			$row = Norma::$dbFacile->fetchRow($sql);
			// success?
			$this->primaryKeyValue = $value; // eh
			$this->data = $row;
			// memcache this row, or should DbCon be in charge of that?
			return;
		}
		if (array_key_exists($prop, static::$aliases)) { // Set field value
			$this->changed[] = $prop; // So we can save only the fields that have changed
			$this->data[ $prop ] = $value;
		}
	}

	// $article->Author()->File()
	// select file.* from file left join author on (author.id=file.user_id) where author.id = ?
	public function __call($name, $args) {
		if (array_key_exists($name, static::$relationships)) {
			$relationship = static::$relationships[ $name ];
			/*
			var_dump($relationship);
			exit;
			*/
			$className = $relationship[1];
			// Pass in current join fields and values
			$where = array(
				// Get table name from foreign class
				$className::$table,
				// Get foreign field name from foreign class
				$className::$aliases[ $relationship[2] ],
				// Value from local object
				$this->data[ $relationship[0] ]
				/*
				static::$table,
				// Get local field name
				static::$aliases[ $relationship[0] ],
				*/
			);
			// need to pass current object and field too

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
	
	// really should build offbase array classes so casting works
	public function toArray() {
		// walk $data, add data from relationCache too
		// want this to only contain arrays and scalars, no objects
		return $this->data;
	}

	// Alphabetical
	protected function ChangedData() {
		$changed = array_unique($this->changed);
		// remove $pk from changed
		$data = array();
		foreach ($this->changed as $field) {
			$data[ static::$aliases[ $field ] ] = $this->data[ $field ];
		}
		return $data;
	}
	
	public function Create() {
		/*
		$sql = 'INSERT ';
		$sql .= ' INTO `' . static::$table . '` (';
		$sql .= implode(', ', $fields);
		$sql .= ') VALUES (' . implode(',', $places) . ')';
		*/
		$data = $this->ChangedData();
		$id = Norma::$dbFacile->insert($data, static::$table);
		return $id;
	}
	
	/*
	public function Delete() {
		if (!$this->isNew && $this->GetPK()) {
			$sql = 'DELETE FROM ' . $this->table . ' WHERE ' . $this->fields[$this->pk]->name . '=' . $this->GetPK();
			Norma::$dbFacile->Execute($sql);
		}
	}
	*/
	
	protected function MakeSql($key, $value) {
		$fields = array();
		foreach (static::$aliases as $alias => $field) {
			$fields[] = '`' . $field . '`' . ($alias != $field ? ' AS `' . $alias . '`' : '');
		}
		$sql = 'SELECT ' . implode(', ', $fields);
		// FROM
		$sql .= ' FROM ' . static::$table;
		$sql .= ' WHERE ';
		//$sql .= '`' . $key . '`=' . Norma::$dbFacile->Escape( $value );
		$sql .= '`' . static::$aliases[ $key ] . '`=' . $value;
		return $sql;	
	}
	
	public function Save($allownull=false) {
		$data = $this->ChangedData();
		$a = Norma::$dbFacile->update($data, static::$table, static::$aliases[ static::$pk ], $this->data[ static::$pk ]);
		
		return $a;
		
		if (!$this->data[ static::$pk ]) return false;
		$sql = 'UPDATE `' . static::$table . '` SET ';
		$changed = $this->changed;
		// remove pk
		$fields = array();
		foreach ($changed as $field) {
			$fields[] = '`' . static::$aliases[ $field ] . "`='" . mysql_real_escape_string( $this->data[ $field ] ) . "'";
		}
		$sql .= implode(', ', $fields);
		$sql .= ' WHERE ' . static::$aliases[ static::$pk ] . "='" . mysql_real_escape_string( $this->data[ static::$pk ] ) . "'";
		return Norma::$dbFacile->execute($sql, $this->table);
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

		$this->className = $name;
		// mergin where items
		return $this;
	}

	public function Rows() {
		$className = $this->className;
		$table = $className::$table;

		$parameters = array();
		// backtick these values
		$sql = 'SELECT ' . $table . '.* from ' . $table;

		$fin = array_shift($this->where); // last where will be part of where clause

		foreach ($this->where as $where) {
			/*
			if (sizeof($where) == 3) {
				$sql .= ' LEFT JOIN ' . $where[0] . ' ON ('
					. $where[0] . '.' . $where[1]
					. '=?'
					. ')';
				$parameters[] = $where[2];
			}
			*/
			if (sizeof($where) == 4) {
				$sql .= ' LEFT JOIN ' . $where[0] . ' ON ('
					. $where[0] . '.' . $where[1]
					. '='
					. $where[2] . '.' . $where[3]
					. ')';
			}
		}

		$sql .= ' WHERE ' . $fin[0] . '.' . $fin[1] . '=?';
		$parameters[] = $fin[2];

		$rows = Norma::$dbFacile->fetchAll($sql, $parameters);
		$out = array();
		foreach ($rows as $row) {
			$out[] = new $className($row);
		}
		return $out;
	}
}
