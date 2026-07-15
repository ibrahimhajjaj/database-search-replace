<?php
/**
 * Search and replace REST API.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Rest;

use InvalidArgumentException;
use RuntimeException;
use SafeSR\Db\Change;
use SafeSR\Db\Change_Log;
use SafeSR\Db\Run_Config;
use SafeSR\Db\Table_Scanner;
use SafeSR\Engine\Search_Config;
use SafeSR\Jobs\Job_Manager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers capability-protected routes consumed by the administration UI.
 */
class Rest_Controller {

	private const NAMESPACE = 'safesr/v1';

	/**
	 * Job storage service.
	 *
	 * @var Job_Manager
	 */
	private Job_Manager $manager;

	/**
	 * Table metadata service.
	 *
	 * @var Table_Scanner
	 */
	private Table_Scanner $scanner;

	/**
	 * Supports injected services for REST tests.
	 *
	 * @param Job_Manager|null   $manager Optional job service.
	 * @param Table_Scanner|null $scanner Optional table scanner.
	 */
	public function __construct( ?Job_Manager $manager = null, ?Table_Scanner $scanner = null ) {
		$this->manager = $manager ?? new Job_Manager();
		$this->scanner = $scanner ?? new Table_Scanner();
	}

	/**
	 * Registers all plugin routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/tables',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tables' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_preview' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->run_args(),
			)
		);
		$apply_args                   = $this->run_args();
		$apply_args['preview_job_id'] = $this->id_arg();
		register_rest_route(
			self::NAMESPACE,
			'/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_apply' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $apply_args,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_jobs' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'type'     => array(
						'type'              => 'string',
						'default'           => 'apply',
						'enum'              => array( 'apply', 'history', 'all' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>[a-f0-9]{32})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>[a-f0-9]{32})/changes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_changes' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'       => $this->id_arg(),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'table'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'text'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		foreach ( array(
			'cancel' => 'cancel_job',
			'undo'   => 'undo_job',
		) as $route => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				'/jobs/(?P<id>[a-f0-9]{32})/' . $route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array( 'id' => $this->id_arg() ),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>[a-f0-9]{32})/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_log' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->settings_args(),
				),
			)
		);
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_csv' ), 10, 4 );
	}

	/**
	 * Requires the site administration capability for every route.
	 *
	 * Cookie-authenticated REST requests receive WordPress's standard nonce validation before
	 * this callback runs.
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to manage search and replace jobs.', 'database-search-replace' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * Returns searchable table metadata and safe defaults.
	 *
	 * @return WP_REST_Response
	 */
	public function get_tables(): WP_REST_Response {
		$tables   = $this->scanner->get_tables();
		$defaults = array();
		foreach ( $tables as &$table ) {
			$table['size']        = $table['data_size'];
			$table['processable'] = null !== $this->scanner->get_primary_key( $table['name'] ) && ! empty( $this->scanner->get_text_columns( $table['name'] ) );
			if ( $table['processable'] && ! $table['protected'] ) {
				$defaults[] = $table['name'];
			}
			unset( $table['data_size'] );
		}
		unset( $table );
		return new WP_REST_Response(
			array(
				'tables'   => $tables,
				'defaults' => $defaults,
			)
		);
	}

