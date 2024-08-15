<?php

namespace deemru;

use Composer\CaBundle\CaBundle;

class Fetcher
{
    private $logger;
    private $lastError;

    private $hosts;
    private $timeoutConnect = 5;
    private $timeoutExec = 15;
    private $options = [];
    private $unsafe = false;
    private $json = true;
    private $strategy = 0;

    private $curls;
    private $multiCurl;

    private $cache = [];
    private $timeoutCache = 0.5;

    private function __construct(){}

    private function error( $message )
    {
        if( isset( $this->logger ) )
            return $this->logger->error( $message );
        $this->lastError = $message;
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function setTimeoutConnect( $timeout )
    {
        $this->timeoutConnect = $timeout;
        return $this;
    }

    public function setTimeoutExec( $timeout )
    {
        $this->timeoutExec = $timeout;
        return $this;
    }

    public function setOptions( $options )
    {
        $this->options = isset( $options ) ? $options : [];
        return $this;
    }

    public function setTimeoutCache( $timeout )
    {
        $this->timeoutCache = $timeout;
        return $this;
    }

    public function setUnsafe( $unsafe )
    {
        $this->unsafe = $unsafe;
        return $this;
    }

    public function setLogger( $logger )
    {
        if( is_object( $logger ) && method_exists( $logger, 'error' ) )
            $this->logger = $logger;
        return $this;
    }

    // 0 - no strategy
    // 1 - pop host top on success
    public function setStrategy( $strategy )
    {
        $this->strategy = $strategy;
        return $this;
    }

    static public function host( $host )
    {
        return ( new Fetcher )->setHosts( [ $host ] );
    }

    static public function hosts( $hosts )
    {
        return ( new Fetcher )->setHosts( $hosts );
    }

    private function jd( $json )
    {
        $decoded = json_decode( $json, true, 512, JSON_BIGINT_AS_STRING );
        return $decoded === null ? false : $decoded;
    }

    private function fetchInit( $host, $connect )
    {
        if( false === ( $curl = curl_init() ) )
            return false;

        $options = [ CURLOPT_CONNECTTIMEOUT  => $this->timeoutConnect,
                     CURLOPT_TIMEOUT         => $this->timeoutExec,
                     CURLOPT_URL             => $host,
                     CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
                     CURLOPT_CONNECT_ONLY    => true,
                     CURLOPT_CAINFO          => CaBundle::getBundledCaBundlePath() ];

        if( $this->unsafe )
        {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = false;
        }

        foreach( $this->options as $k => $v )
            $options[$k] = $v;

        if( false === curl_setopt_array( $curl, $options ) )
            return false;

        if( $connect && !curl_exec( $curl ) && 0 !== ( $errno = curl_errno( $curl ) ) )
        {
            $this->error( $host . ' (CONNECT): ' . $errno . ' (' . curl_error( $curl ) . ')' );
            curl_close( $curl );

            return false;
        }

        if( false === curl_setopt_array( $curl, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECT_ONLY    => false,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
        ] ) )
        {
            curl_close( $curl );
            return false;
        }

        return $curl;
    }

    private function setHosts( $hosts )
    {
        $this->hosts = $hosts;
        return $this;
    }

    public function getHost()
    {
        return isset( $this->hosts[0] ) ? $this->hosts[0] : false;
    }

    /**
     * Fetches GET or POST response
     *
     * @param  string       $url            URL of request
     * @param  bool         $post           POST or GET (default: GET)
     * @param  string|null  $data           Data for POST (default: null)
     * @param  array|null   $ignoreCodes    Array of ignored HTTP codes (default: null)
     * @param  array|null   $headers        Optional HTTP headers (default: null)
     *
     * @return string|false Returns response data or FALSE on failure
     */
    public function fetch( $url, $post = false, $data = null, $ignoreCodes = null, $headers = null )
    {
        $this->lastError = false;

        if( !$post && null !== ( $fetch = $this->getCache( $url ) ) )
            return $fetch;

        $n = count( $this->hosts );
        for( $i = 0; $i < $n; $i++ )
        {
            $host = $this->hosts[$i];
            if( isset( $this->curls[$i] ) )
                $curl = $this->curls[$i];
            else
            {
                $curl = $this->fetchInit( $host, true );
                if( $curl === false )
                    continue;

                $this->curls[$i] = $curl;
            }

            $fetch = $this->fetchSingle( $host, $curl, $url, $post, $data, $ignoreCodes, $headers )[0];

            if( false !== $fetch ||
                ( isset( $ignoreCodes ) && in_array( curl_getinfo( $curl, CURLINFO_HTTP_CODE ), $ignoreCodes ) ) )
            {
                if( !$post )
                    $this->setCache( $url, $fetch );

                if( $i !== 0 && $this->strategy === 1 )
                {
                    $hosts = [ $this->hosts[$i] ];
                    $curls = [ $this->curls[$i] ];
                    for( $j = 0; $j < $n; $j++ )
                        if( $j !== $i )
                        {
                            $hosts[] = $this->hosts[$j];
                            $curls[] = isset( $this->curls[$j] ) ? $this->curls[$j] : null;
                        }
                    $this->hosts = $hosts;
                    $this->curls = $curls;
                }

                return $fetch;
            }

            if( curl_getinfo( $curl, CURLINFO_HTTP_CODE ) === 0 )
            {
                curl_close( $curl );
                unset( $this->curls[$i] );
            }
        }

        return false;
    }

