<?php
/**
 * Fair'repair — Formulaire de réservation
 * ---------------------------------------
 * reserver.php?service=<id_service>
 *
 * Page protégée : seul un utilisateur connecté, dans son espace CLIENT,
 * peut réserver. exigerRole() est appelé avant tout affichage : un visiteur
 * non connecté est envoyé vers connexion.php et l'adresse de cette page est
 * gardée en session pour l'y ramener ensuite.
 *
 * RÈGLE D'OR DE CETTE PAGE : le formulaire n'envoie QUE ce que le client
 * choisit (date, vélo, panne, adresse…). Le prix, la durée, le réparateur
 * sont TOUJOURS relus dans la base. Un champ caché contenant le prix se
 * modifie en deux clics avec les outils de développement du navigateur
 * (F12 → clic droit → « Modifier ») : s'y fier permettrait de réserver une
 * réparation à 0,01 €.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/affichage.php';

// --- Middleware : uniquement l'espace client (avant tout affichage) ---
exigerRole('client');

$idClient = (int) $_SESSION['id_user'];   // toujours la session, jamais le formulaire

// ============================================================
//  RÈGLES DE RÉSERVATION
// ============================================================
const HEURE_OUVERTURE      = 8 * 60;    // 08:00, en minutes depuis minuit
const HEURE_FERMETURE      = 19 * 60;   // 19:00 : l'intervention doit être FINIE à cette heure
const PAS_CRENEAU          = 30;        // créneaux toutes les 30 minutes
const DELAI_MINIMUM_HEURES = 24;        // au moins 24 h à l'avance
const HORIZON_JOURS        = 60;        // au plus 60 jours à l'avance
const MAX_EN_ATTENTE       = 5;         // anti-spam : demandes en attente simultanées
const DUREE_PAR_DEFAUT     = 60;        // si le réparateur n'a pas indiqué de durée

/**
 * Erreur métier levée pendant la transaction. $champ indique où afficher
 * le message dans le formulaire ('general' = en haut de la page).
 */
class ErreurReservation extends RuntimeException
{
    public string $champ;

    public function __construct(string $message, string $champ = 'general')
    {
        parent::__construct($message);
        $this->champ = $champ;
    }
}

/**
 * Charge un service RÉSERVABLE : actif, réparateur validé ET disponible.
 * $verrouiller = true ajoute FOR UPDATE (à utiliser dans une transaction) :
 * la ligne du service est verrouillée jusqu'au COMMIT. Deux clients qui
 * réservent le même service au même instant passent donc l'un APRÈS
 * l'autre : le second voit la réservation du premier lors du test de
 * chevauchement, au lieu de passer tous les deux le test « en même temps ».
 */
function chargerServiceReservable(PDO $pdo, int $idService, bool $verrouiller = false): array|false
{
    $sql = "SELECT s.id_service, s.titre, s.tarif,
                   COALESCE(s.duree_estimee, " . DUREE_PAR_DEFAUT . ") AS duree,
                   s.id_reparateur,
                   rp.raison_sociale, u.prenom, u.nom,
                   COALESCE(rp.ville_atelier, u.ville) AS ville
            FROM service s
            INNER JOIN reparateur  rp ON rp.id_reparateur = s.id_reparateur
            INNER JOIN utilisateur u  ON u.id_user        = rp.id_reparateur
            WHERE s.id_service         = :id
              AND s.actif              = 1
              AND rp.statut_validation = 'valide'
              AND rp.disponible        = 1"
         . ($verrouiller ? ' FOR UPDATE' : '');
    $req = $pdo->prepare($sql);
    $req->execute([':id' => $idService]);
    return $req->fetch();
}

/** Dernière heure de début possible (en minutes) pour que l'intervention finisse avant 19:00. */
function dernierDebut(int $duree): int
{
    $dernier = HEURE_FERMETURE - $duree;
    return $dernier - ($dernier % PAS_CRENEAU);   // arrondi au créneau inférieur
}

