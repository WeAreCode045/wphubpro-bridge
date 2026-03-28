/**
 * Concatenates UBold vendors + app CSS, moves @import/@charset to top, then
 * prefix-wraps all rules under #wpbody-content #wphubpro-bridge-app for wp-admin safety.
 *
 * Run: npm run build:ubold
 * Source copies: assets/ubold/source/ (from Coderthemes UBold build output).
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import postcss from 'postcss';
import postcssPrefixWrap from 'postcss-prefixwrap';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const root = path.resolve( __dirname, '..' );
const sourceDir = path.join( root, 'assets/ubold/source' );
const outFile = path.join( root, 'assets/ubold/ubold.bridge.scoped.css' );

const PREFIX = '#wpbody-content #wphubpro-bridge-app';

/**
 * Pull @charset and @import (including url() with semicolons inside) from the start of CSS.
 */
function stripLeadingAtRules( css ) {
	const rules = [];
	let rest = css.trimStart();

	while ( rest.length > 0 ) {
		if ( rest.startsWith( '@charset' ) ) {
			const end = rest.indexOf( ';' );
			if ( end === -1 ) {
				break;
			}
			rules.push( rest.slice( 0, end + 1 ) );
			rest = rest.slice( end + 1 ).trimStart();
			continue;
		}

		if ( rest.startsWith( '@import' ) ) {
			const urlIdx = rest.indexOf( 'url(' );
			if ( urlIdx !== -1 && urlIdx < 20 ) {
				let i = urlIdx + 4;
				let depth = 0;
				for ( ; i < rest.length; i++ ) {
					const c = rest[ i ];
					if ( c === '(' ) {
						depth++;
					} else if ( c === ')' ) {
						if ( depth === 0 ) {
							i++;
							break;
						}
						depth--;
					}
				}
				const semi = rest.indexOf( ';', i );
				if ( semi === -1 ) {
					break;
				}
				rules.push( rest.slice( 0, semi + 1 ) );
				rest = rest.slice( semi + 1 ).trimStart();
				continue;
			}

			const end = rest.indexOf( ';' );
			if ( end === -1 ) {
				break;
			}
			rules.push( rest.slice( 0, end + 1 ) );
			rest = rest.slice( end + 1 ).trimStart();
			continue;
		}

		break;
	}

	return { headers: rules.join( '\n' ), body: rest };
}

const vendors = fs.readFileSync( path.join( sourceDir, 'vendors.min.css' ), 'utf8' );
const appRaw = fs.readFileSync( path.join( sourceDir, 'app.min.css' ), 'utf8' );
const { headers, body: appBody } = stripLeadingAtRules( appRaw );

const combined = [ headers, '', vendors, '', appBody ].join( '\n' );

const result = await postcss( [
	postcssPrefixWrap( PREFIX, {
		ignoredSelectors: [ /^@keyframes\s/, /^@font-face/ ],
	} ),
] ).process( combined, { from: undefined } );

let css = result.css;

const esc = PREFIX.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
css = css.replace( new RegExp( esc + '\\s*:root', 'g' ), PREFIX );
css = css.replace( new RegExp( esc + '\\s*:root,', 'g' ), PREFIX + ',' );

fs.mkdirSync( path.dirname( outFile ), { recursive: true } );
fs.writeFileSync( outFile, css, 'utf8' );
console.log( 'Wrote', outFile, `(${ ( css.length / 1024 ).toFixed( 1 ) } KB)` );
