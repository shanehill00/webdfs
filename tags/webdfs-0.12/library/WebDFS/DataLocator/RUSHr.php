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
 * This class is an implementation of a RUSH algorithm as described by R.J. Honicky and Ethan Miller
 *
 * @author Shane Hill <shanehill00@gmail.com>
 *
 */

/**
 *  Exception class
 */
require_once('WebDFS/DataLocator/Exception.php');

class WebDFS_DataLocator_RUSHr
{

    /**
     * number of copies to make of each object
     *
     * @var int
     */
    protected $replicationDegree = 1;

    /**
     * an ordinal array where each element represents a cluster
     * and the value is an int that is the total number of nodes in the cluster
     *
     * this property is populated at construction time only
     * @var array
     */
    protected $clusters = array();

    /**
     *  total number of clusters in our data configuration
     *  this property is populated at construction time only
     * @var integer
     */
    protected $totalClusters = 0;

    /**
     * the total number of nodes in all of the clusters
     * this property is populated at construction time only
     *
     * @var integer
     */
    protected $totalNodes = 0;

    /**
     * the total number of nodes in all of the clusters
     * this property is populated at construction time only
     *
     * @var integer
     */
    protected $totalNodesW = 0;

    /**
     * the config that was passed to the constructor
     * it is from the dataConfig that we retrieve the data node
     *
     * @var object
     */
    public $dataConfig = null;

    /**
     * The constructor analyzes the passed config to obtain the fundamental values
     * and data structures for locating a node.
     * each of those values is described in detail above with each property.  briefly:
     *
     *      $this->clusters
     *      $this->totalClusters
     *      $this->totalNodes
     *
     *  The values above are derived from the $dataConfig passed to the locator.
     *
     * @param object $dataConfig
     * @return  DataLocator_RUSHr $this
     *
     * @throws WebDFS_DataLocator_Exception
     *
     */
    protected $nodes = array();

    /**
     * @param <int> value used to help seed the random number generator
     */
    const SEED_PARAM = 1560;
    
    public function __construct( $dataConfig ){

        if( !isset( $dataConfig['clusters'] ) || !count( $dataConfig['clusters'] ) ){
            throw new WebDFS_DataLocator_Exception("data config to the Honicky-Miller locator does not contain a valid clusters property. bad joo joos mon!");
        }

        $this->replicationDegree = $dataConfig['replicationDegree'];
        $this->totalClusters = count($dataConfig['clusters']);

        foreach( $dataConfig['clusters'] as $cluster ){
            $nodeCount = count( $cluster['nodes'] );
            for( $n = 0; $n < $nodeCount; $n++ ){
                $this->nodes[] = $cluster['nodes'][$n];
            }
            $this->totalNodes += $nodeCount;
            $nodeCountW = $nodeCount * $cluster[ 'weight' ];
            $this->totalNodesW += $nodeCountW;
            $this->clusters[] = array('count' => $nodeCount, 'list' => range(0, $nodeCount-1, 1 ) );
        }
        $this->dataConfig = $dataConfig;
    }
    /**
     * This function is an implementation of a RUSHr algorithm as described by R J Honicky and Ethan Miller
     *
    */
    public function findNode( $objKey ){

        $clusters = $this->clusters;
        $sumRemainingNodes = $this->totalNodes;
        $sumRemainingNodesW = $this->totalNodesW;
        $replicationDegree = $this->replicationDegree;
        $totalClusters = $this->totalClusters;
        $totalNodes = $this->totalNodes;
        // throw an exception if the data is no good
        if( ( $totalNodes <= 0 )  || ( $totalClusters <= 0 ) ){
            throw new WebDFS_DataLocator_Exception("the total nodes or total clusters is negative or 0.  bad joo joos!");
        }
        
        $clusterConfig = $this->dataConfig['clusters'];
        
        $sumRemainingNodes = $totalNodes;


        // get the starting cluster
        $currentCluster = --$totalClusters;

        // turn a string identifier into an integer for the random seed
        if( is_string( $objKey ) ){
            $objKey  = ( crc32( $objKey ) >> 16 ) & 0x7fff;
        }

        /**
         * this loop is an implementation
         * of the honickey-miller algorithm for
         * fast placement and location of objects
         * in a distributed storage system
         *
         * j = current cluster
         * m = disks in current cluster
         * n = remaining nodes
         */
        $nodeData = array();
        while( 1 ){

            // prevent an infinite loop, in case there is a bug
            if( $currentCluster < 0 ){
                throw new WebDFS_DataLocator_Exception("the cluster index became negative while we were looking for the following id: $objKey.  This should never happen with any key.  There is a bug or maybe your joo joos are BAD!");
            }

            $clusterData = $clusterConfig[$currentCluster];
            $weight = $clusterData['weight'];
            
            $disksInCurrentCluster = $clusters[$currentCluster]['count'];
            $sumRemainingNodes -= $disksInCurrentCluster;
            
            $disksInCurrentClusterW = $disksInCurrentCluster * $weight;
            $sumRemainingNodesW -= $disksInCurrentClusterW;

            // set the seed to our set id
            mt_srand( $objKey + $currentCluster );
            $t = ($replicationDegree - $sumRemainingNodes) > 0 ? ($replicationDegree - $sumRemainingNodes) : 0;

            $u = $t + $this->drawWHG(
                $replicationDegree - $t,
                $disksInCurrentClusterW - $t,
                $disksInCurrentClusterW + $sumRemainingNodesW - $t,
                $weight
            );
            if( $u > 0 ){
                if($u > $disksInCurrentCluster){
                    $u = $disksInCurrentCluster;
                }
                mt_srand( $objKey + $currentCluster + self::SEED_PARAM );
                $this->choose( $u, $currentCluster, $sumRemainingNodes, $nodeData );
                $replicationDegree -= $u;
            }
            if( $replicationDegree == 0 ){
                break;
            }
            $currentCluster--;
        }
        return $nodeData;
    }

