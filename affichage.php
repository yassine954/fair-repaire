<?php
/**
 * Fair'repair — Fonctions d'affichage communes
 * --------------------------------------------
 * Petites fonctions de mise en forme utilisées par les tableaux de bord
 * (client et réparateur). Elles sont regroupées ici pour ne pas être
 * recopiées dans chaque page.
 *
 * Toutes ces fonctions renvoient du texte DÉJÀ SÛR pour le HTML :
 * soit elles ne manipulent que des nombres/dates, soit elles passent
 * par e() avant de renvoyer une chaîne saisie par un utilisateur.
 */

require_once __DIR__ . '/securite.php';

const JOURS_FR = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const MOIS_FR  = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
                  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** Formate un montant : 1234.5 → "1 234,50 €" */
function euros($montant): string
{
    return number_format((float) $montant, 2, ',', ' ') . ' €';
}

/** Taux de TVA appliqué aux prestations (en %). */
const TAUX_TVA = 20.0;

/**
 * Part de TVA comprise dans un prix TTC : 79,00 € TTC → 13,17 € de TVA.
 * (TTC = HT × 1,20, donc HT = TTC / 1,20 et TVA = TTC − HT.)
 * Utilisée par payement.php et recu.php.
 */
function tvaComprise(float $montantTtc): float
{
    return round($montantTtc - round($montantTtc / (1 + TAUX_TVA / 100), 2), 2);
}

/**
 * Formate une date MySQL (DATETIME) au format français.
 *   dateFr('2026-09-29 14:30:00')       → "29/09/2026"
 *   dateFr('2026-09-29 14:30:00', true) → "29/09/2026 à 14h30"
 */
function dateFr(?string $date, bool $avecHeure = false): string
{
    if (empty($date)) {
        return '—';
    }
    $ts = strtotime($date);
    return $avecHeure ? date('d/m/Y \à H\hi', $ts) : date('d/m/Y', $ts);
}

/** Heure seule au format français : "14h30" */
function heureFr(string $date): string
{
    return date('H\hi', strtotime($date));
}

/** Nom du jour + date en toutes lettres : "mardi 29 septembre" */
function jourFr(string $date): string
{
    $ts = strtotime($date);
    // date('N') renvoie 1 pour lundi … 7 pour dimanche
    return JOURS_FR[(int) date('N', $ts)] . ' ' . (int) date('j', $ts) . ' ' . MOIS_FR[(int) date('n', $ts)];
}

/** Nom du mois en français à partir d'un timestamp : "septembre" */
function moisFr(int $timestamp): string
{
    return MOIS_FR[(int) date('n', $timestamp)];
}

/** Durée en minutes → "45 min", "1 h", "1 h 30" */
function dureeFr($minutes): string
{
    if ($minutes === null || $minutes === '') {
        return '—';
    }
    // Même format partout : la mise en forme est faite par dureeLisible() (securite.php)
    return dureeLisible((int) $minutes);
}

/**
 * Libellés français des types de vélo (valeurs de l'ENUM reservation.type_velo).
 * Sert à la fois à construire la liste déroulante ET à vérifier la valeur
 * reçue : une valeur absente de ce tableau est refusée avant l'INSERT.
 */
const TYPES_VELO = [
    'classique' => 'Vélo classique',
    'vae'       => 'Vélo à assistance électrique',
    'vtt'       => 'VTT',
    'cargo'     => 'Vélo cargo',
    'pliant'    => 'Vélo pliant',
    'route'     => 'Vélo de route',
];

/** Libellé d'un type de vélo : 'vae' → "Vélo à assistance électrique" */
function libelleVelo(?string $type): string
{
    return TYPES_VELO[$type ?? ''] ?? 'Vélo';
}

/**
 * Note moyenne sous forme d'étoiles + valeur + nombre d'avis :
 * "★★★★☆ 4,3 (12 avis)", ou "Pas encore d'avis".
 * Renvoie du HTML sûr (seulement des nombres).
 */
function noteAvecAvis($moyenne, int $nbAvis): string
{
    if ($nbAvis === 0 || $moyenne === null) {
        return '<span class="sans-avis">Pas encore d\'avis</span>';
    }
    return etoiles((int) round((float) $moyenne))
         . ' <strong>' . number_format((float) $moyenne, 1, ',', '') . '</strong>'
         . ' <span class="nb-avis">(' . $nbAvis . ' avis)</span>';
}

/**
 * Ancienneté lisible à partir d'un nombre de minutes :
 *   3 → "il y a 3 min", 150 → "il y a 2 h", 4000 → "il y a 2 jours"
 * Le nombre de minutes est calculé par MySQL (TIMESTAMPDIFF) pour que
 * l'heure de référence soit la même que celle des dates enregistrées.
 */
function depuisFr(int $minutes): string
{
    if ($minutes < 1) {
        return "à l'instant";
    }
    if ($minutes < 60) {
        return "il y a $minutes min";
    }
    if ($minutes < 60 * 24) {
        return 'il y a ' . intdiv($minutes, 60) . ' h';
    }
    $jours = intdiv($minutes, 60 * 24);
    return "il y a $jours jour" . ($jours > 1 ? 's' : '');
}

