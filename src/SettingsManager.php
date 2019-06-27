<?php
/**
 * Copyright (c) Padosoft.com 2018.
 */

namespace Padosoft\Laravel\Settings;

use Padosoft\Laravel\Settings\Settings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Padosoft\Laravel\Settings\Exceptions\DecryptException as SettingsDecryptException;

class SettingsManager
{

    protected $settings = [];

    public function __construct()
    {
        $this->loadOnStartUp();
        $this->overrideConfig();
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
        if (array_key_exists($key, $this->settings)) {
            return $this->getMemoryValue($key);
        }
        $appo = $this->getModel($key);
        $this->set($key, $default);
        if (!is_null($appo)) {
            $this->set($key, $appo->value);
        }

        return $this->getMemoryValue($key);
    }

    protected function getMemoryValue($key)
    {
        if (!is_array(config('padosoft-settings.encrypted_keys')) || !in_array($key,
                config('padosoft-settings.encrypted_keys'))) {
            return $this->settings[$key];
        }
        try {
            return Crypt::decrypt($this->settings[$key]);
        } catch (DecryptException $e) {
            throw new SettingsDecryptException('unable to decrypt value.Maybe you have changed your app.key or padosoft-settings.encrypted_keys without updating database values');
        }
    }

    /**
     * Set the value for the current session
     *
     * @param $key
     * @param $valore
     *
     * @return $this
     */
    public function set($key, $valore)
    {
        if (is_array(config('padosoft-settings.encrypted_keys')) && in_array($key,
                config('padosoft-settings.encrypted_keys'))) {
            $valore = Crypt::encrypt($valore);
        }
        $this->settings[$key] = $valore;

        return $this;
    }

    /**
     * Store all settings on db
     * @return $this
     */
    public function store()
    {
        foreach ($this->settings as $key => $valore) {
            $model = $this->getModel($key, true);
            $model->value = $this->getMemoryValue($key);
            $model->save();
        }

        return $this;
    }

    /**
     * Set the value and store all settings on db
     *
     * @param $key
     * @param $valore
     *
     * @return $this
     */
    public function setAndStore($key, $valore)
    {
        return $this->set($key, $valore)->store();
    }

    /**
     * Load the settings Model for the given key
     *
     * @param $key
     * @param bool $disableCache
     *
     * @return Padosoft\Laravel\Settings\Settings|null
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
        if (!config('padosoft-settings.enabled', false)) {
            return;
        }
        $settings = Settings::select('value', 'key')
                            ->where('load_on_startup', '=', 1)
                            ->get();
        foreach ($settings as $setting) {
            $key = $setting->key;
            $value = $setting->value;
            $this->set($key, $value);
        }
    }

    /**
     * override the config values for the settings bound to a config key
     */
    public function overrideConfig()
    {
        if (!config('padosoft-settings.enabled', false)) {
            return;
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
    }
}
