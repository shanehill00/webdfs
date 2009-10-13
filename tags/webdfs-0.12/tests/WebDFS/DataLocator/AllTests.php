<?php

/**
 * @category   WebDFS
 * @package    WebDFS_DataLocator
 * @subpackage UnitTests
 */


if (!defined('PHPUnit_MAIN_METHOD')) {
    define('PHPUnit_MAIN_METHOD', 'WebDFS_DataLocator_AllTests::main');
}


/**
 * Test helper
 */
require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'TestHelper.php';

require_once('WebDFS/DataLocator/RUSHpsTest.php');
require_once('WebDFS/DataLocator/RUSHpwTest.php');
require_once('WebDFS/DataLocator/RUSHrTest.php');
require_once('WebDFS/DataLocator/RUSHpTest.php');


/**
 * @category   WebDFS
 * @package    WebDFS_DataLocator
 * @subpackage UnitTests
 *
 */
class WebDFS_DataLocator_AllTests
{
    /**
     * Runs this test suite
     *
     * @return void
     */
    public static function main()
    {
        PHPUnit_TextUI_TestRunner::run(self::suite());
    }

    /**
     * Creates and returns this test suite
     *
     * @return PHPUnit_Framework_TestSuite
     */
    public static function suite()
    {
        $suite = new PHPUnit_Framework_TestSuite('WebDFS - WebDFS_DataLocator');

        $suite->addTestSuite('WebDFS_DataLocator_RUSHpsTest');
        $suite->addTestSuite('WebDFS_DataLocator_RUSHpwTest');
        $suite->addTestSuite('WebDFS_DataLocator_RUSHrTest');
        $suite->addTestSuite('WebDFS_DataLocator_RUSHpTest');
        return $suite;
    }
}

if (PHPUnit_MAIN_METHOD == 'WebDFS_DataLocator_AllTests::main') {
    WebDFS_DataLocator_AllTests::main();
}
