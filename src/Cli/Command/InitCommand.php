<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Cli\Command;

final class InitCommand
{
    /** @param array<int,string> $args */
    public function run(array $args): int
    {
        $opts = Options::parse($args);
        $path = $opts['schema'];
        if (file_exists($path)) {
            fwrite(STDERR, "tehilim: {$path} already exists\n");
            return 1;
        }
        file_put_contents($path, $this->template());
        echo "Created {$path}\n";
        return 0;
    }

    private function template(): string
    {
        return <<<'TXT'
// Tehilim schema — see https://github.com/polidog/tehilim

datasource db {
  provider = "sqlite"
  url      = "sqlite:./dev.sqlite"
}

generator client {
  output    = "./src/Generated"
  namespace = "App\\Generated"
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
}
