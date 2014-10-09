<?php
include 'CrudTest.php';

class Sqlite3CrudTest extends CrudTest
{
    public static function setUpBeforeClass()
    {
        $db = \dbFacile\factory::sqlite3();
        $db->open('./norma.sqlite');
        \Norma\Norma::$dbFacile = $db;

        parent::setUpBeforeClass();
    }
}
