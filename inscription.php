<?php
/**
 * Fair'repair — Page d'inscription CLIENT
 * ---------------------------------------
 * Le formulaire et son traitement sont dans le même fichier :
 * on soumet la page sur elle-même (pattern POST / Redirect / GET).
 *
 * L'inscription écrit dans DEUX tables :
 *   1. utilisateur  (données communes à tous les profils)
 *   2. client       (données propres au client)
 * Les deux écritures sont encadrées par une TRANSACTION : si la seconde
 * échoue, la première est annulée. On ne peut donc jamais se retrouver
 * avec un utilisateur orphelin, sans ligne dans client.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/securite.php';

$erreurs = [];      // messages d'erreur à afficher
$donnees = [];      // valeurs saisies, pour les réafficher en cas d'erreur
$succes  = false;

// ============================================================
//  TRAITEMENT DU FORMULAIRE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---------- 1. Vérification du jeton CSRF ----------
    if (!verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $erreurs['global'] = "Session expirée ou requête invalide. Merci de recharger la page.";
    } else {

        // ---------- 2. Récupération et nettoyage des données ----------
        $donnees = [
            'nom'          => nettoyer($_POST['nom']         ?? ''),
            'prenom'       => nettoyer($_POST['prenom']      ?? ''),
            'email'        => nettoyer($_POST['email']       ?? ''),
            'telephone'    => nettoyer($_POST['telephone']   ?? ''),
            'adresse'      => nettoyer($_POST['adresse']     ?? ''),
            'code_postal'  => nettoyer($_POST['code_postal'] ?? ''),
            'ville'        => nettoyer($_POST['ville']       ?? ''),
        ];
        $motDePasse        = $_POST['mot_de_passe']         ?? '';
        $confirmation      = $_POST['confirmation']         ?? '';
        $cgu               = isset($_POST['cgu']);

        // ---------- 3. Validation côté serveur ----------
        // Ne jamais se contenter de la validation HTML5 : elle se contourne
        // en une seconde avec les outils de développement du navigateur.

        if ($donnees['nom'] === '') {
            $erreurs['nom'] = "Le nom est obligatoire.";
        } elseif (mb_strlen($donnees['nom']) > 50) {
            $erreurs['nom'] = "Le nom ne doit pas dépasser 50 caractères.";
        }

        if ($donnees['prenom'] === '') {
            $erreurs['prenom'] = "Le prénom est obligatoire.";
        } elseif (mb_strlen($donnees['prenom']) > 50) {
            $erreurs['prenom'] = "Le prénom ne doit pas dépasser 50 caractères.";
        }

        if ($donnees['email'] === '') {
            $erreurs['email'] = "L'adresse e-mail est obligatoire.";
        } elseif (!filter_var($donnees['email'], FILTER_VALIDATE_EMAIL)) {
            $erreurs['email'] = "Le format de l'adresse e-mail est invalide.";
        }

        if ($motDePasse === '') {
            $erreurs['mot_de_passe'] = "Le mot de passe est obligatoire.";
        } elseif (!motDePasseValide($motDePasse)) {
            $erreurs['mot_de_passe'] = "8 caractères minimum, avec au moins une majuscule, une minuscule et un chiffre.";
        }

        if ($confirmation !== $motDePasse) {
            $erreurs['confirmation'] = "Les deux mots de passe ne correspondent pas.";
        }

        // Téléphone français : 10 chiffres, espaces et points tolérés à la saisie
        $telNettoye = preg_replace('/[\s.\-]/', '', $donnees['telephone']);
        if ($telNettoye === '') {
            $erreurs['telephone'] = "Le numéro de téléphone est obligatoire.";
        } elseif (!preg_match('/^0[1-9][0-9]{8}$/', $telNettoye)) {
            $erreurs['telephone'] = "Numéro invalide (exemple attendu : 06 12 34 56 78).";
        }

        if ($donnees['adresse'] === '') {
            $erreurs['adresse'] = "L'adresse est obligatoire.";
        }

        if (!preg_match('/^[0-9]{5}$/', $donnees['code_postal'])) {
            $erreurs['code_postal'] = "Le code postal doit contenir 5 chiffres.";
        }

        if ($donnees['ville'] === '') {
            $erreurs['ville'] = "La ville est obligatoire.";
        }

        if (!$cgu) {
            $erreurs['cgu'] = "Vous devez accepter les conditions générales d'utilisation.";
        }

        // ---------- 4. Unicité de l'e-mail ----------
        // La contrainte UNIQUE existe déjà en base, mais on teste avant
        // pour pouvoir afficher un message clair à l'utilisateur.
        if (!isset($erreurs['email'])) {
            $sql = "SELECT id_user FROM utilisateur WHERE email = :email";
            $req = $pdo->prepare($sql);
            $req->execute([':email' => $donnees['email']]);

            if ($req->fetch()) {
                $erreurs['email'] = "Un compte existe déjà avec cette adresse e-mail.";
            }
        }

        // ---------- 5. Enregistrement en base ----------
        if (empty($erreurs)) {
            try {
                // On ouvre une transaction : les deux INSERT réussissent
                // ensemble, ou aucun des deux n'est conservé.
                $pdo->beginTransaction();

                // password_hash utilise bcrypt et génère automatiquement
                // un "sel" unique. Le mot de passe en clair n'est jamais stocké.
                $motDePasseHache = password_hash($motDePasse, PASSWORD_DEFAULT);

                // --- INSERT 1 : table utilisateur ---
                $sql = "INSERT INTO utilisateur
                            (nom, prenom, email, mot_de_passe, telephone,
                             adresse, code_postal, ville, date_inscription, type_user)
                        VALUES
                            (:nom, :prenom, :email, :mot_de_passe, :telephone,
                             :adresse, :code_postal, :ville, NOW(), 'client')";

                $req = $pdo->prepare($sql);
                $req->execute([
                    ':nom'          => $donnees['nom'],
                    ':prenom'       => $donnees['prenom'],
                    ':email'        => $donnees['email'],
                    ':mot_de_passe' => $motDePasseHache,
                    ':telephone'    => $telNettoye,
                    ':adresse'      => $donnees['adresse'],
                    ':code_postal'  => $donnees['code_postal'],
                    ':ville'        => $donnees['ville'],
                ]);

                // Récupère l'id auto-incrémenté qui vient d'être créé
                $idUser = (int) $pdo->lastInsertId();

                // --- INSERT 2 : table client ---
                // id_client est à la fois clé primaire ET clé étrangère :
                // c'est la traduction de la spécialisation du MCD.
                $sql = "INSERT INTO client (id_client, adresse_facturation)
                        VALUES (:id_client, NULL)";

                $req = $pdo->prepare($sql);
                $req->execute([':id_client' => $idUser]);

                // Tout s'est bien passé : on valide définitivement
                $pdo->commit();

                // Le jeton CSRF est régénéré pour invalider l'ancien formulaire
                unset($_SESSION['csrf_token']);

                $succes  = true;
                $donnees = [];   // on vide le formulaire

            } catch (PDOException $e) {
                // Une des deux requêtes a échoué : on annule tout
                $pdo->rollBack();
                error_log('Erreur inscription : ' . $e->getMessage());
                $erreurs['global'] = "Une erreur technique est survenue. Merci de réessayer.";
            }
        }
    }
}

// Jeton à insérer dans le formulaire
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription client — Fair'repair</title>
    <style>
        /* =========================================================
           Fair'repair — Feuille de styles
           ========================================================= */

        /* ---------- Variables de couleurs ---------- */
        :root {
            --vert:         #0F766E;   /* couleur principale (écologie / vélo) */
            --vert-fonce:   #0B5A54;
            --vert-clair:   #E6F4F2;
            --bleu:         #1E50D4;
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

        /* ---------- Réinitialisation ---------- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--vert);
            text-decoration: none;
        }

        .logo span {
            color: var(--texte);
        }

        .entete nav a {
            color: var(--texte-doux);
            text-decoration: none;
            font-weight: 500;
        }

        .entete nav a:hover {
            color: var(--vert);
            text-decoration: underline;
        }

        /* ---------- Zone centrale ---------- */
        .page-inscription {
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
            max-width: 660px;
            box-shadow: 0 2px 10px rgba(13, 24, 41, 0.05);
        }

        .carte-formulaire h1 {
            font-size: 1.7rem;
            margin-bottom: 6px;
        }

        .sous-titre {
            color: var(--texte-doux);
            margin-bottom: 26px;
            font-size: 0.95rem;
        }

        /* ---------- Groupes de champs ---------- */
        fieldset {
            border: none;
            border-top: 1px solid var(--bordure);
            padding-top: 18px;
            margin-bottom: 22px;
        }

        legend {
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--vert);
            padding-right: 10px;
        }

        /* ---------- Disposition sur deux colonnes ---------- */
        .ligne {
            display: flex;
            gap: 16px;
        }

        .ligne .champ {
            flex: 1;
        }

        .champ-court {
            max-width: 150px;
        }

        /* ---------- Champs ---------- */
        .champ {
            margin-bottom: 16px;
            display: flex;
            flex-direction: column;
        }

        label {
            font-size: 0.88rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .obligatoire {
            color: var(--rouge);
        }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="password"] {
            padding: 10px 12px;
            border: 1px solid var(--bordure);
            border-radius: 6px;
            font-size: 0.95rem;
            font-family: inherit;
            background-color: var(--blanc);
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        input:focus {
            outline: none;
            border-color: var(--vert);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15);
        }

        input.invalide {
            border-color: var(--rouge);
            background-color: var(--rouge-clair);
        }

        /* ---------- Messages ---------- */
        .message-erreur {
            color: var(--rouge);
            font-size: 0.82rem;
            margin-top: 4px;
        }

        .aide {
            color: var(--texte-doux);
            font-size: 0.8rem;
            margin-top: 4px;
        }

        .alerte {
            padding: 14px 16px;
            border-radius: 6px;
            margin-bottom: 22px;
            font-size: 0.92rem;
        }

        .alerte-erreur {
            background-color: var(--rouge-clair);
            border-left: 4px solid var(--rouge);
            color: var(--rouge);
        }

        .alerte-succes {
            background-color: var(--succes-clair);
            border-left: 4px solid var(--succes);
            color: var(--succes);
        }

        .alerte a {
            color: inherit;
            font-weight: 600;
        }

        /* ---------- Case à cocher ---------- */
        .champ-case {
            margin: 4px 0 24px;
        }

        .case {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-weight: 400;
            font-size: 0.88rem;
            cursor: pointer;
        }

        .case input[type="checkbox"] {
            margin-top: 4px;
            width: 16px;
            height: 16px;
            accent-color: var(--vert);
            flex-shrink: 0;
        }

        .case a {
            color: var(--bleu);
        }

        /* ---------- Bouton ---------- */
        .bouton-principal {
            width: 100%;
            padding: 13px;
            background-color: var(--vert);
            color: var(--blanc);
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background-color 0.15s;
        }

        .bouton-principal:hover {
            background-color: var(--vert-fonce);
        }

        .bouton-principal:active {
            transform: translateY(1px);
        }

        /* ---------- Liens de bas de formulaire ---------- */
        .lien-bas {
            text-align: center;
            margin-top: 20px;
            font-size: 0.88rem;
            color: var(--texte-doux);
            line-height: 2;
        }

        .lien-bas a {
            color: var(--vert);
            font-weight: 600;
        }

        /* ---------- Pied de page ---------- */
        .pied {
            background-color: var(--blanc);
            border-top: 1px solid var(--bordure);
            text-align: center;
            padding: 18px;
            font-size: 0.82rem;
            color: var(--texte-doux);
        }

        /* ---------- Adaptation mobile ---------- */
        @media (max-width: 600px) {
            .carte-formulaire {
                padding: 24px 20px;
            }

            .ligne {
                flex-direction: column;
                gap: 0;
            }

            .champ-court {
                max-width: none;
            }

            .entete {
                padding: 14px 18px;
            }
        }
    </style>
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav>
        <a href="connexion.php">Se connecter</a>
    </nav>
