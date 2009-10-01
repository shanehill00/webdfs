/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

package com.targetnode;

import com.targetnode.data.ILocator;
import com.targetnode.data.locator.LocatorException;
import com.targetnode.data.locator.RUSHr;
import com.targetnode.rushdfs.DFSFile;
import com.targetnode.rushdfs.RushDFSException;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.nio.channels.FileChannel;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;

/**
 *
 * @author shane
 */
public class RushDFS{

    /**
     * holds an array of configs
     * we need an array of config so that we can accommodate
     * move commands and automatic movement of data
     * when new resources are added
     * or old resources are dellocated or removed
     *
     * there are also some values in the array that are "global"
     * such as the path separator to use for file paths.
     *
     */
    protected HashMap<String,Object> config = null;

    /**
     * a concurrent hashmap where we cache several locators
     * several locators for use during request fulfillment
     * the reason we need more than one locator is that we might be handling
     * requests for old data configurations.  also we do want to have to
     * re instantiate a locator for each request
     */
    protected ConcurrentHashMap<HashMap<String,Object>,ILocator> locators = null;

    protected ConcurrentHashMap<com.targetnode.rushdfs.DFSFile,com.targetnode.rushdfs.DFSFile> requests
            = new ConcurrentHashMap<com.targetnode.rushdfs.DFSFile,com.targetnode.rushdfs.DFSFile>();

    /**
     * an array that holds all the target nodes
     * for the file being saved
     *
     */
    protected HashMap[] targetNodes = null;

    /**
     * boolean indicating whether or not to log
     * debug messages.  primarily useful for
     * watching how a file propagates through the nodes
     *
     */
    protected boolean debug = false;

    /**
     * holds an array of debug messages
     */
    protected HashMap<String,String> debugMsgs = null;

    /**
     * holds an array of error messages
     */
    protected HashMap<String,String> errMsgs = null;

    /**
     * holds an array of exception messages
     */
    protected HashMap<String,String> exceptionMsgs = null;

    /**
     * represents the size in bytes that we read from STDIN and write to a temp file
     */
    protected Integer spoolReadSize = null;


    /**
     * holds the data config found in the master config
     */
    protected HashMap[] dataConfig = null;


    /**
     * HashMap holding all of the paths that have been made thus far
     * we consult this hash before we create a dir to avoid having to do a
     * stat for the dir
     */

    protected ConcurrentHashMap<String,Boolean> dirs = new ConcurrentHashMap<String, Boolean>();

    /**
     * integer value that indicates that
     * we do not have a position in the list
     * of target nodes.
     * essentially meaning that we are not a target node.
     */
    public static final int POSITION_NONE = -1;

    /**
     * property indicating whther or not we should try and self heal if at all possible
     */
    protected boolean canSelfHeal = false;
    protected boolean selfHeal = false;

    /**
     * various headers used in the requests sent to webdfs
     */

    public static final String HEADER_REPLICA           = "Webdfs-Replica";
    public static final String HEADER_POSITION          = "Webdfs-Position";
    public static final String HEADER_GET_CONTEXT       = "Webdfs-Get-Context";
    public static final String HEADER_MOVE_CONTEXT      = "Webdfs-Move-Context";
    public static final String HEADER_MOVE_CONFIG_INDEX = "Webdfs-Move-Config-Index";
    public static final String HEADER_CONFIG_INDEX      = "Webdfs-Config-Index";
    public static final String HEADER_CONTENT_LENGTH    = "Content-Length";
    public static final String HEADER_PROPAGATE_DELETE  = "Webdfs-Propagate-Delete";
    public static final String HEADER_FORCE_DELETE      = "Webdfs-Force-Delete";

    public static final String MOVE_CONTEXT_START  = "start";
    public static final String MOVE_CONTEXT_CREATE = "create";
    public static final String MOVE_CONTEXT_DELETE = "delete";

    public static final String GET_CONTEXT_SELFHEAL = "automove";

