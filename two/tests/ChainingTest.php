<?php
require('setup.php');

class ChainingTest extends TestSetup {
	public function testRelation() {
		$a = Article::ID(1);

		$rows = $a->Author()->FileUploads()->Done();

		$b = File::ID(1);
		$c = File::ID(2);
		$data = array($b, $c);

		$this->assertEquals($rows, $data);
	}
}

