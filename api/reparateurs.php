<?php
/**
 * Fair'repair — API : réparateurs disponibles (JSON)
 * --------------------------------------------------
 * Appelée en JavaScript (fetch) par la carte du tableau de bord client,
 * au chargement puis toutes les 30 secondes.
 *
 * GET api/reparateurs.php?lat=48.85&lng=2.35&rayon=5&note_min=4&tarif_max=30
 *   Tous les paramètres sont facultatifs (lat et lng vont ensemble).
 *
 * Réponses :
 *   200 {"nombre": 3, "reparateurs": [...], "filtres": {...}, "genere_a": "..."}
 *   400 {"erreur": "..."}   paramètres invalides
 *   401 {"erreur": "..."}   non connecté ou session expirée
 *   403 {"erreur": "..."}   connecté, mais pas en tant que client
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../recherche-reparateurs.php';

header('Content-Type: application/json; charset=utf-8');
// Les positions changent (disponibilité) : le navigateur ne doit rien mettre en cache
header('Cache-Control: no-store');

/** Envoie une réponse JSON puis arrête le script. */
function repondreJson(array $donnees, int $codeHttp = 200): never
{
    http_response_code($codeHttp);
    // JSON_UNESCAPED_UNICODE : « Défense » reste lisible au lieu de « Défense »
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- CONTRÔLE D'ACCÈS ---
// On applique les MÊMES règles qu'exigerRole('client') (auth.php), mais en
// répondant en JSON. exigerRole() n'est pas appelée directement ici, pour
// deux raisons :
//   1. elle répond par une redirection vers une page HTML (ou un 403 en
//      HTML), que le JavaScript de la carte ne saurait pas interpréter ;
//   2. elle remet à zéro le compteur d'inactivité. Or la carte appelle
//      cette API toutes les 30 secondes : la session n'expirerait JAMAIS
//      tant que l'onglet reste ouvert. Ici, on VÉRIFIE l'inactivité sans
//      la prolonger : seule une vraie action de l'utilisateur la prolonge.
if (!estConnecte()) {
    repondreJson(['erreur' => "Vous devez être connecté."], 401);
}
if (isset($_SESSION['derniere_activite']) && time() - $_SESSION['derniere_activite'] > 30 * 60) {
    repondreJson(['erreur' => "Votre session a expiré. Rechargez la page pour vous reconnecter."], 401);
}
// Rôle ACTIF (session) : les rôles sont relus en base à chaque page
// protégée ; l'API, appelée toutes les 30 s, se contente de la session.
if (roleActif() !== 'client' || !aRole('client')) {
    repondreJson(['erreur' => "Accès réservé aux clients."], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    repondreJson(['erreur' => "Méthode non autorisée."], 405);
}

// --- Validation des filtres (conversion de type + bornes) ---
$lecture = lireFiltresRecherche($_GET);
if (!empty($lecture['erreurs'])) {
    repondreJson(['erreur' => implode(' ', $lecture['erreurs'])], 400);
}
$filtres = $lecture['filtres'];
// Un utilisateur client ET réparateur ne se voit pas sur la carte
$filtres['exclure_id'] = (int) $_SESSION['id_user'];

try {
    $reparateurs = rechercherReparateurs($pdo, $filtres);
} catch (PDOException $e) {
    // On journalise le détail, mais on ne l'envoie jamais au navigateur
    error_log('API reparateurs : ' . $e->getMessage());
    repondreJson(['erreur' => "Erreur technique, réessayez dans un instant."], 500);
}

repondreJson([
    'nombre'      => count($reparateurs),
    'reparateurs' => $reparateurs,
    // Filtres réellement appliqués, après validation et bornage
    'filtres'     => array_diff_key($filtres, ['exclure_id' => true]),
    'genere_a'    => date('c'),
]);