    public function reset( $nodesToRetrieve, $currentCluster ){
        $list = &$this->clusters[ $currentCluster ]['list'];
        $count = $this->clusters[ $currentCluster ]['count'];
        for( $nodeIdx = 0; $nodeIdx < $nodesToRetrieve; $nodeIdx++ ){
            $listIdx = $count - $nodesToRetrieve + $nodeIdx;
            $val = $list[$listIdx];
            if( $val < ($count - $nodesToRetrieve) ){
                $list[$val] = $val;
            }
            $list[ $listIdx ] = $listIdx;
        }
    }

    public function choose( $nodesToRetrieve, $currentCluster, $remainingNodes, &$nodeData ){
        $list = &$this->clusters[ $currentCluster ]['list'];
        $count = $this->clusters[ $currentCluster ]['count'];
        for( $nodeIdx = 0; $nodeIdx < $nodesToRetrieve; $nodeIdx++ ){
            $maxIdx = $count - $nodeIdx - 1;
            $randNode = mt_rand( 0, $maxIdx );
            // swap
            $chosen = $list[ $randNode ];
            $list[ $randNode ] = $list[ $maxIdx ];
            $list[ $maxIdx ] = $chosen;
            // add the remaining nodes so we can find the node data when we are done
            $nodeData[] = $this->nodes[$remainingNodes + $chosen];
        }
        $this->reset( $nodesToRetrieve, $currentCluster );
    }

    public function findNodes( $objKey ){
        return $this->findNode( $objKey );
    }

    public function getReplicationDegree(){
        return $this->replicationDegree;
    }

    public function getTotalNodes(){
        return $this->totalNodes;
    }

    public function drawWHG( $replicas, $disksInCurrentCluster, $totalDisks, $weight = 1 ){
        $found = 0;
        for( $i = 0; $i < $replicas; $i++ ){
            if( $totalDisks != 0 ){
                $z = ( mt_rand() / mt_getrandmax() );
                $prob = ( $disksInCurrentCluster / $totalDisks );
                if( $z <= $prob ){
                    $found++;
                    $disksInCurrentCluster -= $weight;
                }
                $totalDisks -= $weight;
            }
        }
        return $found;
    }
}
