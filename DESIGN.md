# DESIGN.md — Extension Hub

Visual language for Extension Hub's extension browser (Discover landing + list
views), implemented 2026-08 from the "GNOME Hub Final" reference. Dark-only,
GNOME/Adwaita-inspired. Binding for UI agents implementing or reviewing this
project's frontend.

## 1. Atmosphere & Design Intent

- Calm, flat, GNOME-Adwaita-like dark UI. Content-first, chrome-quiet.
- Dark only — no light theme, no theme switch.
- Page background `#242424`. App shell capped at 1600px, centered
  (`margin-inline: auto`). Surfaces separate by brightness steps only; no
  borders on main surfaces.
- No remote fonts/icons: Inter weights 400/500/600/700 are extracted from the
  supplied Final reference and bundled under `assets/fonts/`; every icon is
  inline SVG or a CSS mask. Never add CDN/font/iconify links.

## 2. Visual Dominance

- The blur-backed hero panels and the extension rows carry the page; header
  and sidebar card stay quiet and monochrome.
- Interactive accent: GNOME blue `#3584e4` (brand tile, CTA, active nav tint,
  focus ring). Semantic exceptions: violet `#c4b5fd`/`rgba(139,92,246,.24)`
  (Trending badge), amber `#f9d16a`/`rgba(230,165,10,.24)` (Popular badge),
  green `#8ff0a4`/`rgba(143,240,164,.15)` (New count pill), green `#33d17a`
  (verified seal), yellow `#f5c211` (rating stars). Link blue `#78aeed`
  (hover `#99c1f1`) for "See all".

## 3. Colors & Semantic Roles

| Token | Value | Role |
| --- | --- | --- |
| page background | `#242424` | Body / content canvas |
| `--surface-header` | `#303030` | Sticky header bar |
| `--surface-sidebar` | `#1f1f1f` | Sidebar card |
| tile surface | `#2b2b2b` | Category tiles (hover `#303030`) |
| `--surface-raised` | `#333` | Sort menu, hover popover |
| `--surface-inset` | `#161616` | Popover screenshot backdrop; `#151515` framed hero shot |
| `--accent-primary` | `#3584e4` | Brand tile, CTA, focus ring |
| install solid | `#2c4f80` (hover `#38619c`) | Install button |
| active nav tint | `rgba(53,132,228,.22)` | Active Explore row |
| sort option active | `rgba(53,132,228,.18)` | Active sort menu entry |
| tile icon tint | `rgba(53,132,228,.16)` box, icon `#99c1f1` | Category tiles |
| text primary | `#fff` | Names, titles, active labels |
| text secondary | `rgba(255,255,255,.6–.82)` | Descriptions, nav labels |
| text faint | `rgba(255,255,255,.38–.55)` | Counts, notes, hints |
| hover chrome | `rgba(255,255,255,.06–.13)` | Nav/cat rows, pills, tiles |

Rules: white overlays for states, never new hues. Hero panel fallback
`radial-gradient(120% 140% at 85% 20%, #2a2a2a 0%, #1b1b1b 60%)`; hero scrim
`linear-gradient(90deg, #1b1b1b 0%, rgba(27,27,27,.72) 46%, rgba(27,27,27,.3) 100%)`.

## 4. Typography

- Primary family: bundled **Inter** (`"Inter", "Adwaita Sans", system-ui,
  sans-serif`); system fonts are fallback only.
- Scale: hero title 42/700 (popular 38, ≤1023px 34/32, ≤759px 26),
  letter-spacing normal · section title 27/700 · list title 26/700 · row
  name 17/600 · row description 13/1.45 · body/meta 13–15 · sidebar labels
  12/600 sentence case (never uppercase or letter-spaced) · counts 12–13
  (always English comma grouping via `formatCount`, e.g. `3,256`) · rating
  value 13/600 · CTA 15/600.
- UI chrome (single-line labels/controls: nav, category and source rows,
  badges, pills, buttons) uses line-height 1; reading text keeps document
  default.
- Names and row descriptions truncate single-line with ellipsis.

## 5. Spacing, Layout & Containers

