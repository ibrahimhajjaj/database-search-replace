import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ConfirmModal } from './ConfirmModal';

describe( 'ConfirmModal', () => {
	it( 'links the backup prompt to the SafeGuard preflight landing page', () => {
		render(
			<ConfirmModal
				changeCount={ 4 }
				onClose={ jest.fn() }
				onConfirm={ jest.fn() }
			/>
		);

		expect(
			screen.getByRole( 'link', { name: /back up.*safeguard/i } )
		).toHaveAttribute(
			'href',
			'https://safeguard.verdelic.com/migrate?utm_source=ssr-plugin&utm_medium=preflight'
		);
		expect(
			screen.getByRole( 'link', { name: /back up.*safeguard/i } )
		).toHaveAttribute( 'target', '_blank' );
		expect(
			screen.getByRole( 'link', { name: /back up.*safeguard/i } )
		).toHaveAttribute( 'rel', 'noopener' );
	} );

	it( 'traps focus and closes with Escape', async () => {
		const user = userEvent.setup();
		const onClose = jest.fn();
		render(
			<ConfirmModal
				changeCount={ 4 }
				onClose={ onClose }
				onConfirm={ jest.fn() }
			/>
		);

		expect(
			screen.getByRole( 'checkbox', { name: /safety snapshot/i } )
		).toHaveFocus();
		await user.tab();
		await user.tab();
		await user.tab();
		await user.tab();
		expect(
			screen.getByRole( 'checkbox', { name: /safety snapshot/i } )
		).toHaveFocus();
		await user.keyboard( '{Escape}' );
		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'warns when the safety snapshot is unchecked', async () => {
		const user = userEvent.setup();
		render(
			<ConfirmModal
				changeCount={ 4 }
				onClose={ jest.fn() }
				onConfirm={ jest.fn() }
			/>
		);

		await user.click(
			screen.getByRole( 'checkbox', { name: /safety snapshot/i } )
		);
		expect(
			screen.getByText( /disables one-click undo for this run/i )
		).toBeInTheDocument();
	} );
} );
