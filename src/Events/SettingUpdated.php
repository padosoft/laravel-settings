<?php
namespace Padosoft\Laravel\Settings\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Padosoft\Laravel\Settings\Settings;

class SettingUpdated
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public Settings $setting;

    public function __construct(Settings $setting)
    {

        $this->setting = $setting;
    }
}
