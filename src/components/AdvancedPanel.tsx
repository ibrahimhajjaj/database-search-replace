/* eslint-disable @wordpress/i18n-translator-comments, jsx-a11y/label-has-associated-control */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { Table } from '../api/client';

type Props = {
	tables: Table[];
	value: string[];
	exclusions: string[];
	onTablesChange: ( tables: string[] ) => void;
	onExclusionsChange: ( exclusions: string[] ) => void;
	caseSensitive?: boolean;
	regex?: boolean;
	includeGuids?: boolean;
	thoroughScan?: boolean;
	onOptionChange?: (
		option: 'caseSensitive' | 'regex' | 'includeGuids' | 'thoroughScan',
		value: boolean
	) => void;
};

function sizeLabel( bytes: number ): string {
	if ( bytes < 1024 * 1024 ) {
		return `${ Math.max( 1, Math.round( bytes / 1024 ) ) } KB`;
	}
	return `${ ( bytes / 1024 / 1024 ).toFixed( 1 ) } MB`;
}

export function AdvancedPanel( props: Props ) {
	const [ draft, setDraft ] = useState( '' );
	const [ guidHelp, setGuidHelp ] = useState( false );
	const [ tableFilter, setTableFilter ] = useState( '' );
	const selectable = props.tables.filter(
		( table ) => table.processable && ! table.protected
	);
	const selectedCount = props.tables.filter( ( table ) =>
		props.value.includes( table.name )
	).length;
	const visibleTables = props.tables.filter( ( table ) =>
		table.name.toLowerCase().includes( tableFilter.trim().toLowerCase() )
	);

	const tableMark = ( table: Table, selected: boolean ): string => {
		if ( table.protected ) {
			return '🛡';
		}
		return selected ? '☑' : '☐';
	};

	const toggleTable = ( table: Table ) => {
		if ( table.protected || ! table.processable ) {
			return;
		}
		props.onTablesChange(
			props.value.includes( table.name )
				? props.value.filter( ( name ) => name !== table.name )
				: [ ...props.value, table.name ]
		);
	};

	const addExclusion = () => {
		const value = draft.trim();
		if ( value && ! props.exclusions.includes( value ) ) {
			props.onExclusionsChange( [ ...props.exclusions, value ] );
		}
		setDraft( '' );
	};

	const option = (
		name: 'caseSensitive' | 'regex' | 'includeGuids' | 'thoroughScan',
		label: string,
		help: string
	) => (
		<label className="safesr-option-card">
			<span>
				<strong>{ label }</strong>
				<small>{ help }</small>
			</span>
			<input
				type="checkbox"
				role="switch"
				checked={ Boolean( props[ name ] ) }
				onChange={ ( event ) =>
					props.onOptionChange?.( name, event.target.checked )
				}
			/>
		</label>
	);

	return (
		<div className="safesr-advanced-panel">
			<div className="safesr-table-picker">
				<div className="safesr-table-heading">
					<strong>
						{ __( 'Tables to search', 'database-search-replace' ) }{ ' ' }
						<span>
							{ sprintf(
								__(
									'· %1$d of %2$d',
									'database-search-replace'
								),
								selectedCount,
								props.tables.length
							) }
						</span>
					</strong>
					<span>
						<button
							type="button"
							className="safesr-link-button"
							onClick={ () =>
								props.onTablesChange(
									selectable.map( ( table ) => table.name )
								)
							}
						>
							{ __( 'All', 'database-search-replace' ) }
						</button>
						<span aria-hidden="true"> · </span>
						<button
							type="button"
							className="safesr-link-button"
							onClick={ () => props.onTablesChange( [] ) }
						>
							{ __( 'None', 'database-search-replace' ) }
						</button>
					</span>
				</div>
				<label className="safesr-table-filter">
					<span aria-hidden="true">⌕</span>
					<span className="safesr-visually-hidden">
						{ __( 'Filter tables', 'database-search-replace' ) }
					</span>
					<input
						value={ tableFilter }
						placeholder={ __(
							'Filter tables, e.g. wp_ or woo…',
							'database-search-replace'
						) }
						spellCheck={ false }
						onChange={ ( event ) =>
							setTableFilter( event.target.value )
						}
					/>
				</label>
				<div className="safesr-table-list">
					{ visibleTables.map( ( table ) => {
						const selected = props.value.includes( table.name );
						const disabled = table.protected || ! table.processable;
						const mark = tableMark( table, selected );
						return (
							<button
								type="button"
								role="checkbox"
								aria-checked={ selected }
								disabled={ disabled }
								className={ `${
									selected ? 'is-selected' : ''
								} ${ table.protected ? 'is-protected' : '' }` }
								key={ table.name }
								onClick={ () => toggleTable( table ) }
							>
								<span>
									<span
										className="safesr-table-mark"
										aria-hidden="true"
									>
										{ mark }
									</span>
									<code>{ table.name }</code>
									{ table.protected && (
										<span className="safesr-protected">
											{ __(
												'protected',
												'database-search-replace'
											) }
										</span>
									) }
								</span>
								<small>
									{ sprintf(
										__(
											'%1$s rows · %2$s',
											'database-search-replace'
										),
										table.rows.toLocaleString(),
										sizeLabel( table.size )
									) }
								</small>
							</button>
						);
					} ) }
				</div>
				<p className="safesr-table-helper">
					{ __(
						'Scroll for all tables. Protected tables (like',
						'database-search-replace'
					) }{ ' ' }
					<code>wp_users</code>
					{ __(
						') stay off. Change that in Settings.',
						'database-search-replace'
					) }
				</p>
			</div>
			<div className="safesr-option-grid">
				{ option(
					'caseSensitive',
					__( 'Case-sensitive', 'database-search-replace' ),
					__( 'Match exact casing', 'database-search-replace' )
				) }
				{ option(
					'regex',
					__( 'Regex', 'database-search-replace' ),
					__( 'Pattern matching', 'database-search-replace' )
				) }
				{ option(
					'thoroughScan',
					__( 'Thorough scan', 'database-search-replace' ),
					__( 'Inspect encoded values', 'database-search-replace' )
				) }
			</div>
			<div className="safesr-guid-option">
				<div>
					<strong>
						{ __( 'Replace GUIDs', 'database-search-replace' ) }
					</strong>
					<button
						type="button"
						className="safesr-help-button"
						aria-expanded={ guidHelp }
						onClick={ () => setGuidHelp( ! guidHelp ) }
					>
						{ __( 'why off?', 'database-search-replace' ) }
					</button>
					<p>
						{ __(
							'Changing post GUIDs can break feeds and comments. Leave this off unless you know you need it.',
							'database-search-replace'
						) }
					</p>
				</div>
				<input
					type="checkbox"
					role="switch"
					checked={ Boolean( props.includeGuids ) }
					onChange={ ( event ) =>
						props.onOptionChange?.(
							'includeGuids',
							event.target.checked
						)
					}
				/>
				{ guidHelp && (
					<div className="safesr-guid-help" role="tooltip">
						{ __(
							'A GUID is a permanent post identifier. Feed readers and comment systems use it to recognize a post, even after its public URL changes.',
							'database-search-replace'
						) }
					</div>
				) }
			</div>
			<label
				className="safesr-exclusion-label"
				htmlFor="safesr-exclusion"
			>
				{ __( 'Exclusions', 'database-search-replace' ) }{ ' ' }
				<span>
					{ __(
						'never touch matching text',
						'database-search-replace'
					) }
				</span>
			</label>
			<div className="safesr-chip-input">
				{ props.exclusions.map( ( exclusion ) => (
					<span className="safesr-chip" key={ exclusion }>
						{ exclusion }
						<button
							type="button"
							aria-label={ sprintf(
								__( 'Remove %s', 'database-search-replace' ),
								exclusion
							) }
							onClick={ () =>
								props.onExclusionsChange(
									props.exclusions.filter(
										( value ) => value !== exclusion
									)
								)
							}
						>
							×
						</button>
					</span>
				) ) }
				<input
					id="safesr-exclusion"
					value={ draft }
					placeholder={ __(
						'add pattern, press Enter…',
						'database-search-replace'
					) }
					onChange={ ( event ) => setDraft( event.target.value ) }
					onKeyDown={ ( event ) => {
						if ( event.key === 'Enter' ) {
							event.preventDefault();
							addExclusion();
						}
					} }
				/>
			</div>
		</div>
	);
}
