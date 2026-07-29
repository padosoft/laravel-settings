<?php

/**
 * Copyright (c) Padosoft.com 2018.
 */

namespace Padosoft\Laravel\Settings;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Padosoft\Laravel\Settings\Encryption\SettingsCipher;
use Padosoft\Laravel\Settings\Exceptions\EncryptionException;

class SettingsManager
{
    protected string $redis_key = 'laravel_pds_settings';
    protected array $settings = [];
    protected bool $flag_validate = true;
    protected bool $flag_cast = true;
    protected array $dirties = [];

    //reload settings from redis/database after this time
    protected int $memory_expires_seconds = 600;
    protected int $last_retrived_settings = 0;

    public function __construct()
    {
        $this->dirties = [];
        $connection = (new Settings)->getConnection();
        $this->redis_key .= $connection->getDatabaseName();
        //settings()->loadOnStartUp();
        //settings()->overrideConfig();
    }

    public function setMemoryExpires(int $seconds)
    {
        $this->memory_expires_seconds = $seconds;
    }

    protected function checkExpire()
    {
        // if local timeout has expired clean it
        if ($this->last_retrived_settings > 0 && ($this->last_retrived_settings + config('padosoft-settings.local_expire', 300)) <= time()) {
            SettingsRedisRepository::delLocal($this->redis_key);
        }

        if ($this->last_retrived_settings > 0 && ($this->last_retrived_settings + $this->memory_expires_seconds) > time()) {
            return;
        }

        $this->settings = [];
        $this->loadOnStartUp();
    }

