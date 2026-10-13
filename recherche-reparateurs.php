<?php
/**
 * Fair'repair — Recherche des réparateurs disponibles
 * ---------------------------------------------------
 * Fonctions partagées par :
 *   - api/reparateurs.php  (réponse JSON pour la carte, appelée en JavaScript)
 *   - dashboard-client.php (liste affichée côté serveur, utile si JavaScript
 *                           ou la carte ne se chargent pas)
 * Une seule requête SQL et une seule validation : les deux affichages
 * donnent toujours les mêmes résultats.
 */

// Paramètres de recherche par défaut et bornes autorisées
const RECHERCHE_RAYON_DEFAUT = 5;        // km
const RECHERCHE_RAYON_MIN    = 1;
const RECHERCHE_RAYON_MAX    = 50;
const RECHERCHE_TARIF_PLAFOND = 9999.99; // tarif maximal possible (DECIMAL(8,2) côté appli)
const PARIS_CENTRE = ['lat' => 48.8566, 'lng' => 2.3522];

/**
 * Lit, valide et borne les filtres reçus en GET.
 *
 * Chaque valeur est convertie dans le bon type (float / int) puis ramenée
 * dans un intervalle raisonnable : une valeur absurde (rayon=100000,
 * lat=abc…) ne peut donc jamais atteindre la requête SQL.
 *
 * Renvoie ['filtres' => [...], 'erreurs' => [...]].
 */
function lireFiltresRecherche(array $get): array
{
    $erreurs = [];

    // --- Position : lat ET lng, ou aucune des deux ---
    $lat = $get['lat'] ?? '';
    $lng = $get['lng'] ?? '';
    $position = null;

    if ($lat !== '' || $lng !== '') {
        // FILTER_VALIDATE_FLOAT renvoie false si ce n'est pas un nombre
        $latF = is_string($lat) ? filter_var($lat, FILTER_VALIDATE_FLOAT) : false;
        $lngF = is_string($lng) ? filter_var($lng, FILTER_VALIDATE_FLOAT) : false;

        if ($latF === false || $lngF === false) {
            $erreurs[] = "Les paramètres lat et lng doivent être deux nombres.";
        } elseif ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) {
            $erreurs[] = "Coordonnées hors limites (lat : -90 à 90, lng : -180 à 180).";
        } else {
            $position = ['lat' => $latF, 'lng' => $lngF];
        }
    }

    // --- Rayon : entier, borné entre 1 et 50 km ---
    $rayon = filter_var($get['rayon'] ?? '', FILTER_VALIDATE_INT);
    $rayon = $rayon === false ? RECHERCHE_RAYON_DEFAUT
                              : max(RECHERCHE_RAYON_MIN, min(RECHERCHE_RAYON_MAX, $rayon));

    // --- Note minimale : décimal entre 0 et 5 (0 = pas de filtre) ---
    $noteMin = filter_var($get['note_min'] ?? '', FILTER_VALIDATE_FLOAT);
    $noteMin = $noteMin === false ? 0.0 : max(0.0, min(5.0, $noteMin));

    // --- Tarif maximal : décimal entre 0 et 9999,99 (vide = pas de limite) ---
    // La virgule française est acceptée (« 30,50 »).
    $tarifBrut = is_string($get['tarif_max'] ?? null) ? str_replace(',', '.', trim($get['tarif_max'])) : '';
    $tarifMax  = filter_var($tarifBrut, FILTER_VALIDATE_FLOAT);
    $tarifMax  = $tarifMax === false ? null : max(0.0, min(RECHERCHE_TARIF_PLAFOND, $tarifMax));

    return [
        'filtres' => [
            'position'  => $position,
            'rayon'     => $rayon,
            'note_min'  => $noteMin,
            'tarif_max' => $tarifMax,
        ],
        'erreurs' => $erreurs,
    ];
}

