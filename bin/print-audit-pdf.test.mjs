import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildPrintToPdfParams,
  parseArguments,
} from './print-audit-pdf.mjs';

test('parseArguments accepts one input HTML path and one output PDF path', () => {
  assert.deepEqual(parseArguments(['report.html', 'report.pdf']), {
    inputHtml: 'report.html',
    outputPdf: 'report.pdf',
  });
});

test('parseArguments rejects a missing output path with usage guidance', () => {
  assert.throws(
    () => parseArguments(['report.html']),
    /Usage: node bin\/print-audit-pdf\.mjs <input-html> <output-pdf>/,
  );
});

test('parseArguments rejects extra arguments with usage guidance', () => {
  assert.throws(
    () => parseArguments(['report.html', 'report.pdf', 'extra']),
    /Usage: node bin\/print-audit-pdf\.mjs <input-html> <output-pdf>/,
  );
});

test('parseArguments rejects blank paths', () => {
  assert.throws(
    () => parseArguments(['  ', 'report.pdf']),
    /Input HTML path must not be empty/,
  );
  assert.throws(
    () => parseArguments(['report.html', '\t']),
    /Output PDF path must not be empty/,
  );
});

test('buildPrintToPdfParams requests an unscaled, CSS-sized A4 page without transport margins', () => {
  assert.deepEqual(buildPrintToPdfParams(), {
    printBackground: true,
    preferCSSPageSize: true,
    paperWidth: 210 / 25.4,
    paperHeight: 297 / 25.4,
    scale: 1,
    displayHeaderFooter: false,
    marginTop: 0,
    marginBottom: 0,
    marginLeft: 0,
    marginRight: 0,
  });
});