- Header 66px sticky `#303030` z60, inner grid `[brand min 244px | search
  ≤644px centered | "About" control + GitHub tile 32×32 right]`,
  padding 0 28, capped at 1600px.
  Right slot: both controls sit in one flex row, gap 8, right-aligned.
  "About" is a labelled pill (h32 r8 bg `rgba(255,255,255,.08)`, hover
  `.14`, padding 0 12, circle-info glyph 15 monochrome `currentColor` +
  label 13/500 white) linking to `/use-the-data`; never a database glyph — the
  page is an explainer, not a download. The GitHub tile keeps its icon-only
  32×32 shape.
  Brand: inline "Extension Hub" wordmark logo (white puzzle glyph +
  wordmark, 167×37, with a faint "GNOME Shell Extensions" tagline) sitting
  directly on the header — no tile, no separate wordmark span. Search: h34 r9 bg
  `rgba(0,0,0,.32)`, magnifier
  15, placeholder `rgba(.42)`, Ctrl/K caps h20 r5
  bg `rgba(255,255,255,.1)` 11/500 `rgba(.62)` (K fixed 20 wide).
- Body grid: `320px | minmax(0,1fr)`, gap 44, padding `32px 28px 56px`,
  content-box 1600px (outer 1656px incl. side paddings), centered. Below
  1024px: single column, gap 24, padding 20/16/40.
- Sidebar: rounded card `#1f1f1f` r16, padding `24px 16px 28px`, sticky top
  86 (≥1024px), group gap 38, no borders. Explore group carries a visible
  "Explore" label (like the other groups). Group internals use 1px row
  gaps; labels keep a 5px bottom margin, so their first row starts 6px below.
  Explore rows h36 r8 padding 0 11; category rows h34 padding 0 11; sources
  rows h34 padding 0 11 non-interactive.
- Content column: flex, gap 34 (discover blocks only, gap 64). List views
  wrap header + rows + load-more in `eh-list-view` with gap 18
  (header→rows 18px).
- Row: flex wrap, row-gap 10 / column-gap 20, padding 14, radius 10. Icon tile
  56 r13 (image 46 contain). Text `flex 1 1 260` min 220. Metrics right
  (`margin-left:auto`, nowrap, gap 22): rating slot 150px flex-end, action
  slot 100px flex-end.
- Radii: rows 10, nav 8, hero panel 16, framed shot `12 12 0 0`, popover 12,
  screenshot 8, sort pill/menu 999/10, load-more 999.

## 6. Sidebar Taxonomy (binding)

- Explore order exactly: **Discover, Trending, Popular, New (green count
  pill), All extensions**. Preset mapping: discover→`discover` (default
  landing), trending→`hot`, popular→`popular`, new→`new`, all→`all`. No
  Hot/Rising/Updated labels.
- Categories (order + labels): Top Bar & Indicators; Docks, Menus &
  Launchers; Windows & Workspaces; Appearance & Themes; System & Power;
  Productivity Tools; Audio, Media & Display; Devices & Connectivity.
  Keys/logic live in `assets/app/categories.js` (keyword heuristic); clicking
  a category routes to preset `all` + category filter.
- Compatibility group sits between Categories and "About Extension Hub": label
  "Compatibility" + one dropdown (group gap 6, wrapper inset `0 4px`).
  Trigger h36 r8 padding 0 12 bg `rgba(255,255,255,.07)` (hover .12), label
  14/400 `rgba(.88)` — "Any GNOME version" or "Works with GNOME {v}" —
  chevron 14 `rgba(.6)` rotating 180° when open. Menu absolute top 42,
  left/right 4, z50, max-height 280 scrollable, padding 6, r10, bg `#333`,
  shadow `0 18px 40px rgba(0,0,0,.6)`; options h32 r7 padding 0 11, active
  blue tint `rgba(53,132,228,.18)` + white check 14. Because the menu is a
  column flex box with a capped height and the entry list runs into the
  hundreds, options must pin their size (`flex: 0 0 32px` + `min-height`):
  with the default `flex-shrink: 1` they collapse to the 14px text box and the
  menu never scrolls. The sort menu options carry the same guard at 34px.
  Entries: "Any GNOME
  version" then **every explicitly declared numeric shell version** of the
  snapshot — majors (`51`), series (`3.36`) and point releases (`40.0`,
  `3.28.4`) — sorted newest first segment by segment; tagged declarations
  (`40.beta`, `47.mobile.0`) never appear. Values stay verbatim, so `40` and
  `40.0` are separate entries. Opening the menu scrolls the active entry into
  view because the list spans the full release history.
