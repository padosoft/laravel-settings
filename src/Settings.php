<?php

namespace Padosoft\Laravel\Settings;

use Illuminate\Database\Eloquent\Model;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;

class Settings extends Model
{
    use Cachable;

    protected $dates = ['created_at', 'updated_at'];


}
