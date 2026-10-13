<?php
/**
 * Fair'repair — Tableau de bord ADMINISTRATEUR (version minimale)
 * ---------------------------------------------------------------
 * Pour l'instant, une seule entrée : l'historique des connexions.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/affichage.php';

// Réservé au rôle actif « admin », avant tout affichage
exigerRole('admin');

// --- Déconnexion (POST + jeton CSRF) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deconnexion') {
    if (verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        // Journalisée AVANT fermerSession(), qui vide la session
        enregistrerTentative($pdo, $_SESSION['email'], $_SERVER['REMOTE_ADDR'] ?? '',
                             true, 'deconnexion', (int) $_SESSION['id_user']);
        supprimerJetonSouvenir($pdo);
        fermerSession();
        header('Location: connexion.php?msg=deconnecte');
        exit;
    }
    definirFlash('erreur', "Requête invalide. Merci de recharger la page.");
    header('Location: dashboard-admin.php');
    exit;
}

$flash     = lireFlash();
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration — Fair'repair</title>
    <style>
    :root {
        --vert:         #0F766E;
        --vert-fonce:   #0B5A54;
        --violet:       #6D28D9;
        --violet-clair: #F1EBFD;
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
        background-color: var(--fond);
        color: var(--texte);
        line-height: 1.6;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
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
    .entete nav a:hover, .entete nav a.actif { color: var(--vert); }
    .entete nav a.actif { font-weight: 600; }
    .badge-user {
        background-color: var(--violet-clair); color: var(--violet);
        padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600;
    }
    .form-deconnexion { display: inline; margin: 0; }
    .lien-bouton {
        background: none; border: none; padding: 0;
        font-family: inherit; font-size: 0.92rem; font-weight: 500;
        color: var(--texte-doux); cursor: pointer;
    }
    .lien-bouton:hover { color: var(--rouge); text-decoration: underline; }
    .conteneur { flex: 1; width: 100%; max-width: 1080px; margin: 0 auto; padding: 32px 20px 48px; }
    h1 { font-size: 1.7rem; margin-bottom: 6px; }
    .sous-titre { color: var(--texte-doux); margin-bottom: 26px; font-size: 0.95rem; }
    .alerte { padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 0.92rem; }
    .alerte-erreur { background-color: var(--rouge-clair); border-left: 4px solid var(--rouge); color: var(--rouge); }
    .bloc {
        display: block;
        max-width: 420px;
        background-color: var(--blanc);
        border: 1px solid var(--bordure);
        border-top: 3px solid var(--violet);
        border-radius: var(--rayon);
        padding: 22px 24px;
        color: inherit;
        text-decoration: none;
        transition: box-shadow 0.15s, border-color 0.15s;
    }
    .bloc:hover { box-shadow: 0 2px 10px rgba(13, 24, 41, 0.08); border-color: var(--violet); }
    .bloc h2 { font-size: 1.1rem; margin-bottom: 4px; }
    .bloc p { color: var(--texte-doux); font-size: 0.9rem; }
    .pied {
        background-color: var(--blanc); border-top: 1px solid var(--bordure);
        text-align: center; padding: 18px; font-size: 0.82rem; color: var(--texte-doux);
    }
    @media (max-width: 640px) { .entete { padding: 12px 16px; } }
    </style>
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav>
        <a href="dashboard-admin.php" class="actif">Tableau de bord</a>
        <a href="historique-connexions.php">Historique des connexions</a>
        <span class="badge-user">🛡️ <?= e($_SESSION['prenom']) ?></span>
        <form method="post" action="dashboard-admin.php" class="form-deconnexion">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="deconnexion">
            <button type="submit" class="lien-bouton">Déconnexion</button>
        </form>
    </nav>
</header>

<main class="conteneur">
    <h1>Administration</h1>
    <p class="sous-titre">Bonjour <?= e($_SESSION['prenom']) ?>.</p>

    <?php if ($flash): ?>
        <div class="alerte alerte-erreur"><?= e($flash['texte']) ?></div>
    <?php endif; ?>

    <a href="historique-connexions.php" class="bloc">
        <h2>🔐 Historique des connexions</h2>
        <p>Tentatives réussies, échouées et refusées, activité suspecte, export CSV.</p>
    </a>
</main>

<footer class="pied">
    <p>&copy; <?= date('Y') ?> Fair'repair — Administration</p>
</footer>

</body>
</html>
