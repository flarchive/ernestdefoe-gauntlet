<?php

namespace ErnestDefoe\Gauntlet\Tests\unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every key the code asks for exists in en.yml. flarum/testing loads no
 * extension locale files, so a missing key passes every integration test and
 * shows as raw text on a real forum.
 */
class LocaleTest extends TestCase
{
    public function test_every_key_in_use_is_translated(): void
    {
        $root = dirname(__DIR__, 2);
        $strings = Yaml::parseFile($root.'/locale/en.yml')['ernestdefoe-gauntlet'];
        $has = function (string $key) use ($strings): bool {
            $node = $strings;
            foreach (explode('.', $key) as $part) {
                if (! is_array($node) || ! array_key_exists($part, $node)) {
                    return false;
                }
                $node = $node[$part];
            }

            return is_string($node);
        };

        $used = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            preg_match_all("/ernestdefoe-gauntlet\\.((?:api|email|forum|admin)\\.[a-z_.]+[a-z_])'/", $code, $m);
            $used = array_merge($used, $m[1]);
            preg_match_all("/fail\\('[a-zA-Z0-9]+', '([a-z_]+)'/", $code, $m);
            $used = array_merge($used, array_map(fn ($k) => "api.$k", $m[1]));
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/js/src/forum')) as $file) {
            if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }
            preg_match_all("/\\bt\\(\\s*'([a-z_]+\\.[a-z_.]+[a-z])'/", (string) file_get_contents($file->getPathname()), $m);
            $used = array_merge($used, array_map(fn ($k) => "forum.$k", $m[1]));
        }

        $this->assertGreaterThan(100, count(array_unique($used)));
        $this->assertSame([], array_values(array_unique(array_filter($used, fn ($key) => ! $has($key)))));
    }
}
