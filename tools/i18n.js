#!/usr/bin/env node
/*
 * Translations for Clean Short Links – no dependencies, Node 18+.
 *
 *   node tools/i18n.js pot             write languages/clean-short-links.pot
 *   node tools/i18n.js build           write .pot and, for every languages/<locale>.json, the .po and .mo
 *   node tools/i18n.js check           fail if the .pot is outdated or a translation is incomplete (used by CI)
 *   node tools/i18n.js missing <loc>   list the texts that <locale>.json does not translate yet
 *
 * A translation file languages/<locale>.json maps each English text to its translation.
 * Plurals are arrays. The optional key "_plural_forms" sets the plural rule of the language,
 * default: "nplurals=2; plural=(n != 1);".
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const dir = path.join(root, 'languages');
const files = ['clean-short-links.php', 'uninstall.php', ...fs.readdirSync(path.join(root, 'includes')).filter(f => f.endsWith('.php')).map(f => 'includes/' + f)];

const unq = s => s.replace(/\\'/g, "'").replace(/\\\\/g, '\\');
const entries = new Map();
for (const f of files) {
	const src = fs.readFileSync(path.join(root, f), 'utf8');
	const lines = src.split('\n');
	const re = /\b(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_n)\(\s*'((?:[^'\\]|\\.)*)'(?:\s*,\s*'((?:[^'\\]|\\.)*)')?/g;
	let m;
	while ((m = re.exec(src))) {
		const line = src.slice(0, m.index).split('\n').length;
		const id = unq(m[2]);
		const plural = m[1] === '_n' ? unq(m[3]) : null;
		let comment = '';
		for (let i = line - 1; i >= Math.max(1, line - 4); i--) {
			const c = lines[i - 1] && lines[i - 1].match(/translators:\s*(.*?)\s*\*\//);
			if (c) { comment = c[1]; break; }
		}
		const e = entries.get(id) || { id, plural, refs: [], comment: '' };
		e.refs.push(`${f}:${line}`);
		if (comment) e.comment = comment;
		entries.set(id, e);
	}
}

const esc = s => s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n');
const DEFAULT_PLURAL = 'nplurals=2; plural=(n != 1);';

function header(locale, plural) {
	return 'msgid ""\nmsgstr ""\n'
		+ '"Project-Id-Version: Clean Short Links\\n"\n'
		+ '"Report-Msgid-Bugs-To: https://github.com/matthiasnattke/clean-short-links/issues\\n"\n'
		+ '"MIME-Version: 1.0\\n"\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Content-Transfer-Encoding: 8bit\\n"\n'
		+ (locale ? `"Language: ${locale}\\n"\n"Plural-Forms: ${plural}\\n"\n` : '"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\\n"\n')
		+ '"X-Domain: clean-short-links\\n"\n\n';
}

function po(tr, locale) {
	let out = header(locale, (tr && tr._plural_forms) || DEFAULT_PLURAL);
	for (const e of entries.values()) {
		if (e.comment) out += `#. translators: ${e.comment}\n`;
		out += `#: ${e.refs.join(' ')}\n`;
		if (/%(\d+\$)?[sd]/.test(e.id)) out += '#, php-format\n';
		out += `msgid "${esc(e.id)}"\n`;
		if (e.plural) {
			const t = tr ? tr[e.id] : null;
			const forms = Array.isArray(t) ? t : ['', ''];
			out += `msgid_plural "${esc(e.plural)}"\n` + forms.map((f, i) => `msgstr[${i}] "${esc(f)}"\n`).join('') + '\n';
		} else {
			out += `msgstr "${esc(tr ? tr[e.id] || '' : '')}"\n\n`;
		}
	}
	return out;
}

function mo(tr, locale) {
	const pairs = [['', `Content-Type: text/plain; charset=UTF-8\nPlural-Forms: ${tr._plural_forms || DEFAULT_PLURAL}\nLanguage: ${locale}\n`]];
	for (const e of entries.values()) {
		const t = tr[e.id];
		if (!t) continue;
		pairs.push(e.plural ? [e.id + '\0' + e.plural, [].concat(t).join('\0')] : [e.id, t]);
	}
	pairs.sort((a, b) => Buffer.compare(Buffer.from(a[0]), Buffer.from(b[0])));
	const n = pairs.length, origTab = 28, transTab = 28 + n * 8;
	let off = transTab + n * 8;
	const strings = [], ot = Buffer.alloc(n * 8), tt = Buffer.alloc(n * 8);
	pairs.forEach(([o], i) => { const b = Buffer.from(o, 'utf8'); ot.writeUInt32LE(b.length, i * 8); ot.writeUInt32LE(off, i * 8 + 4); strings.push(b, Buffer.from([0])); off += b.length + 1; });
	pairs.forEach(([, t], i) => { const b = Buffer.from(t, 'utf8'); tt.writeUInt32LE(b.length, i * 8); tt.writeUInt32LE(off, i * 8 + 4); strings.push(b, Buffer.from([0])); off += b.length + 1; });
	const h = Buffer.alloc(28);
	h.writeUInt32LE(0x950412de, 0); h.writeUInt32LE(0, 4); h.writeUInt32LE(n, 8); h.writeUInt32LE(origTab, 12); h.writeUInt32LE(transTab, 16); h.writeUInt32LE(0, 20); h.writeUInt32LE(off, 24);
	return Buffer.concat([h, ot, tt, ...strings]);
}

const locales = fs.readdirSync(dir).filter(f => /^[a-z]{2,3}(_[A-Z]{2})?(_\w+)?\.json$/.test(f)).map(f => f.replace(/\.json$/, ''));
const load = l => JSON.parse(fs.readFileSync(path.join(dir, l + '.json'), 'utf8'));
const missingIn = tr => [...entries.values()].filter(e => !tr[e.id] || (e.plural && !Array.isArray(tr[e.id])));
const potPath = path.join(dir, 'clean-short-links.pot');
const cmd = process.argv[2] || 'pot';

if (cmd === 'check') {
	let ok = true;
	if (!fs.existsSync(potPath) || fs.readFileSync(potPath, 'utf8') !== po(null, null)) {
		console.error('languages/clean-short-links.pot is outdated – run "node tools/i18n.js build".');
		ok = false;
	}
	for (const l of locales) {
		const miss = missingIn(load(l));
		if (miss.length) {
			console.error(`${l}: ${miss.length} texts without translation:\n` + miss.map(e => '  ' + e.id).join('\n'));
			ok = false;
		}
		const p = path.join(dir, `clean-short-links-${l}.po`);
		if (!fs.existsSync(p) || fs.readFileSync(p, 'utf8') !== po(load(l), l)) {
			console.error(`${l}: .po/.mo are not rebuilt – run "node tools/i18n.js build".`);
			ok = false;
		}
	}
	console.log(`${entries.size} texts, languages: ${locales.join(', ') || '–'}`);
	process.exit(ok ? 0 : 1);
}

if (cmd === 'missing') {
	const l = process.argv[3];
	const tr = l && fs.existsSync(path.join(dir, l + '.json')) ? load(l) : {};
	missingIn(tr).forEach(e => console.log(JSON.stringify(e.plural ? [e.id, e.plural] : e.id)));
	process.exit(0);
}

fs.writeFileSync(potPath, po(null, null));
console.log(`languages/clean-short-links.pot written (${entries.size} texts)`);
if (cmd === 'build') {
	for (const l of locales) {
		const tr = load(l);
		const miss = missingIn(tr);
		fs.writeFileSync(path.join(dir, `clean-short-links-${l}.po`), po(tr, l));
		fs.writeFileSync(path.join(dir, `clean-short-links-${l}.mo`), mo(tr, l));
		const unused = Object.keys(tr).filter(k => k !== '_plural_forms' && !entries.has(k));
		console.log(`${l}: .po and .mo written` + (miss.length ? `, ${miss.length} texts still missing` : '') + (unused.length ? `, ${unused.length} no longer used` : ''));
	}
}
