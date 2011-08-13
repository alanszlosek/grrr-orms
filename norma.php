<?php 

/*

- lazily loads relations
- no joins
- define fields with aliases in a static structure
- define relationships to other class names by field
- can do type conversion
- validation
- caching behavior defined at static class level, integrates with memcache

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

*/

abstract class Norma {
	public static $db;
	// You should declare:
	/*
	public static $aliases = array();
	public static $keys = array();
	public static $pk = '';
	public static $relationships = array();
	public static $proxy = array();
	*/
	
	//protected $className;
	protected $changed = array();
	// This is where all of the database data lives
	protected $data = array();

	public function __construct($data = array()) {
		//$this->className = get_class($this);
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
	
	public function __toArray() {
		// walk $data, add data from relationCache too
		// want this to only contain arrays and scalars, no objects
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
	
	public function Delete() {
		if (!$this->isNew && $this->GetPK()) {
			$sql = 'DELETE FROM ' . $this->table . ' WHERE ' . $this->fields[$this->pk]->name . '=' . $this->GetPK();
			Norma::$db->Execute($sql);
		}
	}
	/*	
	public function Escape($value) {
		return Norma::$db->_escape
	}
	*/
	
	public function MakeSql($key, $value) {
		$fields = array();
		foreach (static::$aliases as $alias => $field) {
			$fields[] = '`' . $field . '`' . ($alias != $field ? ' AS `' . $alias . '`' : '');
		}
		$sql = 'SELECT ' . implode(', ', $fields);
		// FROM
		$sql .= ' FROM ' . static::$table;
		$sql .= ' WHERE ';
		//$sql .= '`' . $key . '`=' . Norma::$db->Escape( $value );
		$sql .= '`' . $key . '`=' . $value;
		echo $sql . "\n";
		return $sql;	
	}
	
	public function Save($allownull=false) {
		/*
		if ($this->isNew && !$this->GetPK()) {
			$sql = $this->MakeInsertSql($allownull);
			if ($this->db->Execute($sql, $this->table)) {
				if ($this->isBulk) {
					$this->SetPK($this->db->InsertID(), false);
				} else {
					$this->SetPK($this->db->InsertID());
				}
			} else {
				throw new Exception("Could not save. $sql " . $this->db->LastError());
			}
		*/
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
		echo $sql . "\n";
		return Norma::$db->execute($sql, $this->table);
	}
	
	/*
	public function Exists($field, $value) {
		$sql = 'SELECT ' . $this->fields[$this->pk]->name . ' FROM ' . $this->table . ' WHERE';
		if (is_array($field)) {
			for ($i=0; $i < count($field); $i++) {
				$sql .= ' ' . $this->fields[$field[$i]]->name . '=' . $this->db->escape($value[$i]) . ' AND ';	
			}
			$sql = substr($sql, 0, strlen($sql) - 5);
			$sql .= ' LIMIT 1';
		} else {
			 $sql .= ' ' . $this->fields[$field]->name . '=' . $this->db->escape($value) . ' LIMIT 1';
		 }
		//echo $sql;
		if ($id = $this->db->GetOne($sql)) {
			return $id;	
		} else {
			return false;	
		}
	}
	*/
	
	public function Escape($prop, $allownull=false) {
		return $this->db->Escape($this->data[$prop], $allownull);
	}
/*	
	public function Clear() {
		$this->data = array();
		$this->old = array();
	}
	
	public function SetPKField($displayname, $name, $type, $length=null, $as=null, $default=null) {
		$field = new Field($displayname, $name, $type, $length, $as, $default, false, null);
		$field->unique = true;
		$field->primary = true;
		$this->unique[] = $field->as;
		$this->pk = $field->as;
		$this->fields[$field->as] = $field;
		return $field;
	}
	
	private function clean($str) {
		$str = str_replace("&", "&amp;", $str);
		$str = str_replace("<", "&lt;", $str);
		$str = str_replace(">", "&gt;", $str);
		return  stripslashes($str);	
	}

*/
	public function ToArray() {
		return $this->data;
	}
}

class Field {
	
	public $displayname;
	public $name;
	public $as;
	public $type;
	public $minlength = null;
	public $maxlength;
	public $enum;
	public $null;
	public $allownull;
	public $default;
	public $unique = false;
	public $primary = false;
	public $api_visible = true;
	
	function __construct($displayname, $name, $type, $length=null, $as=null, $default=null, $allownull=false, $enum=null) {
		$this->displayname = $displayname;
		$this->name = $name;
		$this->type = $type;
		$this->maxlength = $length;
		if ($as == null) $this->as = $name;
		else $this->as = $as;
		$this->enum = $enum;
		if ($allownull) {
			$this->allownull = true;
			$this->default = $default;
		} else {
			$this->allownull = 	false;
			if ($default == null) {
				if ($type == 'string') $default = '';
				elseif ($type == 'integer') $default = 0;
				elseif ($type == 'double') $default = 0;
				elseif ($type == 'float') $default = 0;
				elseif ($type == 'currency') $default = 0;
				elseif ($type == 'boolean') $default = 0;
				elseif ($type == 'enum') $default = $enum[0]->key;
				elseif ($type == 'date') $default = '0000-00-00';
				elseif ($type == 'datetime') $default = '0000-00-00 00:00:00';
				else $default = '';
			} else {
				$this->default = $default;	
			}
		}
	}
	
	
}

class CustomField {
	
	public $displayname;
	public $name;
	public $as;
	
	function __construct($displayname, $name, $as) {
		$this->displayname = $displayname;
		$this->name = $name;
		$this->as = $name;
	}
	
}

