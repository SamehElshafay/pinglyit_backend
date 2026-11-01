<?php

namespace App\Console\Commands;

use App\Services\Meta\MetaCampaignBuilder;
use App\Services\Meta\MetaPublisher;
use Illuminate\Console\Command;

/**
 * Finishes Instagram Reels that were still processing when published.
 * Scheduled every minute (routes/console.php).
 */
class PublishSocialPosts extends Command
{
    protected $signature = 'meta:publish-posts';

    protected $description = 'Finish Instagram posts waiting on video processing';

    public function handle(MetaPublisher $publisher, MetaCampaignBuilder $campaigns): int
    {
        $this->info('Checked '.$publisher->advance().' processing post(s).');
        // Campaigns waiting on Meta to finish processing their video.
        $campaigns->advance();

        return self::SUCCESS;
    }
}
