<?php

/**
 * Copyright (c) Padosoft.com 2018.
 */

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
    function settingsAsString($key = null, $default = null)
    {
        if (is_null($key)) {
            return app('Padosoft\Laravel\Settings\SettingsManager');
        }
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
    function settingsRaw($key = null, $default = null)
    {
        if (is_null($key)) {
            return app('Padosoft\Laravel\Settings\SettingsManager');
        }

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
        return \Schema::hasTable('settings');
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
        if (is_null($key)) {
            return app('Padosoft\Laravel\Settings\SettingsManager');
        }
        return app('Padosoft\Laravel\Settings\SettingsManager')->isValid($key);
    }
}
