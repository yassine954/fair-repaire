<?php
/**
 * Fair'repair — Paiement d'une réservation (SIMULÉ)
 * -------------------------------------------------
 * payement.php?reservation=<id_reservation>
 *
 * Une seule page : récapitulatif, choix carte / PayPal, et traitement du
 * formulaire (envoyé à elle-même en POST). La décision « accepté / refusé »
 * est prise par passerelle.php, qui joue le rôle du prestataire externe.
 *
 * Organisation : zone 1 = logique (aucun affichage), zone 2 = affichage
 * (aucune requête SQL, aucun calcul métier).
 */

// ============================================================
//  1. LOGIQUE
// ============================================================

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/affichage.php';
require_once __DIR__ . '/passerelle.php';

// --- Contrôle d'accès : AVANT tout le reste ---
exigerRole('client');

$idClient = (int) $_SESSION['id_user'];   // toujours la session, jamais l'URL

// --- Règles de paiement ---
// (TAUX_TVA et tvaComprise() sont dans affichage.php, partagés avec recu.php)
const TAUX_COMMISSION     = 10.0;   // % prélevés par la plateforme
const MAX_ECHECS_PAIEMENT = 3;      // échecs tolérés…
const MINUTES_BLOCAGE     = 15;     // …dans cette fenêtre, puis blocage de 15 min

// Résultats d'échec qui comptent pour le blocage. 'erreur_technique' n'y est
// volontairement PAS : une panne de la banque n'est pas la faute du client et
// ne ressemble pas à une attaque. Ce qu'on veut freiner, c'est quelqu'un qui
// essaie des numéros de carte les uns après les autres (cartes volées).
const RESULTATS_ECHEC_BLOQUANTS = ['refuse', 'fonds_insuffisants', 'carte_expiree'];

/** Code renvoyé par la passerelle → valeur de l'ENUM tentative_paiement.resultat */
function resultatTentative(string $code): string
{
    return match ($code) {
        'approved'           => 'accepte',
        'insufficient_funds' => 'fonds_insuffisants',
        'expired_card'       => 'carte_expiree',
        'processing_error'   => 'erreur_technique',
        default              => 'refuse',   // card_declined, incorrect_number, paypal_declined…
    };
}

/**
 * Message affiché au client après un refus. Le CONSEIL dépend de la raison :
 * une carte refusée, des fonds insuffisants et une panne technique
 * n'appellent pas la même réaction.
 */
function conseilEchec(string $code, string $mode): array
{
    $paypal = $mode === 'paypal';
    return match ($code) {
        'card_declined'      => ['Carte refusée',
                                 "Votre banque a refusé ce paiement. Contactez-la pour en connaître la raison, ou essayez avec une autre carte."],
        'insufficient_funds' => ['Fonds insuffisants', $paypal
                                 ? "Le solde de votre compte PayPal ne suffit pas. Approvisionnez-le depuis le site de PayPal, ou payez par carte bancaire."
                                 : "Le solde ou le plafond de paiement de votre carte est insuffisant. Vous pouvez demander à votre banque de relever temporairement votre plafond, ou utiliser un autre moyen de paiement."],
        'expired_card'       => ['Carte expirée',
                                 "Cette carte n'est plus valable. Utilisez votre nouvelle carte, ou payez avec PayPal."],
        'incorrect_number'   => ['Numéro de carte incorrect',
                                 "Le numéro saisi n'existe pas. Vérifiez les chiffres inscrits sur le recto de votre carte."],
        'incorrect_cvc'      => ['Cryptogramme incorrect',
                                 "Le cryptogramme correspond aux 3 chiffres inscrits au dos de votre carte."],
        'processing_error'   => ['Incident technique',
                                 "Un incident technique a empêché le traitement. Aucun montant n'a été débité : réessayez dans quelques minutes."],
        'paypal_declined'    => ['Paiement refusé par PayPal',
                                 "PayPal n'a pas autorisé ce paiement. Vérifiez votre compte sur le site de PayPal, ou payez par carte bancaire."],
        'invalid_email'      => ['Compte PayPal introuvable',
                                 "Vérifiez l'adresse e-mail de votre compte PayPal."],
        default              => ['Paiement refusé',
                                 "Le paiement n'a pas abouti. Vérifiez vos informations ou choisissez un autre moyen de paiement."],
    };
}

/**
 * Charge la réservation À PAYER.
 *
 * PROTECTION IDOR : l'id de la réservation vient de l'URL, n'importe qui
 * peut le changer. Le filtre « AND r.id_client = :id_client » (id pris dans
 * la SESSION) garantit qu'un client ne voit et ne paie que SES réservations.
 * Celle d'un autre client donne « aucune ligne », comme un id inexistant.
 */
function chargerReservation(PDO $pdo, int $idReservation, int $idClient): array|false
{
    $sql = "SELECT r.id_reservation, r.date_intervention, r.adresse_intervention,
                   r.type_velo, r.marque_velo, r.statut,
                   s.titre, s.tarif,
                   rp.raison_sociale, u.prenom, u.nom,
                   EXISTS(SELECT 1 FROM payement p
                          WHERE p.id_reservation = r.id_reservation
                            AND p.statut = 'valide') AS deja_paye
            FROM reservation r
            INNER JOIN service     s  ON s.id_service     = r.id_service
            INNER JOIN reparateur  rp ON rp.id_reparateur = s.id_reparateur
            INNER JOIN utilisateur u  ON u.id_user        = rp.id_reparateur
            WHERE r.id_reservation = :id
              AND r.id_client      = :id_client";
    $req = $pdo->prepare($sql);
    $req->execute([':id' => $idReservation, ':id_client' => $idClient]);
    return $req->fetch();
}

/**
 * Détail du montant, calculé à partir du tarif lu EN BASE.
 * Le prix TTC est celui du service ; la TVA est « comprise dedans ».
 * La commission (10 %) est calculée sur le TTC ; le réparateur reçoit le reste.
 */
function calculerMontants(float $tarif): array
{
    $total      = round($tarif, 2);
    $commission = round($total * TAUX_COMMISSION / 100, 2);

    return [
        'total'      => $total,
        'tva'        => tvaComprise($total),
        'commission' => $commission,
        'reparateur' => round($total - $commission, 2),
        'taux'       => TAUX_COMMISSION,
    ];
}

