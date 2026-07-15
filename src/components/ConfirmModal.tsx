/* eslint-disable @wordpress/i18n-translator-comments, jsx-a11y/label-has-associated-control, react/jsx-no-target-blank */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

type Props = {
	changeCount: number;
	onClose: () => void;
	onConfirm: ( createSnapshot: boolean ) => void;
};

export function ConfirmModal( { changeCount, onClose, onConfirm }: Props ) {
	const [ snapshot, setSnapshot ] = useState( true );
	const modal = useRef< HTMLDivElement >( null );

	useEffect( () => {
		const root = modal.current;
		const focusable = root?.querySelectorAll< HTMLElement >(
			'input, button, a[href]'
		);
		focusable?.[ 0 ]?.focus();
		const onKeyDown = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' ) {
				onClose();
			}
			if ( event.key === 'Tab' && focusable?.length ) {
				const first = focusable[ 0 ];
				const last = focusable[ focusable.length - 1 ];
				if (
					event.shiftKey &&
					root?.ownerDocument.activeElement === first
				) {
					event.preventDefault();
					last.focus();
				} else if (
					! event.shiftKey &&
					root?.ownerDocument.activeElement === last
				) {
					event.preventDefault();
					first.focus();
				}
			}
		};
		document.addEventListener( 'keydown', onKeyDown );
		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ onClose ] );

	return (
		<div
			className="safesr-modal-backdrop"
			role="presentation"
			onMouseDown={ ( event ) => {
				if ( event.target === event.currentTarget ) {
					onClose();
				}
			} }
		>
			<div
				className="safesr-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby="safesr-confirm-title"
				ref={ modal }
			>
				<div className="safesr-modal-body">
					<div className="safesr-modal-icon" aria-hidden="true">
						▣
					</div>
					<h2 id="safesr-confirm-title">
						{ __(
							'Ready when you are.',
							'database-search-replace'
						) }
					</h2>
					<p>
						{ sprintf(
							__(
								'We’ll create a safety snapshot first, then apply %s changes. You can undo this in one click afterward.',
								'database-search-replace'
							),
							changeCount.toLocaleString()
						) }
					</p>
					<label className="safesr-snapshot-choice">
						<input
							type="checkbox"
							checked={ snapshot }
							onChange={ ( event ) =>
								setSnapshot( event.target.checked )
							}
						/>
						<span>
							<strong>
								{ __(
									'Create a safety snapshot before applying',
									'database-search-replace'
								) }
							</strong>
							<small>
								{ __(
									'Stores the affected rows locally before any write.',
									'database-search-replace'
								) }
							</small>
						</span>
					</label>
					{ ! snapshot && (
						<p className="safesr-snapshot-warning">
							{ __(
								'Applying without a snapshot disables one-click undo for this run.',
								'database-search-replace'
							) }
						</p>
					) }
					<a
						className="safesr-link-button safesr-pro-line"
						href="https://safeguard.verdelic.com/migrate?utm_source=ssr-plugin&utm_medium=preflight"
						target="_blank"
						rel="noopener"
					>
						{ __(
							'Back up the whole site with SafeGuard first.',
							'database-search-replace'
						) }
					</a>
				</div>
				<div className="safesr-modal-actions">
					<button
						type="button"
						className="safesr-button secondary"
						onClick={ onClose }
					>
						{ __( 'Cancel', 'database-search-replace' ) }
					</button>
					<button
						type="button"
						className="safesr-button primary"
						onClick={ () => onConfirm( snapshot ) }
					>
						{ snapshot
							? __( 'Back up & apply', 'database-search-replace' )
							: __( 'Apply changes', 'database-search-replace' ) }
					</button>
				</div>
			</div>
		</div>
	);
}
