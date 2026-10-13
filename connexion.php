<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/securite.php';


const MAX_ECHECS      = 5;    
const FENETRE_MINUTES = 15;   

// Compte le nombre d'échecs de connexion pour un e-mail ou une IP donnés
// On ne stocke pas les mots de passe erronés, juste le fait qu'une tentative a échoué.
function compterEchecs(PDO $pdo, string $email, string $ip): int
{
    $dateLimite = date('Y-m-d H:i:s', time() - FENETRE_MINUTES * 60);

    // Seuls les VRAIS essais de mot de passe comptent. Les refus
    // ('compte_bloque', 'csrf_invalide') sont aussi notés avec reussie = 0
    // pour l'audit, mais aucun mot de passe n'y a été vérifié. Les compter
    // ferait repartir le blocage à chaque nouvel essai pendant le blocage :
    // le délai « Réessayez dans X minutes » ne diminuerait jamais.
    $sql = "SELECT COUNT(*)
            FROM tentatives_connexion
            WHERE (email = :email OR ip = :ip)
              AND reussie = 0
              AND motif IN ('mot_de_passe_incorrect', 'email_inconnu')
              AND `timestamp` > :date_limite";

    $req = $pdo->prepare($sql);
    $req->execute([
        ':email'       => $email,
        ':ip'          => $ip,
        ':date_limite' => $dateLimite,
    ]);

    return (int) $req->fetchColumn();
}

// Retourne le nombre de minutes restantes avant la fin du blocage de connexion
// (0 si la connexion n'est pas bloquée)

function minutesRestantes(PDO $pdo, string $email, string $ip): int
{
    // Mêmes motifs que compterEchecs() : les refus ne prolongent pas le blocage
    $sql = "SELECT MAX(`timestamp`)
            FROM tentatives_connexion
            WHERE (email = :email OR ip = :ip)
              AND reussie = 0
              AND motif IN ('mot_de_passe_incorrect', 'email_inconnu')";

    $req = $pdo->prepare($sql);
    $req->execute([':email' => $email, ':ip' => $ip]);
    $dernierEchec = $req->fetchColumn();

    if (!$dernierEchec) {
        return 0;
    }

    $secondes = strtotime($dernierEchec) + FENETRE_MINUTES * 60 - time();

    return $secondes > 0 ? (int) ceil($secondes / 60) : 0;
}

// enregistrerTentative() est définie dans auth.php.


function nettoyerTentatives(PDO $pdo): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }

    $req = $pdo->prepare("DELETE FROM tentatives_connexion WHERE `timestamp` < :date_limite");
    $req->execute([':date_limite' => date('Y-m-d H:i:s', time() - 24 * 3600)]);
}


function pluriel(int $nombre, string $mot): string
{
    return $nombre . ' ' . $mot . ($nombre > 1 ? 's' : '');
}

// estConnecte() essaie aussi de rouvrir la session avec le cookie
// « Se souvenir de moi » : on l'appelle une seule fois, avant tout affichage.
$connecte = estConnecte();

// MODE CONFIRMATION : un utilisateur DÉJÀ connecté retape son mot de passe
// avant une action sensible (voir exigerConnexionRecente() dans auth.php).
// La protection contre la force brute s'applique aussi à ce formulaire :
// un voleur de cookie ne peut pas s'en servir pour deviner le mot de passe.
$modeConfirmation = $connecte && isset($_GET['confirmer']);

if ($connecte && !$modeConfirmation) {
    header('Location: ' . espaceActif());
    exit;
}

$erreur     = '';
$email      = $modeConfirmation ? $_SESSION['email'] : '';
$info       = '';
$seSouvenir = false;

// Messages transmis par exigerConnexion() / exigerRole()
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'connexion_requise') {
        $info = "Vous devez être connecté pour accéder à cette page.";
    } elseif ($_GET['msg'] === 'session_expiree') {
        $info = "Votre session a expiré après 30 minutes d'inactivité.";
    } elseif ($_GET['msg'] === 'deconnecte') {
        $info = "Vous avez été déconnecté. À bientôt !";
    } elseif ($_GET['msg'] === 'appareil_revoque') {
        $info = "Cet appareil a été déconnecté de votre compte. Merci de vous reconnecter.";
    }
}
if ($modeConfirmation) {
    $info = "Pour votre sécurité, confirmez votre mot de passe avant de continuer.";
}

