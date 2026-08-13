---
name: Fynix
description: Fynix Cyber Audit operator UI — calm chrome, mint accent, loud status signals.
colors:
  primary: "#75FC96"
  primary-text: "#17A94C"
  primary-deep: "#0C6B2F"
  primary-soft: "#E9FEF0"
  primary-mid: "#C9FDD9"
  brand-forest: "#132D28"
  brand-tile: "#B0E67F"
  danger: "#D13817"
  canvas: "#F7F7F5"
  surface: "#FFFFFF"
  surface-inset: "#EFEFED"
  border: "#E3E3E1"
  border-strong: "#CFCFCD"
  text: "#0A0A0A"
  text-secondary: "#8A8A88"
  text-tertiary: "#4D4D4B"
  status-green: "#17A94C"
  status-green-bg: "#E9FEF0"
  status-amber: "#B96A00"
  status-amber-bg: "#FFF4E0"
  status-red: "#D13817"
  status-red-bg: "#FDECE7"
  status-blue: "#2563EB"
  status-blue-bg: "#EBF1FE"
  status-neutral: "#8A8A88"
  status-neutral-bg: "#EFEFED"
typography:
  display:
    fontFamily: "\"Manrope Variable\", Manrope, \"Segoe UI\", system-ui, sans-serif"
    fontSize: "32px"
    fontWeight: 800
    lineHeight: "40px"
  headline:
    fontFamily: "\"Manrope Variable\", Manrope, \"Segoe UI\", system-ui, sans-serif"
    fontSize: "24px"
    fontWeight: 700
    lineHeight: "32px"
  title:
    fontFamily: "\"Manrope Variable\", Manrope, \"Segoe UI\", system-ui, sans-serif"
    fontSize: "18px"
    fontWeight: 700
    lineHeight: "28px"
  body:
    fontFamily: "\"Manrope Variable\", Manrope, \"Segoe UI\", system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: "22px"
  label:
    fontFamily: "\"Manrope Variable\", Manrope, \"Segoe UI\", system-ui, sans-serif"
    fontSize: "12px"
    fontWeight: 600
    lineHeight: "16px"
  metric:
    fontFamily: "\"Manrope Variable\", Manrope, \"Segoe UI\", system-ui, sans-serif"
    fontSize: "40px"
    fontWeight: 800
    lineHeight: "44px"
  mono:
    fontFamily: "\"JetBrains Mono Variable\", \"JetBrains Mono\", ui-monospace, monospace"
    fontSize: "13px"
    fontWeight: 400
    lineHeight: "20px"
rounded:
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  full: "999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
  page: "24px"
  card: "24px"
  grid-gap: "16px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.text}"
    rounded: "{rounded.md}"
    padding: "0 22px"
    height: "40px"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.md}"
    padding: "0 20px"
    height: "40px"
  button-destructive:
    backgroundColor: "{colors.danger}"
    textColor: "{colors.surface}"
    rounded: "{rounded.md}"
    padding: "0 20px"
    height: "40px"
  card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.xl}"
    padding: "{spacing.card}"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.sm}"
    padding: "0 12px"
    height: "40px"
  chip-status:
    backgroundColor: "{colors.status-green-bg}"
    textColor: "{colors.status-green}"
    rounded: "{rounded.full}"
    padding: "3px 10px"
---

# Design System: Fynix Cyber Audit

Suite source: `ppm/design.md`. This product uses the same Fynix language. Do not invent a second brand hue.

## Overview

**Creative North Star: "The Quiet Control Room"**

Fynix Cyber Audit is an all-day operator product for GRC, audit, and vendor-risk roles. The visual system is a **calm, warm-gray control surface** where mint is the single brand pulse and **status color is reserved for meaning** — control effectiveness, workflow, risk, exceptions.

Personality is **confident, precise, optimistic**: black text on white cards, soft radii, tabular metrics, and rare mint fills. Playfulness lives in the bird mark on a soft green tile — never in the evidence.

**Key Characteristics:**
- Near-monochrome chrome; mint as fill accent only
- Dense insides (tables, evidence grids), airy outsides (card padding, 16px grid gap)
- Status chips with labels + dots; RAG as green/amber/red only
- Manrope Variable + JetBrains Mono
- Soft 8–24px radii; card shadow ambient, not dramatic
- Filament sidebar + topbar restyled as Fynix chrome, not dark GRC-blue

