<?php
declare(strict_types=1);

namespace vielhuber\ppthelper;

use RuntimeException;
use ZipArchive;
use vielhuber\simplemcp\Attributes\McpTool;

/**
 * ppthelper — turn Pandoc-flavored Markdown into editable PowerPoint decks.
 *
 * Companion to excelhelper / aihelper: pure static API, options-array style.
 *
 *   ppthelper::render([
 *       'input_markdown' => '# Slide 1 …',
 *       'output' => '/path/to/deck.pptx',
 *       'colors_primary' => '#1F4E79',
 *       'fonts_heading' => 'Aptos Display',
 *       'transitions' => 'fade',
 *       'animations' => true
 *   ]);
 *
 * Each call (a) builds a themed copy of the bundled `assets/skeleton.pptx`
 * (mutating ppt/theme/theme1.xml), (b) runs pandoc with that as
 * `--reference-doc`, then (c) optionally post-processes the resulting .pptx
 * to inject slide transitions and per-bullet click-to-advance animations.
 * Slide layouts reference theme entries by name (`accent1`, `dk2`,
 * `majorFont`, `minorFont`), so theme changes propagate to every slide.
 *
 * The same class also exposes the `render_deck` simplemcp tool for AI-agent
 * workflows (charly etc.) via the `#[McpTool]`-annotated method below.
 */
class ppthelper
{
    // ====================================================================
    //  Public static API — direct PHP usage
    // ====================================================================

    /**
     * Render a Markdown deck into an editable .pptx and return its path.
     *
     * Accepted options (all flat keys):
     *   - `input_markdown` (string) — inline Pandoc-Markdown content. Mutually
     *     exclusive with `input_file`; exactly one of the two is required.
     *   - `input_file` (string) — path to a markdown file to read instead of
     *     passing content inline.
     *   - `input_template` (string, optional) — path to a custom reference.pptx
     *     skeleton. Defaults to the bundled `assets/skeleton.pptx`.
     *   - `output` (string, optional) — output .pptx path. If omitted a
     *     tempfile is created and its path returned.
     *   - `colors_primary` (hex, e.g. "#1F4E79") → `<a:accent1>` and `<a:dk2>`
     *   - `colors_secondary` (hex) → `<a:accent2>`
     *   - `colors_background` (hex) → `<a:lt1>`; default white
     *   - `colors_text` (hex) → `<a:dk1>`; default near-black
     *   - `fonts_heading` (string) → `<a:majorFont><a:latin typeface="">`
     *   - `fonts_text` (string) → `<a:minorFont><a:latin typeface="">`
     *   - `transitions` (false|'fade'|'slide', default false) — slide transition
     *     applied to every slide via post-render XML injection.
     *   - `animations` (bool, default false) — when true, every body bullet
     *     appears on a separate click (PowerPoint "Appear → By Paragraph").
     *   - `pandoc_path` (string, optional) — pandoc executable. Default
     *     "pandoc" (relies on $PATH).
     *
     * Relative image paths in the markdown (`![](logo.png)`) resolve against
     * the caller's current working directory at the moment `render()` is
     * invoked. Absolute paths always work, regardless of cwd.
     *
     * @return string Absolute path to the written .pptx file.
     */
    public static function render(array $args): string
    {
        // Snapshot the caller's cwd up front — pandoc will be spawned with
        // this as its working directory so relative image references in the
        // markdown resolve where the caller expects them to.
        $caller_cwd = getcwd();
        if ($caller_cwd === false) {
            throw new RuntimeException('ppthelper::render: getcwd() failed.');
        }

        $markdown = self::resolveMarkdown($args);

        $output = $args['output'] ?? null;
        if ($output === null || $output === '') {
            $output = tempnam(sys_get_temp_dir(), 'ppthelper_') . '.pptx';
        } elseif (!is_string($output)) {
            throw new RuntimeException('ppthelper::render: option "output" must be a string path.');
        }
        // Resolve relative output against the caller's cwd so the eventual
        // path-on-disk matches what was passed in, not pandoc's cwd.
        if (!str_starts_with($output, '/')) {
            $output = $caller_cwd . '/' . $output;
        }

        $template = $args['input_template'] ?? (dirname(__DIR__) . '/assets/skeleton.pptx');
        if (!is_string($template) || !is_file($template)) {
            throw new RuntimeException('ppthelper::render: input_template skeleton not found at ' . (string) $template);
        }

        $pandoc_path = (string) ($args['pandoc_path'] ?? 'pandoc');

        $out_dir = dirname($output);
        if (!is_dir($out_dir) && !@mkdir($out_dir, 0775, true) && !is_dir($out_dir)) {
            throw new RuntimeException('ppthelper::render: failed to create output directory ' . $out_dir);
        }

        // Tempfiles: themed reference + markdown source. Both unlinked on exit
        // even when pandoc throws.
        $themed_ref = tempnam(sys_get_temp_dir(), 'pptx_ref_') . '.pptx';
        $md_source = tempnam(sys_get_temp_dir(), 'pptx_md_') . '.md';
        try {
            self::mutateTheme($template, $themed_ref, [
                'colors_primary' => $args['colors_primary'] ?? null,
                'colors_secondary' => $args['colors_secondary'] ?? null,
                'colors_background' => $args['colors_background'] ?? null,
                'colors_text' => $args['colors_text'] ?? null,
                'fonts_heading' => $args['fonts_heading'] ?? null,
                'fonts_text' => $args['fonts_text'] ?? null
            ]);
            if (@file_put_contents($md_source, $markdown) === false) {
                throw new RuntimeException('ppthelper::render: failed to write markdown to ' . $md_source);
            }
            self::runPandoc($pandoc_path, $md_source, $themed_ref, $output, $caller_cwd);
        } finally {
            @unlink($themed_ref);
            @unlink($md_source);
        }

        // Pandoc rewrites slideMaster*.xml + slideLayout*.xml with hardcoded
        // placeholder positions, destroying the carefully-crafted geometry of
        // the user's master design. Copy them back verbatim from the template.
        self::restoreSkeletonLayouts($output, $template);

        // Pandoc emits content placeholders with a bare <a:bodyPr/> — when a
        // model writes more bullets than fit, the text overflows the slide.
        // Adding <a:normAutofit/> tells PowerPoint to shrink text dynamically
        // so it always stays inside the placeholder.
        self::enableAutoFitOnBodyPlaceholders($output);

        // Pandoc writes a fixed <a:xfrm> on every placeholder, including the
        // system ones (centered title, date, footer, slide number), with a
        // one-size-fits-all box that often collides with rotated date fields
        // or other master decorations. Stripping the xfrm makes the placeholder
        // fall back to the layout's intended position. Tables get a minimum
        // cy proportional to the row count so auto-sized rows don't overflow
        // the fixed Pandoc box.
        self::fixSlideShapeGeometry($output);

        // Pandoc's PPTX writer is hardcoded to ~3 layouts (title, title+content,
        // two-content) — Markdown can't address "Section Header", "Picture with
        // Caption", "Quote" etc. As a partial fix, detect slides that look like
        // a section header (only a title, no body / no table / no picture) and
        // remap them to the skeleton's secHead-typed layout.
        $sec_layout = self::findSectionHeaderLayout($template);
        if ($sec_layout !== null) {
            self::remapSectionHeaderSlides($output, $sec_layout);
        }

        self::validateOutput($output);

        // Post-process: transitions + animations.
        $transitions = $args['transitions'] ?? false;
        $animations = (bool) ($args['animations'] ?? false);
        if ($transitions !== false || $animations) {
            self::postProcessPptx($output, $transitions !== false ? (string) $transitions : null, $animations);
        }
        return $output;
    }

