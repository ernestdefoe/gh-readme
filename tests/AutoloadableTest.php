<?php

namespace Ernestdefoe\GhReadme\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every class under src/ is reachable by the name it declares.
 *
 * 🚨 This is the test that would have caught the bug it was written for, and
 * the bug was invisible for a release.
 *
 * `MarkdownToHtml` declared `ErnestDefoe\...` where the PSR-4 prefix — and
 * every other file — is `Ernestdefoe\...`. Composer matches the namespace
 * prefix as a string, so one capital letter made the class unloadable. It
 * survived review, a release and a tag because macOS has a case-insensitive
 * filesystem: the FILE is always found locally, and only the Linux host it
 * deploys to ever disagrees. The feature 500'd on every use from the day it
 * shipped.
 *
 * Nothing here mocks or stubs. It walks src/, reads the namespace each file
 * DECLARES, and asks the autoloader for it — which is exactly the question PHP
 * asks at runtime, and the only one that catches a case slip.
 */
class AutoloadableTest extends TestCase
{
    /** @dataProvider classes */
    public function test_the_class_a_file_declares_can_be_loaded(string $class, string $file): void
    {
        $this->assertTrue(
            class_exists($class) || interface_exists($class) || trait_exists($class),
            "$file declares $class, and the autoloader cannot find it. "
                . 'Check the namespace against the psr-4 prefix in composer.json, including its capitals.'
        );
    }

    public static function classes(): array
    {
        $root = dirname(__DIR__) . '/src';
        $cases = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)
                || ! preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $source, $cls)) {
                continue;
            }

            $name = trim($ns[1]) . '\\' . $cls[1];
            $cases[$name] = [$name, str_replace($root . '/', '', $file->getPathname())];
        }

        return $cases;
    }
}
