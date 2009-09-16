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
     * need to add a param to indicate that we want to continue looking for the data
     * if we are not actually a target node.  logically, any client directly
     * asking for data should be able to locate the exact nodes from which
     * to ask.  So this really should not happen very often (unles someone is making lots of bad requests)
     * and might even be indicative a problem.  we do not really have a way to tell if it denotes a prob or not
     */
    public function handle(){
        $filePath = $this->finalPath;
        $config = $this->dataConfig;
        $iAmATarget = $this->iAmATarget();
        if( $iAmATarget ){
            $dataFH = '';
            if( file_exists( $filePath) ){
                $dataFH = fopen( $filePath, "rb" );
            } else {
                // we have a miss
                // we need to iterate the config array until we find
                // a node that has the data and then initiate a move
                // with the correct configIndex
                // if we cannot find file then we return a 404
                if( $this->params['getContext'] != self::GET_CONTEXT_AUTOMOVE
                     && isset( $this->config['autoMove'] )
                      && $this->config['autoMove'] )
                {
                    $dataFH = $this->autoMove();
                    $filePath = $this->tmpPath;
                }
            }

            if( $dataFH ){
                $finfo = finfo_open( FILEINFO_MIME, $this->config["magicDbPath"] );
                $contentType = finfo_file( $finfo, $filePath );
                finfo_close( $finfo );

                rewind( $dataFH );
                header( "Content-Type: $contentType");
                header( "Content-Length: ".filesize( $filePath ) );
                fpassthru( $dataFH );

                fclose( $dataFH );
                if( $this->tmpPath == $filePath ){
                    // we do this to remove the temp file we retrieved
                    // when we got a miss
                    unlink( $filePath );
                }

            } else {
                WebDFS_Helper::send404( $this->params['name'] );
            }
        } else {
            // get the paths, choose one, and print a 301 redirect
            $nodes = $this->getTargetNodes();
            if( $nodes ){
                WebDFS_Helper::send301( $nodes[ 0 ]['proxyUrl'].'/'.$this->params['name'] );
            }
        }
    }

    /**
     * autoMove will attempt to find the data we are looking for
     * and download it to a temp file and return an open file handle
     * ready for reading
     *
     * we also initiate a move if we successfully find the data
     *
     */
    protected function autoMove(){
        $fh = null;
        $totalConfigs = count( $this->config['data'] );
        $headers = array();
        $headers[0] = self::HEADER_GET_CONTEXT.': '.self::GET_CONTEXT_AUTOMOVE;
        for( $configIndex = 1; $configIndex < $totalConfigs; $configIndex++ ){
            $moveFromConfig = $this->config['data'][ $configIndex ];
            $locClass = $moveFromConfig['locatorClassName'];
            $locator = new $locClass( $moveFromConfig );
            $filename = $this->params['name'];
            $nodes = $locator->findNodes( $filename );
            foreach( $nodes as $node ){
                if( $node['proxyUrl'] != $this->config['thisProxyUrl'] ){
                    $url = $node['proxyUrl'].'/'.urlencode($filename);
                    $curl = curl_init();
                    $fh = fopen( $this->tmpPath, "wb+" );
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers );
                    curl_setopt($curl, CURLOPT_FILE, $fh);
                    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
                    curl_setopt($curl, CURLOPT_HEADER, false);
                    curl_setopt($curl, CURLOPT_URL, $url);
                    curl_setopt($curl, CURLOPT_FAILONERROR, true);
                    curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
                    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
                    curl_exec($curl);
                    $info = curl_getinfo($curl);
                    if( !curl_errno($curl) && $info['http_code'] < 400 ){
                        $this->debugLog('autoMove');
                        $this->sendStartMove( $locator, $configIndex );
                        break 2;
                    }
                    fclose( $fh );
                    $fh = null;
                }
            }
        }
        if( $fh ){
            fclose( $fh );
            $fh = fopen( $this->tmpPath, "rb" );
        }
        return $fh;
    }
}