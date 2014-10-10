Features
====

Note: This documentation may be out of date. Please check the tests folder to be sure, as that's where the latest API is featured.

* Declare field aliases and relations as static variables of Norma-derived classes (see tests/setup.php for setup examples)
* Load Article row having primary key of 123 via $a = Article::ID(123). $a will be null if 123 isn't found.
* Set / get fields: $a->Title = 'New title';
* Create(), Save() and Delete()
* Support for multi-field primary keys
* Support for loading by unique key
* Chain through to a related object: $a->Author->Address->State
* Chain through to related objects (plural) using joins under the hood: $a->Author()->File(array('active'=>1))->Rows() ... Gets all article author's active files.
* Create aliases to fields in far away objects (see $foreignAliases usage in tests/setup.php)
* Load single/first row into object via query: $a = Article::FromQuery('select ...', $params)
* Load multiple: $articles = Article::FindQuery('select ...', $params)
* Load multiple via associative array as the where clause: $rows = Article::Find(array('status'=>'published'))

To load a single row, set up unique keys, or use FromQuery()

Find() accepts a where hash, as do method-joins. To use a string where clause, use Where(string, params)

Ideas
====

What about Article::FindIterator()->...


Goals
====

Want toArray() to return a nested associative array of the current object's data, and data for related objects that have been accessed up until now. The idea is that you can cache this data in memcache.

No objects are returned from toArray()

Dependencies
====

* dbFacile (one of my projects)

Unfinished
====

* Tweak dbFacile to expose escape+quoting methods for each DBMS
* toArray() needs to call toArray() on elements that are Norma objects

Running the Unit Tests
====

* Install PHPUnit
* Go into three/tests
* Run: phpunit CrudTest
* Run: phpunit RelationTest 

Grrrrr
====

What a pain. I want an easy way to merge an array of data into an instantiated object ... but I also want an easy way to create and load an object using a PK or Unique key ... trying to work out both, so it's somewhat intuitive
