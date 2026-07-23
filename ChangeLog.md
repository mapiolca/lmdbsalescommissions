# ChangeLog

## 1.1.0 - 2026-07-16

### Nouveautés depuis la version 1.0

- Répartition manuelle des commissions d’un devis entre plusieurs commerciaux, par montant fixe ou pourcentage calculé sur la marge ou le chiffre d’affaires HT.
- Attribution indépendante du chiffre d’affaires entre plusieurs commerciaux, par montant HT, pourcentage ou combinaison des deux, afin d’alimenter leurs paliers et objectifs sans doubler le CA global.
- Nouvel onglet **Répartition commissions / CA** sur les devis, avec deux sections distinctes, modifiables avant signature puis verrouillées.
- Attribution automatique de 100 % du CA au commercial auteur du devis lorsqu’aucune répartition explicite n’est définie.
- Sélection explicite d’une modalité de versement par bénéficiaire ou héritage automatique depuis ses règles de commission.
- Enregistrement des commissions estimées dès la validation du devis, afin de fiabiliser le suivi du tunnel commercial.
- Détection des échéances payables à partir des factures client liées directement au devis ou rattachées aux commandes issues du devis.
- Nouveau réglage par entité pour déterminer la condition de libération de l’échéance associée à la facture finale.

### Évolutions et corrections

- Ajout du mode de grille **Pourcentage progressif par tranche**, disponible sur les périodes mensuelles, trimestrielles et annuelles, avec calcul marginal centralisé et arrondis financiers Dolibarr.
- Conservation du mode historique **Prime fixe au palier atteint** comme valeur par défaut pour toutes les grilles existantes.
- Ajout du taux de commission aux paliers, du snapshot du mode sur les lignes acquises et du détail de commission acquise ou projetée dans les tableaux de bord et exports.
- Résolution de la grille réellement affectée à chaque commercial dans les indicateurs de progression, sans mélange des paliers appartenant à plusieurs grilles.
- Versionnement des échéances par révision afin de conserver les versements effectués lors d’un recalcul et de ne recréer que le solde restant par événement.
- Signalement des trop-versés après recalcul sans création d’échéance négative et sans recalcul historique automatique lors de la modification d’une grille.
- Adaptation du rattrapage, des contrôles de cohérence, des exports, des listes et des indicateurs aux répartitions multi-commerciaux.
- Extension du rattrapage aux devis déjà signés : création idempotente des contributions de CA, recalcul des paliers et actualisation des archives d’objectifs.
- Conservation des commissions et échéances existantes lors d’une resynchronisation du CA ; les échéances versées ne sont jamais modifiées et leurs écarts sont signalés.
- Blocage de la signature lorsqu’une répartition explicite de CA ne couvre pas exactement 100 % ou lorsque le devis ne possède aucun auteur actif pour l’attribution automatique.
- Correction de la signature sans répartition explicite : l’auteur natif du devis est désormais résolu en priorité, y compris lorsque le trigger transmet un objet sans auteur chargé ou que l’utilisateur auteur appartient à une autre entité d’origine.
- Création des estimations manquantes pour les devis validés et détection des échéances déjà payables pour les devis signés lors du rattrapage.
- Déduplication du chiffre d’affaires et de la marge par devis dans les indicateurs globaux.
- Normalisation des montants selon la précision configurée dans Dolibarr.
- Amélioration du rendu natif des tableaux, actions, lignes de total et photos utilisateurs dans les widgets.
- Ajout de la traduction du sélecteur de box et exclusion des boxes déjà présentes sur le tableau de bord.
- Application de la quote-part de chiffre d’affaires à la marge servant de base aux commissions sur marge.
- Désactivation du chargement d’extrafields pour les objets techniques du module afin d’éviter les requêtes vers des tables `*_extrafields` inexistantes.

## 1.0

- Publication de la version 1.0 du module `lmdbsalescommissions`.
- Stabilisation du périmètre V1 : commissions sur marge, primes par paliers, objectifs commerciaux, échéances de versement, exports, tableaux de bord et widgets Dolibarr.
- Conservation des intégrations natives Dolibarr : permissions, hooks, triggers CRUD, Notifications, travaux planifiés, Multicompany et pages d’administration.
