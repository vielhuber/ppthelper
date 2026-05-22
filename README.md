[![build status](https://github.com/vielhuber/ppthelper/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/ppthelper/actions)
[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/ppthelper)](https://github.com/vielhuber/ppthelper/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/ppthelper)](https://github.com/vielhuber/ppthelper/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/ppthelper)](https://github.com/vielhuber/ppthelper/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/ppthelper)](https://packagist.org/packages/vielhuber/ppthelper)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/ppthelper)](https://packagist.org/packages/vielhuber/ppthelper)

# 📢 ppthelper 📢

ppthelper is a helper for powerpoint.

with its help you can render pptx decks from markdown in php in a very simple, webdev-friendly way.
every render dynamically themes the reference deck (colors, fonts) so the output looks distinct per call while every text element stays fully editable in powerpoint.

## installation

install once with [composer](https://getcomposer.org/):

```
composer require vielhuber/ppthelper
```

then add this to your files:

```php
require __DIR__ . '/vendor/autoload.php';
use vielhuber\ppthelper\ppthelper;
```

[pandoc](https://pandoc.org/) must be installed and reachable on `$PATH` (or pass an explicit `pandoc_path`).

## usage

### rendering

```php
$path = ppthelper::render([
    'output' => 'deck.pptx',
    'input_markdown' => "% Quarterly Update\n% acme corp\n\n# Highlights\n\n- Revenue up 23%\n- 4 new markets",
    'input_file' => null,
    'input_template' => null,
    'colors_primary' => '#1F4E79',
    'colors_secondary' => '#F59E0B',
    'colors_background' => '#FFFFFF',
    'colors_text' => '#111827',
    'fonts_heading' => 'Aptos Display',
    'fonts_text' => 'Aptos',
    'transitions' => false, // false|'fade'|'slide'
    'animations' => false, // false|true
    'pandoc_path' => 'pandoc' // optional
]);
```

read markdown from a file instead of inline:

```php
$path = ppthelper::render([
    'output' => 'deck.pptx',
    'input_file' => 'slides.md'
]);
```

### image paths

`![alt](path)` references in the markdown resolve as follows:

- **absolute paths** (`/abs/path/to/img.png`) work out of the box.
- **relative paths** (`logo.png`, `images/hero.jpg`) are resolved against the **caller's current working directory** at the moment `render()` is called. either `chdir()` into the right place before calling, or pass absolute paths.

### markdown syntax

ppthelper accepts any [pandoc-flavored markdown](https://pandoc.org/MANUAL.html#slide-shows):

```markdown
% Deck title
% author
% 2026-05-21

# first slide

- bullet one
- bullet two

# two-column layout

:::: {.columns}
::: {.column}
**left**

- item a
  :::
  ::: {.column}
  **right**

- item b
  :::
  ::::

# table

| points | grade |
| ------ | ----- |
| 37-40  | 1     |
| 33-36  | 2     |

# image

![hero](hero.png)

::: notes
speaker notes for this slide
:::
```

- title slide: the leading `% Title` / `% Author` / `% Date` lines (all optional).
- each `# Heading` starts a new slide.
- bullets, ordered lists, tables, fenced code, math (`$E=mc^2$`), images: all standard pandoc-markdown.
- two-column layouts via `::: {.columns}` / `::: {.column}` fences. **note:** pandoc's pptx writer caps at two columns — additional `::: {.column}` blocks are silently dropped. for 3+ "columns" use a markdown table (`| A | B | C |`) instead, which pandoc renders as a native side-by-side pptx table.
- speaker notes via `::: notes` / `:::` blocks.

### slide-layout cookbook

pandoc's pptx writer only ever picks ~3 layouts out of the typical 17 a skeleton ships with (title, title+content, two-content). ppthelper widens this with a post-process pass that remaps "section-header"-shaped slides onto the skeleton's `secHead` layout. the patterns below cover every layout actually reachable from markdown.

#### title slide

three leading `%` lines. all optional; pass only what you need.

```markdown
% Q3 Strategy Review
% Acme GmbH · Board Meeting
% 22.05.2026
```

date format follows the locale of your skeleton's `<a:fld type="datetime1">`. for narrow rotated date boxes use the short `DD.MM.YYYY` form — ppthelper rewrites pandoc's static text into a live field so powerpoint formats it correctly.

#### content slide (title + bullets)

the default for any `# heading` followed by body text.

```markdown
# Why this matters now

- AI is moving from experiment to operating layer
- Value emerges from process redesign, not from tools alone
- Governance, data, and skills decide who scales
- Window of differentiation is closing within 12–18 months
```

keep it to 3–5 bullets, ~80 chars each. pandoc rendering doesn't reflow, and `<a:normAutofit/>` (ppthelper adds it automatically) only shrinks down to ~70% before things turn unreadable.

#### quote slide

a `# heading` followed by a body that consists entirely of markdown blockquote (`> …`) lines. ppthelper detects this (via the marL="1270000" + no-bullet marker pandoc emits) and remaps the slide onto the skeleton's quote/zitat layout (larger italic body, accent caption).

```markdown
# Worth quoting

> Künstliche Intelligenz wird nicht jeden Job zerstören.
> Aber jeder Job wird sich verändern.
>
> — Andrew Ng, Stanford 2024
```

best used sparingly — 1 quote per ~15–20 content slides reads as a rhetorical anchor; more often than that feels gimmicky.

#### section-header slide

write a `# heading` with **no body** between it and the next `# heading`. ppthelper detects the empty body and remaps the slide onto the skeleton's section-header layout (larger title, accent background).

```markdown
# Part 1 — Market dynamics

# Market trends 2026
- Adoption breadth keeps rising, skaling lags
- Investment hits record highs

# Part 2 — Value levers

# Operational efficiency
- ...
```

excellent for breaking up decks of 15+ slides — drop one in every 5–7 slides.

#### two-column: image + bullets

the most useful "mixed media" layout. never put `![]()` and bullets in the same plain block — pandoc stacks them awkwardly. use the column fence:

```markdown
# Reference architecture

:::: {.columns}
::: {.column}
![architecture](architecture.png)
:::
::: {.column}
- Ingestion: streaming + batch
- Model layer: shared inference, per-team adapters
- Governance: policy-as-code at the gateway
- Observability: prompt + cost + drift
:::
::::
```

ppthelper remaps the image's `<p:pic>` geometry to fit the column box. for a single full-width hero image use the next pattern instead.

#### image-only slide

bare `![alt](path)` without bullets:

```markdown
# Architecture overview

![architecture](architecture.png)
```

pandoc still picks the `slideLayout2` (title+content) layout under the hood — the image just occupies the content placeholder. absolute paths work everywhere; relative paths resolve against the caller's `cwd` at render time.

#### comparison table (3+ columns)

pandoc's two-column fence stops at two `::: {.column}` blocks. for side-by-side 3+ use a markdown table — pandoc emits a native pptx table that ppthelper auto-grows vertically so it doesn't clip:

```markdown
# Build vs. buy vs. hybrid

| Approach | Strength                  | Trade-off          |
| -------- | ------------------------- | ------------------ |
| Build    | Differentiation, IP       | Talent + runway    |
| Buy      | Time-to-value, support    | Lock-in            |
| Hybrid   | Best-of-both, optionality | Integration cost   |
```

5+ rows are fine; ppthelper bumps the table-frame `cy` so auto-sized rows fit. if your table approaches ~8 rows split it across two slides (`# Roadmap (1/2)` / `# Roadmap (2/2)`).

#### speaker notes

attach context that should not appear on the slide itself:

```markdown
# Strategic options

- Option A: defensive — modernize existing stack
- Option B: offensive — agentic workflows in core ops
- Option C: hybrid — agentic in 2 functions, modernize the rest

::: notes
Board is leaning toward C based on last week's risk workshop.
McKinsey '25 puts agentic-first orgs at 2x revenue growth vs peers.
:::
```

notes show up only in presenter view / printed handouts.

#### transitions & animations

pass at render-time, not in the markdown:

```php
ppthelper::render([
    'input_markdown' => $md,
    'output' => 'deck.pptx',
    'transitions' => 'fade',   // false | 'fade' | 'slide'
    'animations'  => true,     // body bullets appear one click at a time
]);
```

a fade transition with no bullet-animations is the sane default for business decks.

#### putting it all together

a small end-to-end deck mixing every pattern above:

```markdown
% Quarterly Strategy
% Acme GmbH
% 22.05.2026

# Part 1 — Where we stand

# Adoption is uneven
- 78% of teams use AI weekly
- Only 12% have a measured ROI loop
- Governance still owned by IT, not by product

# Reference architecture

:::: {.columns}
::: {.column}
![architecture](architecture.png)
:::
::: {.column}
- Shared inference layer
- Per-team adapters
- Policy-as-code gateway
:::
::::

# Part 2 — Where we go

# Three options on the table

| Approach | Strength               | Trade-off       |
| -------- | ---------------------- | --------------- |
| Build    | Differentiation, IP    | Talent + runway |
| Buy      | Time-to-value          | Lock-in         |
| Hybrid   | Best-of-both           | Integration     |

::: notes
Board is leaning toward Hybrid. McKinsey '25 supports this.
:::

# Recommendation

![roadmap](roadmap.png)
```

this single markdown blob renders into a 7-slide deck (title · section · content · two-content · section · content · image-as-content) that hits all four pandoc-reachable skeleton layouts: `slideLayout1` (title), `slideLayout2` (title+content — also used for bare image slides), `slideLayout3` (section header — picked up via ppthelper's empty-body remap), and `slideLayout4` (two content).

### custom template

bring your own corporate `reference.pptx` (slide masters, default layouts, logo, fonts) and ppthelper themes a copy of it on every render:

```php
$path = ppthelper::render([
    'output' => 'deck.pptx',
    'input_markdown' => $md,
    'input_template' => __DIR__ . '/templates/corporate.pptx',
    'colors_primary' => '#003366'
]);
```

### mcp server

ppthelper ships as a standalone [mcp](https://modelcontextprotocol.io/) server for ai-agent workflows, exposing a single tool `render_deck` that wraps `ppthelper::render`.

```
cp vendor/vielhuber/ppthelper/src/.env.example vendor/vielhuber/ppthelper/src/.env
# edit the .env and set MCP_TOKEN to a private value
vendor/bin/mcp-server.php
```

the server speaks both stdio (CLI invocation) and HTTP via [simplemcp](https://github.com/vielhuber/simplemcp). `auth: 'static'` mode expects the bearer token in `MCP_TOKEN`.

the tool exposes `render_deck(markdown, transitions?, animations?, output?)`. theme parameters (colors, fonts, background) are intentionally **not** part of the mcp surface — llms otherwise reflexively override the carefully designed skeleton theme. for forced theming use `ppthelper::render(...)` directly from php or the cli.

`output` accepts any absolute or relative path; relative paths resolve against the working directory the server was launched from. omit it to get a tempfile back.
