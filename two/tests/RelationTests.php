<?php
require('setup.php');

class RelationTests extends TestSetup {

	public function testRelation() {
		$a = Article::ID(1);
		$thumb = $a->Thumbnail;
		$this->assertEquals( get_class($thumb), 'File');
		$data = array(
			'id' => 1,
			'name' => 'article-thumb.jpg',
			'user_id' => 1
		);
		$this->assertEquals( $thumb->toArray(), $data);
	}

	public function testRelation2() {
		$a = Article::ID(1);

		$b = $a->Thumbnail;
		$b->Name = 'a.jpg';
		$b->Save();
		$this->assertEquals('a.jpg', $b->Name);

		$c = $a->Thumbnail;
		$this->assertEquals('a.jpg', $c->Name);
	}



	public function testForeignAlias() {
		$a = Article::ID(1);
		$this->assertEquals($a->CoverFileName, 'article-cover.jpg');
		$this->assertEquals($a->CoverImage->ID, 2);
	}


	public function testJoins() {
		$a = Article::ID(1);

		$rows = $a->Author()->FileUploads()->Done();

		$b = File::ID(1);
		$c = File::ID(2);
		$data = array($b, $c);

		$this->assertEquals($rows, $data);
	}



	public function testToArray() {
		$a = Article::ID(1);
		$a->Thumbnail;
		$a->CoverImage;
		$a->Author;

		$data = array(
			'id' => 1,
			'title' => 'test article',
			'body' => '',
			'thumbnail_id' => 1,
			'cover_id' => 2,
			'author_id' => 1,

			'Thumbnail' => File::ID(1),
			'CoverImage' => File::ID(2),
			'Author' => User::ID(1)
		);
		$b = $a->toArray();
		$this->assertEquals($data, $b);
	}

	public function testRelationCaching() {
		// make sure the array contains the related objects too, although this will change in the future ...
		// i don't want any objects returned from toArray(), just nested associative array data
		$a = Article::ID(1);
		$a->Thumbnail;
		$a->CoverImage;
		$data = array(
			'id' => 1,
			'title' => 'test article',
			'body' => '',
			'thumbnail_id' => 1,
			'cover_id' => 2,
			'author_id' => 1
		);
		$b = $a->toArray();
		$thumb = $b['Thumbnail'];
		unset($b['Thumbnail']);
		$cover = $b['CoverImage'];
		unset($b['CoverImage']);
		$this->assertEquals($data, $b);
		
		$data = File::ID(1);
		$this->assertEquals($data, $thumb);

		$data = File::ID(2);
		$this->assertEquals($data, $cover);
	}

}

