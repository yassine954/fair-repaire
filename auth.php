<?php
/**
 * Gestion de l'authentification et des rôles — "middleware" de session
 * ---------------------------------------------------------------------
 * À inclure en TOUT DÉBUT de chaque page protégée, avant le moindre
 * affichage HTML (sinon header() échoue : les en-têtes HTTP doivent
 * partir avant le corps de la réponse).
 *
 * Utilisation type :
 *
 *     require_once __DIR__ . '/auth.php';
 *     traiterDemandeBascule();   // bouton « Client / Réparateur » de l'en-tête
 *     exigerRole('client');
 *
 * UN COMPTE, PLUSIEURS RÔLES
 * Un même utilisateur peut être client ET réparateur. La session contient :
 *   $_SESSION['roles']      ex. ['client' => true, 'reparateur' => 'valide', 'admin' => false]
 *   $_SESSION['role_actif'] l'espace dans lequel il se trouve : 'client' ou 'reparateur'
 * Les pages vérifient le rôle ACTIF : un utilisateur qui a les deux rôles
 * mais se trouve dans l'espace client reçoit une erreur 403 sur les pages
 * réparateur. Les deux espaces restent ainsi bien séparés.
 *
 * « SE SOUVENIR DE MOI »
 * Voir la partie « SE SOUVENIR DE MOI » plus bas : jetons de connexion
 * persistants (table jeton_connexion), motif sélecteur / validateur.
 */

require_once __DIR__ . '/securite.php';
require_once __DIR__ . '/database.php';   // $pdo : les rôles et les jetons sont lus en base

// Tableau de bord de chaque rôle
const ESPACES = [
    'client'     => 'dashboard-client.php',
    'reparateur' => 'dashboard-reparateur.php',
    'admin'      => 'dashboard-admin.php',
];

// Rôles entre lesquels l'utilisateur peut basculer lui-même
const ROLES_BASCULABLES = ['client', 'reparateur'];

const DUREE_INACTIVITE = 30 * 60;   // 30 minutes

// « Se souvenir de moi »
const COOKIE_SOUVENIR         = 'fr_souvenir';         // nom du cookie
const DUREE_SOUVENIR          = 30 * 24 * 60 * 60;     // 30 jours, en secondes
const DUREE_CONNEXION_RECENTE = 15 * 60;               // voir exigerConnexionRecente()

/**
 * L'utilisateur est-il connecté ?
 *
 * Si aucune session n'est ouverte, on essaie de la rouvrir grâce au cookie
 * « Se souvenir de moi » (verifierJetonSouvenir()). Cette fonction peut
 * donc envoyer des en-têtes HTTP (nouveau cookie, nouvel identifiant de
 * session) : elle doit être appelée AVANT tout affichage HTML.
 */
function estConnecte(): bool
{
    if (!empty($_SESSION['id_user'])) {
        return true;
    }
    global $pdo;
    return verifierJetonSouvenir($pdo);
}

/*
 * =====================================================================
 *  DÉDUCTION DES RÔLES À PARTIR DES TABLES
 * =====================================================================
 * Un utilisateur est client s'il a une ligne dans la table client, et
 * réparateur s'il a une ligne dans la table reparateur. On ne lit PLUS
 * la colonne utilisateur.type_user pour décider des droits.
 *
 * Pourquoi c'est plus sûr :
 *   - un ENUM ne contient qu'UNE valeur : il ne peut pas exprimer
 *     « client ET réparateur » ;
 *   - c'est une information EN DOUBLE : la vraie preuve d'appartenance
 *     est la ligne dans la table fille. Deux informations qui disent la
 *     même chose finissent par se contredire (une mise à jour oubliée,
 *     une ligne supprimée à la main dans phpMyAdmin…). En lisant
 *     directement les tables, il n'y a qu'une seule source de vérité ;
 *   - les données propres au réparateur (SIRET, statut de validation)
 *     sont dans sa table : on peut exiger un statut « valide », ce
 *     qu'un simple type_user ne permettrait pas.
 */

/**
 * Lit en base les rôles d'un utilisateur, en UNE seule requête.
 *
 * Renvoie par exemple :
 *   ['client' => true, 'reparateur' => 'valide', 'admin' => false]
 * Pour le réparateur : la valeur de statut_validation ('en_attente',
 * 'valide' ou 'refuse'), ou false s'il n'a aucune ligne dans la table.
 */
function chargerRoles(PDO $pdo, int $idUser): array
{
    // EXISTS(...) vaut 1 si la sous-requête trouve au moins une ligne, 0 sinon.
    // La troisième sous-requête renvoie le statut, ou NULL s'il n'y a pas de ligne.
    // Trois marqueurs différents (:id1, :id2, :id3) : les vraies requêtes
    // préparées interdisent de réutiliser deux fois le même nom.
    $sql = "SELECT
                EXISTS(SELECT 1 FROM client         WHERE id_client = :id1) AS est_client,
                EXISTS(SELECT 1 FROM administrateur WHERE id_admin  = :id2) AS est_admin,
                (SELECT statut_validation FROM reparateur
                 WHERE id_reparateur = :id3)                                AS statut_reparateur";
    $req = $pdo->prepare($sql);
    $req->execute([':id1' => $idUser, ':id2' => $idUser, ':id3' => $idUser]);
    $ligne = $req->fetch();

    return [
        'client'     => (bool) $ligne['est_client'],
        'reparateur' => $ligne['statut_reparateur'] ?? false,
        'admin'      => (bool) $ligne['est_admin'],
    ];
}

