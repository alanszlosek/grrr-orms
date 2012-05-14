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
		$this->assertEquals(1, $a);

		$u = User::ID(1);
		$this->assertEquals($u->Name, 'Another');
	}

	public function testCreateThenUpdate() {
		$u = new User();
		$u->Name = 'Third';
		$a = $u->Create();

		$this->assertEquals($a, 3);
		$this->assertEquals($u->ID, 3);

		$u->Name = 'Third x2';
		$a = $u->Save();
		$this->assertEquals(1, $a);
		$this->assertEquals($u->Name, 'Third x2');

		$v = User::ID(3);
		$this->assertEquals($v->Name, 'Third x2');
	}

	public function testCreateWithoutData() {
		$u = new User();
		$a = $u->Create();
		$this->assertEquals(false, $a);
	}

	public function testCreateWithExistingKey() {
		$u = new User();
		$u->ID = 1; // Existing primary key
		$a = $u->Create();
		$this->assertEquals(false, $a);
	}

	public function testCreateFailure() {
		$u = new User();
		$u->Name = 'Woowoo'; // Existing name, which must be unique
		// SQLite triggers and error, but PHPUnit converts it into an Exception
		// Supress the error so it doesn't get turned into an Exception
		$a = @$u->Create();
		$this->assertEquals(false, $a);
		$this->assertEquals('column name is not unique', $u->Error());
	}

	public function testNonUpdate() {
		$u = User::ID(1);
		$a = $u->Save();
		$this->assertEquals(false, $a);
	}

	public function testDelete() {
		$u = User::ID(1);
		$a = $u->Delete();
		$this->assertEquals(1, $a);

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

	// NOW WHEN PRIMARY KEY CONSISTS OF MORE THAN 1 FIELD
	/*
	Torn because the database won't auto-increment with a multi-field unique key ... so Create() becomes irrelevant.
	And now we've got some confusion ... Create() removes primary key, and only inserts other fields so DB can do
	auto-increment. And Save() uses primary key in the where clause for the update, but we want neither! Wow. Thought
	this would be simple.
	*/
	public function testCreateMultiKey() {
		$a = new Combo();
		$a->Key1 = 1;
		$a->Key2 = 2;
		$b = $a->Save();
	}
}