## Colors

Implementation lives in `resources/css/tokens.css`. Components must not hard-code hex.

### Primary
- **Signal Mint** (`#75FC96`): brand fill — primary buttons, active nav, chart highlight. **Fill only**; never body text on white.
- **Operator Green** (`#17A94C`): AA-safe mint for text, icons, focus ring, input focus border.
- **Deep Forest Mint** (`#0C6B2F`): text/links on mint-tint surfaces.
- **Mint Wash / Leaf** (`#E9FEF0` / `#C9FDD9`): selected rows, soft highlights.
- **Fynix Tile / Forest** (`#B0E67F` / `#132D28`): logo tile and bird mark.

### Neutral
- **Ink** (`#0A0A0A`): primary text, dark buttons.
- **Canvas** (`#F7F7F5`): app background.
- **Paper** (`#FFFFFF`): cards, topnav, inputs.
- **Inset / Border / Strong** (`#EFEFED` / `#E3E3E1` / `#CFCFCD`).
- **Muted / Quiet** (`#8A8A88` / `#4D4D4B`).

### Semantic status (GRC mapping)
| Token | Use here |
|---|---|
| Green | Effective, completed, accepted, in-scope |
| Amber | Partial, in progress, pending, expiring |
| Red | Ineffective, rejected, overdue, critical risk |
| Blue | Informational, not-started, draft |
| Neutral | Unknown, not assessed, deactivated |

### Named Rules
**The Fill-Not-Type Rule.** `#75FC96` is never text or thin icons on white; use `--mint-600` or darker.

**The One Accent Rule.** Brand mint appears sparingly. Status red/amber/green only encode real state.

**The Never-Color-Alone Rule.** Every status treatment includes a label, numeral, or icon.

**The No-GRC-Blue Rule.** Legacy `#1375A0` / slate sidebar is retired. Do not reintroduce a second brand blue.

## Typography

**Sans:** Manrope Variable. **Mono:** JetBrains Mono for codes (`AC-2`, `POL-014`, request IDs).

- Display 800 32/40 — auth and empty-state heroes
- Headline 700 24/32 — page titles
- Title 700 18/28 — card titles
- Body 400 14/22 — default UI and table cells
- Label 600 12/16 — field labels, chips, column headers
- Metric 800 40/44 — dashboard KPIs (`tabular-nums`)

## Layout

Filament shell: light sidebar (`232px` / collapsed `64px`), canvas `#F7F7F5`, paper cards. Page padding `24px`. Grid gap `16px`. Dense table cells; cards `24px` padding.

**The Dense-Inside, Airy-Outside Rule.** Pack evidence inside tables; keep air between cards.

## Elevation & Shapes

- Card rest: hairline `--gray-200` + `--shadow-card`
- Overlay: `--shadow-overlay` for modals
- Radii: inputs 8px, buttons 12px, cards 24px, chips/pills full
- Brand mark: 32×32 tile, ~30% corner radius

## Components (Filament mapping)

- **Primary button:** mint fill, ink text, 40px, weight 700
- **Secondary:** paper + gray-300 border
- **Destructive:** `#D13817`, white text
- **Sidebar item active:** mint fill, ink text (not navy)
- **Badge / status:** pill + leading 6px dot + label
- **Input focus:** mint-600 border + mint-200 ring
- **Auth lockup:** full Fynix wordmark (`fynix_logo_dark.png` / `_white.png`); icon for favicon

## Assets

From `ppm/frontend/public/`, shipped in `public/img/`:
- `fynix_logo_dark.png` / `fynix_logo_white.png`
- `logo_icon.png` / `logo_bird_dark.png` / `logo_bird_white.png`

## Do's and Don'ts

**Do:** tokens only; pair RAG with a label; one mint CTA per surface; tabular KPI numbers.

**Don't:** `#75FC96` as text on white; invent a second brand hue; restore GRC-blue chrome; Bruno Ace as the product face; hard-code hex in Blade/PHP views.

**Source of truth:** this file · `resources/css/tokens.css` · `resources/css/filament/app/theme.css`  
**Suite parent:** `ppm/design.md`
