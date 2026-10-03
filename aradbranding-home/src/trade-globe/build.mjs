// Bundles globe.js + the parts of three.js it uses into one self-hosted ES module (the site's CSP only allows 'self' scripts).
import { build } from 'esbuild';

await build({
  entryPoints: ['globe.js'],
  bundle: true,
  minify: true,
  format: 'esm',
  // Older smart-TV browsers (Chromium 61+, Safari 11+) must be able to parse it: no ?., ??, class fields.
  target: ['es2017', 'chrome61', 'safari11'],
  supported: { destructuring: true }, // Chrome 49+ / Safari 10+; esbuild cannot lower it and the targets all have it
  legalComments: 'eof',
  outfile: '../../site/public_html/assets/trade-globe.js',
});
