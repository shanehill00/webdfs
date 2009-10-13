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
                    if( !$this->selfHeal() ){
                        WebDFS_Helper::send404( $this->params['name'] );
                    }
                } catch( Exception $e ){
                    $this->errorLog('selfHeal', $e->getMessage(), $e->getTraceAsString() );
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
}