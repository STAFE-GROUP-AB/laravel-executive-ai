# Design system — foundation and tokens (S0-24)

**Status:** Sprint 0 contract · **Workstream:** C (TALL/PWA design) · **Issue:** #24
**Binding references:** blueprint §1.1 (identity, menu bar panel), §1.2 (PWA responsive), §1.3 (HTML
mail in Apple Mail/Gmail/HEY), §12.3 (six strict first-run screens), §13 (workstream C DoD: Lighthouse,
manual UX, accessibility).

## 1. Identity direction

Laravel Executive AI looks like a well-made instrument, not a consumer utility: sober, premium and calm,
information-dense but breathable. Neutral warm-grey surfaces carry the content; one restrained deep-teal
accent marks the single most important action on a screen; semantic colours appear only when they
mean something (a stale brief, an approval waiting, a failed sync). Dark mode is a first-class theme
with its own tuned palette, not an inversion. Type is the system font (SF Pro on Apple platforms) at
a quiet modular scale with at most three weights. Motion is short and functional. It is explicitly
**not a CleanMyMac clone**, which concretely means: **no mascots or illustrated characters**, **no
glossy or multi-stop gradients**, **no gamified meters, scores, confetti or "health" dials**, no
oversized hero numbers competing for attention, no decorative blur-and-glow panels, and no
notification badge unless there is a real action to take (§1.1). The first-run flow (§12.3) uses the
same tokens as the rest of the product: six screens, one CTA each, no marketing art.

## 2. Files and ownership

| File | Surface | Role |
|---|---|---|
| `resources/css/tokens.css` | PWA | **Source of truth.** CSS custom properties, light + dark + reduced-motion. |
| `resources/css/tailwind.tokens.cjs` | PWA | Tailwind `theme.extend` mapping onto the CSS variables. |
| `apps/macos/DesignTokens/` | Mac app | SwiftPM package `DesignTokens` mirroring the values for SwiftUI. |
| `app/Support/Design/MailTokens.php` | HTML mail | Mail-safe literal subset + prebuilt inline style strings. |
| `packaging/fixtures/design/contrast-check.mjs` | CI | WCAG AA gate; fails the build when any pair drops below threshold. |

Change order when a value moves: `tokens.css` → run the contrast script → mirror into Swift and PHP →
`swift test` pins the hexes. A value that exists in only one surface is a bug.

## 3. Token taxonomy

Names are semantic (`--color-text-primary`), never by hue (`--color-teal-700`), so a theme can
re-colour a role without touching consumers.

| Group | Tokens | Notes |
|---|---|---|
| Surfaces | `canvas`, `surface`, `surface-raised`, `surface-sunken`, `surface-inverse`, `overlay` | Four stacked layers; raised surfaces pair with `shadow-2+`. |
| Text | `text-primary`, `text-secondary`, `text-muted`, `text-inverse`, `text-on-accent`, `text-disabled` | `text-disabled` is the only text colour exempt from AA (WCAG 1.4.3 inactive exception). |
| Borders | `border`, `border-strong` | `border` is decorative; `border-strong` outlines inputs and must reach 3:1. |
| Accent | `accent`, `accent-hover`, `accent-subtle`, `accent-text` | One accent. `accent-text` is for text on `accent-subtle`. |
| Semantic | `success`, `warning`, `danger`, `info` + `-surface`, `-text` | `-surface`/`-text` form a tinted pair; bare token is icon/text on `surface`. |
| Focus | `focus-ring`, `focus-ring-width`, `focus-ring-offset`, `focus-ring` (shadow) | 2px ring, 2px offset in canvas colour, visible on both themes. |
| Type | `font-sans`, `font-mono`, `text-xs…3xl`, `leading-*`, `font-weight-regular/medium/semibold`, `tracking-*` | Minor-third (1.2) scale from 16px: 12/14/16/19/23/28/34. |
| Spacing | `space-0…24` | 4px base. |
| Sizing | `size-touch-min`, `size-control-sm/md/lg`, `layout-content-max`, `layout-readable-max` | 44px touch minimum; 28/36/44 controls; 1152px / 672px widths. |
| Radius | `radius-none/xs/sm/md/lg/xl/2xl/full` | 0/4/6/8/12/16/20. Default `md`. |
| Elevation | `shadow-0…4` | Light: soft umbra + 1px hairline. Dark: hairline + stronger umbra, since shadows vanish on dark. |
| Motion | `duration-instant/fast/base/slow/deliberate`, `ease-standard/decelerate/accelerate` | 0/120/180/260/400ms. Reduced motion collapses durations to 1ms. |
| Z-index | `z-base/raised/sticky/dropdown/overlay/modal/popover/toast/tooltip` | 0/10/100/1000/1100/1200/1300/1400/1500. |

