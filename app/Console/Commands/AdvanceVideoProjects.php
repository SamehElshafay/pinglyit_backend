<?php

namespace App\Console\Commands;

use App\Services\Video\FaceSwapService;
use App\Services\Video\VideoStudioService;
use Illuminate\Console\Command;

/**
 * Moves every rendering video one step forward: submit waiting shots, poll
 * running ones, download + bill finished ones, stitch completed films.
 * Scheduled every minute (routes/console.php). Safe to run alongside the
 * after-response nudge in VideoStudioController — each project is
 * lock-guarded inside VideoStudioService::advance().
 */
class AdvanceVideoProjects extends Command
{
    protected $signature = 'video:advance';

    protected $description = 'Advance in-flight Video Studio renders';

    public function handle(VideoStudioService $studio, FaceSwapService $faceSwaps): int
    {
        $count = $studio->advanceAll();
        $swaps = $faceSwaps->advanceAll();
        $this->info("Advanced {$count} video project(s) and {$swaps} face swap(s).");

        return self::SUCCESS;
    }
}
