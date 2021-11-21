<?php

namespace Padosoft\Laravel\Settings;

use Elegant\Sanitizer\Filters\Cast;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
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
        //Se non esiste validazione o se la validazione è disattivata restituisce il valore non validato
        if ($this->validation_rules === '' || $this->validation_rules === null || $this->validateNow === false) {
            return $value;
        }

        $validation_rules = $this->validation_rules;
        //Genera il tipo di valore raccogliendo dati da config e validation_rules
        $type = typeOfValueFromValidationRule($validation_rules);
        //recupera la stringa di validazione dal config solo se presente
        //il valore validate non è obbligatorio può essere un valore utilizzabile con Validate o un regex
        if (config('padosoft-settings.cast.' . $type . '.validate') !== null) {
            $ruleString = config('padosoft-settings.cast.' . $type . '.validate');
        }else{
            $ruleString = $validation_rules;
        }
        //Se la stringa di validazione contiene un regex, la trasforma in array, altrimenti crea un array esplodendo la stringa sul carattere pipe
        if (str_contains($ruleString, 'regex:')) {
            $rule = array($ruleString);
        } else {
            $rule = explode('|', $ruleString);
        }

        //TODO: configurare bene il sistema per controllare se esiste un metodo per validare nel Validator
        //$methodType = ucfirst(typeOfValueFromValidationRule($ruleString));
        //Prima di fare la validazione se la stringa non contiene una regex, non esiste un metodo Validator disponibile per la validazione e non esiste nemmeno un valore da validare
//        if (!str_contains($ruleString, 'regex:') && !method_exists(Validator::class, 'validate'.$methodType)) {
//            Log::error('Validation method does not exists for settings key: "'. $this->key. '". Miss Method "validate'.$methodType. '" or config value: "cast.'.$methodType.'.validate".');
//            return ($value);
//        }
        try {
            ray($rule);
            Validator::make(['value' => $value], ['value' => $rule])->validate();
            //Effettua un cast dinamico del valore
            return cast($value, $type);
        } catch (ValidationException $e) {
            throw new \Exception($value . ' is not a valid value.' . 'line:' . $e->getLine());
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
        return typeOfValueFromValidationRule($this->validation_rules);
    }
}
