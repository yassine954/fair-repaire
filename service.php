<?php
/**
 * Fair'repair — Fiche d'un service
 * --------------------------------
 * Page PUBLIQUE : service.php?id=<id_service>
 *
 * Affiche la description complète, le réparateur, ses avis et une petite
 * carte. Si le service n'existe pas, est désactivé ou appartient à un
 * réparateur non validé, on renvoie la MÊME page 404 dans les trois cas :
 * le visiteur ne doit pas pouvoir deviner qu'une ligne existe mais est cachée.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/affichage.php';

$connecte = estConnecte();
if ($connecte && empty($_SESSION['roles'])) {
    rafraichirRoles();
}

$idService = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$service = false;
if ($idService !== false) {
    // Les conditions de visibilité sont DANS la requête : un service caché
    // donne exactement le même résultat (aucune ligne) qu'un service inexistant.
    $sql = "SELECT s.id_service, s.titre, s.description, s.tarif, s.duree_estimee, s.rayon,
                   rp.id_reparateur, rp.raison_sociale, rp.latitude, rp.longitude,
                   rp.rayon_intervention, rp.disponible,
                   u.prenom, u.nom,
                   COALESCE(rp.ville_atelier, u.ville) AS ville
            FROM service s
            INNER JOIN reparateur  rp ON rp.id_reparateur = s.id_reparateur
            INNER JOIN utilisateur u  ON u.id_user        = rp.id_reparateur
            WHERE s.id_service         = :id
              AND s.actif              = 1
              AND rp.statut_validation = 'valide'";
    $req = $pdo->prepare($sql);
    $req->execute([':id' => $idService]);
    $service = $req->fetch();
}

if ($service) {
    $idReparateur = (int) $service['id_reparateur'];

    // --- Statistiques du réparateur : note, nombre d'avis, interventions terminées ---
    $req = $pdo->prepare(
        "SELECT
            (SELECT AVG(a.note) FROM avis a
             INNER JOIN reservation r ON r.id_reservation = a.id_reservation
             INNER JOIN service     s ON s.id_service     = r.id_service
             WHERE s.id_reparateur = :id1)                                  AS moyenne,
            (SELECT COUNT(*) FROM avis a
             INNER JOIN reservation r ON r.id_reservation = a.id_reservation
             INNER JOIN service     s ON s.id_service     = r.id_service
             WHERE s.id_reparateur = :id2)                                  AS nb_avis,
            (SELECT COUNT(*) FROM reservation r
             INNER JOIN service s ON s.id_service = r.id_service
             WHERE s.id_reparateur = :id3 AND r.statut = 'terminee')        AS nb_terminees"
    );
    $req->execute([':id1' => $idReparateur, ':id2' => $idReparateur, ':id3' => $idReparateur]);
    $stats = $req->fetch();

    // --- Avis laissés sur les services de ce réparateur ---
    // CONFIDENTIALITÉ : on ne sélectionne que le prénom et la PREMIÈRE LETTRE
    // du nom (« Marie D. »). Le nom complet et l'e-mail ne sortent jamais de
    // la base : ils ne peuvent donc pas fuiter dans la page, même par erreur.
    $req = $pdo->prepare(
        "SELECT a.note, a.commentaire, a.date_avis,
                u.prenom, LEFT(u.nom, 1) AS initiale,
                s.titre AS service
         FROM avis a
         INNER JOIN reservation r ON r.id_reservation = a.id_reservation
         INNER JOIN service     s ON s.id_service     = r.id_service
         INNER JOIN utilisateur u ON u.id_user        = r.id_client
         WHERE s.id_reparateur = :id
         ORDER BY a.date_avis DESC
         LIMIT 20"
    );
    $req->execute([':id' => $idReparateur]);
    $avis = $req->fetchAll();

    // Rayon propre au service s'il est renseigné, sinon celui du réparateur
    $rayonKm = (int) ($service['rayon'] ?: $service['rayon_intervention']);
    $aPosition = $service['latitude'] !== null && $service['longitude'] !== null;
    $nomReparateur = $service['raison_sociale'] ?: $service['prenom'] . ' ' . $service['nom'];
    $estSonService = $connecte && (int) $_SESSION['id_user'] === $idReparateur;
} else {
    // Même réponse pour « n'existe pas », « inactif » et « réparateur non validé »
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $service ? e($service['titre']) : 'Service introuvable' ?> — Fair'repair</title>
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
        --rayon:        10px;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        background-color: var(--fond); color: var(--texte); line-height: 1.6;
        display: flex; flex-direction: column; min-height: 100vh;
    }
    .entete {
        background-color: var(--blanc); border-bottom: 1px solid var(--bordure);
        padding: 14px 32px; display: flex; justify-content: space-between;
        align-items: center; flex-wrap: wrap; gap: 12px;
    }
    .logo { font-size: 1.4rem; font-weight: 700; color: var(--vert); text-decoration: none; }
    .logo span { color: var(--texte); }
    .entete nav { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
    .entete nav a { color: var(--texte-doux); text-decoration: none; font-weight: 500; font-size: 0.92rem; }
    .entete nav a:hover { color: var(--vert); }

    .conteneur { flex: 1; width: 100%; max-width: 1080px; margin: 0 auto; padding: 28px 20px 48px; }
    .fil { font-size: 0.85rem; margin-bottom: 14px; }
    .fil a { color: var(--vert); text-decoration: none; }
    h1 { font-size: 1.7rem; line-height: 1.3; margin-bottom: 8px; }
    h2 { font-size: 1.1rem; margin-bottom: 12px; }

    .bloc {
        background-color: var(--blanc); border: 1px solid var(--bordure);
        border-radius: var(--rayon); padding: 22px 24px; margin-bottom: 22px;
    }
    .bloc-entete { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .bloc-entete h2 { margin-bottom: 0; }
    .alerte { padding: 14px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.92rem; }
    .alerte-info  { background-color: var(--bleu-clair);  border-left: 4px solid var(--bleu);  color: var(--bleu); }
    .alerte-ambre { background-color: var(--ambre-clair); border-left: 4px solid var(--ambre); color: var(--ambre); }

    .mise-en-page { display: grid; grid-template-columns: 1fr 320px; gap: 22px; align-items: start; }
    .colonne-droite { position: sticky; top: 16px; }

    .description { white-space: pre-line; color: var(--texte); }

    .grille-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 18px; }
    .stat { border: 1px solid var(--bordure); border-top: 3px solid var(--vert); border-radius: 8px; padding: 12px 14px; }
    .stat.bleu  { border-top-color: var(--bleu); }
    .stat.ambre { border-top-color: var(--ambre); }
    .stat-valeur { font-size: 1.35rem; font-weight: 700; line-height: 1.2; }
    .stat-libelle { font-size: 0.75rem; color: var(--texte-doux); text-transform: uppercase; letter-spacing: 0.4px; }

    .prix-principal { font-size: 2rem; font-weight: 700; color: var(--vert-fonce); line-height: 1.2; }
    .bouton-principal {
        display: block; width: 100%; padding: 14px; margin-top: 16px;
        background-color: var(--vert); color: var(--blanc); text-align: center;
        border: none; border-radius: 6px; font-size: 1.05rem; font-weight: 600;
        font-family: inherit; text-decoration: none; cursor: pointer;
    }
    .bouton-principal:hover { background-color: var(--vert-fonce); }
    .bouton-petit {
        display: inline-block; padding: 6px 13px; border-radius: 5px; font-size: 0.83rem;
        font-weight: 600; text-decoration: none; border: 1px solid var(--bordure);
        background-color: var(--blanc); color: var(--texte);
    }
    .bouton-petit:hover { background-color: var(--fond); }
    .bouton-petit.primaire { background-color: var(--vert); border-color: var(--vert); color: var(--blanc); }

    .infos { list-style: none; margin-top: 12px; font-size: 0.92rem; }
    .infos li { padding: 7px 0; border-bottom: 1px solid var(--bordure); display: flex; justify-content: space-between; gap: 10px; }
    .infos li:last-child { border-bottom: none; }
    .infos span:first-child { color: var(--texte-doux); }

    .etiquette {
        display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem;
        font-weight: 600; background-color: var(--vert-clair); color: var(--vert-fonce);
    }
    .etoiles { color: #E0A400; letter-spacing: 1px; }
    .etoiles .vide { color: var(--bordure); }
    .sans-avis, .nb-avis { color: var(--texte-doux); font-size: 0.85rem; }

    .avis { border: 1px solid var(--bordure); border-radius: 8px; padding: 12px 14px; margin-bottom: 10px; }
    .avis-entete { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px; margin-bottom: 4px; font-size: 0.9rem; }
    .avis-service { font-size: 0.8rem; color: var(--texte-doux); }
    .avis-commentaire { font-size: 0.9rem; color: var(--texte-doux); font-style: italic; }

    .carte { height: 240px; border-radius: 8px; border: 1px solid var(--bordure); margin-top: 12px; z-index: 0; }

    .vide-etat { text-align: center; padding: 40px 20px; color: var(--texte-doux); }
    .vide-etat .icone { font-size: 2.4rem; }
    .vide-etat p { margin-bottom: 14px; }

    .pied {
        background-color: var(--blanc); border-top: 1px solid var(--bordure);
        text-align: center; padding: 18px; font-size: 0.82rem; color: var(--texte-doux);
    }

    @media (max-width: 860px) {
        .mise-en-page { grid-template-columns: 1fr; }
        .colonne-droite { position: static; order: -1; }   /* prix et bouton en premier sur mobile */
    }
    @media (max-width: 600px) {
        .entete { padding: 12px 16px; }
        .bloc { padding: 18px 16px; }
        .grille-stats { grid-template-columns: 1fr; }
    }
    </style>
    <?php if ($service && $aPosition): ?>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css"
          integrity="sha512-h9FcoyWjHcOcmEVkxOfTLnmZFWIH0iZhZT1H2TbOq55xssQGEJHEaIm+PgoUaZbRvQTNTluNOEfb1ZRy6D3BOw=="
          crossorigin="anonymous" referrerpolicy="no-referrer">
    <?php endif; ?>
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav>
        <a href="services.php">Les services</a>
        <?php if ($connecte): ?>
            <a href="<?= e(espaceActif()) ?>">Mon espace</a>
        <?php else: ?>
            <a href="connexion.php">Connexion</a>
            <a href="inscription.php">Créer un compte</a>
        <?php endif; ?>
    </nav>