    /**
     * @param config
     */
    public RushDFS( HashMap<String, Object> config ){
        this.config = config;

        String key = "debug";
        debug = config.containsKey(key) ? (Boolean) config.get(key) : false;

        key = "debugMsgs";
        debugMsgs = config.containsKey(key) ? (HashMap<String, String>) config.get(key) : new HashMap<String, String>();

        key = "errMsgs";
        errMsgs = config.containsKey(key) ? (HashMap<String, String>) config.get(key) : new HashMap<String, String>();

        key = "exceptionMsgs";
        exceptionMsgs = config.containsKey(key) ? (HashMap<String, String>) config.get(key) : new HashMap<String, String>();

        key = "spoolReadSize";
        spoolReadSize =  config.containsKey(key) ? (Integer) config.get(key) : 2048;

        dataConfig = (HashMap[]) config.get("data");

        locators = new ConcurrentHashMap<HashMap<String, Object>, ILocator>();

        selfHeal = (Boolean) config.get("selfHeal");

    }

    /**
     * here is where we create the request objects for each request that we get
     * we do this to be thread safe and guarantee that each thread will not
     * interfere with each other when fulfilling a request.
     *
     * @param params
     * @throws com.targetnode.data.locator.LocatorException
     * @return
     */
    public DFSFile open( HashMap<String, Object> params ) throws LocatorException{
        DFSFile rFD = new DFSFile();

        rFD.setParams(params);
        // the configIndex tells us which config to use for this request
        // it is initially passed to us via the header Webdfs-Config-Index
        // we need this value because we need to have a "history" of configs to
        // accommodate automatic movement of data.
        Integer configIndex = (Integer) params.get("configIndex");
        HashMap<String, Object> dc = (HashMap<String, Object>) dataConfig[configIndex];

        rFD.setDataConfig(dc);
        rFD.setLocator(getLocator(dc));
        rFD.setFinalDir( dc.get("storageRoot") + "/" + params.get("pathHash") );

        rFD.setFinalPath( rFD.getFinalDir() + "/" + params.get("name") );
        rFD.setTmpPath(dc.get("tmpRoot") + "/" + UUID.randomUUID().toString() );
        rFD.setFsync( (Boolean) dc.get("fsync") );

        String getContext = (String) params.get("getContext");
        rFD.setCanSelfHeal( selfHeal && ( GET_CONTEXT_SELFHEAL.equals( getContext ) ) );

        rFD.setReadBuffer( new byte[spoolReadSize] );

        rFD.setInputStream( (InputStream) params.get("inputStream") );

        rFD.setFileName( (String) params.get("name") );

        return rFD;
    }

    public void close( DFSFile fd ){
        requests.remove( fd );
    }

    protected ILocator getLocator( HashMap<String, Object> dataConfig )
    throws LocatorException{
        if( !locators.containsKey(dataConfig) ){
            RUSHr l = new RUSHr(dataConfig);
            locators.putIfAbsent(dataConfig, l);
        }
        return locators.get(dataConfig);
    }

    public void write( DFSFile fd ) throws IOException, RushDFSException{
        spoolData( fd );
        saveData( fd );
    }

    /*
     * get the data from stdin and put it in a temp file
     *
     * we use the dio functions if configured to do so
     * as they are much faster than file_put_contents on big files (1mb+)
     *
     * we use file_put_contents if the dio libs are not available
     */
    protected void spoolData( DFSFile fd )
    throws IOException, RushDFSException
    {
        // write stdin to a temp file
        InputStream input = fd.getInputStream();
        String tmpPath = fd.getTmpPath();
        byte[] readBuffer = fd.getReadBuffer();
        HashMap<String,Object> params = fd.getParams();

        FileOutputStream spoolFile = new FileOutputStream(tmpPath);

        int totalWritten = 0;
        int read = input.read(readBuffer);

        while( read != -1 ){
            spoolFile.write(readBuffer, 0, read);
            totalWritten += read;
            read = input.read(readBuffer);
        }

        // if we are here and have NOT thrown an IOException
        // we can check the read

        // make a check that we wrote all of the data that we expected to write
        // if not throw an exception
        Integer contentLength = (Integer) params.get("contentLength");
        if( contentLength != null && contentLength > 0 && contentLength != totalWritten)
        {
            spoolFile.close();
            // delete the tmp file
            File tmpFile = new File(tmpPath);
            tmpFile.delete();
            String msg = getExceptionMsg("incompleteWrite", new Object[]{ contentLength, totalWritten} );
            throw new RushDFSException( msg );
        }
        // call fsync if:
        //   we are configured to do so
        //   the fsync function exists
        //   we are a target storage node for the data
        //   and this is the first replica to be created
        spoolFile.flush();
        if( fd.shouldSync() ){
            FileChannel fc = spoolFile.getChannel();
            fc.force(true);
        }
        spoolFile.close();
    }

