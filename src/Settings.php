<?php

namespace Padosoft\Laravel\Settings;

use Illuminate\Database\Eloquent\Model;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Padosoft\Laravel\Settings\Exceptions\DecryptException as SettingsDecryptException;

class Settings extends Model
{

    use Cachable;

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
        if (is_array(config('padosoft-settings.encrypted_keys')) && array_key_exists('key',$this->attributes) && in_array($this->attributes['key'],
                config('padosoft-settings.encrypted_keys'))) {
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
        if (!is_array(config('padosoft-settings.encrypted_keys')) || !array_key_exists('key',$this->attributes)  || !in_array($this->attributes['key'],
                config('padosoft-settings.encrypted_keys'))) {
            return $value;
        }
        try {
            return Crypt::decrypt($value);
        } catch (DecryptException $e) {
            throw new SettingsDecryptException('unable to decrypt value. Maybe you have changed your app.key or padosoft-settings.encrypted_keys without updating database values');
        }
    }
}
