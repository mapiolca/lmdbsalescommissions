# Commissions commerciales

Module Dolibarr externe `lmdbsalescommissions` pour gérer les commissions, primes par paliers et objectifs commerciaux des agents.

## Périmètre V1

- Commission constante sur marge.
- Primes par paliers de chiffre d’affaires.
- Cumul commission sur marge et prime par paliers.
- Règles par agent, par groupe d’utilisateurs et par défaut.
- Objectifs mensuels et annuels facultatifs.
- Archivage de l’atteinte des objectifs par utilisateur et par période.
- Suivi des commissions à verser et des commissions versées.
- Tableau de bord par widgets déplaçables/masquables avec disposition par utilisateur.
- Exports CSV centralisés dans le menu **Exports**.
- Compatibilité Multicompany.

## Structure du module

Le dépôt représente directement la racine du module à installer dans Dolibarr sous :

```text
htdocs/custom/lmdbsalescommissions/
```

Il ne contient pas le préfixe `htdocs/custom/`, car ce chemin est géré par Dolibarr.

## Fonctionnalités principales

- Configuration des règles de commission sur marge et des primes par paliers.
- Modalités de versement avec contrôle de répartition à 100 %.
- Affectation des règles à un utilisateur, un groupe ou par défaut.
- Résolution du profil effectif utilisateur > groupe > défaut.
- Objectifs mensuels et annuels facultatifs, avec archivage.
- Estimation de commission sur la fiche devis.
- Figeage des commissions lors de la signature d’un devis.
- Génération d’échéances de versement et passage en versé.
- Pages métier : tableau de bord par widgets, à verser, suivi, versées, exports.
- Onglet **Commissions** sur la fiche utilisateur.
- Widgets Dolibarr agent et manager, ainsi que widgets d’accueil ciblés pour commissions à verser, objectif, palier, anomalies et synthèses manager.
- Crons natifs pour archivage et échéances.
- Intégration Notifications native via `c_action_trigger`, hook `notifsupported` et substitutions.

## Tableau de bord

Le tableau de bord **Facturation | Paiement > Commissions > Tableau de bord** est composé de widgets indépendants.

- La disposition est enregistrée par utilisateur et par entité.
- Les widgets peuvent être déplacés, masqués puis réaffichés depuis le tableau de bord.
- Les widgets masqués ne chargent pas leurs données.
- Les filtres globaux s’appliquent uniquement aux widgets visibles.
- Aucun widget ne propose d’export direct.

Tous les exports restent centralisés dans **Facturation | Paiement > Commissions > Exports**.

## Configuration rapide

1. Activer le module depuis la liste des modules Dolibarr.
2. Ouvrir la configuration du module.
3. Créer une modalité de versement, par exemple 100 % à la signature ou 30/40/30.
4. Créer une règle de commission sur marge ou une règle par paliers.
5. Créer une grille de paliers si une règle de type palier est utilisée.
6. Affecter la règle à un utilisateur, un groupe ou par défaut.
7. Définir des objectifs mensuels ou annuels si nécessaire.
8. Vérifier les contrôles de cohérence dans l’onglet **Aide / contrôles**.

## Scénarios de validation

- Agent avec commission sur marge seule.
- Agent avec prime par palier seule.
- Agent avec commission sur marge et prime par palier.
- Agent sans objectif.
- Agent avec objectif mensuel.
- Agent avec objectif annuel.
- Règle individuelle prioritaire sur une règle de groupe.
- Règle de groupe prioritaire sur une règle par défaut.
- Conflit de règles détecté.
- Commission figée à la signature du devis.
- Échéance marquée comme versée par un utilisateur autorisé.
- Widget masqué puis réaffiché sans chargement de données lorsqu’il est masqué.
- Exports accessibles uniquement depuis la page **Exports**.

## Compatibilité

- Dolibarr : v20+
- PHP : 8.0+
- Base de données : MySQL/MariaDB via l’abstraction Dolibarr
- Module installé sous `htdocs/custom/lmdbsalescommissions/`.

## Limites V1

- Pas de génération automatique de facture fournisseur ou note de frais.
- Pas de commissionnement par ligne produit/service.
- Pas de gestion de plusieurs commerciaux sur un même devis.
- La détection des paiements acompte/facture finale doit être validée selon les liens facture/devis de l’instance Dolibarr cible.
- Aucun modèle PDF/ODT n’est fourni dans cette V1.

## Licence

AGPL-3.0-or-later.
