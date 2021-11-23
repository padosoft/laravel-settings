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
    protected bool $flag_validate = true;
    protected bool $flag_cast = true;

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
    public function get($key, $default = null, $validate = true, $cast = true)
    {
        if (array_key_exists($key, settings()->settings)) {
            return settings()->getMemoryValue($key);
        }
        $appo = settings()->getModel($key);
        if (!is_null($appo)) {
            //Quando salva in cache fa la validazione ma non il cast
            settings()->set($key, $appo->value, is_null($appo->validation_rules) ? null : $appo->validation_rules);
        } else {
            //Il valore di default non fa ne validazione ne cast
            settings()->set($key, $default, '');
        }
        //Restituisce il valore dalla memoria effettuando il cast e validazione
        return settings()->getMemoryValue($key, $validate, $cast);
    }


    /**
     * Restituisce il valore senza validarlo
     * @return int|mixed|string
     */
    public function getRaw($key, $default = null)
    {
        return  $this->get($key, $default, false, false);
    }
    /**
     * Restituisce il valore come stringa
     * @return string
     */
    public function getAsString($key, $default = null): string
    {
        return $this->get($key, $default, true, false);
    }

    /**
     * @param $key
     * @return bool
     */
    public function isValid($key){
        try {
            $this->get($key);
            return true;
        }catch (\Exception $e){}
        Log::error($key. ' key is not valid.');
        return false;
    }

    /**
     * @param $key
     * @return mixed|null
     */
    protected function getMemoryValue($key, $validate = true, $cast = true)
    {
        if (!array_key_exists($key, settings()->settings)) {
            return null;
        }
        $validation_rule = settings()->settings[$key]['validation_rule'];
        $value = settings()->validate(settings()->settings[$key]['value'], $validation_rule, $validate, $cast);
        if (
            !is_array(config('padosoft-settings.encrypted_keys')) || !in_array(
                $key,
                config('padosoft-settings.encrypted_keys')
            )
        ) {
            return $value;
        }
        try {
            return Crypt::decrypt($value);
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
            $model->value = settings()->getMemoryValue($key, true, false);
            $model->save();
        }

        return $this;
    }

    /**
     * Set the value and store all settings on db
     * @param $key
     * @param $valore
     * @param null $validation_rule
     * @return mixed
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

    /**
     * @return bool
     */
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
     *
     */
    public function recalculateOldValidationRules()
    {
        $records = Settings::orderBy('id', 'ASC')->get();
        $id = [];
        //Crea la lista di possibili opzioni da validare
        $validation_base = 'string';
        //Opzioni base
        $typeCheck = ['string','boolean','numeric','integer'];
        //Opzioni recuperate dal file config
        if (config('padosoft-settings.cast') !== null && is_array(config('padosoft-settings.cast'))) {
            $keys = array_keys(config('padosoft-settings.cast'));
            //Unione di tutte le opzioni
            $typeCheck = array_merge($typeCheck, $keys);
        }
        $typeCheck = array_reverse($typeCheck);
        foreach ($records as $record) {
            echo($record->key.PHP_EOL);
            foreach ($typeCheck as $validate) {
                $type = $this->typeOfValueFromValidationRule($validate);
                $ruleString = $this->getRuleString($validate, $type);
                $rule = $this->getRule($ruleString);
                try {
                    Validator::make(['value' => $record->valueAsString], ['value' => $rule])->validate();
                    echo('id.'.$record->id.'Rule:'. implode(' | ', $rule). ' - ' . $validate.' - '.$record->valueAsString.PHP_EOL);
                    $id[$validate][]=$record->id;
                    break;
                } catch (ValidationException $e) {
                    echo('##### NO '. $type .' #####'.'    Rule:'. implode(' | ', $rule).PHP_EOL);
                }
            }
        }
        foreach ($id as $validation_rules => $list) {
            Settings::whereIn('id', $id[$validation_rules]??[])->update(['validation_rules'=>$validation_rules]);
        }
    }


    /**
     * @param $ruleString
     * @return array|false|string[]
     */
    public static function getRule($ruleString)
    {
        //Se la stringa di validazione contiene un regex, la trasforma in array, altrimenti crea un array esplodendo la stringa sul carattere pipe
        if (str_contains($ruleString, 'regex:')) {
            $rule = array($ruleString);
        } else {
            $rule = explode('|', $ruleString);
        }
        return $rule;
    }

    /**
     * @param $type
     * @param $validation_rules
     * @return \Illuminate\Config\Repository|\Illuminate\Contracts\Config\Repository|\Illuminate\Contracts\Foundation\Application|mixed
     */
    public static function getRuleString($type, $validation_rules)
    {
        //TODO: Aggiungere il supporto per un validate in config in forma Array
        //recupera la stringa di validazione dal config solo se presente
        //il valore validate non è obbligatorio può essere un valore utilizzabile con Validate o un regex
        if (config('padosoft-settings.cast.' . $type . '.validate') !== null) {
            $ruleString = config('padosoft-settings.cast.' . $type . '.validate');
        } else {
            $ruleString = $validation_rules;
        }
        return $ruleString;
    }


    /**
     * Effettua un cast dinamico di value
     * Il tipo di cast da utilizzare viene recuperato se presente da config
     * Se non trova il valore da config cerca nei tipi di cast base più comuni
     * I cast possono essere sovrascritti da config
     * @return bool|float|\Illuminate\Support\Collection|int|mixed|object|string
     * @throws \Exception
     */
    public static function cast($value, $type_of_value)
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

    /**
     * @param $validation_rules
     * @return string
     */
    public static function typeOfValueFromValidationRule($validation_rules)
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

    /**
     * Valida il record corrente secondo le regole presenti in validation_rules
     * @return bool|int|mixed|string|string[]
     * @throws \Exception
     */
    public function validate($value, $validation_rules = null, $validate = true, $cast = true)
    {
        //Se non esiste validazione o se la validazione è disattivata restituisce il valore non validato
        if ($validation_rules === '' || $validation_rules === null) {
            return $value;
        }
        //Se flag_cast = false imposta la validazione su stringa
        $validation_rules = $cast ? $validation_rules : 'string';
        //Genera il tipo di valore raccogliendo dati da config e validation_rules
        $type = self::typeOfValueFromValidationRule($validation_rules);
        //Se Validazione disattivata non valida
        if ($cast === false) {
            self::cast($value, $type);
        }
        $ruleString =  self::getRuleString($type, $validation_rules);
        $rule =  self::getRule($ruleString);

        try {
            if ($validate===true){
                Validator::make(['value' => $value], ['value' => $rule])->validate();
            }
            //Effettua un cast dinamico del valore
            return SettingsManager::cast($value, $type);
        } catch (ValidationException $e) {
            Log::error('Error on key: '. $this->key.'. ' .$e->getMessage());
            return null;
        }
    }


}
