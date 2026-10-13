<?php
/**
 * Fonctions utilitaires de sécurité et de validation
 * --------------------------------------------------
 * Ce fichier est inclus par toutes les pages qui contiennent un formulaire.
 */

// Démarre la session si elle ne l'est pas déjà (nécessaire pour le token CSRF)
if (session_status() === PHP_SESSION_NONE) {

    // Mode strict : PHP refuse un identifiant de session qu'il n'a pas créé
    // lui-même (protection supplémentaire contre la fixation de session).
    ini_set('session.use_strict_mode', '1');

    // Options du cookie de session (PHPSESSID), à fixer AVANT session_start()
    session_set_cookie_params([
        'lifetime' => 0,         // cookie « de session » : supprimé à la fermeture du navigateur
        'path'     => '/',
        'httponly' => true,      // illisible depuis JavaScript : limite le vol par une faille XSS
        'samesite' => 'Lax',     // non envoyé par les requêtes POST venant d'un autre site
        'secure'   => false,     // TODO PRODUCTION : passer à true dès que le site est en HTTPS
    ]);

    session_start();
}

/**
 * Génère (une seule fois par session) un jeton CSRF et le renvoie.
 *
 * Principe : on place une valeur aléatoire en session ET dans un champ caché
 * du formulaire. Au moment du traitement, on vérifie que les deux
 * correspondent. Un site malveillant peut forcer un visiteur à envoyer
 * une requête vers notre site, mais il ne peut pas lire le contenu de
 * notre session : il ne connaîtra jamais le bon jeton.
 */
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        // random_bytes est cryptographiquement sûr (contrairement à rand())
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie le jeton reçu du formulaire.
 * hash_equals compare les chaînes en temps constant (protection timing attack).
 */
function verifierCsrfToken(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && !empty($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Échappe une chaîne avant son affichage dans du HTML.
 * Protège contre les failles XSS : si l'utilisateur saisit <script>...</script>,
 * les chevrons sont convertis en entités HTML et le code n'est pas exécuté.
 */
function e(?string $valeur): string
{
    return htmlspecialchars($valeur ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Nettoie une donnée reçue : supprime les espaces en trop
 * et les caractères invisibles de début/fin.
 */
function nettoyer(?string $valeur): string
{
    return trim($valeur ?? '');
}

/**
 * Retire les séparateurs d'un SIRET saisi : espaces (y compris insécables,
 * fréquents lors d'un copier-coller depuis un PDF), points et tirets.
 * Le modificateur /u est indispensable pour reconnaître les espaces insécables.
 */
function nettoyerSiret(string $saisie): string
{
    return preg_replace('/[\s.\-\x{00A0}\x{202F}]/u', '', $saisie) ?? '';
}

/**
 * Vérifie un numéro SIRET : 14 chiffres + clé de contrôle (algorithme de Luhn).
 *
 * Le dernier chiffre d'un SIRET est une « clé » calculée à partir des
 * 13 autres. L'algorithme de Luhn (le même que pour les cartes bancaires)
 * permet de la vérifier :
 *   1. on parcourt les chiffres de DROITE à GAUCHE ;
 *   2. on double un chiffre sur deux (le 2e, le 4e, le 6e… en partant de la droite) ;
 *   3. si un double dépasse 9, on lui retire 9 (ex. 7 × 2 = 14 → 5) ;
 *   4. on additionne tous les chiffres obtenus : la somme doit être un
 *      multiple de 10.
 * Une faute de frappe sur un chiffre, ou deux chiffres voisins inversés,
 * fausse presque toujours la somme : le numéro est alors refusé.
 * Ce contrôle ne prouve pas que l'entreprise EXISTE (seul l'Insee le
 * sait), il prouve que le numéro est bien formé.
 *
 * Exception connue : les établissements de La Poste (SIREN 356000000)
 * ne respectent pas Luhn ; leur somme simple doit être un multiple de 5.
 */
function siretValide(string $siret): bool
{
    if (!preg_match('/^[0-9]{14}$/', $siret)) {
        return false;
    }

    if (str_starts_with($siret, '356000000')) {
        return array_sum(str_split($siret)) % 5 === 0;
    }

    return luhnValide($siret);
}

/**
 * Algorithme de Luhn (clé de contrôle), partagé par le SIRET et les
 * numéros de carte bancaire (passerelle.php). Voir siretValide() pour
 * l'explication pas à pas. Accepte n'importe quelle longueur.
 */
function luhnValide(string $numero): bool
{
    if (!preg_match('/^[0-9]+$/', $numero)) {
        return false;
    }

    $somme    = 0;
    $longueur = strlen($numero);
    for ($i = 0; $i < $longueur; $i++) {
        $chiffre = (int) $numero[$longueur - 1 - $i];   // $i = 0 : chiffre le plus à droite
        if ($i % 2 === 1) {                             // un chiffre sur deux
            $chiffre *= 2;
            if ($chiffre > 9) {
                $chiffre -= 9;
            }
        }
        $somme += $chiffre;
    }
    return $somme % 10 === 0;
}

/**
 * Carte masquée à partir des 4 derniers chiffres : "•••• •••• •••• 4242".
 * On ne reçoit jamais le numéro complet : il n'est stocké nulle part.
 */
function masquerCarte(?string $quatreDerniers): string
{
    return '•••• •••• •••• ' . ($quatreDerniers ?: '????');
}

/**
 * E-mail partiellement masqué : "acheteur@paypal-test.fr" → "ac•••••@paypal-test.fr".
 * Toujours 5 points, quelle que soit la longueur réelle : on ne révèle
 * pas non plus le nombre de caractères cachés.
 */
function masquerEmail(?string $email): string
{
    $email = (string) $email;
    $arobase = strrpos($email, '@');
    if ($arobase === false) {
        return '•••••';
    }
    return mb_substr(substr($email, 0, $arobase), 0, 2) . '•••••' . substr($email, $arobase);
}

/**
 * Durée en minutes → texte lisible : 45 → "45 min", 60 → "1 h",
 * 90 → "1 h 30", 120 → "2 h", 65 → "1 h 05".
 * Utilisée par le catalogue, la fiche service, la réservation et la confirmation.
 */
function dureeLisible(int $minutes): string
{
    if ($minutes < 60) {
        return max(0, $minutes) . ' min';
    }
    $heures = intdiv($minutes, 60);
    $reste  = $minutes % 60;
    return $heures . ' h' . ($reste > 0 ? ' ' . str_pad((string) $reste, 2, '0', STR_PAD_LEFT) : '');
}

/**
 * Vérifie la robustesse du mot de passe.
 * Règle retenue : 8 caractères minimum, au moins une minuscule,
 * une majuscule et un chiffre.
 */
function motDePasseValide(string $mdp): bool
{
    return strlen($mdp) >= 8
        && preg_match('/[a-z]/', $mdp)
        && preg_match('/[A-Z]/', $mdp)
        && preg_match('/[0-9]/', $mdp);
}