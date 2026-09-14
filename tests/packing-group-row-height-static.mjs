import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../assets/css/packing-board.css', import.meta.url), 'utf8');

assert.match(
    css,
    /\.packing-list-page\s*\{[\s\S]*?--packing-row-height:\s*35px\s*;/,
    'Packing List row-height contract must remain exactly 35px.',
);

assert.match(
    css,
    /\.packing-list-page \.packing-board-table tbody tr:not\(\.packing-add-item-row\)\s*\{[\s\S]*?height:\s*var\(--packing-row-height\);[\s\S]*?min-height:\s*var\(--packing-row-height\);[\s\S]*?max-height:\s*var\(--packing-row-height\);/,
    'Packing group rows must use the shared 35px row-height contract.',
);

assert.match(
    css,
    /\.packing-list-page \.packing-board-table tbody td:not\(\[data-column-key="select"\]\):not\(\[data-column-key="priority"\]\):not\(\[data-column-key="website_uploaded"\]\):not\(\[data-column-key="status"\]\)\s*\{[\s\S]*?height:\s*var\(--packing-row-height\);[\s\S]*?min-height:\s*var\(--packing-row-height\);[\s\S]*?max-height:\s*var\(--packing-row-height\);/,
    'Packing group cells must use the shared 35px row-height contract.',
);

console.log('Packing group row and cell height checks passed.');
