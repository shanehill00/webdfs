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
require_once 'WebDFS/Exception/MoveException.php';
require_once 'WebDFS.php';

class WebDFS_Move extends WebDFS{

    public function __construct( $config, $params ){
        parent::__construct($config, $params);
    }
    
    /**
     * we move within 3 different contexts:
     *      start - this is the context when a move has not started copying data
     *              yet and we are still looking for the node that contains the data to be
     *              moved
     *
     *      create - this is the context after start and means that we are creating new data
     *               to facilitate the move function
     *
     *      delete - this is the context after the create phase is over nd we are deleting the old data
     *
     *  basically we try and ensure that data is always available and to that end we
     *  move data by first traveling to each node in the new config and copying the data to disk
     *  then we go to each node in the old config and remove the data.  by doing this we ensure that
     *  the data is always avaialable to all clients
     *
     *  so depending on our context, we make a decision to run a method that will contribute to completing the
     *  move action.
     *
     *
     */
    protected function handle(){
        if( isset( $this->params['moveContext'] ) ){
            $context = $this->params['moveContext'];
            if( $context == 'start' ){
                $this->doStartForMove();
            } else if( $context == 'create' ){
                $this->doCreateForMove();
            } else if( $context == 'delete' ) {
                $this->doDeleteForMove();
            }
        }
    }

    public function handleMoveDeleteError( $errno, $errmsg, $errfile = "filename not given", $errline = "line number not given", $errcontext = "not given" ){
        throw new WebDFS_Exception_MoveException( "errno: $errno - errmsg: $errmsg - errfile: $errfile - errline: $errline" );
    }

    protected function doDeleteForMove(){
        set_error_handler( array( $this, "handleMoveDeleteError") );
        try{
            // check to see if we are a target node in both the
            // old config and the current config at configIndex = 0
            // if we are a target in both configs then we take no action
            // we just send the delte on to the next node
            $currentConfig = $this->config['data'][ 0 ];
            $locClass = $currentConfig['locatorClassName'];
            $locator = new $locClass( $currentConfig );
            $currentNodes = $locator->findNodes( $this->params['name'] );
            if( !$this->iAmATarget( $currentNodes ) ){
                $this->_deleteData();
            } else {
                $this->debugLog('moveDeleteAlike', $this->config['thisProxyUrl'] );
            }
            $this->sendDeleteForMove();
        } catch( Exception $e ){
            $this->errorLog('doDeleteForMove', $this->params['action'], $this->params['moveContext'], $this->params['name'], $e->getMessage(), $e->getTraceAsString() );
            WebDFS_Helper::send500();
        }
        restore_error_handler();
    }

    public function handleMoveCreateError( $errno, $errmsg, $errfile = "filename not given", $errline = "line number not given", $errcontext = "not given" ){
        throw new WebDFS_Exception_MoveException( " $errno : $errmsg : $errfile : $errline " );
    }

    /**
     * called when we are in create context for a move operation
     *
     * the algorithm at this point is very similar to the
     * putData algorithm.
     *
     * spoolData
     * saveData
     * sendDataForMove
     *
     */

    protected function doCreateForMove(){
        set_error_handler( array( $this, "handleMoveCreateError") );
        try{
            // check to see if we are a target node in both the
            // old config and the current config at configIndex = 0
            // if we are a target in both configs then we take no action
            // we just send the delte on to the next node
            $oldConfig = $this->config['data'][ $this->params['moveConfigIndex'] ];
            $locClass = $oldConfig['locatorClassName'];
            $locator = new $locClass( $oldConfig );
            $oldNodes = $locator->findNodes( $this->params['name'] );
            if( !$this->iAmATarget( $oldNodes ) ){
                $this->spoolData();
                $this->saveData();
            }else {
                $this->debugLog('moveCreateAlike',$this->config['thisProxyUrl'] );
            }
            $this->sendDataForMove();
            // now we need to check if this is the last node to receive the data for a move
            // by calling getForwardInfo.  if the forwardata is empty then we assume we are the last node
            // and start the deleteForMove process
            // which means that we need to set the config index to the same value
            // as the moveConfigIndex so that we start the propagation of the delete
            // down the correct chain
            $this->debugLog('moveFinished');
            if( !$this->getForwardInfo() ){
                $this->startDeleteForMove();
            }
        } catch( Exception $e ){
            $this->errorLog('doCreateForMove', $this->params['action'], $this->params['moveContext'], $this->params['name'], $e->getMessage(), $e->getTraceAsString() );
            WebDFS_Helper::send500();
        }
        restore_error_handler();
    }


