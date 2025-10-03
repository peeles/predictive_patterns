#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const readline = require('node:readline');

class ValidationError extends Error {}

function exitWithError(message) {
    console.error(message);
    process.exit(1);
}

function writeResponse(payload) {
    process.stdout.write(`${JSON.stringify(payload)}\n`);
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

const converters = [h3.latLngToCell, h3.geoToH3].filter((fn) => typeof fn === 'function');

if (converters.length === 0) {
    exitWithError('Bundled h3-js helper does not expose latLngToCell or geoToH3.');
}

const args = process.argv.slice(2);

function parseNumber(value, label, context) {
    if (value === undefined || value === null) {
        throw new ValidationError(`${context}: Missing ${label}.`);
    }

    if (typeof value === 'string' && value.trim() === '') {
        throw new ValidationError(`${context}: ${label} cannot be empty.`);
    }

    const number = Number(value);
    if (!Number.isFinite(number)) {
        throw new ValidationError(`${context}: ${label} must be a finite number.`);
    }

    return number;
}

function parseResolution(value, context) {
    const resolution = parseNumber(value, 'resolution', context);
    if (!Number.isInteger(resolution) || resolution < 0) {
        throw new ValidationError(`${context}: Resolution must be a non-negative integer.`);
    }

    return resolution;
}

function computeIndex(lat, lng, resolution) {
    let lastError;

    for (const converter of converters) {
        try {
            const index = converter(lat, lng, resolution);
            if (typeof index === 'string' && index.length > 0) {
                return index;
            }
        } catch (error) {
            lastError = error;
        }
    }

    if (lastError) {
        throw new Error(`Failed to calculate index: ${lastError?.message ?? lastError}`);
    }

    throw new Error('Bundled h3-js helper returned an invalid index.');
}

if (args[0] === '--daemon') {
    const rl = readline.createInterface({
        input: process.stdin,
        crlfDelay: Infinity,
    });

    process.stdout.write('READY\n');

    rl.on('line', (line) => {
        const payload = line.trim();

        if (payload === '') {
            writeResponse({ error: 'Empty daemon payload.' });
            return;
        }

        let parsed;
        try {
            parsed = JSON.parse(payload);
        } catch (error) {
            writeResponse({ error: `Unable to parse daemon payload: ${error.message}`, raw: payload });
            return;
        }

        const { id, lat, lng, resolution } = parsed;

        if (id === undefined || id === null) {
            writeResponse({ error: 'Daemon payload missing id.' });
            return;
        }

        try {
            const latNumber = parseNumber(lat, 'latitude', 'Daemon payload');
            const lngNumber = parseNumber(lng, 'longitude', 'Daemon payload');
            const resolutionNumber = parseResolution(resolution, 'Daemon payload');

            const index = computeIndex(latNumber, lngNumber, resolutionNumber);

            writeResponse({ id, index });
        } catch (error) {
            if (error instanceof ValidationError) {
                writeResponse({ id, error: error.message });
                return;
            }

            writeResponse({ id, error: error.message ?? String(error) });
        }
    });

    rl.on('close', () => {
        process.exit(0);
    });

    return;
}

if (args[0] === '--batch') {
    const input = fs.readFileSync(0, 'utf8');
    const payload = input.trim();

    if (payload === '') {
        exitWithError('Batch payload is empty.');
    }

    let parsed;
    try {
        parsed = JSON.parse(payload);
    } catch (error) {
        exitWithError(`Unable to parse batch payload: ${error.message}`);
    }

    const operations = Array.isArray(parsed) ? parsed : parsed.operations;
    if (!Array.isArray(operations) || operations.length === 0) {
        exitWithError('Batch payload must contain at least one operation.');
    }

    const indexes = operations.map((operation, index) => {
        if (operation === null || typeof operation !== 'object') {
            exitWithError(`Batch operation at index ${index} must be an object.`);
        }

        try {
            const lat = parseNumber(operation.lat, 'latitude', `Batch operation ${index}`);
            const lng = parseNumber(operation.lng, 'longitude', `Batch operation ${index}`);
            const resolution = parseResolution(operation.resolution, `Batch operation ${index}`);

            return computeIndex(lat, lng, resolution);
        } catch (error) {
            if (error instanceof ValidationError) {
                exitWithError(error.message);
            }

            exitWithError(error.message ?? String(error));
        }
    });

    process.stdout.write(`${JSON.stringify({ indexes })}\n`);
    process.exit(0);
}

const [latArg, lngArg, resArg] = args;

if (latArg === undefined || lngArg === undefined || resArg === undefined) {
    exitWithError('Missing latitude, longitude, or resolution arguments.');
}

let lat;
let lng;
let resolution;

try {
    lat = parseNumber(latArg, 'latitude', 'Argument');
    lng = parseNumber(lngArg, 'longitude', 'Argument');
    resolution = parseResolution(resArg, 'Argument');
} catch (error) {
    if (error instanceof ValidationError) {
        exitWithError(error.message);
    }

    exitWithError(error.message ?? String(error));
}

try {
    const index = computeIndex(lat, lng, resolution);

    process.stdout.write(`${index}\n`);
} catch (error) {
    exitWithError(error.message ?? String(error));
}