	/**
	 * Creates a preview job from validated request data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_preview( WP_REST_Request $request ) {
		try {
			$job_id = $this->manager->create_preview( $this->run_config( $request, true ) );
			return new WP_REST_Response( array( 'job_id' => $job_id ), 201 );
		} catch ( InvalidArgumentException | RuntimeException $error ) {
			return $this->bad_request( $error->getMessage() );
		}
	}

	/**
	 * Creates an apply job with optional preview linkage.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_apply( WP_REST_Request $request ) {
		try {
			$preview = (string) $request->get_param( 'preview_job_id' );
			$job_id  = $this->manager->create_apply( $this->run_config( $request, false ), (bool) $request['create_backup'], $preview );
			return new WP_REST_Response( array( 'job_id' => $job_id ), 201 );
		} catch ( InvalidArgumentException | RuntimeException $error ) {
			return $this->bad_request( $error->getMessage() );
		}
	}

	/**
	 * Returns current job state without advancing background work.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ) {
		$job = $this->manager->get_job( (string) $request['id'] );
		if ( null === $job ) {
			return $this->not_found();
		}
		return new WP_REST_Response( $this->public_job( $job ) );
	}

	/**
	 * Returns a page of stored job summaries.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_jobs( WP_REST_Request $request ): WP_REST_Response {
		$result          = $this->manager->get_jobs_page(
			(string) $request['type'],
			(int) $request['page'],
			(int) $request['per_page']
		);
		$latest_apply_id = $this->manager->latest_undoable_apply_id();
		$items           = array();
		foreach ( $result['items'] as $job ) {
			$items[] = $this->run_summary( $job, $latest_apply_id );
		}
		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => $result['total'],
			)
		);
	}

	/**
	 * Returns a filtered page of stored change excerpts.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_changes( WP_REST_Request $request ) {
		if ( null === $this->manager->get_job( (string) $request['id'] ) ) {
			return $this->not_found();
		}
		$result   = $this->manager->get_changes(
			(string) $request['id'],
			(int) $request['page'],
			(int) $request['per_page'],
			(string) $request['table'],
			(string) $request['text']
		);
		$response = new WP_REST_Response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, (int) $request['per_page'] ) ) );
		return $response;
	}

	/**
	 * Cancels a queued or running job.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_job( WP_REST_Request $request ) {
		$job = $this->manager->get_job( (string) $request['id'] );
		if ( null === $job ) {
			return $this->not_found();
		}
		if ( ! $this->manager->cancel( (string) $job['id'] ) ) {
			return $this->bad_request( __( 'Only a queued or running job can be canceled.', 'database-search-replace' ) );
		}
		return new WP_REST_Response( $this->public_job( $this->manager->get_job( (string) $job['id'] ) ) );
	}

	/**
	 * Creates an undo job for an eligible apply job.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo_job( WP_REST_Request $request ) {
		try {
			$job_id = $this->manager->create_undo( (string) $request['id'] );
			return new WP_REST_Response( array( 'job_id' => $job_id ), 201 );
		} catch ( InvalidArgumentException | RuntimeException $error ) {
			return $this->bad_request( $error->getMessage() );
		}
	}

	/**
	 * Returns a guarded CSV response for a completed job.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_log( WP_REST_Request $request ) {
		$job = $this->manager->get_job( (string) $request['id'] );
		if ( null === $job || empty( $job['summary']['log_available'] ) ) {
			return $this->not_found();
		}
		$csv      = $this->render_log( (string) $job['id'] );
		$response = new WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="database-search-replace-' . (string) $job['id'] . '.csv"' );
		return $response;
	}

	/**
	 * Renders stored change excerpts through the authorized download route.
	 *
	 * @param string $job_id Job identifier.
	 * @return string
	 */
	private function render_log( string $job_id ): string {
		$changes = array();
		$page    = 1;
		do {
			$result = $this->manager->get_changes( $job_id, $page, 100 );
			foreach ( $result['items'] as $row ) {
				$before    = $this->excerpt_text( $row['before_excerpt'] );
				$after     = $this->excerpt_text( $row['after_excerpt'] );
				$changes[] = new Change(
					(string) $row['table_name'],
					(string) $row['column_name'],
					(string) $row['row_pk'],
					$before,
					$after,
					array(
						'before'        => $row['before_excerpt'],
						'after'         => $row['after_excerpt'],
						'truncated'     => false,
						'before_length' => strlen( $before ),
						'after_length'  => strlen( $after ),
					),
					$row['formats'],
					(bool) $row['skipped'],
					(string) $row['skip_reason'],
					1
				);
			}
			++$page;
			$collected = count( $changes );
		} while ( $collected < $result['total'] );
		return ( new Change_Log() )->render( $job_id, $changes );
	}

	/**
	 * Flattens stored diff segments for CSV output.
	 *
	 * @param array<int,array<string,string>> $segments Diff segments.
	 * @return string
	 */
	private function excerpt_text( array $segments ): string {
		return implode( '', array_column( $segments, 't' ) );
	}

