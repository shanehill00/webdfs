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

require_once 'WebDFS/Helper.php';

class WebDFS_Client{

    /**
     * the data locator that is used for looking up the
     * location of an object.
     *
     * @var <WebDFS_DataLocator_HonickyMillerR>
     */
    
    protected $locator = null;
    protected $config = null;

    const WebDFS_PUT_ERR = 1;
    const WebDFS_DELETE_ERR = 2;
    const WebDFS_GET_ERR = 3;

    protected $errs;

    public function __construct( $config ){
        $nodeConfig = $config['data'][0];
        require_once( $nodeConfig['locatorClassPath'] );
        $locatorClassName = $nodeConfig['locatorClassName'];
        $this->locator = new $locatorClassName( $nodeConfig );
        $this->config = $config;

        $this->errs = array(
            self::WebDFS_PUT_ERR => array(
                'className' => 'WebDFS_Exception_PutException',
                'msg'   => 'PUT Exception',
                'classPath' => 'WebDFS/Exception/PutException.php'
            ),
            self::WebDFS_DELETE_ERR => array(
                'className' => 'WebDFS_Exception_DeleteException',
                'msg'   => 'DELETE Exception',
                'classPath' => 'WebDFS/Exception/DeleteException.php'
            ),
            self::WebDFS_GET_ERR => array(
                'className' => 'WebDFS_Exception_GetException',
                'msg'   => 'GET Exception',
                'classPath' => 'WebDFS/Exception/GetException.php'
            ),
        );
    }

    /**
     * fetches the entity and returns a ref to it
     *
     *
     * @param <string> $fileId
     *
     * @throws WebDFS_Client_GetException
     */
    public function &get( $fileId ){
        $paths = $this->getPaths($fileId);
        $response = "";
        if( count( $paths ) ) {
            $url = $paths[0]['url'];
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_TIMEOUT, 10);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            $response = curl_exec($curl);
            $this->checkError( $curl, self::WebDFS_GET_ERR, $response );
        }
        return $response;
    }

    public function getPaths( $fileId ){
    	$fileId = trim($fileId,'/');
        $paths = array();
        $nodes = $this->locator->findNodes( $fileId );
        $pathHash = WebDFS_Helper::getPathHash( $fileId, $this->config );
        $filename = basename($fileId);
        foreach( $nodes as $node ){
            $data = array();
            $data['url'] = $node['proxyUrl'].'/'.$fileId;
            $data['proxyUrl'] = $node['proxyUrl'];
            if( $pathHash === '' ){
	            $data['staticUrl'] = $node['staticUrl']."/$filename";
            } else {
	            $data['staticUrl'] = $node['staticUrl']."/$pathHash/$filename";
            }
            $paths[] = $data;
        }
        return $paths;
    }

    /**
     *
     * @param <string> $fileId
     * @throws WebDFS_Client_GetException
     */
    public function delete( $fileId ){
        $paths = $this->getPaths($fileId);
        $url = $paths[0]['url'];
        if( count( $paths ) ) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_TIMEOUT, 10);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($curl);
            $this->checkError( $curl, self::WebDFS_DELETE_ERR, $response );
        }
    }

    /**
     * @param <string> $fileId
     * @param <string> $filePath
     *
     * @throws WebDFS_Client_PutException
     */
    public function put( $fileId, $filePath ){
        $this->set( $fileId, $filePath );
    }

    /**
     * @param <string> $fileId
     * @param <string> $filePath
     *
     * @throws WebDFS_Client_PutException
     */
    public function set( $fileId, $filePath ){
        $paths = $this->getPaths($fileId);
        if( count( $paths ) ) {
            $url = $paths[0]['url'];
            $fh = fopen($filePath, "rb");
            $size = filesize( $filePath );
            rewind($fh);
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_INFILE, $fh);
            curl_setopt($curl, CURLOPT_INFILESIZE, $size );
            curl_setopt($curl, CURLOPT_TIMEOUT, 10);
            curl_setopt($curl, CURLOPT_PUT, true);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content_Length: $size") );
            $response = curl_exec($curl);
            $this->checkError( $curl, self::WebDFS_PUT_ERR, $response );
            fclose($fh);
        }
    }

    /**
     * @param <curl> $curl
     * @param <int> $webDfsErrCode
     * @param <reference> $additionalInfo
     */
    protected function checkError( $curl, $webDfsErrCode, &$additionalInfo = "" ){
        $info = curl_getinfo( $curl );
        $isHttpErr =  isset( $info['http_code'] ) && ( $info['http_code'] >= 400 );
        $isOtherErr = curl_errno($curl);
        $data = array();
        if( $isOtherErr || $isHttpErr ){
            $errInfo = $this->errs[ $webDfsErrCode ];
            require_once( $errInfo['classPath'] );
            $exceptionClass = $errInfo['className'];
            $data['httpCode'] = isset($info['http_code']) ? $info['http_code'] : '';
            $data['url'] = isset($info['url']) ? $info['url'] : '';
            $data['contentType'] = isset($info['content_type']) ? $info['content_type'] : '';
            $data['body'] = $additionalInfo;
            //[url] => http://www.google.com/dfgsdfgsfg
            //[content_type] => text/html; charset=ISO-8859-1
            //[http_code] => 404
            if( $isOtherErr ){
                $msg = $webDfsErrCode." - ".$errInfo['msg']. " : ".curl_errno($curl)." - " .curl_error($curl)." : $additionalInfo";
            } else {
                $msg = "http error! ".$info['http_code'];
            }
            throw new $exceptionClass( $msg, $data );
        }
    }
    
    /**
     * 
     * will fetch a file from the passed url and upload into webdfs
     * 
     * @param string $url
     * @param string $id
     * 
     * @return array the same data as returned by egtPaths( fileId ) 
     * 
     */
    public function fetchFileFromUrl( $url, $id = null ){
        
        if( !$id ){
            $id = uuid_create();
        }
        
        // get the image from the url
        $filedir = sys_get_temp_dir();
        $tmpfname = tempnam( $filedir, 'WebDFS' );
        file_put_contents( $tmpfname, file_get_contents( $url ) );
        
        // now upload the file into web dfs
        $this->set( $id, $tmpfname );
        
        // now we remove the tmp file
        unlink($tmpfname);
        
        // now get the details from getpaths and return them
        $details = $this->getPaths($id);
        $details['id'] = $id;
        
        return $details;
    }
}