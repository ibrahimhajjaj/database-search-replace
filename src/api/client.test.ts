import apiFetch from '@wordpress/api-fetch';

import { ApiError, createClient } from './client';

jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

describe( 'REST client', () => {
	beforeEach( () => mockedApiFetch.mockReset() );

	it( 'uses the controller paths, methods, and run body', async () => {
		mockedApiFetch.mockResolvedValue( { job_id: 'abc' } );
		const client = createClient();
		const run = {
			search: 'old',
			replace: 'new',
			case_sensitive: false,
			regex: false,
			tables: [ 'wp_posts' ],
			include_guids: false,
			thorough_scan: false,
			exclusions: [],
		};

		await client.createPreview( run );
		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/safesr/v1/preview',
			method: 'POST',
			data: run,
		} );
		await client.createApply( { ...run, preview_job_id: 'abc' } );
		expect( mockedApiFetch ).toHaveBeenLastCalledWith( {
			path: '/safesr/v1/apply',
			method: 'POST',
			data: { ...run, preview_job_id: 'abc' },
		} );
	} );

	it( 'passes change filters in the query string', async () => {
		mockedApiFetch.mockResolvedValue( {
			json: jest.fn().mockResolvedValue( [] ),
			headers: new Headers( {
				'X-WP-Total': '0',
				'X-WP-TotalPages': '0',
			} ),
		} );
		await createClient().getChanges( 'abc', {
			page: 2,
			per_page: 20,
			text: 'hero image',
		} );

		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/safesr/v1/jobs/abc/changes?page=2&per_page=20&text=hero%20image',
			parse: false,
		} );
	} );

	it( 'passes job list filters in the query string', async () => {
		mockedApiFetch.mockResolvedValue( { items: [], total: 0 } );

		await createClient().listJobs( {
			type: 'apply',
			page: 2,
			per_page: 10,
		} );

		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/safesr/v1/jobs?type=apply&page=2&per_page=10',
		} );
	} );

	it( 'requests preview and apply history pages', async () => {
		mockedApiFetch.mockResolvedValue( { items: [], total: 0 } );

		await createClient().listJobs( {
			type: 'history',
			page: 3,
			per_page: 10,
		} );

		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/safesr/v1/jobs?type=history&page=3&per_page=10',
		} );
	} );

	it( 'surfaces a preview validation message for inline form feedback', async () => {
		mockedApiFetch.mockRejectedValue( {
			code: 'safesr_invalid_request',
			message: 'Invalid regular expression.',
		} );

		await expect(
			createClient().createPreview( {
				search: '[',
				replace: '',
				case_sensitive: false,
				regex: true,
				tables: [],
				include_guids: false,
				thorough_scan: false,
				exclusions: [],
			} )
		).rejects.toEqual(
			new ApiError(
				'Invalid regular expression.',
				'safesr_invalid_request'
			)
		);
	} );
} );
