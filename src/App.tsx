/* eslint-disable @wordpress/i18n-text-domain, @wordpress/i18n-translator-comments, jsx-a11y/label-has-associated-control, react/jsx-no-target-blank */
import {
	useCallback,
	useEffect,
	useMemo,
	useReducer,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	createClient,
	type ApiClient,
	type Change,
	type Job,
	type RunRequest,
	type Settings,
	type Table,
	type TableSummary,
} from './api/client';
import { AdvancedPanel } from './components/AdvancedPanel';
import { ConfirmModal } from './components/ConfirmModal';
import { DiffRow } from './components/DiffRow';
import { Help } from './components/Help';
import { History } from './components/History';
import { Progress } from './components/Progress';
import {
	initialState,
	reducer,
	type FormState,
	type Tab,
} from './state/reducer';

const defaultClient = createClient();
const domain = 'database-search-replace';

function bootData() {
	return (
		window.safesrAdmin ?? {
			version: '0.1.0',
			imagesUrl: '',
			logUrlBase: '/wp-json/safesr/v1/jobs/',
			restNonce: '',
			ajaxUrl: '/wp-admin/admin-ajax.php',
			reviewNonce: '',
			reviewDismissed: false,
			proUrl: 'https://safeguard.verdelic.com/migrate?utm_source=ssr-plugin&utm_medium=pro-tools',
			reviewUrl:
				'https://wordpress.org/support/plugin/database-search-replace/reviews/#new-post',
		}
	);
}

function imageUrl( name: string ): string {
	return `${ bootData().imagesUrl }${ name }.png`;
}

function logUrl( jobId: string ): string {
	const data = bootData();
	const nonce = data.restNonce
		? `?_wpnonce=${ encodeURIComponent( data.restNonce ) }`
		: '';
	return `${ data.logUrlBase }${ jobId }/log${ nonce }`;
}

export async function persistReviewDismissal(): Promise< void > {
	const data = bootData();
	if ( ! data.reviewNonce ) {
		return;
	}
	const body = new URLSearchParams( {
		action: 'closed-postboxes',
		closed: 'safesr-review-nudge',
		page: 'safesr',
		closedpostboxesnonce: data.reviewNonce,
	} );
	await fetch( data.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: body.toString(),
	} );
}

function toRunRequest( form: FormState ): RunRequest {
	return {
		search: form.search,
		replace: form.replace,
		case_sensitive: form.caseSensitive,
		regex: form.regex,
		tables: form.tables,
		include_guids: form.includeGuids,
		thorough_scan: form.thoroughScan,
		exclusions: form.exclusions,
	};
}

function errorMessage( error: unknown ): string {
	return error instanceof Error
		? error.message
		: __( 'The request could not be completed.', domain );
}

function Header( {
	tab,
	theme,
	onTab,
	onTheme,
}: {
	tab: Tab;
	theme: 'light' | 'dark';
	onTab: ( tab: Tab ) => void;
	onTheme: () => void;
} ) {
	const data = bootData();
	const tabs: Array< { id: Tab; label: string } > = [
		{ id: 'replace', label: __( 'Search & Replace', domain ) },
		{ id: 'history', label: __( 'History', domain ) },
		{ id: 'settings', label: __( 'Settings', domain ) },
		{ id: 'help', label: __( 'Help', domain ) },
		{ id: 'pro', label: __( 'Pro Tools', domain ) },
	];
	return (
		<header className="safesr-header">
			<div className="safesr-header-main">
				<div className="safesr-brand">
					<span aria-hidden="true">◎</span>
					<div>
						<strong>
							{ __( 'Database Search & Replace', domain ) }
						</strong>
						<small>{ __( 'WordPress · Tools', domain ) }</small>
					</div>
				</div>
				<div className="safesr-header-tools">
					<code>v{ data.version }</code>
					<button type="button" onClick={ onTheme }>
						{ theme === 'dark' ? '☀' : '☾' }{ ' ' }
						{ theme === 'dark'
							? __( 'Light', domain )
							: __( 'Dark', domain ) }
					</button>
				</div>
			</div>
			<nav aria-label={ __( 'Plugin sections', domain ) }>
				{ tabs.map( ( item ) => (
					<button
						type="button"
						className={ tab === item.id ? 'is-active' : '' }
						aria-current={ tab === item.id ? 'page' : undefined }
						key={ item.id }
						onClick={ () => onTab( item.id ) }
					>
						{ item.label }
					</button>
				) ) }
			</nav>
		</header>
	);
}