    /**
     * Retrive a setting value for the given key, if not founf return $default
     *
     * @param $key
     * @param null $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null, bool $validate = false, bool $cast = true)
    {
        $this->checkExpire();
        if (array_key_exists($key, $this->settings)) {
            return $this->getMemoryValue($key, false, $cast);
        }

        $redisValue = SettingsRedisRepository::hget($this->redis_key, $key);
        if ($redisValue !== false && $redisValue !== null && $redisValue !== '') {
            $redisValue = json_decode($redisValue, true);
        }
        if (is_array($redisValue) && count($redisValue)>0) {
            $this->settings[$key] = $this->normalizeRecord($redisValue);
            return $this->getMemoryValue($key, false, $cast);
        }
        $dbValue = Settings::where('key', $key)->first();
        if ($dbValue === null) {
            return $default;
        }

        try {
            $this->validate($key, $this->cipher()->fromStorage($key, $dbValue->getStoredValue()), $dbValue->validation_rules, $validate, false, true);
        } catch (EncryptionException $exception) {
            // Fail closed. Degradare un segreto a $default farebbe partire le
            // integrazioni con una credenziale vuota, senza alcun segnale.
            throw $exception;
        } catch (\Throwable $exception) {
            return $default;
        }

        // Il raw del database e' anche il raw della cache. Fino alla 6.x qui si
        // usavano toJson()/toArray(), che applicano l'accessor: il valore
        // decifrato finiva in Redis, condiviso fra tutti i pod.
        $record = $this->normalizeRecord($dbValue->toCacheArray());
        $this->settings[$key] = $record;
        SettingsRedisRepository::hset($this->redis_key, $key, json_encode($record));

        //Restituisce il valore dalla memoria effettuando il cast e validazione
        return $this->getMemoryValue($key, false, $cast);
    }


    /**
     * Restituisce il valore senza validarlo
     * @return int|mixed|string
     */
    public function getRaw($key, $default = null)
    {
        return $this->get($key, $default, false, false);
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
            $this->get($key, 'laravelsettingsnonvalid') !== 'laravelsettingsnonvalid';
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @param $key
     * @return mixed|null
     */
    protected function getMemoryValue($key, $validate = false, $cast = true)
    {
        if (!array_key_exists($key, $this->settings)) {
            return null;
        }
        $validation_rule = $this->settings[$key]['validation_rules'] ?? null;

        // Prima si decifra, poi si valida e si casta. Fino alla 6.x l'ordine era
        // invertito: il cast veniva applicato al ciphertext e, con una regola
        // numerica, lo distruggeva prima ancora del tentativo di decifratura.
        $plain = $this->cipher()->fromStorage($key, $this->settings[$key]['value'] ?? null);

        return $this->validate($key, $plain, $validation_rule, $validate, $cast);
    }

    /**
     * Unico punto di cifratura/decifratura del package.
     */
    public function cipher(): SettingsCipher
    {
        return SettingsCipher::instance();
    }

    /**
     * Garantisce che un record in cache abbia sempre le tre chiavi attese.
     *
     * getAttributes() restituisce solo le colonne effettivamente valorizzate:
     * un setting creato con la sola coppia key/value non porterebbe con se'
     * validation_rules e config_override, e i consumatori della cache
     * andrebbero in "undefined array key".
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    protected function normalizeRecord(array $record): array
    {
        return $record + [
            'value' => null,
            'validation_rules' => null,
            'config_override' => null,
        ];
    }

    /**
     * Valore in chiaro attualmente in memoria, senza mai lanciare.
     * Serve al confronto semantico di set().
     *
     * @return string|null
     */
    protected function currentPlainValue($key)
    {
        if (!array_key_exists($key, $this->settings)) {
            return null;
        }

        $inspection = $this->cipher()->inspect($key, $this->settings[$key]['value'] ?? null);

        return $inspection['ok'] ? $inspection['value'] : null;
    }

    /**
     * Allinea la cache alla riga appena scritta sul database.
     *
     * Sostituisce la vecchia chiamata a set() dagli hook del Model: passando da
     * set() il valore, gia' cifrato dal mutator, veniva letto decifrato
     * dall'accessor e poi ri-cifrato, producendo il doppio strato.
     */
    public function syncFromModel(Settings $model): void
    {
        $key = $model->key;
        if ($key === null || $key === '') {
            return;
        }

        $record = $this->normalizeRecord($model->toCacheArray());
        $this->settings[$key] = $record;
        unset($this->dirties[$key]);

        try {
            SettingsRedisRepository::hset($this->redis_key, $key, json_encode($record));
        } catch (\Throwable $exception) {
            Log::error('[padosoft-settings] impossibile allineare la cache dopo la scrittura.', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);
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
        return $this->settings[$key]['validation_rules'];
    }

    /**
     * Remove the value the setting array in memory
     *
     * @param $key
     *
     * @return $this
     */
    public function remove($key)
    {
        if (array_key_exists($key, $this->settings)) {
            unset($this->settings[$key]);
            // Rimuovi la chiave dall'hash
            SettingsRedisRepository::hdel($this->redis_key, $key);
        }

        return $this;
    }

    /**
     * Set the value for the current session
     *
     * @param $key
     * @param $valore
     *
     * @return $this
     */
    public function set($key, $value, $validation_rule = null, $config_override = null)
    {
        if ($validation_rule === null && array_key_exists($key, $this->settings)) {
            $validation_rule = $this->settings[$key]['validation_rules'] ?? null;
        }

        // La validazione gira sul valore IN CHIARO. Fino alla 6.x girava dopo la
        // cifratura: qualunque regola diversa da string falliva sul ciphertext e
        // l'eccezione veniva inghiottita, perdendo la scrittura in silenzio.
        try {
            $this->validate($key, $value, $validation_rule, true, false, true);
        } catch (\Exception $exception) {
            return $this;
        }

        // Confronto semantico sul plaintext: l'IV e' casuale, quindi due
        // cifrature dello stesso valore non coincidono mai e un confronto sul
        // raw marcherebbe la riga come modificata a ogni singola richiesta.
        if (array_key_exists($key, $this->settings)
            && $this->currentPlainValue($key) === $value
            && ($this->settings[$key]['validation_rules'] ?? null) === $validation_rule) {
            return $this;
        }

        $raw = $this->cipher()->toStorage($key, $value);

        if (!array_key_exists($key, $this->settings)) {
            $this->dirties[$key] = $raw;
        } else {
            $this->dirties[$key] = $this->settings[$key]['value'];
        }
        $this->settings[$key]['value'] = $raw;
        $this->settings[$key]['config_override'] = $config_override;
        $this->settings[$key]['validation_rules'] = $validation_rule;
        try {
            SettingsRedisRepository::hset($this->redis_key, $key, json_encode($this->settings[$key]));
        } catch (\Throwable $exception) {
            // Nessun valore nel messaggio: questo log e' un sink di segreti.
            Log::error('[padosoft-settings] impossibile scrivere la key in cache.', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);
        }

        return $this;
    }

    /**
     * Store all settings on db
     * @return $this
     */
    public function store(): SettingsManager
    {
        foreach ($this->settings as $key => $valore) {
            if (!array_key_exists($key, $this->dirties)) {
                continue;
            }
            $model = $this->getModel($key, true);
            if ($model === null) {
                throw new \Exception("Failed to update settings key '" . $key . " on Database. This key does not exist. You must create the key before you can perform an update.");
            }

            $model->validation_rules = $valore['validation_rules'] ?? null;
            // Il valore in memoria e' gia' nel formato di persistenza: passare da
            // $model->value lo farebbe ricifrare dal mutator, producendo il
            // doppio strato che affliggeva le 6.x.
            $model->setStoredValue($valore['value']);

            if (!$model->isDirty()) {
                continue;
            }
            $model->save();
            unset($this->dirties[$key]);
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
    public function setAndStore($key, $valore, $validation_rule = null, $config_override = '')
    {
        return $this->set($key, $valore, $validation_rule, $config_override)->store();
    }

    /**
     * Load the settings Model for the given key
     *
     * @param $key
     * @param bool $disableCache
     *
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|object|\Padosoft\Laravel\Settings\Settings|null
     */
    public function getModel($key, $disableCache = false)
    {
        $query = Settings::query();

        return $query->where('key', $key)->first();
    }

    public function clearCache(): bool
    {
        try {
            SettingsRedisRepository::del($this->redis_key);
            return true;
        } catch (\Throwable $exception) {
            Log::error('Impossibile svuotare cache dei settings ' . $exception->getMessage());
        }
        return false;
    }

    protected function loadFromRedis(): bool
    {


        try {
            $redis = SettingsRedisRepository::hgetall($this->redis_key);
            if (!is_array($redis) || count($redis) < 1) {
                return false;
            }

            $this->settings = array_map(function ($value) {
                return json_decode($value, true);
            }, $redis);
            //verifico che non ci siano dati corrotti ripresi da redis (cambio di serialization/compression)
            if (count(array_filter($this->settings, function ($elem) {
                    return $elem === null;
                })) > 0) {
                $this->settings = [];
                return false;
            }
            $this->last_retrived_settings = time();
            return true;
        } catch (\Throwable $exception) {
            $this->settings = [];
            Log::error('Impossibile ricaricare i settings da redis ' . PHP_EOL . 'Exception recevied: ' . $exception->getMessage() . PHP_EOL . $exception->getTraceAsString());
        }

        return false;
    }

    /**
     * @return bool
     */
    public function loadOnStartUp()
    {
        if (!hasDbSettingsTable() || !config('padosoft-settings.enabled', false)) {
            return false;
        }
        if ($this->loadFromRedis()) {
            return true;
        }

        $settings = Settings::where('load_on_startup', '=', 1)
            ->orWhere('config_override', '<>', '')
            ->get();

        foreach ($settings as $setting) {
            $record = $this->normalizeRecord($setting->toCacheArray());

            try {
                $this->validate(
                    $setting->key,
                    $this->cipher()->fromStorage($setting->key, $record['value'] ?? null),
                    $setting->validation_rules,
                    true,
                    false,
                    true
                );
            } catch (\Throwable $exception) {
                // Il valore NON entra nel messaggio: fino alla 6.x veniva loggato
                // in chiaro, e per una key cifrata era il segreto stesso.
                // La key non viene messa in cache: la lettura successiva rifara'
                // il percorso da database e fallira' chiusa se il problema e' la
                // decifratura, invece di degradare al default in silenzio.
                Log::warning('[padosoft-settings] setting con valore non valido, escluso dal preload.', [
                    'key' => $setting->key,
                    'reason' => $exception->getMessage(),
                ]);
                continue;
            }
            $this->settings[$setting->key] = $record;
            SettingsRedisRepository::hset($this->redis_key, $setting->key, json_encode($record));
        }
        $this->last_retrived_settings = time();

        return true;
    }

    /**
     * override the config values for the settings bound to a config key
     */
    public function overrideConfig()
    {
        $this->loadOnStartUp();
        foreach ($this->settings as $chiave => $setting) {

            if (($setting['config_override'] ?? null) === null || $setting['config_override'] === '') {
                continue;
            }
            // Una key cifrata che sovrascrive la config finirebbe in chiaro
            // dentro config(), e quindi potenzialmente in bootstrap/cache/config.php
            // e in ogni dump di debug. Per default si rifiuta la combinazione
            // invece di propagare il segreto; chi ne ha davvero bisogno la abilita.
            if ($this->cipher()->shouldEncrypt($chiave)
                && !config('padosoft-settings.encryption.allow_config_override', false)) {
                Log::warning('[padosoft-settings] config_override ignorato su una key cifrata.', [
                    'key' => $chiave,
                ]);
                continue;
            }

            // inspect() invece di fromStorage(): questo metodo gira nel boot del
            // ServiceProvider, un'eccezione qui impedirebbe l'avvio dell'app.
            $inspection = $this->cipher()->inspect($chiave, $setting['value'] ?? null);
            if (!$inspection['ok']) {
                Log::warning('[padosoft-settings] config_override non applicato: valore non leggibile.', [
                    'key' => $chiave,
                    'reason' => $inspection['reason'],
                ]);
                continue;
            }

            $keys = explode('|', $setting['config_override']);
            foreach ($keys as $key) {
                config([$key => $inspection['value']]);
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
            $this->validate($key, $value, $validation_rule, true, true, true);
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
        $this->validate($key, $value, $validation_rule, true, true, true);
        $setting->value = $value ?? '';
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
                $ruleString = $this->getRuleString($type, $validate);
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
                    // Niente echo: finirebbe nel body della response HTTP.
                    Log::channel('console')->warning('[padosoft-settings] ' . $e->getMessage());
                }
            }
            Log::channel('console')->info('Set validation_rule ' . $logValidate);
        }
        Log::channel('console')->info(PHP_EOL . PHP_EOL . 'UPDATE DATABASE:');

        return ['id' => $id, 'records' => $records];
    }

    /**
     * @return int[]|string[]
     */
    public function getAllValidationRules()
    {
        //Create list options for validation
        $validation_base = 'string';
        //Base Validation Rules
        $typeCheck = ['string' => 'string', 'boolean' => 'boolean', 'numeric' => 'numeric', 'integer' => 'integer'];
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
    public function recognize(string $value, array $recognize_rules = []): bool
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
            return config('padosoft-settings.cast.' . $type . '.validate');
        }

        return $validation_rules;
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
            case 'integer':
                return CastSettings::integer($value);
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
        $typeCheck = ['boolean', 'integer', 'numeric', 'string'];
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
    public function validate($key, $value, $validation_rules = null, $validate = true, $cast = true, $throw = false)
    {
        //Se non esiste validazione o se la validazione è disattivata restituisce il valore non validato
        if ($validation_rules === '' || $validation_rules === null || ($validate === false && $cast === false)) {
            return $value;
        }
        $type = self::typeOfValueFromValidationRule($validation_rules);
        $rule = self::getMixValidationRules($validation_rules);
        //try {
        try {
            if ($validate === true) {
                Validator::make(['value' => $value ?? ''], ['value' => $rule])->validate();
            }

            //Effettua un cast dinamico del valore
            if ($cast === false) {
                return $value;
            }
            return SettingsManager::cast($value, $type);
        } catch (ValidationException $e) {
            if ($throw) {
                throw $e;
            }
            Log::error($key . ' :: ' . $e->getMessage());

            return null;
        } catch (\BadMethodCallException $ex) {

        } catch (\Exception $ex) {
            if ($throw) {
                throw $ex;
            }
            Log::error($key . ' :: ' . $ex->getMessage());
            return null;
        }
        try {
            //Effettua un cast dinamico del valore
            if ($cast === false) {
                return $value;
            }
            return SettingsManager::cast($value, $type);
        } catch (\Exception $error) {
            Log::error('Validation not exists on key ' . $key . ':' . serialize($rule));
            Log::error($error->getMessage());
        }

        //return null;
    }

    /**
     * @param $validation_rules
     * @return array|false|string[]
     */
    public function getMixValidationRules($validation_rules)
    {
        //questo è stato aggiunto perchè altrimenti nella validazione i valori vuoti o null passerebbero sempre
        if (strpos($validation_rules, 'nullable') === false && strpos($validation_rules, 'sometimes') === false && ($validation_rules ?? '') !== ''
            && strpos($validation_rules ?? '', 'regex:') === false) {
            $validation_rules = 'required|' . $validation_rules;
        }
        if (strpos($validation_rules ?? '', 'regex:') === false) {
            $validation_rules = explode('|', $validation_rules);
        } else {
            $validation_rules = [$validation_rules];
        }

        $rules = [];
        foreach ($validation_rules as $single_rule) {
            //Se flag_cast = false imposta la validazione su stringa
            //$validation_rules = $cast ? $validation_rules : 'string';
            //Genera il tipo di valore raccogliendo dati da config e validation_rules
            $type = self::typeOfValueFromValidationRule($single_rule);
            //Se Validazione disattivata non valida
            $ruleString = self::getRuleString($type, $single_rule);
            $rules = array_merge($rules, self::getRule($ruleString));
        }

        return $rules;
    }

}
