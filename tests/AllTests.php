<?php
/**
 * @category   WebDFS
 * @package    UnitTests
 */

if (!defined('PHPUnit_MAIN_METHOD')) {
    define('PHPUnit_MAIN_METHOD', 'AllTests::main');
}

/**
 * Test helper, sets up paths for including files, etc.
 */
require_once 'TestHelper.php';
require_once 'WebDFSTest.php';
require_once 'WebDFS/DataLocator/AllTests.php';

class AllTests {
    public static function main(){
        PHPUnit_TextUI_TestRunner::run(self::suite());
    }

    public static function suite(){
        $suite = new PHPUnit_Framework_TestSuite('WebDFSTest');

        $suite->addTestSuite('WebDFSTest');
        $suite->addTest(WebDFS_DataLocator_AllTests::suite());

        return $suite;
    }
}

if (PHPUnit_MAIN_METHOD == 'AllTests::main') {
    AllTests::main();
}
