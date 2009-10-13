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
require_once 'WebDFS/Exception/DeleteException.php';
require_once 'WebDFS.php';

class WebDFS_Delete extends WebDFS{

    public function __construct( $config, $params ){
        parent::__construct($config, $params);
    }
    
    public function handleDeleteDataError( $errno, $errmsg, $errfile = "filename not given", $errline = "line number not given", $errcontext = "not given" ){
        $msg = sprintf( $this->config['exceptionMsgs']['handleDeleteDataError'], $errno , $errmsg , $errfile , $errline );
        throw new WebDFS_Exception_DeleteException( $msg );
    }

    public function handleForwardDeleteError( $errno, $errmsg, $errfile = "filename not given", $errline = "line number not given", $errcontext = "not given" ){
        $msg = sprintf( $this->config['exceptionMsgs']['handleForwardDeleteError'], $errno , $errmsg , $errfile , $errline );
        throw new WebDFS_Exception_DeleteException( $msg );
    }

    public function handle(){
        set_error_handler( array( $this, "handleDeleteDataError") );
        try{
            $this->_deleteData( );
        } catch( WebDFS_Exception_DeleteException $e ){
            $this->errorLog('deleteData', $this->params['action'], $this->params['name'], $e->getMessage(), $e->getTraceAsString() );
            WebDFS_Helper::send500( $this->config['errMsgs']['delete500'], $this->params['name'] );
        }
        restore_error_handler();


        set_error_handler( array( $this, "handleForwardDeleteError") );
        try{
            $this->sendDelete();
        } catch( WebDFS_Exception_DeleteException $e ){
            $this->errorLog('deleteForward', $this->params['action'], $this->params['name'], $e->getMessage(), $e->getTraceAsString() );
            WebDFS_Helper::send500($error500Msg);
        }
        restore_error_handler();
    }

    /**
     * we will only loop until we see that the position number we are currently
     * looping with is the same as the position number with which we started.
     * if the two position values are equal then that means we have cycled on the
     * whole node list and we should not continue.  with each iteration we log an error
     * before moving on to the next node in the list
     */
    protected function sendDelete( ){
        $forwardInfo = $this->getForwardInfo( );
        if( $forwardInfo ){
            // disconnect before we make another request
            WebDFS_Helper::disconnectClient();
            if( isset( $this->params['propagateDelete'] ) && !$this->params['propagateDelete'] ){
                return;
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
                curl_setopt($curl, CURLOPT_TIMEOUT, 10);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
                curl_setopt($curl, CURLOPT_URL, $forwardInfo['forwardUrl'] );
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($curl);
                $errNo = curl_errno($curl);
                $info = curl_getinfo($curl);
                if( $errNo || $info['http_code'] >= 400 ){
                    $this->errorLog('deleteSend', $forwardInfo['replica'], $forwardInfo['forwardUrl'], curl_errno($curl), curl_error($curl), $response );
                    $forwardInfo = $this->getForwardInfo( $forwardInfo['replica'], $forwardInfo['position'] );
                }
            } while( ($errNo || $info['http_code'] >= 400) && $forwardInfo && ($origPosition != $forwardInfo['position']) );
            curl_close($curl);
        }
    }
}
