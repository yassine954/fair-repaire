<?php
/**
 * Fair'repair — Historique des connexions (ADMINISTRATEUR)
 * --------------------------------------------------------
 * Consultation du journal tentatives_connexion :
 *   1. chiffres des 30 derniers jours ;
 *   2. panneau « Activité suspecte » (24 dernières heures) ;
 *   3. filtres (formulaire GET : une vue filtrée peut être mise en favori
 *      ou partagée par simple copie de l'adresse) ;
 *   4. tableau paginé (50 lignes par page) ;
 *   5. export CSV de la vue filtrée (POST + jeton CSRF).
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/affichage.php';

// --- ACCÈS RÉSERVÉ À L'ADMINISTRATEUR ---
// Tout premier traitement, avant le moindre affichage : un client ou un
// réparateur reçoit une erreur 403, un visiteur non connecté est renvoyé
// vers la page de connexion. Le journal contient des adresses IP et des
// e-mails : ce sont des données personnelles, d'accès restreint (RGPD).
exigerRole('admin');

const LIGNES_PAR_PAGE = 50;
const SEUIL_ECHECS_IP     = 10;   // échecs en 24 h depuis une même IP
const SEUIL_ECHECS_COMPTE = 5;    // échecs suivis d'une réussite en 24 h
const RESULTATS = [
    'tous'   => 'Tous',
    'succes' => 'Réussies',
    'echecs' => 'Échouées ou refusées',
];

// ============================================================
//  DÉCONNEXION (POST + jeton CSRF)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deconnexion') {
    if (verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        enregistrerTentative($pdo, $_SESSION['email'], $_SERVER['REMOTE_ADDR'] ?? '',
                             true, 'deconnexion', (int) $_SESSION['id_user']);
        supprimerJetonSouvenir($pdo);
        fermerSession();
        header('Location: connexion.php?msg=deconnecte');
        exit;
    }
    definirFlash('erreur', "Requête invalide. Merci de recharger la page.");
    header('Location: historique-connexions.php');
    exit;
}

// ============================================================
//  FILTRES (GET) : lecture, validation, conversion
// ============================================================

/** Texte lu dans l'URL (une valeur envoyée sous forme de tableau est ignorée). */
function texteGet(string $cle, int $longueurMax): string
{
    $valeur = $_GET[$cle] ?? '';
    return is_string($valeur) ? mb_substr(trim($valeur), 0, $longueurMax) : '';
}

/**
 * Date AAAA-MM-JJ valide, ou null.
 * createFromFormat() accepte « 2026-02-31 » (qu'il transforme en 3 mars) :
 * on vérifie donc que la date relue est identique à la saisie.
 */
function dateGet(string $saisie): ?DateTimeImmutable
{
    if ($saisie === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $saisie);
    return ($date && $date->format('Y-m-d') === $saisie) ? $date : null;
}

$filtres = [
    'email'    => texteGet('email', 150),
    'motif'    => texteGet('motif', 30),
    'resultat' => texteGet('resultat', 10),
    'du'       => texteGet('du', 10),
    'au'       => texteGet('au', 10),
    'ip'       => texteGet('ip', 45),
];
$avertissements = [];

// Listes fermées : toute valeur inconnue est ramenée à « tous »
if (!array_key_exists($filtres['motif'], MOTIFS_CONNEXION)) {
    $filtres['motif'] = '';
}
if (!array_key_exists($filtres['resultat'], RESULTATS)) {
    $filtres['resultat'] = 'tous';
}

$du = dateGet($filtres['du']);
$au = dateGet($filtres['au']);
if ($filtres['du'] !== '' && !$du) {
    $avertissements[] = "La date de début est invalide : elle a été ignorée.";
    $filtres['du'] = '';
}
if ($filtres['au'] !== '' && !$au) {
    $avertissements[] = "La date de fin est invalide : elle a été ignorée.";
    $filtres['au'] = '';
}
if ($du && $au && $du > $au) {
    [$du, $au] = [$au, $du];
    [$filtres['du'], $filtres['au']] = [$filtres['au'], $filtres['du']];
    $avertissements[] = "Les dates étaient inversées : elles ont été remises dans l'ordre.";
}

