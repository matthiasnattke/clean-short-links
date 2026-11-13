/*
 * Tests for the QR encoder – node tests/qr.test.js
 * Reference values: the "HELLO WORLD" 1-Q example of the QR specification tutorials.
 */
const assert = require('assert');
const QR = require('../assets/qr.js');
let n = 0;
function test(name, fn) { fn(); n++; console.log('  ✓ ' + name); }

test('HELLO WORLD 1-Q: data and error correction codewords', () => {
	const cw = QR._codewords('HELLO WORLD', 'alnum', 1, 'Q');
	assert.deepStrictEqual(cw.slice(0, 13), [32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236]);
	assert.deepStrictEqual(cw.slice(13), [168, 72, 22, 82, 217, 54, 156, 0, 46, 15, 180, 122, 16]);
});
test('URLs in capitals use the compact alphanumeric mode', () => {
	assert.strictEqual(QR.encode('HTTPS://EXAMPLE.COM/Q/ABC').mode, 'alnum');
	assert.strictEqual(QR.encode('https://example.com/q/abc').mode, 'byte');
});
test('Finder patterns sit in three corners', () => {
	const q = QR.encode('HTTPS://EXAMPLE.COM/Q/ABC', { ec: 'H' });
	const m = q.modules, s = q.size;
	for (const [x, y] of [[0, 0], [s - 7, 0], [0, s - 7]]) {
		assert.ok(m[y][x] && m[y + 6][x + 6] && m[y + 3][x + 3] && !m[y + 1][x + 1], 'finder at ' + x + ',' + y);
	}
	assert.ok(m[s - 8][8], 'dark module');
});
test('Size grows with version and a stronger level is taken when it fits', () => {
	const q = QR.encode('HTTPS://EXAMPLE.COM/Q/725', { ec: 'M' });
	assert.strictEqual(q.size, 17 + 4 * q.version);
	assert.ok(['M', 'Q', 'H'].includes(q.ec));
});
test('Versions up to 10, longer texts fail clearly', () => {
	const q = QR.encode('https://example.com/' + 'x'.repeat(150), { ec: 'L' });
	assert.ok(q.version >= 7 && q.version <= 10);
	assert.throws(() => QR.encode('x'.repeat(400)));
});
console.log(`\n${n} passed`);
