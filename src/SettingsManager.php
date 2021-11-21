<?php

/**
 * Copyright (c) Padosoft.com 2018.
 */

namespace Padosoft\Laravel\Settings;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Padosoft\Laravel\Settings\Exceptions\DecryptException as SettingsDecryptException;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;

class SettingsManager
{
    protected $settings = [];

    public function __construct()
    {
        //settings()->loadOnStartUp();
        //settings()->overrideConfig();
    }

    /**
     * Retrive a setting value for the given key, if not founf return $default
     *
     * @param $key
     * @param null $default
     *
     * @return mixed
     */
    public function get($key, $default = null)
    {
        if (array_key_exists($key, settings()->settings)) {
            return settings()->getMemoryValue($key);
        }
        $appo = settings()->getModel($key);
        if (!is_null($appo)) {
            settings()->set($key, $appo->value, is_null($appo->validation_rules) ? null : $appo->validation_rules);
        } else {
            settings()->set($key, $default, '');
        }

        return settings()->getMemoryValue($key);
    }

    /**
     * @param $key
     * @return mixed|null
     */
    protected function getMemoryValue($key)
    {
        if (!array_key_exists($key, settings()->settings)) {
            return null;
        }

        if (
            !is_array(config('padosoft-settings.encrypted_keys')) || !in_array(
                $key,
                config('padosoft-settings.encrypted_keys')
            )
        ) {
            return settings()->settings[$key]['value'];
            //Ritorn valore con cast automatico
            //return cast(settings()->settings[$key]['value'], settings()->settings[$key]['validation_rule'], typeOfValueFromValidationRule(settings()->settings[$key]['validation_rule']));

        }
        try {
            return Crypt::decrypt(settings()->settings[$key]['value']);
        } catch (DecryptException $e) {
            throw new SettingsDecryptException('unable to decrypt value.Maybe you have changed your app.key or padosoft-settings.encrypted_keys without updating database values');
        }
    }

    /**
     * @param $key
     * @return mixed|null
     */
    protected function getMemoryValidationRule($key)
    {
        if (!array_key_exists($key, settings()->settings)) {
            return null;
        }
        return settings()->settings[$key]['validation_rule'];
    }

    /**
     * Set the value for the current session
     *
     * @param $key
     * @param $valore
     *
     * @return $this
     */
    public function set($key, $value, $validation_rule = null)
    {
        //Tolta validazione perchè è validato a basso livello sul model
        //settings()->validate($value, $validation_rule);
        if (
            is_array(config('padosoft-settings.encrypted_keys')) && in_array(
                $key,
                config('padosoft-settings.encrypted_keys')
            )
        ) {
            $value = Crypt::encrypt($value);
        }
        settings()->settings[$key]['value'] = $value;
        settings()->settings[$key]['validation_rule'] = $validation_rule;
        return $this;
    }

    /**
     * Store all settings on db
     * @return $this
     */
    public function store(): SettingsManager
    {
        foreach (settings()->settings as $key => $valore) {
            $model = settings()->getModel($key, true);
            if ($model === null) {
                throw new \Exception("Failed to update settings key '" . $key . " on Database. This key does not exist. You must create the key before you can perform an update.");
            }
            if (settings()->getMemoryValidationRule($key) !== null) {
                $model->validation_rules = settings()->getMemoryValidationRule($key);
            }
            $model->value = settings()->getMemoryValue($key);
            $model->save();
        }

        return $this;
    }

    /**
     * Set the value and store all settings on db
     *
     * @param $key
     * @param $valore
     * @param string|null $validation_rule
     * @return $this
     * @throws \Exception
     */
    public function setAndStore($key, $valore, $validation_rule = null)
    {
        return settings()->set($key, $valore, $validation_rule)->store();
    }

    /**
     * Load the settings Model for the given key
     *
     * @param $key
     * @param bool $disableCache
     *
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|object|Padosoft\Laravel\Settings\Settings|null
     */
    public function getModel($key, $disableCache = false)
    {
        $query = Settings::query();
        if ($disableCache) {
            $query->disableCache();
        }

        return $query->where('key', $key)->first();
    }

    public function loadOnStartUp()
    {
        if (!hasDbSettingsTable() || !config('padosoft-settings.enabled', false)) {
            return false;
        }

        $settings = Settings::where('load_on_startup', '=', 1)
                            ->get();
        foreach ($settings as $setting) {
            $key = $setting->key;
            $value = $setting->value;
            $validation_rule = is_null($setting->validation_rules) ? null : $setting->validation_rules;
            settings()->set($key, $value, $validation_rule);
        }

        return true;
    }

    /**
     * override the config values for the settings bound to a config key
     */
    public function overrideConfig()
    {
        if (!hasDbSettingsTable() || !config('padosoft-settings.enabled', false)) {
            return false;
        }
        $settings = Settings::select('value', 'key', 'config_override')
                            ->where('config_override', '<>', '')
                            ->get();
        foreach ($settings as $setting) {
            $keys = explode('|', $setting->config_override);
            $value = $setting->value;
            foreach ($keys as $key) {
                if (\is_bool(config($key))) {
                    $value = (bool)$value;
                }

                config([$key => $value]);
            }
        }

        return true;
    }

