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
 * first we figure out if:
 *      we are going to save a copy locally
 *      whether we need to replicate or not
 *      or do we need to just pass on the file data to a different node altogether
 *
 *
 * get the cluster and node config
 * parse the url for the input params of:
 *      file name to use when the file is saved
 *      number of times to replicate the file
 *      the params will be located in the path info
 *
 *          /filename/replicaNumber/targetPosition
 *
 *      if num_times_to_replicate is not there, then we assume that we need to replicate
 *      the max_replica_count - 1
 *
 * get all of the urls for the image
 * check to see if this node is included in the urls
 * if not
 *      then we choose the first url in the list
 *      test to see if the url is available via an OPTIONS request
 *      if available send the file data there under the filename we parsed from the url.
 *      if not avaialble, try the next url in the list
 *      repeat until the list is exhausted,  if we did not manage
 *      to send the file anywhere, then we need to return a 500 error
 *
 *      we omit the num_times_to_replicate because we know that
 *      the node to which we are forwarding will take care of starting the replication
 *
 *      to facilitate the forward we read from stdin
 *      and use that with the curl lib to make a put request
 *      to the next node.
 *
 * if the url is in the list, that means we need to save the file to disk
 *      spool the file to disk to a temp spot
 *      rename the file to the name we parsed from the url
 *      this is where we need to send back a signal to the client
 *      that we are all good and we need to disconnect from the client
 *      but keep running to complete the replication checks below
 *
 *      now we check to see if we should replicate
 *          check the num_times_to_replicate
 *
 *          if num_times_to_replicate is 0
 *              do nothing
 *
 *          if num_times_to_replicate is null or non-existent or the empty string
 *              then we assume we are the first stop and need to
 *              start the full replication
 *
 *          if num_times_to_replicate is greater than 0
 *              we take the total number of urls for replicas
 *              subtract num_times_to_replicate,
 *                  this will yield the index of the url to which we should forward
 *
 *              we get the url from our url list
 *              subtract 1 from num_times_to_replicate
 *              forward on to the url with the total num_times_to_replicate
 *
 */

require_once 'WebDFS/Helper.php';
require_once 'WebDFS/Exception.php';

class WebDFS
{

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
     * @var <array>
     */
    protected $config = null;

    /**
     * holds the config data for this instantiation
     *
     * @var <array>
     */
    protected $dataConfig = null;

    /**
     * the data locator that is used for looking up the
     * location of an object.
     *
     * @var <array>
     */
    protected $locator = null;

    /**
     * caller input for things like
     * file name, data directories
     * temp storage directories, etc
     *
     * @var <array>
     */
    protected $params = null;

    /**
     * path to the temp copy of the uploaded file
     * this file may or may not exist, so we need to do appropriate
     * existence checks
     *
     * @var <string>
     */
    protected $tmpPath = "";

    /**
     * path to the final copy of the uploaded file
     * if it gets saved here
     *
     * @var <string>
     */
    protected $finalPath = "";

    /**
     * final path to the directory where the file will be saved
     *
     * @var <string>
     */
    protected $finalDir = "";

    /**
     * an array that holds all the target nodes
     * for the file being saved
     *
     * @var <array>
     */
    protected $targetNodes = null;

    /**
     * boolean indicating whether or not to log
     * debug messages.  primarily useful for
     * watching how a file propagates through the nodes
     *
     * @var <boolean>
     */
    protected $debug = false;

    /**
     * holds an array of debug messages
     *
     * @see debugLog()
     * @var <array>
     */
    protected $debugMsgs = null;

    /**
     * holds an array of error messages
     *
     * @see errorLog()
     * @var <array>
     */
    protected $errMsgs = null;

    /**
     *
     * integer value that indicates that
     * we do not have a position in the list
     * of target nodes.
     * essentially meaning that we are not a target node.
     *
     * @var <int>
     */
    const POSITION_NONE = -1;

