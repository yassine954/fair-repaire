<?php
/**
 * Fair'repair — Tableau de bord CLIENT
 * ------------------------------------
 * Page protégée : réservée aux utilisateurs connectés ayant le rôle "client".
 *
 * Contenu :
 *   1. Quatre cartes de statistiques
 *   2. Le tableau des réservations
 *   3. Les avis déposés + les interventions en attente de notation
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/affichage.php';
require_once __DIR__ . '/recherche-reparateurs.php';

// --- Bouton « Client / Réparateur » de l'en-tête ---
// Traité AVANT tout affichage (il se termine par une redirection header()).
// Traité aussi AVANT exigerRole() : un onglet resté ouvert sur l'ancien
// espace doit pouvoir basculer même si le rôle actif a changé ailleurs.
traiterDemandeBascule();

// --- Bouton « Révoquer » de « Mes appareils connectés » (POST + CSRF) ---
traiterRevocationAppareil();

// --- Middleware : seul le rôle ACTIF « client » ouvre cette page ---
exigerRole('client');

$idClient = $_SESSION['id_user'];
$message  = '';
$erreur   = '';

// Message laissé par une redirection (bascule refusée, profil fermé…)
$flash = lireFlash();
if ($flash && $flash['type'] === 'succes') {
    $message = $flash['texte'];
} elseif ($flash) {
    $erreur = $flash['texte'];
}

// ============================================================
//  ACTION : déconnexion
// ============================================================
// Traitée ici, tout en haut du fichier, car header() doit partir
// AVANT le moindre affichage HTML.
//
// Pourquoi un formulaire POST et pas un simple lien ?
// Un lien <a href="...?action=deconnexion"> se déclenche avec une
// requête GET, qu'un site malveillant peut provoquer à distance
// (une balise <img src="..."> suffit) : le visiteur serait déconnecté
// à son insu. Avec un POST protégé par un jeton CSRF, c'est impossible.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deconnexion') {

    if (verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        // Se déconnecter = aussi « oublier cet appareil » : son jeton
        // « Se souvenir de moi » est supprimé, sinon le cookie rouvrirait
        // la session dès la page suivante. (fermerSession() le fait aussi ;
        // le second appel est sans effet.)
        // Déconnexion journalisée AVANT fermerSession(), qui vide la session
        // (l'e-mail et l'id du compte y sont lus).
        enregistrerTentative($pdo, $_SESSION['email'], $_SERVER['REMOTE_ADDR'] ?? '',
                             true, 'deconnexion', (int) $_SESSION['id_user']);
        supprimerJetonSouvenir($pdo);
        fermerSession();
        header('Location: connexion.php?msg=deconnecte');
        exit;
    }
    $erreur = "Requête invalide. Merci de recharger la page.";
}

// ============================================================
//  ACTION : annulation d'une réservation
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'annuler') {

    if (!verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $erreur = "Requête invalide. Merci de recharger la page.";
    } else {
        $idReservation = (int) ($_POST['id_reservation'] ?? 0);

        // Le "AND id_client = :id_client" est la sécurité essentielle :
        // sans lui, un client pourrait annuler la réservation d'un autre
        // en modifiant simplement l'identifiant envoyé (faille IDOR).
        $sql = "UPDATE reservation
                SET statut = 'annulee'
                WHERE id_reservation = :id_reservation
                  AND id_client      = :id_client
                  AND statut         = 'en_attente'";

        $req = $pdo->prepare($sql);
        $req->execute([
            ':id_reservation' => $idReservation,
            ':id_client'      => $idClient,
        ]);

        if ($req->rowCount() > 0) {
            $message = "Votre demande a bien été annulée.";
        } else {
            $erreur = "Cette réservation ne peut plus être annulée.";
        }
    }
}

// ============================================================
//  1. STATISTIQUES
// ============================================================
// Une seule requête grâce à l'agrégation conditionnelle :
// SUM(CASE WHEN ... THEN 1 ELSE 0 END) compte les lignes qui
// remplissent une condition, sans avoir à faire 4 requêtes.
$sql = "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN r.statut IN ('en_attente','confirmee') THEN 1 ELSE 0 END) AS en_cours,
            SUM(CASE WHEN r.statut = 'terminee' THEN 1 ELSE 0 END) AS terminees
        FROM reservation r
        WHERE r.id_client = :id_client";

$req = $pdo->prepare($sql);
$req->execute([':id_client' => $idClient]);
$stats = $req->fetch();

// Montant réellement dépensé : uniquement les paiements validés
$sql = "SELECT COALESCE(SUM(p.montant), 0) AS depense
        FROM payement p
        INNER JOIN reservation r ON r.id_reservation = p.id_reservation
        WHERE r.id_client = :id_client
          AND p.statut    = 'valide'";

$req = $pdo->prepare($sql);
$req->execute([':id_client' => $idClient]);
$depense = $req->fetchColumn();

// ============================================================
//  2. LISTE DES RÉSERVATIONS
// ============================================================
// LEFT JOIN sur payement et avis car ils sont facultatifs (cardinalité 0,1) :
// une réservation peut exister sans paiement et sans avis.
$sql = "SELECT
            r.id_reservation,
            r.date_demande,
            r.date_intervention,
            r.adresse_intervention,
            r.statut,
            r.message_reparateur,
            r.date_reponse,
            s.titre        AS service,
            s.tarif,
            u.nom          AS rep_nom,
            u.prenom       AS rep_prenom,
            u.telephone    AS rep_tel,
            p.statut       AS statut_payement,
            p.montant      AS montant_paye,
            a.id_avis
        FROM reservation r
        INNER JOIN service     s ON s.id_service     = r.id_service
        INNER JOIN reparateur  rp ON rp.id_reparateur = s.id_reparateur
        INNER JOIN utilisateur u ON u.id_user        = rp.id_reparateur
        LEFT  JOIN payement    p ON p.id_reservation = r.id_reservation
        LEFT  JOIN avis        a ON a.id_reservation = r.id_reservation
        WHERE r.id_client = :id_client
        ORDER BY r.date_intervention DESC";

$req = $pdo->prepare($sql);
$req->execute([':id_client' => $idClient]);
$reservations = $req->fetchAll();

// ============================================================
//  3. AVIS DÉPOSÉS
// ============================================================
$sql = "SELECT
            a.id_avis, a.note, a.commentaire, a.date_avis,
            s.titre  AS service,
            u.prenom AS rep_prenom,
            u.nom    AS rep_nom
        FROM avis a
        INNER JOIN reservation  r  ON r.id_reservation = a.id_reservation
        INNER JOIN service      s  ON s.id_service     = r.id_service
        INNER JOIN reparateur   rp ON rp.id_reparateur = s.id_reparateur
        INNER JOIN utilisateur  u  ON u.id_user        = rp.id_reparateur
        WHERE r.id_client = :id_client
        ORDER BY a.date_avis DESC";

$req = $pdo->prepare($sql);
$req->execute([':id_client' => $idClient]);
$avis = $req->fetchAll();

// Interventions terminées qui n'ont pas encore reçu d'avis
$sql = "SELECT r.id_reservation, r.date_intervention, s.titre AS service
        FROM reservation r
        INNER JOIN service s ON s.id_service = r.id_service
        LEFT  JOIN avis    a ON a.id_reservation = r.id_reservation
        WHERE r.id_client = :id_client
          AND r.statut    = 'terminee'
          AND a.id_avis IS NULL
        ORDER BY r.date_intervention DESC";

$req = $pdo->prepare($sql);
$req->execute([':id_client' => $idClient]);
$aNoter = $req->fetchAll();

// etoiles(), libelleStatut(), euros() et dateFr() sont dans affichage.php

// ============================================================
//  4. RÉPARATEURS PRÈS DE CHEZ MOI — liste calculée côté serveur
// ============================================================
// La carte est mise à jour en JavaScript (fetch vers api/reparateurs.php).
// Cette première liste est calculée ici, en PHP, avec la même fonction :
// la page affiche donc des réparateurs même si JavaScript est désactivé
// ou si la carte ne se charge pas. Sans JavaScript, le formulaire de
// filtres est envoyé en GET et recharge la page avec les filtres choisis.
//
// Le curseur de rayon envoie une POSITION (0 à 4) et non des km :
// on la traduit avec cette liste blanche.
const RAYONS_CARTE = [1, 2, 5, 10, 20];
$pasRayon = filter_var($_GET['rayon_pas'] ?? '', FILTER_VALIDATE_INT,
                       ['options' => ['min_range' => 0, 'max_range' => count(RAYONS_CARTE) - 1]]);
if ($pasRayon === false) {
    $pasRayon = 2;   // 5 km par défaut
}

// Côté serveur, on ne connaît pas la position de l'utilisateur (elle n'est
// connue que du navigateur) : les distances partent du centre de Paris.
$lectureCarte = lireFiltresRecherche([
    'lat'       => (string) PARIS_CENTRE['lat'],
    'lng'       => (string) PARIS_CENTRE['lng'],
    'rayon'     => (string) RAYONS_CARTE[$pasRayon],
    'note_min'  => $_GET['note_min']  ?? '',
    'tarif_max' => $_GET['tarif_max'] ?? '',
]);
$filtresCarte       = $lectureCarte['filtres'];
// Un utilisateur aussi réparateur ne se voit pas lui-même sur la carte
// (id pris dans la session, jamais dans l'URL)
$filtresCarte['exclure_id'] = (int) $idClient;
$reparateursProches = rechercherReparateurs($pdo, $filtresCarte);
$nbProches          = count($reparateursProches);

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon espace — Fair'repair</title>
    <style>
    /* =========================================================
       Fair'repair — styles communs
       Inclus par les pages via : require __DIR__ . '/includes/style.php';
       ========================================================= */

    :root {
        --vert:         #0F766E;
        --vert-fonce:   #0B5A54;
        --vert-clair:   #E6F4F2;
        --bleu:         #1E50D4;
        --bleu-clair:   #E8EEFC;
        --violet:       #6D28D9;
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
    .entete nav { display: flex; align-items: center; gap: 20px; }
    .entete nav a { color: var(--texte-doux); text-decoration: none; font-weight: 500; font-size: 0.92rem; }
    .entete nav a:hover { color: var(--vert); }
    .entete nav a.actif { color: var(--vert); font-weight: 600; }
    .form-deconnexion { display: inline; margin: 0; }
    .lien-bouton {
        background: none;
        border: none;
        padding: 0;
        font-family: inherit;
        font-size: 0.92rem;
        font-weight: 500;
        color: var(--texte-doux);
        cursor: pointer;
    }
    .lien-bouton:hover { color: var(--rouge); text-decoration: underline; }
    .badge-user {
        background-color: var(--vert-clair);
        color: var(--vert-fonce);
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    /* Bouton "pilule" de changement de mode : contour vert, se remplit au survol.
       Il se distingue des simples liens de la navigation. */
    .bouton-mode {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border: 1.5px solid var(--vert);
        border-radius: 20px;
        background-color: var(--blanc);
        color: var(--vert);
        font-family: inherit;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
        transition: background-color 0.15s, color 0.15s;
    }
    .bouton-mode:hover,
    .bouton-mode:focus-visible { background-color: var(--vert); color: var(--blanc); outline: none; }
    a.bouton-mode { text-decoration: none; }
    .entete nav a.bouton-mode { color: var(--vert); font-weight: 600; font-size: 0.85rem; }
    .entete nav a.bouton-mode:hover { color: var(--blanc); }

    /* Sélecteur d'espace « Client / Réparateur » : deux moitiés dans une même pilule */
    .commutateur {
        display: inline-flex;
        margin: 0;
        padding: 2px;
        border: 1.5px solid var(--vert);
        border-radius: 20px;
        background-color: var(--blanc);
    }
    .commutateur-option {
        padding: 4px 12px;
        border: none;
        border-radius: 16px;
        background: none;
        font-family: inherit;
        font-size: 0.83rem;
        font-weight: 600;
        line-height: 1.5;
        color: var(--vert);
        cursor: pointer;
        white-space: nowrap;
    }
    button.commutateur-option:hover,
    button.commutateur-option:focus-visible { background-color: var(--vert-clair); outline: none; }
    .commutateur-option.actif { background-color: var(--vert); color: var(--blanc); cursor: default; }

    /* Demande réparateur en cours d'examen : badge non cliquable */
    .badge-attente {
        background-color: var(--ambre-clair);
        color: var(--ambre);
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        white-space: nowrap;
    }

    /* ---------- Conteneurs ---------- */
    .page-centree {
        flex: 1;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        padding: 40px 20px;
    }
    .carte-formulaire {
        background-color: var(--blanc);
        border: 1px solid var(--bordure);
        border-radius: var(--rayon);
        padding: 36px 40px;
        width: 100%;
        max-width: 440px;
        box-shadow: 0 2px 10px rgba(13, 24, 41, 0.05);
    }
    .conteneur {
        flex: 1;
        width: 100%;
        max-width: 1080px;
        margin: 0 auto;
        padding: 32px 20px 48px;
    }

    h1 { font-size: 1.7rem; margin-bottom: 6px; }
    h2 { font-size: 1.15rem; margin-bottom: 14px; }
    .sous-titre { color: var(--texte-doux); margin-bottom: 26px; font-size: 0.95rem; }

    /* ---------- Formulaires ---------- */
    fieldset { border: none; border-top: 1px solid var(--bordure); padding-top: 18px; margin-bottom: 22px; }
    legend {
        font-weight: 600; font-size: 0.9rem; text-transform: uppercase;
        letter-spacing: 0.5px; color: var(--vert); padding-right: 10px;
    }
    .ligne { display: flex; gap: 16px; }
    .ligne .champ { flex: 1; }
    .champ { margin-bottom: 16px; display: flex; flex-direction: column; }
    label { font-size: 0.88rem; font-weight: 600; margin-bottom: 5px; }
    .obligatoire { color: var(--rouge); }

    input[type="text"], input[type="email"], input[type="tel"],
    input[type="password"], input[type="number"], select, textarea {
        padding: 10px 12px;
        border: 1px solid var(--bordure);
        border-radius: 6px;
        font-size: 0.95rem;
        font-family: inherit;
        background-color: var(--blanc);
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    input:focus, select:focus, textarea:focus {
        outline: none;
        border-color: var(--vert);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15);
    }
    input.invalide { border-color: var(--rouge); background-color: var(--rouge-clair); }

    .message-erreur { color: var(--rouge); font-size: 0.82rem; margin-top: 4px; }
    .aide { color: var(--texte-doux); font-size: 0.8rem; margin-top: 4px; }

    /* ---------- Alertes ---------- */
    .alerte { padding: 14px 16px; border-radius: 6px; margin-bottom: 22px; font-size: 0.92rem; }
    .alerte-erreur { background-color: var(--rouge-clair); border-left: 4px solid var(--rouge); color: var(--rouge); }
    .alerte-succes { background-color: var(--succes-clair); border-left: 4px solid var(--succes); color: var(--succes); }
    .alerte-info   { background-color: var(--bleu-clair);  border-left: 4px solid var(--bleu);  color: var(--bleu); }
    .alerte a { color: inherit; font-weight: 600; }

    /* Réponse du réparateur, affichée sous le nom du service.
       Reprend .alerte-succes (vert) ou .alerte-erreur (rouge) en plus compact. */
    .reponse-rep {
        margin: 10px 0 0;
        padding: 10px 12px;
        max-width: 340px;
        font-size: 0.85rem;
        white-space: normal;
    }
    .reponse-rep-entete {
        display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px;
        margin-bottom: 4px; font-size: 0.8rem;
    }
    .reponse-rep-texte { color: var(--texte); font-style: italic; overflow-wrap: anywhere; }

    /* ---------- Boutons ---------- */
    .bouton-principal {
        width: 100%; padding: 13px;
        background-color: var(--vert); color: var(--blanc);
        border: none; border-radius: 6px;
        font-size: 1rem; font-weight: 600; font-family: inherit;
        cursor: pointer; transition: background-color 0.15s;
    }
    .bouton-principal:hover { background-color: var(--vert-fonce); }

    .bouton-petit {
        display: inline-block;
        padding: 6px 13px;
        border-radius: 5px;
        font-size: 0.83rem;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid var(--bordure);
        background-color: var(--blanc);
        color: var(--texte);
        cursor: pointer;
        font-family: inherit;
    }
    .bouton-petit:hover { background-color: var(--fond); }
    .bouton-petit.primaire { background-color: var(--vert); border-color: var(--vert); color: var(--blanc); }
    .bouton-petit.primaire:hover { background-color: var(--vert-fonce); }
    .bouton-petit.danger { color: var(--rouge); border-color: #F0C4C6; }
    .bouton-petit.danger:hover { background-color: var(--rouge-clair); }
    .actions-cellule { display: flex; gap: 6px; flex-wrap: wrap; }

    .lien-bas { text-align: center; margin-top: 20px; font-size: 0.88rem; color: var(--texte-doux); line-height: 2; }
    .lien-bas a { color: var(--vert); font-weight: 600; }

    /* ---------- Cartes de statistiques ---------- */
    .grille-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 16px;
        margin-bottom: 30px;
    }
    .stat {
        background-color: var(--blanc);
        border: 1px solid var(--bordure);
        border-radius: var(--rayon);
        padding: 18px 20px;
        border-top: 3px solid var(--vert);
    }
    .stat.bleu   { border-top-color: var(--bleu); }
    .stat.ambre  { border-top-color: var(--ambre); }
    .stat.violet { border-top-color: var(--violet); }
    .stat-valeur { font-size: 1.9rem; font-weight: 700; line-height: 1.2; }
    .stat-libelle { font-size: 0.82rem; color: var(--texte-doux); text-transform: uppercase; letter-spacing: 0.4px; }

    /* ---------- Sections ---------- */
    .bloc {
        background-color: var(--blanc);
        border: 1px solid var(--bordure);
        border-radius: var(--rayon);
        padding: 24px 26px;
        margin-bottom: 26px;
    }
    .bloc-entete {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
    }
    .bloc-entete h2 { margin-bottom: 0; }

    /* ---------- Tableaux ---------- */
    .zone-tableau { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .zone-tableau table { min-width: 820px; }   /* mobile : défilement horizontal plutôt que colonnes écrasées */
    table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    thead th {
        text-align: left;
        padding: 10px 12px;
        background-color: var(--fond);
        border-bottom: 2px solid var(--bordure);
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: var(--texte-doux);
        white-space: nowrap;
    }
    tbody td { padding: 12px; border-bottom: 1px solid var(--bordure); vertical-align: middle; }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:hover { background-color: #FAFCFF; }
    .cellule-titre { font-weight: 600; }
    .cellule-sec { color: var(--texte-doux); font-size: 0.85rem; }

    /* ---------- Étiquettes de statut ---------- */
    .etiquette {
        display: inline-block;
        padding: 4px 11px;
        border-radius: 20px;
        font-size: 0.78rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .etiquette.en_attente { background-color: var(--ambre-clair); color: var(--ambre); }
    .etiquette.confirmee  { background-color: var(--bleu-clair);  color: var(--bleu); }
    .etiquette.terminee   { background-color: var(--succes-clair); color: var(--succes); }
    .etiquette.annulee    { background-color: var(--rouge-clair);  color: var(--rouge); }
    .etiquette.appareil-actuel { background-color: var(--vert-clair); color: var(--vert-fonce); margin-left: 6px; }

    /* ---------- Étoiles ---------- */
    .etoiles { color: #E0A400; letter-spacing: 2px; font-size: 0.95rem; }
    .etoiles .vide { color: var(--bordure); }

    /* ---------- Avis ---------- */
    .avis {
        border: 1px solid var(--bordure);
        border-radius: 8px;
        padding: 14px 16px;
        margin-bottom: 12px;
    }
    .avis-entete { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 8px; }
    .avis-commentaire { font-size: 0.9rem; color: var(--texte-doux); font-style: italic; }

    /* ---------- État vide ---------- */
    .vide-etat { text-align: center; padding: 34px 20px; color: var(--texte-doux); }
    .vide-etat p { margin-bottom: 14px; font-size: 0.93rem; }

    /* ---------- Pied de page ---------- */
    .pied {
        background-color: var(--blanc);
        border-top: 1px solid var(--bordure);
        text-align: center;
        padding: 18px;
        font-size: 0.82rem;
        color: var(--texte-doux);
    }

    /* ---------- Carte des réparateurs ---------- */
    .bloc { scroll-margin-top: 16px; }            /* lien #carte : un peu d'air au-dessus */
    .etiquette.compteur-carte { background-color: var(--bleu-clair); color: var(--bleu); }

    .filtres-carte {
        display: grid;
        grid-template-columns: minmax(180px, 1.5fr) 1fr 1fr auto;
        gap: 14px;
        align-items: end;
        padding: 14px 16px;
        margin-bottom: 14px;
        background-color: var(--fond);
        border: 1px solid var(--bordure);
        border-radius: 8px;
    }
    .filtre { display: flex; flex-direction: column; min-width: 0; }
    .filtre label { font-size: 0.82rem; margin-bottom: 4px; }
    .filtre select, .filtre input[type="number"] { padding: 8px 10px; font-size: 0.9rem; width: 100%; }
    .filtre input[type="range"] { width: 100%; accent-color: var(--vert); cursor: pointer; }
    .reperes { display: flex; justify-content: space-between; font-size: 0.72rem; color: var(--texte-doux); }
    .filtre-actions { flex-direction: row; gap: 8px; flex-wrap: wrap; }

    .carte-avis { padding: 10px 14px; font-size: 0.86rem; margin-bottom: 12px; }

    .carte {
        width: 100%;
        height: 450px;
        border: 1px solid var(--bordure);
        border-radius: var(--rayon);
        z-index: 0;          /* garde les calques Leaflet sous le reste de la page */
    }
    .carte-maj { margin-top: 6px; text-align: right; }

    /* Marqueur réparateur : pastille verte (divIcon, pas d'image à charger) */
    .pin-reparateur span {
        display: flex; align-items: center; justify-content: center;
        width: 34px; height: 34px;
        border-radius: 50% 50% 50% 0;        /* forme de goutte */
        transform: rotate(-45deg);
        background-color: var(--vert);
        border: 2px solid var(--blanc);
        box-shadow: 0 2px 6px rgba(13, 24, 41, 0.35);
    }
    .pin-reparateur span::before { content: "🔧"; transform: rotate(45deg); font-size: 15px; }

    /* Fenêtre (popup) d'un réparateur */
    .popup-rep { font-family: inherit; font-size: 0.88rem; line-height: 1.5; min-width: 190px; }
    .popup-rep strong { font-size: 0.98rem; display: block; }
    .popup-rep .ligne-popup { color: var(--texte-doux); }
    .popup-rep .prix { margin: 4px 0 10px; color: var(--texte); }
    .leaflet-container .popup-rep a.bouton-petit.primaire { color: var(--blanc); }

    /* Liste sous la carte */
    .liste-titre { font-size: 0.95rem; margin: 22px 0 10px; }
    .liste-reparateurs {
        list-style: none;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 12px;
    }
    .carte-rep {
        border: 1px solid var(--bordure);
        border-radius: 8px;
        padding: 14px 16px;
        cursor: pointer;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .carte-rep:hover { border-color: var(--vert); }
    .carte-rep.selectionne { border-color: var(--vert); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15); }
    .carte-rep-entete { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
    .etiquette.distance { background-color: var(--vert-clair); color: var(--vert-fonce); }
    .carte-rep .note { font-size: 0.85rem; margin: 4px 0; }
    .carte-rep .prix { font-size: 0.88rem; margin-bottom: 10px; }
    .carte-rep-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .liste-reparateurs .vide-etat { grid-column: 1 / -1; }

    /* ---------- Mobile ---------- */
    @media (max-width: 640px) {
        .carte-formulaire { padding: 24px 20px; }
        .ligne { flex-direction: column; gap: 0; }
        .entete { padding: 12px 16px; }
        .entete nav { flex-wrap: wrap; gap: 12px 16px; }
        .bloc { padding: 18px 16px; }
        .filtres-carte { grid-template-columns: 1fr 1fr; padding: 12px; }
        .filtre-rayon, .filtre-actions { grid-column: 1 / -1; }
        .carte { height: 360px; }
    }
    </style>

    <!-- Leaflet 1.9.4 (cartographie libre) : version figée + empreinte d'intégrité (SRI).
         Si le fichier du CDN était modifié, le navigateur refuserait de l'utiliser. -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css"
          integrity="sha512-h9FcoyWjHcOcmEVkxOfTLnmZFWIH0iZhZT1H2TbOq55xssQGEJHEaIm+PgoUaZbRvQTNTluNOEfb1ZRy6D3BOw=="
          crossorigin="anonymous" referrerpolicy="no-referrer">
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav>
        <a href="dashboard-client.php" class="actif">Mon espace</a>
        <a href="services.php">Les services</a>
        <a href="#carte">La carte</a>
        <a href="profil.php">Mon profil</a>
        <span class="badge-user"><?= e($_SESSION['prenom']) ?></span>

        <!-- Client / Réparateur, « Devenir réparateur » ou « Demande en cours » -->
        <?php afficherCommutateurRole($csrfToken); ?>

        <!-- Déconnexion : formulaire POST protégé par le jeton CSRF -->
        <form method="post" action="dashboard-client.php" class="form-deconnexion">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="deconnexion">
            <button type="submit" class="lien-bouton">Déconnexion</button>
        </form>
    </nav>
</header>

<main class="conteneur">

    <h1>Bonjour <?= e($_SESSION['prenom']) ?></h1>
    <p class="sous-titre">Retrouvez ici vos réservations et vos avis.</p>

    <?php if ($message): ?>
        <div class="alerte alerte-succes"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alerte alerte-erreur"><?= e($erreur) ?></div>
    <?php endif; ?>

    <!-- ============ 1. STATISTIQUES ============ -->
    <div class="grille-stats">
        <div class="stat">
            <div class="stat-valeur"><?= (int) $stats['total'] ?></div>
            <div class="stat-libelle">Réservations</div>
        </div>
        <div class="stat bleu">
            <div class="stat-valeur"><?= (int) $stats['en_cours'] ?></div>
            <div class="stat-libelle">En cours</div>
        </div>
        <div class="stat violet">
            <div class="stat-valeur"><?= (int) $stats['terminees'] ?></div>
            <div class="stat-libelle">Terminées</div>
        </div>
        <div class="stat ambre">
            <div class="stat-valeur"><?= euros($depense) ?></div>
            <div class="stat-libelle">Total dépensé</div>
        </div>
    </div>

    <!-- ============ CARTE DES RÉPARATEURS ============ -->
    <section class="bloc" id="carte">
        <div class="bloc-entete">
            <h2>Réparateurs près de chez moi</h2>
            <!-- aria-live : les lecteurs d'écran annoncent le nouveau nombre -->
            <span class="etiquette compteur-carte" id="carte-compteur" aria-live="polite">
                <?= $nbProches ?> réparateur<?= $nbProches > 1 ? 's' : '' ?> trouvé<?= $nbProches > 1 ? 's' : '' ?>
            </span>
        </div>

        <!-- Filtres : formulaire HTML classique. Avec JavaScript, l'envoi est
             intercepté et la carte se met à jour sans recharger la page.
             Sans JavaScript, il est envoyé en GET et PHP applique les filtres. -->
        <form class="filtres-carte" id="filtres-carte" method="get" action="dashboard-client.php#carte">
            <div class="filtre filtre-rayon">
                <label for="filtre-rayon">
                    Rayon : <strong id="filtre-rayon-valeur"><?= RAYONS_CARTE[$pasRayon] ?> km</strong>
                </label>
                <input type="range" id="filtre-rayon" name="rayon_pas"
                       min="0" max="<?= count(RAYONS_CARTE) - 1 ?>" step="1" value="<?= $pasRayon ?>"
                       aria-valuetext="<?= RAYONS_CARTE[$pasRayon] ?> kilomètres">
                <div class="reperes" aria-hidden="true">
                    <?php foreach (RAYONS_CARTE as $km): ?><span><?= $km ?></span><?php endforeach; ?>
                </div>
            </div>

            <div class="filtre">
                <label for="filtre-note">Note minimale</label>
                <select id="filtre-note" name="note_min">
                    <?php foreach (['' => 'Toutes', '3' => '3 ★ et +', '4' => '4 ★ et +', '4.5' => '4,5 ★ et +'] as $valeur => $libelle): ?>
                        <option value="<?= e($valeur) ?>"
                            <?= ($valeur === '' ? $filtresCarte['note_min'] == 0 : (float) $valeur === $filtresCarte['note_min']) ? 'selected' : '' ?>>
                            <?= e($libelle) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filtre">
                <label for="filtre-tarif">Prix maximum (€)</label>
                <input type="number" id="filtre-tarif" name="tarif_max" min="0" max="9999" step="1"
                       inputmode="numeric" placeholder="Sans limite"
                       value="<?= $filtresCarte['tarif_max'] === null ? '' : e((string) $filtresCarte['tarif_max']) ?>">
            </div>

            <div class="filtre filtre-actions">
                <button type="submit" id="bouton-filtrer" class="bouton-petit primaire">Filtrer</button>
                <!-- Affiché seulement si JavaScript fonctionne (attribut hidden retiré par le script) -->
                <button type="button" id="bouton-localiser" class="bouton-petit" hidden>📍 Me localiser</button>
            </div>
        </form>

        <div id="carte-avis" class="alerte alerte-info carte-avis" role="status" hidden></div>

        <!-- Conteneur de la carte : masqué tant que Leaflet n'est pas chargé -->
        <div id="carte-leaflet" class="carte" role="region" aria-label="Carte des réparateurs disponibles" hidden></div>
        <p class="carte-maj cellule-sec" id="carte-maj">Distances calculées depuis le centre de Paris.</p>

        <h3 class="liste-titre">Liste des réparateurs</h3>
        <ul class="liste-reparateurs" id="liste-reparateurs">
            <?php if ($nbProches === 0): ?>
                <li class="vide-etat"><p>Aucun réparateur ne correspond à ces critères. Essayez d'élargir le rayon.</p></li>
            <?php endif; ?>
            <?php foreach ($reparateursProches as $rp): ?>
                <!-- Même structure que les cartes créées en JavaScript (fonction carteListe) -->
                <li class="carte-rep" data-id="<?= $rp['id'] ?>"
                    data-lat="<?= e((string) $rp['latitude']) ?>" data-lng="<?= e((string) $rp['longitude']) ?>">
                    <div class="carte-rep-entete">
                        <span class="cellule-titre"><?= e($rp['prenom'] . ' ' . $rp['nom']) ?></span>
                        <?php if ($rp['distance'] !== null): ?>
                            <span class="etiquette distance"><?= number_format($rp['distance'], 1, ',', ' ') ?> km</span>
                        <?php endif; ?>
                    </div>
                    <div class="cellule-sec"><?= e(trim($rp['code_postal'] . ' ' . $rp['ville'])) ?></div>
                    <div class="note">
                        <?php if ($rp['note_moyenne'] !== null): ?>
                            <?= etoiles((int) round($rp['note_moyenne'])) ?>
                            <?= number_format($rp['note_moyenne'], 1, ',', '') ?>/5
                            <span class="cellule-sec">(<?= $rp['nb_avis'] ?> avis)</span>
                        <?php else: ?>
                            <span class="cellule-sec">Pas encore d'avis</span>
                        <?php endif; ?>
                    </div>
                    <div class="prix">
                        À partir de <strong><?= euros($rp['prix_min']) ?></strong>
                        <span class="cellule-sec">· <?= $rp['nb_services'] ?> service<?= $rp['nb_services'] > 1 ? 's' : '' ?></span>
                    </div>
                    <div class="carte-rep-actions">
                        <button type="button" class="bouton-petit voir-sur-carte" hidden>Voir sur la carte</button>
                        <a href="services.php?reparateur=<?= $rp['id'] ?>" class="bouton-petit primaire">Voir ses services</a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <noscript>
            <p class="cellule-sec" style="margin-top:10px">
                JavaScript est désactivé : la carte ne peut pas s'afficher, mais la liste
                reste disponible (distances calculées depuis le centre de Paris).
            </p>
        </noscript>
    </section>

    <!-- ============ 2. RÉSERVATIONS ============ -->
    <section class="bloc">
        <div class="bloc-entete">
            <h2>Mes réservations</h2>
            <a href="services.php" class="bouton-petit primaire">Réserver un service</a>
        </div>

        <?php if (empty($reservations)): ?>
            <div class="vide-etat">
                <p>Vous n'avez encore aucune réservation.</p>
                <a href="carte.php" class="bouton-petit primaire">Trouver un réparateur près de chez moi</a>
            </div>
        <?php else: ?>
            <div class="zone-tableau">
                <table>
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Réparateur</th>
                            <th>Intervention</th>
                            <th>Tarif</th>
                            <th>Paiement</th>
                            <th>Statut</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reservations as $r): ?>
                        <tr>
                            <td>
                                <div class="cellule-titre"><?= e($r['service']) ?></div>
                                <div class="cellule-sec">
                                    Demandé le <?= dateFr($r['date_demande']) ?>
                                </div>

                                <?php
                                // Réponse du réparateur : affichée seulement s'il a écrit un message.
                                // date_reponse renseignée = c'est le RÉPARATEUR qui a répondu
                                // (une annulation faite par le client laisse date_reponse vide).
                                if (!empty($r['message_reparateur']) && $r['date_reponse'] !== null):
                                    $refus = $r['statut'] === 'annulee';
                                ?>
                                    <div class="alerte reponse-rep <?= $refus ? 'alerte-erreur' : 'alerte-succes' ?>">
                                        <div class="reponse-rep-entete">
                                            <span class="etiquette <?= $refus ? 'annulee' : 'terminee' ?>">
                                                <?= $refus ? 'Demande refusée' : 'Demande acceptée' ?>
                                            </span>
                                            <span>
                                                <?= e($r['rep_prenom'] . ' ' . $r['rep_nom']) ?>,
                                                le <?= dateFr($r['date_reponse'], true) ?>
                                            </span>
                                        </div>
                                        <!-- Texte libre saisi par le réparateur : TOUJOURS échappé
                                             avec e() (sinon faille XSS). nl2br est appliqué APRÈS
                                             l'échappement pour conserver les retours à la ligne. -->
                                        <p class="reponse-rep-texte">« <?= nl2br(e($r['message_reparateur'])) ?> »</p>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= e($r['rep_prenom'] . ' ' . $r['rep_nom']) ?></div>
                                <div class="cellule-sec"><?= e($r['rep_tel']) ?></div>
                            </td>
                            <td>
                                <div><?= dateFr($r['date_intervention'], true) ?></div>
                                <div class="cellule-sec"><?= e($r['adresse_intervention']) ?></div>
                            </td>
                            <td><?= euros($r['tarif']) ?></td>
                            <td>
                                <?php if ($r['statut_payement'] === 'valide'): ?>
                                    <span class="etiquette terminee">Payé</span>
                                <?php elseif ($r['statut_payement'] === 'en_attente'): ?>
                                    <span class="etiquette en_attente">En attente</span>
                                <?php elseif ($r['statut_payement'] === 'rembourse'): ?>
                                    <span class="etiquette confirmee">Remboursé</span>
                                <?php elseif ($r['statut_payement'] === 'refuse'): ?>
                                    <span class="etiquette annulee">Refusé</span>
                                <?php else: ?>
                                    <span class="cellule-sec">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="etiquette <?= e($r['statut']) ?>">
                                    <?= e(libelleStatut($r['statut'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($r['statut'] === 'en_attente'): ?>
                                    <form method="post" action="dashboard-client.php"
                                          onsubmit="return confirm('Annuler cette réservation ?');">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="annuler">
                                        <input type="hidden" name="id_reservation"
                                               value="<?= (int) $r['id_reservation'] ?>">
                                        <button type="submit" class="bouton-petit danger">Annuler</button>
                                    </form>
                                <?php elseif ($r['statut'] === 'confirmee' && $r['statut_payement'] !== 'valide'): ?>
                                    <!-- Payer : seulement une réservation ACCEPTÉE et pas encore payée.
                                         payement.php revérifie ces deux règles côté serveur. -->
                                    <a href="payement.php?reservation=<?= (int) $r['id_reservation'] ?>"
                                       class="bouton-petit primaire">Payer</a>
                                <?php elseif ($r['statut_payement'] === 'valide' || ($r['statut'] === 'terminee' && !$r['id_avis'])): ?>
                                    <div class="actions-cellule">
                                        <?php if ($r['statut_payement'] === 'valide'): ?>
                                            <a href="recu.php?reservation=<?= (int) $r['id_reservation'] ?>"
                                               class="bouton-petit">Reçu</a>
                                        <?php endif; ?>
                                        <?php if ($r['statut'] === 'terminee' && !$r['id_avis']): ?>
                                            <a href="avis.php?id=<?= (int) $r['id_reservation'] ?>"
                                               class="bouton-petit">Noter</a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="cellule-sec">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- ============ 3. AVIS ============ -->
    <section class="bloc">
        <div class="bloc-entete">
            <h2>Mes avis</h2>
        </div>

        <?php if (!empty($aNoter)): ?>
            <div class="alerte alerte-info">
                <strong>
                    <?= count($aNoter) ?> intervention<?= count($aNoter) > 1 ? 's' : '' ?>
                    en attente de votre avis :
                </strong>
                <ul style="margin:8px 0 0 18px;">
                    <?php foreach ($aNoter as $n): ?>
                        <li>
                            <?= e($n['service']) ?> du <?= dateFr($n['date_intervention']) ?> —
                            <a href="avis.php?id=<?= (int) $n['id_reservation'] ?>">déposer un avis</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (empty($avis)): ?>
            <div class="vide-etat">
                <p>Vous n'avez pas encore déposé d'avis.</p>
                <p class="cellule-sec">
                    Un avis ne peut être laissé qu'après une intervention terminée.
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($avis as $a): ?>
                <article class="avis">
                    <div class="avis-entete">
                        <div>
                            <span class="cellule-titre"><?= e($a['service']) ?></span>
                            <span class="cellule-sec">
                                — <?= e($a['rep_prenom'] . ' ' . $a['rep_nom']) ?>
                            </span>
                        </div>
                        <div>
                            <?= etoiles((int) $a['note']) ?>
                            <span class="cellule-sec"><?= dateFr($a['date_avis']) ?></span>
                        </div>
                    </div>
                    <?php if (!empty($a['commentaire'])): ?>
                        <p class="avis-commentaire">« <?= e($a['commentaire']) ?> »</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <!-- ============ MES DERNIÈRES CONNEXIONS (journal, filtré par la session) ============ -->
    <?php afficherDernieresConnexions($pdo); ?>

    <!-- ============ MES APPAREILS CONNECTÉS (« Se souvenir de moi ») ============ -->
    <?php afficherAppareilsConnectes($pdo, $csrfToken); ?>

</main>

<footer class="pied">
    <p>&copy; <?= date('Y') ?> Fair'repair</p>
</footer>

<!-- Leaflet est chargé juste avant notre script (et non dans le <head> avec
     « defer ») : un script écrit directement dans la page s'exécute AVANT les
     scripts « defer », il ne trouverait pas encore l'objet L. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"
        integrity="sha512-puJW3E/qXDqYp9IfhAI54BJEaWIfloJ7JWs7OeD5i6ruC9JZL1gERT1wjtwXFlh7CjE7ZJ+/vcRZRkIYIb6p4g=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
/* =================================================================
   Carte des réparateurs — JavaScript natif, sans bibliothèque
   à part Leaflet (affichage de la carte).
   ================================================================= */
(function () {
    'use strict';

    var API_URL    = 'api/reparateurs.php';
    var PARIS      = { lat: 48.8566, lng: 2.3522 };
    var RAYONS     = <?= json_encode(RAYONS_CARTE) ?>;   // mêmes valeurs que côté PHP
    var INTERVALLE = 30000;                              // rafraîchissement : 30 s

    // --- Éléments de la page ---
    var formulaire   = document.getElementById('filtres-carte');
    var champRayon   = document.getElementById('filtre-rayon');
    var libelleRayon = document.getElementById('filtre-rayon-valeur');
    var champNote    = document.getElementById('filtre-note');
    var champTarif   = document.getElementById('filtre-tarif');
    var boutonLoc    = document.getElementById('bouton-localiser');
    var zoneAvis     = document.getElementById('carte-avis');
    var zoneCarte    = document.getElementById('carte-leaflet');
    var zoneMaj      = document.getElementById('carte-maj');
    var compteur     = document.getElementById('carte-compteur');
    var liste        = document.getElementById('liste-reparateurs');

    // --- État ---
    // « reelle » : true si la position vient du GPS / navigateur,
    //              false si c'est la position de repli (centre de Paris).
    var position       = { lat: PARIS.lat, lng: PARIS.lng, reelle: false };
    var carte          = null;
    var cercle         = null;       // cercle du rayon de recherche
    var marqueurMoi    = null;       // « Vous êtes ici »
    var marqueurs      = new Map();  // id du réparateur → marqueur Leaflet
    var minuteur       = null;       // identifiant du setInterval
    var requeteEnCours = null;       // AbortController de la requête en cours
    var sessionExpiree = false;

    var formatEuros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
    var formatUnDec = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

    // JavaScript fonctionne : on passe en mode « mise à jour instantanée »
    document.getElementById('bouton-filtrer').hidden = true;
    boutonLoc.hidden = false;

    /* -------------------------------------------------------------
       1. INITIALISATION DE LA CARTE
       ------------------------------------------------------------- */
    if (window.L) {
        zoneCarte.hidden = false;
        carte = L.map(zoneCarte, { scrollWheelZoom: false }).setView([PARIS.lat, PARIS.lng], 12);

        // Tuiles OpenStreetMap : gratuites, sans clé d'API.
        // La mention des contributeurs OSM est OBLIGATOIRE (licence ODbL).
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">contributeurs OpenStreetMap</a>'
        }).addTo(carte);

        // La molette ne zoome qu'après un premier clic : sinon, faire
        // défiler la page au-dessus de la carte la ferait zoomer par erreur.
        carte.once('click', function () { carte.scrollWheelZoom.enable(); });

        liste.querySelectorAll('.voir-sur-carte').forEach(function (b) { b.hidden = false; });
    } else {
        // Le CDN est injoignable : la liste (mise à jour par fetch) reste utilisable
        afficherAvis("La carte n'a pas pu être chargée. La liste ci-dessous reste à jour.");
    }

    var iconeReparateur = window.L ? L.divIcon({
        className: 'pin-reparateur',
        html: '<span></span>',      // contenu fixe, aucune donnée utilisateur
        iconSize: [34, 34],
        iconAnchor: [17, 34],       // la pointe de la goutte touche la position
        popupAnchor: [0, -32]
    }) : null;

    /* -------------------------------------------------------------
       2. GÉOLOCALISATION ET POSITION DE REPLI
       ------------------------------------------------------------- */
    // navigator.geolocation demande l'autorisation à l'utilisateur.
    // Elle n'est disponible que sur une page « sécurisée » : https://,
    // ou http://localhost (c'est le cas sous AMPPS).
    //
    // Si l'utilisateur refuse, si le navigateur ne sait pas faire, ou si
    // la réponse tarde (10 s), on NE BLOQUE PAS la page : on se replie sur
    // le centre de Paris (48.8566, 2.3522) et on l'explique discrètement.
    // Aucun géocodage (conversion adresse → coordonnées) n'est effectué.
    function localiser() {
        if (!('geolocation' in navigator)) {
            positionDeRepli("Votre navigateur ne permet pas la géolocalisation.");
            return;
        }
        boutonLoc.disabled = true;
        boutonLoc.textContent = '📍 Localisation…';

        navigator.geolocation.getCurrentPosition(
            function succes(pos) {
                finLocalisation();
                position = { lat: pos.coords.latitude, lng: pos.coords.longitude, reelle: true };
                masquerAvis();
                majPosition();
                charger(true);
            },
            function echec(err) {
                finLocalisation();
                var raisons = {
                    1: "Vous n'avez pas autorisé l'accès à votre position.",
                    2: "Votre position n'a pas pu être déterminée.",
                    3: "La localisation a pris trop de temps."
                };
                positionDeRepli(raisons[err.code] || "Votre position n'a pas pu être obtenue.");
            },
            { enableHighAccuracy: false, timeout: 10000, maximumAge: 5 * 60 * 1000 }
        );
    }

    function finLocalisation() {
        boutonLoc.disabled = false;
        boutonLoc.textContent = '📍 Me localiser';
    }

    function positionDeRepli(raison) {
        if (position.reelle) {
            // Une position réelle a déjà été obtenue : on la garde
            afficherAvis(raison + " Votre dernière position connue est conservée.");
            return;
        }
        position = { lat: PARIS.lat, lng: PARIS.lng, reelle: false };
        afficherAvis(raison + " Les distances sont calculées depuis le centre de Paris.");
        majPosition();
        charger(true);
    }

    // Dessine le marqueur « Vous êtes ici » et le cercle du rayon de recherche
    function majPosition() {
        if (!carte) { return; }
        var centre = [position.lat, position.lng];

        if (position.reelle) {
            if (!marqueurMoi) {
                // circleMarker : point bleu dessiné, bien distinct des gouttes vertes
                marqueurMoi = L.circleMarker(centre, {
                    radius: 9, color: '#FFFFFF', weight: 3, fillColor: '#1E50D4', fillOpacity: 1
                }).bindTooltip('Vous êtes ici', { permanent: true, direction: 'top', offset: [0, -10] })
                  .addTo(carte);
            }
            marqueurMoi.setLatLng(centre);
        } else if (marqueurMoi) {
            carte.removeLayer(marqueurMoi);
            marqueurMoi = null;
        }

        if (!cercle) {
            cercle = L.circle(centre, {
                radius: rayonKm() * 1000,           // Leaflet attend des mètres
                color: '#0F766E', weight: 1.5, dashArray: '6 6',
                fillColor: '#0F766E', fillOpacity: 0.07,
                interactive: false
            }).addTo(carte);
        }
        cercle.setLatLng(centre);
        cercle.setRadius(rayonKm() * 1000);
    }

    // Ajuste le zoom pour que tout le cercle de recherche soit visible
    function recadrer() {
        if (carte && cercle) {
            carte.fitBounds(cercle.getBounds(), { padding: [20, 20] });
        }
    }

    /* -------------------------------------------------------------
       3. CHARGEMENT DES RÉPARATEURS (fetch)
       ------------------------------------------------------------- */
    function rayonKm() {
        return RAYONS[parseInt(champRayon.value, 10)] || 5;
    }

    function charger(recentrer) {
        if (sessionExpiree) { return; }

        // Si une requête précédente n'est pas terminée (filtres changés
        // rapidement), on l'annule : seule la plus récente compte.
        if (requeteEnCours) { requeteEnCours.abort(); }
        var controleur = new AbortController();
        requeteEnCours = controleur;

        // URLSearchParams encode correctement chaque valeur dans l'URL
        var params = new URLSearchParams({
            lat: position.lat.toFixed(6),
            lng: position.lng.toFixed(6),
            rayon: rayonKm()
        });
        if (champNote.value !== '') { params.set('note_min', champNote.value); }
        if (champTarif.value.trim() !== '') { params.set('tarif_max', champTarif.value.trim()); }

        fetch(API_URL + '?' + params.toString(), {
            credentials: 'same-origin',                 // envoie le cookie de session
            headers: { 'Accept': 'application/json' },
            signal: controleur.signal
        })
        .then(function (reponse) {
            return reponse.json().catch(function () { return {}; }).then(function (donnees) {
                if (reponse.status === 401) {
                    sessionExpiree = true;
                    arreterRafraichissement();
                }
                if (!reponse.ok || !Array.isArray(donnees.reparateurs)) {
                    throw new Error(donnees.erreur || ('erreur ' + reponse.status));
                }
                return donnees;
            });
        })
        .then(function (donnees) {
            majMarqueurs(donnees.reparateurs);
            majListe(donnees.reparateurs);
            majCompteur(donnees.reparateurs.length);
            zoneMaj.textContent = 'Mis à jour à ' + new Date().toLocaleTimeString('fr-FR');
            if (recentrer) { recadrer(); }
        })
        .catch(function (erreur) {
            if (erreur.name === 'AbortError') { return; }   // annulée volontairement
            zoneMaj.textContent = sessionExpiree
                ? erreur.message + ' La mise à jour automatique est arrêtée.'
                : 'Mise à jour impossible (' + erreur.message + '). Nouvel essai dans 30 s.';
        })
        .finally(function () {
            if (requeteEnCours === controleur) { requeteEnCours = null; }
        });
    }

    /* -------------------------------------------------------------
       4. MISE À JOUR DES MARQUEURS (sans doublons)
       ------------------------------------------------------------- */
    // On ne vide PAS la carte à chaque rafraîchissement (effet de clignotement,
    // popup ouverte refermée). On compare plutôt la nouvelle liste à l'ancienne,
    // grâce à la Map « id du réparateur → marqueur » :
    //   - id déjà présent  → on met à jour sa position et sa popup ;
    //   - id nouveau       → on crée le marqueur (réparateur devenu disponible) ;
    //   - id absent de la réponse → on retire le marqueur (réparateur devenu
    //     indisponible, ou hors des filtres).
    // Chaque réparateur a donc toujours UN seul marqueur : pas d'empilement.
    function majMarqueurs(reparateurs) {
        if (!carte) { return; }
        var idsRecus = new Set();

        reparateurs.forEach(function (r) {
            idsRecus.add(r.id);
            var marqueur = marqueurs.get(r.id);
            if (marqueur) {
                marqueur.setLatLng([r.latitude, r.longitude]);
                marqueur.setPopupContent(contenuPopup(r));
            } else {
                marqueur = L.marker([r.latitude, r.longitude], {
                    icon: iconeReparateur,
                    title: r.prenom + ' ' + r.nom,     // attribut, jamais interprété comme HTML
                    alt: 'Réparateur ' + r.prenom + ' ' + r.nom
                }).bindPopup(contenuPopup(r)).addTo(carte);
                marqueurs.set(r.id, marqueur);
            }
        });

        marqueurs.forEach(function (marqueur, id) {
            if (!idsRecus.has(id)) {
                carte.removeLayer(marqueur);
                marqueurs.delete(id);
            }
        });
    }

    /* -------------------------------------------------------------
       5. CONSTRUCTION DU HTML (popup et liste)
       ------------------------------------------------------------- */
    // SÉCURITÉ : les noms et villes viennent de la base de données.
    // On construit les éléments avec createElement + textContent, qui
    // insèrent du TEXTE, jamais du HTML : l'équivalent de e() en PHP.
    function el(balise, classe, texte) {
        var noeud = document.createElement(balise);
        if (classe) { noeud.className = classe; }
        if (texte !== undefined && texte !== null) { noeud.textContent = texte; }
        return noeud;
    }

    function etoiles(note) {
        var span = el('span', 'etoiles');
        var pleines = Math.round(note);
        for (var i = 1; i <= 5; i++) {
            span.appendChild(i <= pleines ? document.createTextNode('★') : el('span', 'vide', '★'));
        }
        return span;
    }

    function blocNote(r) {
        var div = el('div', 'note');
        if (r.note_moyenne === null) {
            div.appendChild(el('span', 'cellule-sec', "Pas encore d'avis"));
        } else {
            div.appendChild(etoiles(r.note_moyenne));
            div.appendChild(document.createTextNode(' ' + formatUnDec.format(r.note_moyenne) + '/5 '));
            div.appendChild(el('span', 'cellule-sec', '(' + r.nb_avis + ' avis)'));
        }
        return div;
    }

    function lienServices(r) {
        var a = el('a', 'bouton-petit primaire', 'Voir ses services');
        a.href = 'services.php?reparateur=' + encodeURIComponent(r.id);
        return a;
    }

    function contenuPopup(r) {
        var div = el('div', 'popup-rep');
        div.appendChild(el('strong', null, r.prenom + ' ' + r.nom));
        div.appendChild(el('div', 'ligne-popup', (r.code_postal + ' ' + r.ville).trim()));
        div.appendChild(blocNote(r));
        if (r.distance !== null) {
            div.appendChild(el('div', 'ligne-popup', 'À ' + formatUnDec.format(r.distance) + ' km'
                + (position.reelle ? ' de vous' : ' du centre de Paris')));
        }
        div.appendChild(el('div', 'prix', 'À partir de ' + formatEuros.format(r.prix_min)));
        div.appendChild(lienServices(r));
        return div;
    }

    function carteListe(r) {
        var li = el('li', 'carte-rep');
        li.dataset.id  = r.id;
        li.dataset.lat = r.latitude;
        li.dataset.lng = r.longitude;

        var entete = el('div', 'carte-rep-entete');
        entete.appendChild(el('span', 'cellule-titre', r.prenom + ' ' + r.nom));
        if (r.distance !== null) {
            entete.appendChild(el('span', 'etiquette distance', formatUnDec.format(r.distance) + ' km'));
        }
        li.appendChild(entete);
        li.appendChild(el('div', 'cellule-sec', (r.code_postal + ' ' + r.ville).trim()));
        li.appendChild(blocNote(r));

        var prix = el('div', 'prix', 'À partir de ');
        prix.appendChild(el('strong', null, formatEuros.format(r.prix_min)));
        prix.appendChild(el('span', 'cellule-sec',
            ' · ' + r.nb_services + ' service' + (r.nb_services > 1 ? 's' : '')));
        li.appendChild(prix);

        var actions = el('div', 'carte-rep-actions');
        if (carte) {
            var voir = el('button', 'bouton-petit voir-sur-carte', 'Voir sur la carte');
            voir.type = 'button';
            actions.appendChild(voir);
        }
        actions.appendChild(lienServices(r));
        li.appendChild(actions);
        return li;
    }

    // La liste est reconstruite à chaque réponse (déjà triée par distance par l'API)
    function majListe(reparateurs) {
        var selection = liste.querySelector('.carte-rep.selectionne');
        var idSelection = selection ? selection.dataset.id : null;

        liste.replaceChildren();
        if (reparateurs.length === 0) {
            var vide = el('li', 'vide-etat');
            vide.appendChild(el('p', null, "Aucun réparateur ne correspond à ces critères. Essayez d'élargir le rayon."));
            liste.appendChild(vide);
            return;
        }
        reparateurs.forEach(function (r) {
            var li = carteListe(r);
            if (String(r.id) === idSelection) { li.classList.add('selectionne'); }
            liste.appendChild(li);
        });
    }

    function majCompteur(n) {
        var s = n > 1 ? 's' : '';
        compteur.textContent = n + ' réparateur' + s + ' trouvé' + s;
    }

    function afficherAvis(texte) { zoneAvis.textContent = texte; zoneAvis.hidden = false; }
    function masquerAvis()      { zoneAvis.hidden = true; }

    // Clic sur une carte de la liste → centre la carte sur ce réparateur.
    // Un seul écouteur sur la liste (« délégation ») : il fonctionne aussi
    // pour les cartes recréées à chaque rafraîchissement.
    liste.addEventListener('click', function (evt) {
        if (evt.target.closest('a')) { return; }             // le lien garde son rôle
        var li = evt.target.closest('.carte-rep');
        if (!li || !carte) { return; }

        liste.querySelectorAll('.carte-rep.selectionne').forEach(function (x) { x.classList.remove('selectionne'); });
        li.classList.add('selectionne');

        carte.setView([parseFloat(li.dataset.lat), parseFloat(li.dataset.lng)], 15);
        var marqueur = marqueurs.get(parseInt(li.dataset.id, 10));
        if (marqueur) { marqueur.openPopup(); }
        zoneCarte.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    /* -------------------------------------------------------------
       6. FILTRES (mise à jour sans recharger la page)
       ------------------------------------------------------------- */
    formulaire.addEventListener('submit', function (evt) {
        evt.preventDefault();          // pas de rechargement : on passe par fetch
        charger(true);
    });

    champRayon.addEventListener('input', function () {
        // Pendant le glissement : libellé et cercle suivent le curseur
        libelleRayon.textContent = rayonKm() + ' km';
        champRayon.setAttribute('aria-valuetext', rayonKm() + ' kilomètres');
        majPosition();
    });
    champRayon.addEventListener('change', function () { charger(true); });   // au relâchement
    champNote.addEventListener('change', function () { charger(false); });

    // Prix : on attend une pause de frappe de 500 ms avant d'interroger le serveur
    var attenteTarif = null;
    champTarif.addEventListener('input', function () {
        clearTimeout(attenteTarif);
        attenteTarif = setTimeout(function () { charger(false); }, 500);
    });

    boutonLoc.addEventListener('click', localiser);

    /* -------------------------------------------------------------
       7. RAFRAÎCHISSEMENT AUTOMATIQUE (« temps réel » par polling)
       ------------------------------------------------------------- */
    // Toutes les 30 s, on redemande la liste au serveur (setInterval).
    // Quand l'onglet est caché (autre onglet, fenêtre réduite), personne ne
    // regarde la carte : on suspend les requêtes pour ne pas solliciter le
    // serveur inutilement. Au retour, on rafraîchit tout de suite puis on
    // relance le minuteur.
    function demarrerRafraichissement() {
        arreterRafraichissement();
        minuteur = setInterval(function () { charger(false); }, INTERVALLE);
    }
    function arreterRafraichissement() {
        if (minuteur !== null) { clearInterval(minuteur); minuteur = null; }
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            arreterRafraichissement();
        } else if (!sessionExpiree) {
            charger(false);
            demarrerRafraichissement();
        }
    });

    /* -------------------------------------------------------------
       8. DÉMARRAGE
       ------------------------------------------------------------- */
    majPosition();      // cercle autour du centre de Paris en attendant
    charger(true);      // premiers résultats sans attendre la réponse du navigateur
    localiser();        // puis demande de la position réelle
    if (document.visibilityState !== 'hidden') { demarrerRafraichissement(); }
})();
</script>

</body>
</html>