<?php
declare(strict_types=1);

namespace App\Service\Retainer;

use App\Models\Retainer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ConsumptionQuery
{
    public function computeTracked(Retainer $retainer, Carbon $asOf, ?string $projectId = null): int
    {
        $sql = <<<'SQL'
            SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (time_entries.end - time_entries.start))::int), 0) AS seconds
            FROM time_entries
            JOIN projects ON projects.id = time_entries.project_id
            WHERE projects.client_id = :client_id
              AND time_entries.end IS NOT NULL
              AND time_entries.start >= :starts_at
              AND time_entries.end <= :as_of
              AND (NOT :billable_only OR time_entries.billable = true)
              AND (:project_id::uuid IS NULL OR time_entries.project_id = :project_id::uuid)
        SQL;

        $row = DB::selectOne($sql, [
            'client_id' => $retainer->client_id,
            'starts_at' => $retainer->starts_at->copy()->startOfDay(),
            'as_of' => $asOf,
            'billable_only' => $retainer->billable_only,
            'project_id' => $projectId,
        ]);

        return (int) ($row->seconds ?? 0);
    }
}
