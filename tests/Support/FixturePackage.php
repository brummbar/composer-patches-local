<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Support;

/**
 * Describes a fixture package: its composer.json data and its files.
 */
final class FixturePackage
{
    /**
     * @param array<string, string> $files
     *   File contents, keyed by path relative to the package.
     * @param array<string, string> $require
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $autoload
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version = '1.0.0',
        public readonly array $files = [],
        public readonly array $require = [],
        public readonly array $extra = [],
        public readonly string $type = 'library',
        public readonly array $autoload = [],
    )
    {
    }

    /**
     * Returns the package composer.json data. The version is in the file.
     *
     * @return array<string, mixed>
     */
    public function composerData(): array
    {
        $data = [
            'name' => $this->name,
            'version' => $this->version,
            'type' => $this->type,
        ];
        if ($this->require) {
            $data['require'] = $this->require;
        }
        if ($this->extra) {
            $data['extra'] = $this->extra;
        }
        if ($this->autoload) {
            $data['autoload'] = $this->autoload;
        }
        return $data;
    }
}
