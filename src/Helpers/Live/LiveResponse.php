<?php

namespace Bpjs\Framework\Helpers\Live;

use Bpjs\Framework\Helpers\Response;

class LiveResponse
{
    public static function fromComponent(LiveComponent $component)
    {
        return Response::json([
            'id'   => $component->id,
            'html' => $component->render()
        ]);
    }
}