    const HEADER_REPLICA           = 'Webdfs-Replica';
    const HEADER_POSITION          = 'Webdfs-Position';
    const HEADER_GET_CONTEXT       = 'Webdfs-Get-Context';
    const HEADER_MOVE_CONTEXT      = 'Webdfs-Move-Context';
    const HEADER_MOVE_CONFIG_INDEX = 'Webdfs-Move-Config-Index';
    const HEADER_CONFIG_INDEX      = 'Webdfs-Config-Index';
    const HEADER_CONTENT_LENGTH    = 'Content-Length';
    const HEADER_PROPAGATE_DELETE  = 'Webdfs-Propagate-Delete';
    const HEADER_FORCE_DELETE      = 'Webdfs-Force-Delete';

    const MOVE_CONTEXT_START  = 'start';
    const MOVE_CONTEXT_CREATE = 'create';
    const MOVE_CONTEXT_DELETE = 'delete';

    const GET_CONTEXT_AUTOMOVE = 'automove';
    /**
     *
     * @param <type> $locator
     * @param <type> $config
     * @param <type> $params
     */

    public function __construct( $config, $params ){
        $this->config = $config;
        $this->params = $params;
        // the configIndex tells us which config to use for this request
        // it is initially passed to us via the header Webdfs-Config-Index
        // we need this value because we need to have a "history" of configs to
        // accommodate automatic movement of data.
        $configIndex = $this->params['configIndex'];
        $this->dataConfig = $this->config['data'][ $configIndex ];

        require_once( $this->dataConfig['locatorClassPath'] );
        $locatorClassName = $this->dataConfig['locatorClassName'];

        $this->locator = new $locatorClassName( $this->dataConfig );

        $this->debug = $this->config['debug'];
        $this->debugMsgs = $this->config['debugMsgs'];
        $this->errMsgs = $this->config['errMsgs'];

        $pathSeparator = '/';
        $this->finalDir = join( $pathSeparator, array($this->dataConfig['storageRoot'], $this->params['pathHash'] ) );
        $this->finalPath = join( $pathSeparator, array( $this->finalDir, $this->params['fileName'] ) );
        $this->tmpPath = join( $pathSeparator, array($this->dataConfig['tmpRoot'], uuid_create() ));
    }

    public function handleRequest(){
        $actionParams = $this->config[ 'reqMethodSettings' ][ $this->params['action'] ];
        require_once( $actionParams['require'] );
        $class = $actionParams['class'];

        $action = new $class( $this->config, $this->params );
        $action->handle();
    }

    /*
     * get the data from stdin and put it in a temp file
     *
     * we use the dio functions if configured to do so
     * as they are much faster than file_put_contents on big files (1mb+)
     *
     * we use file_put_contents if the dio libs are not available
     */
    protected function spoolData( ){
        // write stdin to a temp file
        $input = fopen($this->config['inputStream'], "rb");
        stream_set_blocking($input, 0);

        if( extension_loaded( 'dio' )
              && isset( $this->dataConfig['useDio'] )
                && $this->dataConfig['useDio'] )
        {
            $fd = dio_open($this->tmpPath, O_CREAT | O_NONBLOCK | O_WRONLY );
            $totalWritten = 0;
            while( $data = fread( $input, $this->config['spoolReadSize'] ) ){
                $totalWritten += dio_write( $fd, $data );
            }

            // make a check that we wrote all of the data that we expected to write
            // if not throw an exception
            if( isset( $this->params['contentLength'] )
                  && ($this->params['contentLength'] > 0)
                    && ($this->params['contentLength'] != $totalWritten) )
            {
                dio_close( $fd );
                unlink( $this->tmpPath );
                $msg = sprintf( $this->config['exceptionMsgs']['incompleteWrite'], $this->params['contentLength'], $totalWritten );
                throw new WebDFS_Exception( $msg );
            }
            // call fsync if:
            //   we are configured to do so
            //   the fsync function exists
            //   we are a target storage node for the data
            //   and this is the first replica to be created
            $this->fsync( $fd );
            dio_close( $fd );
            fclose( $input );
        } else {
            $totalWritten = file_put_contents($this->tmpPath, $input);
            if( isset( $this->params['contentLength'] )
                  && ($this->params['contentLength'] > 0)
                    && ($this->params['contentLength'] != $totalWritten) )
            {
                unlink( $this->tmpPath );
                $msg = sprintf( $this->config['exceptionMsgs']['incompleteWrite'], $this->params['contentLength'], $totalWritten );
                throw new WebDFS_Exception( $msg );
            }
        }
    }

