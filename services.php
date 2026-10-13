<?php
/**
 * Fair'repair — Catalogue des services
 * ------------------------------------
 * Page PUBLIQUE : visible sans être connecté, pour donner envie aux
 * visiteurs de s'inscrire. Seul le bouton « Réserver » demande une
 * connexion (voir plus bas).
 *
 * N'apparaissent que les services ACTIFS d'un réparateur VALIDÉ et
 * DISPONIBLE. Filtres en GET (l'URL peut être partagée ou mise en favori),
 * 12 cartes par page.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/affichage.php';

const SERVICES_PAR_PAGE = 12;

// Tris autorisés : clé reçue dans l'URL → libellé et clause ORDER BY.
// L'ORDER BY ne peut pas être un paramètre de requête préparée : on ne
// place donc JAMAIS la valeur reçue dans le SQL, on choisit une clause
// écrite à l'avance dans cette liste blanche.
const TRIS = [
    'prix_asc'  => ['Prix croissant',   's.tarif ASC, s.id_service DESC'],
    'prix_desc' => ['Prix décroissant', 's.tarif DESC, s.id_service DESC'],
    'note'      => ['Mieux notés',      'st.moyenne IS NULL, st.moyenne DESC, st.nb_avis DESC, s.id_service DESC'],
    'recent'    => ['Plus récents',     's.id_service DESC'],
];

// Ouvre éventuellement la session grâce au cookie « Se souvenir de moi ».
// Appelé AVANT tout affichage : la fonction peut envoyer des en-têtes.
$connecte = estConnecte();
if ($connecte && empty($_SESSION['roles'])) {
    rafraichirRoles();
}

// ------------------------------------------------------------
// Lecture des filtres (tout ce qui vient de l'URL est nettoyé)
// ------------------------------------------------------------
$filtres = [
    'q'          => mb_substr(nettoyer($_GET['q'] ?? ''), 0, 100),
    'ville'      => mb_substr(nettoyer($_GET['ville'] ?? ''), 0, 100),
    'prix_max'   => filter_var($_GET['prix_max'] ?? '', FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]),
    'note_min'   => filter_var($_GET['note_min'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]),
    'tri'        => array_key_exists($_GET['tri'] ?? '', TRIS) ? $_GET['tri'] : 'recent',
    'reparateur' => filter_var($_GET['reparateur'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
];
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;

// ------------------------------------------------------------
// Construction de la clause WHERE
// ------------------------------------------------------------
// Seuls des morceaux de SQL écrits ICI sont assemblés ; les valeurs saisies
// passent toutes par des marqueurs nommés (:q1, :ville…). Il n'y a donc
// aucune concaténation de donnée utilisateur dans la requête.
$conditions = [
    's.actif = 1',
    "rp.statut_validation = 'valide'",
    'rp.disponible = 1',
];
$params = [];

if ($filtres['q'] !== '') {
    // Les caractères % et _ sont des jokers pour LIKE : on les neutralise
    // pour qu'une recherche « 100% » cherche vraiment le signe %.
    $motif = '%' . addcslashes($filtres['q'], '%_\\') . '%';
    $conditions[] = '(s.titre LIKE :q1 OR s.description LIKE :q2)';
    $params[':q1'] = $motif;   // deux marqueurs distincts : un même nom ne
    $params[':q2'] = $motif;   // peut pas servir deux fois (émulation désactivée)
}
if ($filtres['ville'] !== '') {
    $conditions[] = 'COALESCE(rp.ville_atelier, u.ville) = :ville';
    $params[':ville'] = $filtres['ville'];
}
if ($filtres['prix_max'] !== false) {
    $conditions[] = 's.tarif <= :prix_max';
    $params[':prix_max'] = $filtres['prix_max'];
}
if ($filtres['note_min'] !== false) {
    $conditions[] = 'st.moyenne >= :note_min';
    $params[':note_min'] = $filtres['note_min'];
}
if ($filtres['reparateur'] !== false) {
    $conditions[] = 's.id_reparateur = :id_reparateur';
    $params[':id_reparateur'] = $filtres['reparateur'];
}
$where = implode(' AND ', $conditions);

// Note moyenne et nombre d'avis de chaque réparateur, calculés à partir
// des avis réellement déposés sur ses interventions.
$jointures = "FROM service s
              INNER JOIN reparateur  rp ON rp.id_reparateur = s.id_reparateur
              INNER JOIN utilisateur u  ON u.id_user        = rp.id_reparateur
              LEFT JOIN (
                  SELECT s2.id_reparateur, AVG(a.note) AS moyenne, COUNT(*) AS nb_avis
                  FROM avis a
                  INNER JOIN reservation r2 ON r2.id_reservation = a.id_reservation
                  INNER JOIN service     s2 ON s2.id_service     = r2.id_service
                  GROUP BY s2.id_reparateur
              ) st ON st.id_reparateur = s.id_reparateur";

// --- Nombre total de résultats (pour la pagination) ---
$req = $pdo->prepare("SELECT COUNT(*) $jointures WHERE $where");
$req->execute($params);
$total     = (int) $req->fetchColumn();
$nbPages   = max(1, (int) ceil($total / SERVICES_PAR_PAGE));
$page      = min($page, $nbPages);
$decalage  = ($page - 1) * SERVICES_PAR_PAGE;

// --- Page de résultats ---
$sql = "SELECT s.id_service, s.titre, s.description, s.tarif, s.duree_estimee,
               u.prenom, u.nom, rp.raison_sociale,
               COALESCE(rp.ville_atelier, u.ville) AS ville,
               st.moyenne, COALESCE(st.nb_avis, 0) AS nb_avis
        $jointures
        WHERE $where
        ORDER BY " . TRIS[$filtres['tri']][1] . "
        LIMIT :limite OFFSET :decalage";
$req = $pdo->prepare($sql);
foreach ($params as $nom => $valeur) {
    $req->bindValue($nom, $valeur);
}
// LIMIT et OFFSET doivent être envoyés comme des ENTIERS
$req->bindValue(':limite',   SERVICES_PAR_PAGE, PDO::PARAM_INT);
$req->bindValue(':decalage', $decalage,         PDO::PARAM_INT);
$req->execute();
$services = $req->fetchAll();

// --- Villes proposées dans le filtre (seulement celles qui ont des services) ---
$villes = $pdo->query(
    "SELECT DISTINCT COALESCE(rp.ville_atelier, u.ville) AS ville
     FROM service s
     INNER JOIN reparateur  rp ON rp.id_reparateur = s.id_reparateur
     INNER JOIN utilisateur u  ON u.id_user        = rp.id_reparateur
     WHERE s.actif = 1 AND rp.statut_validation = 'valide' AND rp.disponible = 1
       AND COALESCE(rp.ville_atelier, u.ville) IS NOT NULL
     ORDER BY ville"
)->fetchAll(PDO::FETCH_COLUMN);

// --- Nom du réparateur filtré (bandeau « Services de … ») ---
$reparateurFiltre = null;
if ($filtres['reparateur'] !== false) {
    $req = $pdo->prepare("SELECT u.prenom, u.nom, rp.raison_sociale
                          FROM reparateur rp
                          INNER JOIN utilisateur u ON u.id_user = rp.id_reparateur
                          WHERE rp.id_reparateur = :id AND rp.statut_validation = 'valide'");
    $req->execute([':id' => $filtres['reparateur']]);
    $reparateurFiltre = $req->fetch() ?: null;
}

/**
 * URL de la page courante avec les filtres actifs, en remplaçant certains
 * paramètres. Sert à la pagination : changer de page garde les filtres.
 * http_build_query encode les valeurs ; e() est appliqué à l'affichage.
 */
