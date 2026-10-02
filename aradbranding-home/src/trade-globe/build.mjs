// Bundles globe.js + the parts of three.js it uses into one self-hosted ES module (the site's CSP only allows 'self' scripts).
import { build } from 'esbuild';

await build({
  entryPoints: ['globe.js'],
  bundle: true,
  minify: true,
  format: 'esm',
  target: ['es2020'],
  legalComments: 'eof',
  outfile: '../../site/public_html/assets/trade-globe.js',
});
