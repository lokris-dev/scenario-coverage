<?php

declare(strict_types=1);

namespace Lokris\ScenarioCoverage\Attribute;

use Attribute;

/**
 * Décore une classe de test PHPUnit pour la déclarer comme scénario utilisateur.
 *
 * Une scénario = un scénario utilisateur nommé et décrit, exécuté de bout en bout.
 * L'extension ScenarioExtension collecte les lignes de code couvertes par chaque
 * scénario et les associe à ce titre/description dans le rapport HTML.
 *
 * Usage :
 *   #[Scenario(
 *       title: "Créer un devis",
 *       description: "L'utilisateur crée un devis depuis la liste, ajoute une ligne produit et vérifie les totaux.",
 *       mandatory: true,
 *   )]
 *   class S01_CreateQuoteTest extends ScenarioTestCase { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Scenario
{
    public function __construct(
        /**
         * Titre court du scénario (s'affiche dans l'arborescence du rapport).
         * Ex : "Créer un devis", "Paiement partiel (50%)", "Flux complet Devis → Facture"
         */
        public readonly string $title,

        /**
         * Description détaillée du scénario utilisateur (visible dans le panel scénario).
         */
        public readonly string $description,

        /**
         * Si true, la scénario est marquée comme obligatoire dans le rapport.
         * Un échec ou une absence de couverture est signalé en rouge.
         */
        public readonly bool $mandatory = false,

        /**
         * Tags libres pour regrouper les scénarios (ex: ["vente", "comptabilité"]).
         * @var string[]
         */
        public readonly array $tags = [],
    ) {}
}
