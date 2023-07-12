<?php

namespace Padosoft\Laravel\Settings\Test;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class ValidatorServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     * @noinspection ReturnTypeCanBeDeclaredInspection
     */
    public function register()
    {
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     * @noinspection ReturnTypeCanBeDeclaredInspection
     */
    public function boot()
    {
        $this->registerValidators();
    }
    /** @noinspection PhpUndefinedFieldInspection */
    public function registerValidators(): void
    {
        Validator::extend(
            'isEmailList',
            function ($attribute, $value, $parameters) {
                /**
                 * Formato corretto:
                 * alias1;email1\r\nalias2;email2\r\n.....aliasN;emailN
                 */
                if (!is_string($value)) {
                    return false;
                }
                if ($value === null) {
                    return false;
                }

                $arr = explode(';', $value);
                if ($arr === false || !is_array($arr) || count($arr) < 1) {
                    return false;
                }

                foreach ($arr as $email) {
                    if (preg_match('/^[A-z0-9\.\+_-]+@[A-z0-9\._-]+\.[A-z]{2,6}$/', $email) <= 0) {
                        return false;
                    }
                }
                return true;
            },
            'La lista di email non è nel formato corretto.Formato: email1;email2;.....;emailN'
        );
    }
}