/**
 * Le rôle est-il utilisable ?
 * Pour 'reparateur', la ligne doit exister ET être validée : une demande
 * en attente ou refusée ne donne accès à rien.
 * La lecture se fait dans $_SESSION['roles'], rafraîchi depuis la base à
 * chaque page protégée (voir exigerConnexion()).
 */
function aRole(string $role): bool
{
    $roles = $_SESSION['roles'] ?? [];
    if ($role === 'reparateur') {
        return ($roles['reparateur'] ?? false) === 'valide';
    }
    return ($roles[$role] ?? false) === true;
}

/** Statut de la demande réparateur ('en_attente', 'valide', 'refuse') ou false. */
function statutReparateur(): string|false
{
    return $_SESSION['roles']['reparateur'] ?? false;
}

/** L'utilisateur a-t-il les DEUX rôles utilisables (donc le bouton de bascule) ? */
function peutBasculer(): bool
{
    return aRole('client') && aRole('reparateur');
}

/** Rôle actif (espace dans lequel se trouve l'utilisateur), ou null. */
function roleActif(): ?string
{
    return $_SESSION['role_actif'] ?? null;
}

/** Ancien nom conservé pour les fichiers qui l'utilisent (api/reparateurs.php). */
function roleUtilisateur(): ?string
{
    return roleActif();
}

/** Espace par défaut : client en priorité, sinon réparateur validé, sinon admin. */
function roleParDefaut(): ?string
{
    foreach (['client', 'reparateur', 'admin'] as $role) {
        if (aRole($role)) {
            return $role;
        }
    }
    return null;   // ex. compte réparateur seul, demande encore en attente
}

/** Page d'accueil du rôle actif (ou page de suivi de la demande à défaut). */
function espaceActif(): string
{
    return ESPACES[roleActif() ?? ''] ?? 'devenir-reparateur.php';
}

/**
 * Relit les rôles en base et les range en session.
 * Si le rôle actif n'est plus utilisable (profil réparateur refusé ou
 * fermé pendant que l'utilisateur était connecté), on le replace dans
 * son espace par défaut.
 */
function rafraichirRoles(): void
{
    global $pdo;
    $_SESSION['roles'] = chargerRoles($pdo, (int) $_SESSION['id_user']);

    if (roleActif() === null || !aRole(roleActif())) {
        $_SESSION['role_actif'] = roleParDefaut();
    }
}

/**
 * Enregistre l'utilisateur en session après une connexion réussie.
 *
 * session_regenerate_id() est ESSENTIEL : il change l'identifiant de
 * session au moment du login. Sans lui, un attaquant qui aurait réussi
 * à imposer un identifiant de session à la victime avant sa connexion
 * (attaque de fixation de session) se retrouverait connecté à sa place.
 *
 * $parJeton : true si la session est rouverte par le cookie « Se souvenir
 * de moi » et non par le mot de passe (voir « SESSION PERSISTANTE » plus bas).
 *
 * type_user n'est volontairement PAS copié en session : il ne donne plus
 * aucun droit, les rôles viennent de chargerRoles().
 */
function ouvrirSession(array $utilisateur, bool $parJeton = false): void
{
    session_regenerate_id(true);

    $_SESSION['id_user']           = (int) $utilisateur['id_user'];
    $_SESSION['prenom']            = $utilisateur['prenom'];
    $_SESSION['nom']               = $utilisateur['nom'];
    $_SESSION['email']             = $utilisateur['email'];
    $_SESSION['derniere_activite'] = time();

    // Niveau de confiance de la session
    $_SESSION['connexion_persistante'] = $parJeton;
    $_SESSION['date_authentification'] = $parJeton ? null : time();   // dernière saisie du mot de passe
    unset($_SESSION['id_jeton']);   // fixé ensuite par creerJetonSouvenir() ou verifierJetonSouvenir()

    // Rôles lus en base ; on arrive dans l'espace client par défaut
    unset($_SESSION['role_actif']);
    rafraichirRoles();
}

/**
 * Détruit complètement la session (déconnexion).
 * L'appareil est aussi « oublié » : son jeton « Se souvenir de moi » est
 * supprimé, sinon le cookie rouvrirait la session à la page suivante.
 */