/** Affiche une note sur 5 sous forme d'étoiles. */
function etoiles(int $note): string
{
    $html = '<span class="etoiles" aria-label="' . $note . ' sur 5">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $note ? '★' : '<span class="vide">★</span>';
    }
    return $html . '</span>';
}

/**
 * Contrôle d'en-tête « Client / Réparateur » (utilise les fonctions d'auth.php).
 *
 *   - les deux rôles utilisables → sélecteur à deux positions (formulaire POST
 *     + jeton CSRF, traité par traiterDemandeBascule() en haut de la page) ;
 *   - demande réparateur en attente → simple badge, non cliquable ;
 *   - client seul (ou demande refusée) → lien « Devenir réparateur ».
 *
 * Le formulaire envoie seulement le NOM du rôle voulu : le serveur revérifie
 * toujours que l'utilisateur y a droit (basculerRole()).
 */
function afficherCommutateurRole(string $csrfToken): void
{
    if (peutBasculer()) {
        $actif = roleActif();
        ?>
        <form method="post" action="<?= e(basename($_SERVER['SCRIPT_NAME'])) ?>"
              class="commutateur" aria-label="Changer d'espace">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="basculer_role">
            <?php foreach (['client' => '🚲 Client', 'reparateur' => '🔧 Réparateur'] as $role => $libelle): ?>
                <?php if ($role === $actif): ?>
                    <!-- Espace actuel : mis en valeur, rien à envoyer -->
                    <span class="commutateur-option actif" aria-current="true"><?= e($libelle) ?></span>
                <?php else: ?>
                    <button type="submit" name="role" value="<?= e($role) ?>" class="commutateur-option"
                            title="Passer dans l'espace <?= $role === 'client' ? 'client' : 'réparateur' ?>">
                        <?= e($libelle) ?>
                    </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </form>
        <?php
    } elseif (statutReparateur() === 'en_attente') {
        ?>
        <span class="badge-attente" title="Votre demande pour devenir réparateur est en cours d'examen">
            ⏳ Demande en cours
        </span>
        <?php
    } elseif (aRole('client')) {
        ?>
        <a href="devenir-reparateur.php" class="bouton-mode">🔧 Devenir réparateur</a>
        <?php
    }
}

/**
 * Nom lisible d'un navigateur à partir de son User-Agent : « Chrome sur Windows ».
 * L'ordre des tests compte : le User-Agent d'Edge contient aussi « Chrome »,
 * celui de Chrome contient « Safari », celui d'Android contient « Linux »,
 * celui d'un iPhone contient « Mac OS X ».
 * Un programme qui n'est pas un navigateur (script, robot : « python-requests »,
 * « curl »…) s'affiche « Autre » : le texte brut reste visible en info-bulle.
 */
function navigateurLisible(?string $ua): string
{
    if ($ua === null || $ua === '') {
        return 'Navigateur inconnu';
    }
    $navigateur = match (true) {
        str_contains($ua, 'Edg/')                              => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'Firefox/')                          => 'Firefox',
        str_contains($ua, 'Chrome/')                           => 'Chrome',
        str_contains($ua, 'Safari/')                           => 'Safari',
        default                                                => 'Autre',
    };
    $systeme = match (true) {
        str_contains($ua, 'Windows')                               => 'Windows',
        str_contains($ua, 'Android')                               => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')   => 'iOS',
        str_contains($ua, 'Mac OS X')                              => 'macOS',
        str_contains($ua, 'Linux')                                 => 'Linux',
        default                                                    => null,
    };
    return $systeme ? "$navigateur sur $systeme" : $navigateur;
}

// Couleur de l'étiquette de chaque motif du journal des connexions
const COULEURS_MOTIFS = [
    'succes'                 => 'vert',
    'mot_de_passe_incorrect' => 'rouge',
    'email_inconnu'          => 'rouge',
    'compte_bloque'          => 'ambre',
    'csrf_invalide'          => 'gris',
    'deconnexion'            => 'gris',
];

/** Étiquette colorée d'un motif (libellés : MOTIFS_CONNEXION dans auth.php). */
function etiquetteMotif(string $motif): string
{
    $couleur = COULEURS_MOTIFS[$motif] ?? 'gris';
    $libelle = MOTIFS_CONNEXION[$motif] ?? $motif;
    return '<span class="etiquette ' . $couleur . '">' . e($libelle) . '</span>';
}

/**
 * Bloc « Mes dernières connexions » des tableaux de bord : les 5 dernières
 * connexions réussies de l'utilisateur CONNECTÉ.
 * Les requêtes sont filtrées par l'id de la SESSION, jamais par un paramètre
 * de l'URL ou du formulaire : personne ne peut lire l'historique d'un autre.
 */
