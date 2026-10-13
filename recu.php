<?php
/**
 * Fair'repair — Reçu de paiement
 * ------------------------------
 * recu.php?reservation=<id_reservation>
 *
 * Page d'arrivée après un paiement accepté (POST/Redirect/GET depuis
 * payement.php) : un F5 recharge le reçu, sans rien débiter à nouveau.
 */

// ============================================================
//  1. LOGIQUE
// ============================================================

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/affichage.php';

// --- Contrôle d'accès : AVANT tout le reste ---
exigerRole('client');

$idReservation = filter_var($_GET['reservation'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$paiement = false;
if ($idReservation !== false) {
    // PROTECTION IDOR : « AND r.id_client = :id_client » avec l'id de la
    // SESSION. Sans ce filtre, changer le numéro dans l'URL suffirait pour
    // lire le reçu d'un autre client (montant, adresse, moyen de paiement).
    // Le reçu d'un autre donne « aucune ligne », comme un numéro inexistant.
    $sql = "SELECT p.montant, p.commission_plateforme, p.montant_reparateur, p.taux_commission,
                   p.date_payement, p.mode_payement, p.carte_4_derniers, p.reseau_carte,
                   p.compte_paypal, p.reference_transaction,
                   r.id_reservation, r.date_intervention, r.adresse_intervention,
                   s.titre,
                   rp.raison_sociale, u.prenom, u.nom
            FROM payement p
            INNER JOIN reservation r  ON r.id_reservation = p.id_reservation
            INNER JOIN service     s  ON s.id_service     = r.id_service
            INNER JOIN reparateur  rp ON rp.id_reparateur = s.id_reparateur
            INNER JOIN utilisateur u  ON u.id_user        = rp.id_reparateur
            WHERE r.id_reservation = :id
              AND r.id_client      = :id_client
              AND p.statut         = 'valide'";
    $req = $pdo->prepare($sql);
    $req->execute([':id' => $idReservation, ':id_client' => (int) $_SESSION['id_user']]);
    $paiement = $req->fetch();
}

if (!$paiement) {
    http_response_code(404);
} else {
    // Tout ce que la vue affiche est préparé ici
    $numeroReservation = 'FR-' . str_pad((string) $paiement['id_reservation'], 6, '0', STR_PAD_LEFT);
    $nomReparateur     = $paiement['raison_sociale'] ?: $paiement['prenom'] . ' ' . $paiement['nom'];
    $dateIntervention  = ucfirst(jourFr($paiement['date_intervention'])) . ' à ' . heureFr($paiement['date_intervention']);
    $tva               = tvaComprise((float) $paiement['montant']);
    $tauxCommission    = rtrim(rtrim(number_format((float) $paiement['taux_commission'], 2, ',', ''), '0'), ',');

    // Moyen de paiement, toujours MASQUÉ
    if ($paiement['mode_payement'] === 'paypal') {
        $moyen       = 'PayPal (simulation)';
        $moyenDetail = $paiement['compte_paypal'] ? masquerEmail($paiement['compte_paypal']) : null;
    } else {
        $moyen       = 'Carte bancaire' . ($paiement['reseau_carte'] ? ' ' . $paiement['reseau_carte'] : '');
        $moyenDetail = $paiement['carte_4_derniers'] ? masquerCarte($paiement['carte_4_derniers']) : null;
    }

    // TODO facture PDF : générer le PDF (ex. bibliothèque FPDF ou Dompdf) dans
    // une page facture.php?reservation=<id>, avec le MÊME filtre id_client.
    // En attendant, le bouton est affiché mais désactivé.
    $factureDisponible = false;
}
?>
<!DOCTYPE html>
<!-- ============================================================
     2. AFFICHAGE
     ============================================================ -->
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $paiement ? 'Reçu de paiement' : 'Reçu introuvable' ?> — Fair'repair</title>
    <style>
    :root {
        --vert:         #0F766E;
        --vert-fonce:   #0B5A54;
        --vert-clair:   #E6F4F2;
        --bleu:         #1E50D4;
        --texte:        #0D1829;
        --texte-doux:   #5A7290;
        --bordure:      #D6E0EE;
        --fond:         #F5F8FC;
        --blanc:        #FFFFFF;
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
    .entete nav { display: flex; gap: 20px; flex-wrap: wrap; }
    .entete nav a { color: var(--texte-doux); text-decoration: none; font-weight: 500; font-size: 0.92rem; }
    .entete nav a:hover { color: var(--vert); }

    .conteneur { flex: 1; width: 100%; max-width: 680px; margin: 0 auto; padding: 32px 20px 48px; }
    h2 { font-size: 1.05rem; margin-bottom: 12px; }

    .bloc {
        background-color: var(--blanc); border: 1px solid var(--bordure);
        border-radius: var(--rayon); padding: 22px 24px; margin-bottom: 20px;
    }
    .bloc-entete { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .bloc-entete h2 { margin-bottom: 0; }

    .succes {
        text-align: center; background-color: var(--succes-clair);
        border: 1px solid #BFE5CF; border-top: 4px solid var(--succes);
    }
    .succes .icone {
        width: 56px; height: 56px; margin: 0 auto 10px; border-radius: 50%;
        background-color: var(--succes); color: var(--blanc);
        display: flex; align-items: center; justify-content: center; font-size: 1.8rem;
    }
    .succes h1 { font-size: 1.4rem; color: var(--succes); }
    .montant-paye { font-size: 2.2rem; font-weight: 700; color: var(--texte); line-height: 1.3; }

    .etiquette { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }
    .etiquette.payee { background-color: var(--succes-clair); color: var(--succes); }

    .lignes div { display: grid; grid-template-columns: 190px 1fr; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--bordure); font-size: 0.92rem; }
    .lignes div:last-child { border-bottom: none; }
    .lignes dt { color: var(--texte-doux); }
    .lignes dd { overflow-wrap: anywhere; }
    .chiffres { font-family: Consolas, "Courier New", monospace; letter-spacing: 1px; }

    .montants div { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; font-size: 0.92rem; }
    .montants .total { font-weight: 700; font-size: 1.05rem; border-bottom: 1px solid var(--bordure); padding-bottom: 10px; margin-bottom: 4px; }
    .montants .detail dt { color: var(--texte-doux); }

    .actions { display: flex; gap: 12px; flex-wrap: wrap; }
    .actions > * { flex: 1; text-align: center; }
    .bouton-principal {
        display: inline-block; padding: 13px 18px; background-color: var(--vert); color: var(--blanc);
        border: none; border-radius: 6px; font-weight: 600; font-size: 0.95rem;
        font-family: inherit; text-decoration: none;
    }
    .bouton-principal:hover { background-color: var(--vert-fonce); }
    .bouton-petit {
        display: inline-block; padding: 13px 18px; border-radius: 6px; font-weight: 600; font-size: 0.95rem;
        font-family: inherit; text-decoration: none; border: 1px solid var(--bordure);
        background-color: var(--blanc); color: var(--texte); cursor: pointer;
    }
    .bouton-petit:disabled { color: var(--texte-doux); cursor: not-allowed; }
    .aide { color: var(--texte-doux); font-size: 0.8rem; text-align: center; margin-top: 8px; }

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
        .lignes div { grid-template-columns: 1fr; gap: 0; }
        .actions { flex-direction: column; }
    }
    @media print {
        .entete, .pied, .actions, .aide { display: none; }
        body { background: none; }
    }
    </style>
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav>
        <a href="services.php">Les services</a>
        <a href="dashboard-client.php">Mon espace</a>
    </nav>
</header>

<main class="conteneur">
<?php if (!$paiement): ?>

    <div class="bloc vide-etat">
        <div class="icone">🔍</div>
        <h1>Reçu introuvable</h1>
        <p>Aucun paiement validé ne correspond à cette réservation sur votre compte.</p>
        <a href="dashboard-client.php" class="bouton-principal">Retour à mes réservations</a>
    </div>

<?php else: ?>

    <section class="bloc succes" role="status">
        <div class="icone" aria-hidden="true">✓</div>
        <h1>Paiement accepté</h1>
        <p class="montant-paye"><?= euros($paiement['montant']) ?></p>
        <p>Merci ! Votre réparateur est prévenu que l'intervention est réglée.</p>
    </section>

    <section class="bloc">
        <div class="bloc-entete">
            <h2>Détails de la transaction</h2>
            <span class="etiquette payee">Payé</span>
        </div>
        <dl class="lignes">
            <div><dt>Référence</dt><dd class="chiffres"><?= e($paiement['reference_transaction']) ?></dd></div>
            <div><dt>Date du paiement</dt><dd><?= e(dateFr($paiement['date_payement'], true)) ?></dd></div>
            <div>
                <dt>Moyen de paiement</dt>
                <dd>
                    <?= e($moyen) ?>
                    <?php if ($moyenDetail): ?><br><span class="chiffres"><?= e($moyenDetail) ?></span><?php endif; ?>
                </dd>
            </div>
            <div><dt>Réservation</dt><dd><?= e($numeroReservation) ?></dd></div>
            <div><dt>Service</dt><dd><strong><?= e($paiement['titre']) ?></strong></dd></div>
            <div><dt>Réparateur</dt><dd><?= e($nomReparateur) ?></dd></div>
            <div><dt>Intervention</dt><dd><?= e($dateIntervention) ?><br><?= e($paiement['adresse_intervention']) ?></dd></div>
        </dl>
    </section>

    <section class="bloc">
        <h2>Détail du montant</h2>
        <dl class="montants">
            <div class="total"><dt>Total payé TTC</dt><dd><?= euros($paiement['montant']) ?></dd></div>
            <div class="detail"><dt>dont TVA (<?= (int) TAUX_TVA ?> %)</dt><dd><?= euros($tva) ?></dd></div>
            <div class="detail"><dt>Commission plateforme (<?= e($tauxCommission) ?> %)</dt><dd><?= euros($paiement['commission_plateforme']) ?></dd></div>
            <div class="detail"><dt>Montant reversé au réparateur</dt><dd><?= euros($paiement['montant_reparateur']) ?></dd></div>
        </dl>
    </section>

    <div class="actions">
        <!-- TODO : facture PDF (génération prévue plus tard, voir la zone 1) -->
        <button type="button" class="bouton-petit" <?= $factureDisponible ? '' : 'disabled' ?>
                title="La facture PDF sera bientôt disponible">📄 Télécharger la facture PDF</button>
        <a href="dashboard-client.php" class="bouton-principal">Retour à mes réservations</a>
    </div>
    <p class="aide">La facture PDF sera disponible prochainement. Vous pouvez imprimer cette page en attendant.</p>

<?php endif; ?>
</main>

<footer class="pied">Fair'repair — paiement simulé dans le cadre d'un projet pédagogique</footer>

</body>
</html>