function SafetyRail() {
	const items = [
		[
			'1',
			__( 'Preview everything', domain ),
			__( 'See every change first, always free.', domain ),
		],
		[
			'2',
			__( 'Auto-backup', domain ),
			__( 'A safety copy before we write.', domain ),
		],
		[
			'3',
			__( 'Apply safely', domain ),
			__( 'Handles serialized data other tools corrupt.', domain ),
		],
		[
			'↺',
			__( 'One-click undo', domain ),
			__( 'Roll it all back after a run.', domain ),
		],
	];
	return (
		<aside className="safesr-safety-rail">
			<h2>{ __( 'How we keep you safe', domain ) }</h2>
			{ items.map( ( [ number, title, copy ] ) => (
				<div className="safesr-safety-item" key={ title }>
					<span>{ number }</span>
					<div>
						<strong>{ title }</strong>
						<p>{ copy }</p>
					</div>
				</div>
			) ) }
			<footer>
				{ __( 'Nothing leaves your server during a replace.', domain ) }
			</footer>
		</aside>
	);
}

function SearchForm( {
	form,
	tables,
	busy,
	error,
	onChange,
	onSubmit,
}: {
	form: FormState;
	tables: Table[];
	busy: boolean;
	error: string;
	onChange: ( form: Partial< FormState > ) => void;
	onSubmit: () => void;
} ) {
	const [ advanced, setAdvanced ] = useState( false );
	const empty = ! form.search && ! form.replace;
	return (
		<section className="safesr-screen">
			<div className="safesr-title">
				<h1>{ __( 'Search & Replace', domain ) }</h1>
				<p>
					{ __(
						'Change text, usually a URL or domain, safely across your database.',
						domain
					) }
				</p>
			</div>
			<div className="safesr-form-card">
				<div className="safesr-form-column">
					{ empty && (
						<div className="safesr-empty-intro">
							<img
								className="safesr-illustration"
								src={ imageUrl( 'empty-state-main' ) }
								alt=""
								width={ 132 }
								height={ 100 }
							/>
							<p>
								{ __(
									'Paste your old URL below and what it should become. We’ll show representative matches before touching a thing.',
									domain
								) }
							</p>
						</div>
					) }
					<div className="safesr-transform-fields">
						<label htmlFor="safesr-search">
							{ __( 'Search for', domain ) }
						</label>
						<input
							id="safesr-search"
							spellCheck={ false }
							value={ form.search }
							onChange={ ( event ) =>
								onChange( { search: event.target.value } )
							}
						/>
						<span
							className="safesr-transform-arrow"
							aria-hidden="true"
						>
							↓
						</span>
						<label htmlFor="safesr-replace">
							{ __( 'Replace with', domain ) }
						</label>
						<input
							id="safesr-replace"
							spellCheck={ false }
							value={ form.replace }
							onChange={ ( event ) =>
								onChange( { replace: event.target.value } )
							}
						/>
					</div>
					<button
						type="button"
						className="safesr-advanced-toggle"
						aria-expanded={ advanced }
						onClick={ () => setAdvanced( ! advanced ) }
					>
						<span>{ advanced ? '▾' : '▸' }</span>
						<strong>{ __( 'Advanced options', domain ) }</strong>
						<small>
							{ __( 'tables · regex · exclusions', domain ) }
						</small>
					</button>
					{ advanced && (
						<AdvancedPanel
							tables={ tables }
							value={ form.tables }
							exclusions={ form.exclusions }
							caseSensitive={ form.caseSensitive }
							regex={ form.regex }
							includeGuids={ form.includeGuids }
							thoroughScan={ form.thoroughScan }
							onTablesChange={ ( value ) =>
								onChange( { tables: value } )
							}
							onExclusionsChange={ ( value ) =>
								onChange( { exclusions: value } )
							}
							onOptionChange={ ( option, value ) =>
								onChange( { [ option ]: value } )
							}
						/>
					) }
					{ error && (
						<div className="safesr-inline-error" role="alert">
							{ error }
						</div>
					) }
					<button
						type="button"
						className="safesr-button primary wide"
						disabled={ busy }
						onClick={ onSubmit }
					>
						{ busy
							? __( 'Preparing preview…', domain )
							: __( '◉ Preview changes', domain ) }
					</button>
					<p className="safesr-reassurance">
						✓{ ' ' }
						{ __(
							'You’ll see exactly what changes before anything is modified.',
							domain
						) }
					</p>
				</div>
				<SafetyRail />
			</div>
		</section>
	);
}

type DiffGroup = {
	table: string;
	rows: Change[];
	total: number;
	protected: boolean;
};

