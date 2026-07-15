import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { App, persistReviewDismissal, SuccessScreen } from './App';
import { ApiError, type ApiClient, type Job } from './api/client';

function client(): ApiClient {
	return {
		getTables: jest.fn().mockResolvedValue( { tables: [], defaults: [] } ),
		listJobs: jest.fn().mockResolvedValue( { items: [], total: 0 } ),
		createPreview: jest
			.fn()
			.mockRejectedValue( new ApiError( 'Invalid regular expression.' ) ),
		createApply: jest.fn(),
		getJob: jest.fn(),
		getChanges: jest.fn(),
		cancelJob: jest.fn(),
		undoJob: jest.fn(),
		getSettings: jest.fn().mockResolvedValue( {
			protected_tables: [ 'wp_users' ],
			batch_size: 'auto',
			keep_logs: true,
		} ),
		updateSettings: jest.fn(),
	};
}

describe( 'App', () => {
	it( 'links the successful-run module to the attributed landing page', () => {
		const job: Job = {
			id: 'job-1',
			type: 'apply',
			status: 'completed',
			progress: {
				tables_total: 1,
				tables_done: 1,
				current_table: '',
				current_cursor: null,
				rows_scanned: 1,
				matches: 1,
				replacements: 1,
				skipped: 0,
				last_run_at: null,
			},
			summary: {},
			backup_id: 'backup-1',
			has_backup: true,
			undone: false,
			error: null,
			created_at: '2026-07-15 10:00:00',
			started_at: '2026-07-15 10:00:00',
			finished_at: '2026-07-15 10:00:01',
		};

		render(
			<SuccessScreen
				job={ job }
				undone={ false }
				onUndo={ jest.fn() }
				onReapply={ jest.fn() }
				onNew={ jest.fn() }
			/>
		);

		const link = screen.getByRole( 'link', {
			name: /compare migration tools/i,
		} );
		expect( link ).toHaveAttribute(
			'href',
			'https://safeguard.verdelic.com/migrate?utm_source=ssr-plugin&utm_medium=post-run'
		);
		expect( link ).toHaveAttribute( 'target', '_blank' );
		expect( link ).toHaveAttribute( 'rel', 'noopener' );
	} );

	it( 'links Pro Tools to the attributed SafeGuard landing page', async () => {
		const user = userEvent.setup();
		render( <App client={ client() } /> );

		await user.click(
			screen.getByRole( 'button', { name: /pro tools/i } )
		);

		expect(
			screen.getByRole( 'link', { name: /start free trial/i } )
		).toHaveAttribute(
			'href',
			'https://safeguard.verdelic.com/migrate?utm_source=ssr-plugin&utm_medium=pro-tools'
		);
		expect(
			screen.getByRole( 'link', { name: /start free trial/i } )
		).toHaveAttribute( 'rel', 'noopener' );
	} );

	it( 'shows preview validation failures beside the form', async () => {
		const user = userEvent.setup();
		render( <App client={ client() } /> );

		await user.type(
			screen.getByLabelText( /search for/i ),
			'invalid pattern'
		);
		await user.click(
			screen.getByRole( 'button', { name: /preview changes/i } )
		);

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Invalid regular expression.'
			)
		);
	} );

	it( 'persists a dismissed review nudge through WordPress user meta', async () => {
		window.safesrAdmin = {
			version: '0.1.0',
			logUrlBase: '/wp-json/safesr/v1/jobs/',
			restNonce: 'rest',
			ajaxUrl: '/wp-admin/admin-ajax.php',
			reviewNonce: 'review',
			reviewDismissed: false,
			proUrl: 'https://example.com',
			reviewUrl: 'https://example.com/review',
		};
		window.fetch = jest.fn().mockResolvedValue( {} );

		await persistReviewDismissal();

		expect( window.fetch ).toHaveBeenCalledWith(
			'/wp-admin/admin-ajax.php',
			expect.objectContaining( {
				method: 'POST',
				body: expect.stringContaining( 'closed=safesr-review-nudge' ),
			} )
		);
		delete window.safesrAdmin;
	} );
} );
