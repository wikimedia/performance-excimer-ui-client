<?php

namespace Wikimedia\ExcimerUI\Client;

use ExcimerProfiler;

class ExcimerClient {
	private const DEFAULT_CONFIG = [
		'url' => null,
		'ingestionUrl' => null,
		'activate' => 'always',
		'period' => 0.001,
		'timeout' => 1,
		'debugCallback' => null,
		'errorCallback' => null,
	];

	/** @var ExcimerClient */
	private static $instance;

	/** @var bool */
	private $activated = false;

	/** @var array */
	private $config;

	/** @var ExcimerProfiler|null */
	private $excimer;

	/** @var string|null */
	private $id;

	/**
	 * Initialise the profiler. This should be called as early as possible.
	 *
	 * @param array $config Associative array with the following keys:
	 *   - url: The URL for the ExcimerUI server's index.php. It can be reached
	 *     via an alias, it doesn't have to have "index.php" in it.
	 *   - ingestionUrl: The URL to post data to, if different from $config['url']
	 *     which would then be used for public link only.
	 *   - activate: Can be one of:
	 *     - "always" to always activate the profiler when setup() is called
	 *       (the default)
	 *     - "manual" to never activate on setup(). $profiler->activate() should
	 *       be called later.
	 *     - "query" to activate when the excimer_profile query string
	 *       parameter is passed
	 *   - period: The sampling period in seconds (default 0.001)
	 *   - timeout: The request timeout for ingestion requests
	 *   - debugCallback: A function to call back with debug messages. It takes
	 *     a single parameter which is the message string.
	 *   - errorCallback: A function to call back with error messages. It takes
	 *     a single parameter which is the message string. This is called on
	 *     request shutdown, so it is not feasible to show the message to the
	 *     user.
	 * @return self
	 */
	public static function setup( $config ) {
		if ( self::$instance ) {
			throw new \LogicException( 'setup() can only be called once' );
		}
		self::$instance = new self( $config );
		self::$instance->maybeActivate();
		return self::$instance;
	}

	/**
	 * Get the instance previously created with self::setup()
	 *
	 * @return self
	 */
	public static function singleton(): self {
		if ( !self::$instance ) {
			throw new \LogicException( 'setup() must be called before singleton()' );
		}
		return self::$instance;
	}

	/**
	 * Return true if setup() has been called and the instance is activated
	 * (i.e. the profiler is running).
	 *
	 * @return bool
	 */
	public static function isActive(): bool {
		return self::$instance && self::$instance->activated;
	}

	/**
	 * Private constructor
	 *
	 * @param array $config
	 */
	private function __construct( $config ) {
		$this->config = $config + self::DEFAULT_CONFIG;
	}

	/**
	 * Start the profiler and register a shutdown function which will post
	 * the results to the server.
	 */
	public function activate() {
		$this->activated = true;
		$this->excimer = new ExcimerProfiler;
		$this->excimer->setPeriod( $this->config['period'] );
		$this->excimer->setEventType( EXCIMER_REAL );
		$this->excimer->start();
		register_shutdown_function( function () {
			$this->shutdown();
		} );
	}

	/**
	 * Shut down the profiler and send the results. This is normally called at
	 * the end of the request, but can be called manually.
	 */
	public function shutdown() {
		if ( !$this->excimer ) {
			return;
		}
		$this->excimer->stop();
		$this->sendReport();
		$this->excimer = null;
	}

	/**
	 * Make a link to the profile for the current request, and any other
	 * requests which had the same ID set with setId().
	 *
	 * @param array $options
	 *   - text: The link text
	 *   - class: A string or array of strings which will be encoded and added
	 *     to the anchor class attribute.
	 * @return string
	 */
	public function makeLink( $options = [] ) {
		$text = $options['text'] ?? 'Performance profile';
		$link = '<a href="' . htmlspecialchars( $this->getUrl() ) . '" ';
		if ( isset( $options['class'] ) ) {
			$class = $options['class'];
			if ( is_array( $class ) ) {
				$class = implode( ' ', $class );
			}
			$link .= 'class="' . htmlspecialchars( $class ) . '" ';
		}
		$link .= '>' . htmlspecialchars( $text, ENT_NOQUOTES ) . '</a>';
		return $link;
	}

