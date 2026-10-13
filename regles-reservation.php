<?php
/**
 * Fair'repair — Règles métier d'une réservation
 * ---------------------------------------------
 * À appeler par la page qui CRÉE une réservation, juste avant l'INSERT :
 *
 *     $motifRefus = verifierReservationPossible($pdo, (int) $_SESSION['id_user'], $idService);
 *     if ($motifRefus !== null) { ... afficher $motifRefus, ne rien insérer ... }
 *
 * L'id du client vient TOUJOURS de la session ; l'id du service vient du
 * formulaire, il est donc revérifié ici en base.
 */

/**
 * Renvoie null si le client peut réserver ce service,
 * sinon un message expliquant pourquoi c'est impossible.
 */
function verifierReservationPossible(PDO $pdo, int $idClient, int $idService): ?string
{
    // Une seule requête : le service, son réparateur et son état
    $sql = "SELECT s.actif, s.id_reparateur, r.disponible, r.statut_validation
            FROM service s
            INNER JOIN reparateur r ON r.id_reparateur = s.id_reparateur
            WHERE s.id_service = :id_service";
    $req = $pdo->prepare($sql);
    $req->execute([':id_service' => $idService]);
    $service = $req->fetch();

    if (!$service || !$service['actif']) {
        return "Ce service n'existe pas ou n'est plus proposé.";
    }
    if ($service['statut_validation'] !== 'valide' || !$service['disponible']) {
        return "Ce réparateur n'accepte pas de réservation pour le moment.";
    }

    /*
     * GARDE « AUTO-RÉSERVATION »
     * Depuis qu'un même compte peut être client ET réparateur, rien
     * n'empêcherait techniquement un utilisateur de réserver SON PROPRE
     * service depuis son espace client. On l'interdit, car cela permettrait :
     *   - de se laisser de faux avis 5 étoiles et de gonfler sa note moyenne,
     *     qui sert à classer les réparateurs sur la carte ;
     *   - de gonfler artificiellement son nombre d'interventions et ses revenus ;
     *   - de faire transiter des paiements entre soi et soi.
     * Le client et le réparateur d'une réservation doivent donc être deux
     * personnes différentes : on compare l'id du réparateur du service à
     * l'id du client connecté (tous deux sont des utilisateur.id_user).
     */
    if ((int) $service['id_reparateur'] === $idClient) {
        return "Vous ne pouvez pas réserver l'un de vos propres services.";
    }

    return null;
}