function urlCatalogue(array $filtres, array $remplacer = []): string
{
    $parametres = array_merge($filtres, $remplacer);
    // On retire les filtres vides pour garder des URL courtes
    $parametres = array_filter($parametres, fn($v) => $v !== '' && $v !== false && $v !== null);
    return 'services.php' . ($parametres ? '?' . http_build_query($parametres) : '');
}

$filtresActifs = $filtres['q'] !== '' || $filtres['ville'] !== '' || $filtres['prix_max'] !== false
              || $filtres['note_min'] !== false || $filtres['reparateur'] !== false;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Les services — Fair'repair</title>
    <style>
    :root {
        --vert:         #0F766E;
        --vert-fonce:   #0B5A54;
        --vert-clair:   #E6F4F2;
        --bleu:         #1E50D4;
        --bleu-clair:   #E8EEFC;
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
        display: flex; flex-direction: column; min-height: 100vh;
    }

    /* ---------- En-tête ---------- */
    .entete {
        background-color: var(--blanc);
        border-bottom: 1px solid var(--bordure);
        padding: 14px 32px;
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap; gap: 12px;
    }
    .logo { font-size: 1.4rem; font-weight: 700; color: var(--vert); text-decoration: none; }
    .logo span { color: var(--texte); }
    .entete nav { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
    .entete nav a { color: var(--texte-doux); text-decoration: none; font-weight: 500; font-size: 0.92rem; }
    .entete nav a:hover, .entete nav a.actif { color: var(--vert); }
    .entete nav a.actif { font-weight: 600; }

    .conteneur { flex: 1; width: 100%; max-width: 1080px; margin: 0 auto; padding: 32px 20px 48px; }
    h1 { font-size: 1.7rem; margin-bottom: 6px; }
    .sous-titre { color: var(--texte-doux); margin-bottom: 22px; font-size: 0.95rem; }

    /* ---------- Blocs, alertes, boutons (classes communes) ---------- */
    .bloc {
        background-color: var(--blanc);
        border: 1px solid var(--bordure);
        border-radius: var(--rayon);
        padding: 20px 22px;
        margin-bottom: 22px;
    }
    .alerte { padding: 14px 16px; border-radius: 6px; margin-bottom: 22px; font-size: 0.92rem; }
    .alerte-info { background-color: var(--bleu-clair); border-left: 4px solid var(--bleu); color: var(--bleu); }
    .alerte a { color: inherit; font-weight: 600; }
    .bouton-petit {
        display: inline-block; padding: 6px 13px; border-radius: 5px;
        font-size: 0.83rem; font-weight: 600; text-decoration: none;
        border: 1px solid var(--bordure); background-color: var(--blanc);
        color: var(--texte); cursor: pointer; font-family: inherit;
    }
    .bouton-petit:hover { background-color: var(--fond); }
    .bouton-petit.primaire { background-color: var(--vert); border-color: var(--vert); color: var(--blanc); }
    .bouton-petit.primaire:hover { background-color: var(--vert-fonce); }
    .etiquette {
        display: inline-block; padding: 3px 10px; border-radius: 20px;
        font-size: 0.78rem; font-weight: 600; white-space: nowrap;
        background-color: var(--vert-clair); color: var(--vert-fonce);
    }
    .etoiles { color: #E0A400; letter-spacing: 1px; }
    .etoiles .vide { color: var(--bordure); }
    .sans-avis, .nb-avis { color: var(--texte-doux); font-size: 0.85rem; }

    /* ---------- Filtres ---------- */
    .filtres {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
        gap: 12px;
        align-items: end;
    }
    .champ { display: flex; flex-direction: column; min-width: 0; }
    .champ label { font-size: 0.82rem; font-weight: 600; margin-bottom: 4px; }
    .champ input, .champ select {
        padding: 8px 10px; border: 1px solid var(--bordure); border-radius: 6px;
        font-size: 0.92rem; font-family: inherit; background-color: var(--blanc); width: 100%;
    }
    .champ input:focus, .champ select:focus {
        outline: none; border-color: var(--vert); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15);
    }
    .filtres-actions { grid-column: 1 / -1; display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }

    .resume-resultats { color: var(--texte-doux); font-size: 0.9rem; margin-bottom: 14px; }

    /* ---------- Grille de cartes ---------- */
    .grille-services {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 18px;
        list-style: none;
    }
    .carte-service {
        background-color: var(--blanc);
        border: 1px solid var(--bordure);
        border-top: 3px solid var(--vert);
        border-radius: var(--rayon);
        padding: 18px;
        display: flex; flex-direction: column; gap: 8px;
        transition: box-shadow 0.15s, transform 0.15s;
    }
    .carte-service:hover { box-shadow: 0 6px 18px rgba(13, 24, 41, 0.08); transform: translateY(-2px); }
    .carte-service h2 { font-size: 1.05rem; line-height: 1.35; }
    .carte-service h2 a { color: var(--texte); text-decoration: none; }
    .carte-service h2 a:hover { color: var(--vert); }
    .carte-description { color: var(--texte-doux); font-size: 0.88rem; flex: 1; }
    .carte-reparateur { font-size: 0.86rem; }
    .carte-reparateur .ville { color: var(--texte-doux); }
    .carte-bas {
        display: flex; justify-content: space-between; align-items: center;
        border-top: 1px solid var(--bordure); padding-top: 12px; margin-top: 4px; gap: 8px;
    }
    .prix { font-size: 1.25rem; font-weight: 700; color: var(--vert-fonce); }
    .duree { font-size: 0.83rem; color: var(--texte-doux); }

    /* ---------- Pagination ---------- */
    .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 28px; flex-wrap: wrap; }
    .pagination a, .pagination span {
        min-width: 38px; padding: 7px 12px; text-align: center;
        border: 1px solid var(--bordure); border-radius: 6px;
        background-color: var(--blanc); color: var(--texte);
        text-decoration: none; font-size: 0.9rem; font-weight: 600;
    }
    .pagination a:hover { border-color: var(--vert); color: var(--vert); }
    .pagination .courante { background-color: var(--vert); border-color: var(--vert); color: var(--blanc); }
    .pagination .inactif { color: var(--bordure); }

    /* ---------- État vide ---------- */
    .vide-etat { text-align: center; padding: 44px 20px; color: var(--texte-doux); }
    .vide-etat .icone { font-size: 2.4rem; margin-bottom: 8px; }
    .vide-etat p { margin-bottom: 14px; }

    .pied {
        background-color: var(--blanc); border-top: 1px solid var(--bordure);
        text-align: center; padding: 18px; font-size: 0.82rem; color: var(--texte-doux);
    }

    /* ---------- Responsive ---------- */
    @media (max-width: 900px) {
        .grille-services { grid-template-columns: repeat(2, 1fr); }
        .filtres { grid-template-columns: 1fr 1fr; }
        .filtres .champ-recherche { grid-column: 1 / -1; }
    }
    @media (max-width: 600px) {
        .entete { padding: 12px 16px; }
        .grille-services { grid-template-columns: 1fr; }   /* une seule colonne sur mobile */
        .filtres { grid-template-columns: 1fr; }
        .filtres-actions { justify-content: stretch; }
        .filtres-actions > * { flex: 1; text-align: center; }
    }
    </style>
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav>
        <a href="services.php" class="actif">Les services</a>
        <?php if ($connecte): ?>
            <a href="<?= e(espaceActif()) ?>">Mon espace</a>
        <?php else: ?>
            <a href="connexion.php">Connexion</a>
            <a href="inscription.php">Créer un compte</a>
        <?php endif; ?>
    </nav>
