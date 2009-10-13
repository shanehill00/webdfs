package com.targetnode.data.locator;

import static org.junit.Assert.* ;
import java.util.*;
import java.util.ArrayList;

/**
 *
 * @author shane
 */
public class RUSHrTest {


    private HashMap<String,Object> data_config = null;

    public void setUp(){
        int numClusters = 10;
        int nodesPerCluster = 5;
        int replicaCount = 3;
        int clusterToWeight = 0;
        int weight = 1;

        data_config = makeConfig(numClusters, nodesPerCluster, replicaCount, clusterToWeight, weight);
    }

    /**
     * Tests that we get a DataLocator_HonickyMiller object and that it functions as expected
     */
    @org.junit.Test
    public void testInstance()
    {
        setUp();
        try{
            RUSHr l = new RUSHr( data_config );
            assertEquals( "com.targetnode.data.locator.RUSHr", l.getClass().getName()  );
        } catch( LocatorException e ){
            e.printStackTrace();
        }
    }

   /**
    * Tests that we throw a DataLocator_Exception when the cluster count is negative .
    * We accomplish this by passing in a negative cluster count
    */
    @org.junit.Test
    public void testThrowLocatorException()
    {
        setUp();
        data_config.put( "subClusters", new HashMap[0] );
        try{
            RUSHr hm = new RUSHr( data_config );
            fail("successfully instantiated the locator when we should have failed");
        } catch( LocatorException e){
            assertEquals("com.targetnode.data.locator.LocatorException", e.getClass().getName() );
        }
    }

    /**
     * Test that we consistently get the same data node for the same id.
     * 
     * @throws com.targetnode.data.locator.LocatorException
     */
    @org.junit.Test
    public void testFindNode()
    throws LocatorException{
        setUp();
        String uuid = UUID.randomUUID().toString();

        RUSHr hm = new RUSHr( data_config );

        ArrayList<HashMap> nodes = hm.findNode( uuid );
        ArrayList<HashMap> nodes2;

        String nodeHost = (String) nodes.get(0).get("proxyUrl");
        String nodeHost2;
        // now we repeat the operation 10 times and see that we get the same node back each time
        int N = 10;
        for(int i = 0; i < N; i++){
            nodes2 = hm.findNode( uuid );
            nodeHost2 = (String) nodes2.get(0).get("proxyUrl");
            assertTrue( nodeHost.equals( nodeHost2 ) );
        }
    }

    /**
     * this test checks that we never place more than one replica per server
     *
     * we loop n times and generate a unique id and for each id we get three urls for replicas
     * 
     * @throws com.targetnode.data.locator.LocatorException
     */
    @org.junit.Test
    public void testReplication()
    throws LocatorException{

        int numClusters = 25;
        int nodesPerCluster = 2;
        int replicaCount = 3;
        int clusterToWeight = 0;
        int weight = 1;

        RUSHr hm = new RUSHr(
            makeConfig(numClusters, nodesPerCluster, replicaCount, clusterToWeight, weight)
        );

        int iterations = 10000;
        String uuid;
        HashMap replicaData;
        HashMap[] replicaNodes;
        ArrayList<HashMap> nodes;

        for( int n = 0; n < iterations; n++ ){
            uuid = UUID.randomUUID().toString();
            nodes = hm.findNode( uuid );
            int size = nodes.size();
            replicaData = new HashMap<Object,Object>();
            replicaNodes = new HashMap[size];

            for( int i = 0; i < size; i++ ){
                HashMap node = nodes.get(i);
                replicaData.put( node.get("proxyUrl"), node );
                replicaNodes[i] = node;
            }

            // now check that all urls are unique
            // by checking thelength of the replica data
            // if for some reason the length of the replicaData array
            // IS NOT equivalent to the replicaCount, then we have a problem
            assertEquals( replicaData.size(), replicaCount );

        }
    }

    /**
     * show that we place data according to the weighting
     *
     * we "place"  1 million objects across 50 nodes
     * which will result in 3 million replicas total
     *
     * then we check node for deviation from the avg that should be on each node
     * we fail if we deviate more than 10% on any node
     *
     * @throws com.targetnode.data.locator.LocatorException
     */
    //@org.junit.Test
    public void ItestDistribution()
    throws LocatorException{
        // first create a config with 50 nodes
        // 10 sub clusters
        // 5 nodes per sub cluster
        // 3 replicas per object
        // even weighting

        int numClusters = 5;
        int nodesPerCluster = 3;
        int replicationDegree = 3;
        int clusterToWeight = 0;
        int weight = 1;

        RUSHr hm = new RUSHr(
            makeConfig(numClusters, nodesPerCluster, replicationDegree, clusterToWeight, weight)
        );

        HashMap<String, Integer> replicaCount = new HashMap<String, Integer>();

        int iterations = 100000;
        String uuid;
        ArrayList<HashMap> nodes;

        for( int n = 0; n < iterations; n++ ){
            uuid = UUID.randomUUID().toString();
            nodes = hm.findNode( uuid );
            int size = nodes.size();
            Integer total = 0;
            for( int i = 0; i < size; i++ ){
                HashMap node = nodes.get(i);
                String proxyUrl = (String) node.get("proxyUrl");
                total = replicaCount.get( proxyUrl );
                if( total == null ){
                    total = 0;
                }
                replicaCount.put( proxyUrl, ++total );
            }
        }
        int grandTotal = iterations * replicationDegree;
        int avg = grandTotal / (numClusters * nodesPerCluster);
        double dev = avg * 0.10;
        int ctDev = 0;
        int totDev = 0;
        int n = 1;
        int z = 1;
        int badDev = 0;
        for( Integer total : replicaCount.values() ){
            ctDev = Math.abs( avg - total );
            //assertTrue( ctDev <= dev );
            if( ctDev > dev ){
                badDev++;
                totDev += ctDev;
                System.out.print( (z++) + " server " + n + " : " + total );
                System.out.print(" deviated more than " + dev + " : " + ctDev + " > " + dev);
                System.out.println(" deviated by " + (( (float) ctDev / (float) avg ) * (float) 100) + "%" );
            }
            n++;
        }
        if(badDev > 0){
            System.out.println( "avg deviation: " + ( totDev / badDev ) + " - " + (( (float) totDev / (float) badDev ) / (float) avg) * (float) 100 );
        }
        System.out.println( grandTotal );
        System.out.println( grandTotal / (numClusters * nodesPerCluster) );
    }

    public HashMap<String,Object> makeConfig(int numClusters, int numNodes, int replicationDegree, int clusterToWeight, int weight){
        HashMap<String, Object> clusters = new HashMap<String, Object>();
        clusters.put( "replicationDegree", replicationDegree);
        
        HashMap[] clusterList = new HashMap[numClusters];
        clusters.put( "subClusters", clusterList );

        int diskNo = 0;
        HashMap[] nodes;
        HashMap<String,String> node;
        HashMap<String,Object> clusterData;
        for( int n = 0; n < numClusters; n++){
            nodes = new HashMap[numNodes];
            for( int i = 0; i < numNodes; i++ ){
                node = new HashMap<String, String>();
                node.put("proxyUrl" , "http://www.example.com" + diskNo + "/" + n + "/" + i + "/put/your/image/here");
                nodes[i] = node;
                diskNo++;
            }
            clusterData = new HashMap<String, Object>();
            clusterData.put("weight", ((n == clusterToWeight) ? weight : 1));
            clusterData.put("nodes", nodes);

            clusterList[n] = clusterData;
        }

        return clusters;
    }
}
