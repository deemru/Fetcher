<?php

require __DIR__ . '/../vendor/autoload.php';
use deemru\Fetcher;

function a2b( $a )
{
    $b = '';
    foreach( $a as $c )
        $b .= chr( $c );

    return $b;
}

function ms( $ms )
{
    if( $ms > 100 )
        return round( $ms );
    else if( $ms > 10 )
        return sprintf( '%.01f', $ms );
    return sprintf( '%.02f', $ms );
}

class tester
{
    private $successful = 0;
    public $failed = 0;
    private $depth = 0;
    private $info = [];
    private $start = [];
    private $init;

    public function pretest( $info )
    {
        $this->info[$this->depth] = $info;
        $this->start[$this->depth] = microtime( true );
        if( !isset( $this->init ) )
            $this->init = $this->start[$this->depth];
        $this->depth++;
    }

    private function ms( $start )
    {
        $ms = ( microtime( true ) - $start ) * 1000;
        $ms = $ms > 100 ? round( $ms ) : $ms;
        $ms = sprintf( $ms > 10 ? ( $ms > 100 ? '%.00f' : '%.01f' ) : '%.02f', $ms );
        return $ms;
    }

    public function test( $cond )
    {
        $this->depth--;
        $ms = $this->ms( $this->start[$this->depth] );
        echo ( $cond ? 'SUCCESS: ' : '  ERROR: ' ) . $this->info[$this->depth] . ' (' . $ms . ' ms)' . PHP_EOL;
        $cond ? $this->successful++ : $this->failed++;
        return $cond;
    }

    public function finish()
    {
        $total = $this->successful + $this->failed;
        $ms = $this->ms( $this->init );
        echo "  TOTAL: {$this->successful}/$total ($ms ms)\n";
        sleep( 3 );

        if( $this->failed > 0 )
            exit( 1 );
    }

    public function error( $message )
    {
        echo "  ERROR: $message\n";
    }

    public function warning( $message )
    {
        echo "WARNING: $message\n";
    }
}

echo '   TEST: Fetcher @ PHP ' . PHP_VERSION . PHP_EOL;
$t = new tester();

$sleep = 1;
$nodes =
[
    'https://example.com',
    'https://testnode1.wavesnodes.com',
    'https://testnode2.wavesnodes.com',
    'https://testnode3.wavesnodes.com',
    'https://testnode4.wavesnodes.com',
];

$t->pretest( 'nodes' );
{
    $wkFaucet = Fetcher::hosts( $nodes )->setLogger( $t );
    $wkFaucet->fetch( '/blocks/height' );
    $result = $wkFaucet->fetchMulti( '/blocks/height' );
}
$t->test( false !== $wkFaucet->fetch( '/blocks/height' ) );

$t->finish();