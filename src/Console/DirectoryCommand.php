<?php

namespace ErnestDefoe\Flarumverse\Console;

use ErnestDefoe\Flarumverse\Directory;
use ErnestDefoe\Flarumverse\Listing;
use Illuminate\Console\Command;

/** flarumverse:directory — daily refresh of every listing (directory host only). */
class DirectoryCommand extends Command
{
    protected $signature = 'flarumverse:directory';

    protected $description = 'Refresh the numbers and latest discussions of every forum in the Flarumverse directory.';

    public function handle(Directory $directory): int
    {
        if (! $directory->isHost()) {
            return 0;
        }

        Listing::query()->whereIn('status', [Listing::PENDING, Listing::APPROVED])->orderBy('checked_at')->limit(1000)->get()
            ->each(fn (Listing $l) => $this->line(str_pad($directory->refresh($l), 12).$l->url));

        return 0;
    }
}