function DiffScreen( {
	client,
	job,
	jobId,
	mode,
	onMode,
	onCancel,
	onApply,
	onError,
}: {
	client: ApiClient;
	job: Job | null;
	jobId: string;
	mode: 'split' | 'inline';
	onMode: ( mode: 'split' | 'inline' ) => void;
	onCancel: () => void;
	onApply: () => void;
	onError: ( message: string ) => void;
} ) {
	const [ changes, setChanges ] = useState< Change[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ filter, setFilter ] = useState( '' );
	const [ open, setOpen ] = useState< Record< string, boolean > >( {} );

	useEffect( () => {
		let current = true;
		const timer = setTimeout(
			() => {
				setLoading( true );
				client
					.getChanges( jobId, {
						page: 1,
						per_page: 100,
						text: filter.trim(),
					} )
					.then( ( page ) => {
						if ( current ) {
							setChanges( page.items );
							setTotal( page.total );
							setLoading( false );
						}
					} )
					.catch( ( error ) => {
						if ( current ) {
							setLoading( false );
							onError( errorMessage( error ) );
						}
					} );
			},
			filter ? 300 : 0
		);
		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ client, filter, jobId, onError ] );

	const groups = useMemo( () => {
		const grouped = new Map< string, Change[] >();
		changes.forEach( ( change ) =>
			grouped.set( change.table_name, [
				...( grouped.get( change.table_name ) ?? [] ),
				change,
			] )
		);
		return Array.from( grouped, ( [ table, rows ] ): DiffGroup => {
			const summary = job?.summary[ table ] as TableSummary | undefined;
			return {
				table,
				rows,
				total: summary?.matches ?? rows.length,
				protected: rows.every( ( row ) => row.skipped ),
			};
		} ).sort(
			( left, right ) =>
				Number( left.protected ) - Number( right.protected )
		);
	}, [ changes, job ] );
	const protectedCount =
		job?.progress.skipped ??
		changes.filter( ( change ) => change.skipped ).length;
	const tableCount = Object.keys( job?.summary ?? {} ).filter(
		( key ) => key !== 'log_available' && key !== 'undone_at'
	).length;
	const hasJson = changes.some( ( change ) =>
		change.formats.includes( 'json' )
	);
	const hasErrors = Object.values( job?.summary ?? {} ).some(
		( value ) =>
			typeof value === 'object' &&
			value !== null &&
			value.errors.length > 0
	);

	if ( ! loading && total === 0 ) {
		return (
			<section className="safesr-no-matches">
				<img
					className="safesr-illustration"
					src={ imageUrl( 'empty-state-no-results' ) }
					alt=""
					width={ 132 }
					height={ 100 }
				/>
				<h1>{ __( 'No matches found', domain ) }</h1>
				<p>
					{ __(
						'Double-check the search string. A missing protocol or a typo is the usual culprit.',
						domain
					) }
				</p>
				<button
					type="button"
					className="safesr-button secondary"
					onClick={ onCancel }
				>
					{ __( '← Edit search', domain ) }
				</button>
			</section>
		);
	}

	return (
		<section className="safesr-screen">
			<div className="safesr-diff-summary">
				<div>
					<span className="safesr-summary-icon">◉</span>
					<div>
						<h1>
							{ sprintf(
								__(
									'%1$s changes found across %2$s tables.',
									domain
								),
								(
									job?.progress.matches ?? total
								).toLocaleString(),
								tableCount.toLocaleString()
							) }
						</h1>
						<p>
							{ protectedCount > 0 && (
								<>
									{ sprintf(
										__(
											'%s values protected and skipped.',
											domain
										),
										protectedCount.toLocaleString()
									) }{ ' ' }
								</>
							) }
							<strong>
								{ __(
									'Nothing has been changed yet.',
									domain
								) }
							</strong>
						</p>
					</div>
				</div>
				<div className="safesr-mode-switch">
					<button
						type="button"
						className={ mode === 'split' ? 'is-active' : '' }
						onClick={ () => onMode( 'split' ) }
					>
						{ __( 'Side-by-side', domain ) }
					</button>
					<button
						type="button"
						className={ mode === 'inline' ? 'is-active' : '' }
						onClick={ () => onMode( 'inline' ) }
					>
						{ __( 'Inline', domain ) }
					</button>
				</div>
				<label className="safesr-filter">
					<span aria-hidden="true">⌕</span>
					<span className="screen-reader-text">
						{ __( 'Filter changes', domain ) }
					</span>
					<input
						value={ filter }
						onChange={ ( event ) =>
							setFilter( event.target.value )
						}
						placeholder={ __(
							'Filter changes: table, column, or text…',
							domain
						) }
					/>
				</label>
				<code>
					{ sprintf(
						__( 'showing %1$s rows of %2$s', domain ),
						changes.length.toLocaleString(),
						total.toLocaleString()
					) }
				</code>
			</div>
			{ hasErrors && (
				<div className="safesr-warning" role="status">
					⚠{ ' ' }
					{ __(
						'A value could not be read and was skipped safely. It was left untouched so nothing breaks.',
						domain
					) }
				</div>
			) }
			{ hasJson && (
				<div className="safesr-aha">
					<span aria-hidden="true">✦</span>
					<p>
						<strong>
							{ __( 'The one other tools miss:', domain ) }
						</strong>{ ' ' }
						{ __(
							'escaped JSON was detected and updated safely.',
							domain
						) }
					</p>
				</div>
			) }
			{ loading ? (
				<div className="safesr-loading" role="status">
					{ __( 'Loading preview…', domain ) }
				</div>
			) : (
				<div className="safesr-diff-groups">
					{ groups.map( ( group ) => {
						const expanded = open[ group.table ] !== false;
						const more = Math.max(
							0,
							group.total -
								group.rows.filter( ( row ) => ! row.skipped )
									.length
						);
						return (
							<article
								className={
									group.protected
										? 'safesr-diff-group is-protected'
										: 'safesr-diff-group'
								}
								key={ group.table }
							>
								<button
									type="button"
									className="safesr-group-heading"
									aria-expanded={ expanded }
									onClick={ () =>
										setOpen( {
											...open,
											[ group.table ]: ! expanded,
										} )
									}
								>
									<span>{ expanded ? '▾' : '▸' }</span>
									<code>{ group.table }</code>
									<strong>
										{ group.protected
											? sprintf(
													__(
														'%s protected',
														domain
													),
													group.total.toLocaleString()
											  )
											: sprintf(
													__( '%s changes', domain ),
													group.total.toLocaleString()
											  ) }
									</strong>
									<small>
										{ sprintf(
											__( '%s shown', domain ),
											group.rows.length.toLocaleString()
										) }
									</small>
								</button>
								{ expanded && (
									<div>
										{ group.rows.map( ( change ) => (
											<DiffRow
												key={ change.id }
												change={ change }
												mode={ mode }
											/>
										) ) }
										{ more > 0 && (
											<footer>
												{ sprintf(
													__(
														'+ %s more in this table',
														domain
													),
													more.toLocaleString()
												) }
											</footer>
										) }
									</div>
								) }
							</article>
						);
					} ) }
				</div>
			) }
			<div className="safesr-sticky-actions">
				<p>
					✓{ ' ' }
					{ __(
						'You’ve seen the sampled changes. Applying is safe and undoable.',
						domain
					) }
				</p>
				<div>
					<button
						type="button"
						className="safesr-button secondary"
						onClick={ onCancel }
					>
						{ __( 'Cancel', domain ) }
					</button>
					<button
						type="button"
						className="safesr-button primary"
						onClick={ onApply }
					>
						{ __( 'Apply changes →', domain ) }
					</button>
				</div>
			</div>
		</section>
	);
}

