<?php

namespace Padosoft\Laravel\Settings;

use Elegant\Sanitizer\Filters\Cast;

class CastSettings
{
    public static function boolean($value)
    {
        $cast = new Cast();
        return $cast->apply($value, ['boolean']);
    }

    public static function string($value)
    {
        return $value.'';
    }

    public static function integer($value)
    {
        return (int)$value;
    }

    public static function numeric($value)
    {
        return (float)$value;
    }
}
