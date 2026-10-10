// The directory manifest the Mac app's PackageVerifier parses (apps/macos/.../PackageVerifier.swift),
// written and re-checked here with the same rules so a runtime assembled by Node is accepted by
// Swift on the first try. Keep the two in lock-step: schema_version 1 is exactly
// `{schema_version, runtime_version, executable, files[{path, sha256, bytes}]}`, SHA256SUMS is
// `sha256sum` format, no symlinks, no unlisted files, safe relative paths only.
//
// Dependency-free on purpose: it runs in `node --test` and inside the assembler without npm.

import { createHash } from 'node:crypto';
import { lstatSync, readdirSync, readFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

export const MANIFEST_FILE_NAME = 'manifest.json';
export const CHECKSUM_FILE_NAME = 'SHA256SUMS';
export const SUPPORTED_SCHEMA_VERSION = 1;

export class RuntimeManifestError extends Error {
    constructor(code, message) {
        super(message);
        this.name = 'RuntimeManifestError';
        this.code = code;
    }
}

const fail = (code, message) => {
    throw new RuntimeManifestError(code, message);
};

// Mirrors RuntimeManifest.isSafeRelativePath: not empty, no leading `/` or `~`, every
// `/`-separated component non-empty and neither `.` nor `..`.
export function isSafeRelativePath(path) {
    if (typeof path !== 'string' || path === '' || path.startsWith('/') || path.startsWith('~')) {
        return false;
    }

    return path.split('/').every((component) => component !== '' && component !== '.' && component !== '..');
}

export const isSha256Hex = (value) => typeof value === 'string' && /^[0-9a-f]{64}$/.test(value);

export const isVersion = (value) => typeof value === 'string' && /^[0-9]+\.[0-9]+\.[0-9]+$/.test(value);

export function sha256Hex(path) {
    return createHash('sha256').update(readFileSync(path)).digest('hex');
}

/** Structural checks only (RuntimeManifest.validate). Returns the manifest when it is sound. */
export function validateManifest(manifest) {
    if (manifest === null || typeof manifest !== 'object' || Array.isArray(manifest)) {
        fail('manifestInvalid', 'not an object');
    }

    const keys = Object.keys(manifest).sort();

    if (keys.join(',') !== 'executable,files,runtime_version,schema_version') {
        fail('manifestInvalid', `schema 1 has exactly the keys schema_version, runtime_version, executable, files; got ${keys.join(', ')}`);
    }

    if (manifest.schema_version !== SUPPORTED_SCHEMA_VERSION) {
        fail('manifestInvalid', `schema_version ${manifest.schema_version} is not supported`);
    }

    if (! isVersion(manifest.runtime_version)) {
        fail('manifestInvalid', 'runtime_version is not a version string');
    }

    if (! Array.isArray(manifest.files) || manifest.files.length === 0) {
        fail('manifestInvalid', 'files is empty');
    }

    const seen = new Set();

    for (const entry of manifest.files) {
        if (entry === null || typeof entry !== 'object' || Object.keys(entry).sort().join(',') !== 'bytes,path,sha256') {
            fail('manifestInvalid', 'every file entry has exactly path, sha256, bytes');
        }

        if (! isSafeRelativePath(entry.path)) {
            fail('manifestInvalid', `unsafe path ${entry.path}`);
        }

        if (! isSha256Hex(entry.sha256)) {
            fail('manifestInvalid', `sha256 for ${entry.path} is not 64 lowercase hex characters`);
        }

        if (! Number.isInteger(entry.bytes) || entry.bytes < 0) {
            fail('manifestInvalid', `negative or non-integer size for ${entry.path}`);
        }

        if (seen.has(entry.path)) {
            fail('manifestInvalid', `duplicate path ${entry.path}`);
        }

        seen.add(entry.path);
    }

    if (! seen.has(manifest.executable)) {
        fail('manifestInvalid', `executable ${manifest.executable} is not listed in files`);
    }

    return manifest;
}

/** `SHA256SUMS` in `sha256sum` format: `<64 hex>  <path>` per line (PackageVerifier.parseChecksumFile). */
export function parseChecksumFile(text) {
    const digests = new Map();

    text.split('\n').forEach((rawLine, index) => {
        const line = rawLine.trim();

        if (line === '' || line.startsWith('#')) {
            return;
        }

        const separator = line.indexOf(' ');

        if (separator < 0) {
            fail('checksumFileInvalid', `line ${index + 1} has no separator`);
        }

        const digest = line.slice(0, separator);
        let path = line.slice(separator).trim();

        if (path.startsWith('*')) {
            path = path.slice(1);
        }

        if (! isSha256Hex(digest) || ! isSafeRelativePath(path)) {
            fail('checksumFileInvalid', `line ${index + 1} is malformed`);
        }

        if (digests.has(path)) {
            fail('checksumFileInvalid', `duplicate entry for ${path}`);
        }

        digests.set(path, digest);
    });

    if (digests.size === 0) {
        fail('checksumFileInvalid', 'no entries');
    }

    return digests;
}

export function formatChecksumFile(entries) {
    return entries.map((entry) => `${entry.sha256}  ${entry.path}\n`).join('');
}

/**
 * Relative paths of every regular file and symlink under `directory`, sorted, `/`-separated.
 * Symlinks are reported so that one standing in for a listed file, or an unlisted one, is caught.
 */
export function listFiles(directory) {
    const paths = [];

    const walk = (dir) => {
        for (const entry of readdirSync(dir, { withFileTypes: true })) {
            const full = join(dir, entry.name);

            if (entry.isSymbolicLink() || entry.isFile()) {
                paths.push(relative(directory, full).split(sep).join('/'));
            } else if (entry.isDirectory()) {
                walk(full);
            } else {
                fail('notARegularFile', `${relative(directory, full)} is neither a file, a symlink nor a directory`);
            }
        }
    };

    walk(directory);

    return paths.sort();
}

/**
 * The full PackageVerifier.verify(runtimeDirectory:) pass minus the code signature: manifest,
 * SHA256SUMS agreement, every listed file regular with the stated size and digest, no unlisted
 * file. Throws RuntimeManifestError with the same case names the Swift verifier uses.
 */
export function verifyRuntimeDirectory(directory) {
    let manifest;

    try {
        manifest = JSON.parse(readFileSync(join(directory, MANIFEST_FILE_NAME), 'utf8'));
    } catch (error) {
        fail(error.code === 'ENOENT' ? 'manifestMissing' : 'manifestInvalid', `manifest.json: ${error.message}`);
    }

    validateManifest(manifest);

    let sumsText;

    try {
        sumsText = readFileSync(join(directory, CHECKSUM_FILE_NAME), 'utf8');
    } catch {
        fail('checksumFileMissing', 'SHA256SUMS is missing');
    }

    const digests = parseChecksumFile(sumsText);

    for (const entry of manifest.files) {
        if (digests.get(entry.path) !== entry.sha256) {
            fail('checksumFileDisagreesWithManifest', `SHA256SUMS and manifest disagree about ${entry.path}`);
        }
    }

    for (const path of digests.keys()) {
        if (! manifest.files.some((entry) => entry.path === path)) {
            fail('checksumFileDisagreesWithManifest', `SHA256SUMS lists ${path}, the manifest does not`);
        }
    }

    for (const entry of manifest.files) {
        const full = join(directory, ...entry.path.split('/'));
        let stat;

        try {
            stat = lstatSync(full);
        } catch {
            fail('fileMissing', `${entry.path} is missing`);
        }

        if (! stat.isFile()) {
            fail('notARegularFile', `${entry.path} is not a regular file`);
        }

        if (stat.size !== entry.bytes) {
            fail('sizeMismatch', `${entry.path} has ${stat.size} bytes, manifest says ${entry.bytes}`);
        }

        if (sha256Hex(full) !== entry.sha256) {
            fail('checksumMismatch', `${entry.path} does not match its checksum`);
        }
    }

    const listed = new Set([...manifest.files.map((entry) => entry.path), MANIFEST_FILE_NAME, CHECKSUM_FILE_NAME]);

    for (const path of listFiles(directory)) {
        if (! listed.has(path)) {
            fail('unlistedFile', `${path} is not listed in the manifest`);
        }
    }

    return manifest;
}