function fermerSession(): void
{
    global $pdo;
    supprimerJetonSouvenir($pdo);

    $_SESSION = [];

    // Supprime aussi le cookie de session côté navigateur
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Bloque l'accès si l'utilisateur n'est pas connecté.
 * Mémorise la page demandée pour y revenir après la connexion.
 */
function exigerConnexion(): void
{
    global $pdo;

    // estConnecte() tente aussi de rouvrir la session avec le cookie
    // « Se souvenir de moi » : on ne redirige que si cela échoue.
    if (!estConnecte()) {
        $_SESSION['redirection'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: connexion.php?msg=connexion_requise');
        exit;
    }

    // --- Expiration après 30 minutes d'inactivité ---
    if (isset($_SESSION['derniere_activite'])
        && (time() - $_SESSION['derniere_activite']) > DUREE_INACTIVITE) {
        // On oublie la SESSION (données vidées, nouvel identifiant)…
        // …mais PAS l'appareil : si « Se souvenir de moi » a été coché,
        // le cookie rouvre une nouvelle session, marquée « persistante ».
        $_SESSION = [];
        session_regenerate_id(true);
        if (!verifierJetonSouvenir($pdo)) {
            header('Location: connexion.php?msg=session_expiree');
            exit;
        }
    }

    // --- Appareil révoqué ? ---
    // Une session ouverte avec « Se souvenir de moi » reste liée à son jeton
    // ($_SESSION['id_jeton']). Si ce jeton a disparu (bouton « Révoquer » de
    // « Mes appareils », ou détection d'un vol de cookie), la session est
    // coupée immédiatement : supprimer le jeton ne suffirait pas à chasser
    // un voleur qui aurait DÉJÀ une session ouverte.
    if (!empty($_SESSION['id_jeton']) && !jetonDeSessionValide($pdo)) {
        effacerCookieSouvenir();
        fermerSession();
        header('Location: connexion.php?msg=appareil_revoque');
        exit;
    }

    $_SESSION['derniere_activite'] = time();

    // --- Rôles relus en base à CHAQUE page protégée ---
    // Une demande validée par l'administrateur, ou un profil réparateur
    // fermé, prend effet immédiatement, sans avoir à se déconnecter.
    // Coût : une petite requête sur des clés primaires (très rapide).
    rafraichirRoles();
}

/**
 * Bloque l'accès si le rôle ACTIF n'est pas celui attendu.
 *
 * On vérifie le rôle actif, et non « l'utilisateur possède-t-il ce rôle » :
 * un utilisateur client + réparateur qui se trouve dans l'espace client
 * n'ouvre pas le tableau de bord réparateur en tapant son URL. Il doit
 * d'abord basculer avec le bouton prévu (qui revérifie ses droits).
 */
function exigerRole(string $roleAttendu): void
{
    exigerConnexion();

    if (roleActif() !== $roleAttendu || !aRole($roleAttendu)) {
        http_response_code(403);
        exit('<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Accès interdit</title></head>'
           . '<body style="font-family:sans-serif;padding:40px">'
           . '<h1>403 — Accès interdit</h1>'
           . '<p>Cette page n\'est pas accessible depuis votre espace actuel.</p>'
           . '<p><a href="' . e(espaceActif()) . '">Retourner à mon espace</a></p>'
           . '</body></html>');
    }
}

/*
 * =====================================================================
 *  SESSION PERSISTANTE = CONFIANCE RÉDUITE
 * =====================================================================
 * Une session rouverte par le cookie « Se souvenir de moi » prouve
 * seulement que le NAVIGATEUR possède le cookie, pas que la personne
 * devant l'écran connaît le mot de passe. Le cookie a pu être copié
 * (logiciel espion, ordinateur partagé ou prêté, profil de navigateur
 * sauvegardé…), et l'ordinateur a pu rester allumé sans surveillance.
 *
 * La session le note dans $_SESSION['connexion_persistante']. Les actions
 * qui permettraient de s'approprier DÉFINITIVEMENT le compte (changer le
 * mot de passe, changer l'e-mail, supprimer le compte) doivent appeler
 * exigerConnexionRecente(), qui redemande le mot de passe. Sans cela, un
 * voleur de cookie pourrait changer l'e-mail puis le mot de passe : le
 * vrai propriétaire perdrait son compte pour de bon.
 *
 * Même avec une connexion par mot de passe, on exige que la saisie date
 * de moins de DUREE_CONNEXION_RECENTE (15 min) : une session ouverte le
 * matin et laissée sans surveillance ne suffit pas.
 */

/**
 * À appeler en HAUT d'une page sensible (avant d'afficher son formulaire).
 * Si la dernière saisie du mot de passe n'est pas récente, l'utilisateur
 * est envoyé sur la page de connexion en mode « confirmation », puis
 * ramené ici une fois son mot de passe confirmé.
 *
 *     require_once __DIR__ . '/auth.php';
 *     exigerConnexionRecente();
 */
function exigerConnexionRecente(): void
{
    exigerConnexion();

    $recente = empty($_SESSION['connexion_persistante'])
            && !empty($_SESSION['date_authentification'])
            && time() - $_SESSION['date_authentification'] <= DUREE_CONNEXION_RECENTE;

    if (!$recente) {
        $_SESSION['redirection'] = $_SERVER['REQUEST_URI'] ?? espaceActif();
        header('Location: connexion.php?confirmer=1');
        exit;
    }
}

/**
 * Le mot de passe vient d'être retapé (mode confirmation de connexion.php) :
 * la session redevient « fraîche », sans changer d'utilisateur ni d'espace.
 */
function confirmerAuthentification(): void
{
    session_regenerate_id(true);
    $_SESSION['connexion_persistante'] = false;
    $_SESSION['date_authentification'] = time();
}

/*
 * =====================================================================
 *  JOURNAL DES CONNEXIONS (table tentatives_connexion)
 * =====================================================================
 * Chaque tentative de connexion est enregistrée : les réussites, les
 * échecs ET les tentatives refusées (compte bloqué, jeton CSRF invalide),
 * ainsi que les déconnexions.
 *
 * POURQUOI JOURNALISER AUSSI LES ÉCHECS ET LES REFUS ?
 * Ce sont eux qui racontent une attaque : une rafale d'échecs depuis une
 * même adresse IP, un compte bloqué que l'on continue de marteler, une
 * connexion réussie juste après une série d'échecs (mot de passe deviné)…
 * Un journal qui ne garderait que les réussites ne montrerait rien de tout
 * cela : une tentative refusée est exactement ce qu'un audit doit voir.
 *
 * La fonction est ici et non dans connexion.php : les tableaux de bord
 * l'appellent aussi pour noter les déconnexions, et inclure connexion.php
 * exécuterait toute la page de connexion.
 */

// Valeurs de l'ENUM tentatives_connexion.motif, avec leur libellé
const MOTIFS_CONNEXION = [
    'succes'                 => 'Connexion réussie',
    'mot_de_passe_incorrect' => 'Mot de passe incorrect',
    'email_inconnu'          => 'E-mail inconnu',
    'compte_bloque'          => 'Compte bloqué',
    'csrf_invalide'          => 'Jeton CSRF invalide',
    'deconnexion'            => 'Déconnexion',
];

/**
 * Enregistre une tentative de connexion (ou une déconnexion).
 *
 * $reussie : la colonne est GARDÉE même si $motif est plus précis. Le
 *   compteur anti force brute de connexion.php filtre d'abord sur
 *   « reussie = 0 », une colonne indexée (idx_reussie) : la requête la plus
 *   fréquente du site (une à chaque tentative) reste simple et rapide.
 *
 * $idUser : NULL quand l'e-mail ne correspond à aucun compte (motif
 *   'email_inconnu'). La colonne accepte NULL pour cette raison, et aussi
 *   parce que sa clé étrangère est en ON DELETE SET NULL : si un compte est
 *   supprimé, ses lignes restent dans le journal (l'e-mail est conservé en
 *   texte) et l'historique de sécurité ne perd rien.
 */
function enregistrerTentative(PDO $pdo, string $email, string $ip,
                              bool $reussie, string $motif,
                              ?int $idUser = null): void
{
    // Une faute de frappe dans le code (« sucess ») serait refusée par l'ENUM ;
    // on la signale ici avec un message clair.
    if (!array_key_exists($motif, MOTIFS_CONNEXION)) {
        throw new InvalidArgumentException("Motif de connexion inconnu : $motif");
    }

    $sql = "INSERT INTO tentatives_connexion
                (email, id_user, ip, navigateur, reussie, motif, `timestamp`)
            VALUES
                (:email, :id_user, :ip, :navigateur, :reussie, :motif, :horodatage)";

    $pdo->prepare($sql)->execute([
        // L'e-mail est tapé par le visiteur : les octets invalides sont
        // remplacés (mb_scrub), puis il est tronqué à la taille de la colonne.
        // Sans cela, un e-mail piégé ferait échouer l'INSERT… et la tentative
        // échapperait au journal comme au compteur anti force brute.
        ':email'      => mb_substr(mb_scrub($email, 'UTF-8'), 0, 150),
        ':id_user'    => $idUser,
        ':ip'         => mb_substr($ip, 0, 45),
        ':navigateur' => navigateurTronque(),   // User-Agent (255 caractères max) ou NULL
        // (int) indispensable : PDO envoie les paramètres sous forme de texte,
        // et false deviendrait une chaîne vide au lieu de 0.
        ':reussie'    => (int) $reussie,
        ':motif'      => $motif,
        ':horodatage' => date('Y-m-d H:i:s'),
    ]);
}

/*
 * =====================================================================
 *  « SE SOUVENIR DE MOI » — MOTIF SÉLECTEUR / VALIDATEUR
 * =====================================================================
 * Le cookie contient « selecteur:validateur », deux valeurs aléatoires.
 * Le mot de passe n'y figure JAMAIS, ni en clair ni haché.
 *
 *   selecteur  (32 caractères) : identifiant PUBLIC du jeton, stocké en
 *                                clair. Il sert uniquement à RETROUVER la
 *                                ligne (index UNIQUE, recherche immédiate).
 *   validateur (64 caractères) : le SECRET. La base ne garde que son
 *                                empreinte SHA-256 (validateur_hache).
 *
 * POURQUOI DEUX PARTIES ?
 *   - On retrouve la ligne avec une valeur NON secrète (le sélecteur), puis
 *     on compare le secret en PHP avec hash_equals(), en temps constant.
 *     Chercher directement « WHERE validateur_hache = ... » ferait comparer
 *     le secret par MySQL, dont le temps de réponse varie selon le nombre
 *     de caractères identiques : une fuite exploitable (attaque temporelle).
 *   - Si la base fuit (sauvegarde volée, injection SQL ailleurs), le pirate
 *     lit les sélecteurs et les EMPREINTES, mais jamais les validateurs :
 *     il ne peut fabriquer aucun cookie valable.
 *
 * POURQUOI SHA-256 ET PAS password_hash() (bcrypt) ?
 *   bcrypt est volontairement LENT, pour empêcher de retrouver un mot de
 *   passe humain (court, prévisible) en essayant des milliards de
 *   combinaisons. Ici, le validateur est une valeur aléatoire de 256 bits :
 *   2^256 possibilités, impossibles à parcourir même avec un hachage
 *   rapide. bcrypt ne ferait que ralentir CHAQUE page qui vérifie un
 *   jeton, sans aucun gain de sécurité.
 *
 * random_bytes() UNIQUEMENT : rand(), mt_rand() et uniqid() ne sont pas
 * cryptographiquement sûrs. Après avoir observé quelques valeurs de rand()
 * ou mt_rand(), on peut prédire les suivantes, et uniqid() n'est qu'une
 * horloge déguisée. Un jeton prévisible se fabrique sans rien voler.
 *
 * ROTATION À CHAQUE UTILISATION (et pourquoi on GARDE le sélecteur)
 *   Chaque fois que le cookie rouvre une session, le validateur est
 *   remplacé par un nouveau (nouvelle empreinte en base, nouveau cookie).
 *   Un validateur ne sert donc qu'UNE fois : un cookie volé devient
 *   inutilisable dès que le vrai utilisateur s'en resert.
 *   Le sélecteur, lui, reste le même : il identifie « cet appareil » d'une
 *   rotation à l'autre. C'est ce qui permet de DÉTECTER le vol :
 *     1. un voleur copie le cookie (S, V1) ;
 *     2. le premier des deux qui s'en sert (voleur ou propriétaire) fait
 *        tourner le jeton : la base attend désormais (S, V2), et lui seul
 *        reçoit V2 ;
 *     3. quand l'autre présente (S, V1), le sélecteur S existe toujours
 *        mais V1 ne correspond plus : deux navigateurs ont eu le même
 *        jeton → vol détecté (voir verifierJetonSouvenir(), étape 6).
 *   Si l'on créait un NOUVEAU sélecteur à chaque rotation, l'ancien
 *   disparaîtrait de la base : le cookie copié donnerait « jeton
 *   introuvable », comme un simple jeton expiré, et le vol passerait
 *   inaperçu.
 *
 * HORLOGE : toutes les dates de la table viennent de MySQL (NOW()). Depuis
 * database.php, PHP et MySQL sont tous deux à l'heure de Paris ; garder une
 * seule source d'heure par table reste malgré tout la règle la plus sûre.
 */

/**
 * Options communes du cookie (création ET suppression : un cookie ne
 * s'efface qu'avec le même chemin et le même domaine que lors de sa pose).
 */
function optionsCookieSouvenir(int $expiration): array
{
    return [
        'expires'  => $expiration,
        'path'     => '/',
        'httponly' => true,    // illisible depuis JavaScript : limite le vol par une faille XSS
        'samesite' => 'Lax',   // Lax et non Strict : le cookie est encore envoyé quand
                               // l'utilisateur arrive depuis un lien externe (e-mail…)
        'secure'   => false,   // TODO PRODUCTION : passer à true dès que le site est en HTTPS,
                               // sinon le cookie circule en clair et peut être intercepté
    ];
}

/**
 * Pose le cookie « selecteur:validateur ».
 * setrawcookie() : la valeur ne contient que des chiffres hexadécimaux et
 * « : », inutile de l'encoder. Elle reste lisible telle quelle dans les
 * outils de développement du navigateur.
 */
function envoyerCookieSouvenir(string $selecteur, string $validateur): void
{
    $valeur = $selecteur . ':' . $validateur;
    setrawcookie(COOKIE_SOUVENIR, $valeur, optionsCookieSouvenir(time() + DUREE_SOUVENIR));
    $_COOKIE[COOKIE_SOUVENIR] = $valeur;   // cohérent pour la suite de la requête
}

/** Efface le cookie du navigateur (date d'expiration dans le passé). */
function effacerCookieSouvenir(): void
{
    setrawcookie(COOKIE_SOUVENIR, '', optionsCookieSouvenir(time() - 3600));
    unset($_COOKIE[COOKIE_SOUVENIR]);
}

/**
 * User-Agent du navigateur, nettoyé et tronqué à 255 caractères (taille
 * des colonnes jeton_connexion.navigateur et tentatives_connexion.navigateur),
 * ou NULL s'il est absent.
 */
function navigateurTronque(): ?string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '') {
        return null;
    }
    // L'en-tête est écrit par le navigateur : il peut contenir des octets
    // invalides, que MySQL refuserait. mb_scrub() les remplace.
    // mb_substr() et non substr() : substr() compte des OCTETS et peut couper
    // un caractère accentué en deux, ce qui recréerait un octet invalide.
    return mb_substr(mb_scrub($ua, 'UTF-8'), 0, 255);
}

