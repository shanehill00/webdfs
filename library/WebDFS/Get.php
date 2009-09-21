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
require_once 'WebDFS/Exception/GetException.php';
require_once 'WebDFS.php';

class WebDFS_Get extends WebDFS{

    public function __construct( $config, $params ){
        parent::__construct($config, $params);
    }
    
    /**
     * handle a GET. Since any node can handle a GET request, we need to gracefully handle misses.
     * the algorithm follows
     *
     * ("target storage node" and "target node" refer to a node that is supposed to store the requested data)
     *
     * if we are NOT a target storage node for the file being requested
     *      send a redirect to a node that supposedly contains the data
     *
     * else if we are a target storage node
     *      if we contain the file
     *          send the file to the client
     *      else if we do not contain the file we might need to self heal
     *          if we are configured to self heal
     *              call the self healing function (described below)
     *          else
     *              send a 404 to the client
     *          endif
     *      endif
     * endif
     *
     */
    public function handle(){
        $iAmATarget = $this->iAmATarget();
        if( $iAmATarget ){
            if( file_exists( $this->finalPath ) ){
                $this->sendFile();
            } else if( $this->canSelfHeal() ){
                try{
                    $this->selfHeal();
                } catch( Exception $e ){
                    $this->errorLog('selfHeal', $e->getTraceAsString() );
                    WebDFS_Helper::send500();
                }
            } else {
                WebDFS_Helper::send404( $this->params['name'] );
            }
        } else {
            // get the paths, choose one, and print a 301 redirect
            $nodes = $this->getTargetNodes();
            if( $nodes ){
                WebDFS_Helper::send301( join('/',array($nodes[ 0 ]['staticUrl'],$this->params['pathHash'],$this->params['name'] ) ) );
            }
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

    protected function selfHeal(){
        $filename = $this->params['name'];
        
        $tmpPath = $this->tmpPath;
        $fd = fopen( $tmpPath, "wb+" );
        if( !$fd ){
            $this->errorLog('selfHealNoFile', $tmpPath, $filename );
            WebDFS_Helper::send500();
            return;
        }

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

        $locator = null;
        $configIdx = null;
        $copiedFrom = null;
        $fileSize = null;
        $nodes = null;
        $healed = false;

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
                        // need to  check to see if we wrote all of the data
                        // as dictated by the content length headeer
                        // and we need to fsync if configured to do so
                        $fileSize = filesize( $tmpPath );
                        if( $fileSize != $info['download_content_length'] ){
                            fclose( $fd );
                            unlink( $tmpPath );
                            $msg = sprintf( $this->config['exceptionMsgs']['incompleteWrite'], $info['download_content_length'], $fileSize );
                            throw new WebDFS_Exception( $msg );
                        }
                        $this->fsync( $fd );
                        $copiedFrom = $node;
                        $this->debugLog('autoMove');
                        $healed = true;
                        break 2;
                    }
                    ftruncate($fd, 0);
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
        } else if( $fileSize > 0 ){
            fclose( $fd );
            $this->saveData();
            $this->sendFile();
            // here we check if the source from where we copied
            // is included in the the current target node list
            $position = $this->getTargetNodePosition( $nodes, $copiedFrom['proxyUrl'] );
            if( $position == WebDFS::POSITION_NONE ){
                $this->sendDelete( $copiedFrom['proxyUrl'].'/'.$filename );
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
    protected function sendDelete( $url ){
        $opts = array(
            CURLOPT_HTTPHEADER => array(WebDFS::HEADER_PROPAGATE_DELETE.': 0'),
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