/** Heures proposées dans la liste déroulante : ["08:00", "08:30", …] */
function heuresProposees(int $duree): array
{
    $heures = [];
    for ($m = HEURE_OUVERTURE; $m <= dernierDebut($duree); $m += PAS_CRENEAU) {
        $heures[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    return $heures;
}

/**
 * Vérifie qu'un début d'intervention respecte TOUTES les règles de date.
 * Renvoie le message d'erreur, ou null si le créneau est valable.
 */
function erreurCreneau(DateTimeImmutable $debut, int $duree, DateTimeImmutable $maintenant): ?string
{
    $minutesDuJour = (int) $debut->format('G') * 60 + (int) $debut->format('i');

    if ($debut->format('N') === '7') {
        return "Les réparateurs n'interviennent pas le dimanche. Choisissez un jour du lundi au samedi.";
    }
    if ($minutesDuJour % PAS_CRENEAU !== 0 || $debut->format('s') !== '00') {
        return "Choisissez une heure ronde ou une demi-heure (ex. 9 h 00 ou 9 h 30).";
    }
    if ($minutesDuJour < HEURE_OUVERTURE || $minutesDuJour > dernierDebut($duree)) {
        return "Les interventions ont lieu entre 8 h et 19 h. Pour ce service ("
             . dureeLisible($duree) . "), le dernier départ possible est à "
             . sprintf('%d h %02d', intdiv(dernierDebut($duree), 60), dernierDebut($duree) % 60) . ".";
    }
    if ($debut < $maintenant->modify('+' . DELAI_MINIMUM_HEURES . ' hours')) {
        return "La réservation doit être faite au moins 24 heures à l'avance.";
    }
    if ($debut > $maintenant->modify('+' . HORIZON_JOURS . ' days')) {
        return "Vous ne pouvez pas réserver plus de " . HORIZON_JOURS . " jours à l'avance.";
    }
    return null;
}

/**
 * TEST DE CHEVAUCHEMENT DE CRÉNEAUX
 * ---------------------------------
 * Une réservation occupe l'intervalle [début, début + durée[.
 * Deux intervalles [A1, A2[ et [B1, B2[ se chevauchent SI ET SEULEMENT SI
 *         A1 < B2   ET   B1 < A2
 * Cette seule condition couvre tous les cas : début ou fin qui empiète,
 * créneau inclus dans l'autre, créneaux identiques.
 *
 * Comparer seulement les heures de début (date_intervention = :debut)
 * serait faux : pour un service de 1 h 30 réservé à 10 h 00, une demande
 * à 10 h 30 passerait alors que le réparateur est encore occupé.
 * Deux créneaux qui se touchent (l'un finit à 11 h, l'autre commence à 11 h)
 * ne se chevauchent pas : c'est voulu, grâce aux inégalités strictes.
 *
 * Les réservations annulées ne bloquent pas le créneau.
 */
function creneauOccupe(PDO $pdo, int $idService, DateTimeImmutable $debut, int $duree): bool
{
    $sql = "SELECT COUNT(*)
            FROM reservation r
            INNER JOIN service s ON s.id_service = r.id_service
            WHERE r.id_service = :id_service
              AND r.statut    <> 'annulee'
              AND r.date_intervention < :fin_demandee
              AND DATE_ADD(r.date_intervention,
                           INTERVAL COALESCE(s.duree_estimee, " . DUREE_PAR_DEFAUT . ") MINUTE) > :debut_demande";
    $req = $pdo->prepare($sql);
    $req->execute([
        ':id_service'    => $idService,
        ':fin_demandee'  => $debut->modify("+$duree minutes")->format('Y-m-d H:i:s'),
        ':debut_demande' => $debut->format('Y-m-d H:i:s'),
    ]);
    return (int) $req->fetchColumn() > 0;
}

/**
 * Réservation « immédiate » : premier créneau à la fois VALIDE (règles de
 * date) et LIBRE (aucun chevauchement), à partir de maintenant + 24 h.
 * Renvoie null si aucun créneau n'est libre dans les 60 prochains jours.
 */
function prochainCreneauLibre(PDO $pdo, int $idService, int $duree, DateTimeImmutable $maintenant): ?DateTimeImmutable
{
    // Point de départ : maintenant + 24 h, arrondi à la demi-heure SUPÉRIEURE
    $depart  = $maintenant->modify('+' . DELAI_MINIMUM_HEURES . ' hours');
    $minutes = (int) $depart->format('G') * 60 + (int) $depart->format('i');
    if ($minutes % PAS_CRENEAU !== 0 || $depart->format('s') !== '00') {
        $minutes += PAS_CRENEAU - ($minutes % PAS_CRENEAU);
    }
    $candidat = $depart->setTime(0, 0)->modify("+$minutes minutes");
    $limite   = $maintenant->modify('+' . HORIZON_JOURS . ' days');

    while ($candidat <= $limite) {
        // Les règles de date sont testées d'abord (sans requête SQL) ;
        // la base n'est interrogée que pour les créneaux autorisés.
        if (erreurCreneau($candidat, $duree, $maintenant) === null
            && !creneauOccupe($pdo, $idService, $candidat, $duree)) {
            return $candidat;
        }
        $candidat = $candidat->modify('+' . PAS_CRENEAU . ' minutes');
    }
    return null;
}

/** Message d'erreur sous un champ (déjà échappé). */
function erreurChamp(array $erreurs, string $champ): string
{
    return isset($erreurs[$champ])
        ? '<p class="message-erreur" id="err-' . e($champ) . '">' . e($erreurs[$champ]) . '</p>'
        : '';
}

// ============================================================
//  CHARGEMENT DU SERVICE (à CHAQUE requête, GET comme POST)
// ============================================================
// Le récapitulatif affiché est reconstruit ici depuis la base. Aucune
// information du service ne transite par le formulaire.
$idService = filter_var($_GET['service'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$service   = $idService !== false ? chargerServiceReservable($pdo, $idService) : false;

if (!$service) {
    http_response_code(404);
}

// Garde « auto-réservation » (voir le contrôle n° 2 plus bas)
$estSonService = $service && (int) $service['id_reparateur'] === $idClient;

// --- Adresse enregistrée du client (pré-remplissage) ---
$req = $pdo->prepare("SELECT adresse, code_postal, ville FROM utilisateur WHERE id_user = :id");
$req->execute([':id' => $idClient]);
$profil = $req->fetch();
$adresseEnregistree = trim(($profil['adresse'] ?? '') . ', ' . trim(($profil['code_postal'] ?? '') . ' ' . ($profil['ville'] ?? '')), ', ');
$aAdresse = !empty($profil['adresse']) && !empty($profil['ville']);

// --- Valeurs du formulaire (vides au premier affichage) ---
$saisie = [
    'type_reservation' => 'planifiee',
    'date'             => '',
    'heure'            => '',
    'type_velo'        => 'classique',
    'marque_velo'      => '',
    'description'      => '',
    'autre_adresse'    => !$aAdresse,   // pas d'adresse enregistrée : saisie obligatoire
    'adresse'          => '',
    'code_postal'      => '',
    'ville'            => 'Paris',
    'message_client'   => '',
    'accepte'          => false,
];
$erreurs = [];

// ============================================================
//  TRAITEMENT DU FORMULAIRE
// ============================================================
if ($service && !$estSonService && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $erreurs['general'] = "Votre session a expiré. Merci de vérifier le formulaire et de l'envoyer à nouveau.";
    }

    // --- Lecture : on garde tout ce qui a été tapé pour le réafficher ---
    $saisie['type_reservation'] = ($_POST['type_reservation'] ?? '') === 'immediate' ? 'immediate' : 'planifiee';
    $saisie['date']             = nettoyer($_POST['date'] ?? '');
    $saisie['heure']            = nettoyer($_POST['heure'] ?? '');
    $saisie['type_velo']        = nettoyer($_POST['type_velo'] ?? '');
    $saisie['marque_velo']      = nettoyer($_POST['marque_velo'] ?? '');
    // Les retours à la ligne Windows (\r\n) comptent pour 2 caractères en PHP
    // mais pour 1 dans le navigateur : on les uniformise avant de compter.
    $saisie['description']      = str_replace("\r\n", "\n", nettoyer($_POST['description'] ?? ''));
    $saisie['autre_adresse']    = !$aAdresse || isset($_POST['autre_adresse']);
    $saisie['adresse']          = nettoyer($_POST['adresse'] ?? '');
    $saisie['code_postal']      = nettoyer($_POST['code_postal'] ?? '');
    $saisie['ville']            = nettoyer($_POST['ville'] ?? '');
    $saisie['message_client']   = str_replace("\r\n", "\n", nettoyer($_POST['message_client'] ?? ''));
    $saisie['accepte']          = isset($_POST['accepte']);

    // --- Date et heure (seulement pour une réservation planifiée) ---
    // La durée vient de $service (base de données), pas du formulaire.
    $debutDemande = null;
    if ($saisie['type_reservation'] === 'planifiee') {
        if ($saisie['date'] === '' || $saisie['heure'] === '') {
            $erreurs['date'] = "Choisissez une date et une heure d'intervention.";
        } else {
            // Le « ! » remet à zéro les champs non fournis (secondes comprises)
            $debutDemande = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $saisie['date'] . ' ' . $saisie['heure']);
            // On vérifie que la date relue donne exactement la saisie :
            // « 2026-02-31 » serait sinon converti silencieusement en 3 mars.
            if (!$debutDemande || $debutDemande->format('Y-m-d H:i') !== $saisie['date'] . ' ' . $saisie['heure']) {
                $erreurs['date'] = "La date ou l'heure n'est pas valide.";
                $debutDemande    = null;
            } elseif ($message = erreurCreneau($debutDemande, (int) $service['duree'], new DateTimeImmutable())) {
                $erreurs['date'] = $message;
            }
        }
    }

    // --- Type de vélo : la valeur doit faire partie de la liste ---
    if (!array_key_exists($saisie['type_velo'], TYPES_VELO)) {
        $erreurs['type_velo'] = "Choisissez un type de vélo dans la liste.";
    }

    if (mb_strlen($saisie['marque_velo']) > 50) {
        $erreurs['marque_velo'] = "La marque ne doit pas dépasser 50 caractères.";
    }

    $longueurPanne = mb_strlen($saisie['description']);
    if ($longueurPanne < 10) {
        $erreurs['description'] = "Décrivez la panne en au moins 10 caractères : cela aide le réparateur à prévoir les bonnes pièces.";
    } elseif ($longueurPanne > 1000) {
        $erreurs['description'] = "La description ne doit pas dépasser 1000 caractères (actuellement $longueurPanne).";
    }

    // --- Adresse d'intervention ---
    if ($saisie['autre_adresse']) {
        if ($saisie['adresse'] === '' || mb_strlen($saisie['adresse']) > 150) {
            $erreurs['adresse'] = "Indiquez le numéro et la rue (150 caractères maximum).";
        }
        if (!preg_match('/^[0-9]{5}$/', $saisie['code_postal'])) {
            $erreurs['code_postal'] = "Le code postal doit comporter 5 chiffres.";
        }
        if ($saisie['ville'] === '' || mb_strlen($saisie['ville']) > 80) {
            $erreurs['ville'] = "Indiquez la ville.";
        }
        $adresseIntervention = $saisie['adresse'] . ', ' . $saisie['code_postal'] . ' ' . $saisie['ville'];
    } else {
        // Adresse du profil, relue en base (pas depuis un champ caché)
        $adresseIntervention = $adresseEnregistree;
    }

    if (mb_strlen($saisie['message_client']) > 500) {
        $erreurs['message_client'] = "Le message ne doit pas dépasser 500 caractères.";
    }

    if (!$saisie['accepte']) {
        $erreurs['accepte'] = "Vous devez accepter le tarif et les conditions d'annulation.";
    }

    // ========================================================
    //  CONTRÔLES MÉTIER + INSERT, DANS UNE TRANSACTION
    // ========================================================
    if (!$erreurs) {
        try {
            $pdo->beginTransaction();

            // Heure relue À CET INSTANT, et non au chargement de la page
            $maintenant = new DateTimeImmutable();

            // --- Contrôle 1 : le service est toujours réservable ---
            // Relu ET verrouillé (FOR UPDATE) : il a pu être désactivé, ou le
            // réparateur passé « indisponible », pendant que le client remplissait
            // le formulaire. On repart de CETTE ligne pour le prix et la durée.
            $serviceBase = chargerServiceReservable($pdo, (int) $service['id_service'], true);
            if (!$serviceBase) {
                throw new ErreurReservation("Ce service n'est plus disponible à la réservation. Il a peut-être été retiré par le réparateur.");
            }
            $duree = (int) $serviceBase['duree'];

            // --- Contrôle 2 : interdiction de réserver son propre service ---
            // Un même compte peut être client ET réparateur. Sans ce contrôle,
            // un réparateur pourrait se réserver lui-même : fausses réservations,
            // puis faux avis « 5 étoiles » sur ses propres interventions pour
            // gonfler sa note. On compare l'id de la SESSION à l'id du
            // réparateur relu en BASE : aucun des deux ne vient du formulaire.
            if ((int) $serviceBase['id_reparateur'] === $idClient) {
                throw new ErreurReservation("Vous ne pouvez pas réserver l'un de vos propres services.");
            }

            // --- Contrôle 5 : pas plus de 5 demandes en attente ---
            // Limite anti-spam : empêche d'inonder les réparateurs de demandes
            // (un robot, ou un client qui réserve « au cas où » partout).
            $req = $pdo->prepare("SELECT COUNT(*) FROM reservation
                                  WHERE id_client = :id_client AND statut = 'en_attente'");
            $req->execute([':id_client' => $idClient]);
            if ((int) $req->fetchColumn() >= MAX_EN_ATTENTE) {
                throw new ErreurReservation("Vous avez déjà " . MAX_EN_ATTENTE . " demandes en attente de réponse. "
                    . "Attendez qu'un réparateur réponde, ou annulez une demande, avant d'en envoyer une nouvelle.");
            }

            // --- Réservation immédiate : le SERVEUR choisit le créneau ---
            if ($saisie['type_reservation'] === 'immediate') {
                $debutDemande = prochainCreneauLibre($pdo, (int) $serviceBase['id_service'], $duree, $maintenant);
                if ($debutDemande === null) {
                    throw new ErreurReservation("Aucun créneau n'est libre pour ce service dans les "
                        . HORIZON_JOURS . " prochains jours.", 'type_reservation');
                }
            }

            // --- Contrôle 6 : la date est TOUJOURS valable au moment de l'INSERT ---
            // La page a pu rester ouverte des heures : un créneau à « demain
            // 10 h » choisi hier soir peut ne plus respecter le délai de 24 h.
            // On revérifie toutes les règles avec l'heure actuelle.
            if ($message = erreurCreneau($debutDemande, $duree, $maintenant)) {
                throw new ErreurReservation($message, 'date');
            }

            // --- Contrôle 4 : double envoi ---
            // Testé AVANT le chevauchement pour afficher un message clair :
            // un double clic sur « Envoyer », ou un retour arrière suivi d'un
            // nouvel envoi, ne doit pas créer deux demandes le même jour.
            $jour = $debutDemande->setTime(0, 0);
            $req = $pdo->prepare("SELECT COUNT(*) FROM reservation
                                  WHERE id_client  = :id_client
                                    AND id_service = :id_service
                                    AND statut IN ('en_attente', 'confirmee')
                                    AND date_intervention >= :jour_debut
                                    AND date_intervention <  :jour_fin");
            $req->execute([
                ':id_client'  => $idClient,
                ':id_service' => $serviceBase['id_service'],
                ':jour_debut' => $jour->format('Y-m-d H:i:s'),
                ':jour_fin'   => $jour->modify('+1 day')->format('Y-m-d H:i:s'),
            ]);
            if ((int) $req->fetchColumn() > 0) {
                throw new ErreurReservation("Vous avez déjà une réservation pour ce service le "
                    . jourFr($debutDemande->format('Y-m-d')) . ". Consultez « Mes réservations ».", 'date');
            }

            // --- Contrôle 3 : le créneau est libre (test de chevauchement) ---
            // Voir creneauOccupe() : test d'intervalles, pas d'égalité des débuts.
            if (creneauOccupe($pdo, (int) $serviceBase['id_service'], $debutDemande, $duree)) {
                throw new ErreurReservation("Ce créneau est déjà pris pour ce service. "
                    . "Choisissez une autre heure, ou l'option « Dès que possible ».", 'date');
            }

            // --- INSERT ---
            // Seules des valeurs contrôlées ci-dessus sont écrites. Le prix
            // n'est même pas stocké dans la réservation : il reste dans la
            // table service, qui fait foi. L'id du service est celui relu en base.
            $req = $pdo->prepare(
                "INSERT INTO reservation
                    (date_demande, date_intervention, adresse_intervention, type_reservation,
                     type_velo, marque_velo, description_panne, message_client,
                     statut, id_client, id_service)
                 VALUES
                    (:date_demande, :date_intervention, :adresse, :type_reservation,
                     :type_velo, :marque, :description, :message,
                     'en_attente', :id_client, :id_service)"
            );
            $req->execute([
                ':date_demande'      => $maintenant->format('Y-m-d H:i:s'),
                ':date_intervention' => $debutDemande->format('Y-m-d H:i:s'),
                ':adresse'           => mb_substr($adresseIntervention, 0, 255),
                ':type_reservation'  => $saisie['type_reservation'],
                ':type_velo'         => $saisie['type_velo'],
                ':marque'            => $saisie['marque_velo'] !== '' ? $saisie['marque_velo'] : null,
                ':description'       => $saisie['description'],
                ':message'           => $saisie['message_client'] !== '' ? $saisie['message_client'] : null,
                ':id_client'         => $idClient,
                ':id_service'        => $serviceBase['id_service'],
            ]);
            $idReservation = (int) $pdo->lastInsertId();

            $pdo->commit();

            // --- POST / REDIRECT / GET ---
            // Après un POST réussi, on REDIRIGE au lieu d'afficher directement
            // la confirmation. Sans cela, la page affichée serait le résultat
            // du POST : un appui sur F5 (ou « Précédent » puis « Suivant »)
            // renverrait le formulaire et créerait une SECONDE réservation.
            // Après la redirection, F5 recharge simplement confirmation.php (GET).
            header('Location: confirmation.php?reservation=' . $idReservation);
            exit;

        } catch (ErreurReservation $ex) {
            // Erreur métier : on annule la transaction (aucune écriture) et on
            // réaffiche le formulaire avec la saisie du client.
            $pdo->rollBack();
            $erreurs[$ex->champ] = $ex->getMessage();

        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Erreur réservation : ' . $ex->getMessage());
            $erreurs['general'] = "Une erreur technique empêche l'enregistrement de votre demande. Merci de réessayer dans quelques instants.";
        }
    }
}

$csrfToken = getCsrfToken();

// Bornes du champ date (aide à la saisie ; le serveur revérifie tout)
$maintenantAffichage = new DateTimeImmutable();
$dateMin = $maintenantAffichage->modify('+' . DELAI_MINIMUM_HEURES . ' hours')->format('Y-m-d');
$dateMax = $maintenantAffichage->modify('+' . HORIZON_JOURS . ' days')->format('Y-m-d');
$heures  = $service ? heuresProposees((int) $service['duree']) : [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réserver<?= $service ? ' — ' . e($service['titre']) : '' ?> — Fair'repair</title>
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
        --rayon:        10px;
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
        border-radius: var(--rayon); padding: 24px 26px; margin-bottom: 22px;
    }
    .bloc-entete { margin-bottom: 12px; }
    .alerte { padding: 14px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 0.92rem; }
    .alerte-erreur { background-color: var(--rouge-clair); border-left: 4px solid var(--rouge); color: var(--rouge); }
    .alerte-info   { background-color: var(--bleu-clair);  border-left: 4px solid var(--bleu);  color: var(--bleu); }
    .alerte a { color: inherit; font-weight: 600; }

    /* Récapitulatif à droite, épinglé pendant le défilement sur ordinateur */
    .mise-en-page { display: grid; grid-template-columns: 1fr 300px; gap: 22px; align-items: start; }
    .recap { position: sticky; top: 16px; border-top: 3px solid var(--vert); }
    .recap dl div { display: flex; justify-content: space-between; gap: 10px; padding: 7px 0; border-bottom: 1px solid var(--bordure); font-size: 0.9rem; }
    .recap dl div:last-child { border-bottom: none; }
    .recap dt { color: var(--texte-doux); }
    .recap dd { font-weight: 600; text-align: right; }
    .recap .prix { font-size: 1.6rem; font-weight: 700; color: var(--vert-fonce); margin-top: 10px; }
    .recap .note { font-size: 0.8rem; color: var(--texte-doux); }

    /* ---------- Formulaire ---------- */
    fieldset { border: none; border-top: 1px solid var(--bordure); padding-top: 18px; margin-bottom: 18px; }
    fieldset:first-of-type { border-top: none; padding-top: 0; }
    legend {
        font-weight: 600; font-size: 0.85rem; text-transform: uppercase;
        letter-spacing: 0.5px; color: var(--vert); padding-right: 10px;
    }
    .champ { margin-bottom: 16px; display: flex; flex-direction: column; }
    .ligne { display: flex; gap: 16px; }
    .ligne .champ { flex: 1; min-width: 0; }
    label { font-size: 0.88rem; font-weight: 600; margin-bottom: 5px; }
    .obligatoire { color: var(--rouge); }
    input[type="text"], input[type="date"], select, textarea {
        padding: 10px 12px; border: 1px solid var(--bordure); border-radius: 6px;
        font-size: 0.95rem; font-family: inherit; background-color: var(--blanc); width: 100%;
    }
    textarea { resize: vertical; min-height: 110px; }
    input:focus, select:focus, textarea:focus {
        outline: none; border-color: var(--vert); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15);
    }
    input:disabled, select:disabled { background-color: var(--fond); color: var(--texte-doux); cursor: not-allowed; }
    .invalide { border-color: var(--rouge) !important; background-color: var(--rouge-clair) !important; }
    .message-erreur { color: var(--rouge); font-size: 0.82rem; margin-top: 4px; }
    .aide { color: var(--texte-doux); font-size: 0.8rem; margin-top: 4px; }
    .compteur { text-align: right; }
    .compteur.trop { color: var(--rouge); font-weight: 600; }

    /* Choix « planifiée / immédiate » sous forme de deux cartes */
    .choix-type { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
    .option-type {
        display: flex; gap: 10px; align-items: flex-start; margin: 0;
        border: 1px solid var(--bordure); border-radius: 8px; padding: 12px 14px; cursor: pointer;
        font-weight: 400;
    }
    .option-type:has(input:checked) { border-color: var(--vert); background-color: var(--vert-clair); }
    .option-type input { margin-top: 5px; accent-color: var(--vert); }
    .option-type strong { display: block; font-size: 0.92rem; }
    .option-type span { font-size: 0.82rem; color: var(--texte-doux); }

    .case { display: flex; gap: 10px; align-items: flex-start; font-weight: 400; font-size: 0.9rem; margin-bottom: 0; }
    .case input { margin-top: 5px; accent-color: var(--vert); width: 16px; height: 16px; flex-shrink: 0; }
    .adresse-actuelle { background-color: var(--fond); border-radius: 6px; padding: 10px 12px; margin-bottom: 10px; font-size: 0.92rem; }
    .autre-adresse { margin-top: 14px; }

    .bouton-principal {
        width: 100%; padding: 14px; background-color: var(--vert); color: var(--blanc);
        border: none; border-radius: 6px; font-size: 1.02rem; font-weight: 600;
        font-family: inherit; cursor: pointer;
    }
    .bouton-principal:hover { background-color: var(--vert-fonce); }
    .bouton-petit {
        display: inline-block; padding: 6px 13px; border-radius: 5px; font-size: 0.83rem;
        font-weight: 600; text-decoration: none; border: 1px solid var(--bordure);
        background-color: var(--blanc); color: var(--texte);
    }
    .bouton-petit.primaire { background-color: var(--vert); border-color: var(--vert); color: var(--blanc); }

    .vide-etat { text-align: center; padding: 40px 20px; color: var(--texte-doux); }
    .vide-etat .icone { font-size: 2.4rem; }
    .vide-etat p { margin-bottom: 14px; }

    .pied {
        background-color: var(--blanc); border-top: 1px solid var(--bordure);
        text-align: center; padding: 18px; font-size: 0.82rem; color: var(--texte-doux);
    }

    @media (max-width: 860px) {
        .mise-en-page { grid-template-columns: 1fr; }
        .recap { position: static; order: -1; }   /* récapitulatif en haut sur mobile */
    }
    @media (max-width: 600px) {
        .entete { padding: 12px 16px; }
        .bloc { padding: 18px 16px; }
        .ligne, .choix-type { flex-direction: column; grid-template-columns: 1fr; gap: 0; }
        .choix-type { gap: 10px; }
    }
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
<?php if (!$service): ?>

    <div class="bloc vide-etat">
        <div class="icone">🔍</div>
        <h1>Service introuvable</h1>
        <p>Ce service n'existe pas ou n'est plus proposé à la réservation.</p>
        <a href="services.php" class="bouton-petit primaire">Parcourir les services</a>
    </div>

<?php else: ?>

    <p class="fil"><a href="service.php?id=<?= (int) $service['id_service'] ?>">← Retour au service</a></p>
    <h1>Réserver une intervention</h1>

    <div class="mise-en-page">

        <div>
            <?php if ($estSonService): ?>
                <div class="alerte alerte-info">
                    C'est l'un de vos propres services : vous ne pouvez pas le réserver.
                    <a href="services.php">Voir les autres services</a>
                </div>
            <?php else: ?>

            <?php if ($erreurs): ?>
                <div class="alerte alerte-erreur" role="alert">
                    <?= isset($erreurs['general']) ? e($erreurs['general']) : 'Votre demande n\'a pas été envoyée. Merci de corriger les champs signalés ci-dessous.' ?>
                </div>
            <?php endif; ?>

            <!-- novalidate : les règles sont vérifiées par le SERVEUR, qui affiche
                 ses propres messages (les contrôles HTML restent une simple aide). -->
            <form method="post" action="reserver.php?service=<?= (int) $service['id_service'] ?>" class="bloc" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <!-- Volontairement AUCUN champ caché pour le prix, la durée ou le
                     réparateur : ils sont relus en base au moment de l'enregistrement. -->

                <!-- ===== 1. Quand ? ===== -->
                <fieldset>
                    <legend>Quand ?</legend>

                    <div class="choix-type">
                        <label class="option-type">
                            <input type="radio" name="type_reservation" value="planifiee"
                                   <?= $saisie['type_reservation'] === 'planifiee' ? 'checked' : '' ?>>
                            <span><strong>Je choisis un créneau</strong>
                            <span>Date et heure de mon choix</span></span>
                        </label>
                        <label class="option-type">
                            <input type="radio" name="type_reservation" value="immediate"
                                   <?= $saisie['type_reservation'] === 'immediate' ? 'checked' : '' ?>>
                            <span><strong>Dès que possible</strong>
                            <span>Le premier créneau libre (au moins 24 h à l'avance)</span></span>
                        </label>
                    </div>
                    <?= erreurChamp($erreurs, 'type_reservation') ?>

                    <div class="ligne" id="zone-date">
                        <div class="champ">
                            <label for="date">Date <span class="obligatoire">*</span></label>
                            <input type="date" id="date" name="date"
                                   min="<?= e($dateMin) ?>" max="<?= e($dateMax) ?>"
                                   value="<?= e($saisie['date']) ?>"
                                   class="<?= isset($erreurs['date']) ? 'invalide' : '' ?>"
                                   aria-describedby="aide-date<?= isset($erreurs['date']) ? ' err-date' : '' ?>">
                        </div>
                        <div class="champ">
                            <label for="heure">Heure <span class="obligatoire">*</span></label>
                            <select id="heure" name="heure" class="<?= isset($erreurs['date']) ? 'invalide' : '' ?>">
                                <option value="">— Choisir —</option>
                                <?php foreach ($heures as $h): ?>
                                    <option value="<?= e($h) ?>" <?= $h === $saisie['heure'] ? 'selected' : '' ?>>
                                        <?= e(str_replace(':', ' h ', $h)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?= erreurChamp($erreurs, 'date') ?>
                    <p class="aide" id="aide-date">
                        Du lundi au samedi, de 8 h à 19 h, au moins 24 h à l'avance et au plus <?= HORIZON_JOURS ?> jours.
                        Ignoré si vous choisissez « Dès que possible ».
                    </p>
                </fieldset>

                <!-- ===== 2. Le vélo ===== -->
                <fieldset>
                    <legend>Votre vélo</legend>

                    <div class="ligne">
                        <div class="champ">
                            <label for="type_velo">Type de vélo <span class="obligatoire">*</span></label>
                            <select id="type_velo" name="type_velo" class="<?= isset($erreurs['type_velo']) ? 'invalide' : '' ?>">
                                <?php foreach (TYPES_VELO as $valeur => $libelle): ?>
                                    <option value="<?= e($valeur) ?>" <?= $valeur === $saisie['type_velo'] ? 'selected' : '' ?>><?= e($libelle) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= erreurChamp($erreurs, 'type_velo') ?>
                        </div>
                        <div class="champ">
                            <label for="marque_velo">Marque <span class="aide">(facultatif)</span></label>
                            <input type="text" id="marque_velo" name="marque_velo" maxlength="50"
                                   value="<?= e($saisie['marque_velo']) ?>" placeholder="Ex. Decathlon, Peugeot, Brompton"
                                   class="<?= isset($erreurs['marque_velo']) ? 'invalide' : '' ?>">
                            <?= erreurChamp($erreurs, 'marque_velo') ?>
                        </div>
                    </div>

                    <div class="champ">
                        <label for="description">Description de la panne <span class="obligatoire">*</span></label>
                        <textarea id="description" name="description" maxlength="1000" rows="5"
                                  placeholder="Ex. Le vélo fait un bruit de cliquetis quand je pédale, et la chaîne saute sur les petits braquets"
                                  class="<?= isset($erreurs['description']) ? 'invalide' : '' ?>"
                                  aria-describedby="compteur-description"><?= e($saisie['description']) ?></textarea>
                        <?= erreurChamp($erreurs, 'description') ?>
                        <p class="aide compteur" id="compteur-description" data-max="1000">Entre 10 et 1000 caractères.</p>
                    </div>
                </fieldset>

                <!-- ===== 3. Où ? ===== -->
                <fieldset>
                    <legend>Adresse d'intervention</legend>

                    <?php if ($aAdresse): ?>
                        <div class="adresse-actuelle">📍 <?= e($adresseEnregistree) ?></div>
                        <label class="case">
                            <input type="checkbox" id="autre_adresse" name="autre_adresse" value="1"
                                   <?= $saisie['autre_adresse'] ? 'checked' : '' ?>>
                            Utiliser une autre adresse
                        </label>
                    <?php else: ?>
                        <p class="aide">Aucune adresse n'est enregistrée dans votre profil : indiquez où intervenir.</p>
                    <?php endif; ?>

                    <!-- Sans JavaScript, ces champs restent visibles : ils ne sont pris
                         en compte que si la case ci-dessus est cochée. -->
                    <div class="autre-adresse" id="zone-autre-adresse">
                        <div class="champ">
                            <label for="adresse">Numéro et rue</label>
                            <input type="text" id="adresse" name="adresse" maxlength="150"
                                   value="<?= e($saisie['adresse']) ?>" placeholder="Ex. 12 rue de la Roquette"
                                   class="<?= isset($erreurs['adresse']) ? 'invalide' : '' ?>">
                            <?= erreurChamp($erreurs, 'adresse') ?>
                        </div>
                        <div class="ligne">
                            <div class="champ">
                                <label for="code_postal">Code postal</label>
                                <input type="text" id="code_postal" name="code_postal" maxlength="5" inputmode="numeric"
                                       value="<?= e($saisie['code_postal']) ?>" placeholder="75011"
                                       class="<?= isset($erreurs['code_postal']) ? 'invalide' : '' ?>">
                                <?= erreurChamp($erreurs, 'code_postal') ?>
                            </div>
                            <div class="champ">
                                <label for="ville_autre">Ville</label>
                                <input type="text" id="ville_autre" name="ville" maxlength="80"
                                       value="<?= e($saisie['ville']) ?>"
                                       class="<?= isset($erreurs['ville']) ? 'invalide' : '' ?>">
                                <?= erreurChamp($erreurs, 'ville') ?>
                            </div>
                        </div>
                    </div>

                    <div class="champ" style="margin-top: 12px;">
                        <label for="message_client">Informations d'accès <span class="aide">(facultatif)</span></label>
                        <textarea id="message_client" name="message_client" maxlength="500" rows="2"
                                  placeholder="Code d'entrée, étage, interphone, où trouver le vélo…"
                                  class="<?= isset($erreurs['message_client']) ? 'invalide' : '' ?>"><?= e($saisie['message_client']) ?></textarea>
                        <?= erreurChamp($erreurs, 'message_client') ?>
                        <p class="aide compteur" id="compteur-message" data-max="500">500 caractères maximum.</p>
                    </div>
                </fieldset>

                <!-- ===== 4. Validation ===== -->
                <fieldset>
                    <label class="case">
                        <input type="checkbox" name="accepte" value="1" <?= $saisie['accepte'] ? 'checked' : '' ?>>
                        <span>
                            J'accepte le tarif de <strong><?= euros($service['tarif']) ?></strong> et les conditions
                            d'annulation : annulation gratuite jusqu'à 24 h avant l'intervention.
                            <span class="obligatoire">*</span>
                        </span>
                    </label>
                    <?= erreurChamp($erreurs, 'accepte') ?>
                </fieldset>

                <button type="submit" class="bouton-principal">Envoyer ma demande</button>
                <p class="aide" style="text-align: center; margin-top: 8px;">
                    Aucun paiement maintenant : vous ne payez qu'une fois la demande acceptée par le réparateur.
                </p>
            </form>
            <?php endif; ?>
        </div>

        <!-- Récapitulatif reconstruit depuis la BASE à chaque affichage -->
        <aside class="bloc recap" aria-label="Récapitulatif du service">
            <h2><?= e($service['titre']) ?></h2>
            <dl>
                <div><dt>Réparateur</dt><dd><?= e($service['raison_sociale'] ?: $service['prenom'] . ' ' . $service['nom']) ?></dd></div>
                <?php if ($service['ville']): ?>
                    <div><dt>Secteur</dt><dd><?= e($service['ville']) ?></dd></div>
                <?php endif; ?>
                <div><dt>Durée estimée</dt><dd><?= e(dureeLisible((int) $service['duree'])) ?></dd></div>
            </dl>
            <div class="prix"><?= euros($service['tarif']) ?></div>
            <p class="note">Prix TTC, déplacement inclus.</p>
        </aside>
    </div>

<?php endif; ?>
</main>

<footer class="pied">Fair'repair — la réparation de vélo à domicile, à Paris</footer>

<script>
// JavaScript de confort uniquement : sans lui, le formulaire reste complet
// et le serveur applique exactement les mêmes règles.
(function () {
    // 1) « Dès que possible » : la date et l'heure sont désactivées
    //    (un champ désactivé n'est pas envoyé : le serveur choisit le créneau).
    var radios = document.querySelectorAll('input[name="type_reservation"]');
    var champsDate = [document.getElementById('date'), document.getElementById('heure')];
    function majDate() {
        var immediate = document.querySelector('input[name="type_reservation"]:checked');
        var desactiver = immediate && immediate.value === 'immediate';
        champsDate.forEach(function (c) { if (c) { c.disabled = desactiver; } });
    }
    radios.forEach(function (r) { r.addEventListener('change', majDate); });
    majDate();

    // 2) « Utiliser une autre adresse » : affiche ou masque les champs
    var caseAdresse = document.getElementById('autre_adresse');
    var zoneAdresse = document.getElementById('zone-autre-adresse');
    if (caseAdresse && zoneAdresse) {
        var majAdresse = function () { zoneAdresse.hidden = !caseAdresse.checked; };
        caseAdresse.addEventListener('change', majAdresse);
        majAdresse();
    }

    // 3) Compteurs de caractères
    [['description', 'compteur-description'], ['message_client', 'compteur-message']].forEach(function (p) {
        var zone = document.getElementById(p[0]), compteur = document.getElementById(p[1]);
        if (!zone || !compteur) { return; }
        var max = parseInt(compteur.dataset.max, 10);
        var maj = function () {
            var n = zone.value.length;
            compteur.textContent = n + ' / ' + max + ' caractères';
            compteur.classList.toggle('trop', n > max);
        };
        zone.addEventListener('input', maj);
        maj();
    });
})();
</script>
</body>
</html>
