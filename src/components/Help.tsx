/* eslint-disable @wordpress/i18n-text-domain */
import { __ } from '@wordpress/i18n';

const domain = 'database-search-replace';

const flags = [
	[
		__( '--regex', domain ),
		__( 'Treat the search value as a regular expression.', domain ),
	],
	[
		__( '--case-sensitive', domain ),
		__( 'Match uppercase and lowercase exactly.', domain ),
	],
	[
		__( '--tables=<csv>', domain ),
		__( 'Limit the run to named database tables.', domain ),
	],
	[
		__( '--include-guids', domain ),
		__( 'Allow changes to post GUID values.', domain ),
	],
	[
		__( '--thorough', domain ),
		__( 'Scan every row instead of using a SQL prefilter.', domain ),
	],
	[
		__( '--exclude=<csv>', domain ),
		__( 'Protect matching literal values from replacement.', domain ),
	],
	[
		__( '--batch-size=<n>', domain ),
		__( 'Set the maximum rows processed in each batch.', domain ),
	],
	[
		__( '--yes', domain ),
		__( 'Skip the confirmation prompt for an apply run.', domain ),
	],
];

export function Help() {
	return (
		<section className="safesr-screen">
			<div className="safesr-title">
				<h1>{ __( 'Help', domain ) }</h1>
				<p>
					{ __(
						'Operational notes for safe database changes.',
						domain
					) }
				</p>
			</div>
			<div className="safesr-help-grid">
				<article className="safesr-help-cli">
					<header>
						<h2>{ __( 'Command line (WP-CLI)', domain ) }</h2>
						<p>
							{ __(
								'Run these from your WordPress directory. The CLI uses the same snapshot and undo path as the admin.',
								domain
							) }
						</p>
					</header>
					<div className="safesr-cli-console">
						<p aria-hidden="true">
							<span>$</span> <b>wp safesr replace</b>{ ' ' }
							<i>
								&#39;&lt;search&gt;&#39;
								&#39;&lt;replace&gt;&#39;
							</i>{ ' ' }
							<em>--dry-run</em>
						</p>
						<span className="safesr-visually-hidden">
							{ __(
								"wp safesr replace '<search>' '<replace>' --dry-run",
								domain
							) }
						</span>
						<p aria-hidden="true">
							<span>$</span> <b>wp safesr replace</b>{ ' ' }
							<i>
								&#39;&lt;search&gt;&#39;
								&#39;&lt;replace&gt;&#39;
							</i>
						</p>
						<span className="safesr-visually-hidden">
							{ __(
								"wp safesr replace '<search>' '<replace>'",
								domain
							) }
						</span>
						<p aria-hidden="true">
							<span>$</span> <b>wp safesr undo</b>{ ' ' }
							<i>&lt;job-id&gt;</i>
						</p>
						<span className="safesr-visually-hidden">
							{ __( 'wp safesr undo <job-id>', domain ) }
						</span>
						<p aria-hidden="true">
							<span>$</span> <b>wp safesr jobs</b>
						</p>
					</div>
					<div className="safesr-help-cli-body">
						<h3>{ __( 'Flags', domain ) }</h3>
						<div className="safesr-help-flags">
							{ flags.map( ( [ flag, description ] ) => (
								<p key={ flag } data-testid="cli-flag">
									<code>{ flag }</code>
									<span>{ description }</span>
								</p>
							) ) }
						</div>
						<p className="safesr-help-cli-note">
							{ __(
								'For very large databases, run it in the background instead of holding a browser open.',
								domain
							) }
						</p>
					</div>
				</article>
				<div className="safesr-help-notes">
					<article className="safesr-help-card">
						<h2>
							<span aria-hidden="true">🛡</span>
							{ __( "What's protected by default", domain ) }
						</h2>
						<p>
							<strong>
								{ __(
									'Users and usermeta are never written',
									domain
								) }
							</strong>
							{ __( ', which prevents login lockouts.', domain ) }{ ' ' }
							<strong>
								{ __( 'Post GUIDs stay unchanged', domain ) }
							</strong>{ ' ' }
							{ __(
								'unless you opt in. Changing them can detach comments and re-notify feed subscribers.',
								domain
							) }
						</p>
					</article>
					<article className="safesr-help-card">
						<h2>
							<span aria-hidden="true">↺</span>
							{ __( 'Snapshots and undo', domain ) }
						</h2>
						<p>
							{ __(
								'Before the first write, affected rows are snapshotted. Undo restores them exactly, and you can re-apply afterward. Large runs can turn the snapshot off to save disk space, which disables undo for that run.',
								domain
							) }
						</p>
					</article>
					<article className="safesr-help-card is-accent">
						<h2>
							<span aria-hidden="true">✨</span>
							{ __( 'Serialized & Elementor-safe', domain ) }
						</h2>
						<p>
							{ __(
								'Plain search and replace can corrupt serialized length metadata. This engine',
								domain
							) }{ ' ' }
							<strong>
								{ __(
									'decodes and rebuilds serialized values',
									domain
								) }
							</strong>
							{ __(
								', including JSON inside serialized data with escaped slashes, and inspects base64-encoded structured values during a thorough scan.',
								domain
							) }
						</p>
					</article>
				</div>
			</div>
		</section>
	);
}