    public function handleMoveStartError( $errno, $errmsg, $errfile = "filename not given", $errline = "line number not given", $errcontext = "not given" ){
        throw new WebDFS_Exception_MoveException( " $errno : $errmsg : $errfile : $errline " );
    }
    /**
     *  called when we are in start context for a move operation
     *
     *  we check to see if we were a target node
     *  that needs to be moved.  it checks using the configIndex value in the params name 'moveConfigIndex'.
     *  if we are an owning node, we set the context to 'create' and we send the data to be moved
     *  to the first node of the current config that should have the data.
     *
     *  if we are not the owning node, we get the list of possible nodes that are owners
     *  and send the move command to the owner node with a context of 'start'
     */
    protected function doStartForMove(){
        // here we make a new locator instance and use it to locate the old data
        require_once( $this->dataConfig['locatorClassPath'] );
        set_error_handler( array( $this, "handleMoveStartError") );
        try{
            $locClass = $this->dataConfig['locatorClassName'];
            $locator = new $locClass( $this->config['data'][ $this->params['moveConfigIndex'] ] );
            $thisProxyUrl = $this->config['thisProxyUrl'];
            $objKey = $this->params['name'];

            if( $this->iAmATarget( $locator->findNodes( $objKey ) ) ){
                $this->sendDataToStartMove( );
            } else {
                $this->sendStartMove( $locator );
            }
        } catch( Exception $e ){
            $this->errorLog('doStartForMove', $this->params['action'], $this->params['moveContext'], $this->params['name'], $e->getMessage(), $e->getTraceAsString() );
            WebDFS_Helper::send500( );
        }
        restore_error_handler();
    }

