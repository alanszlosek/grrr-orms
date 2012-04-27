<?php
require('setup.php');

class CrudTest extends TestSetup {
	public function testOpenByPrimaryKey() {
		$a = Article::ID(1);
		$this->assertTrue($a instanceof Article);
	}

	public function testNotFound() {
		$u = User::ID(99);
		$this->assertNull($u);
	}

	public function testToArray() {
		$data = array(
			'id' => 1,
			'title' => 'test article',
			'body' => '',
			'thumbnail_id' => 1,
			'cover_id' => 2,
			'author_id' => 1
		);
		$a = Article::ID(1);
		$b = $a->toArray();
		$this->assertEquals($data, $b);
	}

	// Make sure we can access fields that were passed in
	// Do we only allow access to legitimate aliases, foreignAliases or relationships?
	public function testInstantiateWithArray() {
		$data = array(
			'id' => 1,
			'title' => 'test article',
			'body' => '',
			'thumbnail_id' => 1,
			'cover_id' => 2,
			'author_id' => 1
		);
		$a = new Article($data);
		$this->assertEquals(1, $a->ID);
		
		$b = $a->toArray();
		$this->assertEquals($data, $b);
	}
	// Then make sure Create() and Save() work if we instantiate with an array
	public function testInstantiateWithArrayThenSave() {
		$data = array(
			'title' => 'title ... from array',
			'body' => '',
			'thumbnail_id' => 1,
			'cover_id' => 2,
			'author_id' => 1
		);
		$a = new Article($data);
		$a->Create();
		$this->assertEquals(2, $a->ID);
	}

	public function testCreate() {
		$u = new User();
		$u->Name = 'Woowoo';
		$a = $u->Create();

		$this->assertEquals($a, 2);
		$this->assertEquals($u->ID, 2);
	}

	public function testUpdate() {
		$u = User::ID(1);
		$u->Name = 'Another';
		$a = $u->Save();
		$this->assertEquals($a, true);

		$u = User::ID(1);
		$this->assertEquals($u->Name, 'Another');
	}

	public function testNonUpdate() {
		$u = User::ID(1);
		$a = $u->Save();
		$this->assertEquals(false, $a);
	}

	public function testDelete() {
		$u = User::ID(1);
		$a = $u->Delete();
		$this->assertEquals($a, true);

		$u = User::ID(1);
		$this->assertNull($u);
	}


	public function testExtending() {
		$data = array(
			'ID' => 1,
			'Title' => 'test article',
			'Body' => '',
			'ThumbnailID' => 1,
			'CoverID' => 2
		);
		$a = Article2::ID(1);
		$this->assertEquals($a->Title(), $data['Title']);
		//$this->assertEquals(1,1);
	}

	// Test annotating with non-field data
	public function testAnnotating() {
		$a = Article2::ID(1);
		$a->SomethingYay = 'Gerbils';

		$this->assertEquals('Gerbils', $a->SomethingYay);
		// What about trying to save after
		$a->Save();
	}
}

