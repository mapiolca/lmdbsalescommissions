# ChangeLog

## Unreleased

- Correction du calcul de la commission estimée du tunnel : elle est désormais calculée depuis les devis validés non signés.
- Affichage des photos utilisateurs dans les liens `getNomUrl()` des widgets du tableau de bord.
- Ajout de la détection native des échéances à verser depuis les factures client liées directement au devis ou liées aux commandes issues du devis.
- Ajout du réglage par entité `LMDBSALESCOMMISSIONS_FINAL_INVOICE_DUE_MODE` pour choisir la règle de libération de l’échéance de facture finale.
- Intégration de cette détection au rattrapage des devis signés, limitée aux devis traités par le rattrapage.
- Déclaration de la compatibilité `native_invoice_payment_detection`, disponible dès Dolibarr v20.

## 1.0

- Publication de la version 1.0 du module `lmdbsalescommissions`.
- Limitation des décimales des montants et indicateurs selon le paramètre Dolibarr des prix totaux.
- Stabilisation du périmètre V1 : commissions sur marge, primes par paliers, objectifs commerciaux, échéances de versement, exports, tableaux de bord et widgets Dolibarr.
- Conservation des intégrations natives Dolibarr : permissions, hooks, triggers CRUD, Notifications, travaux planifiés, Multicompany et pages d’administration.
