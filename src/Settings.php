<?php

namespace Padosoft\Laravel\Settings;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Padosoft\Laravel\Settings\Exceptions\DecryptException as SettingsDecryptException;

class Settings extends Model
{
    use Cachable;

    public const PATTERN_EMAIL_ALIAS = '([a-z0-9\+_\-]+)*;([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_SEMICOLON = '(^[0-9;]+$)|(^.{0}$)';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_COMMA = '(^[0-9,]+$)|(^.{0}$)';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_PIPE = '(^[0-9|]+$)|(^.{0}$)';

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

    /**
     * Get the value attribute.
     *
     * @param  string $value
     *
     * @return string
     */
    public function getValueAttribute($value)
    {
        if (
            !is_array(config('padosoft-settings.encrypted_keys')) || !array_key_exists('key', $this->attributes)  || !in_array(
                $this->attributes['key'],
                config('padosoft-settings.encrypted_keys')
            )
        ) {
            return $this->validate($value, $this->validation_rules);
        }
        try {
            $decriptValue = Crypt::decrypt($value);
            return $this->validate($decriptValue, $this->validation_rules);
        } catch (DecryptException $e) {
            throw new SettingsDecryptException('Unable to decrypt value. Maybe you have changed your app.key or padosoft-settings.encrypted_keys without updating database values.');
        }
    }

    protected function validate($value, $validation_rules)
    {

        if ($validation_rules === '' || $validation_rules === null) {
            return $value;
        }
        $rule = $validation_rules;
        if (str_contains($validation_rules,'regex:')){
            $rule=array($validation_rules);
        }
        try {
            Validator::make(['value' => $value], ['value' => $rule])->validate();
            return $value;
        } catch (ValidationException $e) {
            throw new \Exception($value . ' is not a valid value.' . 'line:' . $e->getLine());
        }
    }

    /**
     * Restituisce il tipo di valore seguendo le regole impostate in Settings
     * @return int|mixed|string
     */
    public function getTypeOfValueAttribute()
    {
        if (!str_contains($this->validation_rules, 'regex')) {
            return $this->validation_rules;
        }
        switch ($this->validation_rules) {
            case 'regex:/' . self::PATTERN_EMAIL_ALIAS . '/':
                return 'emailAlias';
                break;
            case 'regex:/' . self::PATTERN_MULTIPLE_NUMERIC_LIST_SEMICOLON . '/':
                return 'listSemicolon';
                break;
            case 'regex:/' . self::PATTERN_MULTIPLE_NUMERIC_LIST_PIPE . '/':
                return 'listPipe';
                break;
            case 'regex:/' . self::PATTERN_MULTIPLE_NUMERIC_LIST_COMMA . '/':
                return 'listComma';
                break;
            default:
                return 'customRegex';
        }
    }
}
