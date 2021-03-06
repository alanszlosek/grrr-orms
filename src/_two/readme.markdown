Features
====

* Declare field aliases and relations as static variables of Norma-derived classes (see tests/setup.php for setup examples)
* Load Article with primary key of 123 via $a = Article::ID(123). $a will be null if 123 isn't found.
* Set / get fields: $a->Title = 'New title';
* Create() and Save()
* Support for multi-field primary keys
* Support for loading by unique key
* Chain through to a related object: $a->Author->Address->State
* Chain through to related objects (plural) using joins under the hood: $a->Author()->File()->All() ... Gets all article author's files.
* Create aliases to fields in far away objects (see $foreignAliases usage in tests/setup.php)
* Load single/first row into object via query: $a = Article::QueryOne('select ...', $params)
* Load multiple: $articles = Article::QueryMany('select ...', $params)
* Load multiple via where clause: $rows = Article::WhereMany('...', $params)

To load a single row, set up unique keys, or use FromQuery()

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
* Delete()
* toArray() needs to call toArray() on elements that are Norma objects

Running the Unit Tests
====

* Install PHPUnit (what a PITA)
* Go into two/tests
* Run: phpunit CrudTest
* Run: phpunit RelationTest 

Grrrrr
====

What a pain. I want an easy way to merge an array of data into an instantiated object ... but I also want an easy way to create and load an object using a PK or Unique key ... trying to work out both, so it's somewhat intuitive
