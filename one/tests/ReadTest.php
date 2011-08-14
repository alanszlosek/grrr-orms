<?php
include('../norma.php');
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

class Log {
	public static $lines = array();

	public function Output() {
		echo implode("\n", Log::$lines) . "\n";
	}
}



class ReadTest extends PHPUnit_Framework_TestCase {
	public static function setUpBeforeClass() {
		global $db;
		$sql = array();
		$sql[] = 'drop table article';
		$sql[] = 'drop table file';
		$sql[] = 'create table article (id int(11) auto_increment, title varchar(255), body text, cover_id int(11), thumbnail_id int(11), primary key (id))';
		$sql[] = 'create table file (id int(11) auto_increment, name varchar(255), primary key (id))';
		$sql[] = "insert into file (name) value('article-thumb.jpg')";
		$sql[] = "insert into file (name) value('article-cover.jpg')";
		$sql[] = "insert into article (title, cover_id, thumbnail_id) values('test article', 2, 1)";
		foreach ($sql as $s) $db->execute( $s );
	}


	public function testOpenByPrimaryKey() {
		$data = array(
			'ID' => 1,
			'Title' => 'test article',
			'Body' => '',
			'ThumbnailID' => 1,
			'CoverID' => 2
		);
		$a = new Article();
		$a->ID = 1;
		$b = $a->toArray();
		$this->assertEquals($data, $b);
	}
	public function testRelation() {
		$a = new Article();
		$a->ID = 1;
		$thumb = $a->Thumbnail;
		$this->assertEquals( get_class($thumb), 'File');
		$data = array(
			'ID' => 1,
			'Name' => 'article-thumb.jpg'
		);
		$this->assertEquals( $thumb->toArray(), $data);
	}
	public function testForeignAlias() {
		$a = new Article();
		$a->ID = 1;
		$this->assertEquals($a->CoverFileName, 'article-cover.jpg');
	}
	public function testRelationCaching() {
		// make sure the array contains the related objects too, although this will change in the future ...
		// i don't want any objects returned from toArray(), just nested associative array data
		$a = new Article();
		$a->ID = 1;
		$a->Thumbnail;
		$a->CoverImage;
		$data = array(
			'ID' => 1,
			'Title' => 'test article',
			'Body' => '',
			'ThumbnailID' => 1,
			'CoverID' => 2
		);
		$b = $a->toArray();
		$thumb = $b['Thumbnail']->toArray();
		unset($b['Thumbnail']);
		$cover = $b['CoverImage']->toArray();
		unset($b['CoverImage']);
		$this->assertEquals($data, $b);
		
		$data = array(
			'ID' => 1,
			'Name' => 'article-thumb.jpg'
		);
		$this->assertEquals($data, $thumb);

		$data = array(
			'ID' => 2,
			'Name' => 'article-cover.jpg'
		);
		$this->assertEquals($data, $cover);
	}
}

$db = dbFacile::open('mysql', 'norma', 'norma', 'norma');
Norma::$db = $db;


/*
$a->Body = 'body';
$a->Save();
var_dump($a);
*/

/*
$a = new Article();
$a->Title = 'new title';
$a->Body = 'new body';
echo $a->Create();
*/
