<?php

namespace App\Domains\Api\Contracts;

use App\Providers\PluginServiceProvider;
use App\Support\EditionCompatibility;

/**
 * Generic (non-EE-bound) core-extension contract version.
 *
 * Independent of {@see EditionCompatibility}, which checks a
 * `compatibility` block against a required `ee_version` range and therefore
 * can never accept a community/independent module — this contract has no
 * concept of an EE edition at all. A plugin manifest opts in by declaring
 * `"core_contract_version"`; {@see PluginServiceProvider}
 * fails that plugin closed if the declared value does not match exactly.
 * A manifest that omits the field is unaffected (backward compatible with
 * plugins written before this contract existed, e.g. `example-plugin`).
 */
final class PluginContractVersion
{
    public const CURRENT = '1.0.0';

    public static function isSupported(string $declaredVersion): bool
    {
        return $declaredVersion === self::CURRENT;
    }
}
