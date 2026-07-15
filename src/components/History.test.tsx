import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import type { ApiClient, RunSummary } from '../api/client';
import { History } from './History';

const run: RunSummary & { undoable: boolean } = {
	id: '0123456789abcdef0123456789abcdef',
	type: 'apply',
	run_kind: 'apply',
	status: 'completed',
	created_at: '2026-07-14 12:00:00',
	finished_at: '2026-07-14 12:00:02',
	search: 'old.example',
	replace: 'new.example',
	tables: 3,
	replacements: 12,
	matches: 13,
	skipped: 1,
	has_backup: true,
	undoable: true,
	undone: false,
	undone_at: null,
};

function client( items: RunSummary[], total = items.length ): ApiClient {
	return {
		listJobs: jest.fn().mockResolvedValue( { items, total } ),
		getChanges: jest.fn(),
		undoJob: jest.fn(),
		getJob: jest.fn(),
	} as unknown as ApiClient;
}

describe( 'History', () => {
	it( 'renders preview, applied, and undone states with undo only when eligible', async () => {
		render(
			<History
				client={ client( [
					{
						...run,
						id: '4123456789abcdef0123456789abcdef',
						type: 'preview',
						run_kind: 'preview',
						has_backup: false,
						undoable: false,
					},
					run,
					{
						...run,
						id: '3123456789abcdef0123456789abcdef',
						undoable: false,
						undone: true,
						undone_at: '2026-07-14 12:05:00',
					},
				] ) }
			/>
		);

		await waitFor( () =>
			expect( screen.getAllByRole( 'row' ) ).toHaveLength( 4 )
		);
		const undo = screen.getAllByRole( 'button', { name: /undo/i } );
		expect( undo ).toHaveLength( 1 );
		expect( undo[ 0 ] ).toBeEnabled();
		expect( screen.getByText( /preview only/i ) ).toBeInTheDocument();
		expect( screen.getByText( /^applied$/i ) ).toBeInTheDocument();
		expect( screen.getByText( /undone/i ) ).toBeInTheDocument();
		expect( screen.getByText( /13 matches/i ) ).toBeInTheDocument();
	} );

	it( 'requests history pages and renders numbered pagination with disabled ends', async () => {
		const user = userEvent.setup();
		const api = client( [ run ], 320 );
		render( <History client={ api } /> );

		await screen.findByText( /1.+10 of 320 runs/i );
		expect(
			screen.getByRole( 'button', { name: /previous page/i } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: /page 32/i } )
		).toBeEnabled();
		expect( screen.getByText( '…' ) ).toBeInTheDocument();
		await user.click( screen.getByRole( 'button', { name: /page 2/i } ) );
		await waitFor( () =>
			expect( api.listJobs ).toHaveBeenLastCalledWith( {
				type: 'history',
				page: 2,
				per_page: 10,
			} )
		);
	} );

	it( 'renders a calm empty state', async () => {
		render( <History client={ client( [] ) } /> );

		expect(
			await screen.findByText(
				/No runs yet\. Your previews and applied replaces will show up here\./i
			)
		).toBeInTheDocument();
	} );
} );