/**
 * Renvoie les réparateurs disponibles correspondant aux filtres.
 *
 * Seuls les champs PUBLICS sont sélectionnés : jamais l'e-mail, le
 * téléphone, le mot de passe haché ni le SIRET. Ce qui n'est pas dans
 * le SELECT ne peut pas fuiter dans le JSON.
 */
function rechercherReparateurs(PDO $pdo, array $filtres): array
{
    /*
     * Deux sous-requêtes calculent les chiffres AVANT la jointure :
     *   - sv : nombre de services actifs et tarif le plus bas ;
     *   - av : nombre d'avis et note moyenne réelle.
     * Joindre directement service ET avis multiplierait les lignes
     * (2 services × 3 avis = 6 lignes) et fausserait les COUNT.
     *
     * La note est recalculée à partir de la table avis (AVG) : c'est la
     * source fiable, alors que la colonne reparateur.note_moyenne n'est
     * mise à jour par aucune page du site pour l'instant.
     *
     * INNER JOIN sur sv : un réparateur sans aucun service actif n'a rien
     * à proposer, il n'apparaît pas sur la carte.
     */
    if ($filtres['position'] !== null) {
        /*
         * FORMULE DE HAVERSINE — distance « à vol d'oiseau » entre deux
         * points GPS, en tenant compte de la courbure de la Terre.
         *
         *   a = sin²(Δlat / 2) + cos(lat1) × cos(lat2) × sin²(Δlng / 2)
         *   distance = 2 × R × asin(√a)        avec R = 6371 km (rayon terrestre)
         *
         * - RADIANS() : les fonctions trigonométriques de MySQL attendent
         *   des radians, alors que les coordonnées GPS sont en degrés.
         * - LEAST(1, …) : protège ASIN() d'une valeur à peine supérieure à 1
         *   due aux arrondis des nombres à virgule (ASIN(1.0000001) = NULL).
         * - ROUND(…, 1) : distance arrondie au dixième de km.
         *
         * Pourquoi :lat1 ET :lat2 pour la même valeur ? Avec les « vraies »
         * requêtes préparées (ATTR_EMULATE_PREPARES = false, voir database.php),
         * PDO interdit d'utiliser deux fois le même marqueur nommé.
         *
         * HAVING (et pas WHERE) : « distance » est un alias calculé dans le
         * SELECT ; WHERE est évalué avant le calcul, HAVING après.
         */
        $sql = "SELECT r.id_reparateur AS id,
                       u.prenom, u.nom, u.ville, u.code_postal,
                       r.latitude, r.longitude, r.rayon_intervention,
                       av.note_moyenne,
                       COALESCE(av.nb_avis, 0) AS nb_avis,
                       sv.nb_services,
                       sv.prix_min,
                       ROUND(6371 * 2 * ASIN(LEAST(1, SQRT(
                             POW(SIN(RADIANS(r.latitude - :lat1) / 2), 2)
                           + COS(RADIANS(:lat2)) * COS(RADIANS(r.latitude))
                           * POW(SIN(RADIANS(r.longitude - :lng) / 2), 2)
                       ))), 1) AS distance
                FROM reparateur r
                INNER JOIN utilisateur u ON u.id_user = r.id_reparateur
                INNER JOIN (SELECT id_reparateur,
                                   COUNT(*)   AS nb_services,
                                   MIN(tarif) AS prix_min
                            FROM service
                            WHERE actif = 1
                            GROUP BY id_reparateur) sv ON sv.id_reparateur = r.id_reparateur
                LEFT JOIN (SELECT s.id_reparateur,
                                  COUNT(a.id_avis)     AS nb_avis,
                                  ROUND(AVG(a.note), 1) AS note_moyenne
                           FROM avis a
                           INNER JOIN reservation res ON res.id_reservation = a.id_reservation
                           INNER JOIN service     s   ON s.id_service       = res.id_service
                           GROUP BY s.id_reparateur) av ON av.id_reparateur = r.id_reparateur
                WHERE r.disponible = 1
                  AND r.statut_validation = 'valide'
                  AND r.id_reparateur <> :exclure_id
                  AND r.latitude  IS NOT NULL
                  AND r.longitude IS NOT NULL
                  AND COALESCE(av.note_moyenne, 0) >= :note_min
                  AND sv.prix_min <= :tarif_max
                HAVING distance <= :rayon
                ORDER BY distance ASC, av.note_moyenne DESC";

        $params = [
            ':lat1'      => $filtres['position']['lat'],
            ':lat2'      => $filtres['position']['lat'],
            ':lng'       => $filtres['position']['lng'],
            ':rayon'     => $filtres['rayon'],
        ];
    } else {
        // Sans position : pas de distance ni de rayon, tri par note
        $sql = "SELECT r.id_reparateur AS id,
                       u.prenom, u.nom, u.ville, u.code_postal,
                       r.latitude, r.longitude, r.rayon_intervention,
                       av.note_moyenne,
                       COALESCE(av.nb_avis, 0) AS nb_avis,
                       sv.nb_services,
                       sv.prix_min,
                       NULL AS distance
                FROM reparateur r
                INNER JOIN utilisateur u ON u.id_user = r.id_reparateur
                INNER JOIN (SELECT id_reparateur,
                                   COUNT(*)   AS nb_services,
                                   MIN(tarif) AS prix_min
                            FROM service
                            WHERE actif = 1
                            GROUP BY id_reparateur) sv ON sv.id_reparateur = r.id_reparateur
                LEFT JOIN (SELECT s.id_reparateur,
                                  COUNT(a.id_avis)     AS nb_avis,
                                  ROUND(AVG(a.note), 1) AS note_moyenne
                           FROM avis a
                           INNER JOIN reservation res ON res.id_reservation = a.id_reservation
                           INNER JOIN service     s   ON s.id_service       = res.id_service
                           GROUP BY s.id_reparateur) av ON av.id_reparateur = r.id_reparateur
                WHERE r.disponible = 1
                  AND r.statut_validation = 'valide'
                  AND r.id_reparateur <> :exclure_id
                  AND r.latitude  IS NOT NULL
                  AND r.longitude IS NOT NULL
                  AND COALESCE(av.note_moyenne, 0) >= :note_min
                  AND sv.prix_min <= :tarif_max
                ORDER BY av.note_moyenne DESC, u.nom ASC";
        $params = [];
    }

    $params[':note_min']   = $filtres['note_min'];
    $params[':tarif_max']  = $filtres['tarif_max'] ?? RECHERCHE_TARIF_PLAFOND;
    // Id à exclure (l'utilisateur connecté, s'il est aussi réparateur).
    // Fixé par le code appelant à partir de la SESSION, jamais lu dans l'URL.
    $params[':exclure_id'] = (int) ($filtres['exclure_id'] ?? 0);

    $req = $pdo->prepare($sql);
    $req->execute($params);

    // Conversion explicite des types : PDO renvoie les DECIMAL sous forme
    // de chaînes. En JSON, on veut de vrais nombres (48.85, pas "48.85").
    $resultats = [];
    foreach ($req->fetchAll() as $l) {
        $resultats[] = [
            'id'                 => (int) $l['id'],
            'prenom'             => $l['prenom'],
            'nom'                => $l['nom'],
            'ville'              => $l['ville'],
            'code_postal'        => $l['code_postal'],
            'latitude'           => (float) $l['latitude'],
            'longitude'          => (float) $l['longitude'],
            'note_moyenne'       => $l['note_moyenne'] === null ? null : (float) $l['note_moyenne'],
            'nb_avis'            => (int) $l['nb_avis'],
            'rayon_intervention' => (int) $l['rayon_intervention'],
            'nb_services'        => (int) $l['nb_services'],
            'prix_min'           => (float) $l['prix_min'],
            'distance'           => $l['distance'] === null ? null : (float) $l['distance'],
        ];
    }
    return $resultats;
}