### Theme switching

`:root` holds the light theme. `[data-theme="dark"]` holds dark. When no explicit theme is set,
`@media (prefers-color-scheme: dark)` applies the same dark block via `:root:not([data-theme="light"])`,
so the user's OS setting wins by default and an explicit toggle wins over it. The two dark blocks are
identical by design; the contrast script fails if they diverge. `color-scheme` is set on each so native
form controls and scrollbars match.

## 4. How the three surfaces consume tokens

### PWA (Tailwind + Livewire)

`resources/css/app.css` (owned by #26) imports the tokens first:

```css
@import "tailwindcss";
@import "./tokens.css";
@config "../../tailwind.config.js";
```

`tailwind.config.js` extends the theme with one line:

```js
module.exports = {
    content: ['./resources/**/*.blade.php', './resources/**/*.js', './app/**/*.php'],
    theme: { extend: require('./resources/css/tailwind.tokens.cjs') },
};
```

Every Tailwind value is a `var(--…)` reference, so a theme change is a CSS change, not a rebuild.
Resulting vocabulary: `bg-canvas`, `bg-surface`, `bg-surface-raised`, `text-primary`, `text-secondary`,
`text-muted`, `border-default`, `border-strong`, `bg-accent text-on-accent`, `bg-success-surface
text-success-text`, `ring-focus`, `shadow-2`, `rounded-lg`, `text-lg`, `duration-base ease-standard`,
`z-modal`, `min-h-touch`, `max-w-readable`. Do not add raw hex or Tailwind palette colours
(`bg-slate-800`) in Blade; if a role is missing, add a token.

### Mac app (SwiftUI)

`apps/macos/DesignTokens` is a local SwiftPM package (`swift-tools-version 6.0`, `macOS 15`, Swift 6
language mode). The Xcode project from #25 adds it as a local package dependency and imports
`DesignTokens`.

```swift
Text(brief.subject)
    .font(Tokens.Typography.headline)
    .foregroundStyle(Tokens.Colors.textPrimary.dynamic)      // follows the system appearance

// When the scheme is already in hand (previews, tests):
@Environment(\.colorScheme) private var scheme
Tokens.Colors.accent.resolved(for: scheme)
```

`ThemedColor.dynamic` builds an `NSColor(name:dynamicProvider:)` so AppKit resolves light/dark on the
fly, including in the `MenuBarExtra` panel; `resolved(for:)` is the asset-free fallback. Spacing,
radius, elevation and motion are `CGFloat`/`Double` constants with the same names as the CSS tokens.
`Tokens.Motion.duration(_:reduceMotion:)` takes `@Environment(\.accessibilityReduceMotion)`.

### HTML mail (Blade)

Mail templates import nothing from `tokens.css`. They use `App\Support\Design\MailTokens` constants
and the prebuilt roles from `MailTokens::style('…')` as inline `style=""` attributes. See §5.

## 5. Mail-safe subset (§1.3)

Rule: **mail templates use these literal values and only these**, inline, via `MailTokens`. No CSS
custom properties, no `box-shadow`, no web fonts, no flexbox/grid, no background images, no `rem`.
Layout is `<table>`-based, max width 600px, single column on phones. Light theme only, with
`<meta name="color-scheme" content="light">` and `<meta name="supported-color-schemes" content="light">`
in `<head>` so Apple Mail and HEY do not auto-invert. Every colour below passes AA on its documented
background in §7.

| Role | Value | Apple Mail | Gmail | HEY |
|---|---|---|---|---|
| Font stack | `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif` | SF Pro | Roboto/Arial | SF Pro / system |
| Mono stack | `Menlo, Consolas, 'Courier New', monospace` | yes | yes | yes |
| Body bg / card bg | `#f4f4f2` / `#ffffff` | yes | yes | yes |
| Sunken bg (code) | `#ebebe8` | yes | yes | yes |
| Text primary / secondary / muted | `#15181c` / `#4a4f57` / `#60666f` | yes | yes | yes |
| Border | `#e1e2df` 1px solid | yes | yes | yes |
| Accent (links, button bg) | `#0f5f70`, text on it `#ffffff` | yes | yes | yes |
| Accent subtle / text | `#e3f1f3` / `#0b4d5b` | yes | yes | yes |
| Success surface / text | `#e4f3ea` / `#135c33` | yes | yes | yes |
| Warning surface / text | `#fbf1dc` / `#704800` | yes | yes | yes |
| Danger surface / text | `#fbe7e5` / `#8f1c14` | yes | yes | yes |
| Info surface / text | `#e6eefa` / `#1f4790` | yes | yes | yes |
| Sizes (px) | 12/16 · 14/20 · 16/24 · 19/26 · 23/30 · 28/34 (size/line-height) | yes | yes | yes |
| Weights | 400, 500, 600 | yes | yes (600 may render as bold) | yes |
| Spacing (px) | 4, 8, 12, 16, 24, 32 | yes | yes | yes |
| Radius | 6px (badges), 8px (cards, button) | yes | web/iOS yes; Android partial | yes |
| Button | `min-height:44px; padding:12px 24px; display:inline-block` on an `<a>` | yes | yes | yes |

Deliberately excluded: shadows (Gmail strips), `rem`/`em` sizes (Gmail iOS rescales), web fonts
(Gmail ignores `@font-face`), dark-mode media queries (inconsistent; HEY and Gmail partially ignore),
`position`, `transform`, SVG inline (Gmail strips). Plain-text alternative part is mandatory (§5.5).

## 6. Accessibility rules

- **Contrast.** Every text colour on every background it may sit on meets WCAG 2.2 AA 4.5:1; UI
  boundaries (input outlines, focus ring, icons used alone) meet 3:1. The full measured table is §7;
  `node packaging/fixtures/design/contrast-check.mjs` reproduces it and exits 1 on regression. Only
  `text-disabled` is exempt (inactive controls). Never pair `text-muted` with a tinted semantic
  surface; use the matching `-text` token.
- **Focus visibility.** Every interactive element shows the `--focus-ring` (2px ring, 2px offset in
  canvas colour) via `:focus-visible`; never `outline: none` without replacing it. The ring colour is
  the accent, measured ≥ 6.6:1 against canvas and surface in both themes. On macOS the system focus
  ring is kept; do not suppress it.
- **Reduced motion.** `prefers-reduced-motion: reduce` collapses all durations to 1ms (so
  `transitionend` still fires). Mac code routes every animation through
  `Tokens.Motion.duration(_:reduceMotion:)`. No parallax, no auto-playing motion, no looping
  spinners longer than the operation they describe.
- **Touch targets (iPhone PWA).** Minimum hit area 44×44 CSS px (`--size-touch-min`, `min-h-touch
  min-w-touch`), 8px spacing between adjacent targets. Mac pointer surfaces may use `control-sm` (28px)
  only inside dense lists where the whole row is the target.
- **Dynamic Type / zoom.** Web type is in `rem` so browser zoom and iOS text size scale it; nothing is
  fixed-height except where `min-height` allows growth. Mac type builds on system sizes so it follows
  the user's text size setting.
- **Colour is never the only signal.** Semantic colours are always accompanied by an icon or text
  (stale, offline, error in the §1.1 menu bar panel each have a label).
- **No horizontal body scroll** at iPhone widths; wide tables scroll inside their own container
  (S1-04 acceptance).
- **Lighthouse.** Workstream C's DoD requires a Lighthouse accessibility pass; the token choices above
  (contrast, focus, target size) remove the structural causes of failures so remaining findings are
  content-level.

### Typography on macOS vs web

The two scales share names but not sizes, because the platforms have different reading distances and
rendering. The role mapping is what must agree.

| Role | Web (`tokens.css`) | Mac (`Tokens.Typography`) |
|---|---|---|
| Caption / label | `xs` 12px/16 | `caption`/`label` 11pt |
| Footnote / secondary | `sm` 14px/20 | `footnote` 12pt |
| Body | `md` 16px/24 | `body` 13pt |
| Headline (card title) | `lg` 19px/26, 600 | `headline` 15pt, semibold |
| Title (page/section) | `xl` 23px/30, 600 | `title` 17pt, semibold |
| Large title | `2xl` 28px/34, 600 | `titleLarge` 22pt, semibold |
| Display (first-run screens) | `3xl` 34px/40, 600 | `display` 28pt, semibold |

Weights are capped at 600 on both; 700 is not a token. Headings `xl` and above use `-0.01em`
tracking; uppercase labels use `xs` with `0.04em` tracking and never larger.

## 7. Measured contrast (generated)

Output of `node packaging/fixtures/design/contrast-check.mjs --markdown` against the committed
`tokens.css`. Regenerate and replace this table whenever a colour token changes.

| Theme | Foreground | Background | Role | Min | Ratio | Result |
|---|---|---|---|---|---|---|
| light | `text-primary` #15181c | `canvas` #f4f4f2 | text | 4.5:1 | **16.17:1** | pass |
| light | `text-primary` #15181c | `surface` #ffffff | text | 4.5:1 | **17.81:1** | pass |
| light | `text-primary` #15181c | `surface-raised` #ffffff | text | 4.5:1 | **17.81:1** | pass |
| light | `text-primary` #15181c | `surface-sunken` #ebebe8 | text | 4.5:1 | **14.91:1** | pass |
| light | `text-secondary` #4a4f57 | `canvas` #f4f4f2 | text | 4.5:1 | **7.49:1** | pass |
| light | `text-secondary` #4a4f57 | `surface` #ffffff | text | 4.5:1 | **8.24:1** | pass |
| light | `text-secondary` #4a4f57 | `surface-raised` #ffffff | text | 4.5:1 | **8.24:1** | pass |
| light | `text-secondary` #4a4f57 | `surface-sunken` #ebebe8 | text | 4.5:1 | **6.90:1** | pass |
| light | `text-muted` #60666f | `canvas` #f4f4f2 | text | 4.5:1 | **5.26:1** | pass |
| light | `text-muted` #60666f | `surface` #ffffff | text | 4.5:1 | **5.79:1** | pass |
| light | `text-muted` #60666f | `surface-raised` #ffffff | text | 4.5:1 | **5.79:1** | pass |
| light | `text-muted` #60666f | `surface-sunken` #ebebe8 | text | 4.5:1 | **4.85:1** | pass |
| light | `text-inverse` #f3f4f5 | `surface-inverse` #1c1f23 | text | 4.5:1 | **15.02:1** | pass |
| light | `text-on-accent` #ffffff | `accent` #0f5f70 | text | 4.5:1 | **7.26:1** | pass |
| light | `text-on-accent` #ffffff | `accent-hover` #0b4d5b | text | 4.5:1 | **9.43:1** | pass |
| light | `accent` #0f5f70 | `canvas` #f4f4f2 | text | 4.5:1 | **6.60:1** | pass |
| light | `accent` #0f5f70 | `surface` #ffffff | text | 4.5:1 | **7.26:1** | pass |
| light | `accent-text` #0b4d5b | `accent-subtle` #e3f1f3 | text | 4.5:1 | **8.15:1** | pass |
| light | `success` #1b7a43 | `surface` #ffffff | text | 4.5:1 | **5.37:1** | pass |
| light | `success-text` #135c33 | `success-surface` #e4f3ea | text | 4.5:1 | **7.02:1** | pass |
| light | `warning` #8a5a00 | `surface` #ffffff | text | 4.5:1 | **5.93:1** | pass |
| light | `warning-text` #704800 | `warning-surface` #fbf1dc | text | 4.5:1 | **7.16:1** | pass |
| light | `danger` #b4231a | `surface` #ffffff | text | 4.5:1 | **6.57:1** | pass |
| light | `danger-text` #8f1c14 | `danger-surface` #fbe7e5 | text | 4.5:1 | **7.54:1** | pass |
| light | `info` #2a5bb8 | `surface` #ffffff | text | 4.5:1 | **6.39:1** | pass |
| light | `info-text` #1f4790 | `info-surface` #e6eefa | text | 4.5:1 | **7.61:1** | pass |
| light | `border-strong` #878c93 | `surface` #ffffff | ui | 3:1 | **3.39:1** | pass |
| light | `border-strong` #878c93 | `canvas` #f4f4f2 | ui | 3:1 | **3.07:1** | pass |
| light | `focus-ring` #0f5f70 | `canvas` #f4f4f2 | ui | 3:1 | **6.60:1** | pass |
| light | `focus-ring` #0f5f70 | `surface` #ffffff | ui | 3:1 | **7.26:1** | pass |
| light | `accent` #0f5f70 | `surface` #ffffff | ui | 3:1 | **7.26:1** | pass |
| light | `success` #1b7a43 | `surface` #ffffff | ui | 3:1 | **5.37:1** | pass |
| light | `warning` #8a5a00 | `surface` #ffffff | ui | 3:1 | **5.93:1** | pass |
| light | `danger` #b4231a | `surface` #ffffff | ui | 3:1 | **6.57:1** | pass |
| light | `info` #2a5bb8 | `surface` #ffffff | ui | 3:1 | **6.39:1** | pass |
| dark | `text-primary` #f0f2f4 | `canvas` #0e1013 | text | 4.5:1 | **16.98:1** | pass |
| dark | `text-primary` #f0f2f4 | `surface` #15181c | text | 4.5:1 | **15.87:1** | pass |
| dark | `text-primary` #f0f2f4 | `surface-raised` #1c2026 | text | 4.5:1 | **14.57:1** | pass |
| dark | `text-primary` #f0f2f4 | `surface-sunken` #0a0c0e | text | 4.5:1 | **17.46:1** | pass |
| dark | `text-secondary` #b6bcc4 | `canvas` #0e1013 | text | 4.5:1 | **9.96:1** | pass |
| dark | `text-secondary` #b6bcc4 | `surface` #15181c | text | 4.5:1 | **9.31:1** | pass |
| dark | `text-secondary` #b6bcc4 | `surface-raised` #1c2026 | text | 4.5:1 | **8.55:1** | pass |
| dark | `text-secondary` #b6bcc4 | `surface-sunken` #0a0c0e | text | 4.5:1 | **10.24:1** | pass |
| dark | `text-muted` #8d949d | `canvas` #0e1013 | text | 4.5:1 | **6.22:1** | pass |
| dark | `text-muted` #8d949d | `surface` #15181c | text | 4.5:1 | **5.81:1** | pass |
| dark | `text-muted` #8d949d | `surface-raised` #1c2026 | text | 4.5:1 | **5.34:1** | pass |
| dark | `text-muted` #8d949d | `surface-sunken` #0a0c0e | text | 4.5:1 | **6.40:1** | pass |
| dark | `text-inverse` #15181c | `surface-inverse` #f3f4f5 | text | 4.5:1 | **16.17:1** | pass |
| dark | `text-on-accent` #0e1013 | `accent` #63bfd1 | text | 4.5:1 | **8.99:1** | pass |
| dark | `text-on-accent` #0e1013 | `accent-hover` #7fcddc | text | 4.5:1 | **10.59:1** | pass |
| dark | `accent` #63bfd1 | `canvas` #0e1013 | text | 4.5:1 | **8.99:1** | pass |
| dark | `accent` #63bfd1 | `surface` #15181c | text | 4.5:1 | **8.40:1** | pass |
| dark | `accent-text` #8fd6e3 | `accent-subtle` #12303a | text | 4.5:1 | **8.54:1** | pass |
| dark | `success` #4fc98a | `surface` #15181c | text | 4.5:1 | **8.53:1** | pass |
| dark | `success-text` #7ddba7 | `success-surface` #11301f | text | 4.5:1 | **8.57:1** | pass |
| dark | `warning` #e6b04a | `surface` #15181c | text | 4.5:1 | **9.05:1** | pass |
| dark | `warning-text` #f0c76b | `warning-surface` #332608 | text | 4.5:1 | **9.21:1** | pass |
| dark | `danger` #f2857a | `surface` #15181c | text | 4.5:1 | **7.13:1** | pass |
| dark | `danger-text` #f6a59c | `danger-surface` #3a1512 | text | 4.5:1 | **8.32:1** | pass |
| dark | `info` #85b4f2 | `surface` #15181c | text | 4.5:1 | **8.32:1** | pass |
| dark | `info-text` #a6c8f6 | `info-surface` #142540 | text | 4.5:1 | **8.93:1** | pass |
| dark | `border-strong` #666d77 | `surface` #15181c | ui | 3:1 | **3.41:1** | pass |
| dark | `border-strong` #666d77 | `canvas` #0e1013 | ui | 3:1 | **3.65:1** | pass |
| dark | `focus-ring` #63bfd1 | `canvas` #0e1013 | ui | 3:1 | **8.99:1** | pass |
| dark | `focus-ring` #63bfd1 | `surface` #15181c | ui | 3:1 | **8.40:1** | pass |
| dark | `accent` #63bfd1 | `surface` #15181c | ui | 3:1 | **8.40:1** | pass |
| dark | `success` #4fc98a | `surface` #15181c | ui | 3:1 | **8.53:1** | pass |
| dark | `warning` #e6b04a | `surface` #15181c | ui | 3:1 | **9.05:1** | pass |
| dark | `danger` #f2857a | `surface` #15181c | ui | 3:1 | **7.13:1** | pass |
| dark | `info` #85b4f2 | `surface` #15181c | ui | 3:1 | **8.32:1** | pass |

70/70 pairs pass; 0 failure(s).

## 8. Out of scope for S0-24

Component styles (buttons, inputs, cards), the PWA layout (S1-04), the Blade mail template (S1-03),
the `MenuBarExtra` panel (S1-09), icons and the app icon, and the Figma file. Those consume this
contract; they do not extend it without a PR to this document.
