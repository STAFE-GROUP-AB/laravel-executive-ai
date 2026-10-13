# Third-party license inventory

> Companion to [ADR-007](../adrs/007-license-and-compliance.md). One row per artefact that is
> bundled, invoked or recommended by Laravel Executive AI. Verified from primary sources on
> **2026-10-10** unless a row says otherwise. "MIT-compatible" means: may be redistributed inside an
> MIT-licensed product by satisfying notice-only conditions. Anything an engineer could not establish
> from the license text itself is marked **NEEDS LEGAL REVIEW** and listed in ADR-007 Residual risks
> (L-1 … L-10). Engineers do not accept legal risk.
>
> The shipped `Resources/THIRD-PARTY-NOTICES.txt` is generated in CI (`bin/spc dump-license`,
> `composer licenses --format=json`, npm and Swift reports) and diffed against this file. Do not
> hand-edit the generated file; update this table and the build config instead.

**Who decides** — `ENG`: engineering may admit on the evidence in the row. `A+ENG`: ADR-001 owner
(workstream A) confirms the build configuration. `H`: security/privacy lead reviews. `ANDREAS`: only
the Owner, after legal review.

## 1. Bundled executables and runtime libraries

| Name | Purpose | License (SPDX) | Source | Redistribution conditions | MIT-compatible | Who decides |
|---|---|---|---|---|---|---|
| PHP 8.x interpreter (static build) | Runs the Laravel core | PHP-3.01 | https://www.php.net/license/3_01.txt | Clause 2: reproduce copyright, conditions, disclaimer in binary distributions. Clause 4: product may not be called "PHP" / contain "PHP" in its name without permission. Clause 6: every redistribution must retain "This product includes PHP software, freely available from <http://www.php.net/software/>". No copyleft. PHP 8.6+ announced to move to PHP-4.0 (BSD-3) | **yes** (naming + acknowledgement) — L-2 | ENG; naming ANDREAS |
| Zend Engine (inside PHP) | PHP engine | Zend-2.0 → reported folded into PHP license; "effectively identical to BSD-3-Clause" per php.net FAQ (not fetched 2026-10-10) | https://www.php.net/license/ | Notice only | yes — **NEEDS LEGAL REVIEW** to confirm current text | ENG |
| FrankenPHP | HTTP server + PHP SAPI in one binary | MIT | https://github.com/php/frankenphp/blob/main/LICENSE ("Copyright (c) 2022-present Kévin Dunglas") | Keep copyright + permission notice | yes | ENG |
| Caddy (embedded in FrankenPHP) | HTTP server | Apache-2.0 | https://github.com/caddyserver/caddy/blob/master/LICENSE | §4(a) give a copy of the License; §4(c) retain notices; §4(d) include NOTICE contents — repo has **no NOTICE file** (raw URL 404, 2026-10-10); §3 patent grant terminates on patent litigation | yes | ENG |
| static-php-cli (build tool) | Produces the static PHP binary | MIT | https://github.com/crazywhalecc/static-php-cli/blob/main/LICENSE ("Copyright (c) 2022-2023 Jerry Ma") | Tool is not shipped. `bin/spc dump-license` exports the licenses of the libraries actually linked — its output is the authoritative notices source | yes | ENG |
| OpenSSL 3.x | TLS, crypto | Apache-2.0 | https://openssl-library.org/source/license ("For the 3.0 release, and later releases derived from that, the Apache License v2 applies") | License text + notices | yes | ENG |
| libxml2 | XML | MIT | https://gitlab.gnome.org/GNOME/libxml2/-/blob/master/Copyright | Notice | yes | ENG |
| zlib | Compression | Zlib | https://zlib.net/zlib_license.html | Notice; no misrepresentation of origin | yes | ENG |
| oniguruma (`onig`) | mbstring regex | BSD-2-Clause | https://github.com/kkos/oniguruma/blob/master/COPYING | Notice | yes | ENG |
| libzip | zip extension | BSD-3-Clause | https://libzip.org/license/ | Notice; no endorsement | yes | ENG |
| SQLite | Local DB (ADR-002) | Public domain (SQLite blessing) | https://www.sqlite.org/copyright.html | None | yes | ENG |
| curl / libcurl | HTTP client | curl (MIT-style) | https://curl.se/docs/copyright.html | Notice | yes | ENG |
| ICU | intl extension | Unicode-3.0 / ICU | https://github.com/unicode-org/icu/blob/main/LICENSE | Notice | yes | ENG |
| libsodium | sodium extension | ISC | https://github.com/jedisct1/libsodium/blob/master/LICENSE | Notice | yes | ENG |
| brotli, nghttp2 | HTTP compression / HTTP2 | MIT | https://github.com/google/brotli/blob/master/LICENSE, https://github.com/nghttp2/nghttp2/blob/master/COPYING | Notice | yes | ENG |
| xz / liblzma | Compression | 0BSD / public domain | https://github.com/tukaani-project/xz/blob/master/COPYING | None/notice | yes | ENG |
| bzip2 | Compression | bzip2-1.0.6 | https://sourceware.org/bzip2/ | Notice | yes | ENG |
| libpng, libjpeg-turbo, libwebp | gd (if built) | libpng-2.0; IJG + BSD-3 + Zlib; BSD-3-Clause | upstream COPYING files | Notice | yes — build only if gd is needed | A+ENG |
| FreeType | gd fonts (if built) | FTL **or** GPL-2.0 (dual) | https://gitlab.freedesktop.org/freetype/freetype/-/blob/master/LICENSE.TXT | Elect FTL; FTL requires the credit "Portions of this software are copyright © The FreeType Project (www.freetype.org)" | yes under FTL — **NEEDS LEGAL REVIEW** (election) — L-1 | ANDREAS |
| ImageMagick (`imagick`) | Images (optional) | ImageMagick | https://imagemagick.org/script/license.php | Attribution-style; include license | yes — recommend **omit** | A+ENG |
| libedit (via `readline` ext) | CLI line editing | BSD-3-Clause | https://www.thrysoee.dk/editline/ | Notice. static-php-cli's `readline` ext links libedit, **not** GNU readline (GPL-3) | yes | ENG |
| GNU readline | — | GPL-3.0-or-later | https://tiswww.case.edu/php/chet/readline/rltop.html | Copyleft | **no — EXCLUDED** from build | A+ENG |
| gettext / libintl | — | LGPL-2.1-or-later | https://www.gnu.org/software/gettext/ | LGPL §6 relinking obligation when statically linked | **no — EXCLUDED** from build (ADR-007 D2.2) — L-1 | A+ENG |
| GNU libiconv | — | LGPL-2.1-or-later ("The libiconv and libcharset libraries and their header files are under LGPL", gnu.org/software/libiconv) | https://www.gnu.org/software/libiconv/ | LGPL §6 | **no — EXCLUDED**; use macOS system iconv (libSystem) or drop `iconv` — **NEEDS LEGAL REVIEW** that system iconv is what gets linked — L-1 | ANDREAS |
| libpq (PostgreSQL client) | pgsql (optional MySQL/PG profile only) | PostgreSQL | https://www.postgresql.org/about/licence/ | Notice | yes — not in v1 build | A+ENG |
| libssh2 | ssh2 (optional) | BSD-3-Clause | https://www.libssh2.org/license.html | Notice | yes — not in v1 build | A+ENG |

