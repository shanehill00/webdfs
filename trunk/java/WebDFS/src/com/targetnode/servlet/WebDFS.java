package com.targetnode.servlet;

/*
 * servlet class that handles webdfs operations via an http transport
 */

import com.targetnode.RushDFS;
import com.targetnode.data.locator.LocatorException;
import com.targetnode.rushdfs.RushDFSException;
import com.targetnode.rushdfs.DFSFile;
import java.io.*;
import java.util.HashMap;
import java.util.Iterator;
import java.util.Properties;
import java.util.Random;
import java.util.zip.CRC32;
import javax.servlet.*;
import javax.servlet.http.*;
import org.apache.commons.configuration.ConfigurationException;
import org.apache.commons.configuration.SubnodeConfiguration;
import org.apache.commons.configuration.XMLConfiguration;

public class WebDFS extends HttpServlet {
        private static final long serialVersionUID = 42L;

    protected static com.targetnode.RushDFS fs = null;
    protected static XMLConfiguration config = null;
    protected Random ran = new Random();

    @Override
    public void init(){
        try{
            String configFileName = System.getProperty("webdfs.config");
            HashMap<String, Object> dfsConfig = getDFSConfig( configFileName );
            fs = new com.targetnode.RushDFS( dfsConfig );
        } catch (ConfigurationException e){
            e.printStackTrace();
            System.exit(1);
        }
    }

    /**
     * get a file that has been requested
     * if we do not have the file we heal our selves
     * if we cannot find the file then we return a 404
     *
     * @param request
     * @param response
     * @throws javax.servlet.ServletException
     * @throws java.io.IOException
     */
    @Override
    public void doGet(HttpServletRequest request, HttpServletResponse response)
    throws ServletException, IOException
    {
        HashMap<String, Object> reqParams = getRequestParams( request );
        PrintWriter out = response.getWriter();
    }

    /**
     * @param request
     * @param response
     */
    @Override
    public void doPut(HttpServletRequest request, HttpServletResponse response){

        try{

            HashMap<String, Object> reqParams = getRequestParams( request );
            DFSFile fd = fs.open( reqParams );
            fs.write( fd );
            fs.close( fd );

            response.setStatus(204);
            response.setContentLength(0);
            
        } catch( LocatorException e ) {
            e.printStackTrace();
            // need to return a 500 error to the client at this point
        } catch( IOException e ){
            e.printStackTrace();
            // need to return a 500 error to the client at this point
        } catch( RushDFSException e ) {
            e.printStackTrace();
            // need to return a 500 error to the client at this point
        }

    }

