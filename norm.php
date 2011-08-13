<?php 

/*

- lazily loads relations
- no joins
- define fields with aliases in a static structure
- define relationships to other class names by field
- can do type conversion
- validation
- caching behavior defined at static class level, integrates with memcache
*/

abstract class Table2 {
	public static $db;
	// You should declare:
	/*
	public static $numeric = array();
	public static $text = array();
	public static $keys = array();
	public static $pk = '';
	public static $relationships = array();
	public static $proxy = array();
	*/
	

	protected $aliases = array();
	protected $primaryKeyValue;
	protected $changed = array();
	protected $properties = array(); // holds local field values
	protected $relationCache = array();

	public function __construct() {
		if (!sizeof($this->aliases)) { // Merge type arrays into this
			$this->aliases = self::$numeric + self::$text;
		}
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
		if (array_key_exists($prop, self::$foreignAliases)) {
			$data = self::$foreignAliases[ $prop ];
			$localAlias = $data[0];
			$foreignAlias = $data[1];
			// Go through our existing means for pulling and caching relations,
			// then return the specific field
			$a = $this->$localAlias;
			return $a->$foreignAlias;
		}
		// Relationsihp
		if (array_key_exists($prop, self::$relationships)) {
			if (array_key_exists($prop, $this->relationCache)) {
				return $this->relationCache[ $prop ];
			}
			$relation = self::$relationships[ $prop ];
			$className = $relation[0];
			$key = $relation[2];
			$a = new $className();
			$a->$key = $this->properties[ $relation[1] ];
			// Should be loaded
			// but what if not? return false?
			return $a;
		}
		// Local field?
		if (array_key_exists($prop, $this->aliases)) {
			// might not have been pulled, but we don't care
			return $this->properties[$prop];
		}
	}

	public function __set($prop, $value) {
		if ($prop == self::$pk || in_array($prop, self::$keys)) { // Open
			// We can check memcache before generating the SQL
			$sql = $this->MakeSql( self::$pk, $this->primaryKeyValue );
			$row = Table2::$db->GetOne($sql);
			// success?
			$this->primaryKeyValue = $value;
			$this->properties = $row;
			// memcache this row, or should DbCon be in charge of that?
			return;
		}
		if (array_key_exists($prop, $this->aliases)) { // Set field value
			$this->changed[] = $prop; // So we can save only the fields that have changed
			$this->properties[ $prop ] = $value;
		}
	}

	// Alphabetical
	public function Create() {
		// basically a save, but with a different query
	}
	
	public function Delete() {
		if (!$this->isNew && $this->GetPK()) {
			$sql = 'DELETE FROM ' . $this->table . ' WHERE ' . $this->fields[$this->pk]->name . '=' . $this->GetPK();
			Table2::$db->Execute($sql);
		}
	}
	
	public function MakeSql($key, $value) {
		$fields = array();
		foreach ($this->aliases as $alias => $field) {
			$fields[] = '`' . $field . '`' . ($alias != $field ? ' AS `' . $alias . '`' : '');
		}
		$sql = 'SELECT ' . implode(', ', $fields);
		// FROM
		$sql .= ' FROM ' . self::$table;
		$sql .= ' WHERE ';
		$sql .= '`' . $key . '`=' . Table2::$db->Escape( $value );
		return $sql;	
	}
	
	public function MakeInsertSql($allownull=false) {
		$changed = array_unique($this->changed);
		// remove $pk from changed
		$sql = 'INSERT ';
		$sql .= ' INTO `' . self::$table . '` (';
		$fields = array();
		foreach ($this->changed as $field) {
			$fields[] = '`' . $this->aliases[ $field ] . '`';	
		}
		$sql .= implode(', ', $fields);
		$sql .= ') VALUES (';
		foreach ($this->changed as $field) {
			$fields[] =Table2::$db->Escape($this->properties[ $field ]);
		}
		$sql .= implode(', ', $fields);
		$sql .= ')';	
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
		if (!$this->properties[ self::$pk ]) return false;
		$sql = 'UPDATE `' . self::$table . '` SET ';
		$changed = $this->changed;
		// remove pk
		$fields = array();
		foreach ($changed as $field) {
			$fields[] = '`' . $field . '`=' . Table2::$db->escape( $this->properties[ $field ] );
		}
		$sql .= implode(', ', $fields);
		$sql .= ' WHERE ' . self::$pk . '=' . Table2::$db->escape( $this->properties[ self::$pk ] );
		//echo $sql;
		return Table2::$db->Execute($sql, $this->table);
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
		return $this->db->Escape($this->properties[$prop], $allownull);
	}
/*	
	public function Clear() {
		$this->properties = array();
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
		return $this->properties;
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

