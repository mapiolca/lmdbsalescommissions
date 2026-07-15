# Commissions commerciales

Module Dolibarr externe `lmdbsalescommissions` pour gérer les commissions, primes par paliers et objectifs commerciaux des agents.

Version actuelle : **1.1.0**.

## Périmètre de la version 1.1.0

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
- Répartition manuelle d’une commission de devis entre plusieurs commerciaux, sur montant ou pourcentage de marge ou de CA.

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
- Onglet devis de répartition multi-commerciaux avec une formule et une modalité de versement par bénéficiaire : montant fixe ou pourcentage, calculé sur la marge ou le chiffre d’affaires HT.
- Héritage automatique des modalités configurées : règle de marge, puis règle de palier, puis paiement intégral à la signature.
- Sélection explicite d’une modalité de versement existante configurée dans le module.
- Remplacement des commissions, paliers et objectifs automatiques du devis dès qu’une répartition manuelle existe.
- Commission estimée du tunnel enregistrée à la validation des devis, puis lue depuis les lignes estimées.
- Figeage des commissions lors de la signature d’un devis.
- Génération d’échéances de versement et passage en versé.
- Détection des échéances à verser depuis les factures d’acompte et factures finales liées au devis ou aux commandes issues du devis.
- Rattrapage des devis validés non signés pour créer les estimations et des devis signés avec détection des échéances déjà payables dans le même traitement.
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
8. Choisir la règle de libération de l’échéance de facture finale dans les paramètres généraux du module.
9. Vérifier les contrôles de cohérence dans l’onglet **Aide / contrôles**.

Une répartition manuelle peut être créée depuis l’onglet **Répartition des commissions** d’un devis en brouillon ou validé non signé. Chaque commercial ne peut apparaître qu’une fois par devis. Les montants fixes doivent être strictement positifs et ne peuvent pas dépasser la base choisie ; les pourcentages doivent être compris entre 0 % exclu et 100 % inclus.

Dès qu’au moins une ligne existe, la répartition remplace les règles, paliers et objectifs automatiques pour ce devis. La suppression de la dernière ligne avant signature rétablit le calcul automatique. À la signature, les bases et modalités sont revalidées, puis la commission et la modalité effectivement appliquée sont figées pour chaque bénéficiaire. La répartition reste ensuite consultable mais ne peut plus être modifiée.

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
- Plusieurs commerciaux sur un même devis avec montant fixe ou pourcentage de marge ou de CA.
- Modalité de versement explicitement sélectionnée ou héritée des réglages du commercial.
- Répartition verrouillée après signature et visible selon le périmètre de droits propre, groupe ou global.
- Échéance d’acompte rendue payable par une facture d’acompte payée liée directement au devis ou à une commande du devis.
- Échéance de facture finale rendue payable selon le mode configuré pour les factures finales liées.
- Échéance marquée comme versée par un utilisateur autorisé.
- Widget masqué puis réaffiché sans chargement de données lorsqu’il est masqué.
- Exports accessibles uniquement depuis la page **Exports**.

## Compatibilité

- Version du module : 1.1.0
- Dolibarr : v20+
- PHP : 8.0+
- Base de données : MySQL/MariaDB via l’abstraction Dolibarr
- Module installé sous `htdocs/custom/lmdbsalescommissions/`.

## Limites de la version 1.1.0

- Pas de génération automatique de facture fournisseur ou note de frais.
- Pas de commissionnement par ligne produit/service.
- La détection des paiements acompte/facture finale repose sur les liens natifs Dolibarr entre devis, commandes et factures client.
- Aucun modèle PDF/ODT n’est fourni dans cette version.

## Licence

AGPL-3.0-or-later.