    protected function fsync( $fd ){
        // call fsync if:
        //   we are configured to do so
        //   the fsync function exists
        //   we are a target storage node for the data
        //   and this is the first replica to be created
        if( function_exists("dio_fsync")
              && isset( $this->dataConfig['fsync'] )
                && $this->dataConfig['fsync']
                  && $this->iAmATarget()
                    && ($this->params['replica'] == 0)
        )
        {
            dio_fsync( $fd );
        }
    }

    protected function saveData( ){
        $this->makeDir( $this->finalDir );
        if(  !rename( $this->tmpPath, $this->finalPath ) ){
            // throw exception if the rename failed
            $msg = sprintf($this->config['exceptionMsgs']['failedRename'],$this->tmpPath, $this->finalPath);
            throw new WebDFS_Exception( $msg  );
        }
    }

    /**
     * created the necessary storage directory if not yet existent
     */
     protected function makeDir( $dir = null ){
        if( is_null($dir)){
            $dir = $this->finalDir;
        }
        if( extension_loaded("apc") ){
            $this->apcMkdir();
        } else {
            if( !file_exists( $dir ) ){
                // suppress any probs in case someone
                // else is making this directory
                if(!@mkdir( $dir, 0755, true )){
                    $this->debugLog('apcMkdir', $dir );
                }
            }
        }
     }

    /**
     * this is where we unlink the file from the fs
     * we want to prevent the errors from
     * killing the process here as we might
     * receive erroneous delete requests and it
     * does not seem like we should die because of those
     *
     */
    protected function _deleteData( ){
        $forceDelete = (isset($this->params['forceDelete']) && $this->params['forceDelete'] );
        if(  ($this->iAmATarget( ) || $forceDelete )
                && file_exists( $this->finalPath ) ){
            $deleted = unlink( $this->finalPath );
            if( !$deleted ){
                // throw exception if the delete failed
                $msg = sprintf($this->config['exceptionMsgs']['failedUnlink'],$this->finalPath);
                throw new WebDFS_Exception($msg);
            }
        }
    }

    protected function apcMkdir(){
        $knownDir = apc_fetch( $this->finalDir );
        if( !$knownDir ){
            if( !file_exists( $this->finalDir ) ){
                // suppress any probs in case someone
                // else is making this directory

                if(!@mkdir( $this->finalDir, 0755, true )){
                    $this->debugLog('apcMkdir', $this->finalDir );
                }
            }
            apc_store( $this->finalDir, 1 );
        }
    }

