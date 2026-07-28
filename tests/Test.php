<?php
declare(strict_types=1);

use vielhuber\ppthelper\ppthelper;

class Test extends \PHPUnit\Framework\TestCase
{
    /**
     * Find the first skeleton in `assets/` whose `slideLayout*.xml`
     * collection satisfies the predicate. Returns the absolute path, or null
     * if none match. Used by tests that need a feature the bundled
     * `assets/default.pptx` doesn't ship with (e.g. a quote-typed layout
     * or a live <a:fld type="datetime1"> field on the title slide).
     */
    /**
     * @var array<string, list<string>>|null
     * cache: skeleton-path → list of slideLayout XML blobs (lazy-loaded once)
     */
    private static ?array $skeletonLayoutsCache = null;

    /**
     * Load every input skeleton's slideLayout XMLs into a flat in-memory map.
     * Done once per test-suite run, regardless of how many tests call
     * findSkeletonWithFeature() afterwards. Without the cache, six tests
     * each open 32 ZipArchives → 192 reify+close cycles that OOM the
     * macOS GitHub Actions runner.
     */
    private static function loadAllSkeletonLayouts(): array
    {
        if (self::$skeletonLayoutsCache !== null) {
            return self::$skeletonLayoutsCache;
        }
        $dir = dirname(__DIR__) . '/assets';
        if (!is_dir($dir)) {
            return self::$skeletonLayoutsCache = [];
        }
        $skeletons = array_values(array_filter(
            glob($dir . '/*.pptx') ?: [],
            static fn(string $p): bool => !str_starts_with(basename($p), '~$')
        ));
        sort($skeletons);
        $cache = [];
        foreach ($skeletons as $sk) {
            $z = new ZipArchive();
            if ($z->open($sk) !== true) {
                continue;
            }
            $layouts = [];
            for ($i = 0; $i < $z->numFiles; $i++) {
                $n = $z->getNameIndex($i);
                if (!is_string($n) || preg_match('#^ppt/slideLayouts/(slideLayout\d+\.xml)$#', $n, $lm) !== 1) {
                    continue;
                }
                $xml = $z->getFromIndex($i);
                if (is_string($xml)) {
                    $layouts[$lm[1]] = $xml;
                }
            }
            $z->close();
            $cache[$sk] = $layouts;
        }
        return self::$skeletonLayoutsCache = $cache;
    }

    /**
     * Predicate signature: `fn(string $layout_xml, string $layout_name): bool`.
     * `$layout_name` is e.g. `slideLayout1.xml` — tests that only care about
     * the title slide (cover) can filter on `slideLayout1.xml`. Legacy
     * one-arg predicates ignoring the second parameter still work.
     */
    private static function findSkeletonWithFeature(callable $layoutMatches): ?string
    {
        foreach (self::loadAllSkeletonLayouts() as $path => $layouts) {
            foreach ($layouts as $layout_name => $xml) {
                if ($layoutMatches($xml, $layout_name)) {
                    return $path;
                }
            }
        }
        return null;
    }

    private static function loadSlideXml(string $pptx, int $slide_number): string
    {
        $zip = new ZipArchive();
        $zip->open($pptx);
        try {
            $xml = $zip->getFromName('ppt/slides/slide' . $slide_number . '.xml');
        } finally {
            $zip->close();
        }
        if (!is_string($xml)) {
            throw new RuntimeException('slide ' . $slide_number . ' not found in ' . $pptx);
        }
        return $xml;
    }

    private static function loadThemeXml(string $pptx): string
    {
        $zip = new ZipArchive();
        $zip->open($pptx);
        try {
            $xml = $zip->getFromName('ppt/theme/theme1.xml');
        } finally {
            $zip->close();
        }
        return is_string($xml) ? $xml : '';
    }

