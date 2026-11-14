#!/usr/bin/env node
/*
 * Checks the shipped plugin against the machine-checkable parts of the
 * WordPress.org Detailed Plugin Guidelines – no dependencies, Node 18+.
 * https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
 *
 *   node tools/guidelines.js [plugin-dir] [--online]
 *
 * plugin-dir   the plugin as it is shipped (default: the repository itself)
 * --online     also compare "Tested up to" with the current WordPress version
 *
 * Exit code 0 when everything passes, 1 otherwise. Behaviour-only guidelines
 * (2, 3, 6, 9, 14, 16, 18) cannot be checked here and are listed at the end.
 */
const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const online = args.includes('--online');
const root = path.resolve(args.find(a => !a.startsWith('--')) || path.join(__dirname, '..'));
const slug = 'clean-short-links';
const isRepo = fs.existsSync(path.join(root, '.git'));
const devOnly = new Set(['.git', '.github', '.wordpress-org', 'docs', 'tests', 'tools', 'node_modules', 'build']);

/* All files that end up in the plugin package. */
function walk(dir, out = []) {
	for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
		if (isRepo && devOnly.has(e.name) && dir === root) continue;
		const p = path.join(dir, e.name);
		if (e.isDirectory()) walk(p, out); else out.push(p);
	}
	return out;
}
const files = walk(root);
const rel = f => path.relative(root, f);
const read = f => fs.readFileSync(f, 'utf8');
const code = files.filter(f => /\.(php|js|css)$/.test(f));
const php = code.filter(f => f.endsWith('.php'));
const js = code.filter(f => f.endsWith('.js'));

