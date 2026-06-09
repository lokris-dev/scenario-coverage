<?php

declare(strict_types=1);

namespace Lokris\TrajectoryCoverage\Extension\Subscriber;

use Lokris\TrajectoryCoverage\Coverage\TrajectoryStore;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber as Subscriber;

/**
 * Déclenché UNE SEULE FOIS, à la fin de l'exécution de tous les tests.
 *
 * On persiste ici plutôt que sur TestSuite\Finished : ce dernier se déclenche pour
 * CHAQUE sous-suite (répertoire, testsuite nommée) ET la suite racine, ce qui
 * provoquait des flush() et des messages de confirmation dupliqués à chaque run.
 * ExecutionFinished survient une fois, après que tous les events Test\Finished ont
 * été collectés : tous les enregistrements sont donc présents.
 */
final class ExecutionFinishedSubscriber implements Subscriber
{
    public function __construct(private readonly TrajectoryStore $store)
    {
    }

    public function notify(ExecutionFinished $event): void
    {
        $records = $this->store->getRecords();
        if (count($records) === 0) {
            return;
        }

        $this->store->flush();
        fwrite(
            STDOUT,
            sprintf(
                "\n[trajectory-coverage] ✅ %d trajectoire(s) enregistrée(s) → %s\n",
                count($records),
                $this->store->outputFile()
            )
        );
    }
}
