# ChangeLog

## Unreleased

- Ajout d'un rattrapage rétroactif manuel des devis signés depuis la maintenance, basé sur la date de signature.
- Ajout des lignes de suivi `tracking` à commission nulle pour remonter le chiffre signé lorsqu'aucune règle de commissionnement ne s'applique.
- Correction des agrégations de chiffre d'affaires/objectifs pour compter chaque devis une seule fois même s'il possède plusieurs lignes de commission.

- Correction de l'initialisation du handler base de données des objets métier pour éviter une erreur fatale lors des créations via `CommonObject::createCommon()`.

## 0.1.0

- Création du socle du module `lmdbsalescommissions`.
- Ajout du descripteur `modLmdbSalesCommissions`.
- Ajout des permissions initiales stables.
- Ajout du menu gauche `Commissions` sous le menu natif Facturation/Paiement.
- Ajout des pages métier vides : tableau de bord, commissions à verser, suivi, commissions versées et exports.
- Ajout des onglets de configuration administrateur.
- Ajout des pages `Compatibilité` et `À propos`.
- Ajout des traductions `fr_FR` et `en_US`.
- Ajout du rapport d’audit technique initial.
- Ajout du schéma SQL initial des règles, grilles, paliers, affectations, lignes de commission, échéances, objectifs et archives.
- Ajout des classes métier `CommonObject` correspondantes avec CRUD de base.
- Ajout des contrôles de périmètre utilisateur pour la lecture et l’export des commissions.
- Ajout des modalités de versement avec contrôle du total à 100 % et rattachement possible aux règles.
- Ajout de la gestion des grilles de paliers et de leurs lignes avec contrôles de cohérence.
- Ajout des affectations de règles aux utilisateurs, groupes et valeurs par défaut.
- Ajout du moteur de résolution du profil de commissionnement effectif.
- Ajout de la configuration des objectifs commerciaux et du resolver d’objectif effectif.
- Ajout du service d’archivage des objectifs et de l’archivage manuel depuis la maintenance.
- Ajout du hook d’estimation de commission sur la fiche devis.
- Ajout du déclencheur de figeage des commissions lors de la signature d’un devis.
- Ajout du service de calcul des primes par paliers avec ligne de synthèse par période.
- Ajout de la génération et reconstruction des échéances de versement.
- Ajout de l’action sécurisée “marquer comme versé”.
- Ajout des pages métier filtrables : tableau de bord, commissions à verser, suivi, commissions versées et exports.
- Ajout de l’onglet `Commissions` sur la fiche utilisateur.
- Ajout de widgets Dolibarr agent et manager.
- Ajout des contrôles de cohérence administrateur.
- Ajout des travaux planifiés natifs pour archivage et échéances.
- Ajout de l’intégration Notifications native via `c_action_trigger`, hook `notifsupported` et substitutions.
- Ajout des exports CSV commissions, échéances, versées, objectifs et archives.
