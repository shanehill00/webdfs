package com.targetnode.data.locator;

import com.targetnode.data.ILocator;
import java.util.*;
import java.util.ArrayList;
import java.util.zip.CRC32;

/**
 * This class manages a data config and returns the indices for cluster
 * and node where a data object is located.
 *
 * @author shane
 */

public class RUSHr implements ILocator{

    /**
     * @var int holds the value for how many replicas to create for an object
     */
    protected int replicationDegree = 1;

    /**
     * an array of hash maps where each hash map holds
     * info on the sub cluster that corresponds to the array indices
     * meaning that array element 0 holds data for server 0
     *
     * that is the total number of nodes in the cluster
     * this property is populated at construction time only
     * @var 
     */
    
    protected HashMap[] clusters;

    /**
     * an array of hash maps where each element
     * holds data for a sub cluster
     */
    protected HashMap[] clusterConfig;

    /**
     * total number of sub-clusters in our data configuration
     * this property is populated at construction time only
     * @var integer
     */
    protected int totalClusters = 0;

    /**
     * the total number of nodes in all of the subClusters
     *  this property is populated at construction time only
     *
     * @var integer
     */
    protected int totalNodes = 0;

    /**
     * the total number of nodes in all of the clusters
     * this property is populated at construction time only
     *
     * @var integer
     */
    protected int totalNodesW = 0;

    /**
     * an array of HashMaps where each HashMap holds the data for a single node
     */
    protected HashMap[] nodes = null;

    /**
     * @var integer value used to help seed the random number generator
     */
    protected final int SEED_PARAM = 1560;

    /**
     * random number generator
     */

    Random ran = new Random();

    /**
     * maximum value we can have from the ran generator
     */
    float ranMax = (float) Math.pow(2.0, 16.0);

    
    /**
     * The constructor analyzes the passed config to obtain the fundamental values
     * and data structures for locating a node.
     * Each of those values is described in detail above with each property.  briefly:
     *
     *      this.clusters
     *      this.totalClusters
     *      this.totalNodes
     *
     *  The values above are derived from the HashMap[] oonfig passed to the locator.
     *
     * @param conf dataConfig
     *
     * @throws LocatorException
     *
     */

    public RUSHr( HashMap<String, Object> conf ) throws LocatorException{

        clusterConfig = (HashMap[]) conf.get("subClusters");
        replicationDegree = (Integer) conf.get("replicationDegree");

        HashMap[] subClusters = (HashMap[]) conf.get("subClusters");
        totalClusters = subClusters.length;
        clusters = new HashMap[totalClusters];
        //check the confg for all of the params
        // throw a exception if they are not there
        if( totalClusters <= 0 ){
            throw new LocatorException("data config to the RUSHr locator does not contain a valid clusters property");
        }

        int nodeCt = 0;
        HashMap[] nodeData = null;
        ArrayList<HashMap> tempNodes = new ArrayList<HashMap>();
        HashMap subCluster =  null, clusterData = null;
        Integer clusterDataList[] = null;
        for( int i = 0; i < totalClusters; i++){
            subCluster = subClusters[i];
            nodeData = (HashMap[]) subCluster.get("nodes");

            nodeCt = nodeData.length;
            clusterDataList = new Integer[nodeCt];
            for( int n = 0; n < nodeCt; n++ ){
                tempNodes.add(nodeData[n]);
                clusterDataList[n] = n;
            }
            totalNodes += nodeCt;
            totalNodesW += nodeCt * (Integer) subCluster.get( "weight" );

            clusterData = new HashMap< String, Object >();
            clusterData.put( "count", nodeCt );
            clusterData.put( "list", clusterDataList );
            clusters[i] = clusterData;
        }
        nodes = new HashMap[totalNodes];
        tempNodes.toArray(nodes);
    }

