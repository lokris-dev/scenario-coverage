<?php

declare(strict_types=1);

namespace Lokris\TrajectoryCoverage\Extension\Subscriber;

use Lokris\TrajectoryCoverage\Coverage\TrajectoryStore;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use ReflectionClass;
use Throwable;

/**
 * Déclenché après chaque méthode de test.
 *
 * Lit l'attribut #[Trajectory] sur la classe de test via la réflexion,
 * puis délègue à TrajectoryStore l'arrêt de capture et l'enregistrement.
 */
final class TestFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(private readonly TrajectoryStore $store)
    {
    }

    public function notify(Finished $event): void
    {
        $test = $event->test();

        // On ne traite que les TestMethod (pas les DataProvider/etc.)
        if (!$test instanceof TestMethod) {
            $this->store->recordNonTrajectory();
            return;
        }

        $className = $test->className();
        $testFile  = $test->file();
        // durationSincePrevious() retourne la durée depuis l'event précédent (≈ durée du test)
        $duration  = $event->telemetryInfo()->durationSincePrevious()->asFloat();

        // Statut réel : résolu par le store, alimenté par les subscribers
        // Failed/Errored/Skipped (qui se déclenchent avant cet event Finished).
        // 'passed' par défaut si aucun échec/skip n'a été signalé pour ce test.
        $status = $this->store->currentStatus();

        // Lire l'attribut #[Trajectory] PUIS l'instancier — le tout dans le try.
        // On supporte plusieurs namespaces (recherche par nom court de classe) :
        //   - Lokris\TrajectoryCoverage\Attribute\Trajectory  (package standalone)
        //   - Stamina\CoreBundle\Attribute\Trajectory         (bundle natif Stamina)
        // Si la réflexion OU l'instanciation de l'attribut échoue (Error/TypeError du
        // constructeur), on arrête proprement la capture via recordNonTrajectory() pour
        // ne pas laisser une session Xdebug ouverte polluer le test suivant.
        try {
            $ref = new ReflectionClass($className);

            $trajectoryAttr = null;
            foreach ($ref->getAttributes() as $attr) {
                $attrName = $attr->getName();
                if ($attrName === 'Trajectory' || str_ends_with($attrName, '\\Trajectory')) {
                    $trajectoryAttr = $attr;
                    break;
                }
            }

            if ($trajectoryAttr === null) {
                // Test non annoté comme trajectoire — on arrête la capture proprement.
                $this->store->recordNonTrajectory();
                return;
            }

            // Instancier l'attribut (tolérant sur l'absence de `tags`).
            $trajectory = $trajectoryAttr->newInstance();
        } catch (Throwable) {
            $this->store->recordNonTrajectory($className, $status, $duration);
            return;
        }

        $this->store->endTrajectory(
            testClass: $className,
            testFile:  $testFile,
            status:    $status,
            duration:  $duration,
            title:     $trajectory->title,
            desc:      $trajectory->description,
            mandatory: $trajectory->mandatory,
            tags:      property_exists($trajectory, 'tags') ? $trajectory->tags : [],
        );
    }
}
