<?php
/**
 * WebDFS
 *
 * @category   WebDFS
 * @package    UnitTests
 */

require_once 'PHPUnit/Framework.php';
require_once 'PHPUnit/Framework/IncompleteTestError.php';
require_once 'PHPUnit/Framework/TestCase.php';
require_once 'PHPUnit/Framework/TestSuite.php';
require_once 'PHPUnit/Runner/Version.php';
require_once 'PHPUnit/TextUI/TestRunner.php';
require_once 'PHPUnit/Util/Filter.php';

error_reporting( E_ALL | E_STRICT );

/*
 * Determine the root, library, and tests directories
 */
$webdfsRoot     = dirname(dirname(__FILE__));
$webdfsCoreLibrary = $webdfsRoot . DIRECTORY_SEPARATOR . 'library';
$webdfsCoreTests   = $webdfsRoot . DIRECTORY_SEPARATOR . 'tests';

$path = array(
    $webdfsRoot,
    $webdfsCoreLibrary,
    $webdfsCoreTests,
    get_include_path()
);
set_include_path(implode(PATH_SEPARATOR, $path));

require_once 'TestConfiguration.php';