</header>

<main class="page-inscription">
    <section class="carte-formulaire">

        <h1>Créer mon compte client</h1>
        <p class="sous-titre">
            Trouvez un réparateur de vélo près de chez vous et réservez en quelques clics.
        </p>

        <?php if ($succes): ?>
            <div class="alerte alerte-succes">
                <strong>Votre compte a bien été créé.</strong>
                Vous pouvez dès à présent <a href="connexion.php">vous connecter</a>.
            </div>
        <?php else: ?>

            <?php if (isset($erreurs['global'])): ?>
                <div class="alerte alerte-erreur"><?= e($erreurs['global']) ?></div>
            <?php endif; ?>

            <form method="post" action="inscription.php" novalidate>

                <!-- Jeton CSRF : indispensable, ne pas retirer -->
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <fieldset>
                    <legend>Votre identité</legend>

                    <div class="ligne">
                        <div class="champ">
                            <label for="prenom">Prénom <span class="obligatoire">*</span></label>
                            <input type="text" id="prenom" name="prenom" maxlength="50"
                                   value="<?= e($donnees['prenom'] ?? '') ?>"
                                   class="<?= isset($erreurs['prenom']) ? 'invalide' : '' ?>"
                                   required>
                            <?php if (isset($erreurs['prenom'])): ?>
                                <span class="message-erreur"><?= e($erreurs['prenom']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="champ">
                            <label for="nom">Nom <span class="obligatoire">*</span></label>
                            <input type="text" id="nom" name="nom" maxlength="50"
                                   value="<?= e($donnees['nom'] ?? '') ?>"
                                   class="<?= isset($erreurs['nom']) ? 'invalide' : '' ?>"
                                   required>
                            <?php if (isset($erreurs['nom'])): ?>
                                <span class="message-erreur"><?= e($erreurs['nom']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="champ">
                        <label for="telephone">Téléphone <span class="obligatoire">*</span></label>
                        <input type="tel" id="telephone" name="telephone"
                               placeholder="06 12 34 56 78"
                               value="<?= e($donnees['telephone'] ?? '') ?>"
                               class="<?= isset($erreurs['telephone']) ? 'invalide' : '' ?>"
                               required>
                        <?php if (isset($erreurs['telephone'])): ?>
                            <span class="message-erreur"><?= e($erreurs['telephone']) ?></span>
                        <?php endif; ?>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Votre adresse</legend>

                    <div class="champ">
                        <label for="adresse">Adresse <span class="obligatoire">*</span></label>
                        <input type="text" id="adresse" name="adresse" maxlength="255"
                               placeholder="12 rue de la République"
                               value="<?= e($donnees['adresse'] ?? '') ?>"
                               class="<?= isset($erreurs['adresse']) ? 'invalide' : '' ?>"
                               required>
                        <?php if (isset($erreurs['adresse'])): ?>
                            <span class="message-erreur"><?= e($erreurs['adresse']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ligne">
                        <div class="champ champ-court">
                            <label for="code_postal">Code postal <span class="obligatoire">*</span></label>
                            <input type="text" id="code_postal" name="code_postal"
                                   inputmode="numeric" maxlength="5" placeholder="75011"
                                   value="<?= e($donnees['code_postal'] ?? '') ?>"
                                   class="<?= isset($erreurs['code_postal']) ? 'invalide' : '' ?>"
                                   required>
                            <?php if (isset($erreurs['code_postal'])): ?>
                                <span class="message-erreur"><?= e($erreurs['code_postal']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="champ">
                            <label for="ville">Ville <span class="obligatoire">*</span></label>
                            <input type="text" id="ville" name="ville" maxlength="100"
                                   placeholder="Paris"
                                   value="<?= e($donnees['ville'] ?? '') ?>"
                                   class="<?= isset($erreurs['ville']) ? 'invalide' : '' ?>"
                                   required>
                            <?php if (isset($erreurs['ville'])): ?>
                                <span class="message-erreur"><?= e($erreurs['ville']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Vos identifiants de connexion</legend>

                    <div class="champ">
                        <label for="email">Adresse e-mail <span class="obligatoire">*</span></label>
                        <input type="email" id="email" name="email" maxlength="150"
                               placeholder="prenom.nom@exemple.fr"
                               value="<?= e($donnees['email'] ?? '') ?>"
                               class="<?= isset($erreurs['email']) ? 'invalide' : '' ?>"
                               required>
                        <?php if (isset($erreurs['email'])): ?>
                            <span class="message-erreur"><?= e($erreurs['email']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ligne">
                        <div class="champ">
                            <label for="mot_de_passe">Mot de passe <span class="obligatoire">*</span></label>
                            <input type="password" id="mot_de_passe" name="mot_de_passe"
                                   class="<?= isset($erreurs['mot_de_passe']) ? 'invalide' : '' ?>"
                                   required>
                            <?php if (isset($erreurs['mot_de_passe'])): ?>
                                <span class="message-erreur"><?= e($erreurs['mot_de_passe']) ?></span>
                            <?php else: ?>
                                <span class="aide">8 caractères min., 1 majuscule, 1 minuscule, 1 chiffre</span>
                            <?php endif; ?>
                        </div>

                        <div class="champ">
                            <label for="confirmation">Confirmation <span class="obligatoire">*</span></label>
                            <input type="password" id="confirmation" name="confirmation"
                                   class="<?= isset($erreurs['confirmation']) ? 'invalide' : '' ?>"
                                   required>
                            <?php if (isset($erreurs['confirmation'])): ?>
                                <span class="message-erreur"><?= e($erreurs['confirmation']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </fieldset>

                <div class="champ champ-case">
                    <label class="case">
                        <input type="checkbox" name="cgu" value="1">
                        <span>J'accepte les <a href="cgu.php">conditions générales d'utilisation</a>
                              et la politique de confidentialité. <span class="obligatoire">*</span></span>
                    </label>
                    <?php if (isset($erreurs['cgu'])): ?>
                        <span class="message-erreur"><?= e($erreurs['cgu']) ?></span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="bouton-principal">Créer mon compte</button>

                <p class="lien-bas">
                    Vous avez déjà un compte ? <a href="connexion.php">Se connecter</a><br>
                    Vous êtes réparateur ? <a href="inscription-reparateur.php">Inscrivez votre activité</a>
                </p>
            </form>

        <?php endif; ?>
    </section>
</main>

<footer class="pied">
    <p>&copy; <?= date('Y') ?> Fair'repair — Réparation de vélos entre particuliers et professionnels</p>
</footer>

</body>
</html>