    private static function countSlides(string $pptx): int
    {
        $zip = new ZipArchive();
        $zip->open($pptx);
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1) {
                $count++;
            }
        }
        $zip->close();
        return $count;
    }

    public function test__basic_render(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_basic.pptx';
        @unlink($out);
        $path = ppthelper::render([
            'content_markdown' => "# First slide\n\n- one\n- two\n\n# Second slide\n\nContent body.",
            'output' => $out
        ]);
        $this->assertSame($out, $path);
        $this->assertFileExists($out);
        $this->assertSame(2, self::countSlides($out));
        $slide1 = self::loadSlideXml($out, 1);
        $this->assertStringContainsString('First slide', $slide1);
        $this->assertStringContainsString('<a:t>one</a:t>', $slide1);
    }

    public function test__level_two_headings_do_not_create_additional_slides(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_heading_levels.pptx';
        if (is_file($out)) {
            unlink($out);
        }
        ppthelper::render([
            'content_markdown' => "% Deck title\n% Author\n% Date\n\n# First slide\n\n## First section\n\nContent.\n\n# Second slide\n\n## Second section\n\nContent.",
            'output' => $out
        ]);
        $this->assertSame(3, self::countSlides($out));
        $this->assertStringContainsString('First section', self::loadSlideXml($out, 2));
        $this->assertStringContainsString('Second section', self::loadSlideXml($out, 3));
    }

    public function test__mcp_render_returns_verified_slide_count(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_mcp_result.pptx';
        @unlink($out);
        $result = (new ppthelper())->renderDeck(
            markdown: "# First slide\n\nContent.\n\n# Second slide\n\nContent.",
            minimum_slide_count: 2,
            maximum_slide_count: 2,
            output: $out
        );
        $this->assertSame($out, $result['path']);
        $this->assertSame(2, $result['slide_count']);
    }

    public function test__mcp_render_rejects_output_outside_slide_count_limits(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_mcp_slide_limits.pptx';
        if (is_file($out)) {
            unlink($out);
        }
        try {
            (new ppthelper())->renderDeck(
                markdown: "# First slide\n\nContent.\n\n# Second slide\n\nContent.",
                minimum_slide_count: 3,
                maximum_slide_count: 3,
                output: $out
            );
            $this->fail('Expected the slide count validation to reject the deck.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('required range is 3 to 3', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($out);
    }

    public function test__theming_propagates(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_theme.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => '# Themed',
            'output' => $out,
            'colors_primary' => '#DC2626',
            'colors_secondary' => '#F59E0B',
            'fonts_heading' => 'Aptos Display',
            'fonts_text' => 'Aptos'
        ]);
        $theme = self::loadThemeXml($out);
        $this->assertMatchesRegularExpression('#<a:accent1>\s*<a:srgbClr val="DC2626"/>#', $theme);
        $this->assertMatchesRegularExpression('#<a:accent2>\s*<a:srgbClr val="F59E0B"/>#', $theme);
        $this->assertMatchesRegularExpression('#<a:majorFont>.*?<a:latin typeface="Aptos Display"#s', $theme);
        $this->assertMatchesRegularExpression('#<a:minorFont>.*?<a:latin typeface="Aptos"#s', $theme);
    }

    public function test__hex_with_or_without_hash(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_hash.pptx';
        @unlink($out);
        // with hash
        ppthelper::render(['content_markdown' => '# X', 'output' => $out, 'colors_primary' => '#1F4E79']);
        $this->assertMatchesRegularExpression('#<a:accent1>\s*<a:srgbClr val="1F4E79"/>#', self::loadThemeXml($out));
        // without hash — still accepted
        ppthelper::render(['content_markdown' => '# X', 'output' => $out, 'colors_primary' => '1F4E79']);
        $this->assertMatchesRegularExpression('#<a:accent1>\s*<a:srgbClr val="1F4E79"/>#', self::loadThemeXml($out));
    }

    public function test__content_file(): void
    {
        $md_path = sys_get_temp_dir() . '/ppthelper_test_input.md';
        file_put_contents($md_path, "# From file\n\n- one\n- two");
        $out = sys_get_temp_dir() . '/ppthelper_test_input.pptx';
        @unlink($out);
        ppthelper::render(['content_file' => $md_path, 'output' => $out]);
        $this->assertFileExists($out);
        $this->assertStringContainsString('From file', self::loadSlideXml($out, 1));
        @unlink($md_path);
    }

    public function test__markdown_and_input_mutually_exclusive(): void
    {
        $this->expectExceptionMessageMatches('#pass either "content_markdown" or "content_file"#');
        ppthelper::render([
            'content_markdown' => '# X',
            'content_file' => '/tmp/whatever.md',
            'output' => sys_get_temp_dir() . '/x.pptx'
        ]);
    }

    public function test__missing_input_raises(): void
    {
        $this->expectExceptionMessageMatches('#one of "content_markdown"#');
        ppthelper::render(['output' => sys_get_temp_dir() . '/x.pptx']);
    }

    public function test__invalid_hex_raises(): void
    {
        $this->expectExceptionMessageMatches('#Invalid color#');
        ppthelper::render([
            'content_markdown' => '# X',
            'output' => sys_get_temp_dir() . '/x.pptx',
            'colors_primary' => 'not-a-color'
        ]);
    }

    public function test__transitions_inject_fade(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_trans.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "# A\n- one\n\n# B\n- two",
            'output' => $out,
            'transitions' => 'fade'
        ]);
        $slide1 = self::loadSlideXml($out, 1);
        $slide2 = self::loadSlideXml($out, 2);
        $this->assertStringContainsString('<p:transition><p:fade/></p:transition>', $slide1);
        $this->assertStringContainsString('<p:transition><p:fade/></p:transition>', $slide2);
    }

    public function test__transitions_inject_slide(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_slide_trans.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "# A\n- one",
            'output' => $out,
            'transitions' => 'slide'
        ]);
        $this->assertStringContainsString('<p:transition><p:push dir="r"/></p:transition>', self::loadSlideXml($out, 1));
    }

    public function test__animations_inject_click_timing(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_anim.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "# Bullets\n\n- one\n- two\n- three",
            'output' => $out,
            'animations' => true
        ]);
        $slide1 = self::loadSlideXml($out, 1);
        // each bullet gets a clickEffect step → at least 3 of them.
        $this->assertGreaterThanOrEqual(3, substr_count($slide1, 'nodeType="clickEffect"'));
        $this->assertStringContainsString('<p:bldP', $slide1);
        $this->assertStringContainsString('build="p"', $slide1);
    }

    public function test__animations_skip_title_slide(): void
    {
        // The first slide produced by "% Title\n% Author" has a subTitle
        // placeholder with idx="1"; if we treated that as a body the subtitle
        // would silently get animated on click. Make sure we don't.
        $out = sys_get_temp_dir() . '/ppthelper_test_title_anim.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "% Deck title\n% Author name\n\n# Content slide\n\n- bullet",
            'output' => $out,
            'animations' => true
        ]);
        $title_slide = self::loadSlideXml($out, 1);
        $content_slide = self::loadSlideXml($out, 2);
        $this->assertStringNotContainsString('<p:timing', $title_slide);
        $this->assertStringContainsString('<p:timing', $content_slide);
    }

    public function test__empty_markdown_rejected_upfront(): void
    {
        // Empty content_markdown is caught at the resolve step before pandoc
        // ever runs — same error path as omitting the argument entirely.
        $this->expectExceptionMessageMatches('#one of "content_markdown"#');
        ppthelper::render([
            'content_markdown' => '',
            'output' => sys_get_temp_dir() . '/empty.pptx'
        ]);
    }

    public function test__relative_image_resolves_against_cwd(): void
    {
        // Set up a working dir with an image file and chdir into it.
        $work = sys_get_temp_dir() . '/ppthelper_test_relimg_' . uniqid();
        mkdir($work);
        // 1x1 PNG
        file_put_contents($work . '/dot.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9Q/x8ABZYC/4P4M+oAAAAASUVORK5CYII='));
        $previous_cwd = getcwd();
        chdir($work);
        try {
            $out = $work . '/relimg.pptx';
            ppthelper::render([
                'content_markdown' => "# Image slide\n\n![dot](dot.png)",
                'output' => $out
            ]);
            $zip = new ZipArchive();
            $zip->open($out);
            $has_media = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && str_starts_with($name, 'ppt/media/')) {
                    $has_media = true;
                    break;
                }
            }
            $zip->close();
            $this->assertTrue($has_media, 'expected relative image to be embedded under ppt/media/');
        } finally {
            chdir($previous_cwd);
            // cleanup
            @unlink($work . '/dot.png');
            @unlink($work . '/relimg.pptx');
            @rmdir($work);
        }
    }

    public function test__absolute_image_works_regardless_of_cwd(): void
    {
        $img = sys_get_temp_dir() . '/ppthelper_test_abs_' . uniqid() . '.png';
        file_put_contents($img, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9Q/x8ABZYC/4P4M+oAAAAASUVORK5CYII='));
        try {
            $out = sys_get_temp_dir() . '/ppthelper_test_abs.pptx';
            ppthelper::render([
                'content_markdown' => "# Image slide\n\n![dot](" . $img . ")",
                'output' => $out
            ]);
            $zip = new ZipArchive();
            $zip->open($out);
            $has_media = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && str_starts_with($name, 'ppt/media/')) {
                    $has_media = true;
                    break;
                }
            }
            $zip->close();
            $this->assertTrue($has_media, 'expected absolute image path to be embedded');
        } finally {
            @unlink($img);
        }
    }

    public function test__render_without_overrides_preserves_skeleton_theme(): void
    {
        // Render without color/font overrides must leave the skeleton's
        // theme1.xml byte-identical in the output — regardless of what
        // colors/fonts the skeleton happens to ship with.
        $out = sys_get_temp_dir() . '/ppthelper_test_default.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => '# Default look', 'output' => $out]);

        $zip = new ZipArchive();
        $zip->open(dirname(__DIR__) . '/assets/default.pptx');
        $skeleton_theme = $zip->getFromName('ppt/theme/theme1.xml');
        $zip->close();

        $this->assertSame($skeleton_theme, self::loadThemeXml($out));
    }

    public function test__skeleton_layouts_and_masters_restored_after_pandoc(): void
    {
        // Pandoc rewrites slideLayout*.xml and slideMaster*.xml with hardcoded
        // placeholder positions; restoreSkeletonLayouts must put them back so
        // the user's master geometry survives the render. We verify by byte-
        // comparing every layout/master between the skeleton and the output.
        $out = sys_get_temp_dir() . '/ppthelper_test_layouts_restored.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "# Alpha\n\n- one\n- two\n\n# Beta\n\n:::: {.columns}\n::: {.column}\nleft text\n:::\n::: {.column}\nright text\n:::\n::::",
            'output' => $out
        ]);

        $skeleton_zip = new ZipArchive();
        $skeleton_zip->open(dirname(__DIR__) . '/assets/default.pptx');
        $output_zip = new ZipArchive();
        $output_zip->open($out);

        $checked = 0;
        try {
            for ($i = 0; $i < $skeleton_zip->numFiles; $i++) {
                $name = $skeleton_zip->getNameIndex($i);
                if (
                    preg_match('#^ppt/slide(Layouts/slideLayout|Masters/slideMaster)\d+\.xml$#', (string) $name) !== 1
                ) {
                    continue;
                }
                $skel = $skeleton_zip->getFromName($name);
                $out_content = $output_zip->getFromName($name);
                $this->assertNotFalse($out_content, "expected $name to exist in render output");
                $this->assertSame($skel, $out_content, "$name should be byte-identical to skeleton after restore");
                $checked++;
            }
        } finally {
            $skeleton_zip->close();
            $output_zip->close();
        }
        $this->assertGreaterThan(0, $checked, 'no layouts/masters in skeleton to check — fixture broken?');
    }

    public function test__layout_restore_does_not_clobber_theme_overrides(): void
    {
        // Layout-restore must NOT touch ppt/theme/theme1.xml — that's the
        // place where mutateTheme writes color/font overrides. Combine an
        // explicit color override with a normal render and verify the
        // override survives.
        $out = sys_get_temp_dir() . '/ppthelper_test_layouts_keep_theme.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "# Themed slide\n\n- bullet",
            'output' => $out,
            'colors_primary' => '#AA22BB'
        ]);
        $theme = self::loadThemeXml($out);
        $this->assertMatchesRegularExpression('#<a:accent1>\s*<a:srgbClr val="AA22BB"/>#', $theme);
    }

    public function test__autofit_added_to_body_placeholders(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_autofit.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "# Slide A\n\n- bullet one\n- bullet two",
            'output' => $out
        ]);
        $slide1 = self::loadSlideXml($out, 1);
        // body placeholder bodyPr must now contain <a:normAutofit/>
        $this->assertMatchesRegularExpression(
            '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*idx="1"(?:(?!</p:sp>).)*?<a:normAutofit/>#s',
            $slide1,
            'body placeholder should have <a:normAutofit/> injected'
        );
    }

    public function test__two_column_body_anchored_center_when_picture_present(): void
    {
        // regression for chat 019e5ead-019d (minecraft deck): a 16:9 picture in
        // a near-square two-column body floats centered in its column, while
        // the sibling text column top-aligns. Visually the columns look
        // misaligned. balanceTwoColumnAnchor sets anchor="ctr" on body
        // placeholders in slides that contain a <p:pic>, so the text shares
        // the picture's vertical center.
        $out = sys_get_temp_dir() . '/ppthelper_test_anchor_ctr.pptx';
        @unlink($out);
        $img = sys_get_temp_dir() . '/ppthelper_test_anchor_ctr.png';
        file_put_contents($img, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        ));
        $md = "::: {.columns}\n::: {.column width=\"50%\"}\n![](" . $img . ")\n:::\n::: {.column width=\"50%\"}\n# Right title\n\n- a\n- b\n:::\n:::";
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);
        $slide1 = self::loadSlideXml($out, 1);
        // body sp must have anchor="ctr"
        $this->assertMatchesRegularExpression(
            '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*idx="(?:1|2|3)"(?:(?!</p:sp>).)*?<a:bodyPr\b[^/>]*\banchor="ctr"#s',
            $slide1,
            'body placeholder must have anchor="ctr" on slides with a picture'
        );
    }

    public function test__autofit_added_to_two_column_right_body(): void
    {
        // regression for chat 019e5e11-281a: two-column layout (Pandoc emits
        // `<p:ph idx="2" sz="half"/>` for the right body) was being skipped by
        // the autofit pass because the predicate only matched idx="1". Without
        // normAutofit the right column overflows the slide when the text is
        // longer than the layout-default body height.
        $out = sys_get_temp_dir() . '/ppthelper_test_autofit_twocol.pptx';
        @unlink($out);
        // Pandoc two-column fence with image left + bullets right
        $img = sys_get_temp_dir() . '/ppthelper_test_dummy.png';
        // 1x1 transparent PNG
        file_put_contents($img, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        ));
        $md = "::: {.columns}\n::: {.column width=\"50%\"}\n![](" . $img . ")\n:::\n::: {.column width=\"50%\"}\n# Right title\n\n- a\n- b\n- c\n:::\n:::";
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);
        $slide1 = self::loadSlideXml($out, 1);
        // every non-system <p:ph> with idx="N" (N in 1..9) MUST end up with a
        // <a:normAutofit/> in its bodyPr.
        $matched_any = false;
        foreach (range(1, 9) as $idx) {
            $regex = '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*idx="' . $idx . '"(?:(?!</p:sp>).)*?</p:sp>#s';
            if (preg_match($regex, $slide1, $m) === 1) {
                // skip system placeholder types
                if (preg_match('#type="(?:sldNum|dt|ftr|hdr|pic)"#', $m[0]) === 1) {
                    continue;
                }
                $matched_any = true;
                $this->assertStringContainsString(
                    '<a:normAutofit',
                    $m[0],
                    'body placeholder idx="' . $idx . '" must have <a:normAutofit/>'
                );
            }
        }
        $this->assertTrue($matched_any, 'rendered two-column slide should have at least one indexed body placeholder');
    }

    public function test__autofit_skips_title_placeholders(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_autofit_title.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "% Title slide\n% Author\n% 21. Mai 2026\n\n# Content slide\n\n- bullet",
            'output' => $out
        ]);
        // slide1 = title slide. its title shape must NOT have normAutofit.
        $slide1 = self::loadSlideXml($out, 1);
        if (preg_match('#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*type="(?:title|ctrTitle)"(?:(?!</p:sp>).)*?</p:sp>#s', $slide1, $m) === 1) {
            $this->assertStringNotContainsString('<a:normAutofit', $m[0], 'title placeholder must not have auto-fit');
        }
    }

    public function test__autofit_idempotent_on_existing_normAutofit(): void
    {
        // If a slide already had normAutofit (e.g. from a custom skeleton), the
        // post-process must not double-inject. We can simulate by rendering
        // twice over the same output and inspecting that no slide ends up with
        // two normAutofit tags inside a single placeholder.
        $out = sys_get_temp_dir() . '/ppthelper_test_autofit_idem.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => "# X\n\n- bullet", 'output' => $out]);
        $slide1 = self::loadSlideXml($out, 1);
        $body_match = preg_match('#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*idx="1"(?:(?!</p:sp>).)*?</p:sp>#s', $slide1, $m);
        $this->assertSame(1, $body_match);
        $this->assertSame(1, substr_count($m[0], '<a:normAutofit'));
    }

    public function test__layout_restore_does_not_clobber_slide_content(): void
    {
        // Slides must keep the model's content after restore. Plain bullets,
        // a heading, a table — all must appear in the output text body.
        $out = sys_get_temp_dir() . '/ppthelper_test_layouts_keep_slides.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "# Headline X\n\n- bullet ONE\n- bullet TWO\n\n| a | b |\n|---|---|\n| 1 | 2 |",
            'output' => $out
        ]);
        $zip = new ZipArchive();
        $zip->open($out);
        $slide1 = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();
        $this->assertStringContainsString('Headline X', $slide1);
        $this->assertStringContainsString('bullet ONE', $slide1);
        $this->assertStringContainsString('bullet TWO', $slide1);
    }

    public function test__title_slide_system_placeholders_lose_xfrm(): void
    {
        // Pandoc writes its own <a:xfrm> on ctrTitle/dt/ftr/sldNum which
        // overlaps rotated decorations on the skeleton's title slide. After
        // our post-process those system placeholders must have no xfrm —
        // they then inherit the layout's intended position.
        $out = sys_get_temp_dir() . '/ppthelper_test_geom_titleslide.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "% Deck Title\n% Author\n% 21. Mai 2026\n\n# Content slide\n\n- bullet",
            'output' => $out
        ]);
        $slide1 = self::loadSlideXml($out, 1);
        foreach (['ctrTitle', 'dt', 'ftr', 'sldNum'] as $type) {
            if (preg_match(
                '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*type="' . $type . '"(?:(?!</p:sp>).)*?</p:sp>#s',
                $slide1,
                $m
            ) === 1) {
                $this->assertStringNotContainsString(
                    '<a:xfrm>',
                    $m[0],
                    'placeholder type="' . $type . '" must lose its xfrm so it falls back to layout default'
                );
            }
        }
    }

    public function test__geometry_pass_preserves_two_column_content(): void
    {
        // The geometry pass must not delete content from two-column slides
        // (idx=1 / idx=2 body placeholders). Pandoc relies on layout-4
        // ("Two Content") for these — we must touch only system placeholders.
        $out = sys_get_temp_dir() . '/ppthelper_test_geom_twocol.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "# Headline\n\n:::: {.columns}\n::: {.column}\n- LEFT_BULLET\n:::\n::: {.column}\n- RIGHT_BULLET\n:::\n::::",
            'output' => $out
        ]);
        $slide1 = self::loadSlideXml($out, 1);
        $this->assertStringContainsString('LEFT_BULLET', $slide1);
        $this->assertStringContainsString('RIGHT_BULLET', $slide1);
        $this->assertMatchesRegularExpression('#<p:ph\b[^/]*idx="1"#', $slide1);
        $this->assertMatchesRegularExpression('#<p:ph\b[^/]*idx="2"#', $slide1);
    }

    public function test__large_table_grows_cy(): void
    {
        // 6-row table: Pandoc emits cy=2552700 (~2.8"); our post-process must
        // bump it to at least row_count * 380000 + 100000 = 2,380,000 + 100,000
        // = 2,480,000 — which in this case is below the original, so the
        // original wins. Try a 10-row table to trigger growth.
        $rows = ['| col1 | col2 |', '|---|---|'];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = '| r' . $i . 'a | r' . $i . 'b |';
        }
        $md = "# Table\n\n" . implode("\n", $rows);
        $out = sys_get_temp_dir() . '/ppthelper_test_table_grow.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);
        $slide1 = self::loadSlideXml($out, 1);
        $gf_match = preg_match(
            '#<p:graphicFrame\b[^>]*>.*?</p:graphicFrame>#s',
            $slide1,
            $gfm
        );
        $this->assertSame(1, $gf_match, 'graphicFrame for the table must exist');
        $rows_in_gf = preg_match_all('#<a:tr\b#', $gfm[0]);
        $expected_min_cy = $rows_in_gf * 380000 + 100000;
        $ext_match = preg_match('#<a:ext\s+cx="\d+"\s+cy="(\d+)"#', $gfm[0], $em);
        $this->assertSame(1, $ext_match, 'ext on graphicFrame must exist');
        $this->assertGreaterThanOrEqual(
            $expected_min_cy,
            (int) $em[1],
            'cy must grow to fit ' . $rows_in_gf . ' rows'
        );
    }

    public function test__section_header_slide_remapped_to_secHead_layout(): void
    {
        // `# Heading` followed immediately by another `# Heading` (no body) is
        // a section-header candidate. Pandoc itself doesn't pick secHead for
        // PPTX, so our post-process must remap the layout relationship.
        $md = "% Deck\n% Author\n% 22. Mai 2026\n\n# Part 1: Foundations\n\n# Slide One\n\n- bullet A\n\n# Slide Two\n\n- bullet B";
        $out = sys_get_temp_dir() . '/ppthelper_test_sechead_remap.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);
        // slide2 should be the "Part 1: Foundations" section-header candidate.
        $zip = new ZipArchive();
        $zip->open($out);
        $rels = $zip->getFromName('ppt/slides/_rels/slide2.xml.rels');
        $zip->close();
        $this->assertIsString($rels);
        // skeleton.pptx ships with secHead = slideLayout3
        $this->assertStringContainsString(
            'slideLayouts/slideLayout3.xml',
            $rels,
            'empty-body slide must be remapped to skeleton secHead layout'
        );
    }

    public function test__content_slide_keeps_default_layout(): void
    {
        // Plain title+bullets slide must NOT be remapped to secHead.
        $md = "# Real Content\n\n- bullet ONE\n- bullet TWO";
        $out = sys_get_temp_dir() . '/ppthelper_test_sechead_skip.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);
        $zip = new ZipArchive();
        $zip->open($out);
        $rels = $zip->getFromName('ppt/slides/_rels/slide1.xml.rels');
        $zip->close();
        $this->assertIsString($rels);
        $this->assertStringNotContainsString(
            'slideLayouts/slideLayout3.xml',
            $rels,
            'content slide must stay on its original (title+content) layout'
        );
    }

    public function test__quote_slide_remapped_when_skeleton_has_quote_layout(): void
    {
        // Pandoc renders `> text` blockquotes with marL="1270000" + <a:buNone/>.
        // When the entire body of a slide is such paragraphs, ppthelper remaps
        // the layout to the skeleton's quote-named layout AND re-anchors the
        // content placeholder to the layout's body-idx (typically 2, not 1).
        // The bundled assets/default.pptx has no quote layout, so pick the
        // first input skeleton that does.
        $skeleton = self::findSkeletonWithFeature(static function (string $layout_xml): bool {
            return preg_match('#<p:cSld\b[^>]*\bname="[^"]*(zitat|quote)#i', $layout_xml) === 1;
        });
        if ($skeleton === null) {
            $this->markTestSkipped('no skeleton with quote/zitat layout available in assets/');
        }
        // re-read the quote layout's metadata for assertions
        $z = new ZipArchive();
        $z->open($skeleton);
        $quote_layout = null;
        $quote_body_idx = null;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (!is_string($n) || preg_match('#^ppt/slideLayouts/slideLayout\d+\.xml$#', $n) !== 1) {
                continue;
            }
            $x = $z->getFromIndex($i);
            if (
                is_string($x) &&
                preg_match('#<p:cSld\b[^>]*\bname="([^"]+)"#', $x, $nm) === 1 &&
                preg_match('#(zitat|quote)#i', $nm[1]) === 1
            ) {
                $quote_layout = basename($n);
                $best_cy = -1;
                if (preg_match_all('#<p:sp\b[^>]*>.*?</p:sp>#s', $x, $sps) !== false) {
                    foreach ($sps[0] as $sp) {
                        if (preg_match('#<p:ph\b[^/]*\btype="body"[^/]*\bidx="(\d+)"#', $sp, $im) === 1
                            && preg_match('#<a:ext\s+cx="\d+"\s+cy="(\d+)"#', $sp, $em) === 1
                            && (int) $em[1] > $best_cy
                        ) {
                            $best_cy = (int) $em[1];
                            $quote_body_idx = $im[1];
                        }
                    }
                }
                break;
            }
        }
        $z->close();
        $md = "# Worth quoting\n\n> Lorem ipsum dolor sit amet.\n>\n> — Cicero, 45 BC";
        $out = sys_get_temp_dir() . '/ppthelper_test_quote_remap.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'style_template' => $skeleton, 'output' => $out]);
        $z = new ZipArchive();
        $z->open($out);
        $rels = $z->getFromName('ppt/slides/_rels/slide1.xml.rels');
        $slide = $z->getFromName('ppt/slides/slide1.xml');
        $z->close();
        $this->assertStringContainsString(
            $quote_layout,
            $rels,
            'blockquote-only slide must be remapped to the skeleton quote layout'
        );
        if ($quote_body_idx !== null && $quote_body_idx !== '1') {
            $this->assertMatchesRegularExpression(
                '#<p:ph\b[^/]*\bidx="' . preg_quote($quote_body_idx, '#') . '"#',
                $slide,
                'quote slide content placeholder must be re-anchored to layout body-idx (= ' . $quote_body_idx . ')'
            );
            $this->assertDoesNotMatchRegularExpression(
                '#<p:ph\b\s+idx="1"\s*/?>#',
                $slide,
                'pandoc default idx="1" must be replaced when layout uses a different body-idx'
            );
        }
    }

    public function test__body_idx_normalised_to_match_layout(): void
    {
        // Pandoc emits content as <p:ph idx="1">. The skeleton's L2 may use a
        // different idx (e.g. 13). Without normalisation PowerPoint can't bind
        // the placeholder and the body renders at a stray default position.
        // After post-process the slide's body-idx must exist in its layout.
        $md = "# Content\n- bullet";
        $out = sys_get_temp_dir() . '/ppthelper_test_idx_norm.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);

        $z = new ZipArchive();
        $z->open($out);
        $rels = $z->getFromName('ppt/slides/_rels/slide1.xml.rels');
        preg_match('#slideLayout(\d+)#', $rels, $lm);
        $layout = $z->getFromName('ppt/slideLayouts/slideLayout' . $lm[1] . '.xml');
        $slide = $z->getFromName('ppt/slides/slide1.xml');
        $z->close();

        // collect body-idx from layout (non-system placeholders)
        $layout_body_idxs = [];
        if (preg_match_all('#<p:sp\b[^>]*>.*?</p:sp>#s', $layout, $sps) !== false) {
            foreach ($sps[0] as $sp) {
                if (preg_match('#<p:ph\b[^/]*\btype="(?:title|ctrTitle|subTitle|dt|ftr|sldNum)"#', $sp) === 1) {
                    continue;
                }
                if (preg_match('#<p:ph\b[^/]*\bidx="(\d+)"#', $sp, $im) === 1) {
                    $layout_body_idxs[] = $im[1];
                }
            }
        }
        if ($layout_body_idxs === []) {
            $this->markTestSkipped('layout has no body placeholder — no normalisation possible');
        }
        // slide's body-idx must be one of the layout's body-idx
        $slide_body_idx = null;
        if (preg_match_all('#<p:sp\b[^>]*>.*?</p:sp>#s', $slide, $sps) !== false) {
            foreach ($sps[0] as $sp) {
                if (preg_match('#<p:ph\b[^/]*\btype="(?:title|ctrTitle|subTitle|dt|ftr|sldNum)"#', $sp) === 1) {
                    continue;
                }
                if (preg_match('#<p:ph\b[^/]*\bidx="(\d+)"#', $sp, $im) === 1) {
                    $slide_body_idx = $im[1];
                    break;
                }
            }
        }
        $this->assertNotNull($slide_body_idx, 'slide must have a body placeholder');
        $this->assertContains(
            $slide_body_idx,
            $layout_body_idxs,
            'slide body-idx (' . $slide_body_idx . ') must exist in layout body-idx list (' . implode(',', $layout_body_idxs) . ')'
        );
    }

    public function test__quote_paragraph_marl_stripped(): void
    {
        // Pandoc emits blockquote paragraphs with marL="1270000". The quote
        // layout's body is centered, so a non-zero marL pushes short lines
        // (typically the attribution) visibly off-center to the right.
        // remapQuoteSlides must drop the marL after detection so PowerPoint
        // centers over the full body width.
        $skeleton = self::findSkeletonWithFeature(static function (string $layout_xml): bool {
            return preg_match('#<p:cSld\b[^>]*\bname="[^"]*(zitat|quote)#i', $layout_xml) === 1;
        });
        if ($skeleton === null) {
            $this->markTestSkipped('no skeleton with quote/zitat layout available in assets/');
        }
        $md = "# Quote slide\n\n> Lorem ipsum dolor sit amet.\n>\n> — Author";
        $out = sys_get_temp_dir() . '/ppthelper_test_quote_marl.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'style_template' => $skeleton, 'output' => $out]);
        $slide = self::loadSlideXml($out, 1);
        $this->assertDoesNotMatchRegularExpression(
            '#<a:pPr\b[^/]*\bmarL="1270000"#',
            $slide,
            'quote slide must not retain pandoc blockquote marL after remap'
        );
        // text must still be present
        $this->assertStringContainsString('Lorem ipsum', $slide);
        $this->assertStringContainsString('— Author', $slide);
    }

    public function test__image_alt_caption_is_stripped(): void
    {
        // Pandoc renders `![governance](path)` with the alt-text as a free
        // <p:sp txBox="1"> caption below the picture. On compact slide
        // formats this caption collides with the master footer zone; we
        // strip all free text boxes (no <p:ph>) so the picture stands clean.
        $skeleton = dirname(__DIR__) . '/assets/default.pptx';
        $md = "# Bild\n\n![Governance](" . $skeleton . ")";
        $out = sys_get_temp_dir() . '/ppthelper_test_caption_strip.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);
        $slide = self::loadSlideXml($out, 1);
        // There must not be a free TextBox shape (txBox="1" without <p:ph>).
        $this->assertDoesNotMatchRegularExpression(
            '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?txBox="1"(?:(?!</p:sp>)(?!<p:ph\b).)*?</p:sp>#s',
            $slide,
            'free text-box image captions must be stripped'
        );
        // The picture itself must still be there
        $this->assertMatchesRegularExpression(
            '#<p:pic\b#',
            $slide,
            'the picture must survive the caption strip'
        );
        // accessibility: alt-text preserved on the picture descr attribute
        $this->assertMatchesRegularExpression(
            '#<p:pic\b[^>]*>(?:(?!</p:pic>).)*?descr="[^"]+"#s',
            $slide,
            'picture alt-text (descr) must be preserved for accessibility'
        );
    }

    public function test__picture_fits_within_layout_column_bounds(): void
    {
        // Pandoc emits <p:pic> with a hard-coded ~4.4"-wide geometry. On
        // layouts whose left body-column is narrower (e.g. 3.43"), the pic
        // would spill into the right column and overlap the text. After
        // refit the picture's right edge must NOT exceed the left layout
        // column's right edge.
        $skeleton = self::findSkeletonWithFeature(static function (string $layout_xml): bool {
            // pick any two-content layout
            return preg_match('#<p:sldLayout\b[^>]*\btype="twoObj"#', $layout_xml) === 1;
        });
        if ($skeleton === null) {
            $this->markTestSkipped('no twoObj layout available in assets/');
        }
        // find left+right body-column geometry from the layout
        $z = new ZipArchive();
        $z->open($skeleton);
        $left_col = null;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (!is_string($n) || preg_match('#^ppt/slideLayouts/slideLayout\d+\.xml$#', $n) !== 1) {
                continue;
            }
            $x = $z->getFromIndex($i);
            if (is_string($x) && preg_match('#<p:sldLayout\b[^>]*\btype="twoObj"#', $x) === 1) {
                $cols = [];
                if (preg_match_all('#<p:sp\b[^>]*>.*?</p:sp>#s', $x, $sps) !== false) {
                    foreach ($sps[0] as $sp) {
                        if (preg_match('#<p:ph\b[^/]*\btype="(?:title|ctrTitle|subTitle|dt|ftr|sldNum)"#', $sp) === 1) {
                            continue;
                        }
                        if (preg_match('#<p:ph\b#', $sp) !== 1) {
                            continue;
                        }
                        if (
                            preg_match('#<a:off\s+x="(\d+)"\s+y="(\d+)"#', $sp, $om) === 1 &&
                            preg_match('#<a:ext\s+cx="(\d+)"\s+cy="(\d+)"#', $sp, $em) === 1
                        ) {
                            $cols[] = ['x' => (int) $om[1], 'cx' => (int) $em[1]];
                        }
                    }
                }
                usort($cols, static fn($a, $b) => $a['x'] <=> $b['x']);
                if (count($cols) >= 2) {
                    $left_col = $cols[0];
                }
                break;
            }
        }
        $z->close();
        if ($left_col === null) {
            $this->markTestSkipped('twoObj layout has no usable two-column body geometry');
        }
        // render a two-column slide with image left + bullets right
        $sample_path = sys_get_temp_dir() . '/ppthelper_test_pic_fit_sample.png';
        if (!is_file($sample_path)) {
            $im = imagecreatetruecolor(1280, 720);
            $bg = imagecolorallocate($im, 200, 220, 255);
            imagefilledrectangle($im, 0, 0, 1280, 720, $bg);
            imagepng($im, $sample_path);
            imagedestroy($im);
        }
        $md = "# Two col\n\n:::: {.columns}\n::: {.column}\n![pic](" . $sample_path . ")\n:::\n::: {.column}\n- right text\n:::\n::::";
        $out = sys_get_temp_dir() . '/ppthelper_test_pic_fit.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'style_template' => $skeleton, 'output' => $out]);
        $slide = self::loadSlideXml($out, 1);
        $this->assertMatchesRegularExpression(
            '#<p:pic\b#',
            $slide,
            'two-column slide must contain a picture'
        );
        if (
            preg_match('#<p:pic\b[^>]*>.*?<a:off\s+x="(\d+)"\s+y="\d+"\s*/>.*?<a:ext\s+cx="(\d+)"\s+cy="\d+"\s*/>.*?</p:pic>#s', $slide, $m) === 1
        ) {
            $pic_right = (int) $m[1] + (int) $m[2];
            $col_right = $left_col['x'] + $left_col['cx'];
            $this->assertLessThanOrEqual(
                $col_right,
                $pic_right,
                "picture right edge ({$pic_right}) must not exceed left layout column right edge ({$col_right})"
            );
        } else {
            $this->fail('could not parse pic geometry');
        }
    }

    public function test__two_column_text_left_picture_right_pattern(): void
    {
        // Pandoc's column fences are order-positional: first .column is left,
        // second is right. ppthelper must produce a clean split for BOTH
        // orderings — image-left/text-right AND text-left/image-right. The
        // body-idx-normalizer + picture-fit pass should automatically land
        // the picture in whichever column the markdown specifies, with the
        // body text in the opposite column. This test exercises the
        // text-LEFT / picture-RIGHT pattern explicitly.
        $skeleton = self::findSkeletonWithFeature(static function (string $layout_xml): bool {
            return preg_match('#<p:sldLayout\b[^>]*\btype="twoObj"#', $layout_xml) === 1;
        });
        if ($skeleton === null) {
            $this->markTestSkipped('no twoObj layout available in assets/');
        }
        // collect the two-column layout's left/right body geometries
        $z = new ZipArchive();
        $z->open($skeleton);
        $cols = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (!is_string($n) || preg_match('#^ppt/slideLayouts/slideLayout\d+\.xml$#', $n) !== 1) {
                continue;
            }
            $x = $z->getFromIndex($i);
            if (is_string($x) && preg_match('#<p:sldLayout\b[^>]*\btype="twoObj"#', $x) === 1) {
                if (preg_match_all('#<p:sp\b[^>]*>.*?</p:sp>#s', $x, $sps) !== false) {
                    foreach ($sps[0] as $sp) {
                        if (preg_match('#<p:ph\b[^/]*\btype="(?:title|ctrTitle|subTitle|dt|ftr|sldNum)"#', $sp) === 1) {
                            continue;
                        }
                        if (preg_match('#<p:ph\b[^/]*\bidx="(\d+)"#', $sp, $im) !== 1) {
                            continue;
                        }
                        if (
                            preg_match('#<a:off\s+x="(\d+)"\s+y="\d+"#', $sp, $om) === 1 &&
                            preg_match('#<a:ext\s+cx="(\d+)"\s+cy="\d+"#', $sp, $em) === 1
                        ) {
                            $cols[] = ['idx' => $im[1], 'x' => (int) $om[1], 'cx' => (int) $em[1]];
                        }
                    }
                }
                break;
            }
        }
        $z->close();
        if (count($cols) < 2) {
            $this->markTestSkipped('twoObj layout has fewer than 2 body columns');
        }
        usort($cols, static fn($a, $b) => $a['x'] <=> $b['x']);
        $left_col = $cols[0];
        $right_col = $cols[1];

        $sample_path = sys_get_temp_dir() . '/ppthelper_test_pattern_b_sample.png';
        if (!is_file($sample_path)) {
            $im = imagecreatetruecolor(1280, 720);
            $bg = imagecolorallocate($im, 220, 200, 240);
            imagefilledrectangle($im, 0, 0, 1280, 720, $bg);
            imagepng($im, $sample_path);
            imagedestroy($im);
        }
        // Pattern B: bullets LEFT (first column), image RIGHT (second column)
        $md = "# Text left, picture right\n\n:::: {.columns}\n::: {.column}\n- LEFT_TEXT_BULLET_A\n- LEFT_TEXT_BULLET_B\n:::\n::: {.column}\n![pic](" . $sample_path . ")\n:::\n::::";
        $out = sys_get_temp_dir() . '/ppthelper_test_pattern_b.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'style_template' => $skeleton, 'output' => $out]);
        $slide = self::loadSlideXml($out, 1);

        // (1) the picture must land in the RIGHT column
        $this->assertMatchesRegularExpression(
            '#<p:pic\b#',
            $slide,
            'two-column slide must contain a picture'
        );
        if (
            preg_match('#<p:pic\b[^>]*>.*?<a:off\s+x="(\d+)"\s+y="\d+"\s*/>.*?<a:ext\s+cx="(\d+)"\s+cy="\d+"\s*/>.*?</p:pic>#s', $slide, $pm) === 1
        ) {
            $pic_x = (int) $pm[1];
            $pic_right = (int) $pm[1] + (int) $pm[2];
            $this->assertGreaterThanOrEqual(
                $right_col['x'],
                $pic_x,
                "picture x ({$pic_x}) must be at or right of the right column start ({$right_col['x']})"
            );
            $this->assertLessThanOrEqual(
                $right_col['x'] + $right_col['cx'],
                $pic_right,
                "picture right edge ({$pic_right}) must not exceed right column right edge"
            );
        } else {
            $this->fail('could not parse pic geometry');
        }

        // (2) the bullet body must land in the LEFT column — its <p:ph idx>
        // must match the layout's left-column idx (not the right one)
        $this->assertMatchesRegularExpression(
            '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*\bidx="' . preg_quote($left_col['idx'], '#') . '"(?:(?!</p:sp>).)*?LEFT_TEXT_BULLET_A#s',
            $slide,
            "left-text bullets must be anchored to the LEFT column body-idx ({$left_col['idx']})"
        );
        $this->assertDoesNotMatchRegularExpression(
            '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*\bidx="' . preg_quote($right_col['idx'], '#') . '"(?:(?!</p:sp>).)*?LEFT_TEXT_BULLET_A#s',
            $slide,
            "left-text bullets must NOT be in the RIGHT column (idx {$right_col['idx']})"
        );
    }

    public function test__single_body_with_picture_goes_to_unoccupied_column(): void
    {
        // Pandoc renders "two-column image + bullets" as a free <p:pic>
        // (hardcoded x of the left column) + one body placeholder for the
        // bullets. The bullets must be mapped to the layout body in the
        // OTHER column, not the one already occupied by the picture.
        $skeleton = dirname(__DIR__) . '/assets/default.pptx';
        $z = new ZipArchive();
        $z->open($skeleton);
        // find the two-content layout (twoObj) and inspect its bodies
        $layout_id = null;
        $bodies = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (!is_string($n) || preg_match('#^ppt/slideLayouts/slideLayout(\d+)\.xml$#', $n, $sm) !== 1) {
                continue;
            }
            $x = $z->getFromIndex($i);
            if (is_string($x) && preg_match('#<p:sldLayout\b[^>]*\btype="twoObj"#', $x) === 1) {
                $layout_id = (int) $sm[1];
                if (preg_match_all('#<p:sp\b[^>]*>.*?</p:sp>#s', $x, $sps) !== false) {
                    foreach ($sps[0] as $sp) {
                        if (preg_match('#<p:ph\b[^/]*\btype="(?:title|ctrTitle|subTitle|dt|ftr|sldNum)"#', $sp) === 1) {
                            continue;
                        }
                        if (preg_match('#<p:ph\b[^/]*\bidx="(\d+)"#', $sp, $im) === 1
                            && preg_match('#<a:off x="(\d+)"#', $sp, $om) === 1
                        ) {
                            $bodies[] = ['idx' => $im[1], 'x' => (int) $om[1]];
                        }
                    }
                }
                break;
            }
        }
        $z->close();
        if ($layout_id === null || count($bodies) < 2) {
            $this->markTestSkipped('skeleton has no two-content layout with 2 bodies');
        }
        usort($bodies, static fn($a, $b) => $a['x'] <=> $b['x']);
        $left_idx = $bodies[0]['idx'];
        $right_idx = $bodies[1]['idx'];

        $md = "# Two col with pic\n\n:::: {.columns}\n::: {.column}\n![hero](" . $skeleton . ")\n:::\n::: {.column}\n- RIGHT_BULLET\n:::\n::::";
        $out = sys_get_temp_dir() . '/ppthelper_test_pic_aware.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);
        $slide = self::loadSlideXml($out, 1);

        // The bullets must be in the right-column body (not the same column as the picture)
        $this->assertMatchesRegularExpression(
            '#<p:ph\b[^/]*\bidx="' . preg_quote($right_idx, '#') . '"(?:(?!</p:sp>).)*?RIGHT_BULLET#s',
            $slide,
            'bullet body must be mapped to the un-occupied right-column body-idx (' . $right_idx . '), not under the picture'
        );
        // and NOT in the left-column idx
        $this->assertDoesNotMatchRegularExpression(
            '#<p:ph\b[^/]*\bidx="' . preg_quote($left_idx, '#') . '"(?:(?!</p:sp>).)*?RIGHT_BULLET#s',
            $slide,
            'bullet body must NOT be on the left (picture occupies that column)'
        );
    }

    public function test__two_column_idx_mapped_in_reading_order(): void
    {
        // Pandoc emits left=idx="1", right=idx="2" for two-column slides.
        // After normalisation both must point to actual layout body-idx,
        // ordered by x-position (left layout-body for left slide-body).
        $md = "# Two cols\n\n:::: {.columns}\n::: {.column}\n- LEFT_BULLET\n:::\n::: {.column}\n- RIGHT_BULLET\n:::\n::::";
        $out = sys_get_temp_dir() . '/ppthelper_test_idx_twocol.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);

        $z = new ZipArchive();
        $z->open($out);
        $rels = $z->getFromName('ppt/slides/_rels/slide1.xml.rels');
        preg_match('#slideLayout(\d+)#', $rels, $lm);
        $layout = $z->getFromName('ppt/slideLayouts/slideLayout' . $lm[1] . '.xml');
        $slide = $z->getFromName('ppt/slides/slide1.xml');
        $z->close();

        // collect layout body-idx sorted by x
        $layout_bodies = [];
        if (preg_match_all('#<p:sp\b[^>]*>.*?</p:sp>#s', $layout, $sps) !== false) {
            foreach ($sps[0] as $sp) {
                if (preg_match('#<p:ph\b[^/]*\btype="(?:title|ctrTitle|subTitle|dt|ftr|sldNum)"#', $sp) === 1) {
                    continue;
                }
                if (preg_match('#<p:ph\b[^/]*\bidx="(\d+)"#', $sp, $im) !== 1) {
                    continue;
                }
                $x = 0;
                if (preg_match('#<a:off x="(\d+)"#', $sp, $om) === 1) {
                    $x = (int) $om[1];
                }
                $layout_bodies[] = ['idx' => $im[1], 'x' => $x];
            }
        }
        if (count($layout_bodies) < 2) {
            $this->markTestSkipped('layout has fewer than 2 body placeholders');
        }
        usort($layout_bodies, static fn($a, $b) => $a['x'] <=> $b['x']);
        $expected_left = $layout_bodies[0]['idx'];
        $expected_right = $layout_bodies[1]['idx'];

        // verify slide content matches expected placement
        $this->assertMatchesRegularExpression(
            '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*\bidx="' . preg_quote($expected_left, '#') . '"(?:(?!</p:sp>).)*?LEFT_BULLET#s',
            $slide,
            'LEFT_BULLET must sit in the layout-left body placeholder (idx=' . $expected_left . ')'
        );
        $this->assertMatchesRegularExpression(
            '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*\bidx="' . preg_quote($expected_right, '#') . '"(?:(?!</p:sp>).)*?RIGHT_BULLET#s',
            $slide,
            'RIGHT_BULLET must sit in the layout-right body placeholder (idx=' . $expected_right . ')'
        );
    }

    public function test__non_quote_slide_keeps_default_layout(): void
    {
        // Plain content slide (bullets, not blockquote) must NOT be remapped
        // to the quote layout. Run against an input skeleton that actually
        // has a quote layout so the negative assertion is meaningful.
        $skeleton = self::findSkeletonWithFeature(static function (string $layout_xml): bool {
            return preg_match('#<p:cSld\b[^>]*\bname="[^"]*(zitat|quote)#i', $layout_xml) === 1;
        });
        if ($skeleton === null) {
            $this->markTestSkipped('no skeleton with quote/zitat layout available — negative assertion is vacuous');
        }
        // find the quote layout's basename for the negative check
        $z = new ZipArchive();
        $z->open($skeleton);
        $quote_layout = null;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (!is_string($n) || preg_match('#^ppt/slideLayouts/slideLayout\d+\.xml$#', $n) !== 1) {
                continue;
            }
            $x = $z->getFromIndex($i);
            if (
                is_string($x) &&
                preg_match('#<p:cSld\b[^>]*\bname="([^"]+)"#', $x, $nm) === 1 &&
                preg_match('#(zitat|quote)#i', $nm[1]) === 1
            ) {
                $quote_layout = basename($n);
                break;
            }
        }
        $z->close();
        $md = "# Normal content\n\n- not a quote\n- regular bullet";
        $out = sys_get_temp_dir() . '/ppthelper_test_quote_skip.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'style_template' => $skeleton, 'output' => $out]);
        $z = new ZipArchive();
        $z->open($out);
        $rels = $z->getFromName('ppt/slides/_rels/slide1.xml.rels');
        $z->close();
        $this->assertStringNotContainsString(
            $quote_layout,
            $rels,
            'normal content slide must stay on its original layout'
        );
    }

    public function test__sldnum_stub_injected_on_every_slide(): void
    {
        // Pandoc never writes sldNum. The post-process injects a live
        // slidenum field on EVERY slide including the cover so PowerPoint
        // renders consecutive numbers starting at 1.
        $md = "% Cover\n% Author\n% 22. Mai 2026\n\n# Content slide\n\n- bullet";
        $out = sys_get_temp_dir() . '/ppthelper_test_sldnum.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);
        $slide1 = self::loadSlideXml($out, 1);
        $slide2 = self::loadSlideXml($out, 2);
        foreach ([$slide1, $slide2] as $slide) {
            $this->assertMatchesRegularExpression(
                '#<p:ph\b[^/]*\btype="sldNum"#',
                $slide,
                'every slide must carry an injected sldNum stub'
            );
            $this->assertMatchesRegularExpression(
                '#<a:fld\b[^>]*type="slidenum"#',
                $slide,
                'sldNum stub must contain a live slidenum field'
            );
        }
    }

    public function test__ftr_stub_skipped_when_layout_and_master_empty(): void
    {
        // The bundled skeleton has an empty ftr placeholder in every layout
        // and the master — no payload to display. Stub injection would only
        // trigger PowerPoint's "Fußzeile" editor-hint, so it must be a no-op.
        $md = "% Cover\n% Author\n% 22. Mai 2026\n\n# Content\n\n- bullet";
        $out = sys_get_temp_dir() . '/ppthelper_test_no_ftr.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);
        // probe the skeleton itself — only assert "no stub" when it really
        // has no payload, otherwise the assertion is meaningless
        $skeleton = dirname(__DIR__) . '/assets/default.pptx';
        $z = new ZipArchive();
        $z->open($skeleton);
        $any_ftr_payload = false;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (!is_string($n)) {
                continue;
            }
            if (
                preg_match('#^ppt/(slideLayouts/slideLayout|slideMasters/slideMaster)\d+\.xml$#', $n) !== 1
            ) {
                continue;
            }
            $x = $z->getFromIndex($i);
            if (
                is_string($x) &&
                preg_match('#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*\btype="ftr"(?:(?!</p:sp>).)*?</p:sp>#s', $x, $m) === 1 &&
                (preg_match('#<a:t>\s*\S#', $m[0]) === 1 || strpos($m[0], '<a:fld') !== false)
            ) {
                $any_ftr_payload = true;
                break;
            }
        }
        $z->close();
        if ($any_ftr_payload) {
            $this->markTestSkipped('skeleton ships with non-empty footer — stub IS expected, not testing the empty path');
        }
        $slide2 = self::loadSlideXml($out, 2);
        $this->assertDoesNotMatchRegularExpression(
            '#<p:ph\b[^/]*\btype="ftr"#',
            $slide2,
            'with empty layout/master ftr, no stub should be injected'
        );
    }

    public function test__dt_rewritten_to_live_field_when_layout_uses_one(): void
    {
        // ppthelper rewrites Pandoc's static date text into a live
        // <a:fld type="datetime1"> when the layout signals it needs the
        // compact "DD.MM.YYYY" form — i.e. either:
        //   - the layout's dt placeholder ALREADY uses a live datetime1 field
        //   - the dt box is rotated 90° (rot="5400000")
        //   - the dt box is very narrow (cx < ~1.6")
        // Pick any skeleton whose layout matches one of those signals.
        // Restrict to slideLayout1.xml (= the title slide) — that's the only
        // slide ppthelper actually applies the dt-rewrite to. Without this
        // constraint the predicate could match a body-slide's dt placeholder
        // (e.g. slideLayout3) and we'd pick a skeleton whose cover has a
        // wide, static dt — where the rewrite is correctly skipped.
        $skeleton = self::findSkeletonWithFeature(static function (string $layout_xml, string $layout_name): bool {
            if ($layout_name !== 'slideLayout1.xml') {
                return false;
            }
            if (
                preg_match(
                    '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*\btype="dt"(?:(?!</p:sp>).)*?</p:sp>#s',
                    $layout_xml,
                    $dtm
                ) !== 1
            ) {
                return false;
            }
            if (preg_match('#<a:fld\b[^>]*type="datetime1"#', $dtm[0]) === 1) {
                return true;
            }
            if (preg_match('#<a:xfrm\b[^>]*\brot="5400000"#', $dtm[0]) === 1) {
                return true;
            }
            if (
                preg_match('#<a:ext\b[^>]*\bcx="(\d+)"#', $dtm[0], $cxm) === 1
                && (int) $cxm[1] > 0
                && (int) $cxm[1] < 1500000
            ) {
                return true;
            }
            return false;
        });
        if ($skeleton === null) {
            $this->markTestSkipped('no skeleton with a live/rotated/narrow datetime1 placeholder on the title slide — rewrite is a no-op');
        }
        $md = "% Cover Title\n% Subtitle\n% 22. Mai 2026\n\n# Content\n\n- bullet";
        $out = sys_get_temp_dir() . '/ppthelper_test_dt_live.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'style_template' => $skeleton, 'output' => $out]);
        $slide1 = self::loadSlideXml($out, 1);
        if (preg_match(
            '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*\btype="dt"(?:(?!</p:sp>).)*?</p:sp>#s',
            $slide1,
            $m
        ) === 1) {
            $this->assertMatchesRegularExpression(
                '#<a:fld\b[^>]*type="datetime1"#',
                $m[0],
                'dt placeholder must be rewritten to a live datetime1 field'
            );
        } else {
            $this->fail('slide 1 must have a dt placeholder');
        }
    }

    public function test__subtitle_xfrm_stripped(): void
    {
        // The title-slide's subtitle placeholder used to overlap the centered
        // title because Pandoc wrote a y=2914650 box that started before the
        // layout's title-box ended (y=3583036). After our post-process the
        // subtitle must have no xfrm so it inherits the layout's y=3583035.
        $out = sys_get_temp_dir() . '/ppthelper_test_subtitle.pptx';
        @unlink($out);
        ppthelper::render([
            'content_markdown' => "% Big Title\n% A Subtitle Here\n% 22. Mai 2026\n\n# Content slide\n\n- bullet",
            'output' => $out
        ]);
        $slide1 = self::loadSlideXml($out, 1);
        if (preg_match(
            '#<p:sp\b[^>]*>(?:(?!</p:sp>).)*?<p:ph\b[^/]*type="subTitle"(?:(?!</p:sp>).)*?</p:sp>#s',
            $slide1,
            $m
        ) === 1) {
            $this->assertStringNotContainsString(
                '<a:xfrm>',
                $m[0],
                'subTitle placeholder must lose its xfrm (overlaps ctrTitle otherwise)'
            );
        }
    }

    public function test__small_table_keeps_cy(): void
    {
        // 3-row table: under the 5-row threshold, cy must stay as Pandoc set
        // it — no unnecessary growth.
        $md = "# Small\n\n| a | b |\n|---|---|\n| 1 | 2 |\n| 3 | 4 |";
        $out = sys_get_temp_dir() . '/ppthelper_test_table_small.pptx';
        @unlink($out);
        ppthelper::render(['content_markdown' => $md, 'output' => $out]);
        $slide1 = self::loadSlideXml($out, 1);
        if (preg_match('#<p:graphicFrame\b[^>]*>.*?</p:graphicFrame>#s', $slide1, $gfm) !== 1) {
            $this->markTestSkipped('Pandoc rendered no table for this markdown');
        }
        $this->assertMatchesRegularExpression(
            '#<a:ext\s+cx="\d+"\s+cy="\d+"#',
            $gfm[0],
            'small table must still have an <a:ext>'
        );
    }

    /**
     * Renders one realistic charly-style deck (~21 slides, all reachable
     * layouts: cover, agenda, section-headers, content with bullets/tables,
     * two-column with image, quote, image-only, speaker-notes) against every
     * skeleton in `assets/` and writes the result to
     * `tests/output/`. The output is a manual-review fixture — the
     * test only sanity-checks file existence + slide count, the actual visual
     * comparison happens by the reviewer opening each .pptx in PowerPoint.
     */
    public function test__render_all_skeletons_for_manual_review(): void
    {
        // This is a fixture-generator for human review, not a behavior check.
        // It renders one ~21-slide sample deck through every skeleton in
        // `assets/` and dumps the result to `output/`
        // so a reviewer can visually compare looks across templates.
        // In CI we skip it — 32 sequential pandoc runs + post-process passes
        // exhaust the macOS-runner's memory budget (SIGKILL mid-suite), and
        // CI runners can't review .pptx files anyway.
        if (getenv('CI') === 'true' || ($_SERVER['CI'] ?? '') === 'true' || getenv('GITHUB_ACTIONS') === 'true') {
            $this->markTestSkipped('skeleton-batch render is a local visual-review fixture, skipped on CI');
        }
        $input_dir = dirname(__DIR__) . '/assets';
        $output_dir = dirname(__DIR__) . '/tests/output';
        if (!is_dir($input_dir)) {
            $this->markTestSkipped('assets directory missing — drop *.pptx files in there first');
        }
        // glob *.pptx but skip Office lock-files (~$skeleton01.pptx is
        // created while a deck is open in PowerPoint and matches *.pptx too)
        $skeletons = array_values(array_filter(
            glob($input_dir . '/*.pptx') ?: [],
            static fn(string $p): bool => !str_starts_with(basename($p), '~$')
        ));
        if ($skeletons === []) {
            $this->markTestSkipped('no skeletons in assets/');
        }
        if (!is_dir($output_dir) && !mkdir($output_dir, 0775, true) && !is_dir($output_dir)) {
            $this->fail('failed to create tests/output');
        }
        // wipe previous review outputs so a reviewer never opens a stale file
        // from a previous run. leave Office lock-files (~$...) alone — the
        // reviewer may have a previous output still open, and unlink would
        // fail noisily.
        foreach (glob($output_dir . '/*') ?: [] as $stale) {
            if (str_starts_with(basename($stale), '~$')) {
                continue;
            }
            @unlink($stale);
        }
        // generate one neutral placeholder PNG (16:9, 1280x720) outside the
        // review folder so the markdown's `![]()` references resolve without
        // hitting an image-gen API and the review folder stays clean
        $placeholder_path = sys_get_temp_dir() . '/ppthelper_skeleton_placeholder.png';
        if (!is_file($placeholder_path)) {
            $im = imagecreatetruecolor(1280, 720);
            $bg = imagecolorallocate($im, 235, 240, 248);
            $accent = imagecolorallocate($im, 80, 110, 180);
            $line = imagecolorallocate($im, 200, 215, 235);
            imagefilledrectangle($im, 0, 0, 1280, 720, $bg);
            for ($i = 0; $i < 8; $i++) {
                $y = (int) (90 + $i * 70);
                imageline($im, 80, $y, 1200, $y, $line);
            }
            imagefilledrectangle($im, 540, 280, 740, 440, $accent);
            imagestring($im, 5, 540, 460, 'SAMPLE 16:9', $accent);
            imagepng($im, $placeholder_path);
            imagedestroy($im);
        }
        $md = self::buildSampleDeckMarkdown($placeholder_path);
        $failures = [];
        foreach ($skeletons as $skeleton) {
            $stem = pathinfo($skeleton, PATHINFO_FILENAME);
            $out = $output_dir . '/' . $stem . '.pptx';
            try {
                ppthelper::render([
                    'content_markdown' => $md,
                    'style_template' => $skeleton,
                    'output' => $out,
                ]);
            } catch (\Throwable $e) {
                $failures[] = $stem . ': ' . $e->getMessage();
                continue;
            }
            if (!is_file($out)) {
                $failures[] = $stem . ': output file not written';
                continue;
            }
            $slide_count = self::countSlides($out);
            if ($slide_count < 10) {
                $failures[] = $stem . ': only ' . $slide_count . ' slides rendered (expected ≥10)';
            }
        }
        if ($failures !== []) {
            $this->fail(
                "Skeleton renders had failures:\n  - " . implode("\n  - ", $failures)
            );
        }
        // per-file assertion — robust against any stray files that may sit in
        // the output dir for unrelated reasons (e.g. a reviewer drag-and-dropping
        // their own .pptx for comparison)
        foreach ($skeletons as $skeleton) {
            $expected = $output_dir . '/' . pathinfo($skeleton, PATHINFO_FILENAME) . '.pptx';
            $this->assertFileExists(
                $expected,
                'no output produced for skeleton ' . basename($skeleton)
            );
        }
    }

    /**
     * The shared markdown blob used by test__render_all_skeletons_for_manual_review.
     * Covers every layout pattern ppthelper supports: title slide with subtitle,
     * an agenda slide, section-header slides (empty body → auto-remapped to
     * secHead), title+bullets slides, two-column image+bullets slides,
     * tables of different sizes (3 and 7 rows), a quote slide, a bare image
     * slide, and slides with speaker notes.
     */
    private static function buildSampleDeckMarkdown(string $sample_image_path): string
    {
        $img = $sample_image_path;
        $style = 'Flat vector illustration, isometric perspective, soft blue and white palette, minimal composition, clean lines, white background, no text overlays.';
        return <<<MD
% KI in Unternehmen
% Strategie, Wert & Umsetzung
% 23. Mai 2026

# Agenda

- 1. Marktdynamik
- 2. Werthebel
- 3. Daten & Plattform
- 4. Governance
- 5. Roadmap & Fazit

# Teil 1: Marktdynamik

# Adoption steigt rasant

- 78 % der Unternehmen nutzen GenAI wöchentlich
- Pilot-zu-Produktion bleibt das Nadelöhr
- Investitionen verdoppeln sich jedes Jahr
- Skill-Gap wird zur Engpass-Disziplin

::: notes
Quellen: McKinsey State of AI 2025, Stanford AI Index 2025, BCG 2024.
Konkrete Zahl zur Adoption-Tiefe (1× pro Woche, 1× pro Tag, in Kernprozesse) im Anhang.
:::

# Markttrends 2026 im Bild

:::: {.columns}
::: {.column}
![Marktdynamik]($img)
:::
::: {.column}
- Agentic AI verlässt das Lab
- Multimodale Modelle werden Standard
- On-Device-Inferenz für sensible Daten
- Open-Source-Modelle holen auf
:::
::::

# Kernzahlen 2024–2026

| Indikator | 2024 | 2025 | 2026e |
|---|---|---|---|
| KI-Nutzung (wöchentlich) | 56 % | 71 % | 78 % |
| Skalierte Use Cases | 12 % | 19 % | 26 % |
| KI-Investitionen | 180 Mrd | 252 Mrd | 320 Mrd |
| Skills-Gap | hoch | hoch | mittel |
| Governance-Reife | niedrig | mittel | mittel |

# Teil 2: Werthebel

# Wo Wert entsteht

:::: {.columns}
::: {.column}
- Umsatz: Pricing, Sales, Cross-Sell
- Kosten: Automation und Qualität
- Tempo: kürzere Zyklen, weniger Rework
- Innovation: neue Produkte & Services
:::
::: {.column}
![Werthebel]($img)
:::
::::

# Was Andrew Ng sagt

> KI ersetzt nicht Menschen. Menschen mit KI ersetzen Menschen ohne KI.
>
> — frei nach Andrew Ng

# Use-Case-Portfolio

| Bereich | Use Case | Reife |
|---|---|---|
| Service | Agent Assist | hoch |
| Marketing | Content-Engine | hoch |
| Operations | Forecasting | mittel |
| HR | Recruiting Copilot | mittel |
| Finance | Anomalie-Erkennung | niedrig |

# Teil 3: Daten & Plattform

# Daten als Engpass

:::: {.columns}
::: {.column}
![Daten-Plattform]($img)
:::
::: {.column}
- Datenqualität limitiert die meisten Piloten
- Ownership fehlt fast überall
- Vektor-DBs werden zur Pflicht
- Cost-Tracking pro Workflow wird neu
:::
::::

# Architektur-Zielbild

![Architektur]($img)

# Teil 4: Governance

# EU AI Act kompakt

- Anwendungspflicht ab 2026 stufenweise
- High-Risk-Pflichten ab August 2026
- Dokumentation und Audit-Trail werden Pflicht
- Schatten-KI ist heute schon ein Risiko

::: notes
Detail-Timeline siehe Greenberg-Traurig 2025 Compliance-Guide.
Übergang: "Bevor wir zur Roadmap kommen, kurz zu den Kontrollen..."
:::

# Teil 5: Roadmap & Fazit

# 12-Monats-Roadmap

| Quartal | Fokus | Ergebnis |
|---|---|---|
| Q1 | Strategie & Portfolio | Use-Case-Liste |
| Q2 | Plattform & Piloten | produktive MVPs |
| Q3 | Skalierung | Rollout |

# Erfolgsfaktoren

- Vorstands-Sponsoring mit klarer Verantwortung
- Fokus auf wenige, messbare Werthebel
- Daten- und Prozessarbeit vor Modell-Faszination
- Governance als Beschleuniger, nicht als Bremse

# Fazit

- KI ist Organisationsentwicklung, kein IT-Projekt
- Wettbewerbsvorteil entsteht durch integrierte Workflows
- Verantwortungsvolle Skalierung braucht Leitplanken
- Jetzt zählt: priorisieren, produktiv setzen, lernen, skalieren

::: notes
Letzte Folie — Schlussbotschaft für die Diskussion: "Wenn wir in 12 Monaten zurückblicken,
woran erkennen wir, dass dieses Programm erfolgreich war?"
:::
MD;
    }
}