All rows in this section except the three EXCLUDED ones are admissible under ADR-007 D2.1. The exact
set linked is whatever ADR-001's `spc` build config produces; `spc dump-license` output must match
this table or the build fails.

## 2. Application dependencies (Composer / npm / Swift)

| Name | Purpose | License (SPDX) | Source | Conditions | MIT-compatible | Who decides |
|---|---|---|---|---|---|---|
| laravel/framework | Application framework | MIT | https://github.com/laravel/framework/blob/12.x/LICENSE.md | Notice | yes | ENG |
| laravel/ai | Provider-neutral AI SDK (ADR-004) | MIT | https://github.com/laravel/ai/blob/1.x/LICENSE.md (GitHub license API: MIT; Packagist v1.2.0) | Notice | yes | ENG |
| laravel/mcp | MCP server exposure (§7, ADR-005) | MIT | https://github.com/laravel/mcp/blob/main/LICENSE.md | Notice | yes | ENG |
| livewire/livewire | TALL stack | MIT | https://github.com/livewire/livewire/blob/main/LICENSE.md | Notice | yes | ENG |
| alpinejs/alpine | TALL stack | MIT | https://github.com/alpinejs/alpine/blob/main/LICENSE.md | Notice | yes | ENG |
| tailwindcss | TALL stack | MIT | https://github.com/tailwindlabs/tailwindcss/blob/main/LICENSE | Notice | yes | ENG |
| web-auth/webauthn-lib (pinned 5.3.9) | Passkey / WebAuthn ceremonies (§11, S2-05) | MIT | https://github.com/web-auth/webauthn-lib/blob/5.3.9/LICENSE | Notice. Pulls `spomky-labs/cbor-php`, `web-auth/cose-lib`, `spomky-labs/pki-framework` (all MIT), `paragonie/constant_time_encoding` (MIT) and `symfony/*` (MIT) | yes | ENG |
| Remaining Composer / npm packages | — | Mix; expected MIT/BSD/Apache-2.0 | `composer licenses --format=json`, npm license report in CI (S0-05) | Each must be on the ADR-007 D2.1 list | per package, enforced by CI | ENG; exceptions ANDREAS |
| cyclonedx/cyclonedx-php-composer | SBOM generation (dev only) | Apache-2.0 | https://github.com/CycloneDX/cyclonedx-php-composer | Not shipped | yes | ENG |
| Swift packages (apps/macos) | Mac app | To be listed by S1 | `Package.resolved` → license report | Same rule | per package | ENG |