function elapsed( job: Job ): string {
	const start = job.started_at ? Date.parse( `${ job.started_at }Z` ) : NaN;
	const finish = job.finished_at
		? Date.parse( `${ job.finished_at }Z` )
		: NaN;
	return Number.isFinite( start ) && Number.isFinite( finish )
		? `${ Math.max( 0, ( finish - start ) / 1000 ).toFixed( 1 ) }s`
		: __( 'completed', domain );
}

export function SuccessScreen( {
	job,
	undone,
	onUndo,
	onReapply,
	onNew,
}: {
	job: Job;
	undone: boolean;
	onUndo: () => void;
	onReapply: () => void;
	onNew: () => void;
} ) {
	const [ summaryOpen, setSummaryOpen ] = useState( false );
	const [ nudge, setNudge ] = useState( () => ! bootData().reviewDismissed );
	const summaries = Object.entries( job.summary ).filter(
		( entry ): entry is [ string, TableSummary ] =>
			typeof entry[ 1 ] === 'object'
	);
	const replacements = job.progress.replacements;
	const protectedCount = job.progress.skipped;
	const data = bootData();

	if ( undone ) {
		return (
			<section className="safesr-success-wrap">
				<div className="safesr-success-card undone">
					<span className="safesr-success-icon">↺</span>
					<div>
						<h1>
							{ __(
								'Changes undone. Your site is restored.',
								domain
							) }
						</h1>
						<p>
							{ sprintf(
								__(
									'All %s changes were rolled back from the safety snapshot.',
									domain
								),
								replacements.toLocaleString()
							) }
						</p>
					</div>
					<footer>
						<button
							type="button"
							className="safesr-button primary"
							onClick={ onReapply }
						>
							{ __( 'Re-apply these changes', domain ) }
						</button>
						<button
							type="button"
							className="safesr-button secondary"
							onClick={ onNew }
						>
							{ __( 'Start a new search', domain ) }
						</button>
					</footer>
				</div>
			</section>
		);
	}

	return (
		<section className="safesr-success-wrap">
			<div className="safesr-success-card">
				<img
					className="safesr-success-illustration"
					src={ imageUrl( 'success-celebration' ) }
					alt=""
					width={ 72 }
					height={ 72 }
				/>
				<div>
					<h1>
						{ sprintf(
							__( 'Done. %s changes applied.', domain ),
							replacements.toLocaleString()
						) }
					</h1>
					<p>
						{ job.has_backup
							? sprintf(
									__(
										'Across %s tables. A safety snapshot was saved first.',
										domain
									),
									summaries.length.toLocaleString()
							  )
							: sprintf(
									__(
										'Across %s tables. No snapshot was taken for this run.',
										domain
									),
									summaries.length.toLocaleString()
							  ) }
					</p>
				</div>
				<div className="safesr-success-chips">
					<code>⏱ { elapsed( job ) }</code>
					{ job.has_backup && (
						<code>▣ { __( 'safety snapshot saved', domain ) }</code>
					) }
					{ protectedCount > 0 && (
						<code className="protected">
							🛡{ ' ' }
							{ sprintf(
								__( '%s protected', domain ),
								protectedCount.toLocaleString()
							) }
						</code>
					) }
				</div>
				<div className="safesr-undo-zone">
					{ job.has_backup ? (
						<>
							<button
								type="button"
								className="safesr-button undo"
								onClick={ onUndo }
							>
								↺ { __( 'Undo unchanged cells', domain ) }
							</button>
							<p>
								{ __(
									'Restores unchanged affected cells and reports later edits as conflicts.',
									domain
								) }
							</p>
						</>
					) : (
						<p>
							{ __(
								'This run was applied without a snapshot, so it cannot be undone here.',
								domain
							) }
						</p>
					) }
				</div>
				<div className="safesr-change-summary">
					<button
						type="button"
						aria-expanded={ summaryOpen }
						onClick={ () => setSummaryOpen( ! summaryOpen ) }
					>
						<span>{ summaryOpen ? '▾' : '▸' }</span>
						<strong>{ __( 'What changed', domain ) }</strong>
						<small>
							{ sprintf(
								__( '%1$s tables · %2$s changes', domain ),
								summaries.length.toLocaleString(),
								replacements.toLocaleString()
							) }
						</small>
					</button>
					{ summaryOpen && (
						<div>
							{ summaries.map( ( [ table, tableSummary ] ) => (
								<p key={ table }>
									<code>{ table }</code>
									<strong>
										{ sprintf(
											__( '%s changed', domain ),
											tableSummary.replacements.toLocaleString()
										) }
									</strong>
								</p>
							) ) }
							{ job.summary.log_available === true && (
								<a href={ logUrl( job.id ) }>
									{ __(
										'↓ Download preview excerpts (CSV)',
										domain
									) }
								</a>
							) }
						</div>
					) }
				</div>
			</div>
			<div className="safesr-next-step">
				<div>
					<small>{ __( 'Logical next step', domain ) }</small>
					<h2>
						{ __(
							'Moving the whole site, not only text?',
							domain
						) }
					</h2>
					<p>
						{ __(
							'SafeGuard adds full migrations plus scheduled, off-site backups. Search and replace stays free and complete here.',
							domain
						) }
					</p>
				</div>
				<a
					className="safesr-button primary"
					href="https://safeguard.verdelic.com/migrate?utm_source=ssr-plugin&utm_medium=post-run"
					target="_blank"
					rel="noopener"
				>
					{ __( 'Compare migration tools', domain ) }
				</a>
			</div>
			{ nudge && (
				<div className="safesr-review-nudge">
					<span aria-hidden="true">🌱</span>
					<p>
						{ __(
							'Glad that went smoothly. Would you share a quick WordPress.org review?',
							domain
						) }
					</p>
					<a href={ data.reviewUrl } target="_blank" rel="noreferrer">
						{ __( 'Leave a review', domain ) }
					</a>
					<button
						type="button"
						aria-label={ __( 'Dismiss review request', domain ) }
						onClick={ () => {
							setNudge( false );
							void persistReviewDismissal();
						} }
					>
						×
					</button>
				</div>
			) }
		</section>
	);
}

