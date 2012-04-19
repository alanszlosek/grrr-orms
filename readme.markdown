Introduction
====

A few times a year I end up thinking about ORM variations. This is where they're all going to live. Maybe if I just get them out, eventually they'll leave me alone. More likely I'll need to get far away from web programming first.

I'm excited about my most recent Norma variation, in the 'two' folder. It leverages the great late static binding capabilities found in PHP 5.3 and later.

* Create a class that extends Norma
* static::$table in that class to set the table
* static::$aliases array. Keys are aliases, values are field names from the database table
* Load article record 123 into an Article class instance with: $a = Article::ID(123)
* $a will be null if article 123 doesn't exist
* You can chain through to related rows/objects too, but the advanced features of that are still being worked out
