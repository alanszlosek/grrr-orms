Features
====

* Declare fields and relations as static variables of Norma-derived classes (see tests folder for test examples)
* Set / get fields: $articleInstance->Title = 'New title';
* Create() and Save()
* Chain through to a related object: $articleInstance->Author->Address->State
* NEW: Chaining to get an array of related objects (uses joins)
* Can create aliases to fields in related records/objects (see $foreignAliases in tests)

Goals
====

Want toArray() to return a nested associative array of the current object's data, and data for related objects that have been accessed up until now. The idea is that you can cache this data in memcache.

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
* Go into one/tests
* Run: phpunit ReadTest
