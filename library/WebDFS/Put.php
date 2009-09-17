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
require_once 'WebDFS/Exception/PutException.php';
require_once 'WebDFS.php';

class WebDFS_Put extends WebDFS{

    public function __construct( $config, $params ){
        parent::__construct($config, $params);
    }
    
    public function handleSpoolError( $errno, $errmsg, $errfile = "filename not given", $errline = "line number not given", $errcontext = "not given" ){
        $msg = sprintf( $this->config['exceptionMsgs']['handleSpoolError'], $errno , $errmsg , $errfile , $errline );
        throw new WebDFS_Exception_PutException( $msg );
    }

    public function handleForwardDataError( $errno, $errmsg, $errfile = "filename not given", $errline = "line number not given", $errcontext = "not given" ){
        $msg = sprintf( $this->config['exceptionMsgs']['handleForwardDataError'], $errno , $errmsg , $errfile , $errline );
        throw new WebDFS_Exception_PutException( $msg );
    }
    
    public function handle(){
        if( $this->iAmATarget() ){
            set_error_handler( array( $this, "handleSpoolError") );
            try{
                // get the data from stdin and put it in a temp file
                // we will disconnect the client at this point if we are configured to do so
                // otherwise we hang on to the client, which in most cases is really bad
                // because you might stay connected until the replication chain is completed
                $this->spoolData( );

                // save the data to the appropriate directory and remove the spooled file
                // but only if we are a targetNode, otherwise DO NOTHING
                $this->saveData( );
            } catch( WebDFS_Exception_PutException $e ){
                $this->errorLog('putData', $this->params['action'], $this->params['name'], $e->getMessage(), $e->getTraceAsString() );
                WebDFS_Helper::send500();
                // we want to be sure to exit here because we have errored
                // and the state of the file upload is unknown
                exit();
            }
            restore_error_handler();
        }

        // forward the data on to the next node
        // the reasons for forwarding are:
        //
        // we are NOT a targetNode and are just the first node
        // to receive the upload, so we forward the data to the first targetNode
        // and remove the spooled file
        //
        // OR we are a targetNode and need to fulfill the replication requirements
        // so, we forward data to the next targetNode in our list.
        // however, if we are the last replication targetNode, we DO NOTHING.
        set_error_handler( array( $this, "handleForwardDataError") );
        try{
            $this->forwardDataForPut( );
        } catch( WebDFS_Exception_PutException $e ){
            $this->errorLog('putForward', $this->params['action'], $this->params['name'], $e->getMessage(), $e->getTraceAsString() );
            WebDFS_Helper::send500();
        }
        restore_error_handler();
    }

    protected function forwardDataForPut( ){
        $targetNodes = $this->getTargetNodes();
        if( $this->iAmATarget() ){
            // disconnect the client before we start
            // replicating since we are a storage node
            // and already have the file
            WebDFS_Helper::disconnectClient( $targetNodes, $this->params['fileName'] );
            $this->sendDataForPut( $this->finalPath );
        } else {
            $this->sendDataForPut( $this->config['inputStream'] );
            // unlink( $this->tmpPath );
            // disconnect only after we have
            // uploaded the file to the first
            // storage target node
            WebDFS_Helper::disconnectClient( $targetNodes, $this->params['fileName'] );
        }

    }

    /**
     *
     * we will try to send data to the node identified by the url passed in to us
     * if we experience an error communicating with the url, we then go to the next
     * node in the list and repeat until we get a good node. if we cannot find a good node
     * then we just exit.
     *
     * the position parameter is important here as it needs to correlate to the url to which we are forwarding
     * so we check for the NO_POSITION value and if it is the no
     *
     * @param <string> $filepath - the file to send to the next node
     */
    protected function sendDataForPut( $filePath ){

        $forwardInfo = $this->getForwardInfo( );
        if( $forwardInfo ){
            $fh = fopen($filePath, "rb");
            stream_set_blocking($fh, 0);

            // check to see if the passed path is a plain file
            // or something else.
            // if it is something ele we assume that the
            // $this->params['contentLength'] holds the correct data size
            // otherwise we get the file size using the filesize function

            $sdata = stream_get_meta_data($fh);
            if( strtolower( $sdata['stream_type'] ) == 'plainfile' ){
                $size = filesize( $filePath );
            } else {
                $size = $this->params['contentLength'];
            }
            
            $errNo = 0;
            $origPosition = $forwardInfo['position'];
            $curl = curl_init();
            $headers = array();
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

                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers );
                curl_setopt($curl, CURLOPT_INFILE, $fh);
                curl_setopt($curl, CURLOPT_INFILESIZE, $size );
                curl_setopt($curl, CURLOPT_TIMEOUT, 10);
                curl_setopt($curl, CURLOPT_PUT, 4);
                curl_setopt($curl, CURLOPT_URL, $forwardInfo['forwardUrl']);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($curl);
                $errNo = curl_errno($curl);
                $info = curl_getinfo($curl);
                if( $errNo || $info['http_code'] >= 400 ){
                    $this->errorLog('sendDataForPut',$forwardInfo['replica'], $forwardInfo['forwardUrl'],curl_errno($curl), curl_error($curl), $response);
                    $forwardInfo = $this->getForwardInfo( $forwardInfo['replica'], $forwardInfo['position'] );
                }
            } while(( $errNo || $info['http_code'] >= 400 ) && $origPosition != $forwardInfo['position'] && $forwardInfo );
            curl_close($curl);
            fclose($fh);
        }
    }
}