</header>

<main class="conteneur">
<?php if (!$service): ?>

    <div class="bloc vide-etat">
        <div class="icone">🔍</div>
        <h1>Service introuvable</h1>
        <p>Ce service n'existe pas ou n'est plus proposé.</p>
        <a href="services.php" class="bouton-petit primaire">Parcourir les services</a>
    </div>

<?php else: ?>

    <p class="fil"><a href="services.php">← Tous les services</a></p>

    <div class="mise-en-page">
        <div>
            <section class="bloc">
                <h1><?= e($service['titre']) ?></h1>
                <p class="description"><?= e($service['description'] ?: 'Aucune description fournie.') ?></p>

                <ul class="infos">
                    <li><span>Durée estimée</span><strong><?= e(dureeLisible((int) ($service['duree_estimee'] ?? 60))) ?></strong></li>
                    <li><span>Zone d'intervention</span><strong><?= $rayonKm ?> km autour de <?= e($service['ville'] ?? 'l\'atelier') ?></strong></li>
                </ul>
            </section>

            <section class="bloc">
                <h2>Le réparateur</h2>
                <p>
                    🔧 <strong><?= e($service['prenom'] . ' ' . $service['nom']) ?></strong>
                    <?php if ($service['raison_sociale']): ?> — <?= e($service['raison_sociale']) ?><?php endif; ?>
                    <?php if ($service['ville']): ?><br><span class="nb-avis">📍 <?= e($service['ville']) ?></span><?php endif; ?>
                </p>

                <div class="grille-stats">
                    <div class="stat">
                        <div class="stat-valeur">
                            <?= $stats['moyenne'] !== null ? number_format((float) $stats['moyenne'], 1, ',', '') . ' / 5' : '—' ?>
                        </div>
                        <div class="stat-libelle">Note moyenne</div>
                    </div>
                    <div class="stat bleu">
                        <div class="stat-valeur"><?= (int) $stats['nb_avis'] ?></div>
                        <div class="stat-libelle">Avis</div>
                    </div>
                    <div class="stat ambre">
                        <div class="stat-valeur"><?= (int) $stats['nb_terminees'] ?></div>
                        <div class="stat-libelle">Réparations terminées</div>
                    </div>
                </div>

                <?php if ($aPosition): ?>
                    <div id="carte" class="carte" role="img"
                         aria-label="Carte de la zone d'intervention de <?= e($nomReparateur) ?>"
                         data-lat="<?= e((string) $service['latitude']) ?>"
                         data-lng="<?= e((string) $service['longitude']) ?>"
                         data-rayon="<?= $rayonKm ?>"></div>
                <?php endif; ?>
            </section>

            <section class="bloc">
                <div class="bloc-entete">
                    <h2>Avis des clients</h2>
                    <div><?= noteAvecAvis($stats['moyenne'], (int) $stats['nb_avis']) ?></div>
                </div>

                <?php if (!$avis): ?>
                    <p class="sans-avis">Ce réparateur n'a pas encore reçu d'avis. Soyez le premier à en laisser un après votre réparation !</p>
                <?php else: ?>
                    <?php foreach ($avis as $a): ?>
                        <article class="avis">
                            <div class="avis-entete">
                                <span>
                                    <?= etoiles((int) $a['note']) ?>
                                    <!-- Prénom + initiale seulement : « Marie D. » -->
                                    <strong><?= e($a['prenom'] . ' ' . mb_strtoupper($a['initiale']) . '.') ?></strong>
                                </span>
                                <span class="avis-service"><?= e(dateFr($a['date_avis'])) ?> · <?= e($a['service']) ?></span>
                            </div>
                            <?php if ($a['commentaire']): ?>
                                <p class="avis-commentaire">« <?= e($a['commentaire']) ?> »</p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>

        <aside class="colonne-droite bloc">
            <div class="prix-principal"><?= euros($service['tarif']) ?></div>
            <div class="nb-avis">⏱ <?= e(dureeLisible((int) ($service['duree_estimee'] ?? 60))) ?> · déplacement inclus</div>

            <?php if ($estSonService): ?>
                <div class="alerte alerte-info" style="margin: 14px 0 0;">C'est l'un de vos services : vous ne pouvez pas le réserver.</div>
            <?php elseif (!$service['disponible']): ?>
                <div class="alerte alerte-ambre" style="margin: 14px 0 0;">Ce réparateur n'accepte pas de nouvelles demandes pour le moment.</div>
            <?php else: ?>
                <a href="reserver.php?service=<?= (int) $service['id_service'] ?>" class="bouton-principal">Réserver ce service</a>
                <p class="nb-avis" style="margin-top: 10px; text-align: center;">
                    Aucun paiement maintenant : vous ne payez qu'une fois la demande acceptée.
                </p>
            <?php endif; ?>
        </aside>
    </div>

