<?php
/**
 * Fair'repair — Confirmation d'une demande de réservation
 * -------------------------------------------------------
 * confirmation.php?reservation=<id_reservation>
 *
 * Page d'arrivée après la redirection de reserver.php (POST/Redirect/GET) :
 * un appui sur F5 recharge simplement cette page, sans rien réenregistrer.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/affichage.php';

// Page protégée : connexion obligatoire (avant tout affichage)
exigerConnexion();

$idReservation = filter_var($_GET['reservation'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$reservation = false;
if ($idReservation !== false) {
    // PROTECTION IDOR (Insecure Direct Object Reference)
    // ---------------------------------------------------
    // L'id de la réservation est visible dans l'URL : n'importe qui peut le
    // changer (?reservation=41, 42, 43…). Si la requête cherchait seulement
    // « WHERE id_reservation = :id », un client pourrait lire les réservations
    // des AUTRES (adresse, téléphone, description de la panne…).
    // On ajoute donc « AND id_client = :id_client », avec l'id pris dans la
    // SESSION (impossible à modifier depuis le navigateur). La réservation
    // d'un autre client donne alors « aucune ligne », exactement comme un id
    // inexistant : on ne révèle même pas qu'elle existe.
    $sql = "SELECT r.id_reservation, r.date_demande, r.date_intervention,
                   r.adresse_intervention, r.type_reservation, r.type_velo,
                   r.marque_velo, r.description_panne, r.message_client, r.statut,
                   s.id_service, s.titre, s.tarif,
                   COALESCE(s.duree_estimee, 60) AS duree,
                   rp.raison_sociale, u.prenom AS rep_prenom, u.nom AS rep_nom
            FROM reservation r
            INNER JOIN service     s  ON s.id_service     = r.id_service
            INNER JOIN reparateur  rp ON rp.id_reparateur = s.id_reparateur
            INNER JOIN utilisateur u  ON u.id_user        = rp.id_reparateur
            WHERE r.id_reservation = :id
              AND r.id_client      = :id_client";
    $req = $pdo->prepare($sql);
    $req->execute([':id' => $idReservation, ':id_client' => (int) $_SESSION['id_user']]);
    $reservation = $req->fetch();
}

if (!$reservation) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $reservation ? 'Demande envoyée' : 'Réservation introuvable' ?> — Fair'repair</title>
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

    .conteneur { flex: 1; width: 100%; max-width: 760px; margin: 0 auto; padding: 32px 20px 48px; }
    h2 { font-size: 1.05rem; margin-bottom: 12px; }

    .bloc {
        background-color: var(--blanc); border: 1px solid var(--bordure);
        border-radius: var(--rayon); padding: 22px 24px; margin-bottom: 20px;
    }

    /* Bloc vert de succès */
    .succes {
        text-align: center;
        background-color: var(--succes-clair);
        border: 1px solid #BFE5CF;
        border-top: 4px solid var(--succes);
    }
    .succes .icone {
        width: 56px; height: 56px; margin: 0 auto 10px;
        border-radius: 50%; background-color: var(--succes); color: var(--blanc);
        display: flex; align-items: center; justify-content: center; font-size: 1.8rem;
    }
    .succes h1 { font-size: 1.5rem; color: var(--succes); margin-bottom: 4px; }
    .numero { font-size: 1.05rem; }
    .numero strong { font-family: Consolas, monospace; font-size: 1.15rem; letter-spacing: 1px; }

    .etiquette {
        display: inline-block; padding: 3px 10px; border-radius: 20px;
        font-size: 0.78rem; font-weight: 600; white-space: nowrap;
    }
    .etiquette.en_attente { background-color: var(--ambre-clair); color: var(--ambre); }
    .etiquette.confirmee  { background-color: var(--bleu-clair);  color: var(--bleu); }
    .etiquette.terminee   { background-color: var(--succes-clair); color: var(--succes); }
    .etiquette.annulee    { background-color: var(--rouge-clair);  color: var(--rouge); }

    .recap div { display: grid; grid-template-columns: 200px 1fr; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--bordure); font-size: 0.92rem; }
    .recap div:last-child { border-bottom: none; }
    .recap dt { color: var(--texte-doux); }
    .recap dd { overflow-wrap: anywhere; white-space: pre-line; }
    .prix { font-weight: 700; color: var(--vert-fonce); }

    .etapes { list-style: none; counter-reset: etape; }
    .etapes li { counter-increment: etape; position: relative; padding: 0 0 14px 42px; font-size: 0.92rem; }
    .etapes li::before {
        content: counter(etape);
        position: absolute; left: 0; top: 0;
        width: 28px; height: 28px; border-radius: 50%;
        background-color: var(--vert-clair); color: var(--vert-fonce);
        font-weight: 700; display: flex; align-items: center; justify-content: center;
    }
    .etapes strong { display: block; }

    .actions { display: flex; gap: 12px; flex-wrap: wrap; }
    .actions a { flex: 1; text-align: center; }
    .bouton-principal {
        display: inline-block; padding: 13px 18px; background-color: var(--vert); color: var(--blanc);
        border-radius: 6px; font-weight: 600; text-decoration: none;
    }
    .bouton-principal:hover { background-color: var(--vert-fonce); }
    .bouton-petit {
        display: inline-block; padding: 13px 18px; border-radius: 6px; font-weight: 600;
        text-decoration: none; border: 1px solid var(--bordure); background-color: var(--blanc); color: var(--texte);
    }
    .bouton-petit:hover { background-color: var(--fond); }

    .vide-etat { text-align: center; padding: 40px 20px; color: var(--texte-doux); }
    .vide-etat .icone { font-size: 2.4rem; }
    .vide-etat p { margin-bottom: 14px; }

    .pied {
        background-color: var(--blanc); border-top: 1px solid var(--bordure);
        text-align: center; padding: 18px; font-size: 0.82rem; color: var(--texte-doux);
    }

    @media (max-width: 600px) {
        .entete { padding: 12px 16px; }
        .bloc { padding: 18px 16px; }
        .recap div { grid-template-columns: 1fr; gap: 0; }
        .actions { flex-direction: column; }
    }
    </style>
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav>
        <a href="services.php">Les services</a>
        <a href="<?= e(espaceActif()) ?>">Mon espace</a>
    </nav>