/**
 * Crée un jeton « Se souvenir de moi » pour cet appareil et pose le cookie.
 * À appeler UNIQUEMENT après une vérification réussie du mot de passe.
 */
function creerJetonSouvenir(PDO $pdo, int $idUser): void
{
    // Un éventuel ancien jeton de ce navigateur est d'abord supprimé
    supprimerJetonSouvenir($pdo);

    $selecteur  = bin2hex(random_bytes(16));   // 16 octets → 32 caractères hexadécimaux
    $validateur = bin2hex(random_bytes(32));   // 32 octets → 64 caractères (256 bits)
    // (Deux sélecteurs identiques sur 2^128 possibilités : impossible en pratique.
    //  La contrainte UNIQUE garantit de toute façon qu'il n'y en aura jamais deux.)

    $sql = "INSERT INTO jeton_connexion
                (selecteur, validateur_hache, id_user, date_creation, date_expiration, adresse_ip, navigateur)
            VALUES
                (:selecteur, :validateur_hache, :id_user, NOW(),
                 TIMESTAMPADD(SECOND, :duree, NOW()), :adresse_ip, :navigateur)";
    $pdo->prepare($sql)->execute([
        ':selecteur'        => $selecteur,
        ':validateur_hache' => hash('sha256', $validateur),   // JAMAIS le validateur lui-même
        ':id_user'          => $idUser,
        ':duree'            => DUREE_SOUVENIR,
        ':adresse_ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
        ':navigateur'       => navigateurTronque(),
    ]);

    $_SESSION['id_jeton'] = (int) $pdo->lastInsertId();   // la session est liée à ce jeton
    envoyerCookieSouvenir($selecteur, $validateur);
}

