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

the tool exposes `render_deck(markdown, primary_color?, accent_color?, background_color?, text_color?, heading_font?, body_font?, transitions?, animations?, output?)` — same arguments as `ppthelper::render` would take, just spelled flat for json-rpc transport. `output` accepts any absolute or relative path; relative paths resolve against the working directory the server was launched from. omit it to get a tempfile back.