// Alerte posée par verifierJetonSouvenir() quand un cookie « Se souvenir
// de moi » a été utilisé par deux navigateurs (vol probable)
$alerteVol = !empty($_SESSION['alerte_vol_jeton']);
unset($_SESSION['alerte_vol_jeton']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $erreur = "Session expirée. Merci de recharger la page.";

    } else {
        // En mode confirmation, on vérifie le mot de passe du compte DÉJÀ
        // connecté : l'e-mail vient de la session, pas du formulaire.
        $email      = $modeConfirmation ? $_SESSION['email'] : nettoyer($_POST['email'] ?? '');
        $motDePasse = $_POST['mot_de_passe'] ?? '';
        $seSouvenir = !$modeConfirmation && isset($_POST['se_souvenir']);


        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($email === '' || $motDePasse === '') {
            $erreur = "Merci de renseigner votre e-mail et votre mot de passe.";


        } elseif (($echecs = compterEchecs($pdo, $email, $ip)) >= MAX_ECHECS) {
            $minutes = minutesRestantes($pdo, $email, $ip);
            $erreur  = "Trop de tentatives échouées. Pour votre sécurité, la connexion est bloquée. "
                     . "Réessayez dans " . pluriel(max(1, $minutes), 'minute') . ".";

        } else {
            $sql = "SELECT id_user, nom, prenom, email, mot_de_passe
                    FROM utilisateur
                    WHERE email = :email";
            $req = $pdo->prepare($sql);
            $req->execute([':email' => $email]);
            $utilisateur = $req->fetch();

        
            $motDePasseOk = $utilisateur
                && password_verify($motDePasse, $utilisateur['mot_de_passe']);

            // Enregistrement AVANT tout header() / exit : sinon une connexion
            // réussie (qui se termine par une redirection) ne serait jamais notée.
            $motif = $motDePasseOk ? 'succes'
                   : ($utilisateur ? 'mot_de_passe_incorrect' : 'email_inconnu');
            enregistrerTentative($pdo, $email, $ip, $motDePasseOk, $motif,
                                 $utilisateur ? (int) $utilisateur['id_user'] : null);
            nettoyerTentatives($pdo);
            nettoyerJetons($pdo);

            if ($motDePasseOk && $modeConfirmation) {
                // Mot de passe confirmé : la session redevient « fraîche »,
                // sans changer d'utilisateur ni d'espace (client / réparateur)
                confirmerAuthentification();

            } elseif ($motDePasseOk) {
                ouvrirSession($utilisateur);

                // « Se souvenir de moi » : jeton créé UNIQUEMENT ici, après un
                // mot de passe vérifié. Jamais en cas d'échec.
                if ($seSouvenir) {
                    creerJetonSouvenir($pdo, (int) $utilisateur['id_user']);
                }
            }

            if ($motDePasseOk) {
                // Retour éventuel vers la page demandée avant la connexion
                $destination = $_SESSION['redirection'] ?? null;
                unset($_SESSION['redirection']);

                if ($destination) {
                    header('Location: ' . $destination);
                    exit;
                }

                header('Location: ' . espaceActif());
                exit;
            }

            // Échec : on prévient du nombre d'essais restants.
            // $echecs = échecs AVANT cet essai, donc + 1 pour celui-ci.
            $restantes = MAX_ECHECS - ($echecs + 1);

            if ($restantes > 0) {
                $erreur = "E-mail ou mot de passe incorrect. Il vous reste "
                        . pluriel($restantes, 'tentative') . " avant un blocage de "
                        . FENETRE_MINUTES . " minutes.";
            } else {
                // C'était le dernier essai autorisé : le blocage commence maintenant
                $erreur = "E-mail ou mot de passe incorrect. Trop de tentatives échouées : "
                        . "la connexion est bloquée pendant " . pluriel(FENETRE_MINUTES, 'minute') . ".";
            }
        }
    }
}