	/**
	 * Get the URL which will show the profiling results
	 *
	 * @return string
	 */
	public function getUrl() {
		$url = $this->config['url'] ?? null;
		if ( $url === null ) {
			throw new \RuntimeException( "No ingestion URL configured" );
		}
		$url = rtrim( $url, '/' );
		return "$url/profile/" . rawurlencode( $this->getId() );
	}

	/**
	 * Maybe activate the profiler, depending on config and request parameters.
	 */
	private function maybeActivate() {
		switch ( $this->config['activate'] ) {
			case 'always':
				self::activate();
				break;
			case 'manual':
				break;
			case 'query':
				if ( isset( $_GET['excimer_id'] ) ) {
					$this->setId( $_GET['excimer_id'] );
				}
				if ( isset( $_GET['excimer_profile'] ) ) {
					self::activate();
				}
				break;
			default:
				throw new \InvalidArgumentException( 'Unknown activation type' );
		}
	}

	/**
	 * Get the URL to post the profile to.
	 *
	 * @param string $id
	 * @return string
	 */
	private function getIngestionUrl( $id ) {
		$url = $this->config['ingestionUrl'] ?? $this->config['url'] ?? null;
		if ( $url === null ) {
			throw new \RuntimeException( "No ingestion URL configured" );
		}
		return rtrim( $url, '/' ) . '/ingest/' . rawurlencode( $id );
	}

	/**
	 * Set the ID which identifies the request. If multiple requests are
	 * profiled with the same ID, they will be merged and shown together
	 * in the UI.
	 *
	 * @param string $id
	 * @return void
	 */
	public function setId( string $id ) {
		$this->id = $id;
	}

	/**
	 * Get the ID, or generate a random ID.
	 *
	 * @return string
	 */
	private function getId() {
		if ( $this->id === null ) {
			$this->id = sprintf(
				"%07x%07x%07x",
				mt_rand() & 0xfffffff,
				mt_rand() & 0xfffffff,
				mt_rand() & 0xfffffff
			);
		}
		return $this->id;
	}

	/**
	 * Encode an array as JSON
	 *
	 * @param array $data
	 * @return string
	 * @throws \JsonException
	 */
	private function encode( $data ) {
		return json_encode(
			$data,
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Get the profile name from the request data.
	 *
	 * @return mixed|string
	 */
	private function getRequestName() {
		if ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' ) {
			return implode( ' ', $GLOBALS['argv'] );
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			return $_SERVER['REQUEST_URI'];
		} else {
			return '';
		}
	}

	/**
	 * Get an array of JSON-serializable request information to be attached to
	 * the profile.
	 *
	 * @return array
	 */
	private function getRequestInfo() {
		$info = [];
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$info['url'] = $_SERVER['REQUEST_URI'];
		}
		return $info;
	}

	/**
	 * Send the profile result to the server
	 */
	private function sendReport() {
		$log = $this->excimer->getLog();
		$speedscope = $log->getSpeedscopeData();
		$name = $this->getRequestName();
		$speedscope['profiles'][0]['name'] = $name;
		$data = [
			'name' => $name,
			'request' => $this->encode( $this->getRequestInfo() ),
			'period' => $this->config['period'],
			'speedscope_deflated' => gzdeflate(
				json_encode(
					$speedscope,
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			),
		];
		$t = -microtime( true );
		$ch = curl_init( $this->getIngestionUrl( $this->getId() ) );
		curl_setopt_array( $ch, [
			CURLOPT_POSTFIELDS => $data,
			CURLOPT_USERAGENT => 'ExcimerUI',
			CURLOPT_TIMEOUT_MS => $this->config['timeout'] * 1000,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FAILONERROR => true
		] );
		$result = curl_exec( $ch );
		$t += microtime( true );

		if ( $result === false
			&& $this->config['errorCallback']
		) {
			( $this->config['errorCallback'] )( 'ExcimerUI server error: ' . curl_error( $ch ) );
		}

		if ( $this->config['debugCallback'] ) {
			( $this->config['debugCallback'] )(
				'Server returned response code ' .
				curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ) .
				'. Total request time: ' .
				round( $t * 1000, 6 ) . ' ms.'
			);
		}
	}
}
