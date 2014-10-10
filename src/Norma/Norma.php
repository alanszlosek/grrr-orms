<?php
namespace Norma;

/*
Create single insta

THOUGHTS
- Kinda sucks that data is indexed by db table name, not alias name. But that's because i wanted it to be easy to push data into an instance. Probably should index $this->data by alias in the near future, since that should shave some time off lookups for getting and setting. We'll be getting and setting fields more frequently than loading from the DB, so that might be a nice gain. I'm sure there was some reason that I went the other route, but can't recall now. Reminds me, I should probably
  push the lookup into a method so I can easily change and test later.


OrderBy should take two params normally, but if doing joins require params in multiples of 3. Class name, alias, direction

Limit() is like DDB limit.

Object() / First() use limit(1) internally.

Objects()

Decided that allowing string where clauses is too much trouble. Use where hashes, or roll your own query for FindQuery().
Wonder if Find() should take where hash, or full query string. Rather, be more specific:

Article::Where()->Objects()
Article::Query($sql)->Object()
Article::Select($where) or query string
Maybe Find() makes sense after all
Article::OrderBy()->Objects()
Article::Limit(3)->Objects()
*/

abstract class Norma
{
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

    public function __set($field, $value)
    {
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
    public function __call($name, $args)
    {
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
    public static function __callStatic($name, $args)
    {
        // We do allow lookup by more than 1 field, but that assumes our aliases don't have underscores
        $fields = explode('_', $name);
        // Maybe we need static::getPK() so we can always return an array, and make sure it's set as such
        $staticPK = static::GetPK();
        $a = array_intersect($staticPK, $fields);
        $b = array_intersect(static::$keys, $fields); // lookup by other keys? i'm confused
        $where = array();
        // If any of the PKs are matched ... fail if not all are provided
        if (sizeof($a) == sizeof($staticPK)) {
            foreach ($staticPK as $i => $key) {
                $where[] = static::PrepareField(static::GetAliasMap($key)) . '=' . static::PrepareValue($args[$i]);
            }
        }
        if (sizeof($b) == sizeof(static::$keys)) {
            foreach (static::$keys as $i => $key) {
                $where[] = static::PrepareField(static::GetAliasMap($key)) . '=' . static::PrepareValue($args[$i]);
            }
        }
        if ($where) {
            // list fields rather than *?
            $sql = 'SELECT * FROM ' . static::PrepareField(static::$table) . ' WHERE ' . implode(' AND ', $where);
            if (Norma::$debug) trigger_error('Norma SQL: ' . $sql, E_USER_NOTICE);
            $row = Norma::$dbFacile->fetchRow($sql);
            if (!$row) {
                return null;
            } else {
                return new static($row, false);
            }
        } else {
            throw new \Exception('Expected ' . $name . ' to be primary or unique key');
        }

        return null;
    }

    /*
    Find*() methods return a NormaFind instance, which can be used to fetch 1 or more objects

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

    // Variable-length args
    public static function FindQuery()
    {
        $n = new NormaFind(get_called_class());
        $n->QueryAndCache(func_get_args());

        return $n;
    }

    // Variable-length args
    public static function FromQuery()
    {
        $row = call_user_func_array(array(Norma::$dbFacile, 'fetchRow'), func_get_args());

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

    protected static function GetAliasMap($name)
    {
        return static::$aliases[ $name ];
    }

    protected function GetData($name)
    {
        return $this->data[ static::GetAliasMap($name) ];
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

    public function Create()
    {
        $data = $this->ChangedData();
        if (Norma::$debug) trigger_error('Norma Insert: ' . json_encode($data), E_USER_NOTICE);
        // Trap duplicate key exceptions
        $id = Norma::$dbFacile->insert(static::$table, $data);
        // $id will be false if insert fails. Up to programmer to care.
        // There should be auto-key-gen if PK isn't 1, right?
        $staticPK = static::GetPK();
        if ($id !== false && sizeof($staticPK) == 1) {
            $pk = static::GetAliasMap($staticPK[0]);
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
        $where = array();
        $staticPK = static::GetPK();
        foreach ($staticPK as $key) {
            $where[ $key ] = $this->GetData($key);
        }
        if (Norma::$debug) trigger_error('Deleting ' . static::$table . ' ' . json_encode($where), E_USER_NOTICE);
        return Norma::$dbFacile->Delete(static::$table, $where);
    }

    public function Error()
    {
        return Norma::$dbFacile->error();
    }

    // Torn between Create() and Update() or just Save()
    public function Save()
    {
        // We support Create() for objects without a primary key, but not Save()
        if (static::GetPK() == false) return false;
        $data = $this->ChangedData();
        // There was nothing to save
        if (sizeof($data) == 0) return false;

        // Support single and multi-field primary keys
        $where = array();
        $staticPK = static::GetPK();
        foreach ($staticPK as $key) {
            $where[ $key ] = $this->GetData($key);
        }

        // dbFacile update() returns affected rows
        if (Norma::$debug) trigger_error('Norma update: ' . json_encode($data), E_USER_NOTICE);
        $a = Norma::$dbFacile->update(static::$table, $data, $where);

        return $a;
    }

    public static function Relationship($name)
    {
        return static::$relationships[ $name ];
    }
    public static function Table()
    {
        return static::$table;
    }
    public static function AliasToField($name)
    {
        return static::$aliases[ $name ];
    }
    public static function Aliases()
    {
        return static::$aliases;
    }
    public static function GetPK()
    {
        if (static::$pk === false) {
            // not sure i like the side-effects of this, but it's handy
            static::$pk = array();
        } elseif(!is_array(static::$pk)) {
            static::$pk = array(static::$pk);
        }

        return static::$pk;
    }

    public static function PrepareField($field, $field2 = null)
    {
        if ($field2) {
            return Norma::$dbFacile->quoteField($field) . '.' . Norma::$dbFacile->quoteField($field2);
        } else {
            return Norma::$dbFacile->quoteField($field);
        }
    }
    public static function PrepareValue($value)
    {
        return static::$dbFacile->quoteEscapeString($value);
    }
}

// Grr, this needs outside access to relationships and table, etc

/*
TODO
* Allow chaining through to same Class more than once, passing additional where clauses
* Should we allow string-based where clauses? ... really don't want to parse them in order to prepend table name
* Use NormaChain as Find() ... Article::Find()
    Find() accepts where hash. That's the only way you can add where clauses to joins. Too much work to support string where clauses, just write the query yourself.
    * Can then chain to perform joins

Chaining isn't exactly a join. You don't get back data from all joined tables, just the last one.

Treat each NormaFind instance as an array ... fetch keys, foreach over it, get count/sizeof

*/
class NormaFind implements \Iterator, \ArrayAccess, \Countable
{
    protected $className = null;
    protected $joins = array();
    protected $whereHash = array();
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

    public function __call($name, $args)
    {
        // get current class
        $className = $this->className;
        // Tie previous class to new
        $relationship = $className::Relationship($name);
        $className2 = $relationship[1];
        $this->joins[] = array(
            $className::Table(),
            $className::AliasToField( $relationship[0] ),
            $className2::Table(),
            $className2::AliasToField( $relationship[2] ),

        );
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

    public function Objects()
    {
        $this->Done();

        return $this->data;
    }
    public function Rows()
    {
        $this->Done();

        return $this->data;
    }

    // Fixup where clause according to class name
    protected function MergeWhereHash($whereHash, $className = null)
    {
        if (!$className) $className = $this->className;
        foreach ($whereHash as $key => $value) {
            $field = \Norma\Norma::PrepareField($className::Table(), $className::AliasToField($key));
            $this->whereHash[ $field ] = $value;
        }
    }

    // Done with Find() and method chaining
    public function MakeQueryParts()
    {
        $className = $this->className;
        $table = $className::Table();

        // What a pain to have to do field aliases in the SQL. ugh
        $table = \Norma\Norma::PrepareField($table);
        $sql = 'SELECT ' . $table . '.* FROM ' . $table;

        /*
        Thinking I can support all I need by identifying the type of where clause
        4 elements - join
        3 elements - table, field, scalar
        2 elements - string clause, parameters
        */

        $where_pairs = array();
        foreach ($this->joins as $where) {
            $sz = sizeof($where);
            if ($sz == 4) {
                $sql .= ' LEFT JOIN ' . \Norma\Norma::PrepareField($where[0]) . ' ON ('
                    . \Norma\Norma::PrepareField($where[0], $where[1])
                    . '='
                    . \Norma\Norma::PrepareField($where[2], $where[3])
                    . ')';
            } elseif ($sz == 3) {
                $sql .= ' LEFT JOIN ' . \Norma\Norma::PrepareField($where[0]);
                // Wish there was a nice way to let dbFacile wrap ticks around Table.Field later so we don't have to worry about it
                $where_pairs[] = $where[0] . '.' . $where[1] . '=';
                $where_pairs[] = $where[2];
            }
        }
        // need to add where IN support to dbFacile

        // Where hash too
        foreach ($this->whereHash as $key => $value) {
            $where_pairs[] = $key . '=';
            $where_pairs[] = $value;
        }

        $parts = array();
        if ($where_pairs) {
            $sql .= ' WHERE ';
            while ($where_pairs) {
                $parts[] = $sql . array_shift($where_pairs);
                $parts[] = array_shift($where_pairs); // shift off the value that will be quoted+escaped
                $sql = ' AND ';
            }
        } else {
            $parts[] = $sql;
        }
        $sql = '';

        if ($this->_orderBy) $sql .= ' ORDER BY ' . $this->_orderBy;
        if ($this->_limit) $sql .= ' LIMIT ' . $this->_limit;
        if ($sql) {
            $parts[] = $sql;
        }

        return $parts;
    }

    public function QueryAndCache($queryParts)
    {
        $className = $this->className;
        $rows = call_user_func_array(array(Norma::$dbFacile, 'fetchAll'), $queryParts);
        $out = array();
        foreach ($rows as $row) {
            $out[] = new $className($row, false);
        }
        $this->data = $out;
    }

    protected function Done()
    {
        if ($this->data === null) {
            $parts = $this->MakeQueryParts();
            $this->QueryAndCache($parts);
        }
        return $this;
    }

    // Iterator interface methods
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
        return key($this->data);
    }
    public function next()
    {
        $this->Done();
        return next($this->data);
    }
    public function valid()
    {
        $this->Done();
        return $this->current() !== false;
    }

    // ArrayAccess interface methods
    public function offsetExists($offset)
    {
        $this->Done();
        return isset($this->data[$offset]);
    }
    public function offsetGet($offset)
    {
        $this->Done();
        return $this->data[$offset];
    }
    // offsetSet and offsetUnset() should probably be no-ops
    public function offsetSet($offset, $value)
    {
    }
    public function offsetUnset($offset)
    {
    }

    // Countable interface methods
    public function count() {
        $this->Done();
        return count($this->data);
    }
}
