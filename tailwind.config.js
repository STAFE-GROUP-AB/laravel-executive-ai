/*
 * Loaded by resources/css/app.css through Tailwind 4's `@config` directive.
 * The whole theme extension lives in resources/css/tailwind.tokens.cjs (S0-24);
 * do not add colours, radii or type sizes here — add a token instead.
 */
module.exports = {
    content: ['./resources/**/*.blade.php', './resources/**/*.js', './app/**/*.php'],
    theme: { extend: require('./resources/css/tailwind.tokens.cjs') },
};
