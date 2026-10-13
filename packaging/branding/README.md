# Branding

The app icon and the wordmark, and the one script that produces them.

## Why a script rather than a folder of PNGs

The icon is **drawn in code** (`make-icon.swift`, AppKit + CoreGraphics) and rendered to every size
the asset catalog needs. Nothing here was exported from a design tool, so there is no binary source
file that only one machine can open, no step that depends on software a contributor may not have,
and a change to the mark is a readable diff rather than a replaced image.

It uses only what macOS already provides. There is nothing to install.

## The mark

A geometric **E**: a vertical stem with three arms, longest at the top, shortest in the middle. It
reads as *Executive* and as the lines of a brief, and it stays a letter at 16 pt — the size that
decides whether an icon works at all. The first pass had thicker arms and the three bars closed up
into a block when small; the gaps are what keep it legible.

The top arm carries the light accent, so the shape has a headline instead of three equal bars. That
is what separates it from every generic list glyph.

## Palette

The product's own, from `apps/macos/DesignTokens` — the icon belongs to the same family as the
interface rather than being decoration bolted on:

| Role | Colour |
|---|---|
| Ground | `#0f5f70` → `#0a4553`, a quiet vertical gradient |
| Headline arm | `#63bfd1` |
| Stem and lower arms | `#f4f4f2` |

The geometry follows Apple's macOS app-icon grid: the art sits inside the canvas with a 98/1024
margin and a 185/824 continuous corner radius, rather than filling the square.

## Regenerating

```sh
swiftc -O -o /tmp/iconmaker packaging/branding/make-icon.swift
/tmp/iconmaker /tmp/iconout
```

Then copy the PNGs into `apps/macos/LaravelExecutiveAI/Assets.xcassets/AppIcon.appiconset/` under
the names `Contents.json` lists. `ASSETCATALOG_COMPILER_APPICON_NAME` in `Config/App.xcconfig` is
what points the build at it.

## The wordmark

`make-logo.swift` draws the horizontal lockup — the same mark beside the name — for the README and
anywhere the product is introduced rather than launched. Two variants, because a single PNG is
unreadable on half the backgrounds it lands on: `docs/assets/logo-light.png` for light pages and
`logo-dark.png` for dark ones.

```sh
swiftc -O -o /tmp/logomaker packaging/branding/make-logo.swift
/tmp/logomaker /tmp/logoout
```

The geometry is duplicated between the two scripts on purpose: they are 40 lines each and a shared
module would be a build dependency for two files that change together once a year. If a third
artefact appears, extract it then.