    protected String getExceptionMsg(String name, Object[] args){
        String msg = exceptionMsgs.get( name );
        if(msg != null){
            msg = String.format( msg, args );
        }
        return msg;
    }

    protected String getErrMsg(String name, Object[] args){
        String msg = errMsgs.get( name );
        if(msg != null){
            msg = String.format( msg, args );
        }
        return msg;
    }

    protected String getDebugMsg(String name, Object[] args){
        String msg = debugMsgs.get( name );
        if(msg != null){
            msg = String.format( msg, args );
        }
        return msg;
    }
    /**
     * check for the existence of a directory
     *
     * @param fd
     * @throws RushDFSException
     */
    protected void saveData( DFSFile fd ) throws RushDFSException{
        String tmpPath = fd.getTmpPath();
        String finalPath = fd.getFinalPath();
        String finalDir = fd.getFinalDir();

        if( !dirs.containsKey( finalDir ) ){
            File dir = new File( finalDir );
            dir.mkdirs();
            if( !dir.exists() ){
                String msg = getExceptionMsg("couldNotCreateDir", new Object[]{finalDir});
                throw new RushDFSException(msg);
            }
            dirs.put( finalDir, true );
        }
        // now rename the temp file

        File tmpFile = new File( tmpPath );
        File finalFile = new File( finalPath );
        if( !tmpFile.renameTo(finalFile) ){
            // throw exception if the rename failed
            String msg = getExceptionMsg("failedRename",new Object[]{tmpPath, finalPath});
            throw new RushDFSException( msg  );
        }
    }

    /**
     * this is where we unlink the file from the fs
     * we want to prevent the errors from
     * killing the process here as we might
     * receive erroneous delete requests and it
     * does not seem like we should die because of those
     *
     *  @param fd 
     * @throws RushDFSException
     * @throws LocatorException
     */
    public void unlink( DFSFile fd )
    throws RushDFSException, LocatorException{
        HashMap<String,Object> params = fd.getParams();
        Boolean forceDelete = (Boolean) params.get("forceDelete");
        if( forceDelete == null ) forceDelete = false;
        
        String finalPath = fd.getFinalPath();

        File finalFile = new File( finalPath );
        if( ( iAmATarget( fd ) || forceDelete ) && finalFile.exists() ){
            boolean deleted = finalFile.delete();
            if( !deleted ){
                // throw exception if the delete failed
                String msg = getExceptionMsg("failedUnlink", new Object[]{finalPath});
                throw new RushDFSException(msg);
            }
        }
    }

    protected boolean iAmATarget( DFSFile fd )
    throws LocatorException{
        boolean isTarget = false;
        Integer targetPos = getTargetNodePosition( fd );
        if( targetPos != null && targetPos > POSITION_NONE ){
            isTarget = true;
        } else {
            isTarget = false;
        }
        return isTarget;
    }
    
    protected ArrayList<HashMap> getTargetNodes( DFSFile fd )
    throws LocatorException{
        return fd.getLocator().findNodes( fd.getFileName() );
    }

