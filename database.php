<?php
/**
 * Connexion à la base de données Fair'repair
 * ------------------------------------------
 * On utilise PDO (PHP Data Objects) et non mysqli car PDO permet
 * les requêtes préparées de façon simple et reste compatible avec
 * plusieurs SGBD (MySQL, PostgreSQL...).
 */

// --- Paramètres de connexion (à adapter selon ton serveur) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'fairrepair');
define('DB_USER', 'root');
define('DB_PASS', 'mysql');     // AMPPS : 'mysql' | WAMP/XAMPP : '' (vide)
define('DB_CHARSET', 'utf8mb4');

// --- Fuseau horaire : UNE seule horloge pour PHP et MySQL ---
// Sous AMPPS, PHP était réglé sur UTC et MySQL sur l'heure de Paris :
// deux heures d'écart. Une date écrite par PHP (date()) puis comparée à
// NOW() dans MySQL tombait donc dans la mauvaise fenêtre (« 24 dernières
// heures », « 15 dernières minutes »…), et l'historique des connexions
// affichait des heures fausses. On fixe PHP sur l'heure de Paris, puis on
// aligne la connexion MySQL sur le même décalage (voir après la connexion).
date_default_timezone_set('Europe/Paris');

// --- Chaîne de connexion ---
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

// --- Options PDO ---
$options = [
    // Lève une exception en cas d'erreur SQL au lieu d'un simple warning
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

    // Les résultats sont renvoyés sous forme de tableau associatif
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

    // IMPORTANT : désactive l'émulation des requêtes préparées.
    // Les paramètres sont alors envoyés séparément de la requête au serveur MySQL,
    // ce qui rend l'injection SQL réellement impossible.
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // NOW() de MySQL suit désormais la même heure que date() en PHP.
    // date('P') donne le décalage actuel de Paris (+02:00 en été, +01:00 en
    // hiver) : cela fonctionne même si le serveur MySQL est réglé en UTC.
    $pdo->prepare('SET time_zone = :fuseau')->execute([':fuseau' => date('P')]);
} catch (PDOException $e) {
    // En production on n'affiche JAMAIS le message d'erreur réel :
    // il pourrait révéler le nom de la base, l'utilisateur, le chemin des fichiers...
    error_log('Erreur de connexion BDD : ' . $e->getMessage());
    die('Une erreur technique est survenue. Merci de réessayer plus tard.');
}