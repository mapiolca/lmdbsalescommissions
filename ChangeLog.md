# ChangeLog

## Unreleased

## 1.1.0 - 2026-07-15

### Nouveautés

- Répartition manuelle d’une commission de devis entre plusieurs bénéficiaires, par montant fixe ou pourcentage de marge ou de chiffre d’affaires HT.
- Modalité de versement explicite ou héritée automatiquement des règles configurées.
- Enregistrement des commissions estimées dès la validation des devis.
- Détection des échéances payables depuis les factures client liées directement au devis ou via les commandes issues du devis.
- Nouveau réglage par entité pour choisir la règle de libération de l’échéance de facture finale.

### Améliorations

- Adaptation du rattrapage, des contrôles, des exports et des indicateurs aux répartitions multi-commerciaux.
- Déduplication du chiffre d’affaires et de la marge par devis dans les indicateurs globaux.
- Normalisation des montants selon la précision Dolibarr et amélioration du rendu natif des tableaux, actions et totaux.
- Affichage des photos utilisateurs dans les widgets du tableau de bord.

## 1.0

- Publication de la version 1.0 du module `lmdbsalescommissions`.
- Stabilisation du périmètre V1 : commissions sur marge, primes par paliers, objectifs commerciaux, échéances de versement, exports, tableaux de bord et widgets Dolibarr.
- Conservation des intégrations natives Dolibarr : permissions, hooks, triggers CRUD, Notifications, travaux planifiés, Multicompany et pages d’administration.
