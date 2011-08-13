<?php
include('norma.php');
include('/home/alan/coding/projects/dbFacile/dbFacile.php');

/*
create table article (id int(11) auto_increment, title varchar(255), body text, cover_id int(11), thumbnail_id int(11), primary key (id));
create table file (id int(11) auto_increment, name varchar(255), primary key (id));

insert into file (name) value('article-thumb.jpg');
insert into file (name) value('article-cover.jpg');
insert into article (title, cover_id, thumbnail_id) values('test article', 2, 1);
*/

class Article extends Norma {
	protected static $table = 'article';
	protected static $pk = 'ID';
	protected static $keys = array(); // if we wanted to load by other fields
	// not called $fields to suggest the alias should come first in the mapping
	protected static $aliases = array(
		'ID' => 'id',
		'CoverID' => 'cover_id',
		'ThumbnailID' => 'thumbnail_id',
		'Title' => 'title', // varchar 100
		'Body' => 'body'
	);

	// local alias maps to array with local field, remote table, remote field
	// but which class to loas?
	// use aliases or fields? ugh.

	// any other constructs that might make this more bearable? or automatic?
	protected static $relationships = array(
		'CoverImage' => array('File', 'CoverID', 'ID'),
		'Thumbnail' => array('File', 'ThumbnailID', 'ID')
	);

	// through a relationship defined above
	protected static $foreignAliases = array(
		'CoverFileName' => array('CoverImage', 'Name')
	);
}

class File extends Norma {
	protected static $table = 'file';
	protected static $pk = 'ID';
	protected static $keys = array(); // if we wanted to load by other fields
	// not called $fields to suggest the alias should come first in the mapping
	protected static $aliases = array(
		'ID' => 'id',
		'Name' => 'name'
	);
	protected static $relationships = array();
	protected static $foreignAliases = array();
}

$db = dbFacile::open('mysql', 'norma', 'norma', 'norma');
Norma::$db = $db;

$a = new Article();
$a->ID = 1;
//var_dump($a);

$f = $a->Thumbnail;
var_dump($a);