    protected int getTargetNodePosition( DFSFile fd )
    throws LocatorException{
        int position = POSITION_NONE;
        int n = 0;
        String thisProxyUrl = fd.getProxyUrl();
        if( thisProxyUrl != null ){
            ArrayList<HashMap> nodes = fd.getLocator().findNodes( fd.getFileName() );
            for( HashMap node : nodes ){
                if( thisProxyUrl.equals(node.get("proxyUrl")) ){
                    position = n;
                    break;
                }
                n++;
            }
        }
        return position;
    }

    protected void debugLog( String name, Object[] args){
        if( debug ){
            String msg = getDebugMsg( name, args );
            // FIXME
            // need to implement debug log writing
        }
    }

    protected void errorLog( String name, Object[] args){
        if( debug ){
            String msg = getErrMsg( name, args );
            // FIXME
            // need to implement errorLog log writing
        }
    }

    /**
     * return a boolean indicating whether or not auto move is enabled
     *
     * @return <boolean>
     */
    protected boolean canSelfHeal(){
        return canSelfHeal;
    }

    /**

    protected function sendFile( filePath = null ){
        if( is_null( filePath ) ){
            filePath = finalPath;
        }
        dataFH = fopen( filePath, "rb" );
        if( dataFH ){

            finfo = finfo_open( FILEINFO_MIME, config.get("magicDbPath") );
            contentType = finfo_file( finfo, filePath );
            finfo_close( finfo );

            rewind( dataFH );
            header( "Content-Type: contentType");
            header( "Content-Length: ".filesize( filePath ) );
            fpassthru( dataFH );
            fclose( dataFH );

        } else {
            WebDFS_Helper::send404( params.get("name") );
        }
    }
     * */


    /**
     *
     * self heal
     *
     * Self heal accomplishes one of two things depending on when and why it is called.
     * It can be used to automatically move data from an old config when scaling.
     * And it can be used to fetch and save to disk a copy of some data from a peer server when
     * data has been lost; say, when a server failed.
     *
     * The self healing process is initiated when we have been asked for some data that is supposedly
     * stored on our disk and we cannot find it.
     *
     * When we are asked to fetch data that is supposedly stored on our disk, one of the following things can be true:
     *
     *      1) The data never was put on disk and this is simply servicing a request for data that is non-existent
     *         ( we currently do not have a reliable way to tell what is supposedly on our disk
     *           this could change if we start keeping a partial index in memory of what is supposedly on the disk. )
     *
     *      2) For some reason, the data is missing or corrupted and we need to heal ourselves
     *
     *      3) New servers and disks have been added to the cluster configuration and we are performing
     *         an auto move operation
     *
     * Currently, we have to assume that we "might" or "probably" have been asked to store the data
     * at some point in the past. Therefore we are forced to search for the data before we return a 404 to the client
     *
     * heal is the function that fecthes the file from a peer server
     * and then saves it to the temp path.
     *
     * self heal will:
     *      iterate the all data configs starting with the oldest and look for the old data.
     *      if we locate the data
     *          we download it
     *          save it to disk
     *          fsync the data
     *
     *      The above facilitates self heal and the first part of auto move
     *      To complete the auto move we need to check and see if the data needs to be deleted from the
     *      source.  The source being the server from which we downloaded the file
     *      for the self healing process.  we only delete the source if the server in question
     *      is NOT in the target nodes list we derive from the current data config
     *
     *
     *      If we cannot find that data at all;
     *          remove the tempfile
     *          we send a "404 not found" message back to the client
     *
     * endif
     *
     */

