<?php
/**
 * Job — durable state for a chunked export/import operation.
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migrator_Job {

	const TYPE_EXPORT = 'export';
	const TYPE_IMPORT = 'import';

	public string $id;
	public string $type;
	public int $user_id;
	public string $created_at;
	public Migrator_Profile $profile;
	public array $state;

	private function __construct() {
		$this->state = array(
			'phase'            => 'init',
			'phase_progress'   => 0.0,
			'overall_progress' => 0.0,
			'label'            => '',
			'data'             => array(),
		);
	}

	public static function create( string $type, Migrator_Profile $profile ): self {
		$job             = new self();
		$job->id         = self::generate_id();
		$job->type       = $type;
		$job->user_id    = get_current_user_id();
		$job->created_at = gmdate( 'c' );
		$job->profile    = $profile;

		if ( ! wp_mkdir_p( $job->dir() ) ) {
			throw new RuntimeException( 'Could not create job directory.' );
		}

		$job->save();
		return $job;
	}

	public static function load( string $id ): ?self {
		$id = self::sanitize_id( $id );
		if ( '' === $id ) {
			return null;
		}

		$state_file = self::base_dir() . '/job-' . $id . '/state.json';
		if ( ! file_exists( $state_file ) ) {
			return null;
		}

		$raw = file_get_contents( $state_file );
		$arr = json_decode( $raw, true );
		if ( ! is_array( $arr ) ) {
			return null;
		}

		$job             = new self();
		$job->id         = $id;
		$job->type       = (string) ( $arr['type'] ?? '' );
		$job->user_id    = (int) ( $arr['user_id'] ?? 0 );
		$job->created_at = (string) ( $arr['created_at'] ?? '' );
		$job->profile    = Migrator_Profile::from_array( (array) ( $arr['profile'] ?? array() ) );
		$job->state      = (array) ( $arr['state'] ?? $job->state );

		return $job;
	}

	public function save(): void {
		$payload = array(
			'id'         => $this->id,
			'type'       => $this->type,
			'user_id'    => $this->user_id,
			'created_at' => $this->created_at,
			'profile'    => $this->profile->to_array(),
			'state'      => $this->state,
		);
		file_put_contents( $this->dir() . '/state.json', wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
	}

	public function destroy(): void {
		$dir = $this->dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $dir );
	}

	public function dir(): string {
		return self::base_dir() . '/job-' . $this->id;
	}

	public function archive_path(): string {
		$file = ( self::TYPE_EXPORT === $this->type ) ? 'output.zip' : 'upload.zip';
		return $this->dir() . '/' . $file;
	}

	public function sql_path(): string {
		return $this->dir() . '/database.sql';
	}

	public function files_list_path(): string {
		return $this->dir() . '/files.txt';
	}

	public function owned_by_current_user(): bool {
		return $this->user_id === get_current_user_id();
	}

	public function set_phase( string $phase, array $data = array() ): void {
		$this->state['phase'] = $phase;
		$this->state['data']  = $data;
	}

	public function update_progress( float $overall, string $label = '', float $phase_progress = 0.0 ): void {
		$this->state['overall_progress'] = max( 0.0, min( 1.0, $overall ) );
		$this->state['phase_progress']   = max( 0.0, min( 1.0, $phase_progress ) );
		if ( '' !== $label ) {
			$this->state['label'] = $label;
		}
	}

	public function snapshot(): array {
		return array(
			'job_id'           => $this->id,
			'phase'            => $this->state['phase'],
			'phase_progress'   => $this->state['phase_progress'],
			'overall_progress' => $this->state['overall_progress'],
			'label'            => $this->state['label'],
		);
	}

	public static function base_dir(): string {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'migrator';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}
		return $dir;
	}

	private static function generate_id(): string {
		return strtolower( wp_generate_password( 16, false, false ) );
	}

	private static function sanitize_id( string $id ): string {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( $id ) ) ?: '';
	}
}
