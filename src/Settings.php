<?php

namespace Padosoft\Laravel\Settings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Padosoft\Laravel\Settings\Exceptions\DecryptException as SettingsDecryptException;

class Settings extends Model
{
    public const PATTERN_EMAIL_ALIAS = '([a-z0-9\+_\-]+)*;([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_SEMICOLON = '(^[0-9;]+$)|(^.{0}$)';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_COMMA = '(^[0-9,]+$)|(^.{0}$)';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_PIPE = '(^[0-9|]+$)|(^.{0}$)';


    protected $dates = ['created_at', 'updated_at'];
    protected $guarded = ['created_at', 'updated_at'];

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted()
    {
        static::created(function ($model) {
            settings()->set($model->key, $model->value, $model->validation_rules, $model->config_override);

        });
        static::updated(function ($model) {
            settings()->set($model->key, $model->value, $model->validation_rules, $model->config_override);
        });
        static::deleted(function ($model) {
            settings()->remove($model->key);
        });

    }

    /**
     * Set the value attribute.
     *
     * @param string $value
     *
     * @return void
     */
    public function setValueAttribute($value)
    {
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
            !is_array(config('padosoft-settings.encrypted_keys')) || !array_key_exists('key', $this->attributes) || !in_array(
                $this->attributes['key'],
                config('padosoft-settings.encrypted_keys')
            )
        ) {
            return $value;
        }
        try {
            return Crypt::decrypt($value);
        } catch (DecryptException $e) {
            throw new SettingsDecryptException('Unable to decrypt value. Maybe you have changed your app.key or padosoft-settings.encrypted_keys without updating database values.');
        }
    }

    /**
     * Restituisce il tipo di valore seguendo le regole impostate in Settings
     * @return int|mixed|string
     */
    public function getTypeOfValueAttribute()
    {
        return settings()->typeOfValueFromValidationRule($this->validation_rules);
    }

    /**
     * Restituisce true se il valore è valido, false se il valore non è valido
     * @return bool
     */
    public function getIsValidAttribute(): bool
    {
        try {
            settings()->validate($this->key, $this->value, $this->validation_rules, true, true);
        } catch (\Exception $e) {
            echo($e->getMessage());
            return false;
        }
        return true;
    }
}
