<?php
include('../norma.php');
include('/home/alan/coding/projects/dbFacile/src/dbFacile_sqlite3.php');
$db = new dbFacile_sqlite3();
$db->open('./norma.sqlite');

/*
include('/home/alan/coding/projects/dbFacile/src/dbFacile_mysql.php');
$db = new dbFacile_mysql();
$db->open('norma', 'norma', 'norma');
*/

Norma::$dbFacile = $db;

class Article extends Norma {
	public static $table = 'article';
	public static $pk = 'ID';
	public static $keys = array(); // if we wanted to load by other fields
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
	public static $foreignAliases = array(
		'CoverFileName' => array('CoverImage', 'Name')
	);
}
class Article2 extends Article {
	public function Title() {
		return $this->Title;
	}
}

class User extends Norma {
	public static $table = 'user';
	public static $pk = 'ID';
	public static $aliases = array(
		'ID' => 'id',
		'Name' => 'name'
	);
	public static $relationships = array(
		'FileUploads' => array('ID', 'File', 'UserID')
	);
}

class File extends Norma {
	public static $table = 'file';
	public static $pk = 'ID';
	public static $aliases = array(
		'ID' => 'id',
		'Name' => 'name',
		'UserID' => 'user_id'
	);
}

class NoPK extends Norma {
	public static $table = 'noPK';
	//public static $pkAutoGenerated = false;
	public static $pk = false;
	public static $aliases = array(
		'ID' => 'id',
		'Name' => 'name'
	);
}


class NonAutoPK extends Norma {
	public static $table = 'nonAutoPK';
	//public static $pkAutoGenerated = false;
	public static $pk = array('ID');
	public static $aliases = array(
		'ID' => 'id',
		'Name' => 'name'
	);
}

class Combo extends Norma {
	public static $table = 'combo';
	public static $pk = array('Key1', 'Key2');
	// shouldn't this automatically apply for array primary keys?
	public static $pkAutoGenerated = false;
	public static $aliases = array(
		'Key1' => 'key1',
		'Key2' => 'key2',
		'Name' => 'name'
	);
}

class UniqueKey extends Norma {
	public static $table = 'uniqueKey';
	public static $keys = array('Key1');
	public static $aliases = array(
		'ID' => 'id',
		'Key1' => 'key1',
		'Name' => 'name'
	);
}




// Wee
class TestSetup extends PHPUnit_Framework_TestCase {
	public static function setUpBeforeClass() {
	//public  function setUp() {
		$db = Norma::$dbFacile;
		$sql = array();
		$sql[] = 'drop table if exists article';
		$sql[] = 'drop table if exists file';
		$sql[] = 'drop table if exists user';
		$sql[] = 'drop table if exists combo';
		$sql[] = 'drop table if exists noPK';
		$sql[] = 'drop table if exists nonAutoPK';
		$sql[] = 'drop table if exists uniqueKey';
		$sql[] = 'drop index if exists user_name';

		if (get_class($db) == 'dbFacile_sqlite3') {

			$sql[] = 'create table article (id integer primary key autoincrement, title varchar(255), body text, cover_id int(11), thumbnail_id int(11), author_id int(11))';
			$sql[] = 'create table file (id integer primary key autoincrement, name varchar(255), user_id int(11))';
			$sql[] = 'create table user (id integer primary key autoincrement, name varchar(255))';
			$sql[] = 'create table combo (key1 integer, key2 integer, name varchar(255))';
			$sql[] = 'create table noPK (id integer, name varchar(255))';
			$sql[] = 'create table nonAutoPK (id integer, name varchar(255))';
			$sql[] = 'create table uniqueKey (id integer primary key autoincrement, key1 integer, name varchar(255))';

			$sql[] = 'create unique index user_name on user (name)';
			$sql[] = 'create unique index nonAutoPK_id on nonAutoPK (id)';

		} elseif (get_class($db) == 'dbFacile_mysql') {

			$sql[] = 'create table article (id integer primary key auto_increment, title varchar(255), body text, cover_id int(11), thumbnail_id int(11), author_id int(11))';
			$sql[] = 'create table file (id integer primary key auto_increment, name varchar(255), user_id int(11))';
			$sql[] = 'create table user (id integer primary key auto_increment, name varchar(255))';
			$sql[] = 'create table combo (key1 integer, key2 integer, name varchar(255))';
			$sql[] = 'create table noPK (id integer, name varchar(255))';
			$sql[] = 'create table nonAutoPK (id integer primary key, name varchar(255))';
			$sql[] = 'create table uniqueKey (id integer primary key auto_increment, key1 integer, name varchar(255))';

			$sql[] = 'create unique index user_name on user (name)';
		}

		$sql[] = "insert into file (name, user_id) values('article1-thumb.jpg', 1)";
		$sql[] = "insert into file (name, user_id) values('article1-cover.jpg', 1)";
		$sql[] = "insert into file (name, user_id) values('article2-thumb.jpg', 1)";
		$sql[] = "insert into file (name, user_id) values('article2-cover.jpg', 1)";
		$sql[] = "insert into article (title, cover_id, thumbnail_id, author_id) values('First Article', 2, 1, 1)";
		$sql[] = "insert into article (title, cover_id, thumbnail_id, author_id) values('Second Article', 4, 3, 1)";
		$sql[] = "insert into user (name) values('john day')";
		$sql[] = "insert into uniqueKey (key1, name) values(123, 'Special')";
		foreach ($sql as $s) $db->execute( $s );
	}
}