    /**
     * Fetches GET or POST responses from all nodes
     *
     * @param  string       $url            URL of request
     * @param  bool         $post           POST or GET (default: GET)
     * @param  string|null  $data           Data for POST (default: null)
     * @param  array|null   $ignoreCodes    Array of ignored HTTP codes (default: null)
     * @param  array|null   $headers        Optional HTTP headers (default: null)
     *
     * @return array|false Returns data responses from all nodes or FALSE on failure
     */
    public function fetchMulti( $url, $post = false, $data = null, $ignoreCodes = null, $headers = null )
    {
        $n = count( $this->hosts );
        for( $i = 0; $i < $n; $i++ )
        {
            $host = $this->hosts[$i];
            if( isset( $this->curls[$i] ) )
                $curl = $this->curls[$i];
            else
            {
                $curl = $this->fetchInit( $host, false );
                if( $curl === false )
                    continue;

                $this->curls[$i] = $curl;
            }

            if( !$this->fetchSetup( $host, $curl, $url, $post, $data, $headers ) )
                return false;
        }

        if( !isset( $this->multiCurl ) )
            $this->multiCurl = curl_multi_init();

        for( $i = 0; $i < $n; $i++ )
            if( isset( $this->curls[$i] ) )
                curl_multi_add_handle( $this->multiCurl, $this->curls[$i] );

        $tt = microtime( true );
        $active = 0;
        for( ;; )
        {
            if( CURLM_OK != curl_multi_exec( $this->multiCurl, $active ) )
                break;

            if( $active === 0 )
                break;

            curl_multi_select( $this->multiCurl );
        }
        $tt = microtime( true ) - $tt;

        $multiData = [];
        for( $i = 0; $i < $n; $i++ )
        {
            $host = $this->hosts[$i];
            if( isset( $this->curls[$i] ) )
            {
                $curl = $this->curls[$i];
                $data = curl_multi_getcontent( $curl );
                $data = $this->fetchResult( $data, $host, $curl, $ignoreCodes );
                $multiData[$host] = $data;

                curl_multi_remove_handle( $this->multiCurl, $curl );
                continue;
            }

            $multiData[$host] = [ false, $tt ];
        }

        return $multiData;
    }

    private function fetchSetup( $host, $curl, $url, $post, $data, $headers )
    {
        $options = [ CURLOPT_URL => $host . $url, CURLOPT_POST => $post ];

        if( isset( $headers ) )
            $options[CURLOPT_HTTPHEADER] = $headers;

        if( isset( $data ) )
        {
            $options[CURLOPT_POSTFIELDS] = $data;
            if( !isset( $headers ) && $this->json )
                $options[CURLOPT_HTTPHEADER] = [ 'Content-Type: application/json', 'Accept: application/json' ];
        }

        return curl_setopt_array( $curl, $options );
    }

    private function fetchResult( $data, $host, $curl, $ignoreCodes )
    {
        $code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
        if( 0 !== ( $errno = curl_errno( $curl ) ) || $code !== 200 || false === $data )
        {
            if( !isset( $ignoreCodes ) || $errno !== 0 || !in_array( $code, $ignoreCodes ) )
            {
                $curl_error = curl_error( $curl );
                if( is_string( $data ) && $this->json && false !== ( $json = $this->jd( $data ) ) && isset( $json['message'] ) )
                {
                    $status = isset( $json['error'] ) ? $json['error'] : ( isset( $json['status'] ) ? $json['status'] : '...' );
                    $this->error( $host . ' (HTTP ' . $code . '): ' . $status . ' (' . ( isset( $json['message'] ) ? $json['message'] : '...' ) . ')' );
                }
                else
                    $this->error( $host . ' (HTTP ' . $code . '): cURL ' . $errno . ' (' . ( empty( $curl_error ) ? '...' : $curl_error ) . ')' );
            }

            $data = false;
        }

        return [ $data, curl_getinfo( $curl, CURLINFO_TOTAL_TIME ) ];
    }

    private function fetchSingle( $host, $curl, $url, $post, $data, $ignoreCodes, $headers )
    {
        if( false === $this->fetchSetup( $host, $curl, $url, $post, $data, $headers ) )
            return [ false, 0 ];

        return $this->fetchResult( curl_exec( $curl ), $host, $curl, $ignoreCodes );
    }

    private function setCache( $key, $value )
    {
        if( $this->timeoutCache <= 0 )
            return;

        $value = [ $value, microtime( true ) ];
        if( count( $this->cache ) >= 256 )
            $this->cache = [ $key => $value ];
        else
            $this->cache[$key] = $value;
    }

    private function getCache( $key )
    {
        if( $this->timeoutCache > 0 && isset( $this->cache[$key] ) )
        {
            $temp = $this->cache[$key];
            $value = $temp[0];
            $tt = $temp[1];
            if( microtime( true ) - $tt < $this->timeoutCache )
                return $value;

            unset( $this->cache[$key] );
        }

        return null;
    }

    public function resetCache()
    {
        $this->cache = [];
    }

    public function setBest( $url, $scoreFunction )
    {
        $multis = $this->fetchMulti( $url );
        $scores = [];
        $i = 0;
        foreach( $multis as $values )
            $scores[$i++] = $scoreFunction( $values[0], $values[1] );
        arsort( $scores );
        $hosts = [];
        $curls = [];
        foreach( $scores as $key => $_ )
        {
            $hosts[] = $this->hosts[$key];
            $curls[] = $this->curls[$key];
        }
        $this->hosts = $hosts;
        $this->curls = $curls;
    }
}
