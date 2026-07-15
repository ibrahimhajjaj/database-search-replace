import { initialState, reducer } from './reducer';

describe( 'admin state machine', () => {
	it( 'selects the history and help tabs', () => {
		const history = reducer( initialState, {
			type: 'TAB_SELECTED',
			tab: 'history',
		} );
		const help = reducer( history, {
			type: 'TAB_SELECTED',
			tab: 'help',
		} );

		expect( history.tab ).toBe( 'history' );
		expect( help.tab ).toBe( 'help' );
	} );

	it( 'moves through preview, confirmation, progress, success, undo, and re-apply', () => {
		let state = {
			...initialState,
			form: {
				...initialState.form,
				search: 'old.example',
				replace: 'new.example',
			},
		};

		state = reducer( state, { type: 'PREVIEW_STARTED' } );
		state = reducer( state, {
			type: 'PREVIEW_READY',
			jobId: 'preview-id',
		} );
		expect( state.screen ).toBe( 'diff' );
		state = reducer( state, { type: 'CONFIRM_OPENED' } );
		expect( state.confirmOpen ).toBe( true );
		state = reducer( state, { type: 'APPLY_STARTED', jobId: 'apply-id' } );
		expect( state.screen ).toBe( 'progress' );
		state = reducer( state, { type: 'JOB_COMPLETED' } );
		expect( state.screen ).toBe( 'success' );
		state = reducer( state, { type: 'UNDO_STARTED', jobId: 'undo-id' } );
		state = reducer( state, { type: 'UNDO_COMPLETED' } );
		expect( state.screen ).toBe( 'undone' );
		state = reducer( state, {
			type: 'REAPPLY_STARTED',
			jobId: 'reapply-id',
		} );
		expect( state.screen ).toBe( 'progress' );
	} );

	it( 'returns from the diff without clearing form values', () => {
		const form = {
			...initialState.form,
			search: 'keep me',
			replace: 'and me',
		};
		const diff = reducer(
			{ ...initialState, form },
			{ type: 'PREVIEW_READY', jobId: 'preview-id' }
		);
		const result = reducer( diff, { type: 'DIFF_CANCELED' } );

		expect( result.screen ).toBe( 'form' );
		expect( result.form ).toEqual( form );
	} );
} );