## 3. Invoked or recommended, never bundled

| Name | Purpose | License (SPDX) | Source | Conditions | MIT-compatible | Who decides |
|---|---|---|---|---|---|---|
| Peekaboo | Optional macOS GUI automation (§6, ADR-005) | MIT | https://github.com/openclaw/Peekaboo/blob/main/LICENSE ("Copyright (c) 2025 Peter Steinberger"; repo under the `openclaw` org — A1 ruled in ADR-005) | Notice; fetched only as the verified release, checksum + signature checked, user opt-in | yes | ENG (A1: ANDREAS) |
| Tailscale — `tailscaled`/CLI | Private remote access (ADR-006) | BSD-3-Clause | https://github.com/tailscale/tailscale/blob/main/LICENSE ("Copyright (c) 2020 Tailscale Inc & contributors") | §3: no endorsement using the Tailscale name; we never bundle, only detect and invoke | yes (invoke only) | ENG |
| Tailscale — macOS GUI client (App Store / standalone) | Same | Proprietary; Tailscale Terms of Service | https://tailscale.com/terms, https://tailscale.com/docs/concepts/macos-variants | Not redistributable by us; installer links to Tailscale's download page; no logo use | n/a — **not bundled** | H reviews wording |
| Ollama (incl. llama.cpp, ggml) | Local inference runtime, detected (§12.1) | MIT (ggml/llama.cpp MIT, "Copyright (c) 2023-2026 The ggml authors") | https://github.com/ollama/ollama/blob/main/LICENSE, https://github.com/ggml-org/llama.cpp/blob/master/LICENSE | Could be bundled; deliberately not (ADR-007 D3) | yes | ENG |
| LM Studio | Local inference runtime, detected | Proprietary (LM Studio terms) | https://lmstudio.ai/terms | Detected only; never bundled | n/a | ENG |

## 4. Candidate local models (ADR-004 shortlist) — license verdicts

None of these ship in the DMG for v1 (ADR-007 D4.1). "Runtime download" means the user pulls the
model through Ollama/LM Studio/Hugging Face after seeing and accepting the license in-app (D4.2).
Sizes are Ollama default quantisations (Q4_K_M-class) read from ollama.com/library on 2026-10-10.

