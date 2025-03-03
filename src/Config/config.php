<?php
return [
    'enabled' => false,
    'default_connection' => 'default',
    'local_connection' => null,
    'local_expire' => 300,
    'encrypted_keys' => [],
    'cast' => [
        //Esempio
        //'boolean' => ['class' => \Padosoft\Laravel\Settings\CastSettings::class, 'method' => 'boolean'],
        //'booleanFromString' => ['class' => \Padosoft\Laravel\Settings\CastSettings::class]
    ],
];
