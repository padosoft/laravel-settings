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
    protected $flag_validate = true;
    protected $flag_cast = true;

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
        if (array_key_exists($key, $this->settings)) {
            return $this->getMemoryValue($key);
        }
        $appo = $this->getModel($key);
        if (!is_null($appo)) {
            //Quando salva in cache fa la validazione ma non il cast
            $this->set($key, $appo->value, is_null($appo->validation_rules) ? null : $appo->validation_rules);
        } else {
            //Il valore di default non fa ne validazione ne cast
            $this->set($key, $default, '');
        }
        //Restituisce il valore dalla memoria effettuando il cast e validazione
        return $this->getMemoryValue($key, $validate, $cast);
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
    public function getAsString($key, $default = null): ?string
    {
        return $this->get($key, $default, true, false);
    }

    /**
     * @param $key
     * @return bool
     */
    public function isValid($key)
    {
        try {
            $this->get($key);
            return true;
        } catch (\Exception $e) {
        }
        Log::error($key . ' key is not valid.');
        return false;
    }

    /**
     * @param $key
     * @return mixed|null
     */
    protected function getMemoryValue($key, $validate = true, $cast = true)
    {
        if (!array_key_exists($key, $this->settings)) {
            return null;
        }
        $validation_rule = $this->settings[$key]['validation_rule'];
        $value = $this->validate($key, $this->settings[$key]['value'], $validation_rule, $validate, $cast);
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
        if (!array_key_exists($key, $this->settings)) {
            return null;
        }
        return $this->settings[$key]['validation_rule'];
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
        //$this->validate($value, $validation_rule);
        if (
            is_array(config('padosoft-settings.encrypted_keys')) && in_array(
                $key,
                config('padosoft-settings.encrypted_keys')
            )
        ) {
            $value = Crypt::encrypt($value);
        }
        $this->settings[$key]['value'] = $value;
        $this->settings[$key]['validation_rule'] = $validation_rule;
        return $this;
    }

    /**
     * Store all settings on db
     * @return $this
     */
    public function store(): SettingsManager
    {
        foreach ($this->settings as $key => $valore) {
            $model = $this->getModel($key, true);
            if ($model === null) {
                throw new \Exception("Failed to update settings key '" . $key . " on Database. This key does not exist. You must create the key before you can perform an update.");
            }
            if ($this->getMemoryValidationRule($key) !== null) {
                $model->validation_rules = $this->getMemoryValidationRule($key);
            }
            $model->value = $this->getMemoryValue($key, true, false);
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
        return $this->set($key, $valore, $validation_rule)->store();
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
            $this->set($key, $value, $validation_rule);
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
        $settings = Settings::where('config_override', '<>', '')->get();
        foreach ($settings as $setting) {
            $keys = explode('|', $setting->config_override);
            $value = $setting->value;
            $validation_rules = $setting->validation_rules;
            foreach ($keys as $key) {
                if (\is_bool(config($key))) {
                    $value = (bool)$value;
                    $validation_rules = 'boolean';
                }
                config([$key => $this->validate($key, $value, $validation_rules, true, false)]);
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
            $this->validate($key, $value, $validation_rule);
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
        $this->validate($key, $value, $validation_rule);
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
        $this->UpdateOrCreate($key, $description, $value, 'url', $config_override, $load_on_startup);
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
        $this->UpdateOrCreate($key, $description, $value, 'email', $config_override, $load_on_startup);
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
        return $this->checkVal($val, $pattern);
    }

    /**
     * @param $val
     * @return bool
     */
    public function isListSeparatedBySemicolon($val): bool
    {
        $pattern = Settings::PATTERN_MULTIPLE_NUMERIC_LIST_SEMICOLON;
        return $this->checkVal($val, $pattern);
    }


    /**
     * @param $val
     * @return bool
     */
    public function isListSeparatedByComma($val): bool
    {
        $pattern = Settings::PATTERN_MULTIPLE_NUMERIC_LIST_COMMA;
        return $this->checkVal($val, $pattern);
    }

    public function recalculateValidationRules(bool $rebase = false, bool $fix = false, string $key = ''): void
    {
        $data = $this->getRecalculateOldValidationRulesData($rebase, $fix, $key);
        $this->updateValidationRulesFromData($data ['id']);
    }


    public function rebaseValidationRules(string $key = ''): void
    {
        $data = $this->getRecalculateOldValidationRulesData(true, false, $key);
        $this->updateValidationRulesFromData($data ['id']);
    }

    public function fixValidationRules(string $key = ''): void
    {
        $data = $this->getRecalculateOldValidationRulesData(false, true, $key);
        $this->updateValidationRulesFromData($data ['id']);
    }

    public function setValidationRules(string $key = ''): void
    {
        $data = $this->getRecalculateOldValidationRulesData(false, false, $key);
        $this->updateValidationRulesFromData($data ['id']);
    }


    /**
     *
     */
    public function getRecalculateOldValidationRulesData(bool $rebase = false, bool $fix = false, string $key = ''): array
    {
        $id = [];
        if ($key !== '') {
            $records = Settings::where('key', $key)->orderBy('id', 'ASC')->get();
        } else {
            $records = Settings::orderBy('id', 'ASC')->get();
        }
        $id = [];
        $typeCheck = $this->getAllValidationRules();
        //$typeCheck = array_reverse($typeCheck);
        foreach ($records as $record) {
            Log::channel('console')->alert(PHP_EOL . $record->key);
            //If value is empty continue;
            if ($record->value === '') {
                Log::channel('console')->info('Value is empty.');
                continue;
            }
            //If exist validation_rules end rabase is false and fix is false then continue
            if ($record->validation_rules !== '' && $rebase === false && $fix === false) {
                Log::channel('console')->info('Keys has a ValidationRules.');
                continue;
            }
            //If fix is true and Validation is Ok then continue
            if ($rebase === false && $fix === true && $this->validate($record->key, $record->value, $record->validation_rules) !== null) {
                Log::channel('console')->info($record->key . ' has a Valid value.');
                continue;
            }
            Log::channel('console')->alert('Search a valid ValidationRule...');
            $logValidate = '';
            foreach ($typeCheck as $validate => $rules) {
                $type = $this->typeOfValueFromValidationRule($validate);
                $ruleString = $this->getRuleString($validate, $type);
                $rule = $this->getRule($ruleString);
                try {
                    try {
                        //Recognize string with recognize rules
                        if ($this->recognize($record->value, $rules['recognize'] ?? []) === false) {
                            continue;
                        }
                        //Validation
                        Validator::make(['value' => $record->value], ['value' => $rule])->validate();
                        //Recognize
                        $id[$validate][] = $record->id;
                        $logValidate = $validate;
                    } catch (ValidationException $e) {
                        //Log::channel('console')->info($type . '    Rule:' . implode(' | ', $rule));
                    }
                } catch (\Exception $e) {
                    echo($e->getMessage());
                }
            }
            Log::channel('console')->info('Set validation_rule ' . $logValidate);
        }
        Log::channel('console')->info(PHP_EOL . PHP_EOL . 'UPDATE DATABASE:');

        return ['id' => $id,'records' => $records];
    }

    /**
     * @return int[]|string[]
     */
    public function getAllValidationRules()
    {
        //Create list options for validation
        $validation_base = 'string';
        //Base Validation Rules
        $typeCheck = ['string' => 'string','boolean' => 'boolean','numeric' => 'numeric','integer' => 'integer'];
        //Build Validations Rules from config file
        if (config('padosoft-settings.cast') !== null && is_array(config('padosoft-settings.cast'))) {
            $extra = config('padosoft-settings.cast');
            //Unione di tutte le opzioni
            $typeCheck = array_merge($typeCheck, $extra);
        }
        return $typeCheck;
    }

    /**
     * @param string $value
     * @param array $recognize_rules
     * @return bool
     */
    public function recognize(string $value, array $recognize_rules = []):bool
    {
        foreach ($recognize_rules as $ruleData) {
            $ruleDataArray = explode(':', $ruleData, 2);
            $rule = $ruleDataArray[0];
            $data = $ruleDataArray[1] ?? '';
            switch ($rule) {
                case 'contains':
                    if (str_contains($value, $data) === false) {
                        return false;
                    }
                    break;
                case 'noContains':
                    if (str_contains($value, $data) === true) {
                        return false;
                    }
                    break;
            }
        }
        return true;
    }



    /**
     * @param array $id
     * @return void
     */
    public function updateValidationRulesFromData(array $id)
    {
        foreach ($id as $validation_rules => $list) {
            Log::channel('console')->info('Update Database with new Validation Rules: ' . $validation_rules);
            try {
                Settings::whereIn('id', $id[$validation_rules] ?? [])->update(['validation_rules' => $validation_rules]);
            } catch (\Exception $error) {
                Log::error('Error on update settings Validation_rules on validation: ' . $validation_rules);
            }
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
    public function validate($key, $value, $validation_rules = null, $validate = true, $cast = true)
    {
        //Se non esiste validazione o se la validazione è disattivata restituisce il valore non validato
        if ($validation_rules === '' || $validation_rules === null) {
            return $value;
        }
        $type = self::typeOfValueFromValidationRule($validation_rules);
        $rule = self::getMixValidationRules($validation_rules);
        try {
            try {
                if ($validate === true) {
                    Validator::make(['value' => $value], ['value' => $rule])->validate();
                }
                //Effettua un cast dinamico del valore
                if ($cast === false) {
                    return $value;
                }
                return SettingsManager::cast($value, $type);
            } catch (ValidationException $e) {
                Log::error($key . ' :: ' . $e->getMessage());
                return null;
            }
        } catch (\Exception $error) {
            Log::error('Validation not exists on key ' . $key . ':' . serialize($rule));
            Log::error($error->getMessage());
        }
    }

    /**
     * @param $validation_rules
     * @return array|false|string[]
     */
    public function getMixValidationRules($validation_rules)
    {
        //Se flag_cast = false imposta la validazione su stringa
        //$validation_rules = $cast ? $validation_rules : 'string';
        //Genera il tipo di valore raccogliendo dati da config e validation_rules
        $type = self::typeOfValueFromValidationRule($validation_rules);
        //Se Validazione disattivata non valida
        $ruleString =  self::getRuleString($type, $validation_rules);
        $rule =  self::getRule($ruleString);
        return $rule;
    }
}