let failed = 0;
const results = [];
function check(nr, title, problems) {
	problems = problems.filter(Boolean);
	results.push({ nr, title, problems });
	if (problems.length) failed++;
}
function grep(list, re) {
	const hits = [];
	for (const f of list) {
		read(f).split('\n').forEach((line, i) => {
			if (/^\s*(\*|\/\/|#)/.test(line)) return; // Comments.
			if (re.test(line)) hits.push(`${rel(f)}:${i + 1}: ${line.trim().slice(0, 120)}`);
		});
	}
	return hits;
}

const main = read(path.join(root, `${slug}.php`));
const readme = read(path.join(root, 'readme.txt'));
const header = k => (main.match(new RegExp(`^ \\* ${k}:\\s*(.+)$`, 'm')) || [])[1]?.trim() || '';
const field = k => (readme.match(new RegExp(`^${k}:\\s*(.+)$`, 'm')) || [])[1]?.trim() || '';

/* 1 – GPL */
check(1, 'GPL-compatible license', [
	/GPL-2\.0-or-later|GPLv2 or later/i.test(header('License')) ? '' : `plugin header License is "${header('License')}"`,
	/GPL/i.test(field('License')) ? '' : `readme License is "${field('License')}"`,
	fs.existsSync(path.join(root, 'LICENSE')) || fs.existsSync(path.join(root, 'license.txt')) ? '' : 'no LICENSE file',
]);

/* 4 – human-readable code */
check(4, 'Human-readable code, no obfuscation', [
	...grep(php, /\b(eval|base64_decode|gzinflate|gzuncompress|str_rot13|create_function)\s*\(/),
	...grep(js, /eval\s*\(\s*function\s*\(\s*p\s*,\s*a\s*,\s*c\s*,\s*k\s*,\s*e/),
	...code.flatMap(f => read(f).split('\n').some(l => l.length > 1000) ? [`${rel(f)}: very long line – minified?`] : []),
	...files.filter(f => /\.min\.(js|css)$/.test(f) && !fs.existsSync(f.replace('.min.', '.'))).map(f => `${rel(f)}: minified without source`),
]);

/* 5 – no trialware */
check(5, 'No trialware or locked features', grep(code, /(license[_ -]?key|upgrade to (pro|premium)|unlock (all|premium)|trial (expired|period))/i));

/* 7 – no tracking / phoning home */
// Requests that are the plugin's own, documented feature carry the marker "// Link check:".
check(7, 'No external requests or tracking', [
	...grep(php, /\b(wp_(safe_)?remote_(get|post|request|head)|curl_init|fsockopen|stream_socket_client|download_url)\s*\(/).filter(h => !/\/\/ (Link check|Calendar import):/.test(h)),
	...grep(php, /file_get_contents\s*\(\s*['"]https?:/),
	...grep(js, /(fetch|XMLHttpRequest|sendBeacon)\s*\(?\s*['"]https?:/),
	...grep(code, /(google-analytics|googletagmanager|gtag\(|facebook\.net|hotjar|matomo)/i),
]);

/* 8 – no executable code from third parties */
check(8, 'No remote scripts, CDNs, iframes or own updater', [
	...grep(php, /wp_(enqueue|register)_(script|style)\s*\([^)]*['"]https?:\/\//),
	...grep(php, /<script[^>]+src=['"]https?:|<iframe/i),
	...grep(php, /(pre_set_site_transient_update_plugins|site_transient_update_plugins|plugins_api)\b/),
]);

/* 10 – no public credits by default */
check(10, 'No "powered by" or credit links on the public site', grep(php, /powered by/i));

/* 11 – admin notices must not hijack the dashboard */
check(11, 'No non-dismissible site-wide admin notices', php.flatMap(f => {
	const src = read(f);
	return /add_action\(\s*['"](network_|all_)?admin_notices['"]/.test(src) && !/is-dismissible|dismiss/.test(src) ? [`${rel(f)}: admin notice without a dismiss option`] : [];
}));

/* 12 – readme without spam */
const tags = field('Tags').split(',').map(t => t.trim().toLowerCase()).filter(Boolean);
const short = (readme.split(/\n\s*\n/)[1] || '').trim(); // The paragraph right after the header fields.
check(12, 'Readme: required fields, at most 5 tags, short description', [
	...['Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI'].map(k => field(k) ? '' : `readme field "${k}" missing`),
	tags.length > 5 ? `${tags.length} tags (max 5)` : '',
	new Set(tags).size !== tags.length ? 'duplicate tags' : '',
	tags.some(t => ['akismet', 'jetpack', 'woocommerce'].includes(t)) ? 'tag names another product' : '',
	short.length > 150 ? `short description has ${short.length} characters (max 150)` : '',
	/=== *(.+?) *===/.exec(readme)?.[1] === header('Plugin Name') ? '' : 'readme title differs from Plugin Name',
]);

/* 13 – use the libraries bundled with WordPress */
check(13, 'No bundled copies of WordPress libraries', files.filter(f => /(^|\/)(jquery[\w.-]*\.js|simplepie|phpmailer|class-phpass|atomlib)/i.test(rel(f))).map(rel));

/* 15 – version numbers agree */
const constant = (main.match(/define\(\s*'CLEAN_SHORT_LINKS_VERSION',\s*'([^']+)'/) || [])[1];
check(15, 'Version: header, constant, Stable tag and changelog agree', [
	header('Version') === constant ? '' : `header ${header('Version')} vs. constant ${constant}`,
	header('Version') === field('Stable tag') ? '' : `header ${header('Version')} vs. Stable tag ${field('Stable tag')}`,
	new RegExp(`^= ${header('Version').replace(/\./g, '\\.')} =$`, 'm').test(readme) ? '' : `no changelog entry for ${header('Version')}`,
]);

/* 17 – trademarks and names */
const name = header('Plugin Name').toLowerCase();
check(17, 'Name and slug respect trademarks', [
	/^(wordpress|wp[- ]|woocommerce|woo[- ]|jetpack|gutenberg)/.test(slug) ? `slug "${slug}" starts with a trademark` : '',
	/^(wordpress|woocommerce|jetpack|gutenberg)/.test(name) ? `name "${header('Plugin Name')}" starts with a trademark` : '',
	header('Text Domain') === slug ? '' : `Text Domain "${header('Text Domain')}" differs from slug`,
]);

/* Hygiene expected by the review team */
check('–', 'Every PHP file blocks direct access; no development files shipped', [
	...php.filter(f => !/defined\(\s*'(ABSPATH|WP_UNINSTALL_PLUGIN)'\s*\)/.test(read(f))).map(f => `${rel(f)}: no ABSPATH check`),
	...(isRepo ? [] : files.filter(f => /(^|\/)(tests|tools|\.github|node_modules)\/|\.DS_Store$/.test(rel(f))).map(f => `${rel(f)}: development file in the package`)),
]);

async function testedUpTo() {
	if (!online) return;
	try {
		const r = await fetch('https://api.wordpress.org/core/version-check/1.7/');
		const v = (await r.json()).offers[0].version.split('.').slice(0, 2).join('.');
		const t = field('Tested up to');
		const cmp = (a, b) => a.split('.').map(Number).reduce((s, x, i) => s || x - (b.split('.').map(Number)[i] || 0), 0);
		check('–', `"Tested up to" covers WordPress ${v}`, [cmp(t, v) < 0 ? `Tested up to ${t}, current WordPress is ${v}` : '']);
	} catch (e) {
		console.log(`  ! Could not reach api.wordpress.org: ${e.message}`);
	}
}

testedUpTo().then(() => {
	console.log(`WordPress.org plugin guidelines – ${rel(root) || path.basename(root)}\n`);
	for (const r of results) {
		console.log(`  ${r.problems.length ? '✗' : '✓'} ${String(r.nr).padStart(2)}  ${r.title}`);
		for (const p of r.problems) console.log(`         ${p}`);
	}
	console.log('\n  Not machine-checkable, keep in mind: 2 responsibility for contents, 3 stable version on WordPress.org,');
	console.log('  6 SaaS rules, 9 honest behaviour, 14 no frequent SVN commits, 16 complete plugin, 18 directory rights.');
	console.log(`\n${results.length - failed} passed, ${failed} failed`);
	process.exit(failed ? 1 : 0);
});