    /**
     * @param request
     * @param response
     */
    @Override
    public void doDelete(HttpServletRequest request, HttpServletResponse response){

        try{

            HashMap<String, Object> reqParams = getRequestParams( request );
            DFSFile fd = fs.open( reqParams );
            fs.unlink( fd );
            fs.close( fd );

            response.setStatus(204);
            response.setContentLength(0);

        } catch( LocatorException e ) {
            e.printStackTrace();
            // need to return a 500 error to the client at this point
        } catch( IOException e ){
            e.printStackTrace();
            // need to return a 500 error to the client at this point
        } catch( RushDFSException e ) {
            e.printStackTrace();
            // need to return a 500 error to the client at this point
        }

    }
    /**
     * retrieves values from the request headers
     * and prepares them for use by RushDFs
     *
     * @param request
     * @return
     */
    protected HashMap<String,Object> getRequestParams( HttpServletRequest request )
    throws IOException{
        HashMap<String, Object> params = new HashMap<String, Object>();
        params.put("fileName" , "");
        params.put("pathHash" , "");
        params.put("name" , "");
        params.put("action" , "");
        params.put("replica" , 0);
        params.put("position" , null);
        params.put("configIndex" , 0);
        params.put("moveConfigIndex" , 0);
        params.put("moveContext" , RushDFS.MOVE_CONTEXT_START);
        params.put("getContext" , "");
        params.put("propagateDelete" , 1);
        params.put("inputStream", request.getInputStream() );

        String fileName = request.getPathInfo();
        if( fileName != null && fileName.length() > 0){
            fileName = fileName.trim();
            fileName = fileName.replaceAll("/|:|\\*|\\?|\\||<|>|\"|%|\\\\", "");
            params.put( "action", request.getMethod().toLowerCase() );
            params.put( "name", fileName );
            // hash the path info
            params.put("pathHash", getPathHash( (String) params.get("name") ) );

            String headerVal = request.getHeader(  RushDFS.HEADER_REPLICA );
            if( headerVal != null ){
                params.put( "replica", Integer.parseInt( headerVal ) );
            }

            headerVal = request.getHeader(  RushDFS.HEADER_POSITION );
            if( headerVal != null ){
                params.put( "position", Integer.parseInt(headerVal) );
            }

            headerVal = request.getHeader(  RushDFS.HEADER_CONFIG_INDEX );
            if( headerVal != null ){
                params.put( "configIndex", Integer.parseInt(headerVal) );
            }

            headerVal = request.getHeader(  RushDFS.HEADER_MOVE_CONTEXT );
            if( headerVal != null ){
                params.put( "moveContext", headerVal );
            }

            headerVal = request.getHeader(  RushDFS.HEADER_MOVE_CONFIG_INDEX );
            if( headerVal != null ){
                params.put( "moveConfigIndex", Integer.parseInt(headerVal) );
            }

            headerVal = request.getHeader(  RushDFS.HEADER_GET_CONTEXT );
            if( headerVal != null ){
                params.put( "getContext", headerVal );
            }

            headerVal = request.getHeader(  RushDFS.HEADER_CONTENT_LENGTH );
            if( headerVal != null ){
                params.put( "contentLength", Integer.parseInt(headerVal) );
            }

            headerVal = request.getHeader(  RushDFS.HEADER_PROPAGATE_DELETE );
            if( headerVal != null ){
                params.put( "propagateDelete", Integer.parseInt(headerVal) );
            }

            headerVal = request.getHeader(  RushDFS.HEADER_FORCE_DELETE );
            if( headerVal != null ){
                params.put( "forceDelete", Integer.parseInt(headerVal) );
            }
        }
        return params;
    }

    protected String getPathHash( String name ){
        String pathHash = "";

        CRC32 crc32 = new CRC32();
        byte[] bytes = name.getBytes();
        crc32.update(bytes);
        long crc32Value = crc32.getValue( );
        long seed  = ( crc32Value >> 16 ) & 0x7fff;

        ran.setSeed(seed);
        pathHash = String.format( "%04d", ran.nextInt( 10000 ) );
        pathHash = pathHash.replaceAll("^(\\d{2})(\\d{2})$","$1/$2");
        
        return pathHash;
    }