    /**
     * here is where we start the delete process during a move operation.
     * this will only be called after the file in question has been moved to
     * all of its new locations (nodes, servers, etc.)
     *
     * @param <type>
     */
    protected function startDeleteForMove( ){
        $deleteForMoveConfig = $this->config['data'][ $this->params['moveConfigIndex'] ];
        $locClass = $deleteForMoveConfig['locatorClassName'];
        $locator = new $locClass( $deleteForMoveConfig );
        $nodes = $locator->findNodes( $this->params['name'] );
        $replicationDegree = $locator->getReplicationDegree();
        $position = $this->getTargetNodePosition( $nodes );
        $forwardInfo = $this->getForwardInfo( 0, $position, $replicationDegree, $nodes );

        if( $forwardInfo ){
            $errNo = 0;
            $origPosition = $forwardInfo['position'];
            $curl = curl_init();
            $headers = array();
            // disconnect before we make another request
            WebDFS_Helper::disconnectClient();
            $loops = 0;
            $nodeLength = count( $nodes );
            do{
                if( $loops >= $nodeLength ){
                    $this->errorLog('noNodeFound',__FUNCTION__, __FILE__, __LINE__);
                    break;
                }
                $loops++;
                $headers[0] = self::HEADER_MOVE_CONTEXT.': delete';
                // here we have to switch the config index headers so that
                // we start the correct path for deletion
                // so notice that we are setting the moveConfig to our current config value
                // and we are setting the current config to be the moveConfig value
                $headers[1] = self::HEADER_MOVE_CONFIG_INDEX.': '.$this->params['configIndex'];
                $headers[2] = self::HEADER_CONFIG_INDEX.': '.$this->params['moveConfigIndex'];
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers );

                curl_setopt($curl, CURLOPT_TIMEOUT, 10);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "MOVE");
                curl_setopt($curl, CURLOPT_URL, $forwardInfo['forwardUrl'] );
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($curl);
                $errNo = curl_errno($curl);
                $info = curl_getinfo($curl);
                if( $errNo || $info['http_code'] >= 400 ){
                    $this->errorLog('startDeleteForMove',$forwardInfo['replica'], $forwardInfo['forwardUrl'],curl_errno($curl), curl_error($curl), $response);
                    $forwardInfo = $this->getForwardInfo( $forwardInfo['replica'], $forwardInfo['position'], $replicationDegree, $nodes );
                } else {
                    $this->debugLog('moveStartDelete', $forwardInfo['replica'], $forwardInfo['forwardUrl'] );
                }
            } while( ( $errNo || $info['http_code'] >= 400 ) && $origPosition != $forwardInfo['position'] && $forwardInfo );
            curl_close($curl);
        } else {
            $this->errorLog('startDeleteForMoveEmptyForward');
        }
    }

    protected function sendDeleteForMove( ){
        $forwardInfo = $this->getForwardInfo();
        if( $forwardInfo ){
            $errNo = 0;
            $origPosition = $forwardInfo['position'];
            $curl = curl_init();
            $headers = array();
            // disconnect before we make another request
            WebDFS_Helper::disconnectClient();
            $loops = 0;
            $nodeLength = count( $this->getTargetNodes( ) );
            do{
                if( $loops >= $nodeLength ){
                    $this->errorLog('noNodeFound',__FUNCTION__, __FILE__, __LINE__);
                    break;
                }
                $loops++;
                $headers[0] = self::HEADER_REPLICA.': '.$forwardInfo['replica'];
                $headers[1] = self::HEADER_POSITION.': '.$forwardInfo['position'];
                $headers[2] = self::HEADER_MOVE_CONTEXT.': delete';
                $headers[3] = self::HEADER_MOVE_CONFIG_INDEX.': '.$this->params['moveConfigIndex'];
                $headers[4] = self::HEADER_CONFIG_INDEX.': '.$this->params['configIndex'];
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers );

                curl_setopt($curl, CURLOPT_TIMEOUT, 10);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "MOVE");
                curl_setopt($curl, CURLOPT_URL, $forwardInfo['forwardUrl'] );
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($curl);
                $errNo = curl_errno($curl);
                $info = curl_getinfo($curl);
                if( $errNo || $info['http_code'] >= 400 ){
                    $this->errorLog('sendDeleteForMove',$forwardInfo['replica'], $forwardInfo['forwardUrl'],curl_errno($curl), curl_error($curl), $response);
                    $forwardInfo = $this->getForwardInfo( $forwardInfo['replica'], $forwardInfo['position'] );
                } else {
                    $this->debugLog('sendDeleteForMove', $forwardInfo['replica'], $forwardInfo['forwardUrl'] );
                }
            } while( ( $errNo || $info['http_code'] >= 400 ) && $origPosition != $forwardInfo['position'] && $forwardInfo );
            curl_close($curl);
        }
    }

    protected function sendStartMove( $locator, $moveConfigIndex = null ){

        if( is_null( $moveConfigIndex ) ){
            $moveConfigIndex = $this->params['moveConfigIndex'];
        }

        $nodes = $locator->findNodes( $this->params['name'] );
        $replicationDegree = $locator->getReplicationDegree();
        $forwardInfo = $this->getForwardInfo( null, null, $replicationDegree, $nodes );
        if( $forwardInfo ){
            $errNo = 0;
            $origPosition = $forwardInfo['position'];
            $curl = curl_init();
            $headers = array();
            // disconnect before we make another request
            WebDFS_Helper::disconnectClient();
            $loops = 0;
            $nodeLength = count( $nodes );
            do{
                if( $loops >= $nodeLength ){
                    $this->errorLog('noNodeFound',__FUNCTION__, __FILE__, __LINE__);
                    break;
                }
                $loops++;
                $headers[0] = self::HEADER_MOVE_CONTEXT.': start';
                $headers[1] = self::HEADER_MOVE_CONFIG_INDEX.': '.$moveConfigIndex;
                $headers[2] = self::HEADER_CONFIG_INDEX.': '.$this->params['configIndex'];
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers );

                curl_setopt($curl, CURLOPT_TIMEOUT, 10);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "MOVE");
                curl_setopt($curl, CURLOPT_URL, $forwardInfo['forwardUrl'] );
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($curl);
                $errNo = curl_errno($curl);
                $info = curl_getinfo($curl);
                if( $errNo || $info['http_code'] >= 400 ){
                    $this->errorLog('sendStartMove',$forwardInfo['replica'], $forwardInfo['forwardUrl'],curl_errno($curl), curl_error($curl), $response);
                    $forwardInfo = $this->getForwardInfo( $forwardInfo['replica'], $forwardInfo['position'], $replicationDegree, $nodes );
                } else {
                    $this->debugLog('sendStartMove', $forwardInfo['replica'], $forwardInfo['forwardUrl'], $this->params['moveConfigIndex'],$this->params['configIndex']  );
                }
            } while( ( $errNo || $info['http_code'] >= 400 ) && $origPosition != $forwardInfo['position'] && $forwardInfo );
            curl_close($curl);
        }
    }

    protected function sendDataToStartMove( ){
        $filePath = $this->finalPath;
        $forwardInfo = $this->getForwardInfo( );
        if( $forwardInfo ){
            $fh = fopen($filePath, "rb");
            if( $fh ){
                $size = filesize( $filePath );
                rewind($fh);

                $errNo = 0;
                $origPosition = $forwardInfo['position'];
                $curl = curl_init();
                $headers = array();
                // disconnect before we make another request
                WebDFS_Helper::disconnectClient();
                $loops = 0;
                $nodeLength = count( $this->getTargetNodes( ) );
                do{
                    if( $loops >= $nodeLength ){
                        $this->errorLog('noNodeFound',__FUNCTION__, __FILE__, __LINE__);
                        break;
                    }
                    $loops++;
                    $headers[0] = self::HEADER_MOVE_CONTEXT.': create';
                    $headers[1] = self::HEADER_MOVE_CONFIG_INDEX.': '.$this->params['moveConfigIndex'];
                    $headers[2] = self::HEADER_CONFIG_INDEX.': '.$this->params['configIndex'];
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers );

                    curl_setopt($curl, CURLOPT_UPLOAD, true);
                    curl_setopt($curl, CURLOPT_INFILE, $fh);
                    curl_setopt($curl, CURLOPT_INFILESIZE, $size );
                    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
                    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "MOVE");
                    curl_setopt($curl, CURLOPT_URL, $forwardInfo['forwardUrl']);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($curl);
                    $errNo = curl_errno($curl);
                    $info = curl_getinfo($curl);
                    if( $errNo || $info['http_code'] >= 400 ){
                        $this->errorLog('sendDataToStartMove',$forwardInfo['replica'], $forwardInfo['forwardUrl'],curl_errno($curl), curl_error($curl), $response);
                        $forwardInfo = $this->getForwardInfo( $forwardInfo['replica'], $forwardInfo['position'] );
                    }else {
                        $this->debugLog('sendDataToStartMove', $filePath, $forwardInfo['forwardUrl'] );
                    }
                } while( ( $errNo || $info['http_code'] >= 400 ) && $origPosition != $forwardInfo['position'] && $forwardInfo );
                curl_close($curl);
                fclose($fh);
            } else {
                $msg = "received command in start context to move ".$this->params['name']." but cannot find file!";
                error_log($msg);
                throw new WebDFS_Exception_MoveException($msg);
            }
        }
    }

    protected function sendDataForMove( ){
        $filePath = $this->finalPath;
        $forwardInfo = $this->getForwardInfo( );
        if( $forwardInfo ){
            $fh = fopen($filePath, "rb");
            $size = filesize( $filePath );
            rewind($fh);

            $errNo = 0;
            $origPosition = $forwardInfo['position'];
            $curl = curl_init();
            $headers = array();
            // disconnect before we make another request
            WebDFS_Helper::disconnectClient();
            $loops = 0;
            $nodeLength = count( $this->getTargetNodes( ) );
            do{
                if( $loops >= $nodeLength ){
                    $this->errorLog('noNodeFound',__FUNCTION__, __FILE__, __LINE__);
                    break;
                }
                $loops++;
                $headers[0] = self::HEADER_REPLICA.': '.$forwardInfo['replica'];
                $headers[1] = self::HEADER_POSITION.': '.$forwardInfo['position'];
                $headers[2] = self::HEADER_MOVE_CONTEXT.': create';
                $headers[3] = self::HEADER_MOVE_CONFIG_INDEX.': '.$this->params['moveConfigIndex'];
                $headers[4] = self::HEADER_CONFIG_INDEX.': '.$this->params['configIndex'];
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers );

                curl_setopt($curl, CURLOPT_UPLOAD, true);
                curl_setopt($curl, CURLOPT_INFILE, $fh);
                curl_setopt($curl, CURLOPT_INFILESIZE, $size );
                curl_setopt($curl, CURLOPT_TIMEOUT, 10);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "MOVE");
                curl_setopt($curl, CURLOPT_URL, $forwardInfo['forwardUrl']);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($curl);
                $errNo = curl_errno($curl);
                $info = curl_getinfo($curl);
                if( ( $errNo || $info['http_code'] >= 400 ) ){
                    $this->errorLog('sendDataForMove',$forwardInfo['replica'], $forwardInfo['forwardUrl'],curl_errno($curl), curl_error($curl), $response);
                    $forwardInfo = $this->getForwardInfo( $forwardInfo['replica'], $forwardInfo['position'] );
                }else {
                    $this->debugLog('sendDataForMove', $forwardInfo['replica'], $forwardInfo['forwardUrl'] );
                }
            } while( ( $errNo || $info['http_code'] >= 400 ) && $origPosition != $forwardInfo['position'] && $forwardInfo );
            curl_close($curl);
            fclose($fh);
        }
    }
}