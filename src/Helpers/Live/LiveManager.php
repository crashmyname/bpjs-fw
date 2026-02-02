<?php
namespace Bpjs\Framework\Helpers\Live;

class LiveManager
{
    public static function handle(string $componentClass)
    {
        $req = LiveRequest::capture();

        /** @var LiveComponent $component */
        $component = new $componentClass;

        // restore state
        if ($req->componentId && session()->has($req->componentId)) {
            foreach (session($req->componentId) as $k => $v) {
                $component->$k = $v;
            }
        }

        // lifecycle
        if (!$req->componentId) {
            $component->mount();
        }

        $component->hydrate();

        // update props
        foreach ($req->payload as $key => $value) {
            if (property_exists($component, $key)) {
                $component->$key = $value;

                $method = 'updated' . ucfirst($key);
                if (method_exists($component, $method)) {
                    $component->$method($value);
                }
            }
        }

        $component->dehydrate();

        // persist state
        session([$component->id => $component->state()]);

        return LiveResponse::fromComponent($component);
    }
}
