import type { Job } from '../api/client';

export type Tab = 'replace' | 'history' | 'settings' | 'help' | 'pro';
export type Screen = 'form' | 'diff' | 'progress' | 'success' | 'undone';
export type DiffMode = 'split' | 'inline';

export type FormState = {
	search: string;
	replace: string;
	caseSensitive: boolean;
	regex: boolean;
	includeGuids: boolean;
	thoroughScan: boolean;
	tables: string[];
	exclusions: string[];
};

export type State = {
	tab: Tab;
	screen: Screen;
	diffMode: DiffMode;
	confirmOpen: boolean;
	previewJobId: string | null;
	activeJobId: string | null;
	completedJob: Job | null;
	form: FormState;
	busy: boolean;
	error: string;
};

export type Action =
	| { type: 'TAB_SELECTED'; tab: Tab }
	| { type: 'FORM_UPDATED'; form: Partial< FormState > }
	| { type: 'PREVIEW_STARTED' }
	| { type: 'PREVIEW_READY'; jobId: string }
	| { type: 'REQUEST_FAILED'; message: string }
	| { type: 'DIFF_CANCELED' }
	| { type: 'DIFF_MODE_CHANGED'; mode: DiffMode }
	| { type: 'CONFIRM_OPENED' }
	| { type: 'CONFIRM_CLOSED' }
	| { type: 'APPLY_STARTED'; jobId: string }
	| { type: 'JOB_UPDATED'; job: Job }
	| { type: 'JOB_COMPLETED'; job?: Job }
	| { type: 'UNDO_STARTED'; jobId: string }
	| { type: 'UNDO_COMPLETED'; job?: Job }
	| { type: 'REAPPLY_STARTED'; jobId: string }
	| { type: 'NEW_SEARCH' };

export const initialState: State = {
	tab: 'replace',
	screen: 'form',
	diffMode: 'split',
	confirmOpen: false,
	previewJobId: null,
	activeJobId: null,
	completedJob: null,
	busy: false,
	error: '',
	form: {
		search: '',
		replace: '',
		caseSensitive: false,
		regex: false,
		includeGuids: false,
		thoroughScan: false,
		tables: [],
		exclusions: [],
	},
};

export function reducer( state: State, action: Action ): State {
	switch ( action.type ) {
		case 'TAB_SELECTED':
			return { ...state, tab: action.tab, confirmOpen: false };
		case 'FORM_UPDATED':
			return {
				...state,
				form: { ...state.form, ...action.form },
				error: '',
			};
		case 'PREVIEW_STARTED':
			return { ...state, busy: true, error: '' };
		case 'PREVIEW_READY':
			return {
				...state,
				screen: 'diff',
				previewJobId: action.jobId,
				activeJobId: null,
				busy: false,
				error: '',
			};
		case 'REQUEST_FAILED':
			return {
				...state,
				busy: false,
				confirmOpen: false,
				error: action.message,
			};
		case 'DIFF_CANCELED':
			return { ...state, screen: 'form', confirmOpen: false, error: '' };
		case 'DIFF_MODE_CHANGED':
			return { ...state, diffMode: action.mode };
		case 'CONFIRM_OPENED':
			return { ...state, confirmOpen: true };
		case 'CONFIRM_CLOSED':
			return { ...state, confirmOpen: false };
		case 'APPLY_STARTED':
		case 'REAPPLY_STARTED':
			return {
				...state,
				screen: 'progress',
				activeJobId: action.jobId,
				confirmOpen: false,
				busy: false,
				error: '',
			};
		case 'JOB_UPDATED':
			return { ...state, completedJob: action.job };
		case 'JOB_COMPLETED':
			return {
				...state,
				screen: 'success',
				completedJob: action.job ?? state.completedJob,
				activeJobId: null,
			};
		case 'UNDO_STARTED':
			return {
				...state,
				screen: 'progress',
				activeJobId: action.jobId,
				error: '',
			};
		case 'UNDO_COMPLETED':
			return {
				...state,
				screen: 'undone',
				completedJob: action.job ?? state.completedJob,
				activeJobId: null,
			};
		case 'NEW_SEARCH':
			return {
				...initialState,
				form: { ...initialState.form, tables: state.form.tables },
			};
		default:
			return state;
	}
}