/**
 * Rouvre automatiquement la session à partir du cookie « Se souvenir de moi ».
 * Appelée par estConnecte() quand aucune session n'est ouverte.
 * Renvoie true si la session a été rouverte.
 */
function verifierJetonSouvenir(PDO $pdo): bool
{
    // 1. Pas de cookie : rien à faire
    $valeur = $_COOKIE[COOKIE_SOUVENIR] ?? null;
    if (!is_string($valeur) || $valeur === '') {
        return false;
    }

    // Sécurité : si la page a déjà commencé à s'afficher, on ne pourrait
    // plus envoyer le nouveau cookie après la rotation. On n'y touche pas.
    if (headers_sent()) {
        return false;
    }

    // 2. Exactement deux parties « selecteur:validateur », au bon format.
    //    Sinon le cookie est abîmé ou fabriqué : on l'efface.
    $morceaux = explode(':', $valeur);
    if (count($morceaux) !== 2
        || !preg_match('/^[0-9a-f]{32}$/', $morceaux[0])
        || !preg_match('/^[0-9a-f]{64}$/', $morceaux[1])) {
        effacerCookieSouvenir();
        return false;
    }
    [$selecteur, $validateur] = $morceaux;

    // 3. Recherche par le sélecteur (non secret), jeton non expiré
    $req = $pdo->prepare("SELECT id_jeton, id_user, validateur_hache
                          FROM jeton_connexion
                          WHERE selecteur = :selecteur
                            AND date_expiration > NOW()");
    $req->execute([':selecteur' => $selecteur]);
    $jeton = $req->fetch();

    // 4. Jeton inconnu ou expiré : le cookie ne sert plus à rien
    if (!$jeton) {
        effacerCookieSouvenir();
        return false;
    }

    // 5. Comparaison du validateur avec hash_equals(), jamais avec == :
    //    == s'arrête au premier caractère différent, donc répond un peu
    //    plus vite quand le début est faux. En mesurant ces temps, on
    //    pourrait deviner le secret caractère par caractère (attaque
    //    temporelle). hash_equals() met toujours le même temps.
    if (!hash_equals($jeton['validateur_hache'], hash('sha256', $validateur))) {

        // 6. SÉLECTEUR CONNU MAIS VALIDATEUR FAUX → VOL PROBABLE DU COOKIE
        //    Cela ne peut pas arriver par hasard : sélecteur et validateur
        //    sont toujours créés ensemble, et seul le validateur change à
        //    chaque rotation. Un ancien validateur présenté avec un sélecteur
        //    encore valide signifie que DEUX navigateurs ont eu le même
        //    cookie et que l'autre s'en est déjà servi : c'est la signature
        //    d'un cookie copié.
        //    On ne sait pas lequel des deux est le voleur (celui qui se
        //    présente maintenant, ou celui qui est passé avant). On choisit
        //    donc la sécurité : TOUS les jetons de ce compte sont supprimés.
        //    Chaque appareil devra retaper le mot de passe, que le voleur ne
        //    connaît pas, et les sessions ouvertes grâce à ces jetons sont
        //    coupées à leur prochaine requête (voir exigerConnexion()).
        //    Prix à payer : le vrai propriétaire doit lui aussi se reconnecter.
        //    Limite connue : si deux onglets rouvrent la session EXACTEMENT au
        //    même instant avec le même cookie, le second peut déclencher une
        //    fausse alerte. L'erreur se fait alors dans le sens sûr.
        revoquerTousLesJetons($pdo, (int) $jeton['id_user']);
        error_log(sprintf('[Fair\'repair] Vol de jeton probable : utilisateur %d, IP %s',
                          $jeton['id_user'], $_SERVER['REMOTE_ADDR'] ?? '?'));
        effacerCookieSouvenir();
        $_SESSION['alerte_vol_jeton'] = true;   // message affiché sur la page de connexion
        return false;
    }

    // 7. Tout correspond : on charge l'utilisateur…
    $req = $pdo->prepare("SELECT id_user, nom, prenom, email FROM utilisateur WHERE id_user = :id_user");
    $req->execute([':id_user' => $jeton['id_user']]);
    $utilisateur = $req->fetch();
    if (!$utilisateur) {
        effacerCookieSouvenir();
        return false;
    }

    // …puis on fait TOURNER le jeton : nouveau validateur (même sélecteur,
    // voir l'explication plus haut), nouvelle expiration, IP et navigateur
    // de cette utilisation (visibles dans « Mes appareils »).
    // Le « AND validateur_hache = :ancien_hache » est un verrou optimiste :
    // si deux requêtes arrivent en même temps avec le même cookie, une seule
    // réussit la rotation. L'autre n'ouvre pas de session (rowCount = 0).
    // La rotation est faite AVANT ouvrirSession() pour cette raison.
    $nouveauValidateur = bin2hex(random_bytes(32));
    $req = $pdo->prepare("UPDATE jeton_connexion
                          SET validateur_hache = :nouveau_hache,
                              date_expiration  = TIMESTAMPADD(SECOND, :duree, NOW()),
                              adresse_ip       = :adresse_ip,
                              navigateur       = :navigateur
                          WHERE id_jeton         = :id_jeton
                            AND validateur_hache = :ancien_hache");
    $req->execute([
        ':nouveau_hache' => hash('sha256', $nouveauValidateur),
        ':duree'         => DUREE_SOUVENIR,
        ':adresse_ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
        ':navigateur'    => navigateurTronque(),
        ':id_jeton'      => $jeton['id_jeton'],
        ':ancien_hache'  => $jeton['validateur_hache'],
    ]);
    if ($req->rowCount() === 0) {
        return false;
    }

    ouvrirSession($utilisateur, true);                 // session marquée « persistante »
    $_SESSION['id_jeton'] = (int) $jeton['id_jeton'];   // liée à ce jeton
    envoyerCookieSouvenir($selecteur, $nouveauValidateur);
    nettoyerJetons($pdo);

    // 8. Session rouverte
    return true;
}

/**
 * Oublie CET appareil : supprime son jeton en base et efface son cookie.
 * Appelée par fermerSession() (déconnexion). Sans effet si l'appareil
 * n'a pas de jeton, et sans effet au deuxième appel.
 */
function supprimerJetonSouvenir(PDO $pdo): void
{
    $valeur = $_COOKIE[COOKIE_SOUVENIR] ?? null;

    if (is_string($valeur)) {
        // Le jeton est retrouvé par son sélecteur (la partie avant « : »)
        $selecteur = explode(':', $valeur)[0];
        if (preg_match('/^[0-9a-f]{32}$/', $selecteur)) {
            $pdo->prepare("DELETE FROM jeton_connexion WHERE selecteur = :selecteur")
                ->execute([':selecteur' => $selecteur]);
        }
        effacerCookieSouvenir();
    }

    // Jeton lié à la session : supprimé aussi, même si le cookie a été
    // effacé à la main (sinon la ligne resterait valable en base)
    if (!empty($_SESSION['id_jeton']) && !empty($_SESSION['id_user'])) {
        $pdo->prepare("DELETE FROM jeton_connexion WHERE id_jeton = :id_jeton AND id_user = :id_user")
            ->execute([':id_jeton' => $_SESSION['id_jeton'], ':id_user' => $_SESSION['id_user']]);
    }
    unset($_SESSION['id_jeton']);
}

/**
 * Supprime TOUS les jetons d'un utilisateur : déconnecte tous ses appareils.
 * Utilisée quand un vol est détecté. À appeler aussi, plus tard, quand
 * l'utilisateur change son mot de passe.
 */
function revoquerTousLesJetons(PDO $pdo, int $idUser): void
{
    $pdo->prepare("DELETE FROM jeton_connexion WHERE id_user = :id_user")
        ->execute([':id_user' => $idUser]);
}

/** Le jeton auquel la session est liée existe-t-il toujours ? */
function jetonDeSessionValide(PDO $pdo): bool
{
    $req = $pdo->prepare("SELECT 1 FROM jeton_connexion
                          WHERE id_jeton = :id_jeton
                            AND id_user  = :id_user
                            AND date_expiration > NOW()");
    $req->execute([':id_jeton' => $_SESSION['id_jeton'], ':id_user' => $_SESSION['id_user']]);
    return (bool) $req->fetchColumn();
}

/**
 * Supprime les jetons expirés.
 * Il n'y a pas de tâche planifiée (cron) sur ce projet : comme pour
 * nettoyerTentatives() dans connexion.php, le ménage est déclenché au
 * hasard, en moyenne une fois sur 50. Les autres appels repartent aussitôt.
 */
function nettoyerJetons(PDO $pdo): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }
    $pdo->prepare("DELETE FROM jeton_connexion WHERE date_expiration < NOW()")->execute();
}

/*
 * =====================================================================
 *  MES APPAREILS CONNECTÉS
 * =====================================================================
 */

/** Appareils (jetons non expirés) de l'utilisateur, du plus récent au plus ancien. */
function listerAppareils(PDO $pdo, int $idUser): array
{
    $req = $pdo->prepare("SELECT id_jeton, date_creation, date_expiration, adresse_ip, navigateur
                          FROM jeton_connexion
                          WHERE id_user = :id_user
                            AND date_expiration > NOW()
                          ORDER BY date_creation DESC");
    $req->execute([':id_user' => $idUser]);
    return $req->fetchAll();
}

/**
 * Traite le bouton « Révoquer » de la liste des appareils
 * (action=revoquer_appareil, POST + jeton CSRF).
 * À appeler en haut des pages qui affichent la liste, avant tout affichage.
 */
function traiterRevocationAppareil(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'revoquer_appareil') {
        return;
    }
    exigerConnexion();
    global $pdo;

    if (!verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        definirFlash('erreur', "Requête invalide. Merci de recharger la page.");
    } else {
        $idJeton = (int) ($_POST['id_jeton'] ?? 0);

        // PROTECTION IDOR : l'id du jeton vient du formulaire et peut être
        // modifié. « AND id_user = » (id de la SESSION) garantit qu'on ne
        // supprime jamais le jeton d'un autre utilisateur : si l'id ne lui
        // appartient pas, aucune ligne ne correspond.
        $req = $pdo->prepare("DELETE FROM jeton_connexion WHERE id_jeton = :id_jeton AND id_user = :id_user");
        $req->execute([':id_jeton' => $idJeton, ':id_user' => $_SESSION['id_user']]);

        if ($req->rowCount() === 0) {
            definirFlash('erreur', "Appareil introuvable.");
        } elseif ($idJeton === (int) ($_SESSION['id_jeton'] ?? 0)) {
            // C'est CET appareil : on efface aussi son cookie. La session en
            // cours continue, mais ne sera plus rouverte automatiquement.
            effacerCookieSouvenir();
            unset($_SESSION['id_jeton']);
            definirFlash('succes', "Cet appareil ne sera plus reconnu automatiquement.");
        } else {
            definirFlash('succes', "L'appareil a été déconnecté. Sa session se fermera à sa prochaine action.");
        }
    }

    header('Location: ' . espaceActif() . '#appareils');
    exit;
}

/*
 * =====================================================================
 *  BASCULE CLIENT ⇄ RÉPARATEUR
 * =====================================================================
 */

/**
 * Change le rôle actif, APRÈS vérification côté serveur.
 *
 * La valeur $nouveauRole vient du formulaire, donc du navigateur : elle
 * peut avoir été modifiée (« admin », ou « reparateur » alors que la
 * demande est encore en attente). On ne lui fait JAMAIS confiance :
 *   1. elle doit faire partie de la liste des rôles basculables ;
 *   2. les rôles sont RELUS en base juste avant (un profil fermé il y a
 *      une minute n'est plus utilisable) ;
 *   3. aRole() doit confirmer que l'utilisateur possède ce rôle.
 * Si une seule condition échoue, rien ne change.
 */
function basculerRole(string $nouveauRole): bool
{
    if (!in_array($nouveauRole, ROLES_BASCULABLES, true)) {
        return false;
    }

    rafraichirRoles();
    if (!aRole($nouveauRole)) {
        return false;
    }

    if (roleActif() !== $nouveauRole) {
        // Changement de privilèges → nouvel identifiant de session
        session_regenerate_id(true);
        $_SESSION['role_actif'] = $nouveauRole;
    }
    return true;
}

/**
 * Traite le formulaire de bascule de l'en-tête (action=basculer_role).
 * À appeler tout en haut des pages qui affichent ce bouton, AVANT tout
 * affichage : en cas de succès, on redirige (header) vers le tableau de
 * bord du nouveau rôle (pattern POST / Redirect / GET).
 */
function traiterDemandeBascule(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'basculer_role') {
        return;
    }
    exigerConnexion();

    // Jeton CSRF : un site tiers ne peut pas faire changer d'espace à la victime
    if (!verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        definirFlash('erreur', "Requête invalide. Merci de recharger la page.");
    } elseif (!basculerRole(is_string($_POST['role'] ?? null) ? $_POST['role'] : '')) {
        definirFlash('erreur', "Vous n'avez pas accès à cet espace.");
    }

    header('Location: ' . espaceActif());
    exit;
}

/*
 * =====================================================================
 *  MESSAGES « FLASH » (affichés une seule fois après une redirection)
 * =====================================================================
 */
function definirFlash(string $type, string $texte): void
{
    $_SESSION['flash'] = ['type' => $type, 'texte' => $texte];
}

/** Renvoie puis efface le message flash : ['type' => 'succes'|'erreur', 'texte' => ...] ou null. */
function lireFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}