    // ====================================================================
    //  MCP tool wrapper — drives the `render_deck` simplemcp tool
    // ====================================================================

    /**
     * Render a Markdown deck into an editable PowerPoint file.
     *
     * The markdown follows Pandoc's slideshow conventions:
     *   - First three lines `% Title`, `% Author`, `% Date` become the title slide.
     *   - Each `# Heading` starts a new slide; the body is its content.
     *   - Two-column layouts via `::: {.columns}` / `::: {.column}` fences
     *     (pandoc caps at two — for 3+ side-by-side use a Markdown table).
     *   - Tables, fenced code, bullets, ordered lists, images, math: all
     *     standard Pandoc-Markdown.
     *
     * @param string $markdown The complete deck as one Pandoc-Markdown blob.
     * @param string|null $transitions Slide transition applied to every slide. One of: null (none), "fade", "slide".
     * @param bool|null $animations When true, body bullets appear one click at a time (PowerPoint "Appear → By Paragraph").
     * @param string|null $output Output path. Relative paths resolve against the caller's cwd. If null, a tempfile is used and its path returned.
     * @return array{path: string} Path to the generated .pptx file.
     */
    #[McpTool(name: 'render_deck')]
    public function renderDeck(
        string $markdown,
        ?string $transitions = null,
        ?bool $animations = null,
        ?string $output = null
    ): array {
        // Theme parameters (primary/accent/background/text colors, heading/body fonts)
        // are intentionally NOT exposed through the MCP interface — LLMs reflexively
        // fill them with their own "modern default" palette, overwriting the carefully
        // curated skeleton theme. Callers needing to force a theme can still use the
        // PHP `render()` entry point or the CLI directly.
        $path = self::render([
            'input_markdown' => $markdown,
            'output' => $output,
            'transitions' => $transitions !== null && $transitions !== '' ? $transitions : false,
            'animations' => (bool) ($animations ?? false)
        ]);
        return ['path' => $path];
    }