	/**
	 * Emits CSV bodies without JSON encoding during REST serving.
	 *
	 * @param bool             $served  Whether the response was already served.
	 * @param WP_REST_Response $result  REST response.
	 * @param WP_REST_Request  $request REST request.
	 * @param WP_REST_Server   $server  REST server.
	 * @return bool
	 */
	public function serve_csv( bool $served, $result, $request, $server ): bool {
		unset( $request, $server );
		$headers      = $result->get_headers();
		$content_type = isset( $headers['Content-Type'] ) ? (string) $headers['Content-Type'] : '';
		if ( $served || 0 !== strpos( $content_type, 'text/csv' ) ) {
			return $served;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw CSV response.
		echo (string) $result->get_data();
		return true;
	}

	/**
	 * Returns effective settings including mandatory protected tables.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'protected_tables' => $this->scanner->get_protected_tables(),
				'batch_size'       => get_option( 'safesr_batch_size', 'auto' ),
				'keep_logs'        => (bool) get_option( 'safesr_keep_logs', true ),
			)
		);
	}

	/**
	 * Saves validated settings while preserving required user table protection.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$protected = (array) $request['protected_tables'];
		$extras    = array_values( array_diff( $protected, array( $wpdb->users, $wpdb->usermeta ) ) );
		update_option( 'safesr_protected_tables', $extras, false );
		update_option( 'safesr_batch_size', $request['batch_size'], false );
		update_option( 'safesr_keep_logs', (bool) $request['keep_logs'] ? 1 : 0, false );
		return $this->get_settings();
	}

	/**
	 * Builds a validated run configuration from request values.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param bool            $dry_run Whether database writes are disabled.
	 * @return Run_Config
	 * @throws InvalidArgumentException When the search or table selection is invalid.
	 */
	private function run_config( WP_REST_Request $request, bool $dry_run ): Run_Config {
		$search = new Search_Config(
			(string) $request['search'],
			(string) $request['replace'],
			(bool) $request['case_sensitive'],
			(bool) $request['regex'],
			(array) $request['exclusions']
		);
		$tables = $this->scanner->filter_allowed( (array) $request['tables'] );
		if ( empty( $tables ) ) {
			throw new InvalidArgumentException( esc_html__( 'No searchable tables were selected.', 'database-search-replace' ) );
		}
		return new Run_Config(
			$search,
			$tables,
			(bool) $request['include_guids'],
			(bool) $request['thorough_scan'],
			get_option( 'safesr_batch_size', 'auto' ),
			$dry_run
		);
	}

	/**
	 * Returns the full run request schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function run_args(): array {
		return array(
			'search'         => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( $this, 'sanitize_value' ),
			),
			'replace'        => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( $this, 'sanitize_value' ),
			),
			'case_sensitive' => array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'regex'          => array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'tables'         => array(
				'type'     => 'array',
				'required' => true,
				'items'    => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
			'include_guids'  => array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'thorough_scan'  => array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'create_backup'  => array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'exclusions'     => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_value' ),
				),
			),
		);
	}

	/**
	 * Returns the settings request schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function settings_args(): array {
		return array(
			'protected_tables' => array(
				'type'     => 'array',
				'required' => true,
				'items'    => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
			'batch_size'       => array(
				'required'          => true,
				'validate_callback' => static function ( $value ): bool {
					return 'auto' === $value || ( is_numeric( $value ) && 50 <= (int) $value && 5000 >= (int) $value && (string) (int) $value === (string) $value );
				},
				'sanitize_callback' => static function ( $value ) {
					return 'auto' === $value ? 'auto' : (int) $value;
				},
			),
			'keep_logs'        => array(
				'type'              => 'boolean',
				'required'          => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Returns a route identifier schema.
	 *
	 * @param bool $required Whether the value must be present.
	 * @return array<string,mixed>
	 */
	private function id_arg( bool $required = true ): array {
		return array(
			'type'              => 'string',
			'required'          => $required,
			'pattern'           => '^[a-f0-9]{32}$',
			'sanitize_callback' => 'sanitize_key',
		);
	}

	/**
	 * Removes invalid UTF-8 without changing valid replacement characters.
	 *
	 * REST request bodies arrive unslashed, so a backslash is literal here.
	 * Stripping it would turn a regex like \d into d and quietly change the
	 * search the caller submitted.
	 *
	 * @param mixed $value Request value.
	 * @return string
	 */
	public function sanitize_value( $value ): string {
		return wp_check_invalid_utf8( (string) $value );
	}

	/**
	 * Returns public job fields and undo availability.
	 *
	 * @param array<string,mixed>|null $job Job record.
	 * @return array<string,mixed>
	 */
	private function public_job( ?array $job ): array {
		if ( null === $job ) {
			return array();
		}
		return array(
			'id'          => $job['id'],
			'type'        => $job['type'],
			'status'      => $job['status'],
			'progress'    => $job['progress'],
			'summary'     => $job['summary'],
			'backup_id'   => $job['backup_id'],
			'has_backup'  => $this->manager->backup_is_complete( $job ),
			'undone'      => ! empty( $job['summary']['undone_at'] ),
			'error'       => $job['error'],
			'created_at'  => $job['created_at'],
			'started_at'  => $job['started_at'],
			'finished_at' => $job['finished_at'],
		);
	}

	/**
	 * Maps a stored job to the history response shape.
	 *
	 * @param array<string,mixed> $job             Job record.
	 * @param string              $latest_apply_id Newest apply that has not been undone.
	 * @return array<string,mixed>
	 */
	private function run_summary( array $job, string $latest_apply_id ): array {
		$config    = is_array( $job['config'] ?? null ) ? $job['config'] : array();
		$progress  = is_array( $job['progress'] ?? null ) ? $job['progress'] : array();
		$summary   = is_array( $job['summary'] ?? null ) ? $job['summary'] : array();
		$undone_at = isset( $summary['undone_at'] ) && is_string( $summary['undone_at'] ) ? $summary['undone_at'] : null;

		$run_kind   = in_array( $job['type'], array( 'preview', 'undo' ), true ) ? (string) $job['type'] : 'apply';
		$is_apply   = 'apply' === $run_kind;
		$has_backup = $is_apply && (bool) ( $job['has_backup'] ?? false );
		$undone     = $is_apply && null !== $undone_at;
		$undone_at  = $undone ? $undone_at : null;

		return array(
			'id'           => (string) $job['id'],
			'type'         => (string) $job['type'],
			'run_kind'     => $run_kind,
			'status'       => (string) $job['status'],
			'created_at'   => (string) $job['created_at'],
			'finished_at'  => (string) ( $job['finished_at'] ?? '' ),
			'search'       => (string) ( $config['search'] ?? '' ),
			'replace'      => (string) ( $config['replace'] ?? '' ),
			'tables'       => count( is_array( $config['tables'] ?? null ) ? $config['tables'] : array() ),
			'replacements' => (int) ( $progress['replacements'] ?? 0 ),
			'matches'      => (int) ( $progress['matches'] ?? 0 ),
			'skipped'      => (int) ( $progress['skipped'] ?? 0 ),
			'has_backup'   => $has_backup,
			'undoable'     => $is_apply && 'completed' === $job['status'] && $has_backup && ! $undone && $job['id'] === $latest_apply_id,
			'undone'       => $undone,
			'undone_at'    => $undone_at,
		);
	}

	/**
	 * Invalid-request response.
	 *
	 * @param string $message User-facing validation message.
	 * @return WP_Error
	 */
	private function bad_request( string $message ): WP_Error {
		return new WP_Error( 'safesr_invalid_request', $message, array( 'status' => 400 ) );
	}

	/**
	 * Missing-job response.
	 *
	 * @param string $message Optional user-facing message.
	 * @return WP_Error
	 */
	private function not_found( string $message = '' ): WP_Error {
		if ( '' === $message ) {
			$message = __( 'The requested search and replace job was not found.', 'database-search-replace' );
		}
		return new WP_Error( 'safesr_not_found', $message, array( 'status' => 404 ) );
	}
}
