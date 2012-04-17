<?php
include('../norma.php');
include('/home/alan/coding/projects/dbFacile/src/dbFacile_sqlite3.php');

/*
create table article (id int(11) auto_increment, title varchar(255), body text, cover_id int(11), thumbnail_id int(11), primary key (id));
create table file (id int(11) auto_increment, name varchar(255), primary key (id));

insert into file (name) value('article-thumb.jpg');
insert into file (name) value('article-cover.jpg');
insert into article (title, cover_id, thumbnail_id) values('test article', 2, 1);
*/

class Article extends Norma {
	public static $table = 'article';
	protected static $pk = 'ID';
	// can load by PK, or these keys
	protected static $keys = array(); // if we wanted to load by other fields
	// not called $fields to suggest the alias should come first in the mapping
	public static $aliases = array(
		'ID' => 'id',
		'AuthorID' => 'author_id',
		'CoverID' => 'cover_id',
		'ThumbnailID' => 'thumbnail_id',
		'Title' => 'title', // varchar 100
		'Body' => 'body'
	);

	// local alias maps to array with local field, remote table, remote field
	// but which class to loas?
	// use aliases or fields? ugh.

	// any other constructs that might make this more bearable? or automatic?
	public static $relationships = array(
		// Alias => array(LocalField, Table, RemoteField
		'CoverImage' => array('CoverID', 'File', 'ID'),
		'Thumbnail' => array('ThumbnailID', 'File', 'ID'),
		'Author' => array('AuthorID', 'User', 'ID')
	);

	// through a relationship defined above
	protected static $foreignAliases = array(
		'CoverFileName' => array('CoverImage', 'Name')
	);
}

class User extends Norma {
	public static $table = 'user';
	protected static $pk = 'ID';
	protected static $keys = array(); // if we wanted to load by other fields
	// not called $fields to suggest the alias should come first in the mapping
	public static $aliases = array(
		'ID' => 'id',
		'Name' => 'name'
	);
	public static $relationships = array(
		'File' => array('ID', 'File', 'UserID')
	);
	protected static $foreignAliases = array();
}

class File extends Norma {
	public static $table = 'file';
	protected static $pk = 'ID';
	protected static $keys = array(); // if we wanted to load by other fields
	// not called $fields to suggest the alias should come first in the mapping
	public static $aliases = array(
		'ID' => 'id',
		'Name' => 'name',
		'UserID' => 'user_id'
	);
	public static $relationships = array();
	protected static $foreignAliases = array();
}


class Log {
	public static $lines = array();

	public function Output() {
		echo implode("\n", Log::$lines) . "\n";
	}
}



class ReadTest extends PHPUnit_Framework_TestCase {
	public static function setUpBeforeClass() {
		$db = Norma::$dbFacile;
		$sql = array();
		$sql[] = 'drop table if exists article';
		$sql[] = 'drop table if exists file';
		$sql[] = 'drop table if exists user';
		$sql[] = 'create table article (id integer primary key autoincrement, title varchar(255), body text, cover_id int(11), thumbnail_id int(11), author_id int(11))';
		$sql[] = 'create table file (id integer primary key autoincrement, name varchar(255), user_id int(11))';
		$sql[] = 'create table user (id integer primary key autoincrement, name varchar(255))';
		$sql[] = "insert into file (name, user_id) values('article-thumb.jpg', 1)";
		$sql[] = "insert into file (name, user_id) values('article-cover.jpg', 1)";
		$sql[] = "insert into article (title, cover_id, thumbnail_id, author_id) values('test article', 2, 1, 1)";
		$sql[] = "insert into user (name) values('john day')";
		foreach ($sql as $s) $db->execute( $s );
	}


	public function testRelation() {
		$a = new Article();
		$a->ID = 1;

		$b = $a->Author()->File()->Rows();
		var_dump($b);
		exit;
		$this->assertEquals( get_class($thumb), 'File');
	}
}

$db = new dbFacile_sqlite3();
$db->open('./norma.sqlite');
Norma::$dbFacile = $db;

