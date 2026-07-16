# ChangeLog

## Unreleased

## 1.1.0 - 2026-07-16

### Nouveautés depuis la version 1.0

- Répartition manuelle des commissions d’un devis entre plusieurs commerciaux, par montant fixe ou pourcentage calculé sur la marge ou le chiffre d’affaires HT.
- Nouvel onglet **Répartition des commissions** sur les devis, modifiable avant signature puis verrouillé afin de conserver les montants et modalités appliqués.
- Sélection explicite d’une modalité de versement par bénéficiaire ou héritage automatique depuis ses règles de commission.
- Enregistrement des commissions estimées dès la validation du devis, afin de fiabiliser le suivi du tunnel commercial.
- Détection des échéances payables à partir des factures client liées directement au devis ou rattachées aux commandes issues du devis.
- Nouveau réglage par entité pour déterminer la condition de libération de l’échéance associée à la facture finale.

### Évolutions et corrections

- Adaptation du rattrapage, des contrôles de cohérence, des exports, des listes et des indicateurs aux répartitions multi-commerciaux.
- Création des estimations manquantes pour les devis validés et détection des échéances déjà payables pour les devis signés lors du rattrapage.
- Déduplication du chiffre d’affaires et de la marge par devis dans les indicateurs globaux.
- Normalisation des montants selon la précision configurée dans Dolibarr.
- Amélioration du rendu natif des tableaux, actions, lignes de total et photos utilisateurs dans les widgets.

## 1.0

- Publication de la version 1.0 du module `lmdbsalescommissions`.
- Stabilisation du périmètre V1 : commissions sur marge, primes par paliers, objectifs commerciaux, échéances de versement, exports, tableaux de bord et widgets Dolibarr.
- Conservation des intégrations natives Dolibarr : permissions, hooks, triggers CRUD, Notifications, travaux planifiés, Multicompany et pages d’administration.