- Group order is exactly Explore → Categories → Compatibility → **About
  Extension Hub** (label verbatim; the CSS/markup hook stays
  `eh-side-group--project`). It is the last group (see intentional deviations),
  not a global page footer. It opens with the two informational, not clickable source counters —
  extensions.gnome.org (blue dot 13px) with snapshot count, GitHub (mark 13px)
  with source count; GitHub is a **source**, never a visible category — and
  there is no separate "Sources" group or label.

## 7. Components & States

- **Discover landing** (default, preset `discover`, no search/category) in
  order: Trending hero slider · "Trending now" row section (5 rows) ·
  Popular section (header + slider) · "New extensions" row section (5
  rows) · "Browse by category" grid (8 tiles, 4 columns, tile: icon box 40
  r10 + label 600/14 + "N extensions" 12). Section headers: title 27/700 +
  note 13 `rgba(.5)` + "See all" link (13/500 `#78aeed`, arrow). Popular
  header: title "Popular", note "The most downloaded GNOME Shell
  extensions.", See all → popular preset. Trending section shows trending
  ranks 2–6; New section shows newest ranks 2–6 (rank 1 lives in the
  sliders). Vertical rhythm: 64px discover block gap + trending hero
  margin-bottom 20 + section-head margin-top 16 + Popular section extra
  margin-top 20.
