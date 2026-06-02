<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Analytics\RollupService;
use Illuminate\Console\Command;

class RebuildAnalyticsRollupsCommand extends Command
{
    protected $signature = 'analytics:rebuild-rollups
                            {--team= : Limit rebuild to a single team id}
                            {--from= : Start date (Y-m-d)}
                            {--to= : End date (Y-m-d)}';

    protected $description = 'Rebuild analytics rollups from raw analytics events';

    public function handle(RollupService $rollupService): int
    {
        $teamId = $this->option('team');
        $from = $this->option('from');
        $to = $this->option('to');

        $teams = $teamId
            ? Team::query()->whereKey($teamId)->get()
            : Team::query()->orderBy('id')->get();

        if ($teams->isEmpty()) {
            $this->warn('No teams found.');

            return self::FAILURE;
        }

        $total = 0;

        foreach ($teams as $team) {
            $count = $rollupService->rebuildForTeam(
                (int) $team->id,
                is_string($from) && $from !== '' ? $from : null,
                is_string($to) && $to !== '' ? $to : null,
            );
            $total += $count;
            $this->line("Team {$team->id}: {$count} rollup row(s) updated.");
        }

        $this->info("Done. {$total} rollup row(s) updated.");

        return self::SUCCESS;
    }
}
