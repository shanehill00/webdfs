<?php
/**
Copyright (c) 2009, Shane Hill <shanehill00@gmail.com>
All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

    * Redistributions of source code must retain
      the above copyright notice, this list of conditions
      and the following disclaimer.

    * Redistributions in binary form must reproduce the above copyright notice,
      this list of conditions and the following disclaimer in the documentation
      and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO,
THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED
AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

*/

/**
 * @package    DataLocator
 * @subpackage UnitTests
 *   
 * @author     Shane Hill <shanehill00@gmail.com>
 *
 */

/**
 * Test helper
 */
require_once dirname(dirname( dirname(__FILE__) )). DIRECTORY_SEPARATOR . 'TestHelper.php';

require_once 'PHPUnit/Framework.php';
require_once 'PHPUnit/Framework/IncompleteTestError.php';
require_once 'PHPUnit/Framework/TestCase.php';
require_once 'PHPUnit/Framework/TestSuite.php';
require_once 'PHPUnit/Runner/Version.php';
require_once 'PHPUnit/TextUI/TestRunner.php';
require_once 'PHPUnit/Util/Filter.php';

require_once 'WebDFS/DataLocator/RUSHpw.php';
/**
 * @package    DataLocator
 * @subpackage UnitTests
 */
class WebDFS_DataLocator_RUSHpwTest extends PHPUnit_Framework_TestCase
{
    private $data_config = null;
    
    public function setUp(){
        $numClusters = 2;
        $nodesPerCluster = 2;
        $replicaCount = 2;
        $clusterToWeight = 0;
        $weight = 1;

        $this->data_config = $this->makeConfig($numClusters, $nodesPerCluster, $replicaCount, $clusterToWeight, $weight);
    }

    /**
     * Tests that we get a WebDFS_DataLocator_RUSHpw object and that it functions as expected
     */
    public function testInstance()
    {
        $hm = new WebDFS_DataLocator_RUSHpw( $this->data_config );
        $this->assertType( 'WebDFS_DataLocator_RUSHpw', $hm  );
    }

   /**
    * Tests that we throw a DataLocator_Exception when the cluster count is negative .
    * We accomplish this by passing in a negative cluster count 
    */
    public function testThrowLocatorException()
    {
        $badConf['clusters'] = array();
        try{
            $hm = new WebDFS_DataLocator_RUSHpw( $badConf );
            $this->fail("successfully instantiated the locator when we should have failed");
        } catch( WebDFS_DataLocator_Exception $e){
            $this->assertType('WebDFS_DataLocator_Exception', $e);
        }
    }
    
    /**
    * Test that we consistently get the same data node for the same id.
    */
    public function testFindNode(){
        //$uuid = uniqid();
        $uuid = 'random_file_name';

        $hm = new WebDFS_DataLocator_RUSHpw( $this->data_config );

        $node = $hm->findNode( $uuid );
        // now we repeat the operation 10 times and see that we get the same node back each time
        $totalTime = 0;
        $N = 10;
        $J = 1000;
        for($j = 0; $j < $J; $j++){
            for($i = 0; $i < $N; $i++){
                $time = microtime(1);
                $node2 = $hm->findNode( $uuid );
                $totalTime += (microtime(1) - $time);

                $nodeHost = $node['proxyUrl'];
                $nodeHost2 = $node2['proxyUrl'];
                $this->assertNotEquals($nodeHost,'',"nodeHost is empty.  bad joos joos!");
                $this->assertNotEquals($nodeHost2,'',"nodeHost2 is empty.  bad joos joos!");
                $this->assertTrue( $nodeHost == $nodeHost2, "failed consistently fetching a node got $nodeHost == $nodeHost2" );
            }
        }
    }

    public function makeConfig($numClusters = 1, $numNodes = 1, $replicationDegree = 1, $clusterToWeight = 0, $weight = 1){
        $clusters = array( 'replicationDegree' => $replicationDegree, 'clusters' => array() );

        $diskNo = 0;
        for( $n = 0; $n < $numClusters; $n++){
            $nodes = array();
            for( $i = 0; $i < $numNodes; $i++ ){
                $nodes[] =
                    array(
                        'proxyUrl' => "http://www.example.com$diskNo/$n/$i/put/your/image/here"
                    );
                $diskNo++;
            }
            $clusterData = array();
            $clusterData['weight'] =  (($n == $clusterToWeight) ? $weight : 1);
            $clusterData['nodes'] = $nodes;

            $clusters['clusters'][] = $clusterData;
        }

        return $clusters;
    }
}