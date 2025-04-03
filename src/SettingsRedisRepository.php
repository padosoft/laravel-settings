<?php

namespace Padosoft\Laravel\Settings;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SettingsRedisRepository
{

    public static function hdel($hashname, $key)
    {
        Redis::connection(config('padosoft-settings.default_connection'))->hdel($hashname, $key);
        if (config('padosoft-settings.local_connection') === null) {
            return;
        }
        // se ho definito la connection locale aggiorno anche quella
        try {
            Redis::connection(config('padosoft-settings.local_connection'))->hdel($hashname, $key);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }

    public static function delLocal($key)
    {
        if (config('padosoft-settings.local_connection') === null) {
            return;
        }

        try {
            Redis::connection(config('padosoft-settings.local_connection'))->del($key);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }
    public static function del($key)
    {
        Redis::connection(config('padosoft-settings.default_connection'))->del($key);

        if (config('padosoft-settings.local_connection') === null) {
            return;
        }
        // se ho definito la connection locale aggiorno anche quella
        try {
            Redis::connection(config('padosoft-settings.local_connection'))->del($key);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }

    public static function hset($hashname, $key, $value)
    {
        Redis::connection(config('padosoft-settings.default_connection'))->hset($hashname, $key, $value);
        if (config('padosoft-settings.local_connection') === null) {
            return;
        }

        // se ho definito la connection locale aggiorno anche quella
        try {
            Redis::connection(config('padosoft-settings.local_connection'))->hset($hashname, $key, $value);
            Redis::connection(config('padosoft-settings.local_connection'))->expire($hashname, config('padosoft-settings.local_expire'));
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }

    public static function hget($hashname, $key)
    {
        $localValue = null;

        try {
            // se ho definito la connection locale provo a recuperare da lì i valori
            if (config('padosoft-settings.local_connection') !== null) {
                $localValue = Redis::connection(config('padosoft-settings.local_connection'))->hget($hashname, $key);
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }

        // se ho trovato il valore locale lo ritorno
        if ($localValue !== null) {
            return $localValue;
        }
        $remoteValue = Redis::connection(config('padosoft-settings.default_connection'))->hget($hashname, $key);
        // se non ho trovato il valore o non ho definito la connection locale lo ritorno direttamente
        if (config('padosoft-settings.local_connection') === null || $remoteValue === null) {
            return $remoteValue;
        }

        // aggiorno la chiave localmente ma imposto un expire così da non rischiare di rimanere disallineato dal remoto
        try {
            Redis::connection(config('padosoft-settings.local_connection'))->hset($hashname, $key, $remoteValue);
            Redis::connection(config('padosoft-settings.local_connection'))->expire($hashname, config('padosoft-settings.local_expire'));
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }

        return $remoteValue;
    }

    public static function hgetall($hashname)
    {
        $localValue = null;

        try {
            // se ho definito la connection locale provo a recuperare da lì i valori
            if (config('padosoft-settings.local_connection') !== null) {
                $localValue = Redis::connection(config('padosoft-settings.local_connection'))->hgetall($hashname);
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
        // se ho trovato il valore locale lo ritorno
        if ($localValue !== null) {
            return $localValue;
        }
        $remoteValue = Redis::connection(config('padosoft-settings.default_connection'))->hgetall($hashname);
        // se non ho trovato il valore o non ho definito la connection locale lo ritorno direttamente
        if (config('padosoft-settings.local_connection') === null || $remoteValue === null) {
            return $remoteValue;
        }

        // aggiorno la chiave localmente ma imposto un expire così da non rischiare di rimanere disallineato dal remoto
        try {
            foreach ($remoteValue as $key => $value) {
                Redis::connection(config('padosoft-settings.local_connection'))->hset($hashname, $key, $value);
            }
            Redis::connection(config('padosoft-settings.local_connection'))->expire($hashname, config('padosoft-settings.local_expire'));
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }

        return $remoteValue;
    }
}
