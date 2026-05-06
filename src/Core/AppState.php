<?php
namespace Bpjs\Framework\Core;

use Bpjs\Framework\Helpers\Api;
use Bpjs\Framework\Helpers\BaseModel;

class AppState
{
    public static function reset()
    {
        Api::setRoutes([]);
        Api::setNames([]);

        // reset model state kalau perlu
        BaseModel::clearState();
    }
}