<?php

use \WP_CLI\Utils;
use \WP_CLI\Dispatcher;
use \WP_CLI\FileCache;
use \WP_CLI\WpHttpCacheManager;

/**
 * Various utilities for WP-CLI commands.
 */
class WP_CLI {

	private static $configurator;

	private static $logger;

	private static $hooks = array(), $hooks_passed = array();

	/**
	 * Set the logger instance.
	 *
	 * @param object $logger
	 */
	static function set_logger( $logger ) {
		self::$logger = $logger;
	}

	static function get_configurator() {
		static $configurator;

		if ( !$configurator ) {
			$configurator = new WP_CLI\Configurator( WP_CLI_ROOT . '/php/config-spec.php' );
		}

		return $configurator;
	}

	static function get_root_command() {
		static $root;

		if ( !$root ) {
			$root = new Dispatcher\RootCommand;
		}

		return $root;
	}

	static function get_runner() {
		static $runner;

		if ( !$runner ) {
			$runner = new WP_CLI\Runner;
		}

		return $runner;
	}

	/**
	 * @return FileCache
	 */
	public static function get_cache() {
		static $cache;

		if ( !$cache ) {
			$home = getenv( 'HOME' );
			if ( !$home ) {
				// sometime in windows $HOME is not defined
				$home = getenv( 'HOMEDRIVE' ) . '/' . getenv( 'HOMEPATH' );
			}
			$dir = getenv( 'WP_CLI_CACHE_DIR' ) ? : "$home/.wp-cli/cache";

			// 6 months, 300mb
			$cache = new FileCache( $dir, 15552000, 314572800 );

			// clean older files on shutdown with 1/50 probability
			if ( 0 === mt_rand( 0, 50 ) ) {
				register_shutdown_function( function () use ( $cache ) {
					$cache->clean();
				} );
			}
		}

		return $cache;
	}

	/**
	 * Set the context in which WP-CLI should be run
	 */
	static function set_url( $url ) {
		$url_parts = Utils\parse_url( $url );
		self::set_url_params( $url_parts );
	}

	private static function set_url_params( $url_parts ) {
		$f = function( $key ) use ( $url_parts ) {
			return isset( $url_parts[ $key ] ) ? $url_parts[ $key ] : '';
		};

		if ( isset( $url_parts['host'] ) ) {
			$_SERVER['HTTP_HOST'] = $url_parts['host'];
			if ( isset( $url_parts['port'] ) ) {
				$_SERVER['HTTP_HOST'] .= ':' . $url_parts['port'];
			}

			$_SERVER['SERVER_NAME'] = $url_parts['host'];
		}

		$_SERVER['REQUEST_URI'] = $f('path') . ( isset( $url_parts['query'] ) ? '?' . $url_parts['query'] : '' );
		$_SERVER['SERVER_PORT'] = isset( $url_parts['port'] ) ? $url_parts['port'] : '80';
		$_SERVER['QUERY_STRING'] = $f('query');
	}

	/**
	 * @return WpHttpCacheManager
	 */
	static function get_http_cache_manager() {
		static $http_cacher;

		if ( !$http_cacher ) {
			$http_cacher = new WpHttpCacheManager( self::get_cache() );
		}

		return $http_cacher;
	}

	static function colorize( $string ) {
		return \cli\Colors::colorize( $string, self::get_runner()->in_color() );
	}

	/**
	 * Schedule a callback to be executed at a certain point (before WP is loaded).
	 */
	static function add_hook( $when, $callback ) {
		if ( in_array( $when, self::$hooks_passed ) )
			call_user_func( $callback );

		self::$hooks[ $when ][] = $callback;
	}

	/**
	 * Execute registered callbacks.
	 */
	static function do_hook( $when ) {
		self::$hooks_passed[] = $when;

		if ( !isset( self::$hooks[ $when ] ) )
			return;

		array_map( 'call_user_func', self::$hooks[ $when ] );
	}

	/**
	 * Add a command to the wp-cli list of commands
	 *
	 * @param string $name The name of the command that will be used in the cli
	 * @param string $class The command implementation
	 * @param array $args An associative array with additional parameters:
	 *   'before_invoke' => callback to execute before invoking the command
	 */
	static function add_command( $name, $class, $args = array() ) {
		$command = Dispatcher\CommandFactory::create( $name, $class, self::get_root_command() );

		if ( isset( $args['before_invoke'] ) ) {
			self::add_hook( "before_invoke:$name", $args['before_invoke'] );
		}

		self::get_root_command()->add_subcommand( $name, $command );
	}

	/**
	 * Display a message in the CLI and end with a newline
	 *
	 * @param string $message
	 */
	static function line( $message = '' ) {
		echo $message . "\n";
	}

	/**
	 * Log an informational message.
	 *
	 * @param string $message
	 */
	static function log( $message ) {
		self::$logger->info( $message );
	}

	/**
	 * Display a success in the CLI and end with a newline
	 *
	 * @param string $message
	 */
	static function success( $message ) {
		self::$logger->success( $message );
	}

	/**
	 * Display a warning in the CLI and end with a newline
	 *
	 * @param string $message
	 */
	static function warning( $message ) {
		self::$logger->warning( self::error_to_string( $message ) );
	}

	/**
	 * Display an error in the CLI and end with a newline
	 *
	 * @param string $message
	 */
	static function error( $message ) {
		if ( ! isset( self::get_runner()->assoc_args[ 'completions' ] ) ) {
			self::$logger->error( self::error_to_string( $message ) );
		}

		exit(1);
	}