</header>

<main class="conteneur">
    <h1>Les services de réparation</h1>
    <p class="sous-titre">Des réparateurs vérifiés, qui se déplacent chez vous dans Paris.</p>

    <?php if ($reparateurFiltre): ?>
        <div class="alerte alerte-info">
            Services proposés par
            <strong><?= e($reparateurFiltre['raison_sociale'] ?: $reparateurFiltre['prenom'] . ' ' . $reparateurFiltre['nom']) ?></strong>.
            <a href="<?= e(urlCatalogue($filtres, ['reparateur' => '', 'page' => ''])) ?>">Voir tous les services</a>
        </div>
    <?php endif; ?>

    <!-- Filtres : formulaire GET, les résultats restent partageables par lien -->
    <form method="get" action="services.php" class="bloc filtres" role="search">
        <?php if ($filtres['reparateur'] !== false): ?>
            <input type="hidden" name="reparateur" value="<?= (int) $filtres['reparateur'] ?>">
        <?php endif; ?>

        <div class="champ champ-recherche">
            <label for="q">Rechercher</label>
            <input type="search" id="q" name="q" value="<?= e($filtres['q']) ?>"
                   placeholder="Crevaison, freins, dérailleur…" maxlength="100">
        </div>
        <div class="champ">
            <label for="ville">Ville</label>
            <select id="ville" name="ville">
                <option value="">Toutes</option>
                <?php foreach ($villes as $v): ?>
                    <option value="<?= e($v) ?>" <?= $v === $filtres['ville'] ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="prix_max">Prix max (€)</label>
            <input type="number" id="prix_max" name="prix_max" min="0" step="1"
                   value="<?= $filtres['prix_max'] !== false ? e((string) $filtres['prix_max']) : '' ?>">
        </div>
        <div class="champ">
            <label for="note_min">Note minimale</label>
            <select id="note_min" name="note_min">
                <option value="">Toutes</option>
                <?php for ($n = 4; $n >= 1; $n--): ?>
                    <option value="<?= $n ?>" <?= $filtres['note_min'] === $n ? 'selected' : '' ?>><?= $n ?> ★ et plus</option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="champ">
            <label for="tri">Trier par</label>
            <select id="tri" name="tri">
                <?php foreach (TRIS as $cle => [$libelle]): ?>
                    <option value="<?= e($cle) ?>" <?= $cle === $filtres['tri'] ? 'selected' : '' ?>><?= e($libelle) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filtres-actions">
            <?php if ($filtresActifs): ?>
                <a href="services.php" class="bouton-petit">Réinitialiser</a>
            <?php endif; ?>
            <button type="submit" class="bouton-petit primaire">Filtrer</button>
        </div>
    </form>

    <?php if ($total === 0): ?>
        <div class="bloc vide-etat">
            <div class="icone">🚲</div>
            <p><strong>Aucun service ne correspond à votre recherche.</strong></p>
            <p>Essayez d'élargir vos critères : une autre ville, un prix maximum plus élevé ou une note minimale plus basse.</p>
            <a href="services.php" class="bouton-petit primaire">Voir tous les services</a>
        </div>
    <?php else: ?>
        <p class="resume-resultats">
            <?= $total ?> service<?= $total > 1 ? 's' : '' ?> trouvé<?= $total > 1 ? 's' : '' ?>
            <?php if ($nbPages > 1): ?> — page <?= $page ?> sur <?= $nbPages ?><?php endif; ?>
        </p>

        <ul class="grille-services">
            <?php foreach ($services as $s):
                $idService = (int) $s['id_service'];
                $description = (string) $s['description'];
            ?>
                <li class="carte-service">
                    <h2><a href="service.php?id=<?= $idService ?>"><?= e($s['titre']) ?></a></h2>

                    <p class="carte-description">
                        <?= e(mb_strimwidth($description, 0, 120, '…')) ?>
                    </p>

                    <div class="carte-reparateur">
                        🔧 <strong><?= e($s['raison_sociale'] ?: $s['prenom'] . ' ' . $s['nom']) ?></strong>
                        <?php if ($s['ville']): ?><span class="ville">· <?= e($s['ville']) ?></span><?php endif; ?>
                    </div>
                    <div><?= noteAvecAvis($s['moyenne'], (int) $s['nb_avis']) ?></div>

                    <div class="carte-bas">
                        <div>
                            <div class="prix"><?= euros($s['tarif']) ?></div>
                            <div class="duree">⏱ <?= e(dureeLisible((int) ($s['duree_estimee'] ?? 60))) ?></div>
                        </div>
                        <!-- Un visiteur non connecté qui clique est envoyé vers connexion.php
                             par exigerRole() de reserver.php : l'adresse demandée est gardée
                             en session ($_SESSION['redirection']) et, une fois connecté,
                             il arrive directement sur le formulaire de ce service. -->
                        <a href="reserver.php?service=<?= $idService ?>" class="bouton-petit primaire">Réserver</a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($nbPages > 1): ?>
            <nav class="pagination" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= e(urlCatalogue($filtres, ['page' => $page - 1])) ?>" aria-label="Page précédente">‹</a>
                <?php else: ?>
                    <span class="inactif">‹</span>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $nbPages; $p++): ?>
                    <?php if ($p === $page): ?>
                        <span class="courante" aria-current="page"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= e(urlCatalogue($filtres, ['page' => $p])) ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $nbPages): ?>
                    <a href="<?= e(urlCatalogue($filtres, ['page' => $page + 1])) ?>" aria-label="Page suivante">›</a>
                <?php else: ?>
                    <span class="inactif">›</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>

<footer class="pied">Fair'repair — la réparation de vélo à domicile, à Paris</footer>

</body>
</html>
