<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Unit;

use Composer\Autoload\ClassLoader;
use Hekmatinasser\Verta\Verta;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
    public function testComposerRegistersThePackageNamespace(): void
    {
        $root = dirname(__DIR__, 2);
        $loader = ClassLoader::getRegisteredLoaders()[$root . DIRECTORY_SEPARATOR . 'vendor'] ?? null;

        self::assertInstanceOf(ClassLoader::class, $loader);
        $paths = $loader->getPrefixesPsr4()['Zarbinco\\ExpiryPricing\\'] ?? [];

        self::assertContains($root . DIRECTORY_SEPARATOR . 'src', array_map('realpath', $paths));
    }

    public function testRuntimeDependenciesCanBeAutoloaded(): void
    {
        self::assertTrue(class_exists(Verta::class));
        self::assertTrue(class_exists(Str::class));
    }
}
