/* eslint-disable @wordpress/i18n-translator-comments */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { Job } from '../api/client';

type Props = {
	jobId: string;
	getJob: ( id: string ) => Promise< Job >;
	onTerminal: ( job: Job ) => void;
	onError?: ( message: string ) => void;
	compact?: boolean;
};

const terminal = new Set( [ 'completed', 'failed', 'canceled' ] );

export function Progress( {
	jobId,
	getJob,
	onTerminal,
	onError,
	compact = false,
}: Props ) {
	const [ job, setJob ] = useState< Job | null >( null );

	useEffect( () => {
		let canceled = false;
		let timer: ReturnType< typeof setTimeout > | undefined;
		let delay = 1500;
		const poll = async () => {
			try {
				const next = await getJob( jobId );
				if ( canceled ) {
					return;
				}
				setJob( next );
				if ( terminal.has( next.status ) ) {
					onTerminal( next );
					return;
				}
				delay = 1500;
				timer = setTimeout( poll, delay );
			} catch ( error ) {
				if ( canceled ) {
					return;
				}
				delay = Math.min( delay * 1.6, 10000 );
				onError?.(
					error instanceof Error
						? error.message
						: __(
								'Progress could not be refreshed.',
								'database-search-replace'
						  )
				);
				timer = setTimeout( poll, delay );
			}
		};
		void poll();
		return () => {
			canceled = true;
			if ( timer ) {
				clearTimeout( timer );
			}
		};
	}, [ getJob, jobId, onError, onTerminal ] );

	const progress = job?.progress;
	const total = Math.max( 1, progress?.tables_total ?? 1 );
	const done = progress?.tables_done ?? 0;
	const percent = Math.min( 100, Math.round( ( done / total ) * 100 ) );
	if ( compact ) {
		return (
			<div className="safesr-history-restoring" aria-live="polite">
				<span className="safesr-spinner" aria-hidden="true" />
				<strong>
					{ __( 'Restoring…', 'database-search-replace' ) }
				</strong>
				<span>{ percent }%</span>
			</div>
		);
	}

	return (
		<div className="safesr-progress-card">
			<div className="safesr-spinner" aria-hidden="true" />
			<h1>
				{ job?.type === 'undo'
					? __(
							'Restoring your safety snapshot…',
							'database-search-replace'
					  )
					: __(
							'Applying changes safely…',
							'database-search-replace'
					  ) }
			</h1>
			<p>
				{ __(
					'The job is running safely in the background.',
					'database-search-replace'
				) }
			</p>
			<div
				className="safesr-progress-track"
				role="progressbar"
				aria-valuenow={ percent }
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
			>
				<span style={ { width: `${ percent }%` } } />
			</div>
			<div className="safesr-progress-status" aria-live="polite">
				<span aria-live="polite">
					{ sprintf(
						__(
							'Processing table %1$s of %2$s',
							'database-search-replace'
						),
						Math.min( done + 1, total ).toLocaleString(),
						total.toLocaleString()
					) }
				</span>
				<strong>{ percent }%</strong>
			</div>
			<code>
				{ sprintf(
					__( '%1$s rows scanned · %2$s', 'database-search-replace' ),
					( progress?.rows_scanned ?? 0 ).toLocaleString(),
					progress?.current_table ||
						__( 'Preparing…', 'database-search-replace' )
				) }
			</code>
			<div className="safesr-safe-leave">
				<strong>
					{ __(
						'You can safely leave this page.',
						'database-search-replace'
					) }
				</strong>{ ' ' }
				{ __(
					'The work keeps running in the background.',
					'database-search-replace'
				) }
			</div>
		</div>
	);
}
