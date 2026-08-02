<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ModuleDependencyTest extends TestCase
{
    private const array BUSINESS_LAYERS = ['Domain', 'Application', 'Adapter'];

    public function testSourceNamespacesMatchTheirPaths(): void
    {
        $violations = [];

        foreach ($this->sourceFiles() as $file) {
            $relativePath = $this->relativePath($file);

            if ('Kernel.php' === $relativePath) {
                continue;
            }

            $expectedNamespace = 'App\\'.str_replace('/', '\\', dirname($relativePath));
            $actualNamespace = $this->namespaceOf($file);

            if ($expectedNamespace !== $actualNamespace) {
                $violations[] = sprintf(
                    '%s declares %s; expected %s.',
                    $relativePath,
                    $actualNamespace ?? '<no namespace>',
                    $expectedNamespace,
                );
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function testModuleDependenciesFollowAdr015(): void
    {
        $violations = [];

        foreach ($this->sourceFiles() as $file) {
            $relativePath = $this->relativePath($file);

            if ('Kernel.php' === $relativePath) {
                continue;
            }

            $namespace = $this->namespaceOf($file);

            if (null === $namespace) {
                $violations[] = sprintf('%s has no namespace.', $relativePath);

                continue;
            }

            $current = explode('\\', $namespace);
            $currentModule = $current[1] ?? null;
            $currentLayer = $current[2] ?? null;

            if (null === $currentModule) {
                $violations[] = sprintf('%s is outside a module namespace.', $relativePath);

                continue;
            }

            if ('Infrastructure' !== $currentModule && !in_array($currentLayer, self::BUSINESS_LAYERS, true)) {
                $violations[] = sprintf(
                    '%s must be in Domain, Application or Adapter.',
                    $relativePath,
                );

                continue;
            }

            foreach ($this->qualifiedNamesIn($file) as $dependency) {
                $violation = $this->dependencyViolation($currentModule, $currentLayer, $dependency);

                if (null !== $violation) {
                    $violations[] = sprintf('%s: %s', $relativePath, $violation);
                }
            }
        }

        self::assertSame([], array_values(array_unique($violations)), implode(PHP_EOL, array_unique($violations)));
    }

    public function testControllersDoNotDependOnDoctrineRecords(): void
    {
        $violations = [];

        foreach ($this->sourceFiles() as $file) {
            if (!str_ends_with($file->getBasename(), 'Controller.php')) {
                continue;
            }

            foreach ($this->qualifiedNamesIn($file) as $dependency) {
                if (str_contains($dependency, '\\Persistence\\Doctrine\\Record\\')) {
                    $violations[] = sprintf(
                        '%s must return a response DTO or array, not depend on %s.',
                        $this->relativePath($file),
                        $dependency,
                    );
                }
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /** @return list<SplFileInfo> */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->sourceRoot()),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function dependencyViolation(string $currentModule, ?string $currentLayer, string $dependency): ?string
    {
        $parts = explode('\\', ltrim($dependency, '\\'));

        if ('Infrastructure' === $currentModule) {
            if ('App' !== $parts[0] || 'Infrastructure' === ($parts[1] ?? null)) {
                return null;
            }

            if ('Application' === ($parts[2] ?? null) && in_array($parts[3] ?? null, ['Api', 'Port'], true)) {
                return null;
            }

            return sprintf('Infrastructure may use another module only through Application\\Api or Application\\Port, got %s.', $dependency);
        }

        if ('App' !== $parts[0]) {
            if ('Adapter' === $currentLayer) {
                return null;
            }

            return sprintf('%s must not depend on framework or vendor type %s.', $currentLayer, $dependency);
        }

        $targetModule = $parts[1] ?? null;
        $targetLayer = $parts[2] ?? null;

        if ($currentModule !== $targetModule) {
            if ('Application' === $targetLayer && 'Api' === ($parts[3] ?? null) && 'Domain' !== $currentLayer) {
                return null;
            }

            return sprintf('cross-module dependency must target Application\\Api, got %s.', $dependency);
        }

        $allowedLayers = match ($currentLayer) {
            'Domain' => ['Domain'],
            'Application' => ['Domain', 'Application'],
            'Adapter' => ['Domain', 'Application', 'Adapter'],
            default => [],
        };

        if (!in_array($targetLayer, $allowedLayers, true)) {
            return sprintf('%s must not depend on %s.', $currentLayer, $dependency);
        }

        return null;
    }

    private function namespaceOf(SplFileInfo $file): ?string
    {
        $contents = file_get_contents($file->getPathname());
        self::assertIsString($contents);

        if (1 !== preg_match('/^namespace\s+([^;]+);/m', $contents, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    /** @return list<string> */
    private function qualifiedNamesIn(SplFileInfo $file): array
    {
        $contents = file_get_contents($file->getPathname());
        self::assertIsString($contents);
        $names = [];

        foreach (token_get_all($contents) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $names[] = ltrim($token[1], '\\');
            }
        }

        return array_values(array_unique($names));
    }

    private function relativePath(SplFileInfo $file): string
    {
        return str_replace('\\', '/', substr($file->getPathname(), strlen($this->sourceRoot()) + 1));
    }

    private function sourceRoot(): string
    {
        return dirname(__DIR__, 2).'/src';
    }
}
