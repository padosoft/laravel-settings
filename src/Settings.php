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

    protected bool $flag_validate = true;
    protected bool $flag_cast = true;

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
        if ($this->validation_rules === '' || $this->validation_rules === null) {
            return $value;
        }
        //Se flag_cast = false imposta la validazione su stringa
        $validation_rules = $this->flag_cast ? $this->validation_rules : 'string';
        //Genera il tipo di valore raccogliendo dati da config e validation_rules
        $type = SettingsManager::typeOfValueFromValidationRule($validation_rules);
        //Se Validazione disattivata non valida
        if ($this->flag_validate === false) {
            SettingsManager::cast($value, $type);
        }
        $ruleString = SettingsManager::getRuleString($type, $validation_rules);
        $rule = SettingsManager::getRule($ruleString);

        //TODO: configurare bene il sistema per controllare se esiste un metodo per validare nel Validator
        //$methodType = ucfirst(SettingsManager::typeOfValueFromValidationRule($ruleString));
        //Prima di fare la validazione se la stringa non contiene una regex, non esiste un metodo Validator disponibile per la validazione e non esiste nemmeno un valore da validare
//        if (!str_contains($ruleString, 'regex:') && !method_exists(Validator::class, 'validate'.$methodType)) {
//            Log::error('Validation method does not exists for settings key: "'. $this->key. '". Miss Method "validate'.$methodType. '" or config value: "cast.'.$methodType.'.validate".');
//            return ($value);
//        }
        try {
            Validator::make(['value' => $value], ['value' => $rule])->validate();
            //Effettua un cast dinamico del valore
            return SettingsManager::cast($value, $type);
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
        return $this->disable_validation()->value;
    }
    /**
     * Restituisce il valore senza validarlo
     * @return int|mixed|string
     */
    public function getValueAsStringAttribute()
    {
        //Disabilita il cast
        //Richiede il valore che non viene validato
        return $this->disable_automaticCast()->value;
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
        return SettingsManager::typeOfValueFromValidationRule($this->validation_rules);
    }

    public function disable_validation(): Settings
    {
        $this->flag_validate = false;
        return $this;
    }
    public function enable_validation(): Settings
    {
        $this->flag_validate = true;
        return $this;
    }
    public function disable_automaticCast(): Settings
    {
        $this->flag_cast = false;
        return $this;
    }
    public function enable_automaticCast(): Settings
    {
        $this->flag_cast = true;
        return $this;
    }


}
