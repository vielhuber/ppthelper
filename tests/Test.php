<?php
declare(strict_types=1);

use vielhuber\ppthelper\ppthelper;

class Test extends \PHPUnit\Framework\TestCase
{
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
            'input_markdown' => "# First slide\n\n- one\n- two\n\n# Second slide\n\nContent body.",
            'output' => $out
        ]);
        $this->assertSame($out, $path);
        $this->assertFileExists($out);
        $this->assertSame(2, self::countSlides($out));
        $slide1 = self::loadSlideXml($out, 1);
        $this->assertStringContainsString('First slide', $slide1);
        $this->assertStringContainsString('<a:t>one</a:t>', $slide1);
    }

    public function test__theming_propagates(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_theme.pptx';
        @unlink($out);
        ppthelper::render([
            'input_markdown' => '# Themed',
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
        ppthelper::render(['input_markdown' => '# X', 'output' => $out, 'colors_primary' => '#1F4E79']);
        $this->assertMatchesRegularExpression('#<a:accent1>\s*<a:srgbClr val="1F4E79"/>#', self::loadThemeXml($out));
        // without hash — still accepted
        ppthelper::render(['input_markdown' => '# X', 'output' => $out, 'colors_primary' => '1F4E79']);
        $this->assertMatchesRegularExpression('#<a:accent1>\s*<a:srgbClr val="1F4E79"/>#', self::loadThemeXml($out));
    }

    public function test__input_file(): void
    {
        $md_path = sys_get_temp_dir() . '/ppthelper_test_input.md';
        file_put_contents($md_path, "# From file\n\n- one\n- two");
        $out = sys_get_temp_dir() . '/ppthelper_test_input.pptx';
        @unlink($out);
        ppthelper::render(['input_file' => $md_path, 'output' => $out]);
        $this->assertFileExists($out);
        $this->assertStringContainsString('From file', self::loadSlideXml($out, 1));
        @unlink($md_path);
    }

    public function test__markdown_and_input_mutually_exclusive(): void
    {
        $this->expectExceptionMessageMatches('#pass either "input_markdown" or "input_file"#');
        ppthelper::render([
            'input_markdown' => '# X',
            'input_file' => '/tmp/whatever.md',
            'output' => sys_get_temp_dir() . '/x.pptx'
        ]);
    }

    public function test__missing_input_raises(): void
    {
        $this->expectExceptionMessageMatches('#one of "input_markdown"#');
        ppthelper::render(['output' => sys_get_temp_dir() . '/x.pptx']);
    }

    public function test__invalid_hex_raises(): void
    {
        $this->expectExceptionMessageMatches('#Invalid color#');
        ppthelper::render([
            'input_markdown' => '# X',
            'output' => sys_get_temp_dir() . '/x.pptx',
            'colors_primary' => 'not-a-color'
        ]);
    }

    public function test__transitions_inject_fade(): void
    {
        $out = sys_get_temp_dir() . '/ppthelper_test_trans.pptx';
        @unlink($out);
        ppthelper::render([
            'input_markdown' => "# A\n- one\n\n# B\n- two",
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
            'input_markdown' => "# A\n- one",
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
            'input_markdown' => "# Bullets\n\n- one\n- two\n- three",
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
            'input_markdown' => "% Deck title\n% Author name\n\n# Content slide\n\n- bullet",
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
        // Empty input_markdown is caught at the resolve step before pandoc
        // ever runs — same error path as omitting the argument entirely.
        $this->expectExceptionMessageMatches('#one of "input_markdown"#');
        ppthelper::render([
            'input_markdown' => '',
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
                'input_markdown' => "# Image slide\n\n![dot](dot.png)",
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
                'input_markdown' => "# Image slide\n\n![dot](" . $img . ")",
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
        ppthelper::render(['input_markdown' => '# Default look', 'output' => $out]);

        $zip = new ZipArchive();
        $zip->open(dirname(__DIR__) . '/assets/skeleton.pptx');
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
            'input_markdown' => "# Alpha\n\n- one\n- two\n\n# Beta\n\n:::: {.columns}\n::: {.column}\nleft text\n:::\n::: {.column}\nright text\n:::\n::::",
            'output' => $out
        ]);

        $skeleton_zip = new ZipArchive();
        $skeleton_zip->open(dirname(__DIR__) . '/assets/skeleton.pptx');
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
            'input_markdown' => "# Themed slide\n\n- bullet",
            'output' => $out,
            'colors_primary' => '#AA22BB'
        ]);
        $theme = self::loadThemeXml($out);
        $this->assertMatchesRegularExpression('#<a:accent1>\s*<a:srgbClr val="AA22BB"/>#', $theme);
    }

    public function test__layout_restore_does_not_clobber_slide_content(): void
    {
        // Slides must keep the model's content after restore. Plain bullets,
        // a heading, a table — all must appear in the output text body.
        $out = sys_get_temp_dir() . '/ppthelper_test_layouts_keep_slides.pptx';
        @unlink($out);
        ppthelper::render([
            'input_markdown' => "# Headline X\n\n- bullet ONE\n- bullet TWO\n\n| a | b |\n|---|---|\n| 1 | 2 |",
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
}
