<?php

abstract class Norma
{
    public static $dbFacile;
    // You should declare these in derivative classes:
    protected static $table = null;
    protected static $aliases = array(); // key/value pairs: alias=>field_name_in_table
    protected static $keys = array();
    protected static $pk = 'ID'; // Default alias
    protected static $relationships = array();
    protected static $foreignAliases = array();
    protected static $deleteTheseFirst = array();

    // $changed allows us to perform UPDATE queries on only those fields that have changed
    protected $changed = array(); // Holds DB FIELD NAMES, NOT ALIASES
    // This is where all of the database data lives ... keyed by DB FIELD NAMES, NOT ALIASES
    protected $data = array();

    public static $debug = false;

    public function __construct($data = array(), $flagAsChanged = true)
    {
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

    public function __get($name)
    {
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
            $v = $this->data[ static::$aliases[ $relation[0] ] ];
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

    public function __set($field, $value)
    {
        // Perhaps prevent modifying primary key field(s), but that feels like baby-sitting ...
        if (array_key_exists($field, static::$aliases)) { // Set field value
            $field = static::$aliases[ $field ];
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
    public function __call($name, $args)
    {
        // THIS IS BROKEN NOW
        if (array_key_exists($name, static::$relationships)) {
            $relationship = static::$relationships[ $name ];
            // Pass in current join fields and values
            $whereHash = array(
                // Value from local object
                // We probably need a $this->dataByAlias($relationship[0]) method
                $relationship[2] => $this->data[ static::$aliases[ $relationship[0] ] ]
            );
            //var_dump($whereHash);exit;
            if ($args) {
                foreach ($args as $arg) {
                    $whereHash = array_merge($whereHash, $arg);
                }
            }
            // Pass relationship class name as first param
            return new NormaFind($relationship[1], $whereHash);
        }

        return null;
    }

    // So you can do: $u = User::ID(1)
    // Loads User record with primary key of 1 from database and returns an instance of User with that data
    public static function __callStatic($name, $args)
    {
        // We do allow lookup by more than 1 field, but that assumes our aliases don't have underscores
        $fields = explode('_', $name);
        // Maybe we need static::getPK() so we can always return an array, and make sure it's set as such
        $staticPK = (is_array(static::$pk) ? static::$pk : array(static::$pk));
        $a = array_intersect($staticPK, $fields);
        $b = array_intersect(static::$keys, $fields); // lookup by other keys? i'm confused
        /*
        if ($name == static::$pk || in_array($name, static::$keys)) {
            $sql = static::MakeSql($name, $args[0]);
        */
        $where = array();
        $parameters = array();
        // If any of the PKs are matched ... fail if not all are provided
        if (sizeof($a) == sizeof($staticPK)) {
            foreach ($staticPK as $i => $key) {
                $where[] = '`' . static::$aliases[ $key ] . '`=?';
                $parameters[] = $args[ $i ];
            }
        }
        if (sizeof($b) == sizeof(static::$keys)) {
            foreach (static::$keys as $i => $key) {
                $where[] = '`' . static::$aliases[ $key ] . '`=?';
                $parameters[] = $args[ $i ];
            }
        }
        if ($where) {
            // list fields rather than *?
            $sql = 'SELECT * FROM `' . static::$table . '` WHERE ' . implode(' AND ', $where);
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
    public static function Find($where = array(), $limit = null, $order = null)
    {
        return new NormaFind(get_called_class(), $where);
    }
    public static function FindMany($where = null, $parameters = array())
    {
        $n = new NormaFind(get_called_class());
        if ($where) return $n->Where($where, $parameters);
        return $n;
    }

    public static function FindQuery($sql, $parameters = array())
    {
        $n = new NormaFind(get_called_class());
        $n->Query($sql, $parameters);

        return $n;
    }
    public static function FromQuery($sql, $parameters = array())
    {
        if (Norma::$debug) trigger_error('Norma SQL: ' . $sql, E_USER_NOTICE);
        $row = Norma::$dbFacile->fetchRow($sql, $parameters);

        return new static($row, false);
    }

    public function FromArray($data)
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
    }
    public function Merge($data)
    {
        $this->FromArray($data);
    }

    // NOT SURE HOW THIS SHOULD FUNCTION YET
    // Might need two separate functions ... we have aliases to translate or not. Hmm.
    public function toArray()
    {
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
    public static function Aliases()
    {
        return static::$aliases;
    }
    protected function ChangedData()
    {
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
    public function Create()
    {
        $data = $this->ChangedData();
        if (Norma::$debug) trigger_error('Norma Insert: ' . json_encode($data), E_USER_NOTICE);
        $id = Norma::$dbFacile->insert($data, static::$table);
        // $id will be false if insert fails. Up to programmer to care.
        if ($id !== false && static::$pk && !is_array(static::$pk)) {
            $pk = static::$aliases[ static::$pk ];
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

    public function Delete()
    {
        foreach (static::$deleteTheseFirst as $relation) {
            foreach ($this->$relation() as $a) {
                $a->Delete();
            }
        }

        // Support single and multi-field primary keys
        $staticPK = static::$pk;
        $where = array();
        if (!is_array($staticPK)) {
            $staticPK = array($staticPK);
        }
        foreach ($staticPK as $key) {
            $where[ $key ] = $this->data[ static::$aliases[$key] ];
        }
        if (Norma::$debug) trigger_error('Deleting ' . static::$table . ' ' . json_encode($where), E_USER_NOTICE);
        return Norma::$dbFacile->Delete(static::$table, $where);
    }

    public function Error()
    {
        return Norma::$dbFacile->error();
    }

    protected static function MakeSql($alias, $value)
    {
        // To hell with doing field mappings in the SQL, because:
        // If we're doing something special under the hood, we can't allow programmers to
        // customize the SQL and still have it work with Norma. Want to keep it simple,
        // and not do anything unexpected. If a coder wants to write a custom WHERE
        // clause and pass it to Norma, I'd like to let him. Granted, we have no mechanism
        // for doing so yet.
        $sql = 'SELECT * FROM `' . static::$table . '` WHERE ';
        $sql .= '`' . static::$aliases[ $alias ] . '`=' . $value;

        return $sql;
    }

    // Torn between Create() and Update() or just Save()
    public function Save()
    {
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
        foreach ($staticPK as $key) {
            $where[ $key ] = $this->data[ static::$aliases[$key] ];
        }

        // dbFacile update() returns affected rows
        if (Norma::$debug) trigger_error('Norma update: ' . json_encode($data), E_USER_NOTICE);
        $a = Norma::$dbFacile->update($data, static::$table, $where);

        return $a;
    }

    public static function Relationships()
    {
        return static::$relationships;
    }
    public static function Table()
    {
        return static::$table;
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
class NormaFind implements Iterator
{
    protected $className = null;
    protected $joins = array();
    protected $whereHash = array();
    protected $_where = array();
    protected $parameters = array();
    protected $_limit;
    protected $_orderBy;

    protected $data = null; // holds results of the query
    public function __construct($className, $whereHash = array(), $limit = null, $order = null)
    {
        $this->className = $className;
        // Fixup where hash with correct table names
        if ($whereHash) $this->MergeWhereHash($whereHash);
        $this->_limit = $limit; // String
        $this->_orderBy = $order; // String
    }

    public function __get($name)
    {
        die('Nope');
    }
    public function __set($name, $value)
    {
        die('Nope');
    }

    // the logic here is screwy
    // some of these are probably overkill
    /*
    Handles:
    Article::ID(1)->Author()
    Article::ID(1)->Author('T.Permission>?', 2)
    Article::ID(1)->Author()->Where('T.Permission>?', 2)
    Article::ID(1)->Author()->Files()->Where_Author('T.Permission>?', 2)
    */
    public function __call($name, $args)
    {
        $parts = explode('_', $name);
        if ($parts[0] != 'Where') { // Joining
            // get current class
            $className = $this->className;
            // Tie previous class to new
            $relationships = $className::Relationships();
            $aliases = $className::Aliases();
            $relationship = $relationships[ $name ];
            $className2 = $relationship[1];
            $aliases2 = $className2::Aliases();
            $r = array(
                $className::Table(),
                $aliases[ $relationship[0] ],
                $className2::Table(),
                $aliases2[ $relationship[2] ],

            );
            $this->joins[] = $r;
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
            foreach ($args as $arg) {
                $this->MergeWhereHash($arg);
            }
        }

        return $this;
    }

    public function Where($sql, $parameters=array())
    {
        $this->_where[] = $sql;
        // array_merge?
        foreach ($parameters as $param) {
            $this->parameters[] = $param;
        }

        return $this;
    }

    public function OrderBy($str)
    {
        $this->_orderBy = $str;

        return $this;
    }
    public function Limit($limit, $offset = 0)
    {
        $this->_limit = $offset . ',' . $limit;

        return $this;
    }

    public function Rows()
    {
        $this->Done();

        return $this->data;
    }

    public function End()
    {
        // Placeholder
        return $this;
    }

    // Fixup where clause according to class name
    protected function MergeWhereHash($whereHash, $className = null)
    {
        if (!$className) $className = $this->className;

        // Read aliases from public method
        $aliases = $className::Aliases();
        foreach ($whereHash as $key => $value) {
            // need to do proper escaping and quoting here
            $field = '`' . $className::Table() . '`.`' . $aliases[ $key ] . '`';
            $this->whereHash[ $field ] = $value;
        }
    }

    // Need a way to get the SQL

    // Done with Find() and method chaining
    public function SQL()
    {
        $className = $this->className;
        $table = $className::Table();

        // What a pain to have to do field aliases in the SQL. ugh
        // backtick these values
        $sql = 'SELECT `' . $table . '`.* from `' . $table . '`';
        /*
        Thinking I can support all I need by identifying the type of where clause
        4 elements - join
        3 elements - table, field, scalar
        2 elements - string clause, parameters
        */

        $wheres = $this->_where;
        $parameters = $this->parameters;

        foreach ($this->joins as $where) {
            $sz = sizeof($where);
            if ($sz == 4) {
                $sql .= ' LEFT JOIN `' . $where[0] . '` ON (`'
                    . $where[0] . '`.`' . $where[1]
                    . '`=`'
                    . $where[2] . '`.`' . $where[3]
                    . '`)';
            } elseif ($sz == 3) {
                $sql .= ' LEFT JOIN `' . $where[0] . '`';
                $wheres[] = '`' . $where[0] . '`.`' . $where[1] . '`=?';
                $parameters[] = $where[2];
            }
        }


        foreach ($this->whereHash as $key => $value) {
            // gotta figure out a way to designate whether to quote+escape
            // probably should accept a type designation for aliases, then use '#' placeholders
            // dbFacile will take care of the rest
            if (is_array($value)) {
                $wheres[] = $key . ' IN (' . implode(',', $value) . ')';
            } else {
                $wheres[] = $key . '=?';
                $parameters[] = $value;
            }
        }
        if ($wheres) $sql .= ' WHERE ' . implode(' AND ', $wheres);
        // be careful sql injection
        if ($this->_orderBy) $sql .= ' ORDER BY ' . $this->_orderBy;
        if ($this->_limit) $sql .= ' LIMIT ' . $this->_limit;

        //var_dump($sql);var_dump($parameters);exit;
        /*
        if ($table == 'categoryImage') {
            var_dump($this->joins);exit;
            die($sql);
        }
        */

        return array($sql, $parameters);
    }

    // Done with Find() and method chaining
    protected function Finalize()
    {
        list($sql, $parameters) = $this->SQL();
        if (Norma::$debug) trigger_error('Norma SQL: ' . $sql, E_USER_NOTICE);

        $this->Query($sql, $parameters);
    }

    public function Query($sql, $parameters = array())
    {
        $className = $this->className;
        $rows = Norma::$dbFacile->fetchAll($sql, $parameters);
        $out = array();
        foreach ($rows as $row) {
            $out[] = new $className($row, false);
        }
        $this->data = $out;
    }

    protected function Done()
    {
        if ($this->data === null) $this->Finalize();
    }

    // Iterator implementation methods
    public function rewind()
    {
        $this->Done();
        reset($this->data);
    }

    public function current()
    {
        $this->Done();
        $var = current($this->data);

        return $var;
    }

    public function key()
    {
        $this->Done();
        $var = key($this->data);

        return $var;
    }

    public function next()
    {
        $this->Done();
        $var = next($this->data);

        return $var;
    }

    public function valid()
    {
        $this->Done();
        $var = $this->current() !== false;

        return $var;
    }
}
