# ChangeLog

## Unreleased

## 1.1.0 - 2026-07-15

Cette version étend le périmètre de la version 1.0 avec la répartition multi-commerciaux et fiabilise le cycle complet allant de l’estimation au versement.

### Répartition multi-commerciaux

- Répartition manuelle d’une commission de devis entre plusieurs bénéficiaires, par montant fixe ou pourcentage de marge ou de chiffre d’affaires HT.
- Modalité de versement choisie parmi les réglages du module ou résolue automatiquement depuis la règle de marge, puis la règle de palier, avec repli sur un paiement intégral à la signature.
- Nouvel onglet devis et permission dédiée, avec validation des bases, instantanés de calcul, figeage à la signature et verrouillage après signature.
- Remplacement du moteur automatique dès qu’une répartition existe, exclusion des paliers et objectifs, puis rétablissement automatique lors du retrait de la dernière répartition avant signature.
- Adaptation des droits de lecture, contrôles d’anomalies, filtres, exports, rattrapage, indicateurs et compatibilité Multicompany.

### Estimations et échéances

- Enregistrement des commissions estimées à la validation des devis et lecture du tunnel depuis ces estimations persistées.
- Détection des échéances payables depuis les factures client liées directement au devis ou via les commandes issues du devis.
- Nouveau réglage par entité pour choisir la règle de libération de l’échéance de facture finale.
- Intégration de la création des estimations et de la détection des échéances au rattrapage des devis concernés.

### Reporting et interface

- Déduplication du chiffre d’affaires et de la marge par devis dans les indicateurs globaux, tout en cumulant les commissions de chaque bénéficiaire.
- Normalisation de l’affichage des montants et indicateurs selon la précision configurée pour les totaux Dolibarr.
- Affichage des photos utilisateurs dans les widgets et harmonisation des actions, tableaux et lignes de totaux avec le rendu natif Dolibarr.
- Correction du chargement de la bibliothèque native des devis depuis l’onglet de répartition.

### Compatibilité

- Fonctionnalités de répartition et de détection native des paiements disponibles dès Dolibarr v20 et PHP 8.0.

## 1.0

- Publication de la version 1.0 du module `lmdbsalescommissions`.
- Stabilisation du périmètre V1 : commissions sur marge, primes par paliers, objectifs commerciaux, échéances de versement, exports, tableaux de bord et widgets Dolibarr.
- Conservation des intégrations natives Dolibarr : permissions, hooks, triggers CRUD, Notifications, travaux planifiés, Multicompany et pages d’administration.
