declare module '@wordpress/api-fetch' {
	type ApiFetchOptions = {
		path: string;
		method?: string;
		data?: unknown;
		parse?: boolean;
	};

	function apiFetch< T = unknown >( options: ApiFetchOptions ): Promise< T >;
	export default apiFetch;
}

declare module '*.scss';

interface Window {
	safesrAdmin?: {
		version: string;
		imagesUrl: string;
		logUrlBase: string;
		restNonce: string;
		ajaxUrl: string;
		reviewNonce: string;
		reviewDismissed: boolean;
		retentionDays: number;
		proUrl: string;
		reviewUrl: string;
	};
}
