// Build the frozen bundle: npm ci && npm run build (versions pinned in package.json and package-lock.json)
import { build } from 'esbuild';
import { readFileSync, writeFileSync, mkdirSync, copyFileSync } from 'node:fs';

const out = '../../js/vendor/tiptap';
mkdirSync(out, { recursive: true });
await build({
	entryPoints: ['entry.js'],
	bundle: true,
	minify: true,
	format: 'iife',
	globalName: 'InboxTiptap',
	target: ['es2020'],
	legalComments: 'none',
	outfile: out + '/tiptap.min.js',
});
const version = JSON.parse(readFileSync('node_modules/@tiptap/core/package.json', 'utf8')).version;
writeFileSync(out + '/VERSION', 'Tiptap ' + version + ' (MIT), built by dev/tiptap/build.mjs\n');
copyFileSync('node_modules/@tiptap/core/LICENSE.md', out + '/LICENSE.md');
console.log('ok ' + version);
