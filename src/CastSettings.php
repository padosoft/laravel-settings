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

    public static function booleanFromString($value)
    {
        switch ($value) {
            case 'true':
                return true;
            case 'false':
                return false;
            default:
                throw new \Exception('Cast Boolean from String is not possible. Value is not correct.');
        }
    }

    public static function booleanFromInt($value)
    {
        switch ($value) {
            case '1':
                return 1;
            case '0':
                return 0;
            default:
                throw new \Exception('Cast Boolean from String is not possible. Value is not correct.');
        }
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
        return (int)$value;
    }
}