/**
 * Blocage anti-fraude : si les 3 derniers échecs ont eu lieu en moins de
 * 15 minutes, le paiement est bloqué 15 minutes après le dernier.
 * Renvoie le nombre de minutes restantes, ou null si rien n'est bloqué.
 */
function minutesDeBlocage(PDO $pdo, int $idReservation): ?int
{
    $req = $pdo->prepare("SELECT date_tentative FROM tentative_paiement
                          WHERE id_reservation = :id
                            AND resultat IN ('refuse', 'fonds_insuffisants', 'carte_expiree')
                          ORDER BY date_tentative DESC
                          LIMIT " . MAX_ECHECS_PAIEMENT);
    $req->execute([':id' => $idReservation]);
    $dates = $req->fetchAll(PDO::FETCH_COLUMN);

    if (count($dates) < MAX_ECHECS_PAIEMENT) {
        return null;
    }
    $dernier = strtotime($dates[0]);
    $premier = strtotime($dates[count($dates) - 1]);
    $fin     = $dernier + MINUTES_BLOCAGE * 60;

    if ($dernier - $premier > MINUTES_BLOCAGE * 60 || $fin <= time()) {
        return null;
    }
    return (int) ceil(($fin - time()) / 60);
}

/** Nombre d'échecs bloquants dans les 15 dernières minutes. */
function echecsRecents(PDO $pdo, int $idReservation): int
{
    $req = $pdo->prepare("SELECT COUNT(*) FROM tentative_paiement
                          WHERE id_reservation = :id
                            AND resultat IN ('refuse', 'fonds_insuffisants', 'carte_expiree')
                            AND date_tentative >= :depuis");
    $req->execute([
        ':id'     => $idReservation,
        ':depuis' => date('Y-m-d H:i:s', time() - MINUTES_BLOCAGE * 60),
    ]);
    return (int) $req->fetchColumn();
}

/**
 * Journal des tentatives : TOUTES sont enregistrées, réussies ou non.
 *
 * Pourquoi les échecs vont ICI et pas dans payement : la table payement a
 * une contrainte UNIQUE sur id_reservation. Elle ne contient qu'UNE ligne
 * par réservation : le paiement réussi. Trois refus suivis d'une réussite
 * donneraient quatre lignes, ce que payement interdit. tentative_paiement
 * garde l'historique complet : c'est lui qui sert au blocage anti-fraude
 * et à l'analyse d'un litige (« j'ai essayé trois fois ! »).
 *
 * Rien de sensible n'y est écrit : 4 derniers chiffres seulement, jamais
 * le numéro complet ni le CVV.
 */
function enregistrerTentativePaiement(PDO $pdo, int $idReservation, string $mode, float $montant,
                                      ?string $quatreDerniers, ?string $comptePaypal,
                                      string $resultat, string $message): void
{
    $req = $pdo->prepare("INSERT INTO tentative_paiement
                              (id_reservation, mode, montant, carte_4_derniers, compte_paypal,
                               resultat, message, adresse_ip, date_tentative)
                          VALUES
                              (:id, :mode, :montant, :quatre, :paypal,
                               :resultat, :message, :ip, :date)");
    $req->execute([
        ':id'       => $idReservation,
        ':mode'     => $mode,
        ':montant'  => $montant,
        ':quatre'   => $quatreDerniers,
        ':paypal'   => $comptePaypal !== null ? mb_substr($comptePaypal, 0, 150) : null,
        ':resultat' => $resultat,
        ':message'  => mb_substr($message, 0, 255),
        ':ip'       => mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        ':date'     => date('Y-m-d H:i:s'),
    ]);
}

// ------------------------------------------------------------
//  Règles d'accès (vérifiées à CHAQUE requête, GET comme POST)
// ------------------------------------------------------------
// Sur un POST, la réservation est donc RELUE ici : si la page est restée
// ouverte des heures, un changement entre-temps (annulation, paiement fait
// depuis un autre onglet…) est pris en compte avant de débiter quoi que ce soit.
$idReservation = filter_var($_GET['reservation'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reservation   = $idReservation !== false ? chargerReservation($pdo, $idReservation, $idClient) : false;

if (!$reservation) {
    definirFlash('erreur', "Réservation introuvable.");
    header('Location: dashboard-client.php');
    exit;
}

// Déjà payée : on montre le reçu, on ne propose JAMAIS de payer une deuxième fois
if ($reservation['deja_paye']) {
    header('Location: recu.php?reservation=' . $idReservation);
    exit;
}

// Seule une réservation ACCEPTÉE par le réparateur peut être payée
if ($reservation['statut'] !== 'confirmee') {
    definirFlash('erreur', match ($reservation['statut']) {
        'en_attente' => "Cette réservation n'a pas encore été acceptée par le réparateur. Le paiement sera possible dès qu'il l'aura confirmée.",
        'annulee'    => "Cette réservation est annulée : elle ne peut pas être payée.",
        'terminee'   => "Cette intervention est terminée : elle ne peut plus être payée en ligne. Contactez-nous si besoin.",
        default      => "Cette réservation ne peut pas être payée.",
    });
    header('Location: dashboard-client.php');
    exit;
}

$minutesBlocage = minutesDeBlocage($pdo, $idReservation);

// MONTANT : toujours recalculé ici à partir de service.tarif (lu en base).
// Il n'existe AUCUN champ « montant » dans le formulaire, ni dans l'URL :
// un champ caché contenant le prix se modifie en deux clics avec les
// outils de développement du navigateur (F12), et l'on paierait 0,01 €.
$montants = calculerMontants((float) $reservation['tarif']);

$nomCle = 'cle_paiement_' . $idReservation;

// Valeurs réaffichées après une erreur. Le numéro de carte, la date
// d'expiration et le CVV n'y figurent PAS : ils ne sont jamais réaffichés.
$saisie = [
    'mode'         => 'cb',
    'titulaire'    => '',
    'email_paypal' => '',
];
$erreurs        = [];     // erreurs par champ
$erreurGenerale = null;   // message en haut de page
$refus          = null;   // ['titre', 'conseil'] après un refus de la passerelle

// ------------------------------------------------------------
//  Traitement du formulaire (ordre imposé)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $saisie['mode']         = in_array($_POST['mode'] ?? '', ['cb', 'paypal'], true) ? $_POST['mode'] : 'cb';
    $saisie['titulaire']    = mb_substr(nettoyer($_POST['titulaire'] ?? ''), 0, 100);
    $saisie['email_paypal'] = mb_substr(mb_strtolower(nettoyer($_POST['email_paypal'] ?? '')), 0, 150);

    // Données de carte : variables locales uniquement, jamais en session,
    // jamais en base, jamais dans un journal.
    $numeroCarte = preg_replace('/\s+/', '', (string) ($_POST['numero_carte'] ?? '')) ?? '';
    $expiration  = nettoyer($_POST['expiration'] ?? '');
    $cvv         = nettoyer($_POST['cvv'] ?? '');

    // --- 1. Jeton CSRF ---
    if (!verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $erreurGenerale = "Votre session a expiré. Aucun paiement n'a été effectué : merci de recommencer.";

    // --- 2. Règles d'accès ---
    // Réservation, propriétaire, statut et paiement existant viennent d'être
    // revérifiés plus haut pour CETTE requête. Reste le blocage anti-fraude.
    } elseif ($minutesBlocage !== null) {
        $erreurGenerale = "Le paiement de cette réservation est temporairement bloqué.";

    } else {
        // --- 3. Clé d'idempotence ---
        // Une clé = UN envoi. Elle est retirée de la session dès maintenant :
        // un double clic, un F5 sur la page de résultat ou un formulaire
        // renvoyé avec « Précédent » réutilisent une clé déjà consommée et
        // sont refusés AVANT d'appeler la passerelle. Le client ne peut donc
        // jamais être débité deux fois. La vérification en base couvre le
        // cas où la clé aurait déjà servi à un paiement enregistré.
        $cleRecue   = (string) ($_POST['cle_idempotence'] ?? '');
        $cleSession = (string) ($_SESSION[$nomCle] ?? '');
        unset($_SESSION[$nomCle]);

        $req = $pdo->prepare("SELECT COUNT(*) FROM payement WHERE cle_idempotence = :cle");
        $req->execute([':cle' => $cleRecue]);
        $cleDejaUtilisee = (int) $req->fetchColumn() > 0;

        if ($cleSession === '' || !hash_equals($cleSession, $cleRecue) || $cleDejaUtilisee) {
            $erreurGenerale = "Ce formulaire de paiement a déjà été envoyé. Par sécurité, il n'a pas été traité une seconde fois "
                            . "et aucun nouveau débit n'a eu lieu. Vérifiez l'état de la réservation avant de réessayer.";
        } else {
            // --- 4. Montant relu en base ---
            // $reservation vient d'être rechargée pour cette requête :
            // on recalcule le détail à partir de son tarif.
            $montants = calculerMontants((float) $reservation['tarif']);

            // --- 5. Validation des champs (côté serveur) ---
            // Le mode doit être EXACTEMENT 'cb' ou 'paypal' (liste blanche).
            if (!in_array($_POST['mode'] ?? '', ['cb', 'paypal'], true)) {
                $erreurs['mode'] = "Choisissez un moyen de paiement : carte bancaire ou PayPal.";

            } elseif ($saisie['mode'] === 'cb') {
                if (!preg_match('/^[0-9]{13,19}$/', $numeroCarte)) {
                    $erreurs['numero_carte'] = "Le numéro de carte doit comporter entre 13 et 19 chiffres.";
                }
                if (!preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', $expiration, $m)) {
                    $erreurs['expiration'] = "Indiquez la date d'expiration au format MM/AA (ex. 08/28).";
                } elseif ((int) ('20' . $m[2]) * 100 + (int) $m[1] < (int) date('Ym')) {
                    $erreurs['expiration'] = "Cette date d'expiration est dépassée.";
                }
                if (!preg_match('/^[0-9]{3}$/', $cvv)) {
                    $erreurs['cvv'] = "Le cryptogramme comporte 3 chiffres (au dos de la carte).";
                }
                if ($saisie['titulaire'] === '') {
                    $erreurs['titulaire'] = "Indiquez le nom inscrit sur la carte.";
                }

            } elseif (filter_var($saisie['email_paypal'], FILTER_VALIDATE_EMAIL) === false) {
                $erreurs['email_paypal'] = "Indiquez l'adresse e-mail de votre compte PayPal.";
            }

            if (!$erreurs) {
                // --- 6. Appel de la passerelle ---
                $verdict = null;
                try {
                    $verdict = $saisie['mode'] === 'cb'
                        ? passerellePayerCarte($numeroCarte, $expiration, $cvv, $montants['total'])
                        : passerellePayerPaypal($saisie['email_paypal'], $montants['total']);
                } catch (Throwable $ex) {
                    // On journalise le message de l'exception, JAMAIS les données de carte
                    error_log('Passerelle de paiement, réservation ' . $idReservation . ' : ' . $ex->getMessage());
                }

                // Le numéro et le CVV ne servent plus : on les efface de la mémoire
                unset($numeroCarte, $cvv);

                $quatreDerniers = $verdict['quatre_derniers'] ?? null;
                $comptePaypal   = $saisie['mode'] === 'paypal' ? $saisie['email_paypal'] : null;

                // --- 7. Tentative TOUJOURS enregistrée, réussie ou non ---
                try {
                    enregistrerTentativePaiement(
                        $pdo, $idReservation, $saisie['mode'], $montants['total'],
                        $quatreDerniers, $comptePaypal,
                        $verdict ? resultatTentative($verdict['code']) : 'erreur_technique',
                        $verdict ? $verdict['code'] . ' : ' . $verdict['message'] : 'Exception lors de l\'appel à la passerelle'
                    );
                } catch (PDOException $ex) {
                    error_log('Journal tentative_paiement : ' . $ex->getMessage());
                }

                if ($verdict === null) {
                    // --- 10. Exception : message neutre ---
                    $erreurGenerale = "Une erreur technique empêche le paiement pour le moment. Aucun montant n'a été débité. Merci de réessayer dans quelques minutes.";

                } elseif ($verdict['succes']) {
                    // --- 8. Succès : enregistrement dans une TRANSACTION ---
                    try {
                        $pdo->beginTransaction();

                        // Dernière vérification, ligne verrouillée jusqu'au COMMIT :
                        // la réservation est toujours confirmée et pas encore payée.
                        $req = $pdo->prepare("SELECT statut FROM reservation
                                              WHERE id_reservation = :id AND id_client = :id_client
                                              FOR UPDATE");
                        $req->execute([':id' => $idReservation, ':id_client' => $idClient]);
                        if ($req->fetchColumn() !== 'confirmee') {
                            throw new RuntimeException('Réservation plus confirmée au moment de l\'enregistrement');
                        }

                        $req = $pdo->prepare(
                            "INSERT INTO payement
                                (montant, commission_plateforme, montant_reparateur, taux_commission,
                                 date_payement, mode_payement, carte_4_derniers, reseau_carte, compte_paypal,
                                 statut, reference_transaction, cle_idempotence, id_reservation)
                             VALUES
                                (:montant, :commission, :part_reparateur, :taux,
                                 :date, :mode, :quatre, :reseau, :paypal,
                                 'valide', :reference, :cle, :id_reservation)"
                        );
                        $req->execute([
                            ':montant'         => $montants['total'],
                            ':commission'      => $montants['commission'],
                            ':part_reparateur' => $montants['reparateur'],
                            ':taux'            => $montants['taux'],
                            ':date'            => date('Y-m-d H:i:s'),
                            ':mode'            => $saisie['mode'],
                            ':quatre'          => $saisie['mode'] === 'cb' ? $quatreDerniers : null,
                            ':reseau'          => $saisie['mode'] === 'cb' ? $verdict['reseau'] : null,
                            ':paypal'          => $comptePaypal,
                            ':reference'       => $verdict['reference'],
                            ':cle'             => $cleRecue,
                            ':id_reservation'  => $idReservation,
                        ]);

                        // La réservation RESTE 'confirmee'. « Payée » et « terminée »
                        // sont deux choses différentes : le client paie à l'avance,
                        // mais la réservation ne passe à 'terminee' qu'une fois la
                        // réparation réellement effectuée par le réparateur.
                        // L'état « payée » se lit dans payement (statut 'valide').

                        $pdo->commit();

                        // POST / REDIRECT / GET : F5 sur le reçu ne renvoie rien
                        header('Location: recu.php?reservation=' . $idReservation);
                        exit;

                    } catch (Throwable $ex) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        // Cas rare : la passerelle a accepté mais l'enregistrement a
                        // échoué. Avec un vrai prestataire, il faudrait rembourser :
                        // on garde la référence pour pouvoir le faire.
                        error_log('Paiement accepté (réf. ' . $verdict['reference'] . ') mais non enregistré, réservation '
                                  . $idReservation . ' : ' . $ex->getMessage());
                        try {
                            enregistrerTentativePaiement(
                                $pdo, $idReservation, $saisie['mode'], $montants['total'],
                                $quatreDerniers, $comptePaypal, 'erreur_technique',
                                'Accepté (réf. ' . $verdict['reference'] . ') mais non enregistré : remboursement à prévoir'
                            );
                        } catch (PDOException $ex2) {
                            error_log('Journal tentative_paiement : ' . $ex2->getMessage());
                        }
                        $erreurGenerale = "Une erreur technique est survenue pendant l'enregistrement du paiement. "
                                        . "Si un montant a été débité, il vous sera remboursé. Contactez-nous en indiquant la réservation n° "
                                        . $idReservation . ".";
                    }

                } else {
                    // --- 9. Refus : aucune ligne dans payement, conseil adapté ---
                    [$titre, $conseil] = conseilEchec($verdict['code'], $saisie['mode']);
                    $refus = ['titre' => $titre, 'conseil' => $conseil];
                    $minutesBlocage = minutesDeBlocage($pdo, $idReservation);
                }
            }
        }
    }
}

// ------------------------------------------------------------
//  Préparation de l'affichage
// ------------------------------------------------------------
// Nouvelle clé d'idempotence à chaque affichage du formulaire
// (bin2hex(random_bytes(32)) = 64 caractères hexadécimaux imprévisibles).
if ($minutesBlocage === null) {
    $cleIdempotence    = bin2hex(random_bytes(32));
    $_SESSION[$nomCle] = $cleIdempotence;
} else {
    $cleIdempotence = '';
}

$essaisRestants = $minutesBlocage === null
    ? max(0, MAX_ECHECS_PAIEMENT - echecsRecents($pdo, $idReservation))
    : 0;

$csrfToken     = getCsrfToken();
$nomReparateur = $reservation['raison_sociale'] ?: $reservation['prenom'] . ' ' . $reservation['nom'];
$libelleVelo   = libelleVelo($reservation['type_velo'])
               . ($reservation['marque_velo'] ? ' — ' . $reservation['marque_velo'] : '');
$dateIntervention = ucfirst(jourFr($reservation['date_intervention'])) . ' à ' . heureFr($reservation['date_intervention']);
$montantAffiche   = euros($montants['total']);

// Encadré d'aide : construit à partir des constantes de passerelle.php,
// pour que l'aide et le comportement réel ne puissent pas diverger.
$cartesTest = [];
foreach (CARTES_TEST as $numero => [$code, $explication]) {
    $cartesTest[] = ['numero' => implode(' ', str_split($numero, 4)), 'resultat' => $explication, 'succes' => $code === 'approved'];
}
$cartesTest[] = ['numero' => '1234 5678 9012 3456', 'resultat' => 'Numéro invalide (contrôle de Luhn)', 'succes' => false];
$paypalTest = [];
foreach (PAYPAL_TEST as $email => [$code, $explication]) {
    $paypalTest[] = ['email' => $email, 'resultat' => $explication, 'succes' => $code === 'approved'];
}
?>
<!DOCTYPE html>
<!-- ============================================================
     2. AFFICHAGE
     ============================================================ -->
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paiement — Fair'repair</title>
    <style>
    :root {
        --vert:         #0F766E;
        --vert-fonce:   #0B5A54;
        --vert-clair:   #E6F4F2;
        --bleu:         #1E50D4;
        --bleu-clair:   #E8EEFC;
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
        /* Simulation PayPal : bleu et jaune « dans l'esprit », sans logo */
        --pp-bleu:      #123A7A;
        --pp-jaune:     #FFC439;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        background-color: var(--fond); color: var(--texte); line-height: 1.6;
        display: flex; flex-direction: column; min-height: 100vh;
    }
    .entete {
        background-color: var(--blanc); border-bottom: 1px solid var(--bordure);
        padding: 14px 32px; display: flex; justify-content: space-between;
        align-items: center; flex-wrap: wrap; gap: 12px;
    }
    .logo { font-size: 1.4rem; font-weight: 700; color: var(--vert); text-decoration: none; }
    .logo span { color: var(--texte); }
    .entete nav { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
    .entete nav a { color: var(--texte-doux); text-decoration: none; font-weight: 500; font-size: 0.92rem; }
    .entete nav a:hover { color: var(--vert); }

    .conteneur { flex: 1; width: 100%; max-width: 1080px; margin: 0 auto; padding: 28px 20px 48px; }
    .fil { font-size: 0.85rem; margin-bottom: 14px; }
    .fil a { color: var(--vert); text-decoration: none; }
    h1 { font-size: 1.6rem; margin-bottom: 18px; }
    h2 { font-size: 1.05rem; margin-bottom: 12px; }

    .bloc {
        background-color: var(--blanc); border: 1px solid var(--bordure);
        border-radius: var(--rayon); padding: 22px 24px; margin-bottom: 20px;
    }
    .bloc-entete { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
    .bloc-entete h2 { margin-bottom: 0; }
    .alerte { padding: 14px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 0.92rem; }
    .alerte-erreur { background-color: var(--rouge-clair); border-left: 4px solid var(--rouge); color: var(--rouge); }
    .alerte-ambre  { background-color: var(--ambre-clair); border-left: 4px solid var(--ambre); color: var(--ambre); }
    .alerte strong { display: block; }
    .alerte .conseil { color: var(--texte); margin-top: 4px; }

    .mise-en-page { display: grid; grid-template-columns: 1fr 320px; gap: 22px; align-items: start; }
    .recap { position: sticky; top: 16px; border-top: 3px solid var(--vert); }

    /* ---------- Récapitulatif ---------- */
    .recap dl div { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; font-size: 0.88rem; }
    .recap dt { color: var(--texte-doux); }
    .recap dd { text-align: right; font-weight: 600; overflow-wrap: anywhere; }
    .montants { border-top: 1px solid var(--bordure); margin-top: 10px; padding-top: 10px; }
    .montants .total { font-size: 1.05rem; }
    .montants .total dd { font-size: 1.5rem; color: var(--vert-fonce); }
    .montants .detail { font-size: 0.82rem; padding: 3px 0; }
    .montants .detail dd { font-weight: 400; }

    /* ---------- Choix du moyen de paiement (radios en cartes) ---------- */
    .choix-mode { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .option-mode {
        display: flex; gap: 12px; align-items: center; cursor: pointer;
        border: 2px solid var(--bordure); border-radius: var(--rayon); padding: 16px;
        transition: border-color 0.15s, background-color 0.15s;
    }
    .option-mode:hover { border-color: var(--vert); }
    .option-mode:has(input:checked) { border-color: var(--vert); background-color: var(--vert-clair); }
    .option-mode input { accent-color: var(--vert); width: 18px; height: 18px; flex-shrink: 0; }
    .option-mode .icone { font-size: 1.6rem; }
    .option-mode strong { display: block; }
    .option-mode small { color: var(--texte-doux); }

    /* ---------- Champs ---------- */
    .champ { margin-bottom: 14px; display: flex; flex-direction: column; }
    .ligne { display: flex; gap: 14px; }
    .ligne .champ { flex: 1; min-width: 0; }
    label { font-size: 0.86rem; font-weight: 600; margin-bottom: 5px; }
    input[type="text"], input[type="email"], input[type="password"] {
        padding: 11px 12px; border: 1px solid var(--bordure); border-radius: 6px;
        font-size: 1rem; font-family: inherit; background-color: var(--blanc); width: 100%;
    }
    input:focus { outline: none; border-color: var(--vert); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15); }
    .invalide { border-color: var(--rouge) !important; background-color: var(--rouge-clair) !important; }
    .message-erreur { color: var(--rouge); font-size: 0.82rem; margin-top: 4px; }
    .aide { color: var(--texte-doux); font-size: 0.8rem; margin-top: 4px; }
    .chiffres { font-family: Consolas, "Courier New", monospace; letter-spacing: 1px; }

    /* ---------- Panneau carte : interface bancaire sobre ---------- */
    .panneau { border: 1px solid var(--bordure); border-radius: var(--rayon); overflow: hidden; margin-top: 16px; }
    .panneau-corps { padding: 20px; }
    .bandeau-securise {
        display: flex; align-items: center; gap: 8px;
        background-color: #F1F4F8; border-bottom: 1px solid var(--bordure);
        padding: 10px 20px; font-size: 0.85rem; font-weight: 600; color: var(--texte);
    }
    .bandeau-securise .droite { margin-left: auto; font-weight: 400; color: var(--texte-doux); font-size: 0.78rem; }
    .numero-carte { position: relative; }
    .numero-carte input { padding-right: 140px; }
    .reseau {
        position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
        font-size: 0.75rem; font-weight: 700; padding: 3px 8px; border-radius: 4px;
        background-color: var(--fond); color: var(--texte-doux); border: 1px solid var(--bordure);
    }
    .reseau:empty { display: none; }

    .bouton-principal {
        width: 100%; padding: 14px; margin-top: 6px;
        background-color: var(--vert); color: var(--blanc);
        border: none; border-radius: 6px; font-size: 1.05rem; font-weight: 600;
        font-family: inherit; cursor: pointer;
    }
    .bouton-principal:hover { background-color: var(--vert-fonce); }
    .bouton-principal:disabled { opacity: 0.6; cursor: wait; }
    .bouton-petit {
        display: inline-block; padding: 6px 13px; border-radius: 5px; font-size: 0.83rem;
        font-weight: 600; text-decoration: none; border: 1px solid var(--bordure);
        background-color: var(--blanc); color: var(--texte);
    }
    .bouton-petit.primaire { background-color: var(--vert); border-color: var(--vert); color: var(--blanc); }

    /* ---------- Panneau « Simulation PayPal » ---------- */
    .panneau-paypal { border-color: #C9D6EA; }
    .bandeau-paypal {
        background-color: var(--pp-bleu); color: var(--blanc);
        padding: 12px 20px; font-weight: 700; display: flex; align-items: center; gap: 10px;
    }
    .bandeau-paypal .pastille {
        background-color: var(--pp-jaune); color: var(--pp-bleu);
        font-size: 0.72rem; padding: 2px 8px; border-radius: 20px; text-transform: uppercase;
    }
    .bouton-paypal {
        width: 100%; padding: 14px; margin-top: 6px;
        background-color: var(--pp-jaune); color: var(--pp-bleu);
        border: none; border-radius: 30px; font-size: 1.05rem; font-weight: 700;
        font-family: inherit; cursor: pointer;
    }
    .bouton-paypal:hover { filter: brightness(0.95); }
    .bouton-paypal:disabled { opacity: 0.6; cursor: wait; }
    .note-simulation {
        background-color: var(--ambre-clair); color: var(--ambre); border-radius: 6px;
        padding: 8px 12px; font-size: 0.84rem; font-weight: 600; margin-bottom: 14px;
    }

    /* ---------- Aide « données de test » ---------- */
    .aide-test { border-left: 4px solid var(--bleu); }
    .aide-test p { font-size: 0.86rem; color: var(--texte-doux); margin-bottom: 10px; }
    .aide-test h3 { font-size: 0.9rem; margin: 14px 0 6px; }
    .aide-test table { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
    .aide-test td { padding: 6px 8px; border-bottom: 1px solid var(--bordure); }
    .aide-test tr:last-child td { border-bottom: none; }
    .etiquette { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 0.76rem; font-weight: 600; white-space: nowrap; }
    .etiquette.ok  { background-color: var(--succes-clair); color: var(--succes); }
    .etiquette.ko  { background-color: var(--rouge-clair);  color: var(--rouge); }

    /* ---------- Écran « Paiement en cours » ---------- */
    .ecran-traitement {
        position: fixed; inset: 0; z-index: 100;
        background-color: rgba(245, 248, 252, 0.94);
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 18px; text-align: center; padding: 20px;
    }
    .ecran-traitement[hidden] { display: none; }
    .roue {
        width: 54px; height: 54px; border-radius: 50%;
        border: 5px solid var(--bordure); border-top-color: var(--vert);
        animation: tourner 0.9s linear infinite;
    }
    @keyframes tourner { to { transform: rotate(360deg); } }
    .ecran-traitement p { font-weight: 600; font-size: 1.05rem; }
    .ecran-traitement small { color: var(--texte-doux); }

    .pied {
        background-color: var(--blanc); border-top: 1px solid var(--bordure);
        text-align: center; padding: 18px; font-size: 0.82rem; color: var(--texte-doux);
    }

    @media (max-width: 860px) {
        .mise-en-page { grid-template-columns: 1fr; }
        .recap { position: static; order: -1; }
    }
    @media (max-width: 600px) {
        .entete { padding: 12px 16px; }
        .bloc { padding: 18px 16px; }
        .choix-mode { grid-template-columns: 1fr; }
        .ligne { flex-direction: column; gap: 0; }
        .panneau-corps { padding: 16px; }
        .numero-carte input { padding-right: 12px; }
        .reseau { position: static; transform: none; align-self: flex-start; margin-top: 6px; }
    }
    @media (prefers-reduced-motion: reduce) { .roue { animation-duration: 3s; } }
    </style>
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav>
        <a href="services.php">Les services</a>
        <a href="dashboard-client.php">Mon espace</a>
    </nav>
</header>

<main class="conteneur">
    <p class="fil"><a href="dashboard-client.php">← Mes réservations</a></p>
    <h1>Paiement de votre réservation</h1>

    <?php if ($erreurGenerale): ?>
        <div class="alerte alerte-erreur" role="alert"><?= e($erreurGenerale) ?></div>
    <?php endif; ?>

    <?php if ($refus): ?>
        <div class="alerte alerte-erreur" role="alert">
            <strong>Paiement non effectué : <?= e($refus['titre']) ?></strong>
            <p class="conseil"><?= e($refus['conseil']) ?></p>
            <?php if ($minutesBlocage === null && $essaisRestants > 0): ?>
                <p class="conseil">
                    Il vous reste <?= $essaisRestants ?> essai<?= $essaisRestants > 1 ? 's' : '' ?>
                    avant un blocage temporaire de <?= MINUTES_BLOCAGE ?> minutes.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="mise-en-page">
        <div>
        <?php if ($minutesBlocage !== null): ?>

            <section class="bloc">
                <div class="alerte alerte-ambre" role="alert">
                    <strong>Paiement temporairement bloqué</strong>
                    <p class="conseil">
                        Trois tentatives de paiement ont échoué en moins de <?= MINUTES_BLOCAGE ?> minutes.
                        Par sécurité, le paiement de cette réservation est suspendu pendant encore
                        <strong style="display:inline"><?= $minutesBlocage ?> minute<?= $minutesBlocage > 1 ? 's' : '' ?></strong>.
                    </p>
                    <p class="conseil">
                        Cette mesure protège contre l'utilisation de cartes volées, essayées les unes après les autres.
                        Profitez-en pour vérifier vos informations auprès de votre banque, puis revenez sur cette page.
                    </p>
                </div>
                <a href="dashboard-client.php" class="bouton-petit primaire">Retour à mes réservations</a>
            </section>

        <?php else: ?>

            <!-- novalidate : toutes les règles sont revérifiées par le serveur -->
            <form method="post" action="payement.php?reservation=<?= (int) $reservation['id_reservation'] ?>"
                  id="form-paiement" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <!-- Clé d'idempotence : un envoi = un paiement au maximum -->
                <input type="hidden" name="cle_idempotence" value="<?= e($cleIdempotence) ?>">
                <!-- Volontairement AUCUN champ « montant » : il est recalculé en base -->

                <section class="bloc">
                    <h2>Moyen de paiement</h2>
                    <div class="choix-mode">
                        <label class="option-mode">
                            <input type="radio" name="mode" value="cb" <?= $saisie['mode'] === 'cb' ? 'checked' : '' ?>>
                            <span class="icone" aria-hidden="true">💳</span>
                            <span><strong>Carte bancaire</strong><small>Visa, Mastercard, American Express</small></span>
                        </label>
                        <label class="option-mode">
                            <input type="radio" name="mode" value="paypal" <?= $saisie['mode'] === 'paypal' ? 'checked' : '' ?>>
                            <span class="icone" aria-hidden="true">🅿️</span>
                            <span><strong>PayPal</strong><small>Simulation, avec votre e-mail</small></span>
                        </label>
                    </div>
                    <?php if (isset($erreurs['mode'])): ?>
                        <p class="message-erreur"><?= e($erreurs['mode']) ?></p>
                    <?php endif; ?>
                    <noscript>
                        <p class="aide">Choisissez d'abord le moyen de paiement ci-dessus, puis remplissez le formulaire correspondant.</p>
                    </noscript>

                    <!-- ===== Carte bancaire ===== -->
                    <div class="panneau panneau-carte" id="panneau-cb">
                        <div class="bandeau-securise">
                            🔒 Paiement sécurisé
                            <span class="droite">Connexion chiffrée</span>
                        </div>
                        <div class="panneau-corps">
                            <div class="champ">
                                <label for="numero_carte">Numéro de carte</label>
                                <div class="numero-carte">
                                    <!-- value toujours vide : le numéro n'est jamais réaffiché -->
                                    <input type="text" id="numero_carte" name="numero_carte" value=""
                                           inputmode="numeric" autocomplete="cc-number" maxlength="23"
                                           placeholder="1234 5678 9012 3456"
                                           class="chiffres <?= isset($erreurs['numero_carte']) ? 'invalide' : '' ?>">
                                    <span class="reseau" id="reseau-carte" aria-live="polite"></span>
                                </div>
                                <?php if (isset($erreurs['numero_carte'])): ?>
                                    <p class="message-erreur"><?= e($erreurs['numero_carte']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="ligne">
                                <div class="champ">
                                    <label for="expiration">Expiration (MM/AA)</label>
                                    <input type="text" id="expiration" name="expiration" value=""
                                           inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="MM/AA"
                                           class="chiffres <?= isset($erreurs['expiration']) ? 'invalide' : '' ?>">
                                    <?php if (isset($erreurs['expiration'])): ?>
                                        <p class="message-erreur"><?= e($erreurs['expiration']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="champ">
                                    <label for="cvv">Cryptogramme</label>
                                    <input type="password" id="cvv" name="cvv" value=""
                                           inputmode="numeric" autocomplete="cc-csc" maxlength="3" placeholder="•••"
                                           class="chiffres <?= isset($erreurs['cvv']) ? 'invalide' : '' ?>">
                                    <?php if (isset($erreurs['cvv'])): ?>
                                        <p class="message-erreur"><?= e($erreurs['cvv']) ?></p>
                                    <?php else: ?>
                                        <p class="aide">3 chiffres au dos de la carte</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="champ">
                                <label for="titulaire">Nom du titulaire</label>
                                <input type="text" id="titulaire" name="titulaire" maxlength="100"
                                       autocomplete="cc-name" placeholder="Tel qu'inscrit sur la carte"
                                       value="<?= e($saisie['titulaire']) ?>"
                                       class="<?= isset($erreurs['titulaire']) ? 'invalide' : '' ?>">
                                <?php if (isset($erreurs['titulaire'])): ?>
                                    <p class="message-erreur"><?= e($erreurs['titulaire']) ?></p>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="bouton-principal">🔒 Payer <?= e($montantAffiche) ?></button>
                            <p class="aide">
                                Fair'repair ne conserve jamais le numéro complet de votre carte ni son cryptogramme :
                                seuls les 4 derniers chiffres figurent sur votre reçu.
                            </p>
                        </div>
                    </div>

                    <!-- ===== Simulation PayPal =====
                         AUCUN champ mot de passe. Dans une vraie intégration, le client
                         est redirigé vers le site de PayPal, s'y connecte lui-même, puis
                         revient avec une autorisation de paiement. Un site marchand ne
                         doit JAMAIS recueillir le mot de passe PayPal : il pourrait
                         vider le compte, et un site qui le demande ressemble à de
                         l'hameçonnage (phishing). -->
                    <div class="panneau panneau-paypal" id="panneau-paypal">
                        <div class="bandeau-paypal">
                            Simulation PayPal <span class="pastille">Test</span>
                        </div>
                        <div class="panneau-corps">
                            <p class="note-simulation">Simulation : aucun mot de passe n'est demandé ni enregistré.</p>
                            <div class="champ">
                                <label for="email_paypal">E-mail du compte PayPal</label>
                                <input type="email" id="email_paypal" name="email_paypal" maxlength="150"
                                       autocomplete="email" placeholder="acheteur@paypal-test.fr"
                                       value="<?= e($saisie['email_paypal']) ?>"
                                       class="<?= isset($erreurs['email_paypal']) ? 'invalide' : '' ?>">
                                <?php if (isset($erreurs['email_paypal'])): ?>
                                    <p class="message-erreur"><?= e($erreurs['email_paypal']) ?></p>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="bouton-paypal">Payer avec PayPal <?= e($montantAffiche) ?></button>
                        </div>
                    </div>
                </section>
            </form>

        <?php endif; ?>

            <!-- Aide pour tester le projet (données issues de passerelle.php) -->
            <section class="bloc aide-test">
                <h2>🧪 Données de test</h2>
                <p>Ce paiement est une simulation : aucun argent réel n'est débité. Utilisez ces données pour obtenir chaque résultat.
                   Pour une carte, n'importe quelle date d'expiration future et n'importe quel cryptogramme à 3 chiffres conviennent.</p>

                <h3>Cartes bancaires</h3>
                <table>
                    <tbody>
                    <?php foreach ($cartesTest as $carte): ?>
                        <tr>
                            <td class="chiffres"><?= e($carte['numero']) ?></td>
                            <td><span class="etiquette <?= $carte['succes'] ? 'ok' : 'ko' ?>"><?= e($carte['resultat']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr>
                            <td colspan="2" class="aide">Tout autre numéro valide (contrôle de Luhn) est accepté.</td>
                        </tr>
                    </tbody>
                </table>

                <h3>Comptes PayPal</h3>
                <table>
                    <tbody>
                    <?php foreach ($paypalTest as $compte): ?>
                        <tr>
                            <td><?= e($compte['email']) ?></td>
                            <td><span class="etiquette <?= $compte['succes'] ? 'ok' : 'ko' ?>"><?= e($compte['resultat']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr>
                            <td colspan="2" class="aide">Toute autre adresse e-mail valide est acceptée.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <!-- Récapitulatif : montants calculés par le serveur à partir de la base -->
        <aside class="bloc recap" aria-label="Récapitulatif">
            <h2><?= e($reservation['titre']) ?></h2>
            <dl>
                <div><dt>Réparateur</dt><dd><?= e($nomReparateur) ?></dd></div>
                <div><dt>Intervention</dt><dd><?= e($dateIntervention) ?></dd></div>
                <div><dt>Adresse</dt><dd><?= e($reservation['adresse_intervention']) ?></dd></div>
                <div><dt>Vélo</dt><dd><?= e($libelleVelo) ?></dd></div>
            </dl>
            <dl class="montants">
                <div class="total"><dt>Total TTC</dt><dd><?= euros($montants['total']) ?></dd></div>
                <div class="detail"><dt>dont TVA (<?= (int) TAUX_TVA ?> %)</dt><dd><?= euros($montants['tva']) ?></dd></div>
                <div class="detail"><dt>Commission plateforme (<?= (int) TAUX_COMMISSION ?> %)</dt><dd><?= euros($montants['commission']) ?></dd></div>
                <div class="detail"><dt>Montant reversé au réparateur</dt><dd><?= euros($montants['reparateur']) ?></dd></div>
            </dl>
        </aside>
    </div>
</main>

<!-- Écran affiché pendant que le serveur interroge la passerelle -->
<div class="ecran-traitement" id="ecran-traitement" hidden role="status" aria-live="assertive">
    <div class="roue" aria-hidden="true"></div>
    <p>Paiement en cours, ne fermez pas cette fenêtre…</p>
    <small>Nous attendons la réponse de votre banque.</small>
</div>

<footer class="pied">Fair'repair — paiement simulé dans le cadre d'un projet pédagogique</footer>

<script>
// JavaScript de confort uniquement : sans lui, le formulaire fonctionne et
// le serveur applique exactement les mêmes contrôles.
(function () {
    var form = document.getElementById('form-paiement');
    if (!form) { return; }

    // 1) Choix du moyen de paiement : seul le panneau choisi est visible.
    //    Les champs du panneau masqué sont aussi DÉSACTIVÉS : un champ
    //    désactivé n'est pas envoyé, le numéro de carte ne part donc pas
    //    avec un paiement PayPal.
    var panneaux = { cb: document.getElementById('panneau-cb'), paypal: document.getElementById('panneau-paypal') };
    function majPanneaux() {
        var choix = form.querySelector('input[name="mode"]:checked');
        var mode = choix ? choix.value : 'cb';
        Object.keys(panneaux).forEach(function (cle) {
            var actif = cle === mode;
            panneaux[cle].hidden = !actif;
            // Les boutons restent actifs : la touche Entrée « clique » le premier
            // bouton du formulaire, même masqué ; le mode envoyé reste celui du bouton radio.
            panneaux[cle].querySelectorAll('input').forEach(function (el) { el.disabled = !actif; });
        });
    }
    form.querySelectorAll('input[name="mode"]').forEach(function (r) { r.addEventListener('change', majPanneaux); });
    majPanneaux();

    // 2) Numéro de carte : chiffres seulement, groupés par 4, réseau détecté
    var numero = document.getElementById('numero_carte');
    var reseau = document.getElementById('reseau-carte');
    function detecterReseau(n) {
        if (/^4/.test(n)) { return 'Visa'; }
        if (/^3[47]/.test(n)) { return 'American Express'; }
        if (/^5[1-5]/.test(n)) { return 'Mastercard'; }
        var debut = parseInt(n.substring(0, 4), 10);
        if (n.length >= 4 && debut >= 2221 && debut <= 2720) { return 'Mastercard'; }
        return n.length >= 2 ? 'Réseau inconnu' : '';
    }
    numero.addEventListener('input', function () {
        var chiffres = numero.value.replace(/\D/g, '').substring(0, 19);
        numero.value = chiffres.replace(/(\d{4})(?=\d)/g, '$1 ');
        reseau.textContent = detecterReseau(chiffres);
    });

    // 3) Expiration : masque MM/AA (la barre est ajoutée automatiquement)
    var expiration = document.getElementById('expiration');
    expiration.addEventListener('input', function (ev) {
        var chiffres = expiration.value.replace(/\D/g, '').substring(0, 4);
        if (chiffres.length === 1 && chiffres > '1') { chiffres = '0' + chiffres; }   // « 8 » → « 08 »
        var effacement = ev.inputType && ev.inputType.indexOf('delete') === 0;
        expiration.value = chiffres.length > 2 || (chiffres.length === 2 && !effacement)
            ? chiffres.substring(0, 2) + '/' + chiffres.substring(2)
            : chiffres;
    });

    // 4) Cryptogramme : chiffres seulement
    var cvv = document.getElementById('cvv');
    cvv.addEventListener('input', function () { cvv.value = cvv.value.replace(/\D/g, '').substring(0, 3); });

    // 5) Écran « Paiement en cours » : boutons désactivés pour éviter le
    //    double clic. Ce n'est qu'un confort : la vraie protection contre
    //    un double débit est la clé d'idempotence vérifiée par le serveur.
    var ecran = document.getElementById('ecran-traitement');
    form.addEventListener('submit', function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
        ecran.hidden = false;
    });

    // Retour arrière vers une page gardée en cache : on rend la main
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { ecran.hidden = true; majPanneaux(); }
    });
})();
</script>
</body>
</html>
