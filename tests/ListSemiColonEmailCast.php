<?php

namespace Padosoft\Laravel\Settings\Test;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class ListSemiColonEmailCast implements CastsAttributes
{
    public static function execute($value)
    {
        if ($value === '' || $value === null) {
            return [];
        }
        $emailArray = explode(';', $value);
        $return = [];
        foreach ($emailArray as $key => $email) {
            $return[] = $email;
        }
        return $return;
    }

    public function get($model, string $key, $value, array $attributes)
    {
        return self::execute($value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return implode(';', $value);
    }
}