    /**
     * This function is an implementation of a RUSHr algorithm as described by R J Honicky and Ethan Miller
     *
     * @param objKey 
     * @throws LocatorException
     * @return 
    */
    public ArrayList<HashMap> findNode( long objKey )
    throws LocatorException{

        HashMap[] c = this.clusters;
        int sumRemainingNodes = this.totalNodes;
        int sumRemainingNodesW = this.totalNodesW;
        int repDeg = this.replicationDegree;
        int totClu = this.totalClusters;
        int totNod = this.totalNodes;
        HashMap[] clusConfig = this.clusterConfig;

        // throw an exception if the data is no good
        if( ( totNod <= 0 )  || ( totClu <= 0 ) ){
            throw new LocatorException("the total nodes or total clusters is negative or 0.  bad joo joos!");
        }

        // get the starting cluster
        int currentCluster = totClu - 1;

        /**
         * this loop is an implementation
         * of the RUSHr algorithm for
         * fast placement and location of objects
         * in a distributed storage system
         *
         * j = current cluster
         * m = disks in current cluster
         * n = remaining nodes
         */
        ArrayList<HashMap> nodeData = new ArrayList<HashMap>();
        while( true ){

            // prevent an infinite loop, in case there is a bug
            if( currentCluster < 0 ){
                throw new LocatorException("the cluster index became negative while we were looking for the following id: objKey.  This should never happen with any key.  There is a bug or maybe your joo joos are BAD!");
            }

            HashMap clusterData = clusConfig[currentCluster];
            Integer weight = (Integer) clusterData.get("weight");

            Integer disksInCurrentCluster = (Integer) c[currentCluster].get("count");
            sumRemainingNodes -= disksInCurrentCluster;

            Integer disksInCurrentClusterW = disksInCurrentCluster * weight;
            sumRemainingNodesW -= disksInCurrentClusterW;

            // set the seed to our set id
            long seed = objKey + currentCluster;
            ran.setSeed( seed );
            int t = (repDeg - sumRemainingNodes) > 0 ? (repDeg - sumRemainingNodes) : 0;

            int u = t + drawWHG(
                repDeg - t,
                disksInCurrentClusterW - t,
                disksInCurrentClusterW + sumRemainingNodesW - t,
                weight
            );
            if( u > 0 ){
                if(u > disksInCurrentCluster){
                    u = disksInCurrentCluster;
                }
                ran.setSeed( objKey + currentCluster + SEED_PARAM );
                choose( u, currentCluster, sumRemainingNodes, nodeData );
                reset( u, currentCluster );
                repDeg -= u;
            }
            if( repDeg == 0 ){
                break;
            }
            currentCluster--;
        }
        return nodeData;
    }
    
    /**
     * This function is an implementation of a RUSH algorithm as described by R J Honicky and Ethan Miller
     *
     * @param objKey - an int used as the prng seed.  this int is usually derived from a string hash
     *
     * @return node - holds three values:
     *     abs_node - an int which is the absolute position of the located node in relation to all nodes on all subClusters
     *     rel_node - an int which is the relative postion located node within the located cluster
     *     cluster - an int which is the located cluster
     * @throws LocatorException
     *
     */
    public ArrayList<HashMap> findNode( String objKey ) throws LocatorException{
        // turn a string identifier into an integer for the random seed
        CRC32 crc32 = new CRC32();
        byte[] bytes = objKey.getBytes();
        crc32.update(bytes);
        long crc32Value = crc32.getValue( );
        long objKeyLong  = ( crc32Value >> 16 ) & 0x7fff;
        return findNode( objKeyLong );
    }

    public void reset( int nodesToRetrieve, int currentCluster ){
        Integer[] list = (Integer[]) clusters[ currentCluster ].get("list");
        Integer count = (Integer) clusters[ currentCluster ].get("count");

        int listIdx;
        int val;
        for( int nodeIdx = 0; nodeIdx < nodesToRetrieve; nodeIdx++ ){
            listIdx = count - nodesToRetrieve + nodeIdx;
            val = list[listIdx];
            if( val < (count - nodesToRetrieve) ){
                list[val] = val;
            }
            list[ listIdx ] = listIdx;
        }
    }

    public void choose( int nodesToRetrieve, int currentCluster, int remainingNodes, ArrayList<HashMap> nodeData ){
        Integer[] list = (Integer[]) clusters[ currentCluster ].get("list");
        Integer count = (Integer) clusters[ currentCluster ].get("count");

        int maxIdx;
        int randNode;
        int chosen;
        for( int nodeIdx = 0; nodeIdx < nodesToRetrieve; nodeIdx++ ){
            maxIdx = count - nodeIdx - 1;
            randNode = ran.nextInt( maxIdx + 1 );
            // swap
            chosen = list[ randNode ];
            list[ randNode ] = list[ maxIdx ];
            list[ maxIdx ] = chosen;
            // add the remaining nodes so we can find the node data when we are done
            nodeData.add( nodes[remainingNodes + chosen] );
        }
    }
    
    /**
     * @param objKey
     * @return
     * @throws com.targetnode.data.locator.LocatorException
     */
    public ArrayList<HashMap> findNodes( String objKey )
    throws LocatorException
    {
        return findNode( objKey );
    }
    
    public int getReplicationDegree(){
        return replicationDegree;
    }

    public int getTotalNodes(){
        return totalNodes;
    }
    
    public int drawWHG( int replicas, int disksInCurrentCluster, int totalDisks, int weight ){
        int found = 0;
        float z;
        float prob;
        int ranInt;

        for( int i = 0; i < replicas; i++ ){
            if( totalDisks != 0 ){
                ranInt = ran.nextInt( (int) (ranMax + 1) );
                z = ( (float) ranInt / ranMax );
                prob = ( (float) disksInCurrentCluster / (float) totalDisks );
                if( z <= prob ){
                    found++;
                    disksInCurrentCluster -= weight;
                }
                totalDisks -= weight;
            }
        }
        return found;
    }
}