    // ====================================================================
    //  Private helpers — input resolution + output validation
    // ====================================================================

    /**
     * Read the markdown content from either an inline string (`input_markdown`)
     * or a file path (`input_file`). Mutually exclusive: passing both, or
     * neither, raises.
     */
    private static function resolveMarkdown(array $args): string
    {
        $has_inline = isset($args['input_markdown']) && $args['input_markdown'] !== '';
        $has_file = isset($args['input_file']) && $args['input_file'] !== '';
        if ($has_inline && $has_file) {
            throw new RuntimeException('ppthelper::render: pass either "input_markdown" or "input_file", not both.');
        }
        if (!$has_inline && !$has_file) {
            throw new RuntimeException('ppthelper::render: one of "input_markdown" (inline) or "input_file" (path) is required.');
        }
        if ($has_inline) {
            if (!is_string($args['input_markdown'])) {
                throw new RuntimeException('ppthelper::render: option "input_markdown" must be a string.');
            }
            return $args['input_markdown'];
        }
        $file = $args['input_file'];
        if (!is_string($file) || !is_file($file)) {
            throw new RuntimeException('ppthelper::render: input_file not found at ' . (string) $file);
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException('ppthelper::render: failed to read input_file ' . $file);
        }
        return $content;
    }

    /**
     * Verify the rendered file is a usable PPTX: openable as zip, contains at
     * least one slide and the theme XML. Catches half-written or corrupted
     * output that a simple filesize check would miss.
     */
    private static function validateOutput(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('ppthelper::render: output file missing after pandoc: ' . $path);
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('ppthelper::render: output is not a readable .pptx archive: ' . $path);
        }
        try {
            $slide_count = 0;
            $has_theme = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^ppt/slides/slide\d+\.xml$#', (string) $name) === 1) {
                    $slide_count++;
                } elseif ($name === 'ppt/theme/theme1.xml') {
                    $has_theme = true;
                }
            }
        } finally {
            $zip->close();
        }
        if ($slide_count === 0) {
            throw new RuntimeException('ppthelper::render: output contains zero slides — pandoc likely received an empty or invalid markdown body.');
        }
        if (!$has_theme) {
            throw new RuntimeException('ppthelper::render: output is missing ppt/theme/theme1.xml — file is not a valid PowerPoint deck.');
        }
    }

    private static function runPandoc(string $pandoc_path, string $md_source, string $themed_ref, string $out_path, string $cwd): void
    {
        // Spawn pandoc with `--resource-path=<caller's cwd>` AND set its
        // working directory to the same place. Pandoc resolves relative
        // image refs in the markdown against the resource-path; setting cwd
        // additionally makes any other relative refs (e.g. include-files)
        // behave the same way. Absolute paths in the markdown work
        // regardless.
        $cmd = [$pandoc_path, '--reference-doc=' . $themed_ref, '--resource-path=' . $cwd, '-o', $out_path, $md_source];

        // proc_open with an argv array bypasses the shell — no escaping needed.
        $proc = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ],
            $pipes,
            $cwd,
            null
        );
        if (!is_resource($proc)) {
            throw new RuntimeException('ppthelper::render: failed to spawn pandoc.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new RuntimeException(
                'ppthelper::render: pandoc failed (exit ' . $exit . '): ' . trim($stderr !== '' ? $stderr : $stdout)
            );
        }
    }

    /**
     * Copy every `ppt/slideMasters/slideMaster*.xml` and
     * `ppt/slideLayouts/slideLayout*.xml` from the skeleton back into the
     * pandoc-generated output, overwriting Pandoc's rewrites. Pandoc replaces
     * placeholder geometry (positions, sizes) with hardcoded defaults that
     * destroy the user's master design. Theme XML stays untouched in the
     * output (mutateTheme already wrote it via the themed reference Pandoc
     * consumed), and slide content stays untouched (that's the model's input).
     */
    private static function restoreSkeletonLayouts(string $output_path, string $template_path): void
    {
        $template_zip = new ZipArchive();
        if ($template_zip->open($template_path) !== true) {
            throw new RuntimeException('ppthelper::render: failed to open template for layout restore: ' . $template_path);
        }
        $output_zip = new ZipArchive();
        if ($output_zip->open($output_path) !== true) {
            $template_zip->close();
            throw new RuntimeException('ppthelper::render: failed to open output for layout restore: ' . $output_path);
        }
        try {
            for ($i = 0; $i < $template_zip->numFiles; $i++) {
                $name = $template_zip->getNameIndex($i);
                if (!is_string($name)) {
                    continue;
                }
                $is_master = preg_match('#^ppt/slideMasters/slideMaster\d+\.xml$#', $name) === 1;
                $is_layout = preg_match('#^ppt/slideLayouts/slideLayout\d+\.xml$#', $name) === 1;
                if (!$is_master && !$is_layout) {
                    continue;
                }
                $contents = $template_zip->getFromIndex($i);
                if (!is_string($contents)) {
                    continue;
                }
                if ($output_zip->locateName($name) !== false) {
                    $output_zip->deleteName($name);
                }
                $output_zip->addFromString($name, $contents);
            }
        } finally {
            $template_zip->close();
            $output_zip->close();
        }
    }

    /**
     * Walk every slide and add `<a:normAutofit/>` to the `<a:bodyPr>` of body
     * placeholders. PowerPoint then auto-shrinks oversized text instead of
     * letting it overflow the slide. Title placeholders are NOT touched —
     * shrinking titles silently makes for inconsistent decks.
     */
    private static function enableAutoFitOnBodyPlaceholders(string $output_path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($output_path) !== true) {
            throw new RuntimeException('ppthelper::render: failed to open output for auto-fit pass: ' . $output_path);
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || preg_match('#^ppt/slides/slide\d+\.xml$#', $name) !== 1) {
                    continue;
                }
                $xml = $zip->getFromIndex($i);
                if (!is_string($xml)) {
                    continue;
                }
                $new_xml = preg_replace_callback(
                    '#<p:sp\b[^>]*>.*?</p:sp>#s',
                    static function (array $m): string {
                        $sp = $m[0];
                        // skip title/subTitle placeholders — shrinking titles silently looks bad
                        if (preg_match('#<p:ph\b[^/]*\btype="(?:title|ctrTitle|subTitle)"#', $sp) === 1) {
                            return $sp;
                        }
                        // only touch body-style placeholders: type="body"/"obj" or no type with idx="1"
                        if (preg_match('#<p:ph\b([^/]*)/?>#', $sp, $ph_m) !== 1) {
                            return $sp;
                        }
                        $ph_attrs = $ph_m[1];
                        $type = preg_match('#\btype="([^"]+)"#', $ph_attrs, $tm) === 1 ? $tm[1] : '';
                        $has_idx_1 = preg_match('#\bidx="1"#', $ph_attrs) === 1;
                        $is_body = in_array($type, ['body', 'obj'], true) || ($type === '' && $has_idx_1);
                        if (!$is_body) {
                            return $sp;
                        }
                        if (str_contains($sp, '<a:normAutofit')) {
                            return $sp;
                        }
                        // <a:bodyPr/> → <a:bodyPr><a:normAutofit/></a:bodyPr>
                        $patched = preg_replace(
                            '#<a:bodyPr(\s[^/>]*)?\s*/>#',
                            '<a:bodyPr$1><a:normAutofit/></a:bodyPr>',
                            $sp,
                            1
                        );
                        // <a:bodyPr ...>...</a:bodyPr> with inner content → keep inner + append normAutofit
                        if ($patched === $sp) {
                            $patched = preg_replace(
                                '#(<a:bodyPr(?:\s[^>]*)?>)(.*?)(</a:bodyPr>)#s',
                                '$1$2<a:normAutofit/>$3',
                                $sp,
                                1
                            );
                        }
                        return $patched ?? $sp;
                    },
                    $xml
                );
                if (!is_string($new_xml) || $new_xml === $xml) {
                    continue;
                }
                $zip->deleteName($name);
                $zip->addFromString($name, $new_xml);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Two post-process fixes for the per-slide XML:
     *
     *  1. Strip the <a:xfrm> Pandoc writes onto ctrTitle / dt / ftr / sldNum
     *     placeholders. Pandoc uses one geometry for all title slides, which
     *     in our skeleton overlaps the rotated date field on the right edge.
     *     Removing the xfrm makes the placeholder inherit the layout default.
     *
     *  2. Grow the <a:ext cy> of <p:graphicFrame> table shapes when the row
     *     count exceeds Pandoc's fixed box. Pandoc emits e.g. cy=2552700 for
     *     a 6-row table, which is fine for tight content but clips when row
     *     text wraps. We bump cy to ~0.42" per row so auto-sized rows fit.
     */
    private static function fixSlideShapeGeometry(string $output_path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($output_path) !== true) {
            throw new RuntimeException('ppthelper::render: failed to open output for shape-geometry pass: ' . $output_path);
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || preg_match('#^ppt/slides/slide\d+\.xml$#', $name) !== 1) {
                    continue;
                }
                $xml = $zip->getFromIndex($i);
                if (!is_string($xml)) {
                    continue;
                }
                $new_xml = preg_replace_callback(
                    '#<p:sp\b[^>]*>.*?</p:sp>#s',
                    static function (array $m): string {
                        $sp = $m[0];
                        if (preg_match('#<p:ph\b[^/]*\btype="(?:ctrTitle|subTitle|dt|ftr|sldNum)"#', $sp) !== 1) {
                            return $sp;
                        }
                        $stripped = preg_replace('#<a:xfrm>.*?</a:xfrm>#s', '', $sp, 1);
                        return is_string($stripped) ? $stripped : $sp;
                    },
                    $xml
                );
                if (is_string($new_xml)) {
                    $xml_stage1 = $new_xml;
                } else {
                    $xml_stage1 = $xml;
                }
                $new_xml = preg_replace_callback(
                    '#<p:graphicFrame\b[^>]*>.*?</p:graphicFrame>#s',
                    static function (array $m): string {
                        $gf = $m[0];
                        if (!str_contains($gf, '<a:tbl>')) {
                            return $gf;
                        }
                        $row_count = preg_match_all('#<a:tr\b#', $gf);
                        if ($row_count < 5) {
                            return $gf;
                        }
                        $needed_cy = $row_count * 380000 + 100000;
                        $patched = preg_replace_callback(
                            '#<a:ext\s+cx="(\d+)"\s+cy="(\d+)"\s*/>#',
                            static function (array $em) use ($needed_cy): string {
                                $cx = (int) $em[1];
                                $cy = max((int) $em[2], $needed_cy);
                                return '<a:ext cx="' . $cx . '" cy="' . $cy . '"/>';
                            },
                            $gf,
                            1
                        );
                        return is_string($patched) ? $patched : $gf;
                    },
                    $xml_stage1
                );
                if (!is_string($new_xml) || $new_xml === $xml) {
                    continue;
                }
                $zip->deleteName($name);
                $zip->addFromString($name, $new_xml);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Look up the skeleton's "Section Header"-typed layout (OOXML attribute
     * type="secHead" on <p:sldLayout>) and return its basename, or null if the
     * skeleton has no such layout. Used by remapSectionHeaderSlides.
     */
    private static function findSectionHeaderLayout(string $template_path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($template_path) !== true) {
            return null;
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || preg_match('#^ppt/slideLayouts/slideLayout\d+\.xml$#', $name) !== 1) {
                    continue;
                }
                $xml = $zip->getFromIndex($i);
                if (is_string($xml) && preg_match('#<p:sldLayout\b[^>]*\btype="secHead"#', $xml) === 1) {
                    return basename($name);
                }
            }
        } finally {
            $zip->close();
        }
        return null;
    }

    /**
     * Detect "section header" candidate slides (only a title placeholder; no
     * body content, no table, no picture) and remap their layout relationship
     * to point at the skeleton's secHead layout. Also strips the Pandoc-set
     * <a:xfrm> off the title placeholder in those slides so the title falls
     * back to the section-layout's intended (typically larger / centered)
     * position instead of staying at the title+content geometry Pandoc wrote.
     */
    private static function remapSectionHeaderSlides(string $output_path, string $section_layout_name): void
    {
        $zip = new ZipArchive();
        if ($zip->open($output_path) !== true) {
            throw new RuntimeException('ppthelper::render: failed to open output for section-header remap: ' . $output_path);
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $sm) !== 1) {
                    continue;
                }
                $slide_xml = $zip->getFromIndex($i);
                if (!is_string($slide_xml)) {
                    continue;
                }
                if (str_contains($slide_xml, '<p:graphicFrame') || str_contains($slide_xml, '<p:pic')) {
                    continue;
                }
                $has_body = false;
                if (preg_match_all('#<p:sp\b.*?</p:sp>#s', $slide_xml, $sps) !== false) {
                    foreach ($sps[0] as $sp) {
                        if (preg_match(
                            '#<p:ph\b[^/]*\btype="(?:title|ctrTitle|subTitle|dt|ftr|sldNum)"#',
                            $sp
                        ) === 1) {
                            continue;
                        }
                        // anything that's not a system placeholder + has actual
                        // text counts as body content
                        if (preg_match('#<a:t>\s*\S#', $sp) === 1) {
                            $has_body = true;
                            break;
                        }
                    }
                }
                if ($has_body) {
                    continue;
                }
                // also skip the title slide itself (idx=1 with ctrTitle) — the
                // very first slide is already a proper title slide.
                if (preg_match('#<p:ph\b[^/]*\btype="ctrTitle"#', $slide_xml) === 1) {
                    continue;
                }
                $rels_name = 'ppt/slides/_rels/slide' . $sm[1] . '.xml.rels';
                $rels_xml = $zip->getFromName($rels_name);
                if (!is_string($rels_xml)) {
                    continue;
                }
                $new_rels = preg_replace(
                    '#Target="\.\./slideLayouts/slideLayout\d+\.xml"#',
                    'Target="../slideLayouts/' . $section_layout_name . '"',
                    $rels_xml,
                    1
                );
                if (is_string($new_rels) && $new_rels !== $rels_xml) {
                    $zip->deleteName($rels_name);
                    $zip->addFromString($rels_name, $new_rels);
                }
                // Strip the title's xfrm so it inherits the section-header
                // layout's geometry (typically larger/centered) instead of
                // staying at the title+content position Pandoc wrote.
                $new_slide_xml = preg_replace_callback(
                    '#<p:sp\b[^>]*>.*?</p:sp>#s',
                    static function (array $m): string {
                        $sp = $m[0];
                        if (preg_match('#<p:ph\b[^/]*\btype="title"#', $sp) !== 1) {
                            return $sp;
                        }
                        $stripped = preg_replace('#<a:xfrm>.*?</a:xfrm>#s', '', $sp, 1);
                        return is_string($stripped) ? $stripped : $sp;
                    },
                    $slide_xml
                );
                if (is_string($new_slide_xml) && $new_slide_xml !== $slide_xml) {
                    $zip->deleteName($name);
                    $zip->addFromString($name, $new_slide_xml);
                }
            }
        } finally {
            $zip->close();
        }
    }

    // ====================================================================
    //  Private helpers — theme mutation (ppt/theme/theme1.xml)
    // ====================================================================

    /**
     * Write a themed copy of $skeleton to $destination. All theme keys are
     * optional — null/missing means "keep the skeleton default". Slide layouts
     * in the skeleton reference theme entries by name (accent1, dk2,
     * majorFont, …), so a single theme swap propagates to every slide.
     *
     * @param array{
     *     colors_primary?: ?string,
     *     colors_secondary?: ?string,
     *     colors_background?: ?string,
     *     colors_text?: ?string,
     *     fonts_heading?: ?string,
     *     fonts_text?: ?string
     * } $theme
     */
    private static function mutateTheme(string $skeleton, string $destination, array $theme): void
    {
        if (!is_file($skeleton)) {
            throw new RuntimeException('Skeleton reference.pptx not found at ' . $skeleton);
        }
        if (!@copy($skeleton, $destination)) {
            throw new RuntimeException('Failed to copy skeleton to ' . $destination);
        }
        $zip = new ZipArchive();
        if ($zip->open($destination) !== true) {
            throw new RuntimeException('Failed to open themed reference for mutation: ' . $destination);
        }
        try {
            $xml = $zip->getFromName('ppt/theme/theme1.xml');
            if ($xml === false) {
                throw new RuntimeException('ppt/theme/theme1.xml missing from skeleton');
            }
            // dk1/lt1 are sysClr (window/text) in the skeleton — we promote
            // them to srgbClr so PowerPoint honours the override consistently
            // across Office versions; otherwise leaving them as sysClr means
            // the user's system colors leak in unpredictably.
            $color_map = [
                // theme role => desired hex (null = keep current)
                'dk1' => self::normalizeHex($theme['colors_text'] ?? null),
                'lt1' => self::normalizeHex($theme['colors_background'] ?? null),
                'dk2' => self::normalizeHex($theme['colors_primary'] ?? null),
                'accent1' => self::normalizeHex($theme['colors_primary'] ?? null),
                'accent2' => self::normalizeHex($theme['colors_secondary'] ?? null)
            ];
            foreach ($color_map as $role => $hex) {
                if ($hex === null) {
                    continue;
                }
                // match both srgbClr-form and sysClr-form
                $pattern = '#<a:' . $role . '>\s*<a:(?:srgbClr|sysClr)[^/]*/>\s*</a:' . $role . '>#';
                $replacement = '<a:' . $role . '><a:srgbClr val="' . $hex . '"/></a:' . $role . '>';
                $xml = preg_replace($pattern, $replacement, $xml, 1);
            }
            $heading = self::sanitizeFontName($theme['fonts_heading'] ?? null);
            $body = self::sanitizeFontName($theme['fonts_text'] ?? null);
            if ($heading !== null) {
                $xml = preg_replace(
                    '#(<a:majorFont>.*?<a:latin typeface=")[^"]+(")#s',
                    '${1}' . $heading . '${2}',
                    $xml,
                    1
                );
            }
            if ($body !== null) {
                $xml = preg_replace(
                    '#(<a:minorFont>.*?<a:latin typeface=")[^"]+(")#s',
                    '${1}' . $body . '${2}',
                    $xml,
                    1
                );
            }
            $zip->deleteName('ppt/theme/theme1.xml');
            $zip->addFromString('ppt/theme/theme1.xml', $xml);
        } finally {
            $zip->close();
        }
    }

    /**
     * Accept "#1F4E79" (preferred), also tolerates "1F4E79" / "1f4e79" without
     * the #-prefix. Reject anything that isn't a 6-hex triplet — preg_replace
     * pasting an attacker-controlled string into the XML would otherwise break
     * the file or worse.
     */
    private static function normalizeHex(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $stripped = ltrim($value, '#');
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $stripped) !== 1) {
            throw new RuntimeException('Invalid color "' . $value . '"; expected 6-digit hex like "#1F4E79".');
        }
        return strtoupper($stripped);
    }

    private static function sanitizeFontName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        // Same allow-list PowerPoint itself uses for typeface attributes: ASCII
        // letters/digits/space/dash. Quote/angle-bracket would break the XML.
        if (preg_match('/^[A-Za-z0-9 \-]{1,64}$/', $value) !== 1) {
            throw new RuntimeException('Invalid font name "' . $value . '"; ASCII letters/digits/space/dash only, max 64 chars.');
        }
        return $value;
    }

    // ====================================================================
    //  Private helpers — post-render OOXML mutations (transitions + anims)
    // ====================================================================

    /**
     * Apply optional transitions and per-bullet click animations to a
     * finished .pptx. Both features are missing from pandoc's PPTX writer
     * but get injected here by manipulating the slide XML directly.
     * Idempotent: slides that already have a transition or timing block are
     * left untouched.
     */
    private static function postProcessPptx(string $pptx_path, ?string $transition, bool $animations): void
    {
        if ($transition === null && !$animations) {
            return;
        }
        if ($transition !== null && !in_array($transition, ['fade', 'slide'], true)) {
            throw new RuntimeException('ppthelper::render: transitions must be false, "fade" or "slide"; got "' . $transition . '".');
        }
        $zip = new ZipArchive();
        if ($zip->open($pptx_path) !== true) {
            throw new RuntimeException('ppthelper::render: failed to open ' . $pptx_path . ' for post-processing.');
        }
        try {
            $slide_names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1) {
                    $slide_names[] = $name;
                }
            }
            foreach ($slide_names as $name) {
                $xml = $zip->getFromName($name);
                if (!is_string($xml)) {
                    continue;
                }
                $modified = false;
                if ($transition !== null) {
                    $new_xml = self::injectTransition($xml, $transition);
                    if ($new_xml !== $xml) {
                        $xml = $new_xml;
                        $modified = true;
                    }
                }
                if ($animations) {
                    $new_xml = self::injectClickAnimations($xml);
                    if ($new_xml !== $xml) {
                        $xml = $new_xml;
                        $modified = true;
                    }
                }
                if ($modified) {
                    $zip->deleteName($name);
                    $zip->addFromString($name, $xml);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Insert a `<p:transition>` element right before `</p:sld>`. Skips slides
     * that already have a transition.
     */
    private static function injectTransition(string $xml, string $transition): string
    {
        if (str_contains($xml, '<p:transition')) {
            return $xml;
        }
        // OOXML preset transitions; both fade and push are smooth and widely
        // supported across PowerPoint versions and Keynote.
        $snippet = match ($transition) {
            'fade' => '<p:transition><p:fade/></p:transition>',
            'slide' => '<p:transition><p:push dir="r"/></p:transition>',
            default => null
        };
        if ($snippet === null) {
            return $xml;
        }
        return str_replace('</p:sld>', $snippet . '</p:sld>', $xml);
    }

    /**
     * Build and inject a `<p:timing>` block that animates every body paragraph
     * of the slide as a separate click step ("Appear → By Paragraph" in the
     * PowerPoint UI). No-op when the slide has no body placeholder or only an
     * empty body, and idempotent.
     */
    private static function injectClickAnimations(string $xml): string
    {
        if (str_contains($xml, '<p:timing')) {
            return $xml;
        }
        $target = self::findBodyTarget($xml);
        if ($target === null) {
            return $xml;
        }
        ['shape_id' => $shape_id, 'paragraph_count' => $paragraph_count] = $target;
        if ($paragraph_count < 1) {
            return $xml;
        }
        $timing = self::buildClickTimingBlock($shape_id, $paragraph_count);
        return str_replace('</p:sld>', $timing . '</p:sld>', $xml);
    }

    /**
     * Locate the body content placeholder of a pandoc-generated slide and
     * count its text-carrying paragraphs. Pandoc emits one `<p:sp>` per
     * layout placeholder; the body is the one whose `<p:ph>` carries
     * `type="body"`/`"obj"` or no type with `idx="1"`. Explicitly excludes
     * title-slide placeholders so the subtitle doesn't get animated by
     * accident.
     *
     * @return array{shape_id: int, paragraph_count: int}|null
     */
    private static function findBodyTarget(string $xml): ?array
    {
        if (preg_match_all('#<p:sp\b[^>]*>(.*?)</p:sp>#s', $xml, $matches) < 1) {
            return null;
        }
        foreach ($matches[0] as $sp) {
            if (preg_match('#<p:ph\b([^/]*)/?>#', $sp, $ph_m) !== 1) {
                continue;
            }
            $ph_attrs = $ph_m[1];
            $type = preg_match('#\btype="([^"]+)"#', $ph_attrs, $tm) === 1 ? $tm[1] : '';
            if (in_array($type, ['ctrTitle', 'subTitle', 'title'], true)) {
                continue;
            }
            $has_idx_1 = preg_match('#\bidx="1"#', $ph_attrs) === 1;
            $is_body = in_array($type, ['body', 'obj'], true) || ($type === '' && $has_idx_1);
            if (!$is_body) {
                continue;
            }
            if (preg_match('#<p:cNvPr\s+id="(\d+)"#', $sp, $id_m) !== 1) {
                continue;
            }
            $shape_id = (int) $id_m[1];
            if (preg_match('#<p:txBody>(.*?)</p:txBody>#s', $sp, $body_m) !== 1) {
                continue;
            }
            // Only count paragraphs that actually carry a text run — empty
            // <a:p/> placeholders would still get an animation step and waste
            // clicks during presentation.
            $body = $body_m[1];
            $count = preg_match_all('#<a:p\b[^>]*>(?:(?!</a:p>).)*?<a:r\b#s', $body);
            if ($count < 1) {
                continue;
            }
            return ['shape_id' => $shape_id, 'paragraph_count' => $count];
        }
        return null;
    }

    /**
     * Build the verbose `<p:timing>` XML for an "Appear, by paragraph,
     * on click" effect on $paragraph_count paragraphs of the shape with id
     * $shape_id. Each paragraph gets its own click trigger.
     *
     * IDs are unique per timing block (sequential from 1). The presetID=1 +
     * presetClass=entr combo is OOXML's "Appear" entrance effect.
     */
    private static function buildClickTimingBlock(int $shape_id, int $paragraph_count): string
    {
        $next_id = 1;
        $click_steps = '';
        for ($p = 0; $p < $paragraph_count; $p++) {
            $cTn_outer = $next_id++;
            $cTn_inner = $next_id++;
            $cTn_effect = $next_id++;
            $cTn_set = $next_id++;
            $click_steps .= ''
                . '<p:par><p:cTn id="' . $cTn_outer . '" fill="hold">'
                . '<p:stCondLst><p:cond delay="indefinite"/></p:stCondLst>'
                . '<p:childTnLst><p:par><p:cTn id="' . $cTn_inner . '" fill="hold">'
                . '<p:stCondLst><p:cond delay="0"/></p:stCondLst>'
                . '<p:childTnLst><p:par><p:cTn id="' . $cTn_effect . '" presetID="1" presetClass="entr" presetSubtype="0" fill="hold" grpId="0" nodeType="clickEffect">'
                . '<p:stCondLst><p:cond delay="0"/></p:stCondLst>'
                . '<p:childTnLst><p:set>'
                . '<p:cBhvr>'
                . '<p:cTn id="' . $cTn_set . '" dur="1" fill="hold"><p:stCondLst><p:cond delay="0"/></p:stCondLst></p:cTn>'
                . '<p:tgtEl><p:spTgt spid="' . $shape_id . '"><p:txEl><p:pRg st="' . $p . '" end="' . $p . '"/></p:txEl></p:spTgt></p:tgtEl>'
                . '<p:attrNameLst><p:attrName>style.visibility</p:attrName></p:attrNameLst>'
                . '</p:cBhvr>'
                . '<p:to><p:strVal val="visible"/></p:to>'
                . '</p:set></p:childTnLst>'
                . '</p:cTn></p:par></p:childTnLst>'
                . '</p:cTn></p:par></p:childTnLst>'
                . '</p:cTn></p:par>';
        }
        $tmRoot_id = $next_id++;
        $mainSeq_id = $next_id++;
        return ''
            . '<p:timing>'
            . '<p:tnLst><p:par>'
            . '<p:cTn id="' . $tmRoot_id . '" dur="indefinite" restart="never" nodeType="tmRoot">'
            . '<p:childTnLst><p:seq concurrent="1" nextAc="seek">'
            . '<p:cTn id="' . $mainSeq_id . '" dur="indefinite" nodeType="mainSeq"><p:childTnLst>'
            . $click_steps
            . '</p:childTnLst></p:cTn>'
            . '<p:prevCondLst><p:cond evt="onPrev" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:prevCondLst>'
            . '<p:nextCondLst><p:cond evt="onNext" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:nextCondLst>'
            . '</p:seq></p:childTnLst>'
            . '</p:cTn>'
            . '</p:par></p:tnLst>'
            . '<p:bldLst><p:bldP spid="' . $shape_id . '" grpId="0" build="p"/></p:bldLst>'
            . '</p:timing>';
    }
}