	/**
	 * Ask for confirmation before running a destructive operation.
	 */
	static function confirm( $question, $assoc_args = array() ) {
		if ( !isset( $assoc_args['yes'] ) ) {
			fwrite( STDOUT, $question . " [y/n] " );

			$answer = trim( fgets( STDIN ) );

			if ( 'y' != $answer )
				exit;
		}
	}

	/**
	 * Read value from a positional argument or from STDIN.
	 *
	 * @param array $args The list of positional arguments.
	 * @param int $index At which position to check for the value.
	 *
	 * @return string
	 */
	public static function get_value_from_arg_or_stdin( $args, $index ) {
		if ( isset( $args[ $index ] ) ) {
			$raw_value = $args[ $index ];
		} else {
			// We don't use file_get_contents() here because it doesn't handle
			// Ctrl-D properly, when typing in the value interactively.
			$raw_value = '';
			while ( ( $line = fgets( STDIN ) ) !== false ) {
				$raw_value .= $line;
			}
		}

		return $raw_value;
	}

	/**
	 * Read a value, from various formats.
	 *
	 * @param mixed $value
	 * @param array $assoc_args
	 */
	static function read_value( $raw_value, $assoc_args = array() ) {
		if ( isset( $assoc_args['format'] ) && 'json' == $assoc_args['format'] ) {
			$value = json_decode( $raw_value, true );
			if ( null === $value ) {
				WP_CLI::error( sprintf( 'Invalid JSON: %s', $raw_value ) );
			}
		} else {
			$value = $raw_value;
		}

		return $value;
	}

	/**
	 * Display a value, in various formats
	 *
	 * @param mixed $value
	 * @param array $assoc_args
	 */
	static function print_value( $value, $assoc_args = array() ) {
		if ( isset( $assoc_args['format'] ) && 'json' == $assoc_args['format'] ) {
			$value = json_encode( $value );
		} elseif ( is_array( $value ) || is_object( $value ) ) {
			$value = var_export( $value );
		}

		echo $value . "\n";
	}

	/**
	 * Convert a wp_error into a string
	 *
	 * @param mixed $errors
	 * @return string
	 */
	static function error_to_string( $errors ) {
		if ( is_string( $errors ) ) {
			return $errors;
		}

		if ( is_object( $errors ) && is_a( $errors, 'WP_Error' ) ) {
			foreach ( $errors->get_error_messages() as $message ) {
				if ( $errors->get_error_data() )
					return $message . ' ' . $errors->get_error_data();
				else
					return $message;
			}
		}
	}

	/**
	 * Launch an external process that takes over I/O.
	 *
	 * @param string Command to call
	 * @param bool Whether to exit if the command returns an error status
	 *
	 * @return int The command exit status
	 */
	static function launch( $command, $exit_on_error = true ) {
		$r = proc_close( proc_open( $command, array( STDIN, STDOUT, STDERR ), $pipes ) );

		if ( $r && $exit_on_error )
			exit($r);

		return $r;
	}

	/**
	 * Launch another WP-CLI command using the runtime arguments for the current process
	 *
	 * @param string Command to call
	 * @param array $args Positional arguments to use
	 * @param array $assoc_args Associative arguments to use
	 * @param bool Whether to exit if the command returns an error status
	 *
	 * @return int The command exit status
	 */
	static function launch_self( $command, $args = array(), $assoc_args = array(), $exit_on_error = true ) {
		$reused_runtime_args = array(
			'path',
			'url',
			'user',
		);

		foreach ( $reused_runtime_args as $key ) {
			if ( $value = self::get_runner()->config[ $key ] )
				$assoc_args[ $key ] = $value;
		}

		$php_bin = self::get_php_binary();

		$script_path = $GLOBALS['argv'][0];

		$args = implode( ' ', array_map( 'escapeshellarg', $args ) );
		$assoc_args = \WP_CLI\Utils\assoc_args_to_str( $assoc_args );

		$full_command = "{$php_bin} {$script_path} {$command} {$args} {$assoc_args}";

		return self::launch( $full_command, $exit_on_error );
	}

	private static function get_php_binary() {
		if ( defined( 'PHP_BINARY' ) )
			return PHP_BINARY;

		if ( getenv( 'WP_CLI_PHP_USED' ) )
			return getenv( 'WP_CLI_PHP_USED' );

		if ( getenv( 'WP_CLI_PHP' ) )
			return getenv( 'WP_CLI_PHP' );

		return 'php';
	}

	static function get_config( $key = null ) {
		if ( null === $key ) {
			return self::get_runner()->config;
		}

		if ( !isset( self::get_runner()->config[ $key ] ) ) {
			self::warning( "Unknown config option '$key'." );
			return null;
		}

		return self::get_runner()->config[ $key ];
	}

	/**
	 * Run a given command.
	 *
	 * @param array
	 * @param array
	 */
	static function run_command( $args, $assoc_args = array() ) {
		self::get_runner()->run_command( $args, $assoc_args );
	}



	// DEPRECATED STUFF

	static function add_man_dir() {
		trigger_error( 'WP_CLI::add_man_dir() is deprecated. Add docs inline.', E_USER_WARNING );
	}

	// back-compat
	static function out( $str ) {
		fwrite( STDOUT, $str );
	}

	// back-compat
	static function addCommand( $name, $class ) {
		trigger_error( sprintf( 'wp %s: %s is deprecated. use WP_CLI::add_command() instead.',
			$name, __FUNCTION__ ), E_USER_WARNING );
		self::add_command( $name, $class );
	}
}

