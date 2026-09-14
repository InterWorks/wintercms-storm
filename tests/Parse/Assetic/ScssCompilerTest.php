<?php

use Assetic\Asset\FileAsset;
use Winter\Storm\Parse\Assetic\Filter\ScssCompiler;

/**
 * End-to-end coverage for the ScssCompiler import confinement. Each test writes a
 * real `.scss` file to a tmp directory, runs `filterLoad()` on it, and checks the
 * compiled CSS for either the absence of a sensitive marker (confinement tests) or
 * the presence of a legitimate marker (preserve-functionality tests).
 *
 * Mirrors LessCompilerTest, which covers the equivalent LESS wiring.
 */
class ScssCompilerTest extends TestCase
{
    /** @var string */
    protected $tmpRoot;

    /** @var string */
    protected $tmpReal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/storm-scss-compiler-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/theme/assets/scss/sub', 0777, true);
        mkdir($this->tmpRoot . '/cross-tree', 0777, true);
        $this->tmpReal = realpath($this->tmpRoot);

        // Emits a rule, so a successful import is visible in the compiled output.
        file_put_contents($this->tmpReal . '/secret.scss', '.leaked { content: "do-not-leak-me"; }');
    }

    protected function tearDown(): void
    {
        (new \Winter\Storm\Filesystem\Filesystem())->deleteDirectory($this->tmpRoot);
        parent::tearDown();
    }

    public function testBlocksRelativeTraversalImport()
    {
        $main = $this->tmpReal . '/theme/assets/scss/main.scss';
        file_put_contents($main, '@import "../../../secret"; .x { color: red; }');

        $this->assertStringNotContainsString('do-not-leak-me', $this->compile($main));
    }

    public function testBlocksAbsolutePathImport()
    {
        $main = $this->tmpReal . '/theme/assets/scss/main.scss';
        file_put_contents($main, '@import "' . $this->tmpReal . '/secret"; .x { color: red; }');

        $this->assertStringNotContainsString('do-not-leak-me', $this->compile($main));
    }

    /**
     * scssphp resolves a nested `@import` against the importing file's own
     * directory, so confinement has to apply to imported files too.
     */
    public function testBlocksTraversalFromAnImportedSubdirectoryFile()
    {
        $main = $this->tmpReal . '/theme/assets/scss/main.scss';
        file_put_contents($main, '@import "sub/child"; .main { color: blue; }');
        file_put_contents(
            $this->tmpReal . '/theme/assets/scss/sub/_child.scss',
            '@import "../../../../secret"; .child { color: red; }'
        );

        $this->assertStringNotContainsString('do-not-leak-me', $this->compile($main));
    }

    public function testAllowsLegitimateSameTreePartial()
    {
        $main = $this->tmpReal . '/theme/assets/scss/main.scss';
        file_put_contents($this->tmpReal . '/theme/assets/scss/_partial.scss', '.partial-marker { color: green; }');
        file_put_contents($main, '@import "partial"; .main-marker { color: blue; }');

        $css = $this->compile($main);

        $this->assertStringContainsString('partial-marker', $css);
        $this->assertStringContainsString('main-marker', $css);
    }

    public function testAllowsNestedPartialChain()
    {
        $main = $this->tmpReal . '/theme/assets/scss/main.scss';
        file_put_contents($main, '@import "sub/child"; .main-marker { color: blue; }');
        file_put_contents(
            $this->tmpReal . '/theme/assets/scss/sub/_child.scss',
            '@import "deeper"; .child-marker { color: green; }'
        );
        file_put_contents(
            $this->tmpReal . '/theme/assets/scss/sub/_deeper.scss',
            '.deeper-marker { color: purple; }'
        );

        $css = $this->compile($main);

        $this->assertStringContainsString('main-marker', $css);
        $this->assertStringContainsString('child-marker', $css);
        $this->assertStringContainsString('deeper-marker', $css);
    }

    public function testAllowsCrossTreeImportWhenRootIsWhitelisted()
    {
        $main = $this->tmpReal . '/theme/assets/scss/main.scss';
        file_put_contents($this->tmpReal . '/cross-tree/_cross.scss', '.cross-tree-marker { color: green; }');
        file_put_contents($main, '@import "cross"; .main { color: blue; }');

        $compiler = new ScssCompiler();
        $compiler->setAllowedImportRoots([$this->tmpReal . '/cross-tree']);
        $compiler->addImportPath($this->tmpReal . '/cross-tree');

        $this->assertStringContainsString('cross-tree-marker', $this->compile($main, $compiler));
    }

    protected function compile(string $sourceFile, ?ScssCompiler $compiler = null): string
    {
        $compiler ??= new ScssCompiler();
        $asset = new FileAsset($sourceFile, [], dirname($sourceFile), basename($sourceFile));
        $asset->load();
        $compiler->filterLoad($asset);
        return $asset->getContent();
    }
}