$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — Fair'repair</title>
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
    input[type="password"], select, textarea {
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
    input[readonly] { background-color: var(--fond); color: var(--texte-doux); }

    /* Case « Se souvenir de moi », juste au-dessus du bouton */
    .champ-case { margin: 2px 0 18px; }
    .case {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        margin-bottom: 0;
    }
    .case input[type="checkbox"] {
        margin-top: 4px;
        width: 16px;
        height: 16px;
        accent-color: var(--vert);
        flex-shrink: 0;
    }
    .case .aide { display: block; font-weight: 400; margin-top: 0; }

    /* ---------- Alertes ---------- */
    .alerte { padding: 14px 16px; border-radius: 6px; margin-bottom: 22px; font-size: 0.92rem; }
    .alerte-erreur { background-color: var(--rouge-clair); border-left: 4px solid var(--rouge); color: var(--rouge); }
    .alerte-succes { background-color: var(--succes-clair); border-left: 4px solid var(--succes); color: var(--succes); }
    .alerte-info   { background-color: var(--bleu-clair);  border-left: 4px solid var(--bleu);  color: var(--bleu); }
    .alerte a { color: inherit; font-weight: 600; }

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
    .zone-tableau { overflow-x: auto; }
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

    /* ---------- Mobile ---------- */
    @media (max-width: 640px) {
        .carte-formulaire { padding: 24px 20px; }
        .ligne { flex-direction: column; gap: 0; }
        .entete { padding: 12px 16px; }
        .bloc { padding: 18px 16px; }
    }
    </style>
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav><a href="inscription.php">Créer un compte</a></nav>
</header>

<main class="page-centree">
    <section class="carte-formulaire">

        <h1><?= $modeConfirmation ? 'Confirmez votre identité' : 'Connexion' ?></h1>
        <p class="sous-titre">
            <?= $modeConfirmation ? 'Cette action nécessite votre mot de passe.' : 'Accédez à votre espace personnel.' ?>
        </p>

        <?php if ($alerteVol): ?>
            <div class="alerte alerte-erreur">
                <strong>Activité suspecte détectée.</strong>
                Votre connexion automatique a été utilisée depuis deux navigateurs différents.
                Par sécurité, tous vos appareils ont été déconnectés : reconnectez-vous avec votre
                mot de passe. Si vous ne reconnaissez pas cette activité, changez votre mot de passe.
            </div>
        <?php endif; ?>

        <?php if ($info): ?>
            <div class="alerte alerte-info"><?= e($info) ?></div>
        <?php endif; ?>

        <?php if ($erreur): ?>
            <div class="alerte alerte-erreur"><?= e($erreur) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= $modeConfirmation ? 'connexion.php?confirmer=1' : 'connexion.php' ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div class="champ">
                <label for="email">Adresse e-mail</label>
                <?php if ($modeConfirmation): ?>
                    <!-- Compte déjà connecté : l'e-mail n'est pas modifiable
                         (et le serveur ignore de toute façon ce champ) -->
                    <input type="email" id="email" value="<?= e($email) ?>" readonly>
                <?php else: ?>
                    <input type="email" id="email" name="email" autocomplete="username"
                           value="<?= e($email) ?>" autofocus required>
                <?php endif; ?>
            </div>

            <div class="champ">
                <label for="mot_de_passe">Mot de passe</label>
                <input type="password" id="mot_de_passe" name="mot_de_passe" autocomplete="current-password"
                       <?= $modeConfirmation ? 'autofocus' : '' ?> required>
            </div>

            <?php if (!$modeConfirmation): ?>
                <!-- « Se souvenir de moi » : un jeton est créé seulement si la
                     case est cochée ET le mot de passe correct (voir auth.php) -->
                <div class="champ-case">
                    <label class="case">
                        <input type="checkbox" name="se_souvenir" value="1" <?= $seSouvenir ? 'checked' : '' ?>>
                        <span>
                            Se souvenir de moi
                            <span class="aide">
                                Restez connecté <?= intdiv(DUREE_SOUVENIR, 86400) ?> jours sur cet appareil.
                                À éviter sur un ordinateur partagé.
                            </span>
                        </span>
                    </label>
                </div>
            <?php endif; ?>

            <button type="submit" class="bouton-principal">
                <?= $modeConfirmation ? 'Confirmer' : 'Se connecter' ?>
            </button>

            <p class="lien-bas">
                <?php if ($modeConfirmation): ?>
                    <a href="<?= e(espaceActif()) ?>">Annuler et revenir à mon espace</a>
                <?php else: ?>
                    Pas encore de compte ? <a href="inscription.php">Créer un compte client</a>
                <?php endif; ?>
            </p>
        </form>

    </section>
</main>

<footer class="pied">
    <p>&copy; <?= date('Y') ?> Fair'repair</p>
</footer>

</body>
</html>