function SettingsScreen( {
	client,
	tables,
	lastJob,
}: {
	client: ApiClient;
	tables: Table[];
	lastJob: Job | null;
} ) {
	const [ settings, setSettings ] = useState< Settings | null >( null );
	const [ customBatch, setCustomBatch ] = useState( '250' );
	const [ status, setStatus ] = useState( '' );
	useEffect( () => {
		client
			.getSettings()
			.then( ( value ) => {
				setSettings( value );
				if ( value.batch_size !== 'auto' ) {
					setCustomBatch( String( value.batch_size ) );
				}
			} )
			.catch( ( error ) => setStatus( errorMessage( error ) ) );
	}, [ client ] );
	if ( ! settings ) {
		return (
			<div className="safesr-loading" role="status">
				{ status || __( 'Loading settings…', domain ) }
			</div>
		);
	}
	const mandatory = settings.protected_tables.filter( ( table ) =>
		/(?:^|_)users$|(?:^|_)usermeta$/.test( table )
	);
	return (
		<section className="safesr-screen">
			<div className="safesr-title">
				<h1>{ __( 'Settings', domain ) }</h1>
				<p>
					{ __(
						'Sensible, safe defaults. Change these only when you need to.',
						domain
					) }
				</p>
			</div>
			<div className="safesr-settings-grid">
				<div className="safesr-settings-card full">
					<h2>{ __( 'Protected tables', domain ) }</h2>
					<p>
						{ __(
							'These tables are excluded because changing them is a common way to break logins.',
							domain
						) }
					</p>
					<div className="safesr-protected-list">
						{ settings.protected_tables.map( ( table ) => (
							<label key={ table }>
								<input
									type="checkbox"
									checked
									disabled={ mandatory.includes( table ) }
									onChange={ () =>
										setSettings( {
											...settings,
											protected_tables:
												settings.protected_tables.filter(
													( value ) => value !== table
												),
										} )
									}
								/>
								<code>{ table }</code>
								{ mandatory.includes( table ) && (
									<span>{ __( 'required', domain ) }</span>
								) }
							</label>
						) ) }
						<select
							aria-label={ __( 'Add a protected table', domain ) }
							value=""
							onChange={ ( event ) =>
								setSettings( {
									...settings,
									protected_tables: [
										...settings.protected_tables,
										event.target.value,
									],
								} )
							}
						>
							<option value="">
								{ __( '+ Add a protected table', domain ) }
							</option>
							{ tables
								.filter(
									( table ) =>
										! settings.protected_tables.includes(
											table.name
										)
								)
								.map( ( table ) => (
									<option
										key={ table.name }
										value={ table.name }
									>
										{ table.name }
									</option>
								) ) }
						</select>
					</div>
				</div>
				<div className="safesr-settings-card">
					<h2>{ __( 'Batch size', domain ) }</h2>
					<label>
						<input
							type="radio"
							name="batch"
							checked={ settings.batch_size === 'auto' }
							onChange={ () =>
								setSettings( {
									...settings,
									batch_size: 'auto',
								} )
							}
						/>{ ' ' }
						{ __( 'Auto, recommended', domain ) }
					</label>
					<label>
						<input
							type="radio"
							name="batch"
							checked={ settings.batch_size !== 'auto' }
							onChange={ () =>
								setSettings( {
									...settings,
									batch_size: Number( customBatch ),
								} )
							}
						/>{ ' ' }
						{ __( 'Override', domain ) }{ ' ' }
						<input
							className="safesr-batch-input"
							type="number"
							min="50"
							max="5000"
							value={ customBatch }
							onChange={ ( event ) => {
								setCustomBatch( event.target.value );
								setSettings( {
									...settings,
									batch_size: Number( event.target.value ),
								} );
							} }
						/>
					</label>
				</div>
				<div className="safesr-settings-card">
					<h2>{ __( 'Change logs', domain ) }</h2>
					<label className="safesr-log-toggle">
						<span>
							{ __(
								'Keep downloadable preview excerpts',
								domain
							) }
						</span>
						<input
							type="checkbox"
							role="switch"
							checked={ settings.keep_logs }
							onChange={ ( event ) =>
								setSettings( {
									...settings,
									keep_logs: event.target.checked,
								} )
							}
						/>
					</label>
					{ lastJob?.summary.log_available === true && (
						<a href={ logUrl( lastJob.id ) }>
							{ __(
								'↓ Download last preview excerpts (CSV)',
								domain
							) }
						</a>
					) }
				</div>
				<div className="safesr-settings-card full">
					<h2>{ __( 'Regex quick reference', domain ) }</h2>
					<div className="safesr-regex-grid">
						<p>
							<code>\d+</code>
							{ __( 'one or more digits', domain ) }
						</p>
						<p>
							<code>^http:</code>
							{ __( 'starts with http:', domain ) }
						</p>
						<p>
							<code>(\.jpg|\.png)</code>
							{ __( 'either extension', domain ) }
						</p>
						<p>
							<code>$1</code>
							{ __( 'captured group in replace', domain ) }
						</p>
					</div>
				</div>
			</div>
			<div className="safesr-settings-save">
				<span role="status">{ status }</span>
				<button
					type="button"
					className="safesr-button primary"
					onClick={ () =>
						client
							.updateSettings( settings )
							.then( ( value ) => {
								setSettings( value );
								setStatus( __( 'Settings saved.', domain ) );
							} )
							.catch( ( error ) =>
								setStatus( errorMessage( error ) )
							)
					}
				>
					{ __( 'Save settings', domain ) }
				</button>
			</div>
		</section>
	);
}

