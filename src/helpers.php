<?php

/**
 * Copyright (c) Padosoft.com 2018.
 */

use Illuminate\Support\Facades\Schema;
use Padosoft\Laravel\Settings\CastSettings;

if (!function_exists('settings')) {
    /**
     * Get the specified settings value.
     *
     * If null is passed as the key, return SettingManager object.
     *
     * @param  string $key
     * @param  mixed $default
     *
     * @return mixed
     */
    function settings($key = null, $default = null, $validate = true, $cast = true)
    {
        if (is_null($key)) {
            return app('Padosoft\Laravel\Settings\SettingsManager');
        }
        return app('Padosoft\Laravel\Settings\SettingsManager')->get($key, $default, $validate, $cast);
    }
}

if (!function_exists('settingsAsString')) {
    /**
     * Get the specified settings value.
     *
     * If null is passed as the key, return SettingManager object.
     *
     * @param  string $key
     * @param  mixed $default
     *
     * @return mixed
     */
    function settingsAsString($key, $default = null): ?string
    {
        return app('Padosoft\Laravel\Settings\SettingsManager')->getAsString($key, $default);
    }
}

if (!function_exists('settingsRaw')) {
    /**
     * Get the specified settings value.
     *
     * If null is passed as the key, return SettingManager object.
     *
     * @param  string $key
     * @param  mixed $default
     *
     * @return mixed
     */
    function settingsRaw($key, $default = null)
    {
        return app('Padosoft\Laravel\Settings\SettingsManager')->getRaw($key, $default);
    }
}


if (!function_exists('hasDbSettingsTable')) {
    /**
     * Get the specified settings value.
     *
     * If null is passed as the key, return SettingManager object.
     *
     * @param  string $key
     * @param  mixed $default
     *
     * @return mixed
     */
    function hasDbSettingsTable()
    {

        try {
            return \Illuminate\Support\Facades\Cache::rememberForever('hasDbSettingsTable', function () {
                return Schema::hasTable('settings');
            });
        }catch (\Throwable $exception)
        {
            $hasSettings=Schema::hasTable('settings');
        }
        if ($hasSettings)
        {
            \Illuminate\Support\Facades\Cache::forever('hasDbSettingsTable',$hasSettings);
        }
        return $hasSettings;
    }
}


if (!function_exists('settingsIsValid')) {
    /**
     * Get the specified settings value.
     *
     * If null is passed as the key, return SettingManager object.
     *
     * @param  string $key
     * @param  mixed $default
     *
     * @return mixed
     */
    function settingsIsValid($key)
    {
        return app('Padosoft\Laravel\Settings\SettingsManager')->isValid($key);
    }
}
if (!function_exists('getMixValidationRules')) {
    /**
     * Get the validation rule.
     * @param $validation_rules
     * @return array|false|string[]
     */
    function getMixValidationRules($validation_rules)
    {
        return app('Padosoft\Laravel\Settings\SettingsManager')->getMixValidationRules($validation_rules);
    }
}

