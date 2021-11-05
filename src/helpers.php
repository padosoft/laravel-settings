<?php
/**
 * Copyright (c) Padosoft.com 2018.
 */


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
    function settings($key = null, $default = null)
    {
        if (is_null($key)) {
            return app('Padosoft\Laravel\Settings\SettingsManager');
        }

        return app('Padosoft\Laravel\Settings\SettingsManager')->get($key, $default);
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