function ProScreen() {
	const rows = [
		[ __( 'Find and replace text', domain ), '✓', '✓' ],
		[ __( 'Visual diff preview', domain ), '✓', '✓' ],
		[ __( 'Serialized and Elementor-safe', domain ), '✓', '✓' ],
		[
			__( 'Automatic backup', domain ),
			__( 'Local', domain ),
			__( 'Off-site', domain ),
		],
		[ __( 'Move media and files', domain ), '−', '✓' ],
		[ __( 'Full one-click site migration', domain ), '−', '✓' ],
		[ __( 'Scheduled backups and rollback', domain ), '−', '✓' ],
	];
	return (
		<section className="safesr-screen">
			<div className="safesr-title">
				<h1>{ __( 'Pro Tools', domain ) }</h1>
				<p>
					{ __(
						'You may not need this. Search and replace is free and complete. For a whole-site move, here is the honest comparison.',
						domain
					) }
				</p>
			</div>
			<div className="safesr-comparison">
				<div className="safesr-comparison-row heading">
					<strong></strong>
					<strong>
						{ __( 'This plugin', domain ) }
						<small>{ __( 'Free · manual', domain ) }</small>
					</strong>
					<strong>
						{ __( 'SafeGuard', domain ) }
						<small>{ __( 'Full migration', domain ) }</small>
					</strong>
				</div>
				{ rows.map( ( row ) => (
					<div className="safesr-comparison-row" key={ row[ 0 ] }>
						<span>{ row[ 0 ] }</span>
						<span>{ row[ 1 ] }</span>
						<strong>{ row[ 2 ] }</strong>
					</div>
				) ) }
				<footer>
					<p>
						{ __(
							'Try scheduled off-site backups and full migrations for 14 days. Search and replace remains free here.',
							domain
						) }
					</p>
					<a
						className="safesr-button primary"
						href={ bootData().proUrl }
						target="_blank"
						rel="noopener"
					>
						{ __( 'Start free trial', domain ) }
					</a>
				</footer>
			</div>
		</section>
	);
}

