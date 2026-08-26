# Enact brand on this site

How the August 2026 Enact brand identity (designer: Laura Coley, via Victoria
Burgher's 19 Aug email "Fw: Enact logo #3 options") is implemented, and what is
still pending from the designer.

## Logo

- Source artwork: `content/media/Enact_logo.svg` (full) and
  `content/media/Enact_logo_90px_MinSize.svg` (redrawn for small sizes). These
  SVGs are kept in the repo as source-of-truth artwork but are **not** uploaded
  to WordPress (core disallows SVG uploads); the site uses PNG renders:
  - `enact-logo-header.png` — 180x180 render of the MinSize art, tight-cropped
    to the framing device, shown at 90px in the header (2x for retina).
  - `enact-site-icon.png` — 512x512 render of the MinSize art (site icon /
    favicon).
  - `enact-logo-full.png` — 600x600 render of the full art for use in content.
- **Minimum onscreen size is 90px** for the framing device. At or below ~90px
  use the MinSize art (thicker letterforms). Do not render the wordmark smaller.
- The header shows the logo only (no site-title text): the logo is itself the
  "enact" wordmark, so pairing it with the site title would duplicate the name.
- Black/white and print variants are coming later with Laura's final artwork.

## Colour

Grounded in the logo SVGs (the only brand artwork with real hex values so far):

| Slot | Hex | Name | Status |
|------|-----|------|--------|
| `base` | `#FFFFFF` | Base | grounded (logo wordmark white) |
| `contrast` | `#12211D` | Contrast | derived: deep green-black, hue of the brand aqua |
| `accent-1` | `#45BC99` | Brand Aqua | **grounded: the framing-device fill** |
| `accent-2` | `#E9F7F3` | Aqua Tint | derived: pale tint of the brand aqua |
| `accent-3` | `#29705B` | Deep Aqua | derived: darkened brand aqua for links/hover (AA on white) |
| `accent-4` | `#686868` | Gray | neutral, carried over from the theme |
| `accent-5` | `#FBFAF3` | Off White | neutral placeholder — see pending below |

**Pending from the designer:** Laura's palette PDF (taupe primary, secondary
accents including "Tomato") was never forwarded with hex values. The taupe slot
is stood in by the neutral `accent-5` off-white, and no Tomato accent exists on
the site yet. When the palette PDF or hex list arrives, update the values in
`content/global-styles.json` and re-apply (see below) — the band markup
references slots, not hexes, so a palette swap needs no content edits.

All in-use text/background pairs pass WCAG 2.1 AA (4.5:1 normal text); the
derivation targets are documented in the accessibility-readiness note in the
project drive. Aqua button fills on white are 2.36:1 against the page
background, which is acceptable under SC 1.4.11 because the component is
identified by its label text (7.06:1); add a border or switch fills to
`accent-3` if UoW's reviewers want the stricter reading.

## Typography

Brand fonts are **FF Real Head Bold** (headlines) and **FF Real Text Regular**
(body). They are commercial (Adobe Fonts / MyFonts) and webfont licensing is
unresolved, so the site currently runs the designer-approved fallback:
**IBM Plex Sans** (OFL, free), self-hosted — no Google Fonts requests at
runtime, no third-party font CDN.

- Files: `content/fonts/IBMPlexSans-Variable.woff2` (upright, weight axis
  100-700) and `IBMPlexSans-Italic-Variable.woff2` (italic). Latin subset.
- Uploaded to the WordPress Font Library by `bin/provision` (`fonts` section)
  via the `/wp/v2/font-families` REST endpoints; files land in
  `wp-content/uploads/fonts/`.
- `content/global-styles.json` activates the family
  (`settings.typography.fontFamilies.custom`) and applies it: body 400,
  headings 700, buttons 600.

### Swapping in FF Real when licensing resolves

1. Obtain licensed webfont files (WOFF2): FF Real Head Bold (+ Bold Italic),
   FF Real Text Regular (+ Italic; Light/Demibold optional).
2. Drop them in `content/fonts/` and add a family entry (or two: Head and
   Text) to the `fonts` array in `content/site.json`.
3. In `content/global-styles.json`, point
   `styles.typography.fontFamily` at the FF Real Text family preset and
   `styles.elements.heading.typography.fontFamily` at FF Real Head, keeping
   IBM Plex Sans in each family's `fontFamily` fallback stack.
4. Re-apply: `bin/provision ... --only fonts,global-styles`.

Nothing in page/post content references a font family directly, so the swap is
contained to those two files.

## Applying brand changes to a live environment

Content on production is owned by editors once testing starts; never run a full
provision against it. Brand changes deploy with:

    bin/provision --base-url https://enact-wp-production.enacthyku.com \
        --user notch8-admin --app-password '...' \
        --only media,fonts,template-parts,global-styles,site-branding

which touches media (additive), fonts, the header/footer template parts, global
styles, and the site icon + logo settings — and nothing else (no pages, posts,
navigation, or other settings).

The home hero band has two approved looks; `bin/hero-variant` switches between
them surgically (preserving editor changes elsewhere on the page):

    bin/hero-variant --base-url ... --user ... --app-password '...' --variant aqua|dark
