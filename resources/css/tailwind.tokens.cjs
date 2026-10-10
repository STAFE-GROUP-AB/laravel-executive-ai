/**
 * Tailwind theme extension mapping resources/css/tokens.css (S0-24).
 *
 * Every value points at a CSS custom property, so dark mode, reduced motion and any
 * future theme switch happen in CSS without a Tailwind rebuild.
 *
 * tailwind.config.js (Tailwind 3, or Tailwind 4 via `@config` in app.css):
 *
 *   module.exports = {
 *       content: ['./resources/**\/*.blade.php', './resources/**\/*.js', './app/**\/*.php'],
 *       theme: { extend: require('./resources/css/tailwind.tokens.cjs') },
 *   };
 *
 * Tailwind 4 app.css:
 *
 *   @import "tailwindcss";
 *   @import "./tokens.css";
 *   @config "../../tailwind.config.js";
 *
 * Resulting classes (non-exhaustive): bg-canvas, bg-surface, bg-surface-raised,
 * bg-surface-sunken, bg-surface-inverse, text-primary, text-secondary, text-muted,
 * text-inverse, text-on-accent, text-disabled, border-default, border-strong,
 * bg-accent, text-accent, bg-accent-subtle, text-accent-text, bg-success-surface,
 * text-success-text, ring-focus, shadow-1..4, rounded-xs..2xl, text-xs..3xl,
 * duration-fast/base/slow/deliberate, ease-standard, z-modal, min-h-touch.
 */

const colorRoles = ['success', 'warning', 'danger', 'info'];

const semantic = Object.fromEntries(
    colorRoles.map((role) => [
        role,
        {
            DEFAULT: `var(--color-${role})`,
            surface: `var(--color-${role}-surface)`,
            text: `var(--color-${role}-text)`,
        },
    ])
);

module.exports = {
    colors: {
        canvas: 'var(--color-canvas)',
        surface: {
            DEFAULT: 'var(--color-surface)',
            raised: 'var(--color-surface-raised)',
            sunken: 'var(--color-surface-sunken)',
            inverse: 'var(--color-surface-inverse)',
        },
        overlay: 'var(--color-overlay)',
        accent: {
            DEFAULT: 'var(--color-accent)',
            hover: 'var(--color-accent-hover)',
            subtle: 'var(--color-accent-subtle)',
            text: 'var(--color-accent-text)',
        },
        focus: 'var(--color-focus-ring)',
        ...semantic,
    },

    textColor: {
        primary: 'var(--color-text-primary)',
        secondary: 'var(--color-text-secondary)',
        muted: 'var(--color-text-muted)',
        inverse: 'var(--color-text-inverse)',
        'on-accent': 'var(--color-text-on-accent)',
        disabled: 'var(--color-text-disabled)',
    },

    borderColor: {
        DEFAULT: 'var(--color-border)',
        default: 'var(--color-border)',
        strong: 'var(--color-border-strong)',
    },

    divideColor: {
        DEFAULT: 'var(--color-border)',
    },

    ringColor: {
        DEFAULT: 'var(--color-focus-ring)',
        focus: 'var(--color-focus-ring)',
    },

    ringOffsetColor: {
        DEFAULT: 'var(--color-canvas)',
        canvas: 'var(--color-canvas)',
        surface: 'var(--color-surface)',
    },

    fontFamily: {
        sans: 'var(--font-sans)',
        mono: 'var(--font-mono)',
    },

    fontSize: {
        xs: ['var(--text-xs)', { lineHeight: 'var(--leading-xs)' }],
        sm: ['var(--text-sm)', { lineHeight: 'var(--leading-sm)' }],
        md: ['var(--text-md)', { lineHeight: 'var(--leading-md)' }],
        base: ['var(--text-md)', { lineHeight: 'var(--leading-md)' }],
        lg: ['var(--text-lg)', { lineHeight: 'var(--leading-lg)' }],
        xl: ['var(--text-xl)', { lineHeight: 'var(--leading-xl)', letterSpacing: 'var(--tracking-tight)' }],
        '2xl': ['var(--text-2xl)', { lineHeight: 'var(--leading-2xl)', letterSpacing: 'var(--tracking-tight)' }],
        '3xl': ['var(--text-3xl)', { lineHeight: 'var(--leading-3xl)', letterSpacing: 'var(--tracking-tight)' }],
    },

    fontWeight: {
        regular: 'var(--font-weight-regular)',
        medium: 'var(--font-weight-medium)',
        semibold: 'var(--font-weight-semibold)',
    },

    letterSpacing: {
        tight: 'var(--tracking-tight)',
        normal: 'var(--tracking-normal)',
        wide: 'var(--tracking-wide)',
    },

    borderRadius: {
        none: 'var(--radius-none)',
        xs: 'var(--radius-xs)',
        sm: 'var(--radius-sm)',
        md: 'var(--radius-md)',
        DEFAULT: 'var(--radius-md)',
        lg: 'var(--radius-lg)',
        xl: 'var(--radius-xl)',
        '2xl': 'var(--radius-2xl)',
        full: 'var(--radius-full)',
    },

    boxShadow: {
        0: 'var(--shadow-0)',
        1: 'var(--shadow-1)',
        2: 'var(--shadow-2)',
        3: 'var(--shadow-3)',
        4: 'var(--shadow-4)',
        focus: 'var(--focus-ring)',
        none: 'none',
    },

    transitionDuration: {
        instant: 'var(--duration-instant)',
        fast: 'var(--duration-fast)',
        base: 'var(--duration-base)',
        DEFAULT: 'var(--duration-base)',
        slow: 'var(--duration-slow)',
        deliberate: 'var(--duration-deliberate)',
    },

    transitionTimingFunction: {
        standard: 'var(--ease-standard)',
        DEFAULT: 'var(--ease-standard)',
        decelerate: 'var(--ease-decelerate)',
        accelerate: 'var(--ease-accelerate)',
    },

    zIndex: {
        base: 'var(--z-base)',
        raised: 'var(--z-raised)',
        sticky: 'var(--z-sticky)',
        dropdown: 'var(--z-dropdown)',
        overlay: 'var(--z-overlay)',
        modal: 'var(--z-modal)',
        popover: 'var(--z-popover)',
        toast: 'var(--z-toast)',
        tooltip: 'var(--z-tooltip)',
    },

    minHeight: {
        touch: 'var(--size-touch-min)',
        'control-sm': 'var(--size-control-sm)',
        'control-md': 'var(--size-control-md)',
        'control-lg': 'var(--size-control-lg)',
    },

    minWidth: {
        touch: 'var(--size-touch-min)',
    },

    maxWidth: {
        content: 'var(--layout-content-max)',
        readable: 'var(--layout-readable-max)',
    },
};
