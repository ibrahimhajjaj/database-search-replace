const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	setupFilesAfterEnv: [ '<rootDir>/src/setup-tests.ts' ],
	moduleNameMapper: {
		...defaultConfig.moduleNameMapper,
		'^@wordpress/element$': '<rootDir>/test-mocks/wordpress-element.js',
		'^@wordpress/api-fetch$': '<rootDir>/test-mocks/api-fetch.js',
	},
};
