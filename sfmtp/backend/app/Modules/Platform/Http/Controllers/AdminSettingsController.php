<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Support\Settings\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($this->settings->all())]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $request->validate(['settings' => ['required', 'array', 'min:1']]);
        $before = $this->settings->all();
        $after = $this->settings->update($request->input('settings'), $request->user()->id);
        $changed = array_keys(array_diff_assoc(array_map('json_encode', $after), array_map('json_encode', $before)));
        $audit->record('admin.settings_updated', null, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), ['farm_id' => null]);

        return new JsonResponse(['data' => $this->present($after)]);
    }

    private function present(array $values): array
    {
        $out = [];
        foreach (PlatformSettings::schema() as $key => $def) {
            $out[] = ['key' => $key, 'label' => $def['label'], 'value' => $values[$key], 'default' => $def['default']];
        }

        return $out;
    }
}
