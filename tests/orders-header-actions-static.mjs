import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../apps/operations/orders-board.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');

assert.match(
    page,
    /class="monday-board-head-actions orders-header-actions">[\s\S]*?data-orders-tools-open[\s\S]*?data-export-excel/,
    'Local Orders actions remain Tools then Export; account controls belong to the shared topbar.',
);

assert.match(
    css,
    /\.ess-orders-page \.orders-header-actions\s*\{[\s\S]*?flex:\s*0 0 auto;[\s\S]*?flex-wrap:\s*nowrap;[\s\S]*?margin-left:\s*auto;/,
    'Desktop Orders actions must remain grouped at the right immediately before status.',
);

assert.match(
    css,
    /@media \(max-width:700px\)[\s\S]*?\.ess-orders-page \.orders-header-actions\s*\{[\s\S]*?width:\s*100%;[\s\S]*?flex-wrap:\s*wrap;[\s\S]*?margin-left:\s*0;/,
    'Orders header actions must wrap cleanly on narrow screens.',
);

console.log('Orders header action placement checks passed.');
