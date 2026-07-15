import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { AdvancedPanel } from './AdvancedPanel';

const tables = [
	{
		name: 'wp_posts',
		rows: 12,
		size: 1024,
		protected: false,
		processable: true,
	},
	{
		name: 'wp_options',
		rows: 4,
		size: 512,
		protected: false,
		processable: true,
	},
	{
		name: 'wp_users',
		rows: 2,
		size: 256,
		protected: true,
		processable: true,
	},
];

describe( 'AdvancedPanel', () => {
	it( 'filters tables without changing the selected count', async () => {
		const user = userEvent.setup();
		render(
			<AdvancedPanel
				tables={ tables }
				value={ [ 'wp_posts', 'wp_options' ] }
				exclusions={ [] }
				onTablesChange={ jest.fn() }
				onExclusionsChange={ jest.fn() }
			/>
		);

		expect( screen.getByText( /2 of 3/i ) ).toBeInTheDocument();
		await user.type(
			screen.getByPlaceholderText( /filter tables/i ),
			'options'
		);
		expect( screen.getByText( 'wp_options' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'wp_posts' ) ).not.toBeInTheDocument();
		expect( screen.getByText( /2 of 3/i ) ).toBeInTheDocument();
	} );

	it( 'keeps protected tables disabled and supports all and none', async () => {
		const user = userEvent.setup();
		const onChange = jest.fn();
		render(
			<AdvancedPanel
				tables={ tables }
				value={ [ 'wp_posts' ] }
				exclusions={ [] }
				onTablesChange={ onChange }
				onExclusionsChange={ jest.fn() }
			/>
		);

		expect(
			screen.getByRole( 'checkbox', { name: /wp_users/i } )
		).toBeDisabled();
		await user.click( screen.getByRole( 'button', { name: /^all$/i } ) );
		expect( onChange ).toHaveBeenLastCalledWith( [
			'wp_posts',
			'wp_options',
		] );
		await user.click( screen.getByRole( 'button', { name: /^none$/i } ) );
		expect( onChange ).toHaveBeenLastCalledWith( [] );
	} );

	it( 'adds and removes exclusion chips', async () => {
		const user = userEvent.setup();
		const onChange = jest.fn();
		const { rerender } = render(
			<AdvancedPanel
				tables={ tables }
				value={ [] }
				exclusions={ [] }
				onTablesChange={ jest.fn() }
				onExclusionsChange={ onChange }
			/>
		);

		await user.type(
			screen.getByPlaceholderText( /add pattern/i ),
			'webhook{enter}'
		);
		expect( onChange ).toHaveBeenCalledWith( [ 'webhook' ] );
		rerender(
			<AdvancedPanel
				tables={ tables }
				value={ [] }
				exclusions={ [ 'webhook' ] }
				onTablesChange={ jest.fn() }
				onExclusionsChange={ onChange }
			/>
		);
		await user.click(
			screen.getByRole( 'button', { name: /remove webhook/i } )
		);
		expect( onChange ).toHaveBeenLastCalledWith( [] );
	} );
} );
