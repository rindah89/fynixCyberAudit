<?php

namespace App\Support;

class Enterprise
{
    public static function enabled(string $module): bool
    {
        return (bool) config("enterprise.modules.{$module}", false);
    }

    public static function assertEnabled(string $module): void
    {
        if (! self::enabled($module)) {
            abort(404, 'This module is not enabled.');
        }
    }
}
