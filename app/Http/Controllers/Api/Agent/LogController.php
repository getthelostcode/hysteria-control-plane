<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Api\Agent\Concerns\InteractsWithAgent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\LogReportRequest;
use App\Models\NodeLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class LogController extends Controller
{
    use InteractsWithAgent;

    public function __invoke(LogReportRequest $request): JsonResponse
    {
        $node = $this->node($request);
        $entries = array_slice($request->entries(), 0, (int) config('agent.max_log_entries'));
        $now = now();
        $rows = [];

        foreach ($entries as $entry) {
            $rows[] = [
                'node_id' => $node->id,
                'level' => in_array($entry['level'] ?? 'info', NodeLog::LEVELS, true) ? $entry['level'] : 'info',
                'message' => (string) ($entry['message'] ?? ''),
                'context' => isset($entry['context']) && is_array($entry['context'])
                    ? json_encode($entry['context'], JSON_UNESCAPED_UNICODE)
                    : null,
                'recorded_at' => isset($entry['recorded_at']) ? Carbon::parse((string) $entry['recorded_at']) : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            NodeLog::query()->insert($rows);
        }

        return response()->json(['ok' => true, 'stored' => count($rows)], 202);
    }
}
