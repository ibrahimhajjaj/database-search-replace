import { render, screen } from '@testing-library/react';

import { Help } from './Help';

describe( 'Help', () => {
	it( 'renders the WP-CLI commands', () => {
		render( <Help /> );

		expect(
			screen.getByText(
				"wp safesr replace '<search>' '<replace>' --dry-run"
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( "wp safesr replace '<search>' '<replace>'" )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'wp safesr undo <job-id>' )
		).toBeInTheDocument();
		expect( screen.getByText( 'wp safesr jobs' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Flags' ) ).toBeInTheDocument();
		expect( screen.getAllByTestId( 'cli-flag' ) ).toHaveLength( 8 );
		expect(
			screen.getByRole( 'heading', {
				name: /serialized & elementor-safe/i,
			} )
		).toBeInTheDocument();
	} );
} );