    protected HashMap<String, Object> getDFSConfig( String configFileName )
    throws ConfigurationException
    {
        config = new XMLConfiguration();
        config.setFileName( configFileName );

        HashMap<String,Object> dfsConfig = new HashMap<String, Object>();

        config.load();

        String key = "errMsgs";
        SubnodeConfiguration msgConf = config.configurationAt(key);
        Iterator msgKeys = msgConf.getKeys();
        HashMap<String,String> msgs = new HashMap<String, String>();
        dfsConfig.put( key, msgs );
        if( msgKeys != null && msgKeys.hasNext() ){
            while( msgKeys.hasNext() ){
                String errMsgKey = (String) msgKeys.next();
                msgs.put( errMsgKey, msgConf.getString(errMsgKey) );
            }
        }

        key = "exceptionMsgs";
        msgConf = config.configurationAt(key);
        msgKeys = msgConf.getKeys();
        msgs = new HashMap<String, String>();
        dfsConfig.put( key, msgs );
        if( msgKeys != null && msgKeys.hasNext() ){
            while( msgKeys.hasNext() ){
                String errMsgKey = (String) msgKeys.next();
                msgs.put( errMsgKey, msgConf.getString(errMsgKey) );
            }
        }

        key = "debugMsgs";
        msgConf = config.configurationAt(key);
        msgKeys = msgConf.getKeys();
        msgs = new HashMap<String, String>();
        dfsConfig.put( key, msgs );
        if( msgKeys != null && msgKeys.hasNext() ){
            while( msgKeys.hasNext() ){
                String errMsgKey = (String) msgKeys.next();
                msgs.put( errMsgKey, msgConf.getString(errMsgKey) );
            }
        }

        key = "selfHeal";
        dfsConfig.put( key, config.getBoolean(key) );

        key = "magicDbPath";
        dfsConfig.put( key, config.getString(key) );

        key = "disconnectAfterSpooling";
        dfsConfig.put( key, config.getBoolean(key) );

        key = "spoolReadSize";
        dfsConfig.put( key, config.getInt(key) );

        key = "debug";
        dfsConfig.put( key, config.getBoolean(key) );

        SubnodeConfiguration data = config.configurationAt("data");
        int maxIdx = data.getMaxIndex("config");
        if(maxIdx >= 0){
            HashMap[] dataConfigs = new HashMap[maxIdx + 1];
            dfsConfig.put("data", dataConfigs);
            for( int n = 0; n < maxIdx + 1; n++ ){
                SubnodeConfiguration configData = config.configurationAt("data.config(" + n + ")");
                HashMap<String,Object> conf = new HashMap<String, Object>();

                dataConfigs[n] = conf;

                key = "thisProxyUrl";
                conf.put(key, configData.getString(key) );

                key = "fsync";
                conf.put(key, configData.getBoolean(key) );

                key = "storageRoot";
                conf.put(key, configData.getString(key) );

                key = "tmpRoot";
                conf.put(key, configData.getString(key) );

                key = "replicationDegree";
                conf.put(key, configData.getInt(key) );

                SubnodeConfiguration subClusterConf = configData.configurationAt("subClusters");
                int scMaxIdx = subClusterConf.getMaxIndex("subCluster");
                if( scMaxIdx >= 0 ){
                    HashMap[] subClusters = new HashMap[scMaxIdx + 1];
                    conf.put("subClusters", subClusters);
                    for( int i = 0; i < scMaxIdx + 1; i++ ){
                        SubnodeConfiguration subClusterData = configData.configurationAt("subClusters.subCluster(" + i + ")");
                        HashMap<String,Object> subConf = new HashMap<String, Object>();

                        subClusters[i] = subConf;

                        key = "weight";
                        subConf.put(key, subClusterData.getInt(key) );

                        SubnodeConfiguration nodeConf = subClusterData.configurationAt("nodes");
                        int nodeMxIdx = nodeConf.getMaxIndex("node");
                        if( nodeMxIdx >= 0 ){
                            HashMap[] nodes = new HashMap[nodeMxIdx + 1];
                            subConf.put("nodes",nodes);
                            for( int j = 0; j < nodeMxIdx + 1; j++ ){
                                SubnodeConfiguration nodeConfData = subClusterData.configurationAt("nodes.node(" + j + ")");
                                HashMap<String,Object> nConf = new HashMap<String, Object>();

                                key = "proxyUrl";
                                nConf.put(key, nodeConfData.getString(key) );

                                key = "staticUrl";
                                nConf.put(key, nodeConfData.getString(key) );

                                nodes[j] = nConf;
                            }
                        }
                    }
                }
            }
        }

        return dfsConfig;
    }

    public static void main( String args[] ){
        InputStream fileStream = ClassLoader.getSystemResourceAsStream("WebDFS.properties");
        try{fileStream.close();}catch(IOException e){}
    }
}
