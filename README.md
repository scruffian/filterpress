# FilterPress

SVG filter goodies for the WordPress block editor.

FilterPress adds a handful of expressive, server-rendered SVG filter effects to core blocks — no JavaScript required on the frontend (except for the press-to-deform button interaction, which uses the Interactivity API).

## Effects

- **Grainy gradients** — overlay tunable noise on any block with a gradient background. Works on any block that opts into core gradient support.
- **Turbulence (press-to-deform buttons)** — `core/button` blocks deform on press/tap using `feTurbulence` + `feDisplacementMap`. Effects: *Rough edges*, *Inky bleed*, *Watercolor*, *Wavy*.
- **Squiggle** — animated hand-drawn "boiling" wiggle for text, driven by SMIL animation of an `feTurbulence` seed. Controls for intensity and FPS.
- **Grunge edges** — jagged/torn/brushed/splattered/burst/stamped edge treatments for `core/image` blocks, including a border-painted variant that chews the image's CSS border.

## How it works

- Block attributes (`filterpressGrainy`, `filterpressTurbulence`, `filterpressSquiggle`, `filterpressGrunge`) are added to eligible blocks via editor filters.
- On render, `render_block` records which filters are actually in use on the page.
- A single `<svg><defs>` block plus a scoped `<style>` tag is emitted in `wp_footer` containing only the filters used — no payload for unused effects.
- Grainy gradients use a tiled noise SVG data URI applied via a `::before` pseudo-element with `mix-blend-mode: multiply`.

## Requirements

- WordPress 6.3+
- PHP 7.4+

## Installation

Drop the plugin into `wp-content/plugins/filterpress/` and activate it.

## Development

- `build/editor.js` — editor UI bundle (controls in the block inspector)
- `build/view.js` — frontend Interactivity API module for the press-to-deform button

## License

GPL-2.0-or-later