| Model | License (SPDX or LicenseRef) | Source | Commercial use | Redistribution conditions | MAU / user clause | Bundle in MIT DMG | Runtime download | Size | Who decides |
|---|---|---|---|---|---|---|---|---|---|
| Llama 3.1 8B, 3.2 1B/3B, 3.3 70B (Meta) | LicenseRef-Llama-3.x-Community-License (not OSI; HF tag `llama3.1`) | Stable text: https://github.com/meta-llama/llama-models/blob/main/models/llama3_1/LICENSE (also `llama3_2/`, `llama3_3/`). llama.com/llama3_x/license redirected to dev.meta.ai and returned 404 on 2026-10-10 | Yes — §1.a "non-exclusive, worldwide, non-transferable and royalty-free limited license … to use, reproduce, distribute, copy, create derivative works of, and make modifications" | §1.b.i "(A) provide a copy of this Agreement with any such Llama Materials; and (B) prominently display 'Built with Llama' on a related website, user interface, blogpost, about page, or product documentation"; derivative models: "include 'Llama' at the beginning of any such AI model name"; §1.b.iii Notice file "Llama 3.1 is licensed under the Llama 3.1 Community License, Copyright (c) Meta Platforms, Inc. All Rights Reserved."; §1.b.iv Acceptable Use Policy "hereby incorporated by reference" — binds recipients. **Llama 3.2 multimodal:** USE_POLICY.md — "the rights granted under Section 1(a) … are not being granted to you if you are an individual domiciled in, or a company with a principal place of business in, the European Union" (https://github.com/meta-llama/llama-models/blob/main/models/llama3_2/USE_POLICY.md); text-only 1B/3B unaffected | §2: "greater than 700 million monthly active users in the preceding calendar month, you must request a license from Meta" | **No** for v1 — weights are not relicensable; shipping them makes the DMG a mixed-license bundle with Meta's Agreement, Notice and AUP inside an MIT product — L-3 | Yes, with acceptance; HF repos gated (`manual`), Ollama ungated and shows the full license as a layer | llama3.1:8b 4.9 GB; llama3.2:3b 2.0 GB; 1b 1.3 GB | ANDREAS |
| Qwen2.5 7B / 14B / 32B Instruct; **Qwen3 (all sizes)** | Apache-2.0 | https://huggingface.co/Qwen/Qwen2.5-7B-Instruct (`license: apache-2.0`), https://huggingface.co/Qwen/Qwen3-8B (`license: apache-2.0`) | Yes | Apache §4 notices + license text | None | Needs review (L-4: training-data provenance §10.9, "Qwen" mark) | Yes | qwen2.5:7b 4.7 GB | ANDREAS (bundle) / ENG (runtime) |
| **Qwen2.5 3B** Instruct | LicenseRef-Qwen-Research-License | https://huggingface.co/Qwen/Qwen2.5-3B-Instruct/blob/main/LICENSE | **No** — "FOR NON-COMMERCIAL PURPOSES ONLY"; "If you are commercially using the Materials, you shall request a license from us" | Pass on agreement + notice "Qwen is licensed under the Qwen RESEARCH LICENSE AGREEMENT, Copyright (c) Alibaba Cloud. All Rights Reserved." + "prominently display 'Built with Qwen' or 'Improved using Qwen'" | None | **No** | **No — must not be offered** except personal non-commercial workspaces (D4.4) | 1.9 GB | ENG (hard rule) |
| Qwen2.5 72B Instruct | LicenseRef-Qwen-License | https://huggingface.co/Qwen/Qwen2.5-72B-Instruct/blob/main/LICENSE | Yes | Notice file: "Qwen is licensed under the Qwen LICENSE AGREEMENT, Copyright (c) Alibaba Cloud. All Rights Reserved." | "more than 100 million monthly active users, you shall request a license from us" | No | Yes, with acceptance (too large for the Mac profile) | ~47 GB | ANDREAS |
| Mistral 7B Instruct v0.3 | Apache-2.0 | https://huggingface.co/mistralai/Mistral-7B-Instruct-v0.3 (`apache-2.0`, not gated) | Yes | Apache §4 | None | Needs review (L-4) | Yes | mistral:7b (= v0.3) 4.4 GB; Ollama license layer "Apache License Version 2.0, January 2004" | ANDREAS (bundle) / ENG (runtime) |
| Mistral Nemo Instruct 2407 (12B) | Apache-2.0 ("Released under the Apache 2 License") | https://huggingface.co/mistralai/Mistral-Nemo-Instruct-2407 | Yes | Apache §4 | None | Needs review (L-4) | Yes | ~7 GB Q4 | ANDREAS (bundle) / ENG (runtime) |
| **Ministral 8B Instruct 2410** | LicenseRef-Mistral-AI-Research-License-0.1 (HF `license: other`, `mrl`) | https://mistral.ai/licenses/MRL-0.1.md | **No** — "You shall only use the Mistral Models, Derivatives … and Outputs for Research Purposes"; Research Purposes = "non-profit and non-commercial purposes, and not directly or indirectly connected to any commercial activities or business operations" | — | — | **No** | **No — must not be offered** except personal non-commercial workspaces (D4.4) | ~5 GB | ENG (hard rule) |
| Gemma 3 1B / 4B / 12B (Google) | LicenseRef-Gemma-Terms-of-Use (last modified 1 April 2026) | https://ai.google.dev/gemma/terms , https://ai.google.dev/gemma/prohibited_use_policy ; HF repo **gated** ("required to review and agree to Google's usage license") | Yes | "You must include the use restrictions referenced in Section 3.2 as an enforceable provision in any agreement governing the use and/or distribution"; "provide all third party recipients … a copy of this Agreement"; notice "Gemma is provided under and subject to the Gemma Terms of Use found at ai.google.dev/gemma/terms"; §3.2 Prohibited Use Policy — "Google reserves the right to update this Gemma Prohibited Use Policy from time to time"; §4.1 Google "reserves the right to restrict (remotely or otherwise) usage"; §4.2 no trademark rights. No "Built with Gemma" or naming requirement. Ollama's embedded copy is the 21 Feb 2024 version, not the current 1 April 2026 text | None | **No** for v1 — §3.1(a) forces us to bind our users to Google's use restrictions "as an enforceable provision", which an MIT grant cannot carry — L-3 | Yes, with acceptance; HF gated (`manual`), Ollama ungated and shows the Terms as a license layer | gemma3:1b 806 MB; 4b 3.4 GB (+851 MB vision projector); 12b 8.2 GB | ANDREAS |
| Phi-4 14B / Phi-4-mini (Microsoft) | MIT | https://huggingface.co/microsoft/phi-4 (`license: mit`; "Nothing contained in this Model Card should be interpreted as … a restriction or modification to the license"), https://huggingface.co/microsoft/Phi-4-mini-instruct/blob/main/LICENSE ; not gated | Yes | MIT notice. "Responsible AI Considerations" is guidance, not a license term | None | Needs review (L-4) — cleanest candidate | Yes | phi4:14b 9.1 GB; phi4-mini:3.8b 2.5 GB | ANDREAS (bundle) / ENG (runtime) |

