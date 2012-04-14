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
	public static $db;
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
					$className = $relation[0];
					$a = new $className($value);
					$this->data[ $prop ] = $a;
					return $a;
				}
				// uhhh
			} else {
				$relation = static::$relationships[ $prop ];
				$className = $relation[0];
				$key = $relation[2];
				$a = new $className();
				// load using primary key
				$a->$key = $this->data[ $relation[1] ];
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
			$sql = $this->MakeSql( static::$pk, $value );
			$row = Norma::$db->fetchRow($sql);
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
	
	// really should build offbase array classes so casting works
	public function toArray() {
		// walk $data, add data from relationCache too
		// want this to only contain arrays and scalars, no objects
		return $this->data;
	}

	// Alphabetical
	public function Create() {
		$changed = array_unique($this->changed);
		// remove $pk from changed
		$sql = 'INSERT ';
		$sql .= ' INTO `' . static::$table . '` (';
		$fields = array();
		foreach ($this->changed as $field) {
			$fields[] = '`' . static::$aliases[ $field ] . '`';	
		}
		$sql .= implode(', ', $fields);
		$sql .= ') VALUES (';
		$fields = array();
		foreach ($this->changed as $field) {
			$fields[] = "'" . mysql_real_escape_string($this->data[ $field ]) . "'";
		}
		$sql .= implode(', ', $fields);
		$sql .= ')';	
		return $sql;
		return Norma::$db->execute($sql, $this->table);
	}
	
	/*
	public function Delete() {
		if (!$this->isNew && $this->GetPK()) {
			$sql = 'DELETE FROM ' . $this->table . ' WHERE ' . $this->fields[$this->pk]->name . '=' . $this->GetPK();
			Norma::$db->Execute($sql);
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
		//$sql .= '`' . $key . '`=' . Norma::$db->Escape( $value );
		$sql .= '`' . static::$aliases[ $key ] . '`=' . $value;
		return $sql;	
	}
	
	public function Save($allownull=false) {
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
		return Norma::$db->execute($sql, $this->table);
	}
}
