// ADR-006 D3 (#109): the app opens the PWA on `http://localhost:<port>` — the one loopback host a
// browser will register a passkey for — while the IPC client keeps the literal `127.0.0.1` that
// ipc-contract §1.1 requires. These two must never be blurred, so this file asserts both halves:
// the browser URLs are on `localhost`, and `CoreClient.origin` is untouched.
//
// `IpcOriginClientTest` owns the IPC half and must keep passing unchanged.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class PwaOriginTests: XCTestCase {
    func testTheCanonicalBrowserHostIsLocalhost() {
        XCTAssertEqual(PwaOrigin.host, "localhost", "a WebAuthn relying party cannot be an IP literal")
        XCTAssertEqual(PwaOrigin.origin(port: 8787)?.absoluteString, "http://localhost:8787")
    }

    func testTheLoopbackIpLiteralsAreRewrittenAndNothingElseIs() {
        let cases: [(String, String)] = [
            ("http://127.0.0.1:8787/home", "http://localhost:8787/home"),
            ("http://127.0.0.1:8787/approvals/01M4FJ7WN0BRGSZJGNGZ5FSVT5?from=menubar",
             "http://localhost:8787/approvals/01M4FJ7WN0BRGSZJGNGZ5FSVT5?from=menubar"),
            ("http://[::1]:8787/home", "http://localhost:8787/home"),
            // Already canonical, or an origin this app must not touch: returned byte for byte.
            ("http://localhost:8787/home", "http://localhost:8787/home"),
            ("https://executive.example-tailnet.ts.net/home", "https://executive.example-tailnet.ts.net/home"),
            ("https://executive.example-tailnet.ts.net/briefs/01M4?v=2#item-3", "https://executive.example-tailnet.ts.net/briefs/01M4?v=2#item-3"),
        ]

        for (input, expected) in cases {
            let url = URL(string: input)!
            XCTAssertEqual(PwaOrigin.canonical(url).absoluteString, expected, "canonicalising \(input)")
        }
    }

    func testTheBrowserOriginKeepsTheIpcPortAndLeavesTheIpcOriginAlone() {
        let client = FixtureCoreClient(fixtureRoot: FixtureCoreClient.repositoryFixtureRoot()!)

        XCTAssertEqual(client.origin.absoluteString, "http://127.0.0.1:8787", "the IPC origin is ipc-contract §1.1 and does not move")
        XCTAssertEqual(client.browserOrigin.absoluteString, "http://localhost:8787")
        XCTAssertEqual(client.browserOrigin.port, client.origin.port, "same socket, different name")
    }

    /// The §11 passkey requirement is only reachable if no code path hands a browser the IP
    /// literal. The two files that build browser URLs are checked at the source level, because a
    /// regression here is silent: the page still loads, the passkey just never appears. The
    /// patterns are code, not prose — a comment that *names* `client.origin` is fine, a call that
    /// opens it is not.
    func testNoScreenBuildsABrowserUrlOnTheIpLiteral() throws {
        let files = [
            "apps/macos/LaravelExecutiveAI/MenuBar/PanelModel.swift",
            "apps/macos/LaravelExecutiveAI/Install/InstallScreens.swift",
        ]

        for file in files {
            let source = try String(contentsOf: TestFiles.repositoryRoot.appending(path: file), encoding: .utf8)

            XCTAssertFalse(source.contains("\"http://127.0.0.1"), "\(file) builds a browser URL from the IP literal")
            XCTAssertFalse(source.contains("open(client.origin"), "\(file) opens the IPC origin; use client.browserOrigin")
        }
    }
}
