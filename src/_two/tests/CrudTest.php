<?php
require 'setup.php';

class CrudTest extends TestSetup
{
    public function testOpenByPrimaryKey()
    {
        $a = Article::ID(1);
        $this->assertTrue($a instanceof Article);
    }

    public function testNotFound()
    {
        $u = User::ID(99);
        $this->assertNull($u);
    }

    public function testToArray()
    {
        $data = array(
            'id' => 1,
            'title' => 'First Article',
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
    public function testInstantiateWithArray()
    {
        $data = array(
            'id' => 1,
            'title' => 'First Article',
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
    public function testInstantiateWithArrayThenSave()
    {
        $data = array(
            'title' => 'title ... from array',
            'body' => '',
            'thumbnail_id' => 1,
            'cover_id' => 2,
            'author_id' => 1
        );
        $a = new Article($data);
        $a->Create();
        $this->assertEquals(3, $a->ID);
    }

    public function testCreate()
    {
        $u = new User();
        $u->Name = 'Woowoo';
        $a = $u->Create();

        $this->assertEquals(2, $a);
        $this->assertEquals(2, $u->ID);
    }

    public function testUpdate()
    {
        $u = User::ID(1);
        $u->Name = 'Another';
        $a = $u->Save();
        $this->assertEquals(1, $a);

        $u = User::ID(1);
        $this->assertEquals('Another', $u->Name);
    }

    public function testCreateThenUpdate()
    {
        $u = new User();
        $u->Name = 'Third';
        $a = $u->Create();

        $this->assertEquals(3, $a);
        $this->assertEquals(3, $u->ID);

        $u->Name = 'Third x2';
        $a = $u->Save();
        $this->assertEquals(1, $a);
        $this->assertEquals('Third x2', $u->Name);

        $v = User::ID(3);
        $this->assertEquals('Third x2', $v->Name);
    }

    public function testCreateWithoutData()
    {
        $u = new User();
        $a = $u->Create();
        $this->assertEquals(false, $a);
    }

    public function testCreateWithExistingKey()
    {
        $u = new User();
        $u->ID = 1; // Existing primary key
        $a = $u->Create();
        $this->assertEquals(false, $a);
    }

    public function testCreateFailure()
    {
        $u = new User();
        $u->Name = 'Woowoo'; // Existing name, which must be unique
        // SQLite triggers an error, but PHPUnit converts it into an Exception
        // Supress the error so it doesn't get turned into an Exception
        $a = @$u->Create();
        $this->assertEquals(false, $a);
        if (get_class(Norma::$dbFacile) == 'dbFacile_sqlite3') {
            $this->assertEquals('column name is not unique', $u->Error());
        } elseif (get_class(Norma::$dbFacile) == 'dbFacile_mysql') {
            $this->assertEquals("Duplicate entry 'Woowoo' for key 'user_name'", $u->Error());
        }
    }

    public function testNonUpdate()
    {
        // User 1 doesn't exists?
        $u = User::ID(1);
        // No fields have been updated
        $a = $u->Save();
        $this->assertEquals(false, $a);
    }

    public function testDelete()
    {
        $u = User::ID(1);
        $a = $u->Delete();
        $this->assertEquals(1, $a);

        $u = User::ID(1);
        $this->assertNull($u);
    }

    // Article2 is a class that extends Article. Everything else should function normally
    public function testExtending()
    {
        $data = array(
            'ID' => 1,
            'Title' => 'First Article',
            'Body' => '',
            'ThumbnailID' => 1,
            'CoverID' => 2
        );
        $a = Article2::ID(1);
        $this->assertEquals($data['Title'], $a->Title());
        //$this->assertEquals(1,1);
    }

    // Test annotating with non-field data
    public function testAnnotating()
    {
        $a = Article2::ID(1);
        $a->SomethingYay = 'Gerbils';

        $this->assertEquals('Gerbils', $a->SomethingYay);
        // What about trying to save after
        $b = $a->Save();
        // false because no fields have changed
        $this->assertEquals(false, $b);
    }

    // Test on table without a primary key
    public function testNoPrimaryKey()
    {
        $a = new NoPK();
        // PK is required, but we don't specify one
        $b = $a->Create(); // should this fail?
        $this->assertEquals(false, $b);

        // Now we specify one
        $a->ID = time();
        $a->Name = 'no primary';
        $b = $a->Create();
        $this->assertEquals(true, $b);

        // This should fail because without a PK there's no way to ensure we've updated the correct row
        // So Save() on non-PK objects will always skip the query and fail
        $b = $a->Save();
        $this->assertEquals(false, $b);
    }

    // Test on table where primary key is not auto-generated
    public function testNonAutoPrimaryKey()
    {
        $a = new NonAutoPK();
        $b = $a->Create(); // should this fail?
        $this->assertEquals(false, $b);
        $b = $a->Save(); // should this fail?
        $this->assertEquals(false, $b);

        // Now we specify a PK
        $a->ID = 1234567;
        $a->Name = 'non-auto PK';
        $b = $a->Create();
        $this->assertEquals(true, $b);

        $a->Name = 'non-auto PK updated';
        $b = $a->Save();
        $this->assertEquals(true, $b);

        // Test opening by this key
        $a = NonAutoPK::ID(1234567);
        $this->assertNotNull($a);
        $this->assertEquals('non-auto PK updated', $a->Name);
        $b = $a->Save(); // should this fail?
        $this->assertEquals(false, $b);

        // Test insert with existing key
        $a = new NonAutoPK();
        $a->ID = 1234567;
        $a->Name = 'non-auto PK';
        $b = @$a->Create();
        $this->assertEquals(false, $b);
    }


    // NOW WHEN PRIMARY KEY CONSISTS OF MORE THAN 1 FIELD
    /*
    Torn because the database won't auto-increment with a multi-field unique key ... so Create() becomes irrelevant.
    And now we've got some confusion ... Create() removes primary key, and only inserts other fields so DB can do
    auto-increment. And Save() uses primary key in the where clause for the update, but we want neither! Wow. Thought
    this would be simple.
    */
    public function testMultiKey()
    {
        $a = new Combo();
        $a->Key1 = 1;
        $a->Key2 = 2;
        // What if we didn't specify name?
        $a->Name = 'Hello';
        $b = $a->Create();
        $this->assertEquals(true, $b);

        $a->Name = 'Hello again';
        $b = $a->Save();
        $this->assertEquals(true, $b);

        $c = Combo::Key1_Key2(1,2);
        $this->assertNotNull($c);
        $this->assertEquals(1, $c->Key1);
        $this->assertEquals(2, $c->Key2);

        // Key1 and Key2 are primary key fields, both are required
        try {
            $d = Combo::Key1(1);
        } catch (Exception $e) {
            $d = null;
        }
        $this->assertNull($d);
    }

    public function testUniqueKey()
    {
        $a = UniqueKey::ID(1);
        $b = UniqueKey::Key1(123);
        $this->assertEquals($a, $b);
    }

/*
    public function testFind()
    {
        $one = Article::Find('id=1');
        $a = array( Article::ID(1) );
        $this->assertEquals($a, $one);

        $one = Article::Find('id=1');
        $a = array( Article::ID(1) );
        $this->assertEquals($a, $one);
    }
*/
}