    protected function getForwardInfo( $replica = null, $position = null, $replicationDegree = null, $targetNodes = null ){

        // solve replica value
        if( is_null( $replica ) ){
            $replica = (int) $this->params['replica'];
        }

        // solve replicationDegree value
        if( is_null( $replicationDegree ) ){
            $replicationDegree = $this->dataConfig['replicationDegree'];
        }

        // obtain targetNodes for the
        if( is_null( $targetNodes ) ){
            $targetNodes = $this->getTargetNodes();
        }

        // solve position value
        if( is_null( $position ) ) {
            $position = $this->params['position'];
            if( !is_numeric( $position ) ){
                $position = $this->getTargetNodePosition( $targetNodes );
            }
        }
        if( $position == self::POSITION_NONE ){
            srand();
            $position = rand(0, (count($targetNodes)-1) );
        } else {
            $position++;
            $position %= count( $targetNodes );
        }

        $forwardInfo = null;
        $filename = $this->params['name'];
        if( $this->iAmATarget( $targetNodes ) ){
            // check whether or not we are done replicating.
            //
            // replicas are identified by the replica number.
            // replica numbers start at 0, so we check to see
            // if the replica number value is less than the max replicas minus 1
            // if yes, that means we need to continue to replicate
            // if not, that means we are done replicating and can quietly return
            if( $replica < ( $replicationDegree - 1 ) ) {
                $replica++;
                $forwardInfo = array(
                    'forwardUrl' => join("/", array( $targetNodes[$position]['proxyUrl'], $filename ) ),
                    'position' => $position,
                    'replica' => $replica,
                );
            }
        } else {
            $forwardInfo = array(
                'forwardUrl' => join('/', array( $targetNodes[$position]['proxyUrl'], $filename ) ),
                'position' => $position,
                'replica' => $replica,
            );
        }
        return $forwardInfo;
    }

    public function getTargetNodes(){
        if( !$this->targetNodes ){
            $this->targetNodes = $this->locator->findNodes( $this->params['name'] );
        }
        return $this->targetNodes;
    }

    protected function getTargetNodePosition( $nodes = null, $proxyUrl = null ){
        if( !$proxyUrl ){
            $proxyUrl = $this->config['thisProxyUrl'];
        }

        if( !$nodes ){
            $nodes = $this->getTargetNodes();
        }
        $position = self::POSITION_NONE;
        $n = 0;
        foreach( $nodes as $node ){
            if($node['proxyUrl'] == $proxyUrl ){
                $position = $n;
                break;
            }
            $n++;
        }
        return $position;
    }

    public function iAmATarget( $targetNodes = null ){
        $isTarget = false;
        $targetPos = $this->getTargetNodePosition( $targetNodes );
        if( is_numeric( $targetPos ) && $targetPos > self::POSITION_NONE ){
            $isTarget = true;
        } else {
            $isTarget = false;
        }
        return $isTarget;
    }

    protected function debugLog(){
        if( $this->debug ){
            $args = func_get_args();
            $key = $args[0];
            $args[0] = $this->debugMsgs[ $key ];
            $msg = call_user_func_array( "sprintf", $args );
            error_log( $msg );
        }
    }

    protected function errorLog(){
        $args = func_get_args();
        $key = $args[0];
        $args[0] = $this->errMsgs[ $key ];
        $msg = call_user_func_array( "sprintf", $args );
        error_log( $msg );
    }

    /**
     * return a boolean indicating whether or not auto move is enabled
     *
     * @return <boolean>
     */
    protected function canSelfHeal(){
        return (isset( $this->config['autoMove'] ) && $this->config['autoMove'] && $this->params['getContext'] != self::GET_CONTEXT_AUTOMOVE);
    }


    protected function sendFile( $filePath = null ){
        if( is_null( $filePath ) ){
            $filePath = $this->finalPath;
        }
        $dataFH = fopen( $filePath, "rb" );
        if( $dataFH ){

            $finfo = finfo_open( FILEINFO_MIME, $this->config["magicDbPath"] );
            $contentType = finfo_file( $finfo, $filePath );
            finfo_close( $finfo );

            rewind( $dataFH );
            header( "Content-Type: $contentType");
            header( "Content-Length: ".filesize( $filePath ) );
            fpassthru( $dataFH );
            fclose( $dataFH );

        } else {
            WebDFS_Helper::send404( $this->params['name'] );
        }
    }


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

