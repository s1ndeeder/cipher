<?php
/**
 * Cipher Restore Logger — Diagnostic instrumentation for restore pipeline
 * Writes JSON-lines log to wp-content/ai1wm-backups/.cipher-restore-<timestamp>.log
 */

class Cipher_Restore_Logger {

	private static $instance  = null;
	private static $log_file  = null;
	private static $handle    = null;
	private static $start_ts  = null;
	private static $stage_ts  = array();

	public static function init( $context = 'restore' ) {
		if ( self::$instance !== null ) {
			return self::$instance;
		}
		self::$instance = true;
		self::$start_ts = microtime( true );

		$ts             = date( 'Ymd-His' );
		self::$log_file = AI1WM_BACKUPS_PATH . DIRECTORY_SEPARATOR . '.cipher-' . $context . '-' . $ts . '.log';
		self::$handle   = @fopen( self::$log_file, 'a' );

		register_shutdown_function( array( __CLASS__, 'shutdown_handler' ) );
		set_error_handler( array( __CLASS__, 'error_handler' ) );

		self::log( 'logger.init', array(
			'log_file'    => self::$log_file,
			'php_version' => PHP_VERSION,
			'memory_lim'  => ini_get( 'memory_limit' ),
			'max_exec'    => ini_get( 'max_execution_time' ),
			'sapi'        => php_sapi_name(),
			'pid'         => getmypid(),
		) );

		return self::$instance;
	}

	public static function log( $event, $data = array() ) {
		if ( ! self::$handle ) {
			return;
		}
		$line = array(
			't'      => round( microtime( true ) - self::$start_ts, 4 ),
			'mem_mb' => round( memory_get_usage( true ) / 1048576, 2 ),
			'peak_mb'=> round( memory_get_peak_usage( true ) / 1048576, 2 ),
			'event'  => $event,
			'data'   => $data,
		);
		@fwrite( self::$handle, json_encode( $line ) . PHP_EOL );
		@fflush( self::$handle );
	}

	public static function preflight( $archive_path ) {
		$exists = is_file( $archive_path );
		$data   = array( 'path' => $archive_path, 'exists' => $exists );

		if ( $exists ) {
			$size       = filesize( $archive_path );
			$data['size_bytes'] = $size;
			$data['size_human'] = round( $size / 1048576, 2 ) . ' MB';
			$data['perms']      = substr( sprintf( '%o', fileperms( $archive_path ) ), -4 );

			$fh = @fopen( $archive_path, 'rb' );
			if ( $fh ) {
				$head = fread( $fh, 4096 );
				fseek( $fh, -4096, SEEK_END );
				$tail = fread( $fh, 4096 );
				fclose( $fh );
				$data['md5_head_4k'] = md5( $head );
				$data['md5_tail_4k'] = md5( $tail );
				$data['head_hex_32'] = bin2hex( substr( $head, 0, 32 ) );
				$data['tail_hex_32'] = bin2hex( substr( $tail, -32 ) );
			}
		}

		$data['disk_free_mb']    = round( @disk_free_space( dirname( $archive_path ) ) / 1048576, 2 );
		$data['memory_get_mb']   = round( memory_get_usage( true ) / 1048576, 2 );

		self::log( 'preflight', $data );
		return $exists;
	}

	public static function stage_before( $stage ) {
		self::$stage_ts[ $stage ] = microtime( true );
		self::log( 'stage.before', array( 'stage' => $stage ) );
	}

	public static function stage_after( $stage, $params = null ) {
		$elapsed = isset( self::$stage_ts[ $stage ] )
			? round( microtime( true ) - self::$stage_ts[ $stage ], 4 )
			: null;
		$data = array( 'stage' => $stage, 'elapsed_s' => $elapsed );
		if ( is_array( $params ) ) {
			$data['param_keys'] = array_keys( $params );
		}
		self::log( 'stage.after', $data );
	}

	public static function error_handler( $errno, $errstr, $errfile, $errline ) {
		self::log( 'php.error', array(
			'errno' => $errno,
			'msg'   => $errstr,
			'file'  => $errfile,
			'line'  => $errline,
		) );
		return false;
	}

	public static function shutdown_handler() {
		$err = error_get_last();
		if ( $err && in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			self::log( 'php.fatal', $err );
		}
		self::log( 'logger.shutdown', array(
			'total_elapsed_s' => round( microtime( true ) - self::$start_ts, 4 ),
			'peak_mem_mb'     => round( memory_get_peak_usage( true ) / 1048576, 2 ),
		) );
		if ( self::$handle ) {
			@fclose( self::$handle );
		}
	}

	public static function get_log_file() {
		return self::$log_file;
	}
}
