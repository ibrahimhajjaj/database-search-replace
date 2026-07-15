/* eslint-disable @wordpress/i18n-text-domain, @wordpress/i18n-translator-comments */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { ApiClient, Change, Job, RunSummary } from '../api/client';
import { DiffRow } from './DiffRow';
import { Progress } from './Progress';

const domain = 'database-search-replace';
const pageSize = 10;

type ChangeState = {
	items: Change[];
	loading: boolean;
	error: string;
};

type PageItem = number | 'ellipsis';

export function pageWindow( current: number, total: number ): PageItem[] {
	if ( total <= 7 ) {
		return Array.from( { length: total }, ( _, index ) => index + 1 );
	}
	const pages = new Set( [ 1, total, current - 1, current, current + 1 ] );
	if ( current <= 3 ) {
		pages.add( 2 );
		pages.add( 3 );
	}
	if ( current >= total - 2 ) {
		pages.add( total - 1 );
		pages.add( total - 2 );
	}
	const sorted = Array.from( pages )
		.filter( ( page ) => page >= 1 && page <= total )
		.sort( ( left, right ) => left - right );
	const items: PageItem[] = [];
	sorted.forEach( ( page, index ) => {
		if ( index > 0 && page - sorted[ index - 1 ] > 1 ) {
			items.push( 'ellipsis' );
		}
		items.push( page );
	} );
	return items;
}

function message( error: unknown ): string {
	return error instanceof Error
		? error.message
		: __( 'The request could not be completed.', domain );
}

function localDate( value: string ): string {
	const date = new Date( `${ value.replace( ' ', 'T' ) }Z` );
	return Number.isNaN( date.getTime() ) ? value : date.toLocaleString();
}

function RestoreProgress( {
	client,
	runId,
	jobId,
	onFinished,
	onFailed,
	onPollError,
}: {
	client: ApiClient;
	runId: string;
	jobId: string;
	onFinished: ( runId: string ) => void;
	onFailed: ( runId: string, error: string ) => void;
	onPollError: ( runId: string, error: string ) => void;
} ) {
	const terminal = useCallback(
		( job: Job ) => {
			if ( job.status === 'completed' ) {
				onFinished( runId );
				return;
			}
			onFailed(
				runId,
				job.error || __( 'The restore did not complete.', domain )
			);
		},
		[ onFailed, onFinished, runId ]
	);
	const failed = useCallback(
		( error: string ) => onPollError( runId, error ),
		[ onPollError, runId ]
	);

	return (
		<Progress
			compact
			jobId={ jobId }
			getJob={ client.getJob }
			onTerminal={ terminal }
			onError={ failed }
		/>
	);
}

function Status( { run }: { run: RunSummary } ) {
	if ( run.run_kind === 'preview' ) {
		return (
			<>
				<span className="safesr-history-status is-preview">
					<span aria-hidden="true">◉</span>{ ' ' }
					{ __( 'Preview only', domain ) }
				</span>
				<small>
					{ sprintf(
						__( '%s matches', domain ),
						run.matches.toLocaleString()
					) }
				</small>
			</>
		);
	}
	return (
		<>
			<span
				className={ `safesr-history-status ${
					run.undone ? 'is-undone' : 'is-applied'
				}` }
			>
				<span aria-hidden="true">{ run.undone ? '↺' : '✓' }</span>{ ' ' }
				{ run.undone
					? __( 'Undone', domain )
					: __( 'Applied', domain ) }
			</span>
			<small>
				{ sprintf(
					__( '%1$s changes · %2$s skipped', domain ),
					run.replacements.toLocaleString(),
					run.skipped.toLocaleString()
				) }
			</small>
		</>
	);
}