Ollama license display: the top-level `ollama.com/library/<model>` page does **not** show the
license, but each tag page `ollama.com/library/<model>:<tag>` lists a `license` layer with the full
text (verified 2026-10-10 for llama3.1:8b, gemma3:4b, qwen2.5:3b, mistral:7b, phi4-mini:3.8b), and
`ollama show <model> --license` prints it locally. The embedded copy can lag the vendor's current
text (Gemma: Feb 2024 vs April 2026). The app therefore reads the Ollama license layer **and** the
vendor URL, stores both hashes in register field R6 (ADR-007 D6), and shows the vendor text.

Recommended "suggested download" set for ADR-004, in this order: Phi-4-mini (MIT, 2.5 GB), Qwen3-4B/8B
(Apache-2.0, 2.5/5.2 GB), Mistral 7B v0.3 (Apache-2.0, 4.4 GB). Llama and Gemma only as user-initiated
downloads with their license shown first. Qwen2.5-3B and Ministral 8B are never offered to commercial
workspaces.

## 5. Apple distribution (Developer ID, notarization) — high level only

| Item | What we rely on | Status |
|---|---|---|
| Apple Developer Program License Agreement (latest PDF version 20251008: https://developer.apple.com/support/downloads/terms/apple-developer-program/Apple-Developer-Program-License-Agreement-20251008-English.pdf) | §1.2 defines "FOSS" (incl. GPL) and "Covered Products"; §2.1 Permitted Uses — Apple Certificates may sign Applications; §2.6 No Other Permitted Uses — no redistribution of Apple Software/Certificates, comply with licences of open-source components in Apple Software; §3.2 representations — Covered Products "do not and will not violate, misappropriate, or infringe any Apple or third-party copyrights, trademarks, rights of privacy and publicity, trade secrets, patents, or other proprietary or legal rights" (= we must hold rights to every bundled binary) plus a FOSS clause (do not subject Apple Software to FOSS terms — exact lettering unconfirmed); §5.2 Developer ID certificates used only as Apple documents, revocable; §5.3 Notarized Applications for macOS — notary scans for malware/security and may refuse or revoke Tickets; §11 termination on breach | **NEEDS LEGAL REVIEW** (L-5); team identity A12 |
| Notarization (https://developer.apple.com/documentation/security/notarizing-macos-software-before-distribution) | "Notarization of macOS software is not App Review. The Apple notary service is an automated system that scans your software for malicious content, checks for code-signing issues". Requirements: "Enable code-signing for all of the executables you distribute"; "Use a 'Developer ID' application … certificate"; "Enable the Hardened Runtime capability for your app and command line targets"; "Include a secure timestamp"; no `com.apple.security.get-task-allow`. Every nested helper (php, frankenphp) needs its own signature | ADR-001 owns mechanics; L-5 |
| Expected result | No Apple term beyond "you must hold the rights" constrains open-source binaries in a Developer ID app | To be confirmed — L-5 |

## 6. Regulatory sources used

- Regulation (EU) 2024/1689 (AI Act): https://eur-lex.europa.eu/eli/reg/2024/1689/oj ; article pages
  https://artificialintelligenceact.eu/article/2/ (Art. 2(12)), /article/5/ (Art. 5(1)(f)),
  /article/50/, /annex/3/ (point 4), /article/113/.
- Regulation (EU) 2026/1744 of 8 July 2026 (Digital Omnibus on AI), OJ L 2026/1744 of 24 July 2026:
  https://eur-lex.europa.eu/eli/reg/2026/1744/oj/eng — Annex III → 2 December 2027, Annex I →
  2 August 2028, four-month Art. 50(2) grace for systems on the market before 2 August 2026.
- Commission AI policy page (updated 3 August 2026):
  https://digital-strategy.ec.europa.eu/en/policies/regulatory-framework-ai
- GDPR: https://eur-lex.europa.eu/eli/reg/2016/679/oj — Art. 42 certification by an accredited body
  against approved criteria is the only official "certified" route (approved European Data Protection
  Seals: Europrivacy, EuroPriSe, BC 5701:2024 —
  https://www.edpb.europa.eu/our-work-tools/accountability-tools/certification-mechanisms-seals-and-marks_en);
  this product holds none, and Art. 42(4) says certification "does not reduce the responsibility of
  the controller or the processor for compliance with this Regulation".
- AI Act Art. 4 (AI literacy, providers and deployers, since 2 Feb 2025; rewritten by the Omnibus to
  add that it "does not require providers or deployers to guarantee any specific level of AI
  literacy"): https://artificialintelligenceact.eu/article/4/
- AI Act Art. 6(3) (Annex III system not high-risk if "a narrow procedural task", "intended to
  improve the result of a previously completed human activity", pattern detection without replacing
  human assessment, or "a preparatory task to an assessment" — but "shall always be considered to be
  high-risk where the AI system performs profiling of natural persons"):
  https://artificialintelligenceact.eu/article/6/

## 7. Change log

| Date | Change |
|---|---|
| 2026-10-10 | Initial inventory for ADR-007 (Proposed). No legal review yet. |
