import { render, screen } from '@testing-library/react';

import { DiffRow } from './DiffRow';

const change = {
	id: '1',
	job_id: 'job',
	table_name: 'wp_options',
	column_name: 'siteurl',
	row_pk: 'option_id=1',
	before_excerpt: [
		{ t: 'http', k: 'ctx' as const },
		{ t: '://old', k: 'del' as const },
	],
	after_excerpt: [
		{ t: 'http', k: 'ctx' as const },
		{ t: 's://new', k: 'add' as const },
	],
	formats: [ 'plain' ],
	skipped: false,
	skip_reason: '',
	created_at: '2026-07-14',
};

describe( 'DiffRow', () => {
	it( 'renders context, removed, and added segments with text markers', () => {
		render( <DiffRow change={ change } mode="split" /> );

		expect( screen.getByText( '− Before' ) ).toBeInTheDocument();
		expect( screen.getByText( '+ After' ) ).toBeInTheDocument();
		expect( screen.getByText( '://old' ) ).toHaveClass(
			'safesr-segment-del'
		);
		expect( screen.getByText( 's://new' ) ).toHaveClass(
			'safesr-segment-add'
		);
	} );

	it( 'renders a muted safety message for skipped rows', () => {
		render(
			<DiffRow
				change={ {
					...change,
					skipped: true,
					skip_reason: 'protected_table',
				} }
				mode="inline"
			/>
		);

		expect( screen.getByText( /protected table/i ) ).toBeInTheDocument();
		expect( screen.getByText( /shield/i ) ).toBeInTheDocument();
	} );

	it( 'announces when an excerpt was truncated', () => {
		render(
			<DiffRow change={ { ...change, truncated: true } } mode="split" />
		);

		expect( screen.getByText( /excerpt shortened/i ) ).toBeInTheDocument();
	} );
} );
