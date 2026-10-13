<?php
/**
 * Fair'repair — Tableau de bord RÉPARATEUR
 * ----------------------------------------
 * Page protégée : réservée aux utilisateurs connectés ayant le rôle "reparateur".
 *
 * Contenu :
 *   1. Demandes en attente : accepter / refuser, avec un message au client
 *   2. Mes rendez-vous (vue « Aujourd'hui » ou « Cette semaine »)
 *   3. Onglet « Statistiques » : chiffres clés, revenus du mois, derniers avis
 *   4. Onglet « Mes services » : ajout, modification, activation, suppression
 *
 * Navigation sans JavaScript : l'onglet et la vue sont transmis dans l'URL
 *   dashboard-reparateur.php?onglet=services&vue=semaine
 *
 * Prérequis : la migration migration-messages.sql doit avoir été exécutée
 * (colonnes reservation.message_reparateur et reservation.date_reponse).
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/affichage.php';

// --- Bouton « Client / Réparateur » de l'en-tête ---
// Traité AVANT tout affichage (il se termine par une redirection header())
// et avant exigerRole() (un onglet resté ouvert doit pouvoir basculer).
traiterDemandeBascule();

// --- Bouton « Révoquer » de « Mes appareils connectés » (POST + CSRF) ---
traiterRevocationAppareil();

// --- CONTRÔLE DU RÔLE ACTIF ---
// Appelé tout en haut, AVANT le moindre affichage :
//   - pas connecté                          → page de connexion ;
//   - rôle actif ≠ réparateur, ou profil
//     réparateur non validé                 → erreur 403, la page s'arrête.
// Même un utilisateur qui POSSÈDE les deux rôles reçoit un 403 tant
// qu'il se trouve dans l'espace client : il doit d'abord basculer.
exigerRole('reparateur');

// L'identifiant vient de la SESSION (côté serveur), jamais du formulaire
// ni de l'URL : c'est lui qui filtre TOUTES les requêtes de la page.
$idReparateur = (int) $_SESSION['id_user'];

// --- Paramètres d'affichage : liste blanche ---
// Toute valeur inattendue retombe sur la valeur par défaut.
$onglet = ($_GET['onglet'] ?? '') === 'services' ? 'services' : 'statistiques';
$vue    = ($_GET['vue']    ?? '') === 'semaine'  ? 'semaine'  : 'jour';

// ============================================================
//  CONSTANTES
// ============================================================

// Durées proposées dans la liste déroulante (minutes => libellé)
const DUREES_PROPOSEES = [
    15  => '15 min',
    30  => '30 min',
    45  => '45 min',
    60  => '1 h',
    90  => '1 h 30',
    120 => '2 h',
    180 => '3 h',
    240 => 'Demi-journée (4 h)',
];

const MESSAGE_MAX = 500;   // longueur maximale du message au client
const MOTIF_MIN   = 10;    // longueur minimale d'un motif de refus

// Messages prêts à l'emploi (cliquables pour pré-remplir la zone de texte)
const MODELES_ACCEPTATION = [
    "Je serai là à l'heure prévue, à bientôt !",
    "Merci de prévoir un accès à une prise électrique.",
    "Merci de laisser le vélo accessible (rez-de-chaussée ou cour).",
    "Je vous appellerai 30 minutes avant mon arrivée.",
];
const MOTIFS_REFUS = [
    "Je ne suis pas disponible à cette date."           => "Je ne suis pas disponible à cette date.",
    "L'adresse est hors de ma zone d'intervention."     => "L'adresse est hors de ma zone d'intervention.",
    "Cette panne nécessite un atelier."                 => "Cette panne nécessite un atelier.",
    "Autre"                                             => "",   // vide la zone : motif à rédiger
];

// ============================================================
//  MESSAGES APRÈS REDIRECTION (POST / Redirect / GET)
// ============================================================
// Après une action réussie, on redirige vers une URL du type
// ?onglet=services&ok=service_ajoute. On n'affiche JAMAIS le contenu de
// l'URL tel quel : le code sert de clé dans une liste blanche de messages.
// Un code inconnu n'affiche rien (pas de faille XSS possible par l'URL).
const MESSAGES_OK = [
    'service_ajoute'   => "Le service a bien été ajouté.",
    'service_modifie'  => "Le service a bien été modifié.",
    'service_supprime' => "Le service a été supprimé.",
    'service_archive'  => "Ce service a déjà été réservé : il a été désactivé plutôt que supprimé, pour conserver l'historique.",
    'service_active'   => "Le service est de nouveau visible par les clients.",
    'service_desactive'=> "Le service n'est plus visible par les clients.",
    'demande_acceptee' => "Demande acceptée. Le client verra votre message dans son espace.",
    'demande_refusee'  => "Demande refusée. Le client verra le motif dans son espace.",
];
const MESSAGES_ERREUR = [
    'service_introuvable'   => "Service introuvable.",
    'demande_deja_traitee'  => "Cette demande a déjà été traitée ou ne vous concerne pas.",
    'requete_invalide'      => "Requête invalide. Merci de recharger la page.",
];

$codeOk     = is_string($_GET['ok']     ?? null) ? $_GET['ok']     : '';
$codeErreur = is_string($_GET['erreur'] ?? null) ? $_GET['erreur'] : '';
$message = MESSAGES_OK[$codeOk]         ?? '';
$erreur  = MESSAGES_ERREUR[$codeErreur] ?? '';

// Message laissé en session par une redirection (ex. bascule refusée)
$flash = lireFlash();
if ($flash && $flash['type'] === 'succes') {
    $message = $flash['texte'];
} elseif ($flash) {
    $erreur = $flash['texte'];
}

// ============================================================
//  FONCTIONS UTILITAIRES
// ============================================================

/** Construit une URL de cette page en conservant l'onglet et la vue. */
function urlPage(array $params): string
{
    global $onglet, $vue;
    return 'dashboard-reparateur.php?' . http_build_query(array_merge(
        ['onglet' => $onglet, 'vue' => $vue],
        $params
    ));
}

/**
 * Redirige puis arrête le script (dernière étape du pattern PRG).
 *
 * POURQUOI REDIRIGER APRÈS UN POST ?
 * Si on affichait la page directement en réponse au POST, un simple F5
 * renverrait le formulaire : le service serait ajouté deux fois, ou la
 * demande traitée deux fois. Avec la redirection, le navigateur charge
 * la page en GET : l'actualiser ne renvoie plus rien.
 */
function redirigerVers(array $params, string $ancre = ''): never
{
    header('Location: ' . urlPage($params) . $ancre);
    exit;
}

/** Lit un champ texte du POST (une valeur envoyée sous forme de tableau est ignorée). */
function champTexte(array $source, string $cle): string
{
    $valeur = $source[$cle] ?? '';
    return is_string($valeur) ? trim($valeur) : '';
}

/**
 * Valide le formulaire d'un service (ajout ou modification).
 * Renvoie [valeurs saisies à réafficher, erreurs par champ, valeurs prêtes pour SQL].
 *
 * Tout est revérifié ici, côté serveur : les attributs HTML (required,
 * maxlength, min, max) se retirent en une seconde avec les outils
 * de développement du navigateur.
 */
function validerService(array $post): array
{
    $saisie = [
        'titre'         => champTexte($post, 'titre'),
        'description'   => champTexte($post, 'description'),
        'tarif'         => champTexte($post, 'tarif'),
        'duree_estimee' => champTexte($post, 'duree_estimee'),
        'rayon'         => champTexte($post, 'rayon'),
        'actif'         => isset($post['actif']),
    ];
    $erreurs = [];

    // Titre : 3 à 150 caractères (mb_strlen compte les lettres accentuées pour 1)
    $longueur = mb_strlen($saisie['titre']);
    if ($longueur === 0) {
        $erreurs['titre'] = "Le titre est obligatoire.";
    } elseif ($longueur < 3) {
        $erreurs['titre'] = "Le titre doit contenir au moins 3 caractères.";
    } elseif ($longueur > 150) {
        $erreurs['titre'] = "Le titre ne doit pas dépasser 150 caractères.";
    }

    // Description : facultative, 1000 caractères maximum
    if (mb_strlen($saisie['description']) > 1000) {
        $erreurs['description'] = "La description ne doit pas dépasser 1000 caractères.";
    }

    // Tarif : on accepte la virgule française (« 25,50 ») et on la convertit.
    // L'expression régulière impose 1 à 4 chiffres, puis 1 ou 2 décimales
    // facultatives : de 0 à 9999,99, exactement ce que permet DECIMAL(8,2) ici.
    $tarif = str_replace([',', ' '], ['.', ''], $saisie['tarif']);
    if ($tarif === '') {
        $erreurs['tarif'] = "Le tarif est obligatoire.";
    } elseif (!preg_match('/^[0-9]{1,4}(\.[0-9]{1,2})?$/', $tarif)) {
        $erreurs['tarif'] = "Tarif invalide : un montant entre 0 et 9999,99 € (ex. 25 ou 25,50).";
    }

    // Durée : entier entre 5 et 600 minutes.
    // ctype_digit refuse les signes, les décimales et le texte.
    $duree = $saisie['duree_estimee'];
    if (!ctype_digit($duree) || (int) $duree < 5 || (int) $duree > 600) {
        $erreurs['duree_estimee'] = "Merci de choisir une durée dans la liste.";
    }

    // Rayon : facultatif, entier de 0 à 100 km. Vide = NULL en base.
    $rayon = $saisie['rayon'];
    if ($rayon !== '' && (!ctype_digit($rayon) || (int) $rayon > 100)) {
        $erreurs['rayon'] = "Le rayon doit être un nombre entier entre 0 et 100 km.";
    }

    $pourSql = [
        ':titre'         => $saisie['titre'],
        ':description'   => $saisie['description'] === '' ? null : $saisie['description'],
        ':tarif'         => $tarif,
        ':duree_estimee' => (int) $duree,
        ':rayon'         => $rayon === '' ? null : (int) $rayon,
        ':actif'         => $saisie['actif'] ? 1 : 0,
    ];

    return [$saisie, $erreurs, $pourSql];
}

