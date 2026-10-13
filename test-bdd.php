<?php
// FICHIER DE TEST UNIQUEMENT — à supprimer une fois le problème réglé
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=fairrepair;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "<h2 style='color:green'>Connexion réussie</h2>";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Tables trouvées : " . count($tables) . "</p><ul>";
    foreach ($tables as $t) { echo "<li>$t</li>"; }
    echo "</ul>";

} catch (PDOException $e) {
    echo "<h2 style='color:red'>Échec</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}