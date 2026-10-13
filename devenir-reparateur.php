<?php
/**
 * Fair'repair — Devenir réparateur (demande de partenariat)
 * ---------------------------------------------------------
 * Un utilisateur connecté (en général un client) demande à ouvrir un
 * espace réparateur SUR LE MÊME COMPTE. La page s'adapte à l'état de sa
 * demande, relu en base à chaque affichage :
 *
 *   aucune ligne dans reparateur → formulaire
 *   'en_attente'                 → « demande en cours d'examen », sans formulaire
 *   'refuse'                     → motif du refus + formulaire pour une nouvelle demande
 *   'valide'                     → redirection vers l'espace réparateur
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/affichage.php';

// Mettre à true pour valider automatiquement les demandes (utile en
// développement). En production, la validation est faite par un
// administrateur qui vérifie le SIRET.
define('VALIDATION_AUTOMATIQUE', true);

// Bouton « Accéder à mon espace réparateur » (formulaire de bascule)
traiterDemandeBascule();

// Page réservée aux utilisateurs connectés, QUEL QUE SOIT leur rôle.
// exigerConnexion() relit aussi les rôles en base.
exigerConnexion();

$idUser = (int) $_SESSION['id_user'];   // jamais lu dans le formulaire

// ============================================================
//  CONSTANTES
// ============================================================
const SPECIALITES = [
    'vae'          => 'Vélo à assistance électrique',
    'roues'        => 'Roues et pneus',
    'freins'       => 'Freins',
    'derailleur'   => 'Dérailleur',
    'transmission' => 'Transmission (chaîne, cassette)',
    'cadre'        => 'Cadre',
    'electronique' => 'Électronique',
];

// Les trois engagements obligatoires (nom du champ => texte)
const ENGAGEMENTS = [
    'engagement_siret'  => "Je certifie être un professionnel déclaré et disposer d'un numéro SIRET valide",
    'engagement_delais' => "Je m'engage à respecter les délais et à répondre aux demandes sous 48h",
    'engagement_cgp'    => "J'accepte les conditions générales prestataires et la commission de la plateforme",
];

// Rectangle englobant la France métropolitaine (Corse comprise)
const LAT_MIN = 41.0;
const LAT_MAX = 52.0;
const LNG_MIN = -5.0;
const LNG_MAX = 10.0;

// ============================================================
//  ÉTAT DE LA DEMANDE (lu en base, jamais dans la requête)
// ============================================================
function lireDemande(PDO $pdo, int $idUser): ?array
{
    $req = $pdo->prepare("SELECT raison_sociale, num_siret, adresse_atelier, code_postal_atelier,
                                 ville_atelier, latitude, longitude, rayon_intervention, specialites,
                                 statut_validation, date_demande, motif_refus
                          FROM reparateur
                          WHERE id_reparateur = :id");
    $req->execute([':id' => $idUser]);
    return $req->fetch() ?: null;
}

$demande = lireDemande($pdo, $idUser);
$statut  = $demande['statut_validation'] ?? false;
$envoye  = isset($_GET['ok']);   // arrivée après l'envoi du formulaire (PRG)

// Demande validée : direction l'espace réparateur, sauf juste après
// l'envoi (on affiche d'abord la confirmation).
if ($statut === 'valide' && !$envoye) {
    basculerRole('reparateur');   // revérifie le rôle en base avant de changer
    header('Location: ' . espaceActif());
    exit;
}

// ============================================================
//  VALEURS DU FORMULAIRE
// ============================================================
// Pré-remplissage : l'ancienne demande (si refusée), sinon l'adresse du compte
$req = $pdo->prepare("SELECT adresse, code_postal, ville FROM utilisateur WHERE id_user = :id");
$req->execute([':id' => $idUser]);
$compte = $req->fetch() ?: ['adresse' => '', 'code_postal' => '', 'ville' => ''];

$saisie = [
    'raison_sociale' => $demande['raison_sociale']      ?? '',
    'num_siret'      => $demande['num_siret']           ?? '',
    'adresse'        => $demande['adresse_atelier']     ?? $compte['adresse'],
    'code_postal'    => $demande['code_postal_atelier'] ?? $compte['code_postal'],
    'ville'          => $demande['ville_atelier']       ?? $compte['ville'],
    'rayon'          => (string) ($demande['rayon_intervention'] ?? 5),
    'latitude'       => (string) ($demande['latitude']  ?? ''),
    'longitude'      => (string) ($demande['longitude'] ?? ''),
    'specialites'    => $demande && $demande['specialites'] ? explode(',', $demande['specialites']) : [],
];
$engagementsCoches = [];
$erreurs = [];

/** Lit un champ texte du POST (une valeur envoyée sous forme de tableau est ignorée). */
function champ(string $cle): string
{
    $v = $_POST[$cle] ?? '';
    return is_string($v) ? trim($v) : '';
}

// ============================================================
//  TRAITEMENT DU FORMULAIRE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = champ('action');

    if (!verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $erreurs['global'] = "Session expirée ou requête invalide. Merci de recharger la page.";

    } elseif ($action === 'deconnexion') {
        // Déconnexion journalisée AVANT fermerSession(), qui vide la session.
        // (fermerSession() oublie aussi l'appareil « Se souvenir de moi ».)
        enregistrerTentative($pdo, $_SESSION['email'], $_SERVER['REMOTE_ADDR'] ?? '',
                             true, 'deconnexion', (int) $_SESSION['id_user']);
        fermerSession();
        header('Location: connexion.php?msg=deconnecte');
        exit;

    } elseif ($action === 'demander_partenariat') {

        // On ne peut déposer une demande que si l'on n'en a pas, ou si la
        // précédente a été refusée. Revérifié ici côté serveur.
        if ($statut !== false && $statut !== 'refuse') {
            header('Location: devenir-reparateur.php');
            exit;
        }

        // ---------- Lecture ----------
        $saisie = [
            'raison_sociale' => champ('raison_sociale'),
            'num_siret'      => champ('num_siret'),
            'adresse'        => champ('adresse'),
            'code_postal'    => champ('code_postal'),
            'ville'          => champ('ville'),
            'rayon'          => champ('rayon'),
            'latitude'       => str_replace(',', '.', champ('latitude')),
            'longitude'      => str_replace(',', '.', champ('longitude')),
            // Spécialités : on ne garde QUE les valeurs de la liste blanche
            'specialites'    => array_values(array_intersect(
                                    is_array($_POST['specialites'] ?? null) ? $_POST['specialites'] : [],
                                    array_keys(SPECIALITES))),
        ];
        foreach (ENGAGEMENTS as $nom => $texte) {
            if (isset($_POST[$nom])) {
                $engagementsCoches[] = $nom;
            }
        }

        // ---------- Validation côté serveur ----------
        $longueur = mb_strlen($saisie['raison_sociale']);
        if ($longueur < 2 || $longueur > 150) {
            $erreurs['raison_sociale'] = "La raison sociale doit contenir entre 2 et 150 caractères.";
        }

        // SIRET : séparateurs retirés, 14 chiffres, clé de Luhn (securite.php)
        $siret = nettoyerSiret($saisie['num_siret']);
        if ($siret === '') {
            $erreurs['num_siret'] = "Le numéro SIRET est obligatoire.";
        } elseif (!preg_match('/^[0-9]{14}$/', $siret)) {
            $erreurs['num_siret'] = "Le SIRET doit contenir exactement 14 chiffres.";
        } elseif (!siretValide($siret)) {
            $erreurs['num_siret'] = "Ce numéro SIRET est invalide : sa clé de contrôle ne correspond pas. "
                                  . "Vérifiez chaque chiffre.";
        } else {
            // Déjà utilisé par UN AUTRE compte ? (le sien, en cas de nouvelle
            // demande après un refus, est accepté)
            $req = $pdo->prepare("SELECT 1 FROM reparateur
                                  WHERE num_siret = :siret AND id_reparateur <> :id");
            $req->execute([':siret' => $siret, ':id' => $idUser]);
            if ($req->fetchColumn()) {
                $erreurs['num_siret'] = "Ce numéro SIRET est déjà enregistré par un autre compte.";
            }
        }

        if ($saisie['adresse'] === '' || mb_strlen($saisie['adresse']) > 255) {
            $erreurs['adresse'] = "L'adresse de l'atelier est obligatoire (255 caractères maximum).";
        }
        if (!preg_match('/^[0-9]{5}$/', $saisie['code_postal'])) {
            $erreurs['code_postal'] = "Le code postal doit contenir 5 chiffres.";
        }
        if ($saisie['ville'] === '' || mb_strlen($saisie['ville']) > 100) {
            $erreurs['ville'] = "La ville est obligatoire.";
        }

        $rayon = filter_var($saisie['rayon'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
        if ($rayon === false) {
            $erreurs['rayon'] = "Le rayon doit être un nombre entier entre 1 et 50 km.";
        }

        // Coordonnées : nombres décimaux, dans la France métropolitaine
        $lat = filter_var($saisie['latitude'],  FILTER_VALIDATE_FLOAT);
        $lng = filter_var($saisie['longitude'], FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false) {
            $erreurs['position'] = "Indiquez la position de votre atelier (bouton « Me localiser »).";
        } elseif ($lat < LAT_MIN || $lat > LAT_MAX || $lng < LNG_MIN || $lng > LNG_MAX) {
            $erreurs['position'] = "Cette position est en dehors de la France métropolitaine.";
        }

        foreach (ENGAGEMENTS as $nom => $texte) {
            if (!in_array($nom, $engagementsCoches, true)) {
                $erreurs[$nom] = "Cet engagement est obligatoire.";
            }
        }

        // ---------- Enregistrement ----------
        if (empty($erreurs)) {
            $statutInitial  = VALIDATION_AUTOMATIQUE ? 'valide' : 'en_attente';
            $dateValidation = VALIDATION_AUTOMATIQUE ? date('Y-m-d H:i:s') : null;

            $valeurs = [
                ':id'              => $idUser,                 // SESSION, jamais le formulaire
                ':raison_sociale'  => $saisie['raison_sociale'],
                ':siret'           => $siret,
                ':adresse'         => $saisie['adresse'],
                ':code_postal'     => $saisie['code_postal'],
                ':ville'           => $saisie['ville'],
                ':latitude'        => round($lat, 8),
                ':longitude'       => round($lng, 8),
                ':rayon'           => $rayon,
                // Type SET : les valeurs séparées par des virgules ('vae,freins')
                ':specialites'     => $saisie['specialites'] ? implode(',', $saisie['specialites']) : null,
                ':statut'          => $statutInitial,
                ':date_validation' => $dateValidation,
            ];

            try {
                $pdo->beginTransaction();

                // On relit l'état DANS la transaction, en verrouillant la ligne
                // (FOR UPDATE) : deux envois simultanés du formulaire ne
                // peuvent pas créer deux demandes.
                $req = $pdo->prepare("SELECT statut_validation FROM reparateur
                                      WHERE id_reparateur = :id FOR UPDATE");
                $req->execute([':id' => $idUser]);
                $statutActuel = $req->fetchColumn();

                if ($statutActuel !== false && $statutActuel !== 'refuse') {
                    $pdo->rollBack();
                    header('Location: devenir-reparateur.php');
                    exit;
                }

                if ($statutActuel === 'refuse') {
                    // Nouvelle demande après un refus : la ligne existe déjà
                    // (id_reparateur est la clé primaire), on la met à jour.
                    $sql = "UPDATE reparateur
                            SET raison_sociale      = :raison_sociale,
                                num_siret           = :siret,
                                adresse_atelier     = :adresse,
                                code_postal_atelier = :code_postal,
                                ville_atelier       = :ville,
                                latitude            = :latitude,
                                longitude           = :longitude,
                                rayon_intervention  = :rayon,
                                specialites         = :specialites,
                                disponible          = 1,
                                statut_validation   = :statut,
                                date_demande        = NOW(),
                                date_validation     = :date_validation,
                                motif_refus         = NULL
                            WHERE id_reparateur = :id";
                } else {
                    // Première demande : id_reparateur = id de l'utilisateur
                    // (clé primaire ET étrangère : spécialisation du MCD)
                    $sql = "INSERT INTO reparateur
                                (id_reparateur, raison_sociale, num_siret, adresse_atelier,
                                 code_postal_atelier, ville_atelier, latitude, longitude,
                                 rayon_intervention, specialites, note_moyenne, disponible,
                                 statut_validation, date_demande, date_validation)
                            VALUES
                                (:id, :raison_sociale, :siret, :adresse,
                                 :code_postal, :ville, :latitude, :longitude,
                                 :rayon, :specialites, NULL, 1,
                                 :statut, NOW(), :date_validation)";
                }
                $pdo->prepare($sql)->execute($valeurs);
                $pdo->commit();

                // Les rôles en session sont relus : si la demande est validée
                // automatiquement, le bouton de bascule apparaît tout de suite.
                rafraichirRoles();

                // POST / Redirect / GET : actualiser la page ne renverra pas le formulaire
                header('Location: devenir-reparateur.php?ok=1');
                exit;

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // 23000 : contrainte UNIQUE sur le SIRET (inscrit entre-temps par un autre compte)
                if ($e->getCode() === '23000') {
                    $erreurs['num_siret'] = "Ce numéro SIRET est déjà enregistré par un autre compte.";
                } else {
                    error_log('Demande réparateur : ' . $e->getMessage());
                    $erreurs['global'] = "Une erreur technique est survenue. Merci de réessayer.";
                }
            }
        }
    }
}

// Formulaire affiché si aucune demande, ou demande refusée
$afficherFormulaire = $statut === false || $statut === 'refuse';

/** SIRET lisible : « 123 456 789 00012 » */
function siretLisible(string $siret): string
{
    return substr($siret, 0, 3) . ' ' . substr($siret, 3, 3) . ' ' . substr($siret, 6, 3) . ' ' . substr($siret, 9);
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Devenir réparateur — Fair'repair</title>
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
    .badge-user {
        background-color: var(--vert-clair);
        color: var(--vert-fonce);
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

    /* ---------- Zone centrale ---------- */
    .page-centree {
        flex: 1;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        padding: 36px 20px 48px;
    }
    .carte-formulaire {
        background-color: var(--blanc);
        border: 1px solid var(--bordure);
        border-top: 4px solid var(--vert);
        border-radius: var(--rayon);
        padding: 32px 40px;
        width: 100%;
        max-width: 720px;
        box-shadow: 0 2px 10px rgba(13, 24, 41, 0.05);
    }
    .surtitre {
        display: inline-block;
        background-color: var(--vert-clair);
        color: var(--vert-fonce);
        padding: 3px 11px;
        border-radius: 20px;
        font-size: 0.78rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 10px;
    }
    h1 { font-size: 1.6rem; margin-bottom: 6px; }
    .sous-titre { color: var(--texte-doux); margin-bottom: 24px; font-size: 0.95rem; }

    /* ---------- Formulaire ---------- */
    fieldset { border: none; border-top: 1px solid var(--bordure); padding-top: 18px; margin-bottom: 22px; }
    legend {
        font-weight: 600; font-size: 0.9rem; text-transform: uppercase;
        letter-spacing: 0.5px; color: var(--vert); padding-right: 10px;
    }
    .ligne { display: flex; gap: 16px; }
    .ligne .champ { flex: 1; min-width: 0; }
    .champ-court { max-width: 160px; }
    .champ { margin-bottom: 16px; display: flex; flex-direction: column; }
    label { font-size: 0.88rem; font-weight: 600; margin-bottom: 5px; }
    .obligatoire { color: var(--rouge); }

    input[type="text"], input[type="number"] {
        padding: 10px 12px;
        border: 1px solid var(--bordure);
        border-radius: 6px;
        font-size: 0.95rem;
        font-family: inherit;
        background-color: var(--blanc);
        color: var(--texte);
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    input:focus {
        outline: none;
        border-color: var(--vert);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15);
    }
    input[readonly] { background-color: var(--fond); color: var(--texte-doux); }
    .invalide { border-color: var(--rouge) !important; background-color: var(--rouge-clair) !important; }
    .message-erreur { color: var(--rouge); font-size: 0.82rem; margin-top: 4px; }
    .aide { color: var(--texte-doux); font-size: 0.8rem; margin-top: 4px; }

    .position { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
    .position .champ { flex: 1; min-width: 140px; margin-bottom: 0; }
    .position-info { margin: 6px 0 16px; }

    /* Spécialités : cases à cocher en grille */
    .grille-cases {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 8px 16px;
    }
    .case {
        display: flex; align-items: flex-start; gap: 10px;
        font-weight: 400; font-size: 0.9rem; cursor: pointer; margin-bottom: 0;
    }
    .case input[type="checkbox"] {
        margin-top: 4px; width: 16px; height: 16px;
        accent-color: var(--vert); flex-shrink: 0;
    }

    /* Conditions : une vraie liste lisible, pas des petits caractères */
    .conditions {
        background-color: var(--fond);
        border: 1px solid var(--bordure);
        border-radius: 8px;
        padding: 16px 18px 16px 36px;
        margin-bottom: 18px;
        font-size: 0.93rem;
    }
    .conditions li { margin-bottom: 6px; }
    .conditions li:last-child { margin-bottom: 0; }
    .engagement {
        border: 1px solid var(--bordure);
        border-radius: 8px;
        padding: 10px 12px;
        margin-bottom: 10px;
    }
    .engagement.invalide { border-color: var(--rouge) !important; }
    .engagement .case { font-weight: 600; }

    /* ---------- Alertes ---------- */
    .alerte { padding: 14px 16px; border-radius: 6px; margin-bottom: 22px; font-size: 0.92rem; }
    .alerte-erreur  { background-color: var(--rouge-clair); border-left: 4px solid var(--rouge); color: var(--rouge); }
    .alerte-succes  { background-color: var(--succes-clair); border-left: 4px solid var(--succes); color: var(--succes); }
    .alerte-attente { background-color: var(--ambre-clair); border-left: 4px solid var(--ambre); color: var(--ambre); }
    .alerte strong { display: block; margin-bottom: 2px; }

    /* ---------- Récapitulatif de la demande ---------- */
    .recap { display: grid; grid-template-columns: 180px 1fr; gap: 8px 16px; margin-bottom: 24px; font-size: 0.93rem; }
    .recap dt { color: var(--texte-doux); }
    .etiquette {
        display: inline-block; padding: 4px 11px; border-radius: 20px;
        font-size: 0.78rem; font-weight: 600; white-space: nowrap;
    }
    .etiquette.en_attente { background-color: var(--ambre-clair); color: var(--ambre); }

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
        padding: 8px 14px;
        border-radius: 6px;
        font-size: 0.88rem;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid var(--bordure);
        background-color: var(--blanc);
        color: var(--texte);
        cursor: pointer;
        font-family: inherit;
        white-space: nowrap;
    }
    .bouton-petit:hover { background-color: var(--fond); }
    .bouton-petit.primaire { background-color: var(--vert); border-color: var(--vert); color: var(--blanc); }
    .bouton-petit.primaire:hover { background-color: var(--vert-fonce); }
    .actions-bas { display: flex; gap: 10px; flex-wrap: wrap; }

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
        .carte-formulaire { padding: 22px 18px; }
        .ligne { flex-direction: column; gap: 0; }
        .champ-court { max-width: none; }
        .recap { grid-template-columns: 1fr; gap: 2px; }
        .recap dd { margin-bottom: 8px; }
    }
    </style>
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav>
        <?php if (roleActif() !== null): ?>
            <a href="<?= e(espaceActif()) ?>">← Mon espace</a>
        <?php endif; ?>
        <span class="badge-user"><?= e($_SESSION['prenom']) ?></span>
        <form method="post" action="devenir-reparateur.php" class="form-deconnexion">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="deconnexion">
            <button type="submit" class="lien-bouton">Déconnexion</button>
        </form>
    </nav>
</header>

<main class="page-centree">
    <section class="carte-formulaire">

        <span class="surtitre">Espace réparateur</span>

        <?php if ($statut === 'valide'): ?>
            <!-- ============ DEMANDE VALIDÉE (juste après l'envoi) ============ -->
            <h1>Bienvenue parmi les réparateurs !</h1>
            <div class="alerte alerte-succes">
                <strong>Votre profil réparateur est actif.</strong>
                Le bouton « Client / Réparateur » apparaît désormais en haut de vos deux espaces :
                un clic suffit pour passer de l'un à l'autre, sans vous déconnecter.
            </div>
            <div class="actions-bas">
                <!-- Bascule : POST + CSRF, le serveur revérifie le rôle -->
                <form method="post" action="devenir-reparateur.php">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="basculer_role">
                    <input type="hidden" name="role" value="reparateur">
                    <button type="submit" class="bouton-petit primaire">🔧 Accéder à mon espace réparateur</button>
                </form>
                <?php if (aRole('client')): ?>
                    <a href="dashboard-client.php" class="bouton-petit">Rester dans mon espace client</a>
                <?php endif; ?>
            </div>

        <?php elseif ($statut === 'en_attente'): ?>
            <!-- ============ DEMANDE EN COURS D'EXAMEN ============ -->
            <h1>Demande en cours d'examen</h1>
            <?php if ($envoye): ?>
                <div class="alerte alerte-succes">
                    <strong>Votre demande a bien été envoyée.</strong>
                    Vous serez informé dès qu'un administrateur l'aura examinée.
                </div>
            <?php endif; ?>
            <div class="alerte alerte-attente">
                <strong>⏳ Un administrateur vérifie votre numéro SIRET.</strong>
                Votre espace réparateur s'ouvrira automatiquement après validation,
                sans que vous ayez besoin de vous reconnecter.
            </div>
            <dl class="recap">
                <dt>Envoyée le</dt>
                <dd><?= dateFr($demande['date_demande'], true) ?></dd>
                <dt>Raison sociale</dt>
                <dd><?= e($demande['raison_sociale']) ?></dd>
                <dt>SIRET</dt>
                <dd><?= e(siretLisible((string) $demande['num_siret'])) ?></dd>
                <dt>Atelier</dt>
                <dd><?= e(trim($demande['adresse_atelier'] . ', ' . $demande['code_postal_atelier'] . ' ' . $demande['ville_atelier'], ' ,')) ?></dd>
                <dt>Statut</dt>
                <dd><span class="etiquette en_attente">En attente de validation</span></dd>
            </dl>
            <?php if (aRole('client')): ?>
                <a href="dashboard-client.php" class="bouton-petit">← Retour à mon espace client</a>
            <?php endif; ?>

        <?php else: ?>
            <!-- ============ FORMULAIRE (aucune demande, ou demande refusée) ============ -->
            <h1>Devenir réparateur</h1>
            <p class="sous-titre">
                Proposez vos services aux cyclistes de votre quartier, depuis le même compte :
                vous garderez votre espace client.
            </p>

            <?php if ($statut === 'refuse'): ?>
                <div class="alerte alerte-erreur">
                    <strong>Votre précédente demande n'a pas été retenue.</strong>
                    <?= e($demande['motif_refus'] ?: "Aucun motif n'a été précisé.") ?>
                    <br>Vous pouvez corriger vos informations et envoyer une nouvelle demande.
                </div>
            <?php endif; ?>

            <?php if (isset($erreurs['global'])): ?>
                <div class="alerte alerte-erreur"><?= e($erreurs['global']) ?></div>
            <?php elseif (!empty($erreurs)): ?>
                <div class="alerte alerte-erreur">Certains champs sont à corriger (voir ci-dessous).</div>
            <?php endif; ?>

            <form method="post" action="devenir-reparateur.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="demander_partenariat">
                <!-- Aucun champ id_reparateur : l'identifiant vient de la session -->

                <fieldset>
                    <legend>Votre entreprise</legend>

                    <div class="champ">
                        <label for="raison_sociale">Raison sociale <span class="obligatoire">*</span></label>
                        <input type="text" id="raison_sociale" name="raison_sociale" maxlength="150"
                               placeholder="Ex. Atelier Vélo Bastille, ou votre nom si auto-entrepreneur"
                               value="<?= e($saisie['raison_sociale']) ?>"
                               class="<?= isset($erreurs['raison_sociale']) ? 'invalide' : '' ?>" required>
                        <?php if (isset($erreurs['raison_sociale'])): ?>
                            <span class="message-erreur"><?= e($erreurs['raison_sociale']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="champ">
                        <label for="num_siret">Numéro SIRET <span class="obligatoire">*</span></label>
                        <input type="text" id="num_siret" name="num_siret" inputmode="numeric" maxlength="20"
                               placeholder="123 456 789 00015"
                               value="<?= e($saisie['num_siret']) ?>"
                               class="<?= isset($erreurs['num_siret']) ? 'invalide' : '' ?>" required>
                        <?php if (isset($erreurs['num_siret'])): ?>
                            <span class="message-erreur"><?= e($erreurs['num_siret']) ?></span>
                        <?php else: ?>
                            <span class="aide">14 chiffres, indiqués sur votre avis de situation Insee. Les espaces sont acceptés.</span>
                        <?php endif; ?>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Atelier et zone d'intervention</legend>

                    <div class="champ">
                        <label for="adresse">Adresse de l'atelier ou point de départ <span class="obligatoire">*</span></label>
                        <input type="text" id="adresse" name="adresse" maxlength="255"
                               value="<?= e($saisie['adresse']) ?>"
                               class="<?= isset($erreurs['adresse']) ? 'invalide' : '' ?>" required>
                        <?php if (isset($erreurs['adresse'])): ?>
                            <span class="message-erreur"><?= e($erreurs['adresse']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ligne">
                        <div class="champ champ-court">
                            <label for="code_postal">Code postal <span class="obligatoire">*</span></label>
                            <input type="text" id="code_postal" name="code_postal" inputmode="numeric" maxlength="5"
                                   value="<?= e($saisie['code_postal']) ?>"
                                   class="<?= isset($erreurs['code_postal']) ? 'invalide' : '' ?>" required>
                            <?php if (isset($erreurs['code_postal'])): ?>
                                <span class="message-erreur"><?= e($erreurs['code_postal']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="champ">
                            <label for="ville">Ville <span class="obligatoire">*</span></label>
                            <input type="text" id="ville" name="ville" maxlength="100"
                                   value="<?= e($saisie['ville']) ?>"
                                   class="<?= isset($erreurs['ville']) ? 'invalide' : '' ?>" required>
                            <?php if (isset($erreurs['ville'])): ?>
                                <span class="message-erreur"><?= e($erreurs['ville']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="champ champ-court">
                            <label for="rayon">Rayon (km) <span class="obligatoire">*</span></label>
                            <input type="number" id="rayon" name="rayon" min="1" max="50" step="1"
                                   value="<?= e($saisie['rayon']) ?>"
                                   class="<?= isset($erreurs['rayon']) ? 'invalide' : '' ?>" required>
                            <?php if (isset($erreurs['rayon'])): ?>
                                <span class="message-erreur"><?= e($erreurs['rayon']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Position GPS : remplie par le bouton « Me localiser » (JavaScript).
                         Si la géolocalisation est impossible, les champs deviennent modifiables. -->
                    <div class="position">
                        <div class="champ">
                            <label for="latitude">Latitude <span class="obligatoire">*</span></label>
                            <input type="number" id="latitude" name="latitude" step="0.000001"
                                   min="<?= LAT_MIN ?>" max="<?= LAT_MAX ?>" placeholder="48.853300"
                                   value="<?= e($saisie['latitude']) ?>"
                                   class="<?= isset($erreurs['position']) ? 'invalide' : '' ?>" required>
                        </div>
                        <div class="champ">
                            <label for="longitude">Longitude <span class="obligatoire">*</span></label>
                            <input type="number" id="longitude" name="longitude" step="0.000001"
                                   min="<?= LNG_MIN ?>" max="<?= LNG_MAX ?>" placeholder="2.369200"
                                   value="<?= e($saisie['longitude']) ?>"
                                   class="<?= isset($erreurs['position']) ? 'invalide' : '' ?>" required>
                        </div>
                        <button type="button" id="bouton-localiser" class="bouton-petit" hidden>📍 Me localiser</button>
                    </div>
                    <p class="position-info">
                        <?php if (isset($erreurs['position'])): ?>
                            <span class="message-erreur"><?= e($erreurs['position']) ?></span>
                        <?php else: ?>
                            <span class="aide" id="geo-info">Placez-vous à votre atelier (ou point de départ) puis cliquez sur « Me localiser ».</span>
                        <?php endif; ?>
                    </p>
                </fieldset>

                <fieldset>
                    <legend>Spécialités <span class="aide">(facultatif)</span></legend>
                    <div class="grille-cases">
                        <?php foreach (SPECIALITES as $code => $libelle): ?>
                            <label class="case">
                                <input type="checkbox" name="specialites[]" value="<?= e($code) ?>"
                                    <?= in_array($code, $saisie['specialites'], true) ? 'checked' : '' ?>>
                                <span><?= e($libelle) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Conditions pour devenir réparateur</legend>

                    <ul class="conditions">
                        <li>Vous exercez en tant que <strong>professionnel déclaré</strong> (auto-entreprise, société…) avec un numéro SIRET actif.</li>
                        <li>Vous répondez à <strong>chaque demande sous 48 heures</strong> : acceptation, ou refus avec un motif pour le client.</li>
                        <li>Vous respectez les <strong>dates et heures confirmées</strong>. En cas d'empêchement, vous prévenez le client au plus tôt.</li>
                        <li>Vos <strong>tarifs affichés</strong> correspondent à ce que vous facturez au client.</li>
                        <li>Fair'repair prélève une <strong>commission</strong> sur chaque intervention payée en ligne ; son taux figure dans les conditions générales prestataires.</li>
                        <li>Votre demande est <strong>vérifiée par un administrateur</strong> (contrôle du SIRET) avant l'ouverture de votre espace.</li>
                    </ul>

                    <?php foreach (ENGAGEMENTS as $nom => $texte): ?>
                        <div class="engagement <?= isset($erreurs[$nom]) ? 'invalide' : '' ?>">
                            <label class="case">
                                <input type="checkbox" name="<?= e($nom) ?>" value="1"
                                    <?= in_array($nom, $engagementsCoches, true) ? 'checked' : '' ?>>
                                <span><?= e($texte) ?> <span class="obligatoire">*</span></span>
                            </label>
                            <?php if (isset($erreurs[$nom])): ?>
                                <span class="message-erreur"><?= e($erreurs[$nom]) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </fieldset>

                <button type="submit" class="bouton-principal">
                    <?= $statut === 'refuse' ? 'Envoyer une nouvelle demande' : 'Envoyer ma demande' ?>
                </button>
            </form>
        <?php endif; ?>

    </section>
</main>

<footer class="pied">
    <p>&copy; <?= date('Y') ?> Fair'repair</p>
</footer>

<?php if ($afficherFormulaire): ?>
<script>
/*
 * Pré-remplissage de la position avec la géolocalisation du navigateur.
 * - Si elle est disponible : les champs latitude / longitude passent en
 *   lecture seule et sont remplis par le bouton « Me localiser ».
 * - Si elle est refusée ou indisponible : les champs redeviennent
 *   modifiables, pour une saisie manuelle.
 * Sans JavaScript, les champs restent simplement modifiables.
 * Dans tous les cas, le serveur revérifie les valeurs (France métropolitaine).
 */
(function () {
    'use strict';
    var bouton = document.getElementById('bouton-localiser');
    var lat    = document.getElementById('latitude');
    var lng    = document.getElementById('longitude');
    var info   = document.getElementById('geo-info');

    function dire(texte) { if (info) { info.textContent = texte; } }
    function saisieManuelle(raison) {
        lat.readOnly = false;
        lng.readOnly = false;
        dire(raison + ' Saisissez les coordonnées à la main (sur openstreetmap.org : clic droit '
           + 'sur votre atelier, puis « Afficher l\'adresse »).');
    }

    if (!('geolocation' in navigator)) {
        saisieManuelle('Votre navigateur ne permet pas la géolocalisation.');
        return;
    }

    lat.readOnly = true;
    lng.readOnly = true;
    bouton.hidden = false;

    bouton.addEventListener('click', function () {
        bouton.disabled = true;
        dire('Localisation en cours…');
        navigator.geolocation.getCurrentPosition(function (pos) {
            lat.value = pos.coords.latitude.toFixed(6);
            lng.value = pos.coords.longitude.toFixed(6);
            dire('Position récupérée (précision : environ ' + Math.round(pos.coords.accuracy) + ' m).');
            bouton.disabled = false;
        }, function (err) {
            bouton.disabled = false;
            var raisons = {
                1: "Vous n'avez pas autorisé l'accès à votre position.",
                2: "Votre position n'a pas pu être déterminée.",
                3: "La localisation a pris trop de temps."
            };
            saisieManuelle(raisons[err.code] || "Position non obtenue.");
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    });
})();
</script>
<?php endif; ?>

</body>
</html>