export function History( { client }: { client: ApiClient } ) {
	const [ runs, setRuns ] = useState< RunSummary[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ refresh, setRefresh ] = useState( 0 );
	const [ open, setOpen ] = useState< Record< string, boolean > >( {} );
	const [ changes, setChanges ] = useState< Record< string, ChangeState > >(
		{}
	);
	const [ restoring, setRestoring ] = useState<
		Record< string, string | null >
	>( {} );
	const [ rowErrors, setRowErrors ] = useState< Record< string, string > >(
		{}
	);

	useEffect( () => {
		let ignore = false;
		setLoading( true );
		setError( '' );
		client
			.listJobs( { type: 'history', page, per_page: pageSize } )
			.then( ( response ) => {
				if ( ! ignore ) {
					setRuns( response.items );
					setTotal( response.total );
					setLoading( false );
					setRestoring( {} );
				}
			} )
			.catch( ( requestError ) => {
				if ( ! ignore ) {
					setLoading( false );
					setError( message( requestError ) );
				}
			} );
		return () => {
			ignore = true;
		};
	}, [ client, page, refresh ] );

	const finishRestore = useCallback( ( runId: string ) => {
		setRowErrors( ( current ) => ( { ...current, [ runId ]: '' } ) );
		setRefresh( ( value ) => value + 1 );
	}, [] );
	const failRestore = useCallback( ( runId: string, failure: string ) => {
		setRestoring( ( current ) => {
			const next = { ...current };
			delete next[ runId ];
			return next;
		} );
		setRowErrors( ( current ) => ( { ...current, [ runId ]: failure } ) );
	}, [] );
	const reportPollError = useCallback( ( runId: string, failure: string ) => {
		setRowErrors( ( current ) => ( { ...current, [ runId ]: failure } ) );
	}, [] );

	const undo = async ( runId: string ) => {
		setRowErrors( ( current ) => ( { ...current, [ runId ]: '' } ) );
		setRestoring( ( current ) => ( { ...current, [ runId ]: null } ) );
		try {
			const created = await client.undoJob( runId );
			setRestoring( ( current ) => ( {
				...current,
				[ runId ]: created.job_id,
			} ) );
		} catch ( requestError ) {
			failRestore( runId, message( requestError ) );
		}
	};

	const toggleChanges = ( runId: string ) => {
		const expanded = ! open[ runId ];
		setOpen( ( current ) => ( { ...current, [ runId ]: expanded } ) );
		if ( ! expanded || changes[ runId ] ) {
			return;
		}
		setChanges( ( current ) => ( {
			...current,
			[ runId ]: { items: [], loading: true, error: '' },
		} ) );
		client
			.getChanges( runId, { page: 1 } )
			.then( ( response ) =>
				setChanges( ( current ) => ( {
					...current,
					[ runId ]: {
						items: response.items,
						loading: false,
						error: '',
					},
				} ) )
			)
			.catch( ( requestError ) =>
				setChanges( ( current ) => ( {
					...current,
					[ runId ]: {
						items: [],
						loading: false,
						error: message( requestError ),
					},
				} ) )
			);
	};

	if ( loading && runs.length === 0 ) {
		return (
			<div className="safesr-loading" role="status">
				{ __( 'Loading run history…', domain ) }
			</div>
		);
	}

	const totalPages = Math.max( 1, Math.ceil( total / pageSize ) );
	const from = total === 0 ? 0 : ( page - 1 ) * pageSize + 1;
	const to = Math.min( page * pageSize, total );
	const retentionDays = window.safesrAdmin?.retentionDays ?? 30;

	return (
		<section className="safesr-screen">
			<div className="safesr-title">
				<h1>{ __( 'History', domain ) }</h1>
				<p>
					{ __(
						'Applied search and replace runs on this site.',
						domain
					) }
				</p>
			</div>
			{ error && (
				<div className="safesr-inline-error" role="alert">
					{ error }
				</div>
			) }
			{ runs.length === 0 ? (
				<div className="safesr-history-empty">
					{ __(
						'No runs yet. Your previews and applied replaces will show up here.',
						domain
					) }
				</div>
			) : (
				<div
					className="safesr-history-table"
					role="table"
					aria-busy={ loading }
				>
					<div className="safesr-history-columns" role="row">
						<span role="columnheader">
							{ __( 'Job / time', domain ) }
						</span>
						<span role="columnheader">
							{ __( 'Replacement', domain ) }
						</span>
						<span role="columnheader">
							{ __( 'Result', domain ) }
						</span>
						<span role="columnheader">
							{ __( 'Actions', domain ) }
						</span>
					</div>
					{ runs.map( ( run ) => {
						const expanded = Boolean( open[ run.id ] );
						const changeState = changes[ run.id ];
						const isRestoring =
							Object.prototype.hasOwnProperty.call(
								restoring,
								run.id
							);
						return (
							<div
								className="safesr-history-row"
								role="row"
								key={ run.id }
							>
								<div className="safesr-history-job" role="cell">
									<code>{ run.id.slice( 0, 8 ) }</code>
									<time dateTime={ `${ run.created_at }Z` }>
										{ localDate( run.created_at ) }
									</time>
								</div>
								<div
									className="safesr-history-replacement"
									role="cell"
								>
									<code>− { run.search }</code>
									<code>+ { run.replace }</code>
								</div>
								<div
									className="safesr-history-result"
									role="cell"
								>
									<Status run={ run } />
								</div>
								<div
									className="safesr-history-actions"
									role="cell"
								>
									<button
										type="button"
										className="safesr-history-view"
										aria-expanded={ expanded }
										aria-controls={ `safesr-history-changes-${ run.id }` }
										onClick={ () =>
											toggleChanges( run.id )
										}
									>
										{ __( 'View', domain ) }
									</button>
									{ run.undoable && (
										<button
											type="button"
											className="safesr-history-undo"
											disabled={ isRestoring }
											onClick={ () =>
												void undo( run.id )
											}
										>
											<span aria-hidden="true">↺</span>{ ' ' }
											{ __( 'Undo', domain ) }
										</button>
									) }
								</div>
								{ isRestoring && (
									<div className="safesr-history-restore">
										{ restoring[ run.id ] ? (
											<RestoreProgress
												client={ client }
												runId={ run.id }
												jobId={
													restoring[ run.id ] ?? ''
												}
												onFinished={ finishRestore }
												onFailed={ failRestore }
												onPollError={ reportPollError }
											/>
										) : (
											<span aria-live="polite">
												{ __( 'Restoring…', domain ) }
											</span>
										) }
									</div>
								) }
								{ rowErrors[ run.id ] && (
									<span
										className="safesr-history-error"
										role="alert"
									>
										{ rowErrors[ run.id ] }
									</span>
								) }
								{ expanded && (
									<div
										id={ `safesr-history-changes-${ run.id }` }
										className="safesr-history-changes"
									>
										{ changeState?.loading &&
											__( 'Loading changes…', domain ) }
										{ changeState?.error && (
											<span role="alert">
												{ changeState.error }
											</span>
										) }
										{ changeState &&
											! changeState.loading &&
											! changeState.error &&
											changeState.items.length === 0 && (
												<p>
													{ __(
														'No sampled changes are available for this run.',
														domain
													) }
												</p>
											) }
										{ changeState?.items.map(
											( change ) => (
												<DiffRow
													key={ change.id }
													change={ change }
													mode="split"
												/>
											)
										) }
									</div>
								) }
							</div>
						);
					} ) }
					<div className="safesr-history-snapshot">
						<span aria-hidden="true">✓</span>{ ' ' }
						{ sprintf(
							__(
								'Every run keeps its snapshot for %d days. Undo is available while a snapshot exists.',
								domain
							),
							retentionDays
						) }
					</div>
					<div className="safesr-history-pagination">
						<span>
							{ sprintf(
								__( '%1$d-%2$d of %3$d runs', domain ),
								from,
								to,
								total
							) }
						</span>
						<nav aria-label={ __( 'History pages', domain ) }>
							<button
								type="button"
								aria-label={ __( 'Previous page', domain ) }
								disabled={ page === 1 }
								onClick={ () => setPage( page - 1 ) }
							>
								←
							</button>
							{ pageWindow( page, totalPages ).map(
								( item, index ) =>
									item === 'ellipsis' ? (
										<span key={ `ellipsis-${ index }` }>
											…
										</span>
									) : (
										<button
											type="button"
											key={ item }
											className={
												item === page
													? 'is-current'
													: ''
											}
											aria-current={
												item === page
													? 'page'
													: undefined
											}
											aria-label={ sprintf(
												__( 'Page %d', domain ),
												item
											) }
											onClick={ () => setPage( item ) }
										>
											{ item }
										</button>
									)
							) }
							<button
								type="button"
								aria-label={ __( 'Next page', domain ) }
								disabled={ page === totalPages }
								onClick={ () => setPage( page + 1 ) }
							>
								→
							</button>
						</nav>
					</div>
				</div>
			) }
		</section>
	);
}
