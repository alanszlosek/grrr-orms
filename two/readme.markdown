testing chaining through relationships, returning an array of objects ... and using joins

Features
====

* Declare fields and relations as static variables of Norma-derived classes (see unit-tests)
* No joins are used
* Set / get fields: $articleInstance->Title = 'New title';
* Create() and Save()
* Lazy loading of related records
* Chain through to a related object: $articleInstance->Author->Address->State
* Can create aliases to fields in related records/objects (see $foreignAliases in tests)
* Should never go over 200 lines

Goals
====

Want toArray() to return a nested associative array of the current object's data, and data for related objects that have been accessed up until now. The idea is that you can cache this data in memcache.

Dependencies
====

* dbFacile so we're not tied to 1 dbms

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