    public function selfHeal(){
        $filename = $this->params['name'];

        $tmpPath = $this->tmpPath;
        $fd = fopen( $tmpPath, "wb+" );
        if( !$fd ){
            $this->errorLog('selfHealNoFile', $tmpPath, $filename );
            WebDFS_Helper::send500();
            return;
        }

        $locator = null;
        $configIdx = null;
        $copiedFrom = null;
        $fileSize = null;
        $nodes = null;
        $healed = false;

        if( $this->params['getContext'] != self::GET_CONTEXT_AUTOMOVE){
            $headers = array();
            $headers[0] = self::HEADER_GET_CONTEXT.': '.self::GET_CONTEXT_AUTOMOVE;

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers );
            curl_setopt($curl, CURLOPT_FILE, $fd);
            curl_setopt($curl, CURLOPT_TIMEOUT, 10);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_FAILONERROR, true);
            curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            $totalConfigs = count( $this->config['data'] );
            for( $configIdx = ($totalConfigs - 1); $configIdx >= 0; $configIdx-- ){

                if( $configIdx == 0 ){
                    // 0 means we are looking at the most current config
                    $locator = $this->locator;
                    $nodes = $this->getTargetNodes();
                } else {
                    $config = $this->config['data'][ $configIdx ];
                    $locClass = $config['locatorClassName'];
                    $locator = new $locClass( $config );
                    $nodes = $locator->findNodes( $filename );
                }
                foreach( $nodes as $node ){
                    // check to see if we are looking at node data for ourselves
                    // in which case we do not want to make a request as that
                    // would be wasted resources and pointless
                    if( $node['proxyUrl'] != $this->config['thisProxyUrl'] ){
                        $url = join('/',array($node['staticUrl'],$this->params['pathHash'],$filename) );
                        curl_setopt($curl, CURLOPT_URL, $url);
                        curl_exec($curl);
                        $info = curl_getinfo($curl);
                        if( !curl_errno($curl) && $info['http_code'] < 400 ){
                            fclose( $fd );
                            $copiedFrom = $node;
                            $this->debugLog('autoMove');
                            $healed = true;
                            break 2;
                        }
                        ftruncate($fd, 0);
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
        if( !$healed ){
            // we cannot find the data
            // remove the temp file
            // send a 404
            fclose( $fd );
            unlink( $tmpPath );
            WebDFS_Helper::send404( $this->params['name'] );
        } else if( $healed ){
            // need to  check to see if we wrote all of the data
            // as dictated by the content length headeer
            $fileSize = filesize( $tmpPath );
            if( $fileSize != $info['download_content_length'] ){
                unlink( $tmpPath );
                $msg = sprintf( $this->config['exceptionMsgs']['incompleteWrite'], $info['download_content_length'], $fileSize );
                throw new WebDFS_Exception( $msg );
            }
            $this->saveData();
            $this->sendFile();
            // here we check if the source from where we copied
            // is included in the the current target node list
            $position = $this->getTargetNodePosition( null, $copiedFrom['proxyUrl'] );
            if( $position == WebDFS::POSITION_NONE ){
                $this->sendDeleteForHeal( $copiedFrom['proxyUrl'].'/'.$filename );
            }
        }
    }

    /**
     * Send a delete command to the passed target nodes and filename.
     *
     * We need to be sure to include the Webdfs-Propagate-Delete
     * header with a value of 0 as we do not want the delete command to propagate.
     *
     */
    protected function sendDeleteForHeal( $url ){
        $opts = array(
            CURLOPT_HTTPHEADER => array(WebDFS::HEADER_PROPAGATE_DELETE.': 0',WebDFS::HEADER_FORCE_DELETE.': 1'),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => "DELETE",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
        );
        $curl = curl_init();
        curl_setopt_array($curl, $opts);
        $response = curl_exec( $curl );
        $info = curl_getinfo( $curl );
        $isHttpErr =  isset( $info['http_code'] ) && ( $info['http_code'] >= 400 );
        $isOtherErr = curl_errno($curl);
        if( $isOtherErr || $isHttpErr ){
            $msg = sprintf( $this->config['exceptionMsgs']['selfHealDelete'], $url );
            throw new WebDFS_Exception( $msg );
        }
    }
}