// État des formulaires à réafficher (en cas d'erreur de saisie)
$formService = null;   // ['mode' => 'ajout'|'modification', 'id', 'saisie', 'erreurs']
$formReponse = null;   // ['id', 'decision', 'message', 'erreur']

// ============================================================
//  ACTIONS (toutes en POST + jeton CSRF)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = champTexte($_POST, 'action');

    if (!verifierCsrfToken($_POST['csrf_token'] ?? null)) {
        $erreur = MESSAGES_ERREUR['requete_invalide'];

    } elseif ($action === 'deconnexion') {
        // Se déconnecter = aussi « oublier cet appareil » (jeton « Se souvenir
        // de moi »). fermerSession() le fait aussi ; le second appel est sans effet.
        // Déconnexion journalisée AVANT fermerSession(), qui vide la session.
        enregistrerTentative($pdo, $_SESSION['email'], $_SERVER['REMOTE_ADDR'] ?? '',
                             true, 'deconnexion', (int) $_SESSION['id_user']);
        supprimerJetonSouvenir($pdo);
        fermerSession();
        header('Location: connexion.php?msg=deconnecte');
        exit;

    // ---------------------------------------------------------
    //  AJOUT / MODIFICATION D'UN SERVICE
    // ---------------------------------------------------------
    } elseif ($action === 'ajouter_service' || $action === 'modifier_service') {

        $estAjout  = $action === 'ajouter_service';
        $idService = $estAjout ? null : (int) ($_POST['id_service'] ?? 0);

        // Modification : on vérifie D'ABORD que le service appartient bien
        // au réparateur connecté (protection IDOR, voir plus bas).
        // On le fait avant l'UPDATE car, avec MySQL, rowCount() vaut 0 quand
        // on enregistre sans rien changer : on ne pourrait pas distinguer
        // « rien n'a changé » de « ce service n'est pas à vous ».
        if (!$estAjout) {
            $req = $pdo->prepare("SELECT id_service FROM service
                                  WHERE id_service = :id_service AND id_reparateur = :id_reparateur");
            $req->execute([':id_service' => $idService, ':id_reparateur' => $idReparateur]);
            if ($req->fetchColumn() === false) {
                redirigerVers(['onglet' => 'services', 'erreur' => 'service_introuvable']);
            }
        }

        [$saisie, $erreursService, $valeurs] = validerService($_POST);

        if (empty($erreursService)) {
            // id_reparateur vient de $_SESSION, JAMAIS d'un champ caché :
            // un <input type="hidden"> se modifie aussi facilement qu'un
            // champ visible, et permettrait de créer un service au nom
            // d'un autre réparateur.
            $valeurs[':id_reparateur'] = $idReparateur;

            if ($estAjout) {
                $sql = "INSERT INTO service
                            (titre, description, tarif, duree_estimee, rayon, actif, image, id_reparateur)
                        VALUES
                            (:titre, :description, :tarif, :duree_estimee, :rayon, :actif, NULL, :id_reparateur)";
                $pdo->prepare($sql)->execute($valeurs);
                redirigerVers(['onglet' => 'services', 'ok' => 'service_ajoute']);
            }

            // Le « AND id_reparateur » est redondant avec la vérification
            // ci-dessus, mais on le garde : chaque requête d'écriture doit
            // être sûre à elle seule (défense en profondeur).
            $valeurs[':id_service'] = $idService;
            $sql = "UPDATE service
                    SET titre         = :titre,
                        description   = :description,
                        tarif         = :tarif,
                        duree_estimee = :duree_estimee,
                        rayon         = :rayon,
                        actif         = :actif
                    WHERE id_service    = :id_service
                      AND id_reparateur = :id_reparateur";
            $pdo->prepare($sql)->execute($valeurs);
            redirigerVers(['onglet' => 'services', 'ok' => 'service_modifie']);
        }

        // Erreurs de saisie : PAS de redirection ici, sinon on perdrait ce
        // que l'utilisateur a tapé. La page s'affiche avec le formulaire
        // ouvert, les valeurs saisies et un message sous chaque champ.
        $formService = [
            'mode'    => $estAjout ? 'ajout' : 'modification',
            'id'      => $idService,
            'saisie'  => $saisie,
            'erreurs' => $erreursService,
        ];
        $onglet = 'services';

    // ---------------------------------------------------------
    //  ACTIVER / DÉSACTIVER UN SERVICE
    // ---------------------------------------------------------
    } elseif ($action === 'basculer_service') {
        $idService = (int) ($_POST['id_service'] ?? 0);

        // --- PROTECTION IDOR ---
        // L'id du service vient du formulaire : un petit malin peut le
        // modifier (outils de développement du navigateur) pour viser le
        // service d'un AUTRE réparateur. Le « AND id_reparateur = :id_reparateur »
        // rend la requête inopérante dans ce cas : aucune ligne ne correspond,
        // donc rien n'est modifié.
        $sql = "UPDATE service
                SET actif = CASE WHEN actif = 1 THEN 0 ELSE 1 END
                WHERE id_service    = :id_service
                  AND id_reparateur = :id_reparateur";
        $req = $pdo->prepare($sql);
        $req->execute([':id_service' => $idService, ':id_reparateur' => $idReparateur]);

        if ($req->rowCount() === 0) {
            redirigerVers(['onglet' => 'services', 'erreur' => 'service_introuvable']);
        }

        $req = $pdo->prepare("SELECT actif FROM service
                              WHERE id_service = :id_service AND id_reparateur = :id_reparateur");
        $req->execute([':id_service' => $idService, ':id_reparateur' => $idReparateur]);
        redirigerVers(['onglet' => 'services', 'ok' => $req->fetchColumn() ? 'service_active' : 'service_desactive']);

    // ---------------------------------------------------------
    //  SUPPRESSION D'UN SERVICE (réelle ou « douce »)
    // ---------------------------------------------------------
    } elseif ($action === 'supprimer_service') {
        $idService = (int) ($_POST['id_service'] ?? 0);

        // Combien de réservations pour ce service ? La condition sur
        // id_reparateur garantit en même temps que le service est bien à lui.
        $sql = "SELECT COUNT(r.id_reservation) AS nb
                FROM service s
                LEFT JOIN reservation r ON r.id_service = s.id_service
                WHERE s.id_service    = :id_service
                  AND s.id_reparateur = :id_reparateur
                GROUP BY s.id_service";
        $req = $pdo->prepare($sql);
        $req->execute([':id_service' => $idService, ':id_reparateur' => $idReparateur]);
        $nbReservations = $req->fetchColumn();

        if ($nbReservations === false) {
            redirigerVers(['onglet' => 'services', 'erreur' => 'service_introuvable']);
        }

        // POURQUOI UNE SUPPRESSION « DOUCE » ?
        // La clé étrangère reservation.id_service → service est déclarée
        // ON DELETE NO ACTION : MySQL REFUSE de supprimer un service encore
        // référencé par une réservation (erreur 1451). Et même si la base
        // l'acceptait (ON DELETE CASCADE), on effacerait l'historique du
        // client : ses réservations, ses paiements et ses avis.
        // Un service déjà réservé est donc seulement désactivé (actif = 0) :
        // il disparaît du catalogue mais l'historique reste intact.
        // Un service jamais réservé ne gêne personne : on le supprime vraiment.
        $sqlDesactiver = "UPDATE service SET actif = 0
                          WHERE id_service = :id_service AND id_reparateur = :id_reparateur";
        $params = [':id_service' => $idService, ':id_reparateur' => $idReparateur];

        if ((int) $nbReservations > 0) {
            $pdo->prepare($sqlDesactiver)->execute($params);
            redirigerVers(['onglet' => 'services', 'ok' => 'service_archive']);
        }

        try {
            $pdo->prepare("DELETE FROM service
                           WHERE id_service = :id_service AND id_reparateur = :id_reparateur")
                ->execute($params);
            redirigerVers(['onglet' => 'services', 'ok' => 'service_supprime']);
        } catch (PDOException $e) {
            // Cas rare : un client a réservé ce service entre le comptage et
            // la suppression. La clé étrangère bloque le DELETE (code 23000) :
            // on se rabat sur la désactivation.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $pdo->prepare($sqlDesactiver)->execute($params);
            redirigerVers(['onglet' => 'services', 'ok' => 'service_archive']);
        }

    // ---------------------------------------------------------
    //  RÉPONSE À UNE DEMANDE : ACCEPTER / REFUSER
    // ---------------------------------------------------------
    } elseif ($action === 'repondre_demande') {
        $idReservation = (int) ($_POST['id_reservation'] ?? 0);
        $decision      = champTexte($_POST, 'decision');

        // Les navigateurs envoient les retours à la ligne sous la forme \r\n
        // (2 caractères) alors que maxlength les compte pour 1 : on normalise
        // avant de mesurer, sinon un message de 500 caractères serait refusé.
        $texte = str_replace("\r\n", "\n", champTexte($_POST, 'message'));

        $erreurReponse = '';
        if ($decision !== 'accepter' && $decision !== 'refuser') {
            redirigerVers(['erreur' => 'requete_invalide'], '#demandes');
        } elseif (mb_strlen($texte) > MESSAGE_MAX) {
            $erreurReponse = "Le message ne doit pas dépasser " . MESSAGE_MAX . " caractères.";
        } elseif ($decision === 'refuser' && mb_strlen($texte) < MOTIF_MIN) {
            $erreurReponse = "Merci d'expliquer la raison du refus au client ("
                           . MOTIF_MIN . " caractères minimum).";
        }

        if ($erreurReponse === '') {
            // UNE SEULE requête UPDATE, avec deux garde-fous dans le WHERE :
            //
            // 1) AND s.id_reparateur = :id_reparateur  → PROTECTION IDOR
            //    L'id de réservation vient du formulaire et peut être modifié.
            //    La jointure avec service vérifie que la réservation porte sur
            //    un service DE CE réparateur. Sinon, aucune ligne ne correspond
            //    et rien n'est modifié.
            //
            // 2) AND r.statut = 'en_attente'           → GARDE SUR L'ÉTAT
            //    On ne répond qu'à une demande encore en attente. Un double clic,
            //    un formulaire renvoyé ou deux onglets ouverts ne peuvent donc
            //    pas retraiter une demande déjà acceptée ou refusée. La
            //    vérification et la modification se font dans la même requête,
            //    ce qui évite aussi une « situation de concurrence » : lire le
            //    statut PUIS le modifier en deux requêtes laisserait un
            //    intervalle où une autre requête pourrait passer entre les deux.
            $sql = "UPDATE reservation r
                    INNER JOIN service s ON s.id_service = r.id_service
                    SET r.statut             = :statut,
                        r.message_reparateur = :message,
                        r.date_reponse       = NOW()
                    WHERE r.id_reservation = :id_reservation
                      AND s.id_reparateur  = :id_reparateur
                      AND r.statut         = 'en_attente'";
            $req = $pdo->prepare($sql);
            $req->execute([
                ':statut'         => $decision === 'accepter' ? 'confirmee' : 'annulee',
                ':message'        => $texte === '' ? null : $texte,
                ':id_reservation' => $idReservation,
                ':id_reparateur'  => $idReparateur,
            ]);

            // rowCount() = nombre de lignes réellement modifiées.
            // 0 → la demande n'existe pas, n'est pas à ce réparateur, ou a
            // déjà reçu une réponse : on le signale au lieu d'afficher un succès.
            if ($req->rowCount() === 0) {
                redirigerVers(['erreur' => 'demande_deja_traitee'], '#demandes');
            }
            redirigerVers(['ok' => $decision === 'accepter' ? 'demande_acceptee' : 'demande_refusee'], '#demandes');
        }

        // Erreur de saisie : on réaffiche le formulaire ouvert avec le texte
        $formReponse = [
            'id'       => $idReservation,
            'decision' => $decision,
            'message'  => $texte,
            'erreur'   => $erreurReponse,
        ];

    // ---------------------------------------------------------
    //  FERMETURE DU PROFIL RÉPARATEUR (suppression « douce »)
    // ---------------------------------------------------------
    } elseif ($action === 'fermer_profil') {

        // POURQUOI PAS UN VRAI DELETE ?
        // La ligne reparateur est référencée par ses services (service.id_reparateur),
        // eux-mêmes référencés par les réservations, les paiements et les avis
        // des clients. Supprimer la ligne casserait ces liens (ou, avec
        // ON DELETE CASCADE, effacerait l'historique des clients).
        // On la « désactive » donc : statut 'refuse' + motif, profil
        // indisponible, services retirés du catalogue. L'historique reste
        // intact, et l'utilisateur pourra refaire une demande plus tard.
        //
        // Toutes les requêtes utilisent l'id de la SESSION : on ne peut
        // fermer que SON propre profil.
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE reparateur
                           SET statut_validation = 'refuse',
                               motif_refus       = :motif,
                               disponible        = 0,
                               date_validation   = NULL
                           WHERE id_reparateur = :id")
                ->execute([
                    ':motif' => 'Profil fermé à votre demande le ' . date('d/m/Y') . '.',
                    ':id'    => $idReparateur,
                ]);

            $pdo->prepare("UPDATE service SET actif = 0 WHERE id_reparateur = :id")
                ->execute([':id' => $idReparateur]);

            // Les demandes encore en attente n'auront plus de réponse : on les
            // refuse en prévenant le client, plutôt que de le laisser attendre.
            // (Les interventions déjà CONFIRMÉES restent dues et visibles.)
            $pdo->prepare("UPDATE reservation r
                           INNER JOIN service s ON s.id_service = r.id_service
                           SET r.statut             = 'annulee',
                               r.message_reparateur = 'Le réparateur a fermé son profil sur Fair''repair.',
                               r.date_reponse       = NOW()
                           WHERE s.id_reparateur = :id
                             AND r.statut        = 'en_attente'")
                ->execute([':id' => $idReparateur]);

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Fermeture profil réparateur : ' . $e->getMessage());
            redirigerVers(['onglet' => 'services', 'erreur' => 'requete_invalide']);
        }

        // Rôles relus en base : le profil n'est plus valide, le rôle actif
        // repasse automatiquement sur « client » (s'il existe).
        rafraichirRoles();
        definirFlash('succes', "Votre profil réparateur est fermé. Vos services ne sont plus visibles. "
                             . "Vous pourrez refaire une demande à tout moment.");
        header('Location: ' . espaceActif());
        exit;
    }
}

// ============================================================
//  1. DEMANDES EN ATTENTE
// ============================================================
// TIMESTAMPDIFF(MINUTE, date_demande, NOW()) : ancienneté de la demande en
// minutes, calculée par MySQL (même horloge que les dates enregistrées).
// (date_intervention < NOW()) vaut 1 si la date souhaitée est déjà passée.
$sql = "SELECT r.id_reservation, r.date_demande, r.date_intervention,
               r.adresse_intervention, r.type_reservation,
               r.type_velo, r.marque_velo, r.description_panne, r.message_client,
               s.titre     AS service,
               s.tarif,
               u.prenom    AS cli_prenom,
               u.nom       AS cli_nom,
               u.telephone AS cli_tel,
               TIMESTAMPDIFF(MINUTE, r.date_demande, NOW()) AS minutes_attente,
               (r.date_intervention < NOW())                AS depassee
        FROM reservation r
        INNER JOIN service     s ON s.id_service = r.id_service
        INNER JOIN utilisateur u ON u.id_user    = r.id_client
        WHERE s.id_reparateur = :id_reparateur
          AND r.statut        = 'en_attente'
        ORDER BY r.date_intervention ASC";
$req = $pdo->prepare($sql);
$req->execute([':id_reparateur' => $idReparateur]);
$demandes   = $req->fetchAll();
$nbDemandes = count($demandes);   // compteur réutilisé dans l'onglet Statistiques

// ============================================================
//  2. RENDEZ-VOUS (jour / semaine)
// ============================================================
// Chemin des jointures : reservation → service → (id_reparateur)
// Une réservation n'a pas de colonne id_reparateur : c'est le SERVICE
// réservé qui appartient au réparateur. On filtre donc sur s.id_reparateur.
//
// FILTRAGE PAR DATE avec les fonctions MySQL :
//   - DATE(x) = CURDATE()
//       DATE() garde seulement la partie « jour » du DATETIME
//       (2026-09-29 14:30:00 → 2026-09-29), comparée à la date du jour.
//   - YEARWEEK(x, 1) = YEARWEEK(CURDATE(), 1)
//       YEARWEEK renvoie l'année + le numéro de semaine (ex. 202640).
//       Le mode 1 fait commencer la semaine le LUNDI (usage français).
//       Comparer l'année ET la semaine évite de mélanger la semaine 40
//       de 2025 avec la semaine 40 de 2026.
//
// Les deux requêtes sont écrites en entier (pas de morceau de SQL collé
// selon le choix de l'utilisateur) : la variable $vue sert seulement à
// CHOISIR laquelle exécuter.
$requetesRdv = [
    'jour' => "SELECT r.id_reservation, r.date_intervention, r.adresse_intervention, r.statut,
                      s.titre     AS service,
                      u.prenom    AS cli_prenom,
                      u.nom       AS cli_nom,
                      u.telephone AS cli_tel
               FROM reservation r
               INNER JOIN service     s ON s.id_service = r.id_service
               INNER JOIN utilisateur u ON u.id_user    = r.id_client
               WHERE s.id_reparateur = :id_reparateur
                 AND r.statut <> 'annulee'
                 AND DATE(r.date_intervention) = CURDATE()
               ORDER BY r.date_intervention ASC",

    'semaine' => "SELECT r.id_reservation, r.date_intervention, r.adresse_intervention, r.statut,
                         s.titre     AS service,
                         u.prenom    AS cli_prenom,
                         u.nom       AS cli_nom,
                         u.telephone AS cli_tel
                  FROM reservation r
                  INNER JOIN service     s ON s.id_service = r.id_service
                  INNER JOIN utilisateur u ON u.id_user    = r.id_client
                  WHERE s.id_reparateur = :id_reparateur
                    AND r.statut <> 'annulee'
                    AND YEARWEEK(r.date_intervention, 1) = YEARWEEK(CURDATE(), 1)
                  ORDER BY r.date_intervention ASC",
];

$req = $pdo->prepare($requetesRdv[$vue]);
$req->execute([':id_reparateur' => $idReparateur]);
$rendezVous = $req->fetchAll();

// Vue semaine : regroupement par jour. Les lignes arrivant déjà triées
// par date, les jours restent dans l'ordre chronologique.
$rdvParJour = [];
foreach ($rendezVous as $rdv) {
    $jour = substr($rdv['date_intervention'], 0, 10);   // "2026-09-29"
    $rdvParJour[$jour][] = $rdv;
}
$aujourdhui = date('Y-m-d');

// ============================================================
//  3. ONGLET « STATISTIQUES »
// ============================================================
if ($onglet === 'statistiques') {

    // Compteurs en une seule requête (agrégation conditionnelle)
    $sql = "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN r.statut = 'confirmee'  THEN 1 ELSE 0 END), 0) AS confirmees,
                COALESCE(SUM(CASE WHEN r.statut = 'terminee'   THEN 1 ELSE 0 END), 0) AS terminees
            FROM reservation r
            INNER JOIN service s ON s.id_service = r.id_service
            WHERE s.id_reparateur = :id_reparateur";
    $req = $pdo->prepare($sql);
    $req->execute([':id_reparateur' => $idReparateur]);
    $stats = $req->fetch();

    // Revenus : seuls les paiements VALIDÉS comptent.
    // « Mes gains » = montant_reparateur, c'est-à-dire ce que le réparateur
    // touche VRAIMENT, une fois la commission de la plateforme retirée.
    // Additionner p.montant (le prix payé par le client) surestimerait ses gains.
    // Même requête pour le total, le mois en cours et le mois précédent :
    // DATE_FORMAT(date, '%Y-%m') donne "2026-09", ce qui permet de comparer
    // un mois précis (année comprise). CURDATE() - INTERVAL 1 MONTH donne
    // une date du mois précédent, y compris en janvier (→ décembre N-1).
    $sql = "SELECT
                COALESCE(SUM(p.montant_reparateur), 0)    AS total,
                COALESCE(SUM(p.commission_plateforme), 0) AS commission,
                COALESCE(SUM(CASE WHEN DATE_FORMAT(p.date_payement, '%Y-%m')
                                     = DATE_FORMAT(CURDATE(), '%Y-%m')
                                  THEN p.montant_reparateur ELSE 0 END), 0) AS mois_courant,
                COALESCE(SUM(CASE WHEN DATE_FORMAT(p.date_payement, '%Y-%m')
                                     = DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m')
                                  THEN p.montant_reparateur ELSE 0 END), 0) AS mois_precedent
            FROM payement p
            INNER JOIN reservation r ON r.id_reservation = p.id_reservation
            INNER JOIN service     s ON s.id_service     = r.id_service
            WHERE s.id_reparateur = :id_reparateur
              AND p.statut        = 'valide'";
    $req = $pdo->prepare($sql);
    $req->execute([':id_reparateur' => $idReparateur]);
    $revenus = $req->fetch();

    $moisCourant   = (float) $revenus['mois_courant'];
    $moisPrecedent = (float) $revenus['mois_precedent'];

    // Évolution en % (impossible à calculer si le mois précédent vaut 0)
    $evolution = $moisPrecedent > 0
        ? (int) round(($moisCourant - $moisPrecedent) / $moisPrecedent * 100)
        : null;

    // Largeur des barres de comparaison (en % de la plus grande valeur)
    $max            = max($moisCourant, $moisPrecedent, 1);
    $largeurCourant = (int) round($moisCourant   / $max * 100);
    $largeurPrec    = (int) round($moisPrecedent / $max * 100);

    $nomMoisCourant = moisFr(time());
    $nomMoisPrec    = moisFr(strtotime('first day of last month'));

    // Note moyenne et nombre d'avis
    $sql = "SELECT AVG(a.note) AS moyenne, COUNT(a.id_avis) AS nombre
            FROM avis a
            INNER JOIN reservation r ON r.id_reservation = a.id_reservation
            INNER JOIN service     s ON s.id_service     = r.id_service
            WHERE s.id_reparateur = :id_reparateur";
    $req = $pdo->prepare($sql);
    $req->execute([':id_reparateur' => $idReparateur]);
    $notes = $req->fetch();

    // 5 derniers avis reçus
    $sql = "SELECT a.note, a.commentaire, a.date_avis,
                   s.titre  AS service,
                   u.prenom AS cli_prenom,
                   u.nom    AS cli_nom
            FROM avis a
            INNER JOIN reservation r ON r.id_reservation = a.id_reservation
            INNER JOIN service     s ON s.id_service     = r.id_service
            INNER JOIN utilisateur u ON u.id_user        = r.id_client
            WHERE s.id_reparateur = :id_reparateur
            ORDER BY a.date_avis DESC
            LIMIT 5";
    $req = $pdo->prepare($sql);
    $req->execute([':id_reparateur' => $idReparateur]);
    $derniersAvis = $req->fetchAll();
}

// ============================================================
//  4. ONGLET « MES SERVICES »
// ============================================================
if ($onglet === 'services') {
    // LEFT JOIN : un service qui n'a encore jamais été réservé doit
    // apparaître quand même, avec 0 réservation.
    // COUNT(r.id_reservation) ne compte que les lignes non NULL.
    // Toutes les colonnes affichées figurent dans le GROUP BY
    // (obligatoire avec le mode ONLY_FULL_GROUP_BY de MySQL).
    $sql = "SELECT s.id_service, s.titre, s.tarif, s.duree_estimee, s.rayon, s.actif,
                   COUNT(r.id_reservation) AS nb_reservations
            FROM service s
            LEFT JOIN reservation r ON r.id_service = s.id_service
            WHERE s.id_reparateur = :id_reparateur
            GROUP BY s.id_service, s.titre, s.tarif, s.duree_estimee, s.rayon, s.actif
            ORDER BY s.actif DESC, s.titre ASC";
    $req = $pdo->prepare($sql);
    $req->execute([':id_reparateur' => $idReparateur]);
    $services = $req->fetchAll();

    // Lien « Modifier » (?modifier=12) : on charge le service pour pré-remplir
    // le formulaire, UNIQUEMENT s'il appartient au réparateur connecté.
    $idAModifier = (int) ($_GET['modifier'] ?? 0);
    if ($formService === null && $idAModifier > 0) {
        $req = $pdo->prepare("SELECT id_service, titre, description, tarif, duree_estimee, rayon, actif
                              FROM service
                              WHERE id_service = :id_service AND id_reparateur = :id_reparateur");
        $req->execute([':id_service' => $idAModifier, ':id_reparateur' => $idReparateur]);
        $s = $req->fetch();

        if ($s) {
            $formService = [
                'mode'    => 'modification',
                'id'      => (int) $s['id_service'],
                'saisie'  => [
                    'titre'         => $s['titre'],
                    'description'   => (string) $s['description'],
                    'tarif'         => number_format((float) $s['tarif'], 2, ',', ''),
                    'duree_estimee' => (string) $s['duree_estimee'],
                    'rayon'         => $s['rayon'] === null ? '' : (string) $s['rayon'],
                    'actif'         => (bool) $s['actif'],
                ],
                'erreurs' => [],
            ];
        } else {
            $erreur = MESSAGES_ERREUR['service_introuvable'];
        }
    }

    // Valeurs du formulaire d'ajout : celles saisies en cas d'erreur,
    // sinon des valeurs par défaut (1 h, service actif).
    $formAjout = ($formService['mode'] ?? '') === 'ajout'
        ? $formService
        : ['mode' => 'ajout', 'id' => null, 'erreurs' => [], 'saisie' => [
              'titre' => '', 'description' => '', 'tarif' => '',
              'duree_estimee' => '60', 'rayon' => '', 'actif' => true,
          ]];
}

/**
 * Affiche le formulaire d'un service (ajout ou modification).
 * Une seule fonction pour les deux cas : mêmes champs, même validation.
 */
function afficherFormulaireService(array $form, string $csrfToken): void
{
    $s       = $form['saisie'];
    $err     = $form['erreurs'];
    $estAjout = $form['mode'] === 'ajout';
    $prefixe = $estAjout ? 'ajout' : 'modif';   // ids HTML uniques

    // Une durée existante hors liste mais valide (ex. 50 min, service créé
    // avant cette liste) est ajoutée aux options pour ne pas être perdue.
    $durees = DUREES_PROPOSEES;
    if (ctype_digit($s['duree_estimee'])
        && (int) $s['duree_estimee'] >= 5 && (int) $s['duree_estimee'] <= 600
        && !isset($durees[(int) $s['duree_estimee']])) {
        $durees[(int) $s['duree_estimee']] = dureeFr((int) $s['duree_estimee']);
        ksort($durees);
    }
    ?>
    <form method="post" action="<?= e(urlPage(['onglet' => 'services'])) ?>" class="form-service" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="<?= $estAjout ? 'ajouter_service' : 'modifier_service' ?>">
        <?php if (!$estAjout): ?>
            <!-- L'id du service est revérifié côté serveur (appartient-il au réparateur ?) -->
            <input type="hidden" name="id_service" value="<?= (int) $form['id'] ?>">
        <?php endif; ?>
        <!-- Pas de champ id_reparateur : il est lu dans la session, côté serveur. -->

        <div class="champ">
            <label for="<?= $prefixe ?>-titre">Titre <span class="obligatoire">*</span></label>
            <input type="text" id="<?= $prefixe ?>-titre" name="titre" maxlength="150"
                   placeholder="Ex. Réparation de crevaison"
                   value="<?= e($s['titre']) ?>"
                   class="<?= isset($err['titre']) ? 'invalide' : '' ?>" required>
            <?php if (isset($err['titre'])): ?>
                <span class="message-erreur"><?= e($err['titre']) ?></span>
            <?php endif; ?>
        </div>

        <div class="champ">
            <label for="<?= $prefixe ?>-description">Description</label>
            <textarea id="<?= $prefixe ?>-description" name="description" rows="3" maxlength="1000"
                      placeholder="Ce qui est compris dans la prestation…"
                      class="<?= isset($err['description']) ? 'invalide' : '' ?>"><?= e($s['description']) ?></textarea>
            <?php if (isset($err['description'])): ?>
                <span class="message-erreur"><?= e($err['description']) ?></span>
            <?php else: ?>
                <span class="aide">Facultatif, 1000 caractères maximum.</span>
            <?php endif; ?>
        </div>

        <div class="ligne">
            <div class="champ">
                <label for="<?= $prefixe ?>-tarif">Tarif (€) <span class="obligatoire">*</span></label>
                <input type="text" id="<?= $prefixe ?>-tarif" name="tarif" inputmode="decimal"
                       placeholder="25,00" value="<?= e($s['tarif']) ?>"
                       class="<?= isset($err['tarif']) ? 'invalide' : '' ?>" required>
                <?php if (isset($err['tarif'])): ?>
                    <span class="message-erreur"><?= e($err['tarif']) ?></span>
                <?php endif; ?>
            </div>

            <div class="champ">
                <label for="<?= $prefixe ?>-duree">Durée estimée <span class="obligatoire">*</span></label>
                <select id="<?= $prefixe ?>-duree" name="duree_estimee"
                        class="<?= isset($err['duree_estimee']) ? 'invalide' : '' ?>">
                    <?php foreach ($durees as $minutes => $libelle): ?>
                        <option value="<?= (int) $minutes ?>"
                            <?= (string) $minutes === $s['duree_estimee'] ? 'selected' : '' ?>>
                            <?= e($libelle) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($err['duree_estimee'])): ?>
                    <span class="message-erreur"><?= e($err['duree_estimee']) ?></span>
                <?php endif; ?>
            </div>

            <div class="champ">
                <label for="<?= $prefixe ?>-rayon">Rayon (km)</label>
                <input type="text" id="<?= $prefixe ?>-rayon" name="rayon" inputmode="numeric"
                       maxlength="3" placeholder="5" value="<?= e($s['rayon']) ?>"
                       class="<?= isset($err['rayon']) ? 'invalide' : '' ?>">
                <?php if (isset($err['rayon'])): ?>
                    <span class="message-erreur"><?= e($err['rayon']) ?></span>
                <?php else: ?>
                    <span class="aide">Facultatif, de 0 à 100.</span>
                <?php endif; ?>
            </div>
        </div>

        <label class="case">
            <input type="checkbox" name="actif" value="1" <?= $s['actif'] ? 'checked' : '' ?>>
            <span>Service actif (visible par les clients)</span>
        </label>

        <div class="form-boutons">
            <button type="submit" class="bouton-petit primaire">
                <?= $estAjout ? 'Ajouter le service' : 'Enregistrer les modifications' ?>
            </button>
            <?php if (!$estAjout): ?>
                <a href="<?= e(urlPage(['onglet' => 'services'])) ?>" class="bouton-petit">Annuler</a>
            <?php endif; ?>
        </div>
    </form>
    <?php
}

/**
 * Affiche le formulaire de réponse (accepter ou refuser) d'une demande.
 * $etat contient le texte et l'erreur à réafficher, ou null.
 */
function afficherFormulaireReponse(int $idReservation, string $decision, ?array $etat, string $csrfToken): void
{
    $accepter = $decision === 'accepter';
    $idZone   = "message-{$decision}-{$idReservation}";
    $modeles  = $accepter ? array_combine(MODELES_ACCEPTATION, MODELES_ACCEPTATION) : MOTIFS_REFUS;
    ?>
    <form method="post" action="<?= e(urlPage([])) ?>" class="form-reponse" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="repondre_demande">
        <input type="hidden" name="decision" value="<?= e($decision) ?>">
        <input type="hidden" name="id_reservation" value="<?= $idReservation ?>">

        <div class="modeles">
            <span class="cellule-sec"><?= $accepter ? 'Messages rapides :' : 'Motifs fréquents :' ?></span>
            <?php foreach ($modeles as $libelle => $texte): ?>
                <!-- type="button" : le clic remplit la zone de texte sans envoyer le formulaire -->
                <button type="button" class="modele"
                        data-cible="<?= e($idZone) ?>" data-message="<?= e($texte) ?>">
                    <?= e($libelle) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="champ">
            <label for="<?= e($idZone) ?>">
                <?php if ($accepter): ?>
                    Message au client <span class="cellule-sec">(facultatif)</span>
                <?php else: ?>
                    Motif du refus <span class="obligatoire">*</span>
                <?php endif; ?>
            </label>
            <textarea id="<?= e($idZone) ?>" name="message" rows="3" maxlength="<?= MESSAGE_MAX ?>"
                      data-compteur="<?= e($idZone) ?>-compteur"
                      placeholder="<?= $accepter ? 'Ex. Je serai là à 14h, prévoyez un accès à une prise.' : 'Expliquez brièvement pourquoi vous ne pouvez pas intervenir…' ?>"
                      class="<?= !empty($etat['erreur']) ? 'invalide' : '' ?>"
                      <?= $accepter ? '' : 'required minlength="' . MOTIF_MIN . '"' ?>><?= e($etat['message'] ?? '') ?></textarea>
            <?php if (!empty($etat['erreur'])): ?>
                <span class="message-erreur"><?= e($etat['erreur']) ?></span>
            <?php endif; ?>
            <span class="aide" id="<?= e($idZone) ?>-compteur"><?= mb_strlen($etat['message'] ?? '') ?> / <?= MESSAGE_MAX ?></span>
        </div>

        <button type="submit" class="bouton-petit <?= $accepter ? 'primaire' : 'danger-plein' ?>">
            <?= $accepter ? "Confirmer l'acceptation" : 'Confirmer le refus' ?>
        </button>
    </form>
    <?php
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace réparateur — Fair'repair</title>
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
    /* Bouton "pilule" de changement de mode (même style que côté client) */
    .bouton-mode {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border: 1.5px solid var(--vert);
        border-radius: 20px;
        background-color: var(--blanc);
        color: var(--vert);
        font-family: inherit;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
        transition: background-color 0.15s, color 0.15s;
    }
    .bouton-mode:hover,
    .bouton-mode:focus-visible { background-color: var(--vert); color: var(--blanc); outline: none; }

    /* Sélecteur d'espace « Client / Réparateur » : deux moitiés dans une même pilule */
    .commutateur {
        display: inline-flex;
        margin: 0;
        padding: 2px;
        border: 1.5px solid var(--vert);
        border-radius: 20px;
        background-color: var(--blanc);
    }
    .commutateur-option {
        padding: 4px 12px;
        border: none;
        border-radius: 16px;
        background: none;
        font-family: inherit;
        font-size: 0.83rem;
        font-weight: 600;
        line-height: 1.5;
        color: var(--vert);
        cursor: pointer;
        white-space: nowrap;
    }
    button.commutateur-option:hover,
    button.commutateur-option:focus-visible { background-color: var(--vert-clair); outline: none; }
    .commutateur-option.actif { background-color: var(--vert); color: var(--blanc); cursor: default; }

    /* Zone « fermer mon profil » : action rare et lourde, mise à l'écart */
    .zone-sensible { border-color: #F0C4C6; }
    .zone-sensible h2 { color: var(--rouge); }
    .zone-sensible p { color: var(--texte-doux); font-size: 0.9rem; margin-bottom: 12px; }

    /* ---------- Conteneurs ---------- */
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
    .ligne { display: flex; gap: 16px; }
    .ligne .champ { flex: 1; }
    .champ { margin-bottom: 16px; display: flex; flex-direction: column; }
    label { font-size: 0.88rem; font-weight: 600; margin-bottom: 5px; }
    .obligatoire { color: var(--rouge); }

    input[type="text"], select, textarea {
        padding: 10px 12px;
        border: 1px solid var(--bordure);
        border-radius: 6px;
        font-size: 0.95rem;
        font-family: inherit;
        background-color: var(--blanc);
        color: var(--texte);
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    textarea { resize: vertical; min-height: 80px; line-height: 1.5; }
    input:focus, select:focus, textarea:focus {
        outline: none;
        border-color: var(--vert);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15);
    }
    .invalide { border-color: var(--rouge) !important; background-color: var(--rouge-clair) !important; }

    .message-erreur { color: var(--rouge); font-size: 0.82rem; margin-top: 4px; }
    .aide { color: var(--texte-doux); font-size: 0.8rem; margin-top: 4px; }

    .case { display: flex; align-items: center; gap: 10px; font-weight: 400; cursor: pointer; margin-bottom: 18px; }
    .case input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--vert); }
    .form-boutons { display: flex; gap: 10px; flex-wrap: wrap; }

    /* ---------- Alertes ---------- */
    .alerte { padding: 14px 16px; border-radius: 6px; margin-bottom: 22px; font-size: 0.92rem; }
    .alerte-erreur  { background-color: var(--rouge-clair); border-left: 4px solid var(--rouge); color: var(--rouge); }
    .alerte-succes  { background-color: var(--succes-clair); border-left: 4px solid var(--succes); color: var(--succes); }
    .alerte-info    { background-color: var(--bleu-clair);  border-left: 4px solid var(--bleu);  color: var(--bleu); }
    .alerte-attente { background-color: var(--ambre-clair); border-left: 4px solid var(--ambre); color: var(--ambre);
                      display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .alerte a { color: inherit; font-weight: 600; }

    /* ---------- Boutons ---------- */
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
        white-space: nowrap;
    }
    .bouton-petit:hover { background-color: var(--fond); }
    .bouton-petit.primaire { background-color: var(--vert); border-color: var(--vert); color: var(--blanc); }
    .bouton-petit.primaire:hover { background-color: var(--vert-fonce); }
    .bouton-petit.danger { color: var(--rouge); border-color: #F0C4C6; }
    .bouton-petit.danger:hover { background-color: var(--rouge-clair); }
    .bouton-petit.danger-plein { background-color: var(--rouge); border-color: var(--rouge); color: var(--blanc); }
    .bouton-petit.danger-plein:hover { background-color: #9E1F24; }

    /* Pastille de compteur (demandes en attente) */
    .pastille {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 24px; height: 24px; padding: 0 7px;
        border-radius: 12px;
        background-color: var(--ambre); color: var(--blanc);
        font-size: 0.8rem; font-weight: 700; line-height: 1;
        vertical-align: middle;
    }
    .pastille.zero { background-color: var(--fond); color: var(--texte-doux); border: 1px solid var(--bordure); }

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
        color: inherit;
        text-decoration: none;
    }
    a.stat:hover { box-shadow: 0 2px 10px rgba(13, 24, 41, 0.08); }
    .stat.bleu   { border-top-color: var(--bleu); }
    .stat.ambre  { border-top-color: var(--ambre); }
    .stat.violet { border-top-color: var(--violet); }
    .stat.a-traiter { background-color: var(--ambre-clair); }
    .stat-valeur { font-size: 1.9rem; font-weight: 700; line-height: 1.2; }
    .stat-valeur small { font-size: 1rem; color: var(--texte-doux); font-weight: 600; }
    .stat-libelle { font-size: 0.82rem; color: var(--texte-doux); text-transform: uppercase; letter-spacing: 0.4px; }

    /* ---------- Sections ---------- */
    .bloc {
        background-color: var(--blanc);
        border: 1px solid var(--bordure);
        border-radius: var(--rayon);
        padding: 24px 26px;
        margin-bottom: 26px;
        scroll-margin-top: 16px;     /* ancre #demandes : un peu d'air au-dessus */
    }
    .bloc-entete {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
    }
    .bloc-entete h2 { margin-bottom: 0; display: flex; align-items: center; gap: 10px; }
    .compteur { color: var(--texte-doux); font-weight: 500; font-size: 0.95rem; }

    /* ---------- Demandes en attente ---------- */
    .demande {
        border: 1px solid var(--bordure);
        border-left: 4px solid var(--ambre);
        border-radius: 8px;
        padding: 16px 18px;
        margin-bottom: 14px;
    }
    .demande:last-child { margin-bottom: 0; }
    .demande-entete {
        display: flex; justify-content: space-between; align-items: baseline;
        flex-wrap: wrap; gap: 6px 12px; margin-bottom: 10px;
    }
    .demande-infos {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 8px 20px;
        margin-bottom: 14px;
    }
    .demande-infos dt { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px; color: var(--texte-doux); }
    .demande-infos dd { font-size: 0.92rem; }
    /* Textes libres du client (panne, accès) : toute la largeur, retours à la ligne gardés */
    .demande-infos div:has(> .texte-client) { grid-column: 1 / -1; }
    .demande-infos .texte-client {
        white-space: pre-line; overflow-wrap: anywhere;
        background-color: var(--fond); border-radius: 6px; padding: 8px 10px;
    }
    .demande-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; }

    /* <details> : le bouton (summary) révèle le formulaire, sans JavaScript */
    .reponse > summary { list-style: none; }
    .reponse > summary::-webkit-details-marker { display: none; }
    .reponse[open] {
        flex-basis: 100%;
        order: 1;                  /* le formulaire ouvert passe sous les boutons */
        border: 1px solid var(--bordure);
        border-radius: 8px;
        padding: 14px 16px;
        background-color: var(--fond);
    }
    .reponse.refuser[open] { border-color: #F0C4C6; }
    .reponse[open] > summary { margin-bottom: 12px; }
    .form-reponse .champ { margin-bottom: 10px; }

    .modeles { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 10px; }
    .modele {
        padding: 4px 11px;
        border: 1px solid var(--bordure);
        border-radius: 20px;
        background-color: var(--blanc);
        font-family: inherit; font-size: 0.8rem; color: var(--texte);
        cursor: pointer;
    }
    .modele:hover { border-color: var(--vert); color: var(--vert); }
    .reponse.refuser .modele:hover { border-color: var(--rouge); color: var(--rouge); }

    /* ---------- Formulaire d'ajout de service ---------- */
    .ajout-service { margin-bottom: 18px; }
    .ajout-service > summary { list-style: none; display: inline-block; }
    .ajout-service > summary::-webkit-details-marker { display: none; }
    .ajout-service[open] > summary { margin-bottom: 14px; }
    .form-service {
        border: 1px solid var(--bordure);
        border-radius: 8px;
        padding: 18px;
        background-color: var(--fond);
    }
    .modification-service { border-color: var(--vert); }

    /* ---------- Onglets (liens + paramètre ?onglet=) ---------- */
    .onglets {
        display: flex;
        gap: 6px;
        border-bottom: 1px solid var(--bordure);
        margin-bottom: 24px;
        overflow-x: auto;           /* mobile : défilement plutôt que retour à la ligne */
    }
    .onglets a {
        padding: 10px 18px;
        color: var(--texte-doux);
        text-decoration: none;
        font-weight: 600;
        font-size: 0.95rem;
        border-bottom: 3px solid transparent;
        margin-bottom: -1px;        /* la bordure active recouvre la ligne grise */
        white-space: nowrap;
    }
    .onglets a:hover { color: var(--vert); }
    .onglets a[aria-current="page"] { color: var(--vert); border-bottom-color: var(--vert); }

    /* ---------- Sélecteur de vue Aujourd'hui / Cette semaine ---------- */
    .bascule-vue {
        display: inline-flex;
        border: 1px solid var(--bordure);
        border-radius: 20px;
        padding: 3px;
        background-color: var(--fond);
    }
    .bascule-vue a {
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--texte-doux);
        text-decoration: none;
        white-space: nowrap;
    }
    .bascule-vue a:hover { color: var(--vert); }
    .bascule-vue a[aria-current="page"] { background-color: var(--vert); color: var(--blanc); }

    /* ---------- Rendez-vous ---------- */
    .jour-titre {
        font-size: 0.95rem;
        text-transform: capitalize;
        margin: 22px 0 8px;
        padding-bottom: 6px;
        border-bottom: 1px solid var(--bordure);
        display: flex; align-items: center; gap: 10px;
    }
    .jour-titre:first-of-type { margin-top: 0; }
    .jour-titre .etiquette { text-transform: none; }
    .rdv-heure { font-weight: 700; font-size: 1rem; color: var(--vert-fonce); white-space: nowrap; }
    .lien-tel { color: var(--texte-doux); text-decoration: none; }
    .lien-tel:hover { color: var(--vert); text-decoration: underline; }

    /* ---------- Tableaux ---------- */
    .zone-tableau { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .zone-tableau table { min-width: 640px; }   /* force le défilement horizontal sur mobile */
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
    tbody tr.inactif td { color: var(--texte-doux); }
    tbody tr.en-modification td { background-color: var(--vert-clair); }
    .cellule-titre { font-weight: 600; }
    .cellule-sec { color: var(--texte-doux); font-size: 0.85rem; font-weight: 400; }
    .nombre { text-align: right; white-space: nowrap; }
    .actions { display: flex; gap: 6px; flex-wrap: nowrap; }
    .actions form { margin: 0; }

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
    .etiquette.actif      { background-color: var(--succes-clair); color: var(--succes); }
    .etiquette.inactif    { background-color: var(--fond); color: var(--texte-doux); border: 1px solid var(--bordure); }
    .etiquette.jour       { background-color: var(--vert-clair); color: var(--vert-fonce); }
    .etiquette.appareil-actuel { background-color: var(--vert-clair); color: var(--vert-fonce); margin-left: 6px; }

    /* ---------- Revenus mensuels ---------- */
    .revenus { display: grid; gap: 14px; }
    .revenu-ligne { display: grid; grid-template-columns: 110px 1fr 110px; align-items: center; gap: 12px; }
    .revenu-mois { font-size: 0.88rem; font-weight: 600; text-transform: capitalize; }
    .revenu-montant { text-align: right; font-weight: 700; white-space: nowrap; }
    .barre { height: 12px; background-color: var(--fond); border-radius: 6px; overflow: hidden; }
    .barre span { display: block; height: 100%; border-radius: 6px; background-color: var(--vert); }
    .barre.precedent span { background-color: var(--bordure); }
    .evolution { font-size: 0.85rem; font-weight: 600; }
    .evolution.hausse { color: var(--succes); }
    .evolution.baisse { color: var(--rouge); }

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
    .avis:last-child { margin-bottom: 0; }
    .avis-entete { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 8px; }
    .avis-commentaire { font-size: 0.9rem; color: var(--texte-doux); font-style: italic; }

    /* ---------- État vide ---------- */
    .vide-etat { text-align: center; padding: 34px 20px; color: var(--texte-doux); }
    .vide-etat.compact { padding: 14px 10px; }
    .vide-etat p { margin-bottom: 14px; font-size: 0.93rem; }
    .vide-etat.compact p { margin-bottom: 0; }
    .vide-etat .icone { font-size: 2rem; margin-bottom: 6px; }

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
        .entete { padding: 12px 16px; }
        .entete nav { flex-wrap: wrap; gap: 12px 16px; }
        .bloc { padding: 18px 16px; }
        .ligne { flex-direction: column; gap: 0; }
        .demande { padding: 14px; }
        .revenu-ligne { grid-template-columns: 80px 1fr; }
        .revenu-montant { grid-column: 2; text-align: left; }
    }
    </style>
</head>
<body>

<header class="entete">
    <a href="index.php" class="logo">Fair'<span>repair</span></a>
    <nav>
        <a href="dashboard-reparateur.php" class="actif">Mon espace pro</a>
        <span class="badge-user">🔧 <?= e($_SESSION['prenom']) ?></span>

        <!-- Sélecteur Client / Réparateur (affiché si l'utilisateur a les deux rôles) -->
        <?php afficherCommutateurRole($csrfToken); ?>

        <form method="post" action="dashboard-reparateur.php" class="form-deconnexion">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="deconnexion">
            <button type="submit" class="lien-bouton">Déconnexion</button>
        </form>
    </nav>
</header>

<main class="conteneur">

    <h1>Bonjour <?= e($_SESSION['prenom']) ?></h1>
    <p class="sous-titre">Votre activité de réparateur en un coup d'œil.</p>

    <?php if ($message): ?>
        <div class="alerte alerte-succes"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alerte alerte-erreur"><?= e($erreur) ?></div>
    <?php endif; ?>

    <!-- ============ 1. DEMANDES EN ATTENTE ============ -->
    <section class="bloc" id="demandes">
        <div class="bloc-entete">
            <h2>
                Demandes en attente
                <span class="pastille <?= $nbDemandes === 0 ? 'zero' : '' ?>"
                      title="<?= $nbDemandes ?> demande(s) à traiter"><?= $nbDemandes ?></span>
            </h2>
        </div>

        <?php if ($nbDemandes === 0): ?>
            <div class="vide-etat compact">
                <p>✔ Aucune demande en attente : vous êtes à jour.</p>
            </div>
        <?php else: ?>
            <?php foreach ($demandes as $d):
                $idRes = (int) $d['id_reservation'];
                // Formulaire à rouvrir si une erreur de saisie concerne cette demande
                $etatAccepter = ($formReponse && $formReponse['id'] === $idRes && $formReponse['decision'] === 'accepter') ? $formReponse : null;
                $etatRefuser  = ($formReponse && $formReponse['id'] === $idRes && $formReponse['decision'] === 'refuser')  ? $formReponse : null;
            ?>
                <article class="demande">
                    <div class="demande-entete">
                        <div>
                            <span class="cellule-titre"><?= e($d['cli_prenom'] . ' ' . $d['cli_nom']) ?></span>
                            <?php if (!empty($d['cli_tel'])): ?>
                                · <a class="cellule-sec lien-tel"
                                     href="tel:<?= e(preg_replace('/[^0-9+]/', '', $d['cli_tel'])) ?>"><?= e($d['cli_tel']) ?></a>
                            <?php endif; ?>
                        </div>
                        <span class="cellule-sec">
                            Reçue <?= e(depuisFr((int) $d['minutes_attente'])) ?>
                        </span>
                    </div>

                    <dl class="demande-infos">
                        <div>
                            <dt>Service</dt>
                            <dd><strong><?= e($d['service']) ?></strong> — <?= euros($d['tarif']) ?></dd>
                        </div>
                        <div>
                            <dt>Date souhaitée</dt>
                            <dd>
                                <?= e(jourFr($d['date_intervention'])) ?> à <?= e(heureFr($d['date_intervention'])) ?>
                                <?php if ($d['type_reservation'] === 'immediate'): ?>
                                    <span class="etiquette en_attente">Immédiate</span>
                                <?php endif; ?>
                                <?php if ($d['depassee']): ?>
                                    <span class="etiquette annulee">Date dépassée</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Adresse d'intervention</dt>
                            <dd><?= e($d['adresse_intervention']) ?></dd>
                        </div>
                        <div>
                            <dt>Vélo</dt>
                            <dd>
                                <?= e(libelleVelo($d['type_velo'])) ?>
                                <?php if ($d['marque_velo']): ?> — <?= e($d['marque_velo']) ?><?php endif; ?>
                            </dd>
                        </div>
                        <?php if ($d['description_panne']): ?>
                            <div>
                                <dt>Panne décrite</dt>
                                <dd class="texte-client"><?= e($d['description_panne']) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ($d['message_client']): ?>
                            <div>
                                <dt>Accès</dt>
                                <dd class="texte-client"><?= e($d['message_client']) ?></dd>
                            </div>
                        <?php endif; ?>
                    </dl>

                    <!-- name identique : ouvrir « Refuser » referme « Accepter » (navigateurs récents) -->
                    <div class="demande-actions">
                        <details class="reponse accepter" name="reponse-<?= $idRes ?>" <?= $etatAccepter ? 'open' : '' ?>>
                            <summary class="bouton-petit primaire">✓ Accepter</summary>
                            <?php afficherFormulaireReponse($idRes, 'accepter', $etatAccepter, $csrfToken); ?>
                        </details>
                        <details class="reponse refuser" name="reponse-<?= $idRes ?>" <?= $etatRefuser ? 'open' : '' ?>>
                            <summary class="bouton-petit danger">✕ Refuser</summary>
                            <?php afficherFormulaireReponse($idRes, 'refuser', $etatRefuser, $csrfToken); ?>
                        </details>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <!-- ============ 2. RENDEZ-VOUS ============ -->
    <section class="bloc">
        <div class="bloc-entete">
            <h2>
                Mes rendez-vous
                <span class="compteur">(<?= count($rendezVous) ?>)</span>
            </h2>
            <nav class="bascule-vue" aria-label="Période affichée">
                <a href="<?= e(urlPage(['vue' => 'jour'])) ?>"
                   <?= $vue === 'jour' ? 'aria-current="page"' : '' ?>>Aujourd'hui</a>
                <a href="<?= e(urlPage(['vue' => 'semaine'])) ?>"
                   <?= $vue === 'semaine' ? 'aria-current="page"' : '' ?>>Cette semaine</a>
            </nav>
        </div>

        <?php if (empty($rendezVous)): ?>
            <div class="vide-etat">
                <div class="icone">☕</div>
                <p>
                    <?= $vue === 'jour'
                        ? "Aucun rendez-vous aujourd'hui. Profitez-en pour souffler !"
                        : "Aucun rendez-vous prévu cette semaine." ?>
                </p>
                <?php if ($vue === 'jour'): ?>
                    <a href="<?= e(urlPage(['vue' => 'semaine'])) ?>" class="bouton-petit">Voir toute la semaine</a>
                <?php endif; ?>
            </div>
        <?php else: ?>

            <?php foreach ($rdvParJour as $jour => $rdvsDuJour): ?>

                <?php if ($vue === 'semaine'): ?>
                    <h3 class="jour-titre">
                        <?= e(jourFr($jour)) ?>
                        <?php if ($jour === $aujourdhui): ?>
                            <span class="etiquette jour">Aujourd'hui</span>
                        <?php endif; ?>
                    </h3>
                <?php endif; ?>

                <div class="zone-tableau">
                    <table>
                        <thead>
                            <tr>
                                <th>Heure</th>
                                <th>Client</th>
                                <th>Service</th>
                                <th>Adresse d'intervention</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rdvsDuJour as $rdv): ?>
                            <tr>
                                <td class="rdv-heure"><?= e(heureFr($rdv['date_intervention'])) ?></td>
                                <td>
                                    <div class="cellule-titre"><?= e($rdv['cli_prenom'] . ' ' . $rdv['cli_nom']) ?></div>
                                    <?php if (!empty($rdv['cli_tel'])): ?>
                                        <!-- tel: ne garde que chiffres et « + » pour un lien cliquable sur mobile -->
                                        <a class="cellule-sec lien-tel"
                                           href="tel:<?= e(preg_replace('/[^0-9+]/', '', $rdv['cli_tel'])) ?>">
                                            <?= e($rdv['cli_tel']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="cellule-sec">Téléphone non renseigné</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($rdv['service']) ?></td>
                                <td class="cellule-sec"><?= e($rdv['adresse_intervention']) ?></td>
                                <td>
                                    <span class="etiquette <?= e($rdv['statut']) ?>">
                                        <?= e(libelleStatut($rdv['statut'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <!-- ============ ONGLETS ============ -->
    <nav class="onglets" aria-label="Sections du tableau de bord">
        <a href="<?= e(urlPage(['onglet' => 'statistiques'])) ?>"
           <?= $onglet === 'statistiques' ? 'aria-current="page"' : '' ?>>📊 Statistiques</a>
        <a href="<?= e(urlPage(['onglet' => 'services'])) ?>"
           <?= $onglet === 'services' ? 'aria-current="page"' : '' ?>>🛠️ Mes services</a>
    </nav>

    <?php if ($onglet === 'statistiques'): ?>

        <!-- ============ 3. STATISTIQUES ============ -->

        <?php if ($nbDemandes > 0): ?>
            <!-- Même compteur que la section « Demandes en attente » -->
            <div class="alerte alerte-attente">
                <span class="pastille"><?= $nbDemandes ?></span>
                <span>
                    <?= $nbDemandes > 1 ? 'demandes attendent' : 'demande attend' ?> votre réponse.
                    <a href="#demandes">Répondre maintenant</a>
                </span>
            </div>
        <?php endif; ?>

        <div class="grille-stats">
            <div class="stat">
                <div class="stat-valeur"><?= (int) $stats['total'] ?></div>
                <div class="stat-libelle">Réservations</div>
            </div>
            <a href="#demandes" class="stat ambre <?= $nbDemandes > 0 ? 'a-traiter' : '' ?>">
                <div class="stat-valeur"><?= $nbDemandes ?></div>
                <div class="stat-libelle">En attente de réponse</div>
            </a>
            <div class="stat bleu">
                <div class="stat-valeur"><?= (int) $stats['confirmees'] ?></div>
                <div class="stat-libelle">Confirmées</div>
            </div>
            <div class="stat violet">
                <div class="stat-valeur"><?= (int) $stats['terminees'] ?></div>
                <div class="stat-libelle">Terminées</div>
            </div>
            <div class="stat">
                <div class="stat-valeur"><?= euros($revenus['total']) ?></div>
                <div class="stat-libelle">Mes gains (net)</div>
            </div>
            <div class="stat violet">
                <div class="stat-valeur"><?= euros($revenus['commission']) ?></div>
                <div class="stat-libelle">Commission plateforme</div>
            </div>
            <div class="stat ambre">
                <div class="stat-valeur">
                    <?php if ($notes['moyenne'] !== null): ?>
                        <?= number_format((float) $notes['moyenne'], 1, ',', '') ?><small> / 5</small>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
                <div class="stat-libelle">Note moyenne</div>
            </div>
            <div class="stat bleu">
                <div class="stat-valeur"><?= (int) $notes['nombre'] ?></div>
                <div class="stat-libelle">Avis reçus</div>
            </div>
        </div>

        <section class="bloc">
            <div class="bloc-entete">
                <h2>Revenus du mois</h2>
                <?php if ($evolution !== null): ?>
                    <span class="evolution <?= $evolution >= 0 ? 'hausse' : 'baisse' ?>">
                        <?= $evolution >= 0 ? '▲ +' : '▼ ' ?><?= $evolution ?> % par rapport à <?= e($nomMoisPrec) ?>
                    </span>
                <?php elseif ($moisCourant > 0): ?>
                    <span class="evolution hausse">Premiers revenus ce mois-ci</span>
                <?php endif; ?>
            </div>

            <div class="revenus">
                <div class="revenu-ligne">
                    <span class="revenu-mois"><?= e($nomMoisCourant) ?></span>
                    <div class="barre"><span style="width: <?= $largeurCourant ?>%"></span></div>
                    <span class="revenu-montant"><?= euros($moisCourant) ?></span>
                </div>
                <div class="revenu-ligne">
                    <span class="revenu-mois"><?= e($nomMoisPrec) ?></span>
                    <div class="barre precedent"><span style="width: <?= $largeurPrec ?>%"></span></div>
                    <span class="revenu-montant"><?= euros($moisPrecedent) ?></span>
                </div>
            </div>
        </section>

        <section class="bloc">
            <div class="bloc-entete">
                <h2>Derniers avis reçus</h2>
            </div>

            <?php if (empty($derniersAvis)): ?>
                <div class="vide-etat">
                    <p>Vous n'avez pas encore reçu d'avis.</p>
                    <p class="cellule-sec">Les clients peuvent noter une intervention une fois qu'elle est terminée.</p>
                </div>
            <?php else: ?>
                <?php foreach ($derniersAvis as $a): ?>
                    <article class="avis">
                        <div class="avis-entete">
                            <div>
                                <span class="cellule-titre"><?= e($a['service']) ?></span>
                                <!-- Nom du client abrégé (« Marie D. ») : discrétion -->
                                <span class="cellule-sec">
                                    — <?= e($a['cli_prenom'] . ' ' . mb_substr($a['cli_nom'], 0, 1) . '.') ?>
                                </span>
                            </div>
                            <div>
                                <?= etoiles((int) $a['note']) ?>
                                <span class="cellule-sec"><?= dateFr($a['date_avis']) ?></span>
                            </div>
                        </div>
                        <?php if (!empty($a['commentaire'])): ?>
                            <p class="avis-commentaire">« <?= e($a['commentaire']) ?> »</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

    <?php else: ?>

        <!-- ============ 4. MES SERVICES ============ -->

        <?php if (($formService['mode'] ?? '') === 'modification'): ?>
            <!-- Formulaire de modification (ouvert via ?modifier=ID, ou rouvert après une erreur) -->
            <section class="bloc" id="form-service">
                <div class="bloc-entete">
                    <h2>Modifier le service</h2>
                </div>
                <?php afficherFormulaireService($formService, $csrfToken); ?>
            </section>
        <?php endif; ?>

        <section class="bloc">
            <div class="bloc-entete">
                <h2>
                    Mes services
                    <span class="compteur">(<?= count($services) ?>)</span>
                </h2>
            </div>

            <!-- Ajout : <details> affiche / masque le formulaire sans JavaScript.
                 Il reste ouvert (attribut open) si la saisie contenait des erreurs. -->
            <details class="ajout-service" <?= ($formService['mode'] ?? '') === 'ajout' ? 'open' : '' ?>>
                <summary class="bouton-petit primaire">＋ Ajouter un service</summary>
                <?php afficherFormulaireService($formAjout, $csrfToken); ?>
            </details>

            <?php if (empty($services)): ?>
                <div class="vide-etat">
                    <p>Vous ne proposez encore aucun service. Ajoutez-en un pour recevoir des demandes.</p>
                </div>
            <?php else: ?>
                <div class="zone-tableau">
                    <table>
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th class="nombre">Tarif</th>
                                <th>Durée</th>
                                <th>Rayon</th>
                                <th class="nombre">Réservations</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($services as $s):
                            $idServ = (int) $s['id_service'];
                            $nbRes  = (int) $s['nb_reservations'];
                            // Le texte de confirmation annonce ce qui va vraiment se passer
                            $confirmation = $nbRes > 0
                                ? "Ce service a déjà été réservé : il sera désactivé (et non supprimé) pour conserver l'historique. Continuer ?"
                                : "Supprimer définitivement ce service ?";
                            $enModification = ($formService['mode'] ?? '') === 'modification' && $formService['id'] === $idServ;
                        ?>
                            <tr class="<?= $s['actif'] ? '' : 'inactif' ?> <?= $enModification ? 'en-modification' : '' ?>">
                                <td class="cellule-titre"><?= e($s['titre']) ?></td>
                                <td class="nombre"><?= euros($s['tarif']) ?></td>
                                <td><?= e(dureeFr($s['duree_estimee'])) ?></td>
                                <td><?= $s['rayon'] !== null ? (int) $s['rayon'] . ' km' : '—' ?></td>
                                <td class="nombre"><?= $nbRes ?></td>
                                <td>
                                    <?php if ($s['actif']): ?>
                                        <span class="etiquette actif">Actif</span>
                                    <?php else: ?>
                                        <span class="etiquette inactif">Inactif</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <!-- Modifier : simple lien GET, il ne fait qu'AFFICHER le formulaire -->
                                        <a href="<?= e(urlPage(['onglet' => 'services', 'modifier' => $idServ])) ?>#form-service"
                                           class="bouton-petit">Modifier</a>

                                        <!-- Activation / désactivation : POST + CSRF.
                                             L'id envoyé est re-vérifié côté serveur (anti-IDOR). -->
                                        <form method="post" action="<?= e(urlPage(['onglet' => 'services'])) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="basculer_service">
                                            <input type="hidden" name="id_service" value="<?= $idServ ?>">
                                            <button type="submit" class="bouton-petit">
                                                <?= $s['actif'] ? 'Désactiver' : 'Activer' ?>
                                            </button>
                                        </form>

                                        <form method="post" action="<?= e(urlPage(['onglet' => 'services'])) ?>"
                                              onsubmit="return confirm(<?= e(json_encode($confirmation, JSON_UNESCAPED_UNICODE)) ?>);">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="supprimer_service">
                                            <input type="hidden" name="id_service" value="<?= $idServ ?>">
                                            <button type="submit" class="bouton-petit danger">Supprimer</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- Fermeture du profil réparateur : suppression « douce » (voir le traitement PHP) -->
        <section class="bloc zone-sensible">
            <div class="bloc-entete">
                <h2>Fermer mon profil réparateur</h2>
            </div>
            <p>
                Vos services ne seront plus visibles et vos demandes en attente seront refusées
                (le client est prévenu). Votre compte client, votre historique et les
                interventions déjà confirmées sont conservés. Vous pourrez refaire une
                demande plus tard.
            </p>
            <form method="post" action="<?= e(urlPage(['onglet' => 'services'])) ?>"
                  onsubmit="return confirm('Fermer votre profil réparateur ? Vos services ne seront plus visibles.');">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="fermer_profil">
                <button type="submit" class="bouton-petit danger">Fermer mon profil réparateur</button>
            </form>
        </section>

    <?php endif; ?>

    <!-- ============ MES DERNIÈRES CONNEXIONS (journal, filtré par la session) ============ -->
    <?php afficherDernieresConnexions($pdo); ?>

    <!-- ============ MES APPAREILS CONNECTÉS (« Se souvenir de moi ») ============ -->
    <?php afficherAppareilsConnectes($pdo, $csrfToken); ?>

</main>

<footer class="pied">
    <p>&copy; <?= date('Y') ?> Fair'repair</p>
</footer>

<script>
/*
 * Seul JavaScript de la page, et il est facultatif : sans lui, tout
 * fonctionne (on tape simplement le message à la main).
 *   1. Un clic sur un message rapide remplit la zone de texte correspondante.
 *   2. Un compteur affiche le nombre de caractères saisis (maximum 500).
 * Les messages sont lus dans l'attribut data-message, déjà échappé par e()
 * côté PHP, puis affectés via .value : jamais interprétés comme du HTML.
 */
document.querySelectorAll('.modele').forEach(function (bouton) {
    bouton.addEventListener('click', function () {
        var zone = document.getElementById(bouton.dataset.cible);
        zone.value = bouton.dataset.message;
        zone.focus();
        zone.dispatchEvent(new Event('input'));   // met à jour le compteur
    });
});

document.querySelectorAll('textarea[data-compteur]').forEach(function (zone) {
    var sortie = document.getElementById(zone.dataset.compteur);
    zone.addEventListener('input', function () {
        sortie.textContent = zone.value.length + ' / ' + zone.maxLength;
    });
});
</script>

</body>
</html>
