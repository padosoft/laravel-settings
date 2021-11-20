<?php

namespace Padosoft\Laravel\Settings;

use Elegant\Sanitizer\Filters\Cast;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Padosoft\Laravel\CmsAdmin\Presenters\PresenterBase;
use Padosoft\Laravel\Settings\Exceptions\DecryptException as SettingsDecryptException;

class Settings extends Model
{
    use Cachable;

    public const PATTERN_EMAIL_ALIAS = '([a-z0-9\+_\-]+)*;([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_SEMICOLON = '(^[0-9;]+$)|(^.{0}$)';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_COMMA = '(^[0-9,]+$)|(^.{0}$)';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_PIPE = '(^[0-9|]+$)|(^.{0}$)';

    protected bool $validateNow = true;

    protected $dates = ['created_at', 'updated_at'];
    protected $guarded = ['created_at', 'updated_at'];

    /**
     * Set the value attribute.
     *
     * @param  string $value
     *
     * @return void
     */
    public function setValueAttribute($value)
    {
        $this->validate($value, $this->validation_rules ?? '');

        if (
            is_array(config('padosoft-settings.encrypted_keys')) && array_key_exists('key', $this->attributes) && in_array(
                $this->attributes['key'],
                config('padosoft-settings.encrypted_keys')
            )
        ) {
            $this->attributes['value'] = Crypt::encrypt($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }


    public function getValueAttribute($value)
    {
        if (
            !is_array(config('padosoft-settings.encrypted_keys')) || !array_key_exists('key', $this->attributes)  || !in_array(
                $this->attributes['key'],
                config('padosoft-settings.encrypted_keys')
            )
        ) {
            return $this->validate($value);
        }
        try {
            return Crypt::decrypt($this->validate($value));
        } catch (DecryptException $e) {
            throw new SettingsDecryptException('Unable to decrypt value. Maybe you have changed your app.key or padosoft-settings.encrypted_keys without updating database values.');
        }
    }

    /**
     * Valida il record corrente secondo le regole presenti in validation_rules
     * @return bool|int|mixed|string|string[]
     * @throws \Exception
     */
    protected function validate($value)
    {
        //Se non esiste validazione o se la validazione è disattivata restituisce il valore
        if ($this->validation_rules === '' || $this->validation_rules === null || $this->validateNow === false) {
            return $value;
        }

        $rule = $this->validation_rules;
        //recupera la validazione dal config se presente
        //il valore validate non è obbligatorio può essere un valore utilizzabile con Validate o un regex
        if (config('padosoft-settings.cast.' . $rule . '.validate') !== null) {
            $rule = config('padosoft-settings.cast.' . $rule . '.validate');
        }
        //Se regex trasforma in array altrimenti crea un array esplodendo sul carattere pipe
        if (str_contains($rule, 'regex:')) {
            $rule = array($rule);
        } else {
            $rule = explode('|', $rule);
        }
        try {
            Validator::make(['value' => $value], ['value' => $rule])->validate();
            //Effettua un cast dinamico del valore
            return $this->cast($value);
        } catch (ValidationException $e) {
            throw new \Exception($value . ' is not a valid value.' . 'line:' . $e->getLine());
        }
    }

    /**
     * Effettua un cast dinamico di value
     * Il tipo di cast da utilizzare viene recuperato se presente da config
     * Se non trova il valore da config cerca nei tipi di cast base più comuni
     * I cast possono essere sovrascritti da config
     * @return bool|float|\Illuminate\Support\Collection|int|mixed|object|string
     * @throws \Exception
     */
    protected function cast($value)
    {
        $cast = config('padosoft-settings.cast.' . $this->validation_rules);
        //Se esiste la classe e il metodo indicati per il cast in config li utilizza
        //Altrimenti prosegue.
        $class = $cast['class'] ?? CastSettings::class;
        $method = $cast['method'] ?? 'execute';
        if ($cast !== null && class_exists($class) && method_exists($class, $method)) {
            return $class::$method($value);
        }
        switch ($this->typeOfValue) {
            case 'boolean':
                return CastSettings::boolean($value);
            case 'booleanFromString':
                return CastSettings::booleanFromString($value);
            case 'booleanFromInt':
                return CastSettings::booleanFromInt($value);
            case 'numeric':
                return CastSettings::numeric($value);
            default:
                //Se non trova niente effettua un cast in string
                return CastSettings::string($value);
        }
    }



    /**
     * Restituisce il valore senza validarlo
     * @return int|mixed|string
     */
    public function getValueRawAttribute()
    {
        //Disabilita la validazione
        $this->validateNow = false;
        //Richiede il valore che non viene validato
        $value = $this->value;
        //Riabilita la validazione
        $this->validateNow = true;
        return $value;
    }

    /**
     * Restituisce true se il valore è valido, false se il valore non è valido
     * @return bool
     */
    public function getIsValidAttribute(): bool
    {
        try {
            $ck = $this->value;
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Restituisce il tipo di valore seguendo le regole impostate in Settings
     * @return int|mixed|string
     */
    public function getTypeOfValueAttribute()
    {
        if (str_contains($this->validation_rules, 'regex')) {
            return 'custom';
        }
        $validation_base = 'string';
        $typeCheck = ['boolean','integer','numeric','string'];
        if (config('padosoft-settings.cast') !== null && is_array(config('padosoft-settings.cast'))) {
            $keys = array_keys(config('padosoft-settings.cast'));
            $typeCheck = array_merge($keys, $typeCheck);
        }
        $arrayValidate = explode('|', $this->validation_rules);
        foreach ($typeCheck as $type) {
            if (in_array($type, $arrayValidate)) {
                $validation_base = $type;
                break;
            };
        }
        return $validation_base;
    }
}
