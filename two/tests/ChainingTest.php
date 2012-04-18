<?php
require('setup.php');

class ChainingTest extends TestSetup {
	public function testRelation() {
		$a = new Article();
		$a->ID = 1;

		$rows = $a->Author()->FileUploads()->Done();

		$b = new File();
		$b->ID = 1;
		$c = new File();
		$c->ID = 2;
		$data = array($b, $c);

		$this->assertEquals($rows, $data);
	}
}