export function App( { client = defaultClient }: { client?: ApiClient } ) {
	const [ state, dispatch ] = useReducer( reducer, initialState );
	const [ tables, setTables ] = useState< Table[] >( [] );
	const [ theme, setTheme ] = useState< 'light' | 'dark' >( () =>
		localStorage.getItem( 'safesr-theme' ) === 'dark' ? 'dark' : 'light'
	);

	// The app canvas only paints its own box, so overscroll and any area below
	// the content reveal wp-admin's own background. Paint the scroll roots to
	// match the active theme, and restore them when leaving the screen.
	useEffect( () => {
		const canvas = theme === 'dark' ? '#080d13' : '#eef1f3';
		const roots = [ document.documentElement, document.body ];
		const previous = roots.map( ( node ) => node.style.background );
		roots.forEach( ( node ) => {
			node.style.background = canvas;
		} );
		return () => {
			roots.forEach( ( node, index ) => {
				node.style.background = previous[ index ];
			} );
		};
	}, [ theme ] );

	useEffect( () => {
		client
			.getTables()
			.then( ( response ) => {
				setTables( response.tables );
				dispatch( {
					type: 'FORM_UPDATED',
					form: { tables: response.defaults },
				} );
			} )
			.catch( ( error ) =>
				dispatch( {
					type: 'REQUEST_FAILED',
					message: errorMessage( error ),
				} )
			);
	}, [ client ] );

	const waitForCompletion = useCallback(
		async ( jobId: string ): Promise< Job > => {
			let delay = 750;
			for (;;) {
				const job = await client.getJob( jobId );
				if (
					[ 'completed', 'failed', 'canceled' ].includes( job.status )
				) {
					return job;
				}
				await new Promise( ( resolve ) =>
					setTimeout( resolve, delay )
				);
				delay = Math.min( 3000, delay * 1.35 );
			}
		},
		[ client ]
	);

	const preview = async () => {
		dispatch( { type: 'PREVIEW_STARTED' } );
		try {
			const created = await client.createPreview(
				toRunRequest( state.form )
			);
			const job = await waitForCompletion( created.job_id );
			if ( job.status !== 'completed' ) {
				throw new Error(
					job.error || __( 'The preview did not complete.', domain )
				);
			}
			dispatch( { type: 'JOB_UPDATED', job } );
			dispatch( { type: 'PREVIEW_READY', jobId: created.job_id } );
		} catch ( error ) {
			dispatch( {
				type: 'REQUEST_FAILED',
				message: errorMessage( error ),
			} );
		}
	};

	const apply = async ( createBackup: boolean ) => {
		try {
			if ( ! state.previewJobId ) {
				throw new Error(
					__( 'Run a preview before applying changes.', domain )
				);
			}
			const created = await client.createApply( {
				...toRunRequest( state.form ),
				preview_job_id: state.previewJobId,
				create_backup: createBackup,
			} );
			dispatch( { type: 'APPLY_STARTED', jobId: created.job_id } );
		} catch ( error ) {
			dispatch( {
				type: 'REQUEST_FAILED',
				message: errorMessage( error ),
			} );
		}
	};

	const undo = async () => {
		if ( ! state.completedJob ) {
			return;
		}
		try {
			const created = await client.undoJob( state.completedJob.id );
			dispatch( { type: 'UNDO_STARTED', jobId: created.job_id } );
		} catch ( error ) {
			dispatch( {
				type: 'REQUEST_FAILED',
				message: errorMessage( error ),
			} );
		}
	};

	const handleTerminal = useCallback( ( job: Job ) => {
		if ( job.status !== 'completed' ) {
			dispatch( {
				type: 'REQUEST_FAILED',
				message:
					job.error ||
					__(
						'The background job stopped before completion.',
						domain
					),
			} );
			return;
		}
		dispatch(
			job.type === 'undo'
				? { type: 'UNDO_COMPLETED', job }
				: { type: 'JOB_COMPLETED', job }
		);
	}, [] );

	const reapply = async () => {
		try {
			if ( ! state.previewJobId ) {
				throw new Error(
					__( 'Run a preview before applying changes.', domain )
				);
			}
			const created = await client.createApply( {
				...toRunRequest( state.form ),
				preview_job_id: state.previewJobId,
			} );
			dispatch( { type: 'REAPPLY_STARTED', jobId: created.job_id } );
		} catch ( error ) {
			dispatch( {
				type: 'REQUEST_FAILED',
				message: errorMessage( error ),
			} );
		}
	};

	const selectTab = ( tab: Tab ) => dispatch( { type: 'TAB_SELECTED', tab } );
	return (
		<div className={ `safesr-app${ theme === 'dark' ? ' dark' : '' }` }>
			<Header
				tab={ state.tab }
				theme={ theme }
				onTab={ selectTab }
				onTheme={ () => {
					const next = theme === 'light' ? 'dark' : 'light';
					localStorage.setItem( 'safesr-theme', next );
					setTheme( next );
				} }
			/>
			<main className="safesr-main">
				{ state.tab === 'history' && <History client={ client } /> }
				{ state.tab === 'help' && <Help /> }
				{ state.tab === 'replace' && state.screen === 'form' && (
					<SearchForm
						form={ state.form }
						tables={ tables }
						busy={ state.busy }
						error={ state.error }
						onChange={ ( form ) =>
							dispatch( { type: 'FORM_UPDATED', form } )
						}
						onSubmit={ preview }
					/>
				) }
				{ state.tab === 'replace' &&
					state.screen === 'diff' &&
					state.previewJobId && (
						<DiffScreen
							client={ client }
							job={ state.completedJob }
							jobId={ state.previewJobId }
							mode={ state.diffMode }
							onMode={ ( mode ) =>
								dispatch( { type: 'DIFF_MODE_CHANGED', mode } )
							}
							onCancel={ () =>
								dispatch( { type: 'DIFF_CANCELED' } )
							}
							onApply={ () =>
								dispatch( { type: 'CONFIRM_OPENED' } )
							}
							onError={ ( message ) =>
								dispatch( { type: 'REQUEST_FAILED', message } )
							}
						/>
					) }
				{ state.tab === 'replace' &&
					state.screen === 'progress' &&
					state.activeJobId && (
						<Progress
							jobId={ state.activeJobId }
							getJob={ client.getJob }
							onTerminal={ handleTerminal }
							onError={ ( message ) =>
								dispatch( { type: 'REQUEST_FAILED', message } )
							}
						/>
					) }
				{ state.tab === 'replace' &&
					( state.screen === 'success' ||
						state.screen === 'undone' ) &&
					state.completedJob && (
						<SuccessScreen
							job={ state.completedJob }
							undone={ state.screen === 'undone' }
							onUndo={ undo }
							onReapply={ reapply }
							onNew={ () => dispatch( { type: 'NEW_SEARCH' } ) }
						/>
					) }
				{ state.tab === 'settings' && (
					<SettingsScreen
						client={ client }
						tables={ tables }
						lastJob={
							state.completedJob?.type === 'apply'
								? state.completedJob
								: null
						}
					/>
				) }
				{ state.tab === 'pro' && <ProScreen /> }
			</main>
			{ state.confirmOpen && (
				<ConfirmModal
					changeCount={ state.completedJob?.progress.matches ?? 0 }
					onClose={ () => dispatch( { type: 'CONFIRM_CLOSED' } ) }
					onConfirm={ ( snapshot ) => void apply( snapshot ) }
				/>
			) }
		</div>
	);
}
