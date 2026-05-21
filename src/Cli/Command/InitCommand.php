<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Cli\Command;

final class InitCommand
{
    private const DEFAULT_OUTPUT = '../src/Generated';
    private const DEFAULT_NAMESPACE = 'App\Generated';

    /** @param array<int,string> $args */
    public function run(array $args): int
    {
        $opts = Options::parse($args);
        $path = $opts['schema'];
        if (file_exists($path)) {
            fwrite(STDERR, "tehilim: {$path} already exists\n");

            return 1;
        }
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            fwrite(STDERR, "tehilim: cannot create directory {$dir}\n");

            return 1;
        }

        $namespace = $this->detectNamespace($path, self::DEFAULT_OUTPUT) ?? self::DEFAULT_NAMESPACE;
        if (file_put_contents($path, $this->template($namespace)) === false) {
            fwrite(STDERR, "tehilim: failed to write {$path}\n");

            return 1;
        }
        echo "Created {$path} (namespace {$namespace})\n";

        return 0;
    }

    private function template(string $namespace): string
    {
        $nsEscaped = str_replace('\\', '\\\\', $namespace);
        $output = self::DEFAULT_OUTPUT;

        return <<<TXT
// Tehilim schema — see https://github.com/polidog/tehilim

datasource db {
  provider = "sqlite"
  url      = "sqlite:./dev.sqlite"
}

generator client {
  output    = "{$output}"
  namespace = "{$nsEscaped}"
}

model User {
  id        Int      @id @default(autoincrement())
  email     String   @unique
  name      String?
  createdAt DateTime @default(now())
}

model Post {
  id        Int      @id @default(autoincrement())
  title     String
  body      String?
  published Boolean  @default(false)
  authorId  Int
  createdAt DateTime @default(now())
}

TXT;
    }

    /**
     * Try to derive the namespace from composer.json's PSR-4 mappings.
     * Returns null when no composer.json exists, no PSR-4 entry covers the
     * resolved output directory, or anything fails to parse cleanly — the
     * caller falls back to the default in that case.
     */
    private function detectNamespace(string $schemaPath, string $outputRel): ?string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return null;
        }
        $composerPath = $cwd . '/composer.json';
        if (!is_file($composerPath)) {
            return null;
        }
        $contents = @file_get_contents($composerPath);
        if ($contents === false) {
            return null;
        }
        $json = json_decode($contents, true);
        if (!is_array($json)) {
            return null;
        }

        /** @var mixed $psr4Raw */
        $psr4Raw = $json['autoload']['psr-4'] ?? null;
        if (!is_array($psr4Raw) || $psr4Raw === []) {
            return null;
        }

        $schemaAbs = $this->absPath($cwd, $schemaPath);
        $outputAbs = $this->absPath(dirname($schemaAbs), $outputRel);

        $bestPrefix = null;
        $bestDir = null;
        $bestLen = -1;
        foreach ($psr4Raw as $prefix => $dirs) {
            if (!is_string($prefix)) {
                continue;
            }

            /** @var list<string> $dirList */
            $dirList = is_array($dirs) ? array_values(array_filter($dirs, is_string(...))) : (is_string($dirs) ? [$dirs] : []);
            foreach ($dirList as $d) {
                $absDir = $this->absPath($cwd, $d);
                if (!$this->pathContains($absDir, $outputAbs)) {
                    continue;
                }
                if (strlen($absDir) > $bestLen) {
                    $bestPrefix = $prefix;
                    $bestDir = $absDir;
                    $bestLen = strlen($absDir);
                }
            }
        }
        if ($bestPrefix === null || $bestDir === null) {
            return null;
        }

        $suffix = trim(substr($outputAbs, strlen($bestDir)), '/');
        $nsSuffix = $suffix === '' ? '' : str_replace('/', '\\', $suffix);
        $prefix = rtrim($bestPrefix, '\\');
        if ($prefix === '' && $nsSuffix === '') {
            return null;
        }
        if ($prefix === '') {
            return $nsSuffix;
        }
        if ($nsSuffix === '') {
            return $prefix;
        }

        return $prefix . '\\' . $nsSuffix;
    }

    private function absPath(string $base, string $path): string
    {
        $combined = str_starts_with($path, '/') ? $path : rtrim($base, '/') . '/' . $path;
        $parts = [];
        foreach (explode('/', $combined) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $seg;
        }

        return '/' . implode('/', $parts);
    }

    private function pathContains(string $parent, string $child): bool
    {
        $parent = rtrim($parent, '/') . '/';
        $child = rtrim($child, '/') . '/';

        return str_starts_with($child, $parent);
    }
}
