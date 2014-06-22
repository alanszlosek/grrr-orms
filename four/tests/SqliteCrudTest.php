<?php
include('CrudTest.php');

class Sqlite3CrudTest extends CrudTest {
	public static function setUpBeforeClass() {
		$db = new dbFacile_sqlite3();
		$db->open('./norma.sqlite');
		Norma::$dbFacile = $db;

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
}

