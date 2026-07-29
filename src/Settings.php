<?php

namespace Padosoft\Laravel\Settings;

use Illuminate\Database\Eloquent\Model;
use Padosoft\Laravel\Settings\Encryption\EnvelopeCodec;
use Padosoft\Laravel\Settings\Encryption\SettingsCipher;
use Padosoft\Laravel\Settings\Events\SettingCreated;
use Padosoft\Laravel\Settings\Events\SettingDeleted;
use Padosoft\Laravel\Settings\Events\SettingUpdated;

class Settings extends Model
{
    public const PATTERN_EMAIL_ALIAS = '([a-z0-9\+_\-]+)*;([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_SEMICOLON = '(^[0-9;]+$)|(^.{0}$)';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_COMMA = '(^[0-9,]+$)|(^.{0}$)';
    public const PATTERN_MULTIPLE_NUMERIC_LIST_PIPE = '(^[0-9|]+$)|(^.{0}$)';

    /**
     * Segnaposto restituito da toArray()/toJson() al posto di un valore cifrato.
     */
    public const REDACTED = '[redacted]';

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
            settings()->syncFromModel($model);
            SettingCreated::dispatch($model);
        });
        static::updated(function ($model) {
            settings()->syncFromModel($model);
            SettingUpdated::dispatch($model);
        });
        static::deleted(function ($model) {
            settings()->remove($model->key);
            SettingDeleted::dispatch($model);
        });

        // Rete di sicurezza sull'ordine di assegnazione degli attributi: se
        // "value" viene valorizzato prima di "key" (mass assignment con array
        // in ordine diverso), al momento del mutator la key non e' ancora nota
        // e il valore resterebbe in chiaro. Qui la key c'e' sempre.
        // Non e' un secondo punto di cifratura: delega a SettingsCipher come
        // tutti gli altri, e salta i valori gia' incapsulati.
        static::saving(function ($model) {
            $key = $model->getAttributes()['key'] ?? null;
            if ($key === null || $key === '') {
                return;
            }
            $raw = $model->getStoredValue();
            if (EnvelopeCodec::looksCanonical($raw)) {
                return;
            }
            if (!SettingsCipher::instance()->shouldEncrypt((string) $key)) {
                return;
            }
            $model->setStoredValue(SettingsCipher::instance()->toStorage((string) $key, $raw));
        });
    }

    /**
     * Set the value attribute.
     *
     * La cifratura non e' implementata qui: viene delegata a SettingsCipher, che
     * e' l'unico punto del package autorizzato a cifrare. Il Manager usa la
     * stessa funzione, quindi non esistono due percorsi che possano produrre
     * numeri di strati diversi.
     *
     * @param string|null $value valore in chiaro
     *
     * @return void
     */
    public function setValueAttribute($value)
    {
        $key = $this->attributes['key'] ?? null;

        if ($key === null) {
            $this->attributes['value'] = $value;

            return;
        }

        $this->attributes['value'] = SettingsCipher::instance()->toStorage((string) $key, $value);
    }

    /**
     * @return string|null valore in chiaro
     */
    public function getValueAttribute($value)
    {
        $key = $this->attributes['key'] ?? null;

        if ($key === null) {
            return $value;
        }

        return SettingsCipher::instance()->fromStorage((string) $key, $value);
    }

    /**
     * Valore cosi' com'e' memorizzato, senza decifrarlo.
     *
     * E' il formato che viaggia verso la cache: DB e Redis contengono lo stesso
     * raw, il plaintext non viene mai serializzato sui canali interni.
     *
     * @return string|null
     */
    public function getStoredValue()
    {
        return $this->attributes['value'] ?? null;
    }

    /**
     * Scrive il valore gia' pronto per la persistenza, bypassando il mutator.
     *
     * Usato dal Manager, che ha gia' cifrato una volta: passare da
     * $model->value lo farebbe cifrare una seconda volta.
     *
     * @param string|null $raw
     */
    public function setStoredValue($raw): void
    {
        $this->attributes['value'] = $raw;
    }

    /**
     * Rappresentazione destinata alla cache: attributi grezzi, nessun accessor.
     *
     * @return array<string, mixed>
     */
    public function toCacheArray(): array
    {
        return $this->getAttributes();
    }

    /**
     * True se il valore di questa riga e' (o deve essere) cifrato.
     */
    public function valueIsSecret(): bool
    {
        $key = $this->attributes['key'] ?? null;
        if ($key === null) {
            return false;
        }

        if (EnvelopeCodec::looksCanonical($this->attributes['value'] ?? null)) {
            return true;
        }

        return SettingsCipher::instance()->shouldEncrypt((string) $key);
    }

    /**
     * Redige il valore nelle serializzazioni.
     *
     * toArray()/toJson() finiscono in response JSON, dump di debug, payload di
     * job e collector di terze parti: un segreto non deve attraversarli. La
     * redazione avviene senza invocare l'accessor, quindi non viene nemmeno
     * decifrato.
     *
     * @return array<string, mixed>
     */
    public function attributesToArray()
    {
        if (!array_key_exists('value', $this->attributes) || !$this->valueIsSecret()) {
            return parent::attributesToArray();
        }

        $raw = $this->attributes['value'];
        unset($this->attributes['value']);
        try {
            $array = parent::attributesToArray();
        } finally {
            $this->attributes['value'] = $raw;
        }
        $array['value'] = self::REDACTED;

        return $array;
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
            // $throw = true: senza questo flag validate() intercetta l'errore e
            // ritorna null, e questo accessor risponderebbe true su ogni valore.
            settings()->validate($this->key, $this->value, $this->validation_rules, true, true, true);
        } catch (\Throwable $e) {
            // Nessun echo: questo e' un accessor, il suo output finirebbe nel
            // body della response corrompendo JSON e HTML.
            return false;
        }

        return true;
    }
}
