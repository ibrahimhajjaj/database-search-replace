import apiFetch from '@wordpress/api-fetch';

export type Table = {
	name: string;
	rows: number;
	size: number;
	protected: boolean;
	processable: boolean;
};

export type TablesResponse = { tables: Table[]; defaults: string[] };

export type RunRequest = {
	search: string;
	replace: string;
	case_sensitive: boolean;
	regex: boolean;
	tables: string[];
	include_guids: boolean;
	thorough_scan: boolean;
	exclusions: string[];
};

export type ApplyRequest = RunRequest & {
	preview_job_id: string;
	create_backup?: boolean;
};
export type JobCreated = { job_id: string };
export type JobStatus =
	| 'queued'
	| 'running'
	| 'completed'
	| 'failed'
	| 'canceled';

export type JobProgress = {
	tables_total: number;
	tables_done: number;
	current_table: string;
	current_cursor: string | null;
	rows_scanned: number;
	matches: number;
	replacements: number;
	skipped: number;
	last_run_at: number | null;
};

export type TableSummary = {
	rows_scanned: number;
	matches: number;
	replacements: number;
	skipped: number;
	errors: string[];
};

export type Job = {
	id: string;
	type: 'preview' | 'apply' | 'undo';
	status: JobStatus;
	progress: JobProgress;
	summary: Record< string, TableSummary | string | boolean >;
	backup_id: string | null;
	has_backup: boolean;
	undone: boolean;
	error: string | null;
	created_at: string;
	started_at: string | null;
	finished_at: string | null;
};

export type Segment = { t: string; k: 'ctx' | 'del' | 'add' };
export type Change = {
	id: string;
	job_id: string;
	table_name: string;
	column_name: string;
	row_pk: string;
	before_excerpt: Segment[];
	after_excerpt: Segment[];
	formats: string[];
	skipped: boolean;
	skip_reason: string;
	created_at: string;
	truncated?: boolean;
};

export type ChangesPage = {
	items: Change[];
	total: number;
	totalPages: number;
};
export type RunSummary = {
	id: string;
	type: string;
	run_kind: 'preview' | 'apply';
	status: string;
	created_at: string;
	finished_at: string;
	search: string;
	replace: string;
	tables: number;
	replacements: number;
	matches: number;
	skipped: number;
	has_backup: boolean;
	undone: boolean;
	undone_at: string | null;
	undoable: boolean;
};
export type JobListParams = {
	type?: 'apply' | 'history' | 'all';
	page?: number;
	per_page?: number;
};
export type ChangeFilters = {
	page?: number;
	per_page?: number;
	table?: string;
	text?: string;
};
export type Settings = {
	protected_tables: string[];
	batch_size: 'auto' | number;
	keep_logs: boolean;
};

type RestFailure = { code?: string; message?: string };

export class ApiError extends Error {
	code: string;

	constructor( message: string, code = 'safesr_request_failed' ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
	}
}

function normalizeError( error: unknown ): ApiError {
	if ( error instanceof ApiError ) {
		return error;
	}
	const failure = error as RestFailure;
	return new ApiError(
		failure?.message || 'The request could not be completed.',
		failure?.code
	);
}

async function request< T >(
	options: Parameters< typeof apiFetch >[ 0 ]
): Promise< T > {
	try {
		return await apiFetch< T >( options );
	} catch ( error ) {
		throw normalizeError( error );
	}
}

function queryPath( path: string, filters: object ): string {
	const query = Object.entries( filters )
		.filter( ( [ , value ] ) => value !== undefined && value !== '' )
		.map(
			( [ key, value ] ) =>
				`${ encodeURIComponent( key ) }=${ encodeURIComponent(
					String( value )
				) }`
		)
		.join( '&' );
	return query ? `${ path }?${ query }` : path;
}

export function createClient() {
	return {
		getTables: () =>
			request< TablesResponse >( { path: '/safesr/v1/tables' } ),
		listJobs: ( params: JobListParams = {} ) =>
			request< { items: RunSummary[]; total: number } >( {
				path: queryPath( '/safesr/v1/jobs', params ),
			} ),
		createPreview: ( data: RunRequest ) =>
			request< JobCreated >( {
				path: '/safesr/v1/preview',
				method: 'POST',
				data,
			} ),
		createApply: ( data: ApplyRequest ) =>
			request< JobCreated >( {
				path: '/safesr/v1/apply',
				method: 'POST',
				data,
			} ),
		getJob: ( id: string ) =>
			request< Job >( { path: `/safesr/v1/jobs/${ id }` } ),
		async getChanges(
			id: string,
			filters: ChangeFilters = {}
		): Promise< ChangesPage > {
			try {
				const response = await apiFetch< Response >( {
					path: queryPath(
						`/safesr/v1/jobs/${ id }/changes`,
						filters
					),
					parse: false,
				} );
				return {
					items: ( await response.json() ) as Change[],
					total: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
					totalPages: Number(
						response.headers.get( 'X-WP-TotalPages' ) || 0
					),
				};
			} catch ( error ) {
				throw normalizeError( error );
			}
		},
		cancelJob: ( id: string ) =>
			request< Job >( {
				path: `/safesr/v1/jobs/${ id }/cancel`,
				method: 'POST',
			} ),
		undoJob: ( id: string ) =>
			request< JobCreated >( {
				path: `/safesr/v1/jobs/${ id }/undo`,
				method: 'POST',
			} ),
		getSettings: () =>
			request< Settings >( { path: '/safesr/v1/settings' } ),
		updateSettings: ( data: Settings ) =>
			request< Settings >( {
				path: '/safesr/v1/settings',
				method: 'POST',
				data,
			} ),
	};
}

export type ApiClient = ReturnType< typeof createClient >;
