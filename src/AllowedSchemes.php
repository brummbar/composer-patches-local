<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Package\RootPackageInterface;

/**
 * The URL schemes that a patch URL of a dependency can use. The import command also uses this list. The root project sets them in extra.composer-patches-local.
 */
final class AllowedSchemes
{
    private const DEFAULT = ['http', 'https'];

    /**
     * Returns the allowed schemes in lowercase.
     *
     * @return list<string>
     */
    public static function fromRoot(RootPackageInterface $root): array
    {
        $settings = $root->getExtra()[Plugin::KEY] ?? [];
        if (!is_array($settings) || !array_key_exists('allowed-schemes', $settings)) {
            return self::DEFAULT;
        }
        $schemes = $settings['allowed-schemes'];
        if (!is_array($schemes) || !array_is_list($schemes)) {
            throw self::invalid();
        }
        $result = [];
        foreach ($schemes as $scheme) {
            if (!is_string($scheme) || !preg_match('/^' . PatchPath::SCHEME_PATTERN . '$/iD', $scheme)) {
                throw self::invalid();
            }
            $result[] = strtolower($scheme);
        }

        return $result;
    }

    private static function invalid(): \RuntimeException
    {
        return new \RuntimeException(
            'composer-patches-local: "allowed-schemes" in extra.composer-patches-local of the root composer.json must be a list of URL schemes, for example ["http", "https"].'
        );
    }
}