// Adresse IP : IPv4 ou IPv6 valide, sinon ignorée
if ($filtres['ip'] !== '' && filter_var($filtres['ip'], FILTER_VALIDATE_IP) === false) {
    $avertissements[] = "L'adresse IP saisie est invalide : elle a été ignorée.";
    $filtres['ip'] = '';
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;

// ============================================================
//  CONSTRUCTION DU WHERE (entièrement paramétré)
// ============================================================
// Chaque filtre actif ajoute :
//   - UNE condition, écrite ici dans le code (jamais la saisie elle-même) ;
//   - UNE valeur dans $params, liée ensuite à son marqueur.
// Les conditions sont jointes par AND. Quelle que soit la combinaison de
// filtres choisie, aucune donnée saisie n'entre dans le texte SQL.
$conditions = [];
$params     = [];

if ($filtres['email'] !== '') {
    $conditions[] = 't.email LIKE :email';
    // « % » et « _ » sont des jokers de LIKE : on les neutralise (avec « \ »)
    // pour qu'une recherche comme « jean_dupont » soit faite telle quelle.
    // Les % du début et de la fin sont ajoutés ICI, en PHP, puis la valeur
    // COMPLÈTE est liée au marqueur : rien n'est concaténé dans la requête.
    $params[':email'] = '%' . addcslashes($filtres['email'], '%_\\') . '%';
}
if ($filtres['motif'] !== '') {
    $conditions[] = 't.motif = :motif';
    $params[':motif'] = $filtres['motif'];
}
if ($filtres['resultat'] !== 'tous') {
    $conditions[] = 't.reussie = :reussie';
    $params[':reussie'] = $filtres['resultat'] === 'succes' ? 1 : 0;
}
if ($du) {
    $conditions[] = 't.`timestamp` >= :du';
    $params[':du'] = $du->format('Y-m-d 00:00:00');
}
if ($au) {
    // Le jour de fin est INCLUS : on prend tout ce qui précède le lendemain à 0 h
    $conditions[] = 't.`timestamp` < :au';
    $params[':au'] = $au->modify('+1 day')->format('Y-m-d 00:00:00');
}
if ($filtres['ip'] !== '') {
    $conditions[] = 't.ip = :ip';
    $params[':ip'] = $filtres['ip'];
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

/** Adresse de cette page avec les filtres actifs (et d'éventuels paramètres en plus). */
function urlHistorique(array $filtres, array $plus = []): string
{
    $actifs = array_filter($filtres, fn($v) => $v !== '' && $v !== 'tous');
    $requete = http_build_query(array_merge($actifs, $plus));
    return 'historique-connexions.php' . ($requete !== '' ? '?' . $requete : '');
}

// Colonnes communes au tableau et à l'export. Les rôles sont déduits des
// TABLES (client / reparateur / administrateur), comme dans auth.php, et non
// de utilisateur.type_user.
const COLONNES_JOURNAL = "
    t.id_tentative, t.email, t.id_user, t.ip, t.navigateur, t.reussie, t.motif, t.`timestamp`,
    u.prenom, u.nom,
    EXISTS(SELECT 1 FROM client         c WHERE c.id_client = t.id_user) AS est_client,
    EXISTS(SELECT 1 FROM administrateur a WHERE a.id_admin  = t.id_user) AS est_admin,
    (SELECT r.statut_validation FROM reparateur r WHERE r.id_reparateur = t.id_user) AS statut_reparateur";

/** Rôles d'une ligne du journal : ['Admin', 'Réparateur', 'Client']. */
function rolesLigne(array $l): array
{
    $roles = [];
    if ($l['est_admin']) {
        $roles[] = 'Admin';
    }
    if ($l['statut_reparateur'] === 'valide') {
        $roles[] = 'Réparateur';
    } elseif ($l['statut_reparateur']) {
        $roles[] = 'Réparateur (' . ($l['statut_reparateur'] === 'en_attente' ? 'en attente' : 'refusé') . ')';
    }
    if ($l['est_client']) {
        $roles[] = 'Client';
    }
    return $roles;
}

// ============================================================
//  EXPORT CSV de la vue filtrée (POST + jeton CSRF)
// ============================================================

/**
 * Neutralise une cellule avant de l'écrire dans le CSV.
 *
 * INJECTION DE FORMULE (« CSV injection ») : l'e-mail et le User-Agent sont
 * écrits par le visiteur. Une valeur comme =HYPERLINK("http://pirate…")
 * serait EXÉCUTÉE comme une formule à l'ouverture du fichier dans Excel ou
 * LibreOffice. Une apostrophe placée devant oblige le tableur à l'afficher
 * comme du simple texte.
 */
function celluleCsv(?string $valeur): string
{
    $valeur = (string) $valeur;
    if ($valeur !== '' && str_contains("=+-@\t\r", $valeur[0])) {
        return "'" . $valeur;
    }
    return $valeur;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'exporter_csv') {

    if (!verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        definirFlash('erreur', "Requête invalide. Merci de recharger la page.");
        header('Location: ' . urlHistorique($filtres));
        exit;
    }

    // La session n'est plus nécessaire : on la libère pour que l'administrateur
    // puisse continuer à naviguer pendant un long export.
    session_write_close();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="historique-connexions-' . date('Y-m-d-His') . '.csv"');
    header('Cache-Control: no-store');           // données personnelles : pas de copie en cache
    header('X-Content-Type-Options: nosniff');

    $sortie = fopen('php://output', 'w');

    // BOM UTF-8 (3 octets invisibles EF BB BF) en tout début de fichier.
    // PIÈGE CLASSIQUE : sans lui, Excel suppose l'encodage Windows-1252 et
    // affiche « Ã© » à la place de « é » dans tous les accents français.
    fwrite($sortie, "\xEF\xBB\xBF");

    // Séparateur « ; » : c'est celui qu'attend Excel en français (la virgule
    // y sert de séparateur décimal). Dernier paramètre '' : pas de caractère
    // d'échappement, les guillemets sont simplement doublés (format standard).
    $entetes = ['Date', 'E-mail', 'Prénom', 'Nom', 'Rôles', 'Adresse IP',
                'Navigateur', 'User-Agent', 'Réussie', 'Motif'];
    fputcsv($sortie, $entetes, ';', '"', '');

    // Requête NON tamponnée : les lignes sont lues et écrites une par une,
    // la mémoire reste stable même avec des dizaines de milliers de lignes.
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $req = $pdo->prepare("SELECT " . COLONNES_JOURNAL . "
                          FROM tentatives_connexion t
                          LEFT JOIN utilisateur u ON u.id_user = t.id_user
                          $where
                          ORDER BY t.`timestamp` DESC, t.id_tentative DESC");
    $req->execute($params);

    while ($l = $req->fetch()) {
        fputcsv($sortie, array_map('celluleCsv', [
            $l['timestamp'],
            $l['email'],
            $l['prenom'],
            $l['nom'],
            implode(', ', rolesLigne($l)),
            $l['ip'],
            navigateurLisible($l['navigateur']),
            $l['navigateur'],
            $l['reussie'] ? 'oui' : 'non',
            MOTIFS_CONNEXION[$l['motif']] ?? $l['motif'],
        ]), ';', '"', '');
    }

    fclose($sortie);
    exit;
}

// ============================================================
//  1. CHIFFRES DES 30 DERNIERS JOURS (une seule requête)
// ============================================================
// Agrégation conditionnelle : SUM(CASE WHEN … THEN 1 ELSE 0 END) compte les
// lignes qui remplissent une condition. Quatre compteurs en un seul passage
// sur la table, au lieu de quatre requêtes.
// Les déconnexions ne sont pas des tentatives : elles sont exclues.
$sql = "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN motif = 'succes' THEN 1 ELSE 0 END), 0) AS reussies,
            COALESCE(SUM(CASE WHEN reussie = 0      THEN 1 ELSE 0 END), 0) AS echecs,
            COUNT(DISTINCT CASE WHEN reussie = 0 THEN ip END)               AS ip_en_echec
        FROM tentatives_connexion
        WHERE `timestamp` >= NOW() - INTERVAL 30 DAY
          AND motif <> 'deconnexion'";
$stats = $pdo->query($sql)->fetch();

// ============================================================
//  2. ACTIVITÉ SUSPECTE (24 dernières heures)
// ============================================================

// a) Adresses IP avec au moins 10 échecs : rafale de tentatives (robot)
$req = $pdo->prepare("SELECT ip,
                             COUNT(*)              AS echecs,
                             COUNT(DISTINCT email) AS comptes,
                             MIN(`timestamp`)      AS debut,
                             MAX(`timestamp`)      AS fin
                      FROM tentatives_connexion
                      WHERE reussie = 0
                        AND `timestamp` >= NOW() - INTERVAL 24 HOUR
                      GROUP BY ip
                      HAVING COUNT(*) >= :seuil
                      ORDER BY echecs DESC");
$req->execute([':seuil' => SEUIL_ECHECS_IP]);
$ipSuspectes = $req->fetchAll();

// b) Comptes avec au moins 5 échecs SUIVIS d'une réussite : la signature
//    d'une attaque par force brute qui a fonctionné.
//    Pour chaque connexion réussie des dernières 24 h, on compte les échecs
//    sur le même e-mail qui la précèdent : depuis la réussite précédente
//    (sinon les mêmes échecs seraient comptés plusieurs fois), et au plus
//    sur les 24 heures d'avant.
$req = $pdo->prepare("SELECT *
                      FROM (
                          SELECT s.email, s.id_user, s.ip, s.`timestamp`,
                                 (SELECT COUNT(*)
                                  FROM tentatives_connexion f
                                  WHERE f.email   = s.email
                                    AND f.reussie = 0
                                    AND f.`timestamp` <  s.`timestamp`
                                    AND f.`timestamp` >= s.`timestamp` - INTERVAL 24 HOUR
                                    AND f.`timestamp` > COALESCE(
                                            (SELECT MAX(p.`timestamp`)
                                             FROM tentatives_connexion p
                                             WHERE p.email = s.email
                                               AND p.motif = 'succes'
                                               AND p.`timestamp` < s.`timestamp`),
                                            '1000-01-01')
                                 ) AS echecs_avant
                          FROM tentatives_connexion s
                          WHERE s.motif = 'succes'
                            AND s.`timestamp` >= NOW() - INTERVAL 24 HOUR
                      ) AS reussites
                      WHERE echecs_avant >= :seuil
                      ORDER BY `timestamp` DESC");
$req->execute([':seuil' => SEUIL_ECHECS_COMPTE]);
$forceBruteReussie = $req->fetchAll();

// c) Connexions réussies depuis une adresse IP jamais vue pour ce compte.
//    « Vue » = une connexion ou une déconnexion réussie (reussie = 1) déjà
//    enregistrée depuis cette IP. Les comptes sans aucun historique sont
//    écartés, sinon toute première connexion serait signalée.
$req = $pdo->prepare("SELECT s.email, s.id_user, s.ip, s.navigateur, s.`timestamp`,
                             (SELECT p.ip
                              FROM tentatives_connexion p
                              WHERE p.id_user = s.id_user
                                AND p.reussie = 1
                                AND p.`timestamp` < s.`timestamp`
                              ORDER BY p.`timestamp` DESC
                              LIMIT 1) AS ip_habituelle
                      FROM tentatives_connexion s
                      WHERE s.motif = 'succes'
                        AND s.id_user IS NOT NULL
                        AND s.`timestamp` >= NOW() - INTERVAL 24 HOUR
                        AND NOT EXISTS (SELECT 1 FROM tentatives_connexion p
                                        WHERE p.id_user = s.id_user
                                          AND p.reussie = 1
                                          AND p.ip      = s.ip
                                          AND p.`timestamp` < s.`timestamp`)
                        AND EXISTS     (SELECT 1 FROM tentatives_connexion p
                                        WHERE p.id_user = s.id_user
                                          AND p.reussie = 1
                                          AND p.`timestamp` < s.`timestamp`)
                      ORDER BY s.`timestamp` DESC");
$req->execute();
$nouvellesIp = $req->fetchAll();

$nbAlertes = count($ipSuspectes) + count($forceBruteReussie) + count($nouvellesIp);

// ============================================================
//  3. RÉSULTATS FILTRÉS ET PAGINÉS
// ============================================================

// Nombre total de lignes : MÊME clause WHERE, mêmes paramètres
$req = $pdo->prepare("SELECT COUNT(*) FROM tentatives_connexion t $where");
$req->execute($params);
$total = (int) $req->fetchColumn();

$nbPages  = max(1, (int) ceil($total / LIGNES_PAR_PAGE));
$page     = min($page, $nbPages);
$decalage = ($page - 1) * LIGNES_PAR_PAGE;

$req = $pdo->prepare("SELECT " . COLONNES_JOURNAL . "
                      FROM tentatives_connexion t
                      LEFT JOIN utilisateur u ON u.id_user = t.id_user
                      $where
                      ORDER BY t.`timestamp` DESC, t.id_tentative DESC
                      LIMIT :limite OFFSET :decalage");
foreach ($params as $marqueur => $valeur) {
    $req->bindValue($marqueur, $valeur);
}
// LIMIT et OFFSET doivent recevoir de VRAIS ENTIERS : bindValue(..., PDO::PARAM_INT).
// Avec execute([...]), PDO transmet chaque valeur comme du TEXTE. Si
// l'émulation des requêtes préparées est active (réglage PAR DÉFAUT de PDO),
// il écrit alors « LIMIT '50' » entre guillemets : erreur de syntaxe 1064.
// Ici l'émulation est désactivée (database.php) et MySQL tolérerait le texte,
// mais PARAM_INT est la seule écriture correcte dans les deux réglages : elle
// ne cassera pas si quelqu'un modifie database.php un jour.
$req->bindValue(':limite',   LIGNES_PAR_PAGE, PDO::PARAM_INT);
$req->bindValue(':decalage', $decalage,       PDO::PARAM_INT);
$req->execute();
$lignes = $req->fetchAll();

$premiere = $total === 0 ? 0 : $decalage + 1;
$derniere = $decalage + count($lignes);

// Messages laissés par une redirection (ex. jeton CSRF invalide)
$flash = lireFlash();

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des connexions — Fair'repair</title>
    <style>
    /* =========================================================
       Fair'repair — styles communs
       ========================================================= */
    :root {
        --vert:         #0F766E;
        --vert-fonce:   #0B5A54;
        --vert-clair:   #E6F4F2;
        --bleu:         #1E50D4;
        --bleu-clair:   #E8EEFC;
        --violet:       #6D28D9;
        --violet-clair: #F1EBFD;
        --ambre:        #B45309;
        --ambre-clair:  #FDF3E3;
        --texte:        #0D1829;
        --texte-doux:   #5A7290;
        --bordure:      #D6E0EE;
        --fond:         #F5F8FC;
        --blanc:        #FFFFFF;
        --rouge:        #C1272D;
        --rouge-clair:  #FDECEC;
        --succes:       #14804A;
        --succes-clair: #E7F6EE;
        --rayon:        10px;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        background-color: var(--fond);
        color: var(--texte);
        line-height: 1.6;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }

    /* ---------- En-tête ---------- */
    .entete {
        background-color: var(--blanc);
        border-bottom: 1px solid var(--bordure);
        padding: 14px 32px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    .logo { font-size: 1.4rem; font-weight: 700; color: var(--vert); text-decoration: none; }
    .logo span { color: var(--texte); }
    .entete nav { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
    .entete nav a { color: var(--texte-doux); text-decoration: none; font-weight: 500; font-size: 0.92rem; }
    .entete nav a:hover { color: var(--vert); }
    .entete nav a.actif { color: var(--vert); font-weight: 600; }
    .badge-user {
        background-color: var(--violet-clair);
        color: var(--violet);
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    .form-deconnexion { display: inline; margin: 0; }
    .lien-bouton {
        background: none; border: none; padding: 0;
        font-family: inherit; font-size: 0.92rem; font-weight: 500;
        color: var(--texte-doux); cursor: pointer;
    }
    .lien-bouton:hover { color: var(--rouge); text-decoration: underline; }

    /* ---------- Conteneur ---------- */
    .conteneur {
        flex: 1;
        width: 100%;
        max-width: 1180px;
        margin: 0 auto;
        padding: 32px 20px 48px;
    }
    h1 { font-size: 1.7rem; margin-bottom: 6px; }
    h2 { font-size: 1.15rem; margin-bottom: 14px; }
    h3 { font-size: 0.95rem; margin: 16px 0 8px; }
    .sous-titre { color: var(--texte-doux); margin-bottom: 26px; font-size: 0.95rem; }

    /* ---------- Alertes ---------- */
    .alerte { padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.92rem; }
    .alerte-erreur { background-color: var(--rouge-clair); border-left: 4px solid var(--rouge); color: var(--rouge); }
    .alerte-succes { background-color: var(--succes-clair); border-left: 4px solid var(--succes); color: var(--succes); }
    .alerte-info   { background-color: var(--bleu-clair);  border-left: 4px solid var(--bleu);  color: var(--bleu); }

    /* ---------- Boutons ---------- */
    .bouton-petit {
        display: inline-block;
        padding: 8px 14px;
        border-radius: 6px;
        font-size: 0.86rem;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid var(--bordure);
        background-color: var(--blanc);
        color: var(--texte);
        cursor: pointer;
        font-family: inherit;
        white-space: nowrap;
        line-height: 1.4;
    }
    .bouton-petit:hover { background-color: var(--fond); }
    .bouton-petit.primaire { background-color: var(--vert); border-color: var(--vert); color: var(--blanc); }
    .bouton-petit.primaire:hover { background-color: var(--vert-fonce); }

    /* ---------- Cartes de statistiques ---------- */
    .grille-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 16px;
        margin-bottom: 26px;
    }
    .stat {
        background-color: var(--blanc);
        border: 1px solid var(--bordure);
        border-radius: var(--rayon);
        padding: 18px 20px;
        border-top: 3px solid var(--bleu);
    }
    .stat.vert   { border-top-color: var(--vert); }
    .stat.rouge  { border-top-color: var(--rouge); }
    .stat.ambre  { border-top-color: var(--ambre); }
    .stat-valeur { font-size: 1.9rem; font-weight: 700; line-height: 1.2; }
    .stat-libelle { font-size: 0.82rem; color: var(--texte-doux); text-transform: uppercase; letter-spacing: 0.4px; }

    /* ---------- Sections ---------- */
    .bloc {
        background-color: var(--blanc);
        border: 1px solid var(--bordure);
        border-radius: var(--rayon);
        padding: 22px 24px;
        margin-bottom: 24px;
    }
    .bloc-entete {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 14px; flex-wrap: wrap; gap: 10px;
    }
    .bloc-entete h2 { margin-bottom: 0; }

    /* ---------- Activité suspecte ---------- */
    .bloc.suspect { border-left: 4px solid var(--ambre); }
    .bloc.suspect.calme { border-left-color: var(--vert); }
    .liste-suspecte { list-style: none; }
    .liste-suspecte li {
        display: flex; justify-content: space-between; align-items: center;
        gap: 10px; flex-wrap: wrap;
        padding: 9px 0;
        border-bottom: 1px solid var(--bordure);
        font-size: 0.9rem;
    }
    .liste-suspecte li:last-child { border-bottom: none; }

    /* ---------- Filtres ---------- */
    .filtres {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 14px;
        align-items: end;
    }
    .champ { display: flex; flex-direction: column; min-width: 0; }
    label { font-size: 0.82rem; font-weight: 600; margin-bottom: 4px; }
    input[type="text"], input[type="date"], select {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid var(--bordure);
        border-radius: 6px;
        font-size: 0.9rem;
        font-family: inherit;
        background-color: var(--blanc);
        color: var(--texte);
    }
    input:focus, select:focus {
        outline: none;
        border-color: var(--vert);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15);
    }
    .filtres-actions { display: flex; gap: 8px; flex-wrap: wrap; grid-column: 1 / -1; }   /* boutons sur leur propre ligne */

    /* ---------- Tableau ---------- */
    .barre-resultats {
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap; gap: 10px; margin-bottom: 12px;
    }
    .zone-tableau { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .zone-tableau table { min-width: 860px; }   /* mobile : défilement horizontal */
    thead th {
        text-align: left;
        padding: 10px 12px;
        background-color: var(--fond);
        border-bottom: 2px solid var(--bordure);
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: var(--texte-doux);
        white-space: nowrap;
    }
    tbody td { padding: 10px 12px; border-bottom: 1px solid var(--bordure); vertical-align: top; }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:hover { background-color: #FAFCFF; }
    .cellule-sec { color: var(--texte-doux); font-size: 0.83rem; }
    .lien-filtre { color: inherit; text-decoration: none; border-bottom: 1px dotted var(--texte-doux); }
    .lien-filtre:hover { color: var(--vert); border-bottom-color: var(--vert); }
    .email { font-weight: 600; overflow-wrap: anywhere; }
    .date { white-space: nowrap; }

    /* ---------- Étiquettes ---------- */
    .etiquette {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.76rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .etiquette.vert   { background-color: var(--succes-clair); color: var(--succes); }
    .etiquette.rouge  { background-color: var(--rouge-clair);  color: var(--rouge); }
    .etiquette.ambre  { background-color: var(--ambre-clair);  color: var(--ambre); }
    .etiquette.gris   { background-color: var(--fond); color: var(--texte-doux); border: 1px solid var(--bordure); }
    .etiquette.bleu   { background-color: var(--bleu-clair);   color: var(--bleu); }
    .etiquette.violet { background-color: var(--violet-clair); color: var(--violet); }
    .roles { margin-top: 3px; display: flex; gap: 4px; flex-wrap: wrap; }

    /* ---------- Pagination ---------- */
    .pagination { display: flex; gap: 6px; flex-wrap: wrap; justify-content: center; margin-top: 16px; }
    .pagination a, .pagination span {
        padding: 6px 11px;
        border: 1px solid var(--bordure);
        border-radius: 6px;
        font-size: 0.86rem;
        text-decoration: none;
        color: var(--texte);
        background-color: var(--blanc);
    }
    .pagination a:hover { border-color: var(--vert); color: var(--vert); }
    .pagination .courante { background-color: var(--vert); border-color: var(--vert); color: var(--blanc); font-weight: 600; }
    .pagination .inactif { color: var(--texte-doux); background-color: var(--fond); }

    /* ---------- État vide ---------- */
    .vide-etat { text-align: center; padding: 30px 20px; color: var(--texte-doux); }
    .vide-etat p { font-size: 0.93rem; }

    /* ---------- Pied de page ---------- */
    .pied {
        background-color: var(--blanc);
        border-top: 1px solid var(--bordure);
        text-align: center;
        padding: 18px;
        font-size: 0.82rem;
        color: var(--texte-doux);
    }

    /* ---------- Mobile ---------- */
    @media (max-width: 640px) {
        .entete { padding: 12px 16px; }
        .bloc { padding: 18px 16px; }
        .filtres { grid-template-columns: 1fr 1fr; }
    }
    </style>
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav>
        <a href="dashboard-admin.php">Tableau de bord</a>
        <a href="historique-connexions.php" class="actif">Historique des connexions</a>
        <span class="badge-user">🛡️ <?= e($_SESSION['prenom']) ?></span>
        <form method="post" action="historique-connexions.php" class="form-deconnexion">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="deconnexion">
            <button type="submit" class="lien-bouton">Déconnexion</button>
        </form>
    </nav>
</header>

<main class="conteneur">

    <h1>Historique des connexions</h1>
    <p class="sous-titre">
        Toutes les tentatives de connexion, réussies, échouées ou refusées, et les déconnexions.
        Conservation : 12 mois.
    </p>

    <?php if ($flash): ?>
        <div class="alerte <?= $flash['type'] === 'succes' ? 'alerte-succes' : 'alerte-erreur' ?>"><?= e($flash['texte']) ?></div>
    <?php endif; ?>
    <?php foreach ($avertissements as $avertissement): ?>
        <div class="alerte alerte-info"><?= e($avertissement) ?></div>
    <?php endforeach; ?>

    <!-- ============ 1. CHIFFRES DES 30 DERNIERS JOURS ============ -->
    <div class="grille-stats">
        <div class="stat">
            <div class="stat-valeur"><?= (int) $stats['total'] ?></div>
            <div class="stat-libelle">Tentatives (30 j)</div>
        </div>
        <div class="stat vert">
            <div class="stat-valeur"><?= (int) $stats['reussies'] ?></div>
            <div class="stat-libelle">Connexions réussies</div>
        </div>
        <div class="stat rouge">
            <div class="stat-valeur"><?= (int) $stats['echecs'] ?></div>
            <div class="stat-libelle">Échecs et refus</div>
        </div>
        <div class="stat ambre">
            <div class="stat-valeur"><?= (int) $stats['ip_en_echec'] ?></div>
            <div class="stat-libelle">IP distinctes en échec</div>
        </div>
    </div>

    <!-- ============ 2. ACTIVITÉ SUSPECTE (24 h) ============ -->
    <section class="bloc suspect <?= $nbAlertes === 0 ? 'calme' : '' ?>">
        <div class="bloc-entete">
            <h2>Activité suspecte <span class="cellule-sec">— 24 dernières heures</span></h2>
            <?php if ($nbAlertes > 0): ?>
                <span class="etiquette ambre"><?= $nbAlertes ?> signal<?= $nbAlertes > 1 ? 'ements' : 'ement' ?></span>
            <?php endif; ?>
        </div>

        <?php if ($nbAlertes === 0): ?>
            <p class="cellule-sec">✔ Rien de suspect ces dernières 24 heures.</p>
        <?php endif; ?>

        <?php if ($ipSuspectes): ?>
            <h3>Adresses IP avec au moins <?= SEUIL_ECHECS_IP ?> échecs</h3>
            <ul class="liste-suspecte">
                <?php foreach ($ipSuspectes as $s): ?>
                    <li>
                        <span>
                            <strong><?= e($s['ip']) ?></strong> :
                            <?= (int) $s['echecs'] ?> échecs sur <?= (int) $s['comptes'] ?> compte<?= $s['comptes'] > 1 ? 's' : '' ?>
                            <span class="cellule-sec">
                                (de <?= e(heureFr($s['debut'])) ?> à <?= e(heureFr($s['fin'])) ?>)
                            </span>
                        </span>
                        <a href="<?= e(urlHistorique(['ip' => $s['ip']])) ?>" class="bouton-petit">Voir l'historique</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($forceBruteReussie): ?>
            <h3>Comptes ouverts après au moins <?= SEUIL_ECHECS_COMPTE ?> échecs (force brute réussie ?)</h3>
            <ul class="liste-suspecte">
                <?php foreach ($forceBruteReussie as $s): ?>
                    <li>
                        <span>
                            <strong><?= e($s['email']) ?></strong> :
                            <?= (int) $s['echecs_avant'] ?> échecs, puis connexion réussie le
                            <?= dateFr($s['timestamp'], true) ?> depuis <strong><?= e($s['ip']) ?></strong>
                        </span>
                        <a href="<?= e(urlHistorique(['email' => $s['email']])) ?>" class="bouton-petit">Voir l'historique</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($nouvellesIp): ?>
            <h3>Connexions depuis une adresse IP jamais vue pour le compte</h3>
            <ul class="liste-suspecte">
                <?php foreach ($nouvellesIp as $s): ?>
                    <li>
                        <span>
                            <strong><?= e($s['email']) ?></strong> :
                            connexion le <?= dateFr($s['timestamp'], true) ?> depuis <strong><?= e($s['ip']) ?></strong>
                            <span class="cellule-sec" title="<?= e($s['navigateur']) ?>">
                                (<?= e(navigateurLisible($s['navigateur'])) ?> ;
                                adresse habituelle : <?= e($s['ip_habituelle'] ?? '—') ?>)
                            </span>
                        </span>
                        <a href="<?= e(urlHistorique(['email' => $s['email']])) ?>" class="bouton-petit">Voir l'historique</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <!-- ============ 3. FILTRES (formulaire GET : la vue filtrée garde son adresse) ============ -->
    <section class="bloc">
        <form method="get" action="historique-connexions.php" class="filtres">
            <div class="champ">
                <label for="f-email">E-mail contient</label>
                <input type="text" id="f-email" name="email" maxlength="150"
                       placeholder="ex. martin" value="<?= e($filtres['email']) ?>">
            </div>
            <div class="champ">
                <label for="f-motif">Motif</label>
                <select id="f-motif" name="motif">
                    <option value="">Tous</option>
                    <?php foreach (MOTIFS_CONNEXION as $valeur => $libelle): ?>
                        <option value="<?= e($valeur) ?>" <?= $filtres['motif'] === $valeur ? 'selected' : '' ?>>
                            <?= e($libelle) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="champ">
                <label for="f-resultat">Résultat</label>
                <select id="f-resultat" name="resultat">
                    <?php foreach (RESULTATS as $valeur => $libelle): ?>
                        <option value="<?= e($valeur) ?>" <?= $filtres['resultat'] === $valeur ? 'selected' : '' ?>>
                            <?= e($libelle) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="champ">
                <label for="f-du">Du</label>
                <input type="date" id="f-du" name="du" value="<?= e($filtres['du']) ?>">
            </div>
            <div class="champ">
                <label for="f-au">Au</label>
                <input type="date" id="f-au" name="au" value="<?= e($filtres['au']) ?>">
            </div>
            <div class="champ">
                <label for="f-ip">Adresse IP (exacte)</label>
                <input type="text" id="f-ip" name="ip" maxlength="45"
                       placeholder="ex. 192.0.2.10" value="<?= e($filtres['ip']) ?>">
            </div>
            <div class="filtres-actions">
                <button type="submit" class="bouton-petit primaire">Filtrer</button>
                <a href="historique-connexions.php" class="bouton-petit">Réinitialiser</a>
            </div>
        </form>
    </section>

    <!-- ============ 4. RÉSULTATS ============ -->
    <section class="bloc">
        <div class="barre-resultats">
            <h2 style="margin-bottom:0">
                <?= $total ?> ligne<?= $total > 1 ? 's' : '' ?>
                <?php if ($total > 0): ?>
                    <span class="cellule-sec">— affichage de <?= $premiere ?> à <?= $derniere ?></span>
                <?php endif; ?>
            </h2>
            <?php if ($total > 0): ?>
                <!-- Export CSV : POST + jeton CSRF. L'adresse du formulaire garde
                     les filtres actifs : on exporte exactement la vue affichée. -->
                <form method="post" action="<?= e(urlHistorique($filtres)) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="exporter_csv">
                    <button type="submit" class="bouton-petit">⬇ Exporter en CSV</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($total === 0): ?>
            <div class="vide-etat">
                <p>Aucune tentative ne correspond à ces filtres.</p>
            </div>
        <?php else: ?>
            <div class="zone-tableau">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Utilisateur</th>
                            <th>Adresse IP</th>
                            <th>Navigateur</th>
                            <th>Motif</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lignes as $l): ?>
                        <?php
                        /*
                         * XSS : l'e-mail et le User-Agent sont des chaînes ÉCRITES PAR
                         * N'IMPORTE QUEL VISITEUR. Un attaquant qui n'a aucun accès au
                         * panneau d'administration peut quand même y déposer du code :
                         * il lui suffit d'envoyer le formulaire de connexion avec un
                         * e-mail comme <script>…</script>, ou un navigateur réglé sur
                         * un User-Agent piégé. La ligne est enregistrée telle quelle,
                         * puis affichée ICI, dans la page du compte le plus privilégié
                         * (XSS « stockée »). Tout passe donc par e(), y compris dans
                         * l'attribut title.
                         */
                        $roles = rolesLigne($l);
                        ?>
                        <tr>
                            <td class="date" title="<?= e($l['timestamp']) ?>"><?= dateFr($l['timestamp'], true) ?></td>
                            <td>
                                <a href="<?= e(urlHistorique(['email' => $l['email']])) ?>"
                                   class="lien-filtre email"><?= e($l['email']) ?></a>
                                <?php if ($l['id_user'] !== null): ?>
                                    <div class="cellule-sec"><?= e(trim($l['prenom'] . ' ' . $l['nom'])) ?></div>
                                    <?php if ($roles): ?>
                                        <div class="roles">
                                            <?php foreach ($roles as $role): ?>
                                                <span class="etiquette <?= str_starts_with($role, 'Admin') ? 'violet' : (str_starts_with($role, 'Réparateur') ? 'vert' : 'bleu') ?>">
                                                    <?= e($role) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= e(urlHistorique(['ip' => $l['ip']])) ?>" class="lien-filtre"><?= e($l['ip']) ?></a>
                            </td>
                            <td title="<?= e($l['navigateur']) ?>"><?= e(navigateurLisible($l['navigateur'])) ?></td>
                            <td><?= etiquetteMotif($l['motif']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($nbPages > 1): ?>
                <!-- Pagination : chaque lien garde les filtres actifs dans l'adresse -->
                <nav class="pagination" aria-label="Pages de résultats">
                    <?php if ($page > 1): ?>
                        <a href="<?= e(urlHistorique($filtres, ['page' => $page - 1])) ?>">« Précédent</a>
                    <?php else: ?>
                        <span class="inactif">« Précédent</span>
                    <?php endif; ?>

                    <?php for ($n = max(1, $page - 2); $n <= min($nbPages, $page + 2); $n++): ?>
                        <?php if ($n === $page): ?>
                            <span class="courante" aria-current="page"><?= $n ?></span>
                        <?php else: ?>
                            <a href="<?= e(urlHistorique($filtres, ['page' => $n])) ?>"><?= $n ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $nbPages): ?>
                        <a href="<?= e(urlHistorique($filtres, ['page' => $page + 1])) ?>">Suivant »</a>
                    <?php else: ?>
                        <span class="inactif">Suivant »</span>
                    <?php endif; ?>
                </nav>
                <p class="cellule-sec" style="text-align:center;margin-top:6px">Page <?= $page ?> sur <?= $nbPages ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

</main>

<footer class="pied">
    <p>&copy; <?= date('Y') ?> Fair'repair — Administration</p>
</footer>

</body>
</html>
