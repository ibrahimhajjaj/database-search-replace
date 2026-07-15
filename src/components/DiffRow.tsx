import { __ } from '@wordpress/i18n';

import type { Change, Segment } from '../api/client';
import type { DiffMode } from '../state/reducer';

type Props = { change: Change; mode: DiffMode };

function SegmentList( { segments }: { segments: Segment[] } ) {
	return (
		<>
			{ segments.map( ( segment, index ) => (
				<span
					key={ `${ segment.k }-${ index }` }
					className={ `safesr-segment-${ segment.k }` }
				>
					{ segment.t }
				</span>
			) ) }
		</>
	);
}

function skipMessage( reason: string ): string {
	if ( reason === 'protected_table' ) {
		return __(
			'Shield: protected table, skipped to prevent login issues.',
			'database-search-replace'
		);
	}
	if ( reason === 'guid_excluded' ) {
		return __(
			'Info: GUID left unchanged to protect feeds and comments.',
			'database-search-replace'
		);
	}
	return __(
		'Info: this value could not be read and was skipped safely.',
		'database-search-replace'
	);
}

export function DiffRow( { change, mode }: Props ) {
	return (
		<div
			className={ `safesr-diff-row${
				change.skipped ? ' is-skipped' : ''
			}` }
		>
			<div className="safesr-row-meta">
				<strong>{ change.column_name }</strong>
				<span>{ change.row_pk }</span>
				{ change.formats.map( ( format ) => (
					<span className="safesr-badge" key={ format }>
						{ format }
					</span>
				) ) }
			</div>
			{ change.skipped ? (
				<div className="safesr-skip-row" role="note">
					<span aria-hidden="true">
						{ change.skip_reason === 'protected_table'
							? '🛡'
							: 'ⓘ' }
					</span>
					<div>
						<strong>{ skipMessage( change.skip_reason ) }</strong>
						<div className="safesr-skip-value">
							<SegmentList segments={ change.before_excerpt } />
						</div>
					</div>
				</div>
			) : (
				<div className={ `safesr-diff-values is-${ mode }` }>
					<div className="safesr-before">
						<div className="safesr-diff-label">
							{ __( '− Before', 'database-search-replace' ) }
						</div>
						<code>
							<SegmentList segments={ change.before_excerpt } />
						</code>
					</div>
					<div className="safesr-after">
						<div className="safesr-diff-label">
							{ __( '+ After', 'database-search-replace' ) }
						</div>
						<code>
							<SegmentList segments={ change.after_excerpt } />
						</code>
					</div>
				</div>
			) }
			{ change.truncated && (
				<p className="safesr-truncated">
					{ __(
						'Excerpt shortened for display.',
						'database-search-replace'
					) }
				</p>
			) }
		</div>
	);
}
