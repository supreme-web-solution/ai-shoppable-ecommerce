<?php

namespace App\Console\Commands;

use App\Services\Media\LocalVideoStagingService;
use Illuminate\Console\Command;

class PruneLocalVideoStagingCommand extends Command
{
    protected $signature = 'videos:prune-staging {--hours=6 : Delete unreferenced staging files older than this many hours}';

    protected $description = 'Remove orphaned local video uploads after Cloudinary processing';

    public function handle(LocalVideoStagingService $staging): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $deleted = $staging->pruneOrphans($hours);

        $this->info("Pruned {$deleted} orphaned staging file(s) older than {$hours} hour(s).");

        return self::SUCCESS;
    }
}
