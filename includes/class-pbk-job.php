<?php
/**
 * File-based job state. Lives outside the database on purpose: a restore replaces the database
 * (and the user's session) mid-way, so jobs are authorized by a random token, not by cookies.
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped, Squiz.PHP.DiscouragedFunctions -- job state files, flock() and set_time_limit() for long-running jobs; messages are escaped where displayed.

class PBK_Job {
	public $id;
	public $data;

	/** type => handler class with static step( PBK_Job, float $deadline ) and on_error( PBK_Job ). */
	public static $handlers = array(
		'import' => 'PBK_Importer',
		'export' => 'PBK_Exporter',
	);

	public static function create( $type, array $state ) {
		$job       = new self();
		$job->id   = bin2hex( random_bytes( 8 ) );
		$job->data = array(
			'type'     => $type,
			'token'    => bin2hex( random_bytes( 16 ) ),
			'created'  => time(),
			'status'   => 'running',
			'message'  => '',
			'progress' => 0,
			'need'     => null,
			'log'      => array(),
			'state'    => $state,
		);
		if ( ! wp_mkdir_p( $job->dir() ) ) {
			throw new PBK_Exception( 'Could not create the job folder.' );
		}
		$job->save();
		return $job;
	}

	public static function load( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{16}$/', $id ) ) {
			return null;
		}
		$file = self::root() . "/$id/job.php";
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = json_decode( substr( file_get_contents( $file ), strlen( self::GUARD ) ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$job       = new self();
		$job->id   = $id;
		$job->data = $data;
		return $job;
	}

	/** Loads a job and checks its token (constant-time). */
	public static function authorize( $id, $token ) {
		$job = self::load( $id );
		return ( $job && is_string( $token ) && hash_equals( $job->data['token'], $token ) ) ? $job : null;
	}

	const GUARD = "<?php exit; ?>\n"; // job files hold secrets (token, password): never servable as text

	public static function root() {
		return PBK_Storage::dir() . '/jobs';
	}

	public function dir() {
		return self::root() . '/' . $this->id;
	}

	public function save() {
		$this->data['updated'] = time();
		$tmp = $this->dir() . '/job.php.tmp';
		if ( file_put_contents( $tmp, self::GUARD . wp_json_encode( $this->data ) ) === false || ! rename( $tmp, $this->dir() . '/job.php' ) ) {
			throw new PBK_Exception( 'Could not save job state (disk full?).' );
		}
	}

	public function delete() {
		PBK_Storage::rrmdir( $this->dir() );
	}

	public function &state() {
		return $this->data['state'];
	}

	public function status( $message, $progress = null ) {
		$this->data['message'] = $message;
		if ( $progress !== null ) {
			$this->data['progress'] = max( 0, min( 100, (int) $progress ) );
		}
	}

	public function log( $message ) {
		$this->data['log'][] = gmdate( 'H:i:s' ) . ' ' . $message;
		$this->data['log']   = array_slice( $this->data['log'], -200 );
	}

	/** Pauses the job until the user answers ($need = password|confirm). */
	public function ask( $need, $message ) {
		$this->data['status']  = 'input';
		$this->data['need']    = $need;
		$this->data['message'] = $message;
	}

	public function finish( $message ) {
		$this->data['status']   = 'done';
		$this->data['progress'] = 100;
		$this->data['message']  = $message;
		$this->log( $message );
	}

	public function fail( $message ) {
		$this->data['status']  = 'error';
		$this->data['message'] = $message;
		$this->log( 'ERROR: ' . $message );
	}

	public function is_active() {
		return $this->data['status'] === 'running';
	}

	public function public_status() {
		return array(
			'id'       => $this->id,
			'type'     => $this->data['type'],
			'status'   => $this->data['status'],
			'message'  => $this->data['message'],
			'progress' => $this->data['progress'],
			'need'     => $this->data['need'],
			'log'      => array_slice( $this->data['log'], -30 ),
			'result'   => isset( $this->data['result'] ) ? $this->data['result'] : null,
		);
	}

	/**
	 * Runs the job for up to $seconds (INF = until finished, for WP-CLI / cron).
	 * Only one request works on a job at a time (flock); others just get the status.
	 */
	public function run( $seconds ) {
		$lock = @fopen( $this->dir() . '/lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			return $this->public_status();
		}
		$fresh = self::load( $this->id ); // another request may have advanced it while we waited
		if ( $fresh ) {
			$this->data = $fresh->data;
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( is_finite( $seconds ) ? (int) $seconds + 60 : 0 );
		}
		$deadline = microtime( true ) + $seconds;
		$handler  = self::$handlers[ $this->data['type'] ];
		try {
			while ( $this->is_active() ) {
				$handler::step( $this, $deadline );
				$this->save();
				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}
		} catch ( Throwable $e ) {
			$this->fail( $e->getMessage() );
			try {
				$handler::on_error( $this );
			} catch ( Throwable $ignored ) {
				$this->log( 'Cleanup failed: ' . $ignored->getMessage() );
			}
			$this->save();
		}
		flock( $lock, LOCK_UN );
		fclose( $lock );
		return $this->public_status();
	}

	/** Seconds a single web request may work (stays well under typical 30 s limits). */
	public static function request_budget() {
		$max = (int) ini_get( 'max_execution_time' );
		$budget = $max > 0 ? min( 20, max( 5, $max - 10 ) ) : 20;
		return (float) apply_filters( 'pbk_request_budget', $budget );
	}

	/** Deletes finished/abandoned job folders older than a day. */
	public static function cleanup( $max_age = DAY_IN_SECONDS ) {
		foreach ( (array) glob( self::root() . '/*', GLOB_ONLYDIR ) as $dir ) {
			$job = self::load( basename( $dir ) );
			$age = time() - ( $job ? (int) $job->data['updated'] : (int) filemtime( $dir ) );
			if ( $age > $max_age ) {
				PBK_Storage::rrmdir( $dir );
			}
		}
	}
}
