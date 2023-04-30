<?php
use Wikimedia\ExcimerUI\Client\ExcimerClient;
use Wikimedia\TestingAccessWrapper;

if ( !class_exists( ExcimerProfiler::class ) ) {
	require_once __DIR__ . '/../.phan/internal_stubs/excimer.php';
}

/**
 * @covers Wikimedia\ExcimerUI\Client\ExcimerClient
 */
class ExcimerClientTest extends PHPUnit\Framework\TestCase {

	protected function tearDown(): void {
		ExcimerClient::resetForTest();
		parent::tearDown();
	}

	protected function makeStubProfiler() {
		$log = $this->createStub( ExcimerLog::class );
		$log->method( 'getSpeedscopeData' )->willReturn( [] );

		$excimer = $this->createStub( ExcimerProfiler::class );
		$excimer->method( 'getLog' )->willReturn( $log );

		return $excimer;
	}

	protected function createClient( $config ): ExcimerClient {
		$client = ExcimerClient::setup( $config );
		$clientWrapped = TestingAccessWrapper::newFromObject( $client );
		$clientWrapped->excimer = $this->makeStubProfiler();

		return $client;
	}

	public function testSendWithoutUrl() {
		$client = $this->createClient( [] );

		$this->expectException( RuntimeException::class );
		$client->shutdown();
	}

	public function testSendUnreachableUrl() {
		$client = $this->createClient( [
			'url' => 'https://example.test',
			'errorCallback' => static function ( $msg ) {
				throw new RuntimeException( $msg );
			},
		] );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Could not resolve host' );
		$client->shutdown();
	}

	public function testGetUrl() {
		$client = $this->createClient( [
			'url' => 'https://example.test',
			'errorCallback' => static function ( $msg ) {
				throw new RuntimeException( $msg );
			},
		] );
		$client->setId( '1234' );

		$this->assertSame( 'https://example.test/profile/d404559f602eab6f', $client->getUrl() );
	}

	public function testSendSuccess() {
		$debug = null;
		$error = null;
		$client = $this->getMockBuilder( ExcimerClient::class )
			->setConstructorArgs( [ [
				'url' => 'https://example.test',
				'debugCallback' => static function ( $msg ) use ( &$debug ) {
					$debug = $msg;
				},
				'errorCallback' => static function ( $msg ) use ( &$error ) {
					$error = $msg;
				},
			] ] )
			->onlyMethods( [ 'httpRequest' ] )
			->getMock();
		$client->setId( '0000000' );
		$client->activate();
		$client->expects( $this->once() )
			->method( 'httpRequest' )
			->with( 'https://example.test/ingest/e2b0c412c3793f60', $this->anything() )
			->willReturn( [
				'result' => '',
				'code' => 202,
				'error' => null,
			] );

		TestingAccessWrapper::newFromObject( $client )
			->excimer = $this->makeStubProfiler();

		$client->shutdown();
		$this->assertStringContainsString(
			'Server returned response code 202. Total request time:',
			$debug
		);
		$this->assertSame( null, $error );
	}

	public function testSendError() {
		$debug = null;
		$error = null;
		$client = $this->getMockBuilder( ExcimerClient::class )
			->setConstructorArgs( [ [
				'url' => 'https://example.test',
				'debugCallback' => static function ( $msg ) use ( &$debug ) {
					$debug = $msg;
				},
				'errorCallback' => static function ( $msg ) use ( &$error ) {
					$error = $msg;
				},
			] ] )
			->onlyMethods( [ 'httpRequest' ] )
			->getMock();
		$client->setId( '0000000' );
		$client->activate();
		$client->expects( $this->once() )
			->method( 'httpRequest' )
			->with( 'https://example.test/ingest/e2b0c412c3793f60', $this->anything() )
			->willReturn( [
				'result' => '
<!DOCTYPE html>
<html lang="en">
<body>
<h1>Excimer UI Error 404</h1>
<p>
Not enough cowbell.
</p>
</body>
</html>',
				'code' => 404,
				'error' => null,
			] );

		TestingAccessWrapper::newFromObject( $client )
			->excimer = $this->makeStubProfiler();

		$client->shutdown();
		$this->assertStringContainsString(
			'Server returned response code 404. Total request time:',
			$debug
		);
		$this->assertSame( 'ExcimerUI server error 404: Not enough cowbell.', $error );
	}
}
