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


    if (!function_exists('cast')) {
        /**
         * Effettua un cast dinamico di value
         * Il tipo di cast da utilizzare viene recuperato se presente da config
         * Se non trova il valore da config cerca nei tipi di cast base più comuni
         * I cast possono essere sovrascritti da config
         * @return bool|float|\Illuminate\Support\Collection|int|mixed|object|string
         * @throws \Exception
         */
        function cast($value, $type_of_value)
        {

            $cast = config('padosoft-settings.cast.' . $type_of_value);
            //Se esiste la classe e il metodo indicati per il cast in config li utilizza
            //Altrimenti prosegue.
            $class = $cast['class'] ?? CastSettings::class;
            $method = $cast['method'] ?? 'execute';
            if ($cast !== null && class_exists($class) && method_exists($class, $method)) {
                return $class::$method($value);
            }
            switch ($type_of_value) {
                case 'boolean':
                    return CastSettings::boolean($value);
                case 'numeric':
                    return CastSettings::numeric($value);
                default:
                    //Se non trova niente effettua un cast in string
                    return CastSettings::string($value);
            }
        }
    }

    if (!function_exists('typeOfValue')) {
        function typeOfValueFromValidationRule($validation_rules)
        {
            if (str_contains($validation_rules, 'regex')) {
                return 'custom';
            }
            $validation_base = 'string';
            $typeCheck = ['boolean','integer','numeric','string'];
            if (config('padosoft-settings.cast') !== null && is_array(config('padosoft-settings.cast'))) {
                $keys = array_keys(config('padosoft-settings.cast'));
                $typeCheck = array_merge($keys, $typeCheck);
            }
            $arrayValidate = explode('|', $validation_rules);
            foreach ($typeCheck as $type) {
                if (in_array($type, $arrayValidate)) {
                    $validation_base = $type;
                    break;
                };
            }
            return $validation_base;
        }
    }
}