function afficherDernieresConnexions(PDO $pdo): void
{
    $idUser = (int) $_SESSION['id_user'];

    $req = $pdo->prepare("SELECT ip, navigateur, `timestamp`
                          FROM tentatives_connexion
                          WHERE id_user = :id_user
                            AND motif   = 'succes'
                          ORDER BY `timestamp` DESC
                          LIMIT 5");
    $req->execute([':id_user' => $idUser]);
    $connexions = $req->fetchAll();

    // Échecs sur ce compte depuis 30 jours (mauvais mot de passe, compte bloqué…)
    $req = $pdo->prepare("SELECT COUNT(*)
                          FROM tentatives_connexion
                          WHERE id_user = :id_user
                            AND reussie = 0
                            AND `timestamp` >= NOW() - INTERVAL 30 DAY");
    $req->execute([':id_user' => $idUser]);
    $echecs = (int) $req->fetchColumn();
    ?>
    <section class="bloc" id="connexions">
        <div class="bloc-entete">
            <h2>Mes dernières connexions</h2>
        </div>

        <?php if ($echecs > 0): ?>
            <div class="alerte alerte-info">
                <?= $echecs ?> tentative<?= $echecs > 1 ? 's' : '' ?> de connexion
                échouée<?= $echecs > 1 ? 's' : '' ?> sur votre compte ces 30 derniers jours.
            </div>
        <?php endif; ?>

        <?php if (empty($connexions)): ?>
            <div class="vide-etat">
                <p>Aucune connexion enregistrée pour le moment.</p>
            </div>
        <?php else: ?>
            <div class="zone-tableau">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Adresse IP</th>
                            <th>Navigateur</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($connexions as $c): ?>
                        <tr>
                            <td><?= dateFr($c['timestamp'], true) ?></td>
                            <td class="cellule-sec"><?= e($c['ip']) ?></td>
                            <td title="<?= e($c['navigateur']) ?>"><?= e(navigateurLisible($c['navigateur'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p class="cellule-sec" style="margin-top:12px">
            Une connexion que vous ne reconnaissez pas ? Changez votre mot de passe, révoquez
            les appareils inconnus ci-dessous et signalez-la à l'équipe Fair'repair.
        </p>
    </section>
    <?php
}

/**
 * Section « Mes appareils connectés » : les appareils où « Se souvenir de
 * moi » a été coché, avec un bouton « Révoquer » (POST + jeton CSRF, traité
 * par traiterRevocationAppareil() dans auth.php, requête filtrée par id_user).
 */
function afficherAppareilsConnectes(PDO $pdo, string $csrfToken): void
{
    $appareils = listerAppareils($pdo, (int) $_SESSION['id_user']);
    $actuel    = (int) ($_SESSION['id_jeton'] ?? 0);
    ?>
    <section class="bloc" id="appareils">
        <div class="bloc-entete">
            <h2>Mes appareils connectés</h2>
        </div>
        <p class="cellule-sec" style="margin-bottom:14px">
            Appareils sur lesquels vous avez coché « Se souvenir de moi ». Si vous n'en
            reconnaissez pas un, révoquez-le : il devra se reconnecter avec votre mot de passe.
        </p>

        <?php if (empty($appareils)): ?>
            <div class="vide-etat">
                <p>Aucun appareil n'est mémorisé pour le moment.</p>
            </div>
        <?php else: ?>
            <div class="zone-tableau">
                <table>
                    <thead>
                        <tr>
                            <th>Appareil</th>
                            <th>Adresse IP</th>
                            <th>Connecté depuis le</th>
                            <th>Expire le</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($appareils as $a): ?>
                        <tr>
                            <td>
                                <!-- Le User-Agent complet est en info-bulle, échappé comme le reste -->
                                <span class="cellule-titre" title="<?= e($a['navigateur']) ?>">
                                    <?= e(navigateurLisible($a['navigateur'])) ?>
                                </span>
                                <?php if ((int) $a['id_jeton'] === $actuel): ?>
                                    <span class="etiquette appareil-actuel">Cet appareil</span>
                                <?php endif; ?>
                            </td>
                            <td class="cellule-sec"><?= e($a['adresse_ip'] ?? '—') ?></td>
                            <td class="cellule-sec"><?= dateFr($a['date_creation'], true) ?></td>
                            <td class="cellule-sec"><?= dateFr($a['date_expiration']) ?></td>
                            <td>
                                <form method="post" action="<?= e(basename($_SERVER['SCRIPT_NAME'])) ?>"
                                      onsubmit="return confirm('Déconnecter cet appareil ?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="revoquer_appareil">
                                    <input type="hidden" name="id_jeton" value="<?= (int) $a['id_jeton'] ?>">
                                    <button type="submit" class="bouton-petit danger">Révoquer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

/** Convertit un statut technique de réservation en libellé lisible. */
function libelleStatut(string $statut): string
{
    return [
        'en_attente' => 'En attente',
        'confirmee'  => 'Confirmée',
        'terminee'   => 'Terminée',
        'annulee'    => 'Annulée',
    ][$statut] ?? $statut;
}