</header>

<main class="conteneur">
<?php if (!$reservation): ?>

    <div class="bloc vide-etat">
        <div class="icone">🔍</div>
        <h1>Réservation introuvable</h1>
        <p>Cette réservation n'existe pas ou n'est pas associée à votre compte.</p>
        <a href="dashboard-client.php" class="bouton-principal">Voir mes réservations</a>
    </div>

<?php else:
    $numero = 'FR-' . str_pad((string) $reservation['id_reservation'], 6, '0', STR_PAD_LEFT);
    $nomReparateur = $reservation['raison_sociale'] ?: $reservation['rep_prenom'] . ' ' . $reservation['rep_nom'];
?>

    <section class="bloc succes" role="status">
        <div class="icone" aria-hidden="true">✓</div>
        <h1>Votre demande a bien été envoyée !</h1>
        <p class="numero">Numéro de réservation : <strong><?= e($numero) ?></strong></p>
        <p>
            Statut actuel :
            <span class="etiquette <?= e($reservation['statut']) ?>"><?= e(libelleStatut($reservation['statut'])) ?></span>
        </p>
    </section>

    <section class="bloc">
        <h2>Récapitulatif</h2>
        <dl class="recap">
            <div><dt>Service</dt><dd><strong><?= e($reservation['titre']) ?></strong></dd></div>
            <div><dt>Réparateur</dt><dd><?= e($nomReparateur) ?></dd></div>
            <div><dt>Tarif</dt><dd class="prix"><?= euros($reservation['tarif']) ?></dd></div>
            <div><dt>Durée estimée</dt><dd><?= e(dureeLisible((int) $reservation['duree'])) ?></dd></div>
            <div>
                <dt>Intervention</dt>
                <dd>
                    <?= e(ucfirst(jourFr($reservation['date_intervention']))) ?> à <?= e(heureFr($reservation['date_intervention'])) ?>
                    <?php if ($reservation['type_reservation'] === 'immediate'): ?>
                        <br><span class="etiquette en_attente">Premier créneau disponible</span>
                    <?php endif; ?>
                </dd>
            </div>
            <div><dt>Adresse</dt><dd><?= e($reservation['adresse_intervention']) ?></dd></div>
            <div>
                <dt>Vélo</dt>
                <dd>
                    <?= e(libelleVelo($reservation['type_velo'])) ?>
                    <?php if ($reservation['marque_velo']): ?> — <?= e($reservation['marque_velo']) ?><?php endif; ?>
                </dd>
            </div>
            <div><dt>Description de la panne</dt><dd><?= e($reservation['description_panne']) ?></dd></div>
            <?php if ($reservation['message_client']): ?>
                <div><dt>Informations d'accès</dt><dd><?= e($reservation['message_client']) ?></dd></div>
            <?php endif; ?>
            <div><dt>Demande envoyée le</dt><dd><?= e(dateFr($reservation['date_demande'], true)) ?></dd></div>
        </dl>
    </section>

    <section class="bloc">
        <h2>Et maintenant ?</h2>
        <ol class="etapes">
            <li>
                <strong>Le réparateur étudie votre demande</strong>
                Il dispose de 48 heures pour l'accepter ou la refuser.
            </li>
            <li>
                <strong>Vous êtes prévenu de sa réponse</strong>
                Elle s'affiche dans « Mes réservations », avec son éventuel message.
            </li>
            <li>
                <strong>Vous payez seulement une fois la réservation confirmée</strong>
                Aucun montant n'est prélevé tant que le réparateur n'a pas accepté.
                Annulation gratuite jusqu'à 24 h avant l'intervention.
            </li>
        </ol>
    </section>

    <div class="actions">
        <a href="dashboard-client.php" class="bouton-principal">Voir mes réservations</a>
        <a href="services.php" class="bouton-petit">Réserver un autre service</a>
    </div>

<?php endif; ?>
</main>

<footer class="pied">Fair'repair — la réparation de vélo à domicile, à Paris</footer>

</body>
</html>
