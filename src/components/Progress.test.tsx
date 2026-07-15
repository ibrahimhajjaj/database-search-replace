import { act, render, screen } from '@testing-library/react';

import { Progress } from './Progress';

describe( 'Progress', () => {
	beforeEach( () => jest.useFakeTimers() );
	afterEach( () => {
		jest.runOnlyPendingTimers();
		jest.useRealTimers();
	} );

	it( 'polls until a completed job and then stops', async () => {
		const queued = {
			id: 'job',
			type: 'apply' as const,
			status: 'running' as const,
			progress: {
				tables_total: 2,
				tables_done: 1,
				current_table: 'wp_posts',
				current_cursor: null,
				rows_scanned: 4,
				matches: 2,
				replacements: 2,
				skipped: 0,
				last_run_at: null,
			},
			summary: {},
			backup_id: 'backup',
			has_backup: true,
			undone: false,
			error: null,
			created_at: '',
			started_at: '',
			finished_at: null,
		};
		const completed = {
			...queued,
			status: 'completed' as const,
			finished_at: '2026-07-14',
		};
		const getJob = jest
			.fn()
			.mockResolvedValueOnce( queued )
			.mockResolvedValueOnce( completed );
		const onTerminal = jest.fn();
		render(
			<Progress jobId="job" getJob={ getJob } onTerminal={ onTerminal } />
		);

		await act( async () => {} );
		expect( getJob ).toHaveBeenCalledTimes( 1 );
		await act( async () => {
			jest.advanceTimersByTime( 1500 );
			await Promise.resolve();
		} );
		expect( getJob ).toHaveBeenCalledTimes( 2 );
		expect( onTerminal ).toHaveBeenCalledWith( completed );
		act( () => jest.advanceTimersByTime( 10000 ) );
		expect( getJob ).toHaveBeenCalledTimes( 2 );
		expect( screen.getByText( /processing table/i ) ).toHaveAttribute(
			'aria-live',
			'polite'
		);
	} );
} );
