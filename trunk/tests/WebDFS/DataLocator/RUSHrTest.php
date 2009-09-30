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

require_once 'WebDFS/DataLocator/RUSHr.php';
/**
 * @package    DataLocator
 * @subpackage UnitTests
 */
class WebDFS_DataLocator_RUSHrTest extends PHPUnit_Framework_TestCase
{
    private $data_config = null;
    
    public function setUp(){
        $numClusters = 2;
        $nodesPerCluster = 2;
        $replicaCount = 3;
        $clusterToWeight = 0;
        $weight = 1;
        
        $this->data_config = $this->makeConfig($numClusters, $nodesPerCluster, $replicaCount, $clusterToWeight, $weight);
    }

    /**
     * Tests that we get a DataLocator_HonickyMiller object and that it functions as expected
     */
    public function testInstance()
    {
        $hm = new WebDFS_DataLocator_RUSHr( $this->data_config );
        $this->assertType( 'WebDFS_DataLocator_RUSHr', $hm  );
    }

   /**
    * Tests that we throw a DataLocator_Exception when the cluster count is negative .
    * We accomplish this by passing in a negative cluster count 
    */
    public function testThrowLocatorException()
    {
        $badConf['clusters'] = array();
        try{
            $hm = new WebDFS_DataLocator_RUSHr( $badConf );
            $this->fail("successfully instantiated the locator when we should have failed");
        } catch( WebDFS_DataLocator_Exception $e){
            $this->assertType('WebDFS_DataLocator_Exception', $e);
        }
    }
    
    /**
    * Test that we consistently get the same data node for the same id.
    */
    public function testFindNode(){
        $uuid = 'random_file_name';
        $replicaNo = 2;

        $hm = new WebDFS_DataLocator_RUSHr( $this->data_config );

        $nodes = $hm->findNode( $uuid );

        $nodeHost = $nodes[0]['proxyUrl'];
        // now we repeat the operation 10 times and see that we get the same node back each time
        $N = 10;
        for($i = 0; $i < $N; $i++){
            $nodes2 = $hm->findNode( $uuid );
            $nodeHost2 = $nodes2[0]['proxyUrl'];
            $this->assertNotEquals($nodeHost,'',"nodeHost is empty.  bad joos joos!");
            $this->assertNotEquals($nodeHost2,'',"nodeHost2 is empty.  bad joos joos!");
            $this->assertTrue( $nodeHost == $nodeHost2, "failed consistently fetching a node got $nodeHost == $nodeHost2" );
        }
    }

    /**
     * this test checks that we never place replicas or original on the same server
     *
     * we loop n times and generate a uniqid and for each id we get three urls for replicas
     */
    public function testReplication(){
        $numClusters = 2;
        $nodesPerCluster = 2;
        $replicaCount = 3;
        $clusterToWeight = 0;
        $weight = 1;

        $hm = new WebDFS_DataLocator_RUSHr(
            $this->makeConfig($numClusters, $nodesPerCluster, $replicaCount, $clusterToWeight, $weight)
        );

        $totalTime = 0;
        $iterations = 10000;
        for( $n = 0; $n < $iterations; $n++ ){
            $uuid = uniqid();
            $replicaData = array();
            $replicaNodes = array();
            
            $time = microtime(1);
            $nodes = $hm->findNode( $uuid );
            $totalTime += (microtime(1) - $time);
            foreach( $nodes as $node ){
                $replicaData[ $node['proxyUrl'] ] = $node;
                $replicaNodes[] = $node;
            }

            // now check that all urls are unique
            // by checking thelength of the replica data
            // if for some reason the length of the replicaData array
            // IS NOT equivalent to the replicaCount, then we have a problem
            $this->assertEquals( count($replicaData), $replicaCount, print_r(array("replica urls are not all unique!", $uuid, $replicaData, $replicaNodes ),1));

        }
    }

    public function testDistribution(){
        // first create a config with 50 nodes
        // 10 sub clusters
        // 5 nodes per sub cluster
        // 3 replicas per object
        // even weighting
        echo("\nstarting distribution test\n");
        $numClusters = 5;
        $nodesPerCluster = 2;
        $replicationDegree = 3;
        $clusterToWeight = 0;
        $weight = 1;

        $hm = new WebDFS_DataLocator_RUSHr(
            $this->makeConfig($numClusters, $nodesPerCluster, $replicationDegree, $clusterToWeight, $weight)
        );


        $replicaCount = array();
        $grandTotal = 0;

        $iterations = 100000;
        $uuid;
        $nodes = array();
        $total = 0;

        for( $n = 0; $n < $iterations; $n++ ){
            $uuid = uniqid("fgfgg", true);
            $nodes = $hm->findNode( $uuid );
            $size = count($nodes);
            if( !( $n % 10000 ) ){
                echo("iterations: $n  - node size: $size \n");
            }
            $grandTotal += $size;
            if( !( $grandTotal % 10000 ) ){
                echo("placed $grandTotal replicas \n");
            }
            for( $i = 0; $i < $size; $i++ ){
                $node = $nodes[$i];
                $proxyUrl = $node["proxyUrl"];
                if( !isset( $replicaCount[$proxyUrl] ) ){
                    $replicaCount[$proxyUrl] = 0;
                }
                $total = $replicaCount[$proxyUrl];
                if( $total == null ){
                    $total = 0;
                }
                $total++;
                $replicaCount[$proxyUrl] = $total;
            }
        }
        $eGrandTotal = $iterations * $replicationDegree;
        $avg = $grandTotal / ($numClusters * $nodesPerCluster);
        $dev = $avg * 0.10;
        $ctDev = 0;
        $n = 0;
        foreach( $replicaCount as $proxyUrl => $total ){
            $n++;
            echo( "server $n :  $total" );
            $ctDev = abs( $avg - $total );
            if(!($ctDev <= $dev) ){
                echo(" deviated more than $dev : $ctDev <= $dev");
            }
            echo("\n");
        }
        echo( "expected: $eGrandTotal - placed: $grandTotal" );
        echo("\n");
        echo( "avg: " + ($grandTotal / ($numClusters * $nodesPerCluster) ) );
        echo("\n");
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