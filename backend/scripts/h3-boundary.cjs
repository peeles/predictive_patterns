#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');

function exitWithError(message) {
    console.error(message);
    process.exit(1);
}

const [, , h3Index] = process.argv;

if (!h3Index) {
    exitWithError('Missing H3 index argument.');
}

const vendorBundle = path.resolve(__dirname, 'h3-js-bundle/h3-js.cjs');

if (!fs.existsSync(vendorBundle)) {
    exitWithError('Bundled h3-js helper is missing.');
}

let h3;
try {
    h3 = require(vendorBundle);
} catch (error) {
    exitWithError(`Unable to load h3-js helper: ${error.message}`);
}

if (typeof h3.cellToBoundary !== 'function') {
    exitWithError('Bundled h3-js helper does not expose cellToBoundary.');
}

let boundary;
try {
    boundary = h3.cellToBoundary(h3Index, true);
} catch (error) {
    exitWithError(`Failed to calculate boundary: ${error.message}`);
}

process.stdout.write(JSON.stringify(boundary));
process.stdout.write('\n');