- **Hero/Popular sliders:** no prev/next arrows. Backdrop = slide screenshot
  blurred `blur(48px) scale(1.3)` opacity .45 + scrim; framed screenshot
  absolute right 40, top 46 (popular 42), bottom 0, width 50%, r12 12 0 0,
  bg `#151515`, cover top-center, shadow `0 -6px 40px rgba(0,0,0,.45)`. Text
  column width 50%, padding 32/44/46/56 (popular 30/44/46/56), gap 12,
  justify-center; desktop panels capped at the reference-measured heights
  434 (popular 396) — description one-line ellipsis, title max 2 lines
  (line-clamp), CTA/rating/badge never clip. Mobile (≤759px): height auto and
  the framed screenshot restacks above the text — see §9.
  Trending slide titles are H1, popular slide titles H3 (same visual
  class). Trending head: icon tile 54
  r14 (image 44) + violet "Trending" badge 24 h; Popular: amber "Popular"
  badge 22 h alone. Rating row: stars 95×17 (popular 90×16) + label
  14 `rgba(.55)` ("4.5 rating · 12 reviews"; unrated trending "No reviews
  yet", unrated popular compact downloads). CTA "View extension" solid
  `#3584e4` h42 r10 600/15. Dots bottom 22 left 56: 8px, active 22px white.
  Pools: top 5 trending (icon+screenshot) / top 5 by downloads (screenshot);
  crossfaded slides stacked in one grid cell; auto-rotate 6s paused on
  hover/focus; single-item pools render statically without dots.
- **List views:** header = title 26/700 + note 15 `rgba(.55)`, gap 18 above
  rows. Trending/Popular/New have **no sort control**. All extensions and
  category views show a sort pill (h32 r999 bg `rgba(255,255,255,.08)`,
  chevron) opening a menu (absolute right, w210, r10, bg `#333`, shadow
  `0 18px 40px rgba(0,0,0,.6)`, options h34 r7; active blue tint + check):
  Most downloaded (`downloads`, default) | Trending this week (`trend_7d`) |
  Newest (`recent`) | Recently updated (`updated`) | Highest rated (`rating`)
  | Name A–Z (`name`). Notes: Trending "Extensions gaining attention this
  week." / Popular "The most downloaded GNOME Shell extensions." / New
  "Recently published extensions." / All "Every GNOME Shell extension in the
  index." / Category "{label}" + "The most downloaded extensions in this
  category."
- **Row:** 56px icon tile r13. Generic placeholder icons
  (`/static/images/plugin.png` or missing) with a screenshot render the
  screenshot as the tile backdrop: cover, `blur(14px) saturate(2.5)
  scale(1.6)`, dimmed `rgba(0,0,0,.2)` (`.eh-row-icon-shot`), with the
  letter-tile letter on top; the same blurred-screenshot fallback (without
  letter) applies to the trending hero icon tile (`.eh-hero-icon-shot`) and
  the detail-page icon slots. Only icon-less items without a screenshot get
  the colored letter tile from the fixed palette `#613583 #1c71d8 #2ec27e
  #c64600 #986a44 #e5a50a #a51d2d` indexed by `pk % 7`, first letter 26/700
  white. Name 17/600 + verified seal
  (official GNOME repo: `gitlab.gnome.org/GNOME` or
  `git.gnome.org/gnome-shell-extensions`) + source glyph 14 `#8a8a8a`
  (github/gitlab/globe). Description single-line 13. No downloads/GitHub
  stars in rows. Install button h34 r9 600/14; whole row is the click target
  (stretched link); Install clicks must not navigate.
- **Compatibility:** detected shell = highest major-only version with ≥5%
  snapshot coverage (currently 50). Compatible iff `supportedShellVersions`
  contains that major as an explicit numeric entry (e.g. "50"); otherwise the
  whole row gets opacity .6 and the action becomes an "Archived" pill (bg
  `rgba(255,255,255,.08)`, text `rgba(.5)`). Popover shell label: compatible
  `GNOME {min}–{max}`, else `up to {max}` (major-only versions, sorted).
  The sidebar **compatibility selection** is a second, explicit gate on top
  of that display rule: a chosen version filters the whole directory
  (Discover hero, Discover sections, sidebar counts, every list) down to
  extensions declaring exactly that version string — no ranges, no minimum
  version, so a `46` declaration does not satisfy a `46.2` selection;
  extensions without any declared version disappear. "Any GNOME version" shows everything,
  undeclared items included. The selection persists in `localStorage`
  (`gh.shellVersion`) and never appears in the URL, so shared links always
  open the unfiltered index.
- **Hover popover** (desktop only, see §9): while row hovered/focused; absolute right 10 / top 58,
  width 440, padding 10, bg `#333`, shadow `0 20px 44px rgba(0,0,0,.65)`,
  `pointer-events:none`; screenshot h230 contain on `#161616`; meta line
  "{shell label} · updated {age}" (left, `rgba(.6)`) and source label
  (right, `rgba(.45)`; official → "GNOME · gitlab.gnome.org", else host).
  No popover without a screenshot.
- **Detail page** (`eh-detail`, same 1600px content box as the list layout,
  padding `32px 28px 56px`, block gap 30): back pill (h34 r999
  `rgba(255,255,255,.07)`, chevron 15, "Back to list") · detail head · screenshot
  hero · two-column content area.
  - *Head:* icon tile 80 r20 (image 64 contain; icon-less items reuse the row
    fallback ladder — blurred screenshot backdrop with the initial, else the
    colored letter tile 38/700) · title 34/700 · byline
    "by {creator} · updated {age}" 14 `rgba(.55)` (creator links to its EGO
    profile when `creatorUrl` exists) · metrics row (gap 18) · primary action
    right (`margin-left:auto`, h44 r10 `#3584e4` 600/15, puzzle glyph for
    "Install extension", download glyph for "Download latest release"/"View
    repository"). Only the primary action lives in the head; EGO/GitHub links
    live in the facts sidebar.
  - *Metrics row (source-scoped, never merged):* rating stars 90×16 + "4.9 · 24
    reviews" (EGO items without a rating show "No rating yet"; GitHub-only items
    show no rating slot) · "{n} downloads" from EGO · GitHub mark + amber star +
    "{n} stars" from GitHub. Each metric renders only when **its own** source
    measured it; the optional 7-day delta appends a green
    `+{n} this week` (`#8ff0a4`). Metric presence is decided by field presence,
    never truthiness, so a real 0 stays visible ("+0 this week").
  - *Screenshot hero:* `<a class="glightbox eh-detail-shot">` — r14 padding 28
    on `#1b1b1b`, backdrop = same screenshot `blur(44px) scale(1.25)` opacity
    .35, foreground image h420 r6 `object-fit:contain`, `cursor:zoom-in`.
    Margins `20px 0 18px`. No screenshot → no hero block at all.
    A zoom pill (`.eh-detail-shot-zoom`) makes the lightbox visible instead of
    cursor-only: bottom/right inset 40 (≤759px: 22), h26 r999 padding 0 10,
    bg `rgba(0,0,0,.5)` + `blur(6px)`, border `rgba(255,255,255,.16)`, magnifier
    glyph 13 + "Zoom" 12/500 `rgba(.82)`, opacity .62 rising to 1 on hero hover
    or focus. It is `aria-hidden` (the anchor's own label announces the action)
    and lives inside the hero, so a missing screenshot removes it too.
  - *Content area:* grid `minmax(0,1fr) | 320px`, gap 44, align start. Text
    column gap 42: "About this extension" (h2 21/700 + description 15/1.7
    `rgba(.74)`, max-width 720, `white-space: pre-line` — the snapshot
    description in full, never an invented long text) · Reviews · "More by
    {creator}" rows (reuses `.eh-row`, list pulled left by 14 so text aligns
    with the headings).
  - *Reviews:* avatar 38 circle (gravatar, else initial on a palette color) ·
    author 14/600 + optional blue "Developer" pill + date 12 `rgba(.4)` · stars
    78×14 · body 14/1.6 `rgba(.72)` (sanitised EGO HTML, small tag allowlist).
    The first **5** loaded reviews are visible; every further review is rendered
    but carries `is-hidden`. A reveal pill ("Show {n} more reviews", h36 r999)
    appears only when more than 5 exist, unhides all of them on click and then
    removes itself — no additional request. No reviews → single muted card on
    `#2b2b2b`.
  - *Facts sidebar* (`eh-facts`, r14 `#1f1f1f`, padding 20, gap 16, sticky top
    86 ≥1024px): label 12/500 `rgba(.42)` over value 14/1.4 `rgba(.88)`, green
    `eh-fact-extra` for week deltas. Rows in order, each only when the value
    exists: Author (EGO profile link + external glyph) · Shell versions (newest
    first, 3 + "+ N more") · Downloads (extensions.gnome.org) · Stars (GitHub) ·
    Forks (GitHub) · Published · Last updated · UUID (mono). A "Links" block
    (top border `rgba(255,255,255,.08)`) lists only contract-backed links:
    extensions.gnome.org (`pageUrl`), GitHub repository (`repositoryUrl`),
    Latest release (`releaseUrl`).
  - *Missing data never renders:* no "Unknown", no demo values, no empty fact
    rows; an absent link, metric or date drops its whole row/pill.
- **Empty state:** centered single line "No extensions match these filters."
  15 `rgba(.4)`, padding 60.
- **Load more:** centered pill h40 r999 `rgba(255,255,255,.07)`; auto-triggers
  via IntersectionObserver (`rootMargin` 600px); disappears on the last page.
- **Cursors/focus:** every interactive gh control `cursor:pointer`; visible
  focus ring `rgba(53,132,228,.9)` 2px offset 2 on all interactive elements.

## 8. Depth, Shadows & Separation

- No borders separate surfaces; brightness does (header 303030 > page 242424
  > sidebar card 1f1f1f / tiles 2b2b2b).
- Shadows only on floating layers: popover `0 20px 44px rgba(0,0,0,.65)`,
  sort menu `0 18px 40px rgba(0,0,0,.6)`, framed hero shot
  `0 -6px 40px rgba(0,0,0,.45)`.

## 9. Responsive Behavior

Desktop (≥1024px) is the reference layout and stays untouched; everything
below is an additive mobile pass — responsive and close to the design, not
pixel-perfect. Every sticky offset hangs off `--eh-header-h` (66px, 58px
≤759px).

- ≥1024px: two-column grid (320px sidebar card sticky top 94). <1024px:
  single column.
- **Mobile navigation ≤1023px:** a sticky chip strip under the header
  (`.eh-mobilenav`, top `--eh-header-h`, z55, bg `#2a2a2a`, horizontally
  scrollable, scrollbar hidden) replaces the sidebar navigation, which would
  otherwise fill the whole first screen. **Every** chip lives in that one
  scroller — the five Explore presets (New keeps its green count), all eight
  categories and, last, the compatibility filter ("Any GNOME" / "GNOME {v}",
  active pill state while filtering). Nothing is pinned on top of the strip:
  a fixed trailing control covered too much of it. The compatibility menu is
  therefore `position: fixed`, placed under its trigger from the trigger rect
  and closed when the strip scrolls, because an absolute menu would be
  clipped by the scroller's `overflow-x`; for the same reason the track must
  never get `mask` or `filter`. Chips h34 r999, icon 14 + label 13/500,
  active white on `#1c1c1c`; the active chip is scrolled into view on every
  render (the compatibility chip is excluded — it is last, and revealing it
  would push every preset out of sight). It is a visible strip, never an
  overlay drawer. The sidebar card keeps only its "About Extension Hub" group
  and moves **below** the content as a closing colophon; Explore, Categories
  and Compatibility are hidden there because the strip owns them.
- **Header ≤759px:** 58px bar. The search field collapses to a 32×32
  magnifier pill beside the GitHub tile and expands to a full-width bar
  across the header on tap or focus (Ctrl/K included), closing again on
  Escape or on blur with an empty field. Above 760px it is never collapsed.
- Header ≤639px: brand logo stays visible (no separate wordmark to hide), Ctrl/K caps hidden, brand min-width 0, "About" collapses to its glyph (the accessible name stays).
- **Hero ≤759px:** height auto. The framed screenshot leaves its absolute
  desktop frame and becomes a full-width banner **in flow above** the text
  (h170, radius 0, no shadow); text column full width padding 20/18/22, title
  26, description wraps up to 3 lines instead of the desktop one-line
  ellipsis, dots static below the panel. The CTA stays (see deviations).
- **Slider swipe ≤1023px (touch/pen only):** in the mobile layout — the same
  range that carries the chip strip — a horizontal drag over a hero moves one
  slide (`goTo(±1)`, same path as the dots); dots and the 6s auto-rotation
  are unchanged, the gesture just pauses it. Guards: ≥45px travel, more
  horizontal than vertical (otherwise the page scrolls), `touch-action:
  pan-y` on `.eh-hero` (also mobile-only), and the click that follows a
  completed swipe is swallowed so it cannot open the CTA or the lightbox.
  Both the media query and the mouse exclusion gate the handler, so ≥1024px
  keeps dots as the only control even on a touchscreen — no drag-nav, no
  prev/next arrows.
- **Rows ≤759px:** icon tile 44 r11 (image 38); name and description keep the
  first line, the rating wraps onto a second line indented to the text
  column. The row action is **hidden** here (see deviations), so the whole
  row is one tap target; a row without a rating drops that second line
  entirely.
- **Hover popover: desktop only.** Hidden for `(hover: none)`,
  `(pointer: coarse)` and below 1024px, so a tap never flashes a 440px panel
  over the list.
- Discover ≤759px: block gap 40 (Popular offset 20), section titles 22,
  section inset 4. List header ≤759px: title, note and sort pill stack; the
  pill then sits left, so its menu opens to the right.
- Detail content area ≥1024px: text column + 320px facts sidebar. <1024px it
  collapses to one column, so the facts sidebar follows About, Reviews and
  "More by" in document order (no reordering, no sticky). ≤759px: icon tile
  72 r18 (image 56), title 26, the primary action becomes a full-width CTA
  under the head, hero padding 14 with image h220, review text breaks long
  words.
- Category grid: 4 → 2 (≤1023px) → 1 (≤479px) columns.

## 10. Do's and Don'ts

Do:
- Reuse `eh-*` classes; extend them before inventing new CSS.
- Keep data-driven counts (New pill, category counts, source counts).
- Use `object-fit: contain` for row/popover screenshots and icons.

Don't:
- No light theme, no glassmorphism chrome, no borders around main surfaces.
- No new accent hues beyond the semantic set above; blue is the interactive
  accent.
- No remote fonts, icon fonts, iconify or CDN assets (inline SVG / CSS masks
  only).
- No prev/next arrows on sliders; no uppercase/tracked sidebar labels.
- No downloads or star counts in list rows (hero review labels may show a
  compact download fallback when unrated).
- Don't merge EGO downloads and GitHub stars into one "popularity" number, and
  don't fake a missing 7-day delta as 0.
- Don't redesign search/loading/error states, and don't turn the mobile chip
  strip into an overlay drawer or hamburger menu — keep them functional and
  graceful.

## Intentional deviations from the reference artifact (user decisions)

- Both hero sliders keep auto-rotating (6s, pause on hover/focus) although
  the artifact only shows dot navigation.
- The sidebar merges the source counters and the colophon into one closing
  "About Extension Hub" group although the artifact has no footer: counters,
  repo link `github.com/butu/extensionhub` ("Open source · MIT", GitHub mark +
  external glyph, `target="_blank"` + `rel="noopener noreferrer"`,
  `aria-label="View Extension Hub source repository on GitHub"`), the snapshot
  date and an "About the data" link to `/use-the-data`. A provenance sentence
  would only repeat the counters, so there is none. Colophon text 12/1.4
  `rgba(255,255,255,.38)`, links `rgba(.55)` 600 (hover white), rows keep the
  11px sidebar inset with 2px vertical padding; the counter→colophon handover
  is 6px (5px margin plus the shared 1px group gap), no card/borders and no new
  accent hues. Detail pages have no footer.
- The detail page keeps its "Back to list" pill; the artifact navigates via a
  breadcrumb the app does not have.
- Mobile keeps two controls the artifact drops, because dropping them would
  cost function the app has and the artifact has not: the hero **CTA** (the
  panel is not a click target on its own, so it would become unreachable) and
  the **compatibility filter** (a stored device preference that would
  otherwise be impossible to change or clear on a phone) — the latter as the
  last chip of the strip, see §9. The row action follows the artifact and is
  hidden on mobile: the row already navigates to the detail page, where the
  same action is a full-width CTA.
- Heroes are swipeable in the mobile layout (≤1023px) although the artifact
  only shows dots. Dots on a phone are a state display, not a comfortable
  control; the swipe is the gesture users try first. ≥1024px keeps dots only.
- The mobile chip strip appears at ≤1023px, not at the artifact's ~860px:
  it reuses the breakpoint at which the layout already collapses to one
  column, and a stacked sidebar card reads just as badly at 900px as at
  390px.
- The reviews block gets a visible "Reviews (n)" heading although the artifact
  starts straight into the review list — an unlabelled section reads as broken
  and leaves the region without an accessible name.
- The reveal pill unhides **all** remaining loaded reviews at once instead of
  the artifact's 5-at-a-time stepping, then removes itself.
- The facts sidebar adds a "UUID" row (real snapshot identity, useful for
  `gnome-extensions` CLI work) and splits the artifact's single "Downloads"
  fact into source-labelled EGO/GitHub rows.
- The artifact's quality badge, bookmark button, version table, rating
  histogram and issue links are not built: none of them is backed by the public
  snapshot contract.

## Assumptions (derived, not explicit in the reference)

- "Detected shell" stays data-driven (≥5% coverage rule) instead of a hard
  "50"; currently resolves to 50.
- The Popular list preset no longer injects a minimum GNOME version filter —
  "Popular" is now purely most-downloaded (the "Popular on GNOME X" variant
  was dropped with the old design).
- Search view (title + match count, no sort control) is a minimal functional
  solution; the search page was not part of the designed scope.
- Detail page: `starsDelta7d` has no baseline in any current snapshot, so the
  GitHub week delta is implemented presence-based but currently never renders.
- Detail page: "More by" reuses the list `.eh-row` without the install button
  and popover, so the rows stay pure navigation inside the detail page.
