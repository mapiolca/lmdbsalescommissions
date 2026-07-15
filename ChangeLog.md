# ChangeLog

## Unreleased

- Ajout de la répartition manuelle des commissions d’un devis entre plusieurs commerciaux, par montant ou pourcentage de marge ou de chiffre d’affaires.
- Ajout d’une modalité de versement explicite ou héritée automatiquement des règles de marge, puis de palier, avec repli sur un versement intégral à la signature.
- Ajout de l’onglet devis, de la permission dédiée, des snapshots de calcul et du verrouillage de la répartition après signature.
- Exclusion des répartitions manuelles des paliers et objectifs, avec déduplication du chiffre d’affaires et de la marge dans les indicateurs globaux.
- Ajout des contrôles de cohérence, traductions, filtres, exports et déclarations de compatibilité associés à la répartition multi-commerciaux.
- Correction du calcul de la commission estimée du tunnel : elle est désormais enregistrée à la validation des devis et relue depuis les lignes estimées.
- Affichage des photos utilisateurs dans les liens `getNomUrl()` des widgets du tableau de bord.
- Ajout de la détection native des échéances à verser depuis les factures client liées directement au devis ou liées aux commandes issues du devis.
- Ajout du réglage par entité `LMDBSALESCOMMISSIONS_FINAL_INVOICE_DUE_MODE` pour choisir la règle de libération de l’échéance de facture finale.
- Intégration de cette détection et de l’enregistrement des estimations au rattrapage des devis, limitée aux devis traités par le rattrapage.
- Déclaration de la compatibilité `native_invoice_payment_detection`, disponible dès Dolibarr v20.

## 1.0

- Publication de la version 1.0 du module `lmdbsalescommissions`.
- Limitation des décimales des montants et indicateurs selon le paramètre Dolibarr des prix totaux.
- Stabilisation du périmètre V1 : commissions sur marge, primes par paliers, objectifs commerciaux, échéances de versement, exports, tableaux de bord et widgets Dolibarr.
- Conservation des intégrations natives Dolibarr : permissions, hooks, triggers CRUD, Notifications, travaux planifiés, Multicompany et pages d’administration.
