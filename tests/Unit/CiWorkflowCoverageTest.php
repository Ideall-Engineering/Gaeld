<?php

namespace Tests\Unit;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Guards the hand-maintained test-path list in .github/workflows/ci.yml against
 * drift. The matrix names every suite explicitly, so a new directory under
 * tests/Feature or a new module under plugins/ is silently never run in CI
 * until someone remembers to add it — which is exactly what happened to
 * tests/Feature/Automation, EditionBoundary, Http, Plugins and the whole
 * accountant-api module.
 *
 * The expectation is derived from disk rather than hard-coded here, so adding a
 * suite is the only thing anyone has to remember: this test then insists CI
 * runs it.
 */
class CiWorkflowCoverageTest extends TestCase
{
    /** EE tests ship from phpunit.ee.xml and must never run in the public CE workflow. */
    private const PRIVATE_PLUGINS = ['gaeld-ee'];

    public function test_every_suite_on_disk_runs_in_the_ci_matrix(): void
    {
        $configured = $this->configuredTestPaths();

        foreach ($this->expectedTestPaths() as $expected) {
            $this->assertContains(
                $expected,
                $configured,
                "{$expected} contains tests but no CI matrix entry runs it. ".
                'Add it to a test-paths group in .github/workflows/ci.yml.'
            );
        }
    }

    public function test_every_configured_path_exists_and_actually_holds_tests(): void
    {
        foreach ($this->configuredTestPaths() as $path) {
            $absolute = base_path($path);

            $this->assertFileExists($absolute, "The CI matrix runs {$path}, which does not exist.");

            $this->assertNotEmpty(
                $this->findTestFiles($absolute),
                "The CI matrix runs {$path}, which contains no PHPUnit tests. ".
                'PHPUnit reports success without executing anything, so the job is a false green.'
            );
        }
    }

    public function test_no_path_is_run_by_more_than_one_group(): void
    {
        $configured = $this->configuredTestPaths();

        $this->assertSame(
            array_values(array_unique($configured)),
            $configured,
            'A test path appears in more than one CI matrix group and would run twice.'
        );
    }

    /**
     * Every test-paths value in the workflow's test matrix, split into single paths.
     *
     * @return list<string>
     */
    private function configuredTestPaths(): array
    {
        /** @var array{jobs: array{test: array{strategy: array{matrix: array{include: list<array{name: string, 'test-paths': string}>}}}}} $workflow */
        $workflow = Yaml::parseFile(base_path('.github/workflows/ci.yml'));

        $paths = [];
        foreach ($workflow['jobs']['test']['strategy']['matrix']['include'] as $group) {
            foreach (preg_split('/\s+/', trim($group['test-paths'])) ?: [] as $path) {
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * Suites that must be run by some CI group: every immediate child of
     * tests/Feature, every other tests/* directory that holds PHPUnit tests, and
     * every public plugin's test directory.
     *
     * @return list<string>
     */
    private function expectedTestPaths(): array
    {
        $expected = [];

        foreach ($this->childrenOf(base_path('tests/Feature')) as $child) {
            if (is_dir($child) || str_ends_with($child, 'Test.php')) {
                $expected[] = $this->relative($child);
            }
        }

        foreach ($this->childrenOf(base_path('tests')) as $child) {
            if (is_dir($child) && basename($child) !== 'Feature' && $this->findTestFiles($child) !== []) {
                $expected[] = $this->relative($child);
            }
        }

        foreach ($this->childrenOf(base_path('plugins')) as $plugin) {
            if (! is_dir($plugin) || in_array(basename($plugin), self::PRIVATE_PLUGINS, true)) {
                continue;
            }

            if (is_dir($plugin.'/tests') && $this->findTestFiles($plugin.'/tests') !== []) {
                $expected[] = $this->relative($plugin.'/tests');
            }
        }

        sort($expected);

        return $expected;
    }

    /**
     * @return list<string> Absolute paths, without the dot entries
     */
    private function childrenOf(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $entry): string => $directory.'/'.$entry,
            array_diff(scandir($directory) ?: [], ['.', '..'])
        ));
    }

    /**
     * @return list<string> Every *Test.php at or below the given path
     */
    private function findTestFiles(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, 'Test.php') ? [$path] : [];
        }

        $found = [];
        foreach ($this->childrenOf($path) as $child) {
            $found = [...$found, ...$this->findTestFiles($child)];
        }

        return $found;
    }

    private function relative(string $absolute): string
    {
        return ltrim(str_replace(base_path(), '', $absolute), '/');
    }
}
