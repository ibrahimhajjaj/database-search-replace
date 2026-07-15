#!/usr/bin/env node
/**
 * Appends translatable strings from the TypeScript UI to the POT template.
 *
 * The bundler rewrites __() into a member expression the WP-CLI extractor
 * cannot follow, so the strings are read from source here. Only the flat
 * __( 'text', 'database-search-replace' ) form is used in this project, which is
 * matched exactly. Plural and context calls would need a real parser.
 */
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const DOMAIN = 'database-search-replace';
const SRC_DIR = 'src';
const POT = 'languages/database-search-replace.pot';

const callPattern = new RegExp(
	"__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'" + DOMAIN + "'\\s*\\)",
	'gs'
);

function walk( dir ) {
	const out = [];
	for ( const entry of readdirSync( dir ) ) {
		const path = join( dir, entry );
		if ( statSync( path ).isDirectory() ) {
			out.push( ...walk( path ) );
		} else if (
			/\.tsx?$/.test( entry ) &&
			! /\.test\.tsx?$/.test( entry )
		) {
			out.push( path );
		}
	}
	return out;
}

function fromJsLiteral( raw ) {
	return raw.replace( /\\(['\\])/g, '$1' );
}

function toPotString( value ) {
	return value.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' );
}

const entries = new Map();
for ( const file of walk( SRC_DIR ).sort() ) {
	const source = readFileSync( file, 'utf8' );
	let match;
	callPattern.lastIndex = 0;
	while ( ( match = callPattern.exec( source ) ) !== null ) {
		const text = fromJsLiteral( match[ 1 ] );
		if ( ! entries.has( text ) ) {
			const line = source.slice( 0, match.index ).split( '\n' ).length;
			entries.set( text, `${ file }:${ line }` );
		}
	}
}

const pot = readFileSync( POT, 'utf8' );
const already = new Set(
	[ ...pot.matchAll( /^msgid "((?:[^"\\]|\\.)*)"$/gm ) ].map(
		( m ) => m[ 1 ]
	)
);

const additions = [];
for ( const [ text, reference ] of entries ) {
	const escaped = toPotString( text );
	if ( already.has( escaped ) ) {
		continue;
	}
	additions.push( `#: ${ reference }\nmsgid "${ escaped }"\nmsgstr ""\n` );
}

if ( additions.length > 0 ) {
	writeFileSync(
		POT,
		pot.replace( /\n*$/, '\n' ) + '\n' + additions.join( '\n' )
	);
}

process.stdout.write(
	`${ additions.length } UI strings added, ${ entries.size } found\n`
);
