<?php

namespace Ernestdefoe\DiscussionParticipants\Console;

use Ernestdefoe\DiscussionParticipants\ParticipantSynchronizer;
use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputOption;

/**
 * Backfills participant data for discussions that already existed when the
 * extension was enabled. Safe to re-run: each chunk is rebuilt from the
 * posts table, so a half-finished run just gets finished.
 */
class PopulateCommand extends AbstractCommand
{
    public const CHUNK = 500;

    public function __construct(
        protected ParticipantSynchronizer $sync,
        protected ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('participants:populate')
            ->setDescription('Rebuild discussion participant data for every discussion.')
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Discussions per batch.', (string) self::CHUNK);
    }

    protected function fire(): int
    {
        $chunk = max(1, (int) $this->input->getOption('chunk'));
        $max = (int) $this->db->table('discussions')->max('id');

        if ($max === 0) {
            $this->info('No discussions to populate.');

            return 0;
        }

        $progress = new ProgressBar($this->output, (int) ceil($max / $chunk));
        $progress->start();

        for ($from = 1; $from <= $max; $from += $chunk) {
            $this->sync->rebuildRange($from, min($from + $chunk - 1, $max));
            $progress->advance();
        }

        $progress->finish();
        $this->output->writeln('');

        $rows = (int) $this->db->table('discussion_participants')->count();
        $this->info("Done. {$rows} participant records across {$max} discussion ids.");

        return 0;
    }
}