    /**
    public selfHeal(){
        filename = params.get("name");

        tmpPath = tmpPath;
        fd = fopen( tmpPath, "wb+" );
        if( !fd ){
            errorLog("selfHealNoFile", tmpPath, filename );
            WebDFS_Helper::send500();
            return;
        }

        locator = null;
        configIdx = null;
        copiedFrom = null;
        fileSize = null;
        nodes = null;
        healed = false;

        if( params.get("getContext") != self::GET_CONTEXT_SELFHEAL){
            headers = array();
            headers[0] = self::HEADER_GET_CONTEXT.": ".self::GET_CONTEXT_SELFHEAL;

            curl = curl_init();
            curl_setopt(curl, CURLOPT_HTTPHEADER, headers );
            curl_setopt(curl, CURLOPT_FILE, fd);
            curl_setopt(curl, CURLOPT_TIMEOUT, 10);
            curl_setopt(curl, CURLOPT_HEADER, false);
            curl_setopt(curl, CURLOPT_FAILONERROR, true);
            curl_setopt(curl, CURLOPT_BINARYTRANSFER, true);
            curl_setopt(curl, CURLOPT_FOLLOWLOCATION, true);
            totalConfigs = count( config.get("data") );
            for( configIdx = (totalConfigs - 1); configIdx >= 0; configIdx-- ){

                if( configIdx == 0 ){
                    // 0 means we are looking at the most current config
                    locator = locator;
                    nodes = getTargetNodes();
                } else {
                    config = config.get("data")[ configIdx ];
                    locClass = config.get("locatorClassName");
                    locator = new locClass( config );
                    nodes = locator->findNodes( filename );
                }
                foreach( nodes as node ){
                    // check to see if we are looking at node data for ourselves
                    // in which case we do not want to make a request as that
                    // would be wasted resources and pointless
                    if( node["proxyUrl") != config.get("thisProxyUrl") ){
                        url = join("/",array(node["staticUrl"),params.get("pathHash"),filename) );
                        curl_setopt(curl, CURLOPT_URL, url);
                        curl_exec(curl);
                        info = curl_getinfo(curl);
                        if( !curl_errno(curl) && info["http_code") < 400 ){
                            fclose( fd );
                            copiedFrom = node;
                            debugLog("autoMove");
                            healed = true;
                            break 2;
                        }
                        ftruncate(fd, 0);
                    }
                }
            }
        }
        // at this point we have achieved the same effect as a spoolData() call
        // so now we:
        // save the data
        // return the file back to the caller
        // if the source proxy url is NOT in the current target nodes list
        //      we issue a delete command to the source node
        //      and delete the data from the old location
        // endif
        if( !healed ){
            // we cannot find the data
            // remove the temp file
            // send a 404
            fclose( fd );
            unlink( tmpPath );
            WebDFS_Helper::send404( params.get("name") );
        } else if( healed ){
            // need to  check to see if we wrote all of the data
            // as dictated by the content length headeer
            fileSize = filesize( tmpPath );
            if( fileSize != info["download_content_length") ){
                unlink( tmpPath );
                msg = sprintf( config.get("exceptionMsgs")["incompleteWrite"), info["download_content_length"), fileSize );
                throw new WebDFS_Exception( msg );
            }
            saveData();
            sendFile();
            // here we check if the source from where we copied
            // is included in the the current target node list
            position = getTargetNodePosition( null, copiedFrom["proxyUrl") );
            if( position == RushDFS::POSITION_NONE ){
                sendDeleteForHeal( copiedFrom["proxyUrl")."/".filename );
            }
        }
    }
     */

    /**
     * Send a delete command to the passed target nodes and filename.
     *
     * We need to be sure to include the Webdfs-Propagate-Delete
     * header with a value of 0 as we do not want the delete command to propagate.
     *
     */
    /**
    protected function sendDeleteForHeal( url ){
        opts = array(
            CURLOPT_HTTPHEADER => array(RushDFS::HEADER_PROPAGATE_DELETE.": 0",RushDFS::HEADER_FORCE_DELETE.": 1"),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => "DELETE",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => url,
        );
        curl = curl_init();
        curl_setopt_array(curl, opts);
        response = curl_exec( curl );
        info = curl_getinfo( curl );
        isHttpErr =  isset( info["http_code") ) && ( info["http_code") >= 400 );
        isOtherErr = curl_errno(curl);
        if( isOtherErr || isHttpErr ){
            msg = sprintf( config.get("exceptionMsgs")["selfHealDelete"), url );
            throw new WebDFS_Exception( msg );
        }
    }
     */
}