<?php endif; ?>
</main>

<footer class="pied">Fair'repair — la réparation de vélo à domicile, à Paris</footer>

<?php if ($service && $aPosition): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"
        integrity="sha512-puJW3E/qXDqYp9IfhAI54BJEaWIfloJ7JWs7OeD5i6ruC9JZL1gERT1wjtwXFlh7CjE7ZJ+/vcRZRkIYIb6p4g=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
// Petite carte centrée sur l'atelier du réparateur, avec son rayon d'intervention.
// Sans JavaScript, la page reste complète : seule la carte est absente.
(function () {
    var div = document.getElementById('carte');
    if (!div || typeof L === 'undefined') { return; }
    var lat = parseFloat(div.dataset.lat), lng = parseFloat(div.dataset.lng);
    var rayon = parseInt(div.dataset.rayon, 10) || 5;

    var carte = L.map(div, { scrollWheelZoom: false }).setView([lat, lng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">contributeurs OpenStreetMap</a>'
    }).addTo(carte);

    L.marker([lat, lng]).addTo(carte);
    var zone = L.circle([lat, lng], {
        radius: rayon * 1000, color: '#0F766E', weight: 1.5, fillOpacity: 0.08
    }).addTo(carte);
    carte.fitBounds(zone.getBounds(), { padding: [10, 10] });
})();
</script>
<?php endif; ?>
</body>
</html>
