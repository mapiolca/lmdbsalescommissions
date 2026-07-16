# Commissions commerciales

Module Dolibarr externe `lmdbsalescommissions` pour gérer les commissions, primes par paliers et objectifs commerciaux des agents.

Version actuelle : **1.1.0**.

## Nouveautés de la version 1.1.0

Par rapport à la version 1.0, cette version apporte principalement :

- la répartition d’une commission de devis entre plusieurs commerciaux, avec un montant fixe ou un pourcentage de marge ou de chiffre d’affaires HT ;
- l’attribution indépendante du chiffre d’affaires à plusieurs commerciaux pour leurs paliers, objectifs et indicateurs individuels ;
- une modalité de versement choisie par bénéficiaire ou héritée automatiquement de ses règles ;
- l’enregistrement des commissions estimées dès la validation du devis ;
- la détection des factures payées liées directement au devis ou par l’intermédiaire de ses commandes ;
- un réglage par entité pour choisir la condition de libération de l’échéance de facture finale ;
- l’adaptation du rattrapage, des exports et des indicateurs au nouveau fonctionnement multi-commerciaux.
- le rattrapage des devis déjà signés avec attribution automatique au commercial principal lorsque l’historique ne contient aucune répartition de CA.

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
- Attribution du CA d’un devis entre plusieurs commerciaux, indépendamment de leurs commissions.

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
- Onglet devis **Répartition commissions / CA** avec une section de commission et une section d’attribution du chiffre d’affaires indépendantes.
- Attribution du CA par montant HT ou pourcentage, avec contrôle immédiat du dépassement et contrôle strict des 100 % à la signature.
- Attribution automatique de 100 % du CA au commercial principal lorsqu’aucune ligne explicite n’est saisie.
- Héritage automatique des modalités configurées : règle de marge, puis règle de palier, puis paiement intégral à la signature.
- Sélection explicite d’une modalité de versement existante configurée dans le module.
- Calcul des commissions à partir de leur propre répartition, tandis que les paliers et objectifs utilisent exclusivement le CA attribué.
- Commission estimée du tunnel enregistrée à la validation des devis, puis lue depuis les lignes estimées.
- Figeage des commissions lors de la signature d’un devis.
- Génération d’échéances de versement et passage en versé.
- Détection des échéances à verser depuis les factures d’acompte et factures finales liées au devis ou aux commandes issues du devis.
- Rattrapage des devis validés non signés pour créer les estimations et des devis signés avec contributions de CA idempotentes, recalcul des paliers, actualisation des archives d’objectifs et détection des échéances déjà payables.
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

Une répartition manuelle peut être créée depuis l’onglet **Répartition commissions / CA** d’un devis en brouillon ou validé non signé. Chaque section est indépendante et chaque commercial ne peut apparaître qu’une fois dans une même section. Les montants fixes doivent être strictement positifs et ne peuvent pas dépasser leur base ; les pourcentages doivent être compris entre 0 % exclu et 100 % inclus.

La répartition des commissions détermine les commissions et leurs modalités de versement. L’attribution du CA alimente séparément les paliers, objectifs et indicateurs individuels. Une attribution explicite peut rester temporairement inférieure à 100 %, mais elle doit représenter exactement 100 % du CA HT à la signature. Sans attribution explicite, 100 % du CA est affecté au commercial principal ; si celui-ci est absent, la signature est refusée. Après signature, les deux répartitions restent consultables mais ne peuvent plus être modifiées.

## Mise à niveau depuis la version 1.0

1. Sauvegarder la base de données et les fichiers du module.
2. Remplacer le contenu du module par la version 1.1.0.
3. Désactiver puis réactiver le module afin d’exécuter la migration idempotente des tables, colonnes et index nécessaires aux répartitions de commissions et de CA.
4. Vérifier le réglage de libération de l’échéance de facture finale dans les paramètres généraux.
5. Exécuter les contrôles de cohérence et le rattrapage des devis existants si leur CA doit être attribué aux commerciaux. Sans historique explicite, le traitement affecte 100 % du CA au commercial principal du devis.

La désactivation temporaire conserve les réglages métier du module. La migration ajoute les données de répartition sans remettre en cause les commissions déjà enregistrées.

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
- CA réparti à 60/40 entre deux commerciaux, par pourcentages, montants ou combinaison des deux, sans double comptabilisation globale.
- Commercial recevant uniquement du CA, uniquement une commission, ou les deux.
- Répartition de CA incomplète visible et signature refusée ; dépassement refusé dès l’enregistrement.
- Ancien devis signé attribué à 100 % à son commercial principal lors du rattrapage, sans doublon au second passage.
- Archive d’objectif actualisée et échéance déjà versée conservée lors du recalcul.
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