    /**
     * @param $key
     * @param $description
     * @param $value
     * @param null $validation_rule
     * @param string $config_override
     * @param int $load_on_startup
     */
    public function UpdateOrCreate($key, $description, $value, $validation_rule = null, $config_override = '', $load_on_startup = 0)
    {
        //Controlla se Esiste la chiave
        //Se non esiste
        $setting = Settings::where('key', $key)->first();
        if ($setting === null) {
            //Valida il valore
            settings()->validate($value, $validation_rule);
            //Crea e esce
            Settings::create([
                                 'key' => $key,
                                 'value' => $value,
                                 'descr' => $description,
                                 'validation_rules' => $validation_rule,
                                 'config_override' => $config_override,
                                 'load_on_startup' => $load_on_startup
                             ]);
            return;
        }
        //Se esiste la chiave
        //Se validationa_rule è a null valida con la vecchia validation_rule
        if ($validation_rule === null) {
            $validation_rule = is_null($setting->validation_rules) ? null : $setting->validation_rules;
        }
        settings()->validate($value, $validation_rule);
        $setting->value = $value;
        $setting->descr = $description;
        $setting->validation_rules = $validation_rule;
        $setting->config_override = $config_override;
        $setting->load_on_startup = $load_on_startup;
        $setting->save();
    }

    /**
     * @param $key
     * @param $description
     * @param $value
     * @param string $config_override
     * @param int $load_on_startup
     */
    public function UpdateOrCreate_URL($key, $description, $value, $config_override = '', $load_on_startup = 0)
    {
        settings()->UpdateOrCreate($key, $description, $value, 'url', $config_override, $load_on_startup);
    }

    /**
     * @param $key
     * @param $description
     * @param $value
     * @param string $config_override
     * @param int $load_on_startup
     */
    public function UpdateOrCreate_Email($key, $description, $value, $config_override = '', $load_on_startup = 0)
    {
        settings()->UpdateOrCreate($key, $description, $value, 'email', $config_override, $load_on_startup);
    }


    //Controlli

    /**
     * @param $val
     * @param $regEx
     * @return bool
     */
    public function checkVal($val, $regEx): bool
    {
        return preg_match("/" . $regEx . "/i", $val);
    }

    /**
     * @param $val
     * @return bool
     */
    public function isNumberInteger($val)
    {
        return filter_var($val, FILTER_VALIDATE_INT) || $val === '0';
    }

    /**
     * @param $val
     * @return mixed
     */
    public function isNumber($val)
    {
        return filter_var($val, FILTER_VALIDATE_FLOAT);
    }

    /**
     * @param $val
     * @return mixed
     */
    public function isEmail($val)
    {
        return filter_var($val, FILTER_VALIDATE_EMAIL);
    }

    /**
     * @param $val
     * @return bool
     */
    public function isEmailAndAlias($val): bool
    {
        $pattern = Settings::PATTERN_EMAIL_ALIAS;
        return self::checkVal($val, $pattern);
    }

    /**
     * @param $val
     * @return mixed
     */
    public function isURL($val)
    {
        return filter_var($val, FILTER_VALIDATE_URL);
    }

    /**
     * @param $val
     * @return bool
     */
    public function isListSeparatedByPipe($val): bool
    {
        $pattern = Settings::PATTERN_MULTIPLE_NUMERIC_LIST_PIPE;
        return settings()->checkVal($val, $pattern);
    }

    /**
     * @param $val
     * @return bool
     */
    public function isListSeparatedBySemicolon($val): bool
    {
        $pattern = Settings::PATTERN_MULTIPLE_NUMERIC_LIST_SEMICOLON;
        return settings()->checkVal($val, $pattern);
    }

    /**
     * @param $val
     * @return bool
     */
    public function isListSeparatedByComma($val): bool
    {
        $pattern = Settings::PATTERN_MULTIPLE_NUMERIC_LIST_COMMA;
        return settings()->checkVal($val, $pattern);
    }

    /**
     * @param $value
     * @param null $validation_rules
     * @return mixed
     * @throws \Exception
     */
    protected function validate($value, $validation_rules = null)
    {
        if ($validation_rules === '' || $validation_rules === null) {
            return $value;
        }
        try {
            Validator::make(['value' => $value], ['value' => $validation_rules])->validate();
            return $value;
        } catch (ValidationException $e) {
            throw new \Exception('Value: ' . $value . ' is not valid.');
        }
    }
    public function recalculateOldValidationRules(){
        $records = Settings::get();
        $id = [];
        //Crea la lista di possibili opzioni da validare
        $validation_base = 'string';
        //Opzioni base
        $typeCheck = ['boolean','integer','numeric','string'];
        //Opzioni recuperate dal file config
        if (config('padosoft-settings.cast') !== null && is_array(config('padosoft-settings.cast'))) {
            $keys = array_keys(config('padosoft-settings.cast'));
            //Unione di tutte le opzioni
            $typeCheck = array_merge($keys, $typeCheck);
        }
        foreach ($records as $record) {
            echo($record->key.PHP_EOL);
            foreach ($typeCheck as $validate){
                $ruleString = getRuleString($validate,$validation_base);
                $rule = getRule($ruleString);
                try {
                    Validator::make(['value' => $record->valueAsString], ['value' => $rule])->validate();
                    echo('Rule:'. implode(' | ', $rule). ' - ' . $validate.' - '.$record->valueAsString.PHP_EOL);
                    $id[$validate][]=$record->id;
                } catch (ValidationException $e) {
                    continue;
                }
            }
        }
        foreach($id as $validation_rules => $list){
            Settings::whereIn('id', $id[$validation_rules]??[])->update(['validation_rules'=>$validation_rules]);
        }
    }
}
