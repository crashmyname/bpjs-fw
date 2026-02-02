<?php

namespace Bpjs\Framework\Helpers\Live;

class LiveRenderer
{
    public static function mount(string $component, array $props = []): string
    {
        /** @var LiveComponent $instance */
        $instance = new $component;

        foreach ($props as $k => $v) {
            $instance->$k = $v;
        }

        $instance->mount();

        session([$instance->id => $instance->state()]);

        return <<<HTML
<div id="{$instance->id}" data-live="{$component}">
  {$instance->render()}
</div>
HTML;
    }
}
