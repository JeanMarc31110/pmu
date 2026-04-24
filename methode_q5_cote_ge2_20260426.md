# Méthode Q5 cote >= 2

Date d'application : 25/04/2026.

Règle de qualification :
- Conserver uniquement les chevaux en `Q5`.
- Conserver uniquement les chevaux avec une cote disponible `>= 2`.
- Ne plus exclure les chevaux dont la cote est supérieure à 8.

Tri du ticket dans une course :
- Profil 1 : cote entre 5 et 8.
- Profil 2 : cote entre 3 et moins de 5.
- Profil 3 : cote entre 2 et moins de 3.
- Profil 4 : cote supérieure à 8.
- Puis `jt_score` décroissant.
- Puis `valeur_handicap` décroissante.

Mise de référence :
- 1 euro par course jouée pour le PnL réel.
- Kelly reste un indicateur d'affichage et de préparation.

Source des cotes :
- À D-10, utiliser les cotes live au moment de l'exécution de la moulinette.
- Le ticket D-10 doit être conservé tel quel après capture et ne doit pas être recalculé après la course.

Fichiers concernés :
- `method_config.php`
- `build_moulinette_inputs.php`
- `query_day_moulinette_summary.php`
- `capture_d10_due_test.php`
- `query_day_moulinette_pnl_test.php`
