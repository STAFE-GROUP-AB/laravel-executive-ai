// The Supervisor LaunchAgent plist template (ADR-001 D3, S1-07) is well-formed plist XML and
// carries nothing per machine: no absolute user path, no secret, no environment block.
// Dependency-free node:test, like the install-timer and runtime assembler suites.

import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const directory = join(here, '..');
const repositoryRoot = join(here, '..', '..', '..', '..');

const plistFiles = readdirSync(directory).filter((name) => name.endsWith('.plist'));

// A minimal plist XML reader: enough for dict/array/string/integer/true/false, which is all a
// launchd plist needs. Anything else is a failure, by design.
function parsePlist(xml) {
    assert.match(xml, /^<\?xml version="1.0" encoding="UTF-8"\?>\s*<!DOCTYPE plist PUBLIC "-\/\/Apple\/\/DTD PLIST 1.0\/\/EN" "http:\/\/www.apple.com\/DTDs\/PropertyList-1.0.dtd">\s*<plist version="1.0">/, 'plist header');

    const body = xml
        .replace(/^<\?xml[^>]*\?>\s*<!DOCTYPE[^>]*>\s*/, '')
        .replace(/<plist version="1\.0">/, '<plist>')
        .replace(/<!--[\s\S]*?-->/g, '');
    const tokens = body.match(/<\/?[a-z]+\/?>|[^<]+/g).map((token) => token.trim()).filter(Boolean);
    let index = 0;

    function next() {
        return tokens[index++];
    }

    function parseValue(token) {
        switch (token) {
            case '<dict>': {
                const dict = {};

                for (let current = next(); current !== '</dict>'; current = next()) {
                    assert.equal(current, '<key>', `expected <key>, got ${current}`);
                    const key = next();
                    assert.equal(next(), '</key>');
                    assert.ok(!(key in dict), `duplicate key ${key}`);
                    dict[key] = parseValue(next());
                }

                return dict;
            }
            case '<array>': {
                const array = [];

                for (let current = next(); current !== '</array>'; current = next()) {
                    array.push(parseValue(current));
                }

                return array;
            }
            case '<string>': {
                const value = next();

                if (value === '</string>') {
                    return '';
                }

                assert.equal(next(), '</string>');

                return value;
            }
            case '<integer>': {
                const value = next();
                assert.equal(next(), '</integer>');
                assert.match(value, /^-?\d+$/);

                return Number(value);
            }
            case '<true/>':
                return true;
            case '<false/>':
                return false;
            default:
                assert.fail(`unsupported plist element ${token}`);
        }
    }

    assert.equal(next(), '<plist>');
    const root = parseValue(next());
    assert.equal(next(), '</plist>');
    assert.equal(index, tokens.length, 'trailing content after </plist>');

    return root;
}

test('there is exactly one Supervisor LaunchAgent template', () => {
    assert.deepEqual(plistFiles, ['se.stafe.executive-ai.supervisor.plist']);
});

for (const name of plistFiles) {
    const xml = readFileSync(join(directory, name), 'utf8');
    const label = name.replace(/\.plist$/, '');

    test(`${name} is well-formed plist XML with the ADR-001 D3 keys`, () => {
        const plist = parsePlist(xml);

        assert.equal(plist.Label, label, 'Label equals the file name');
        assert.equal(plist.BundleProgram, 'Contents/MacOS/LaravelExecutiveAISupervisor');
        assert.equal(plist.RunAtLoad, true);
        assert.equal(plist.KeepAlive, true);
        assert.equal(plist.ThrottleInterval, 10);
        assert.equal(plist.ProcessType, 'Background');
        assert.equal(plist.ExitTimeOut, 30);
        assert.equal(plist.LimitLoadToSessionType, 'Aqua');
        assert.deepEqual(plist.AssociatedBundleIdentifiers, ['se.stafe.executive-ai.app']);
        assert.deepEqual(Object.keys(plist.MachServices), ['se.stafe.executive-ai.supervisor.control']);
        assert.equal(plist.MachServices['se.stafe.executive-ai.supervisor.control'], true);

        assert.equal(plist.Program, undefined, 'BundleProgram, never an absolute Program');
        assert.equal(plist.ProgramArguments, undefined);
        assert.equal(plist.EnvironmentVariables, undefined, 'nothing per machine, no secret');
        assert.equal(plist.UserName, undefined);
        assert.equal(plist.WorkingDirectory, undefined);
        assert.equal(plist.StandardOutPath, undefined);
        assert.equal(plist.StandardErrorPath, undefined);
    });

    test(`${name} contains no user path, no secret and no absolute path`, () => {
        assert.doesNotMatch(xml, /\/Users\//);
        assert.doesNotMatch(xml, /\/home\//);
        assert.doesNotMatch(xml, /base64:/);
        assert.doesNotMatch(xml, /<string>\//, 'every string value is bundle-relative or an identifier');
        assert.doesNotMatch(xml, /(api[_-]?key|token|password|secret)=/i);
    });

    test(`${name} carries the bundle prefix from Shared.xcconfig`, () => {
        const xcconfig = readFileSync(join(repositoryRoot, 'apps/macos/Config/Shared.xcconfig'), 'utf8');
        const prefix = xcconfig.match(/^LXAI_BUNDLE_PREFIX\s*=\s*(\S+)/m)[1];

        assert.ok(label.startsWith(`${prefix}.`), `Label ${label} starts with ${prefix}`);
        assert.equal(label, `${prefix}.supervisor`);
    });
}

test('the Xcode project embeds the template at Contents/Library/LaunchAgents', () => {
    const project = readFileSync(join(repositoryRoot, 'apps/macos/LaravelExecutiveAI.xcodeproj/project.pbxproj'), 'utf8');

    assert.match(project, /dstPath = Contents\/Library\/LaunchAgents;/);
    assert.match(project, /packaging\/manifest\/launchd\/se\.stafe\.executive-ai\.supervisor\.plist/);
    assert.match(project, /productType = "com\.apple\.product-type\.tool";/, 'the Supervisor is a separate tool target');
});
