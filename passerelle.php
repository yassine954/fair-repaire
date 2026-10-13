<?php
/**
 * Fair'repair — Passerelle de paiement SIMULÉE
 * --------------------------------------------
 * Ce fichier joue le rôle du prestataire de paiement EXTERNE (Stripe pour
 * les cartes, PayPal pour PayPal). Aucun argent réel, aucune API, aucune
 * connexion Internet : tout est décidé ici, à partir des données de test.
 *
 * CONCEPTION « BOÎTE NOIRE »
 * L'application ne connaît que deux fonctions :
 *     passerellePayerCarte($numero, $expiration, $cvv, $montant)
 *     passerellePayerPaypal($email, $montant)
 * Elle leur confie une demande et reçoit un VERDICT, toujours sous la même
 * forme :
 *     ['succes' => bool, 'code' => 'approved' | 'card_declined' | …,
 *      'reference' => 'pi_…' | 'PAYID-…' | null, 'message' => '…',
 *      'quatre_derniers' => '4242' | null, 'reseau' => 'Visa' | null]
 * payement.php ne sait PAS comment la décision est prise. Pour passer à un
 * vrai prestataire, il suffirait de réécrire l'intérieur de CE fichier
 * (appel HTTPS à l'API Stripe ou PayPal) en gardant ces deux signatures
 * et ce format de réponse : aucune autre page ne changerait.
 *
 * RÈGLE PCI-DSS (norme de sécurité des cartes bancaires)
 * Le numéro complet de la carte et le cryptogramme (CVV) ne sont utilisés
 * QU'EN MÉMOIRE, le temps de cette fonction. Ils ne sont jamais enregistrés
 * en base, jamais écrits dans un journal (error_log), jamais renvoyés.
 * Seuls les 4 derniers chiffres et le réseau sortent de ce fichier.
 * Même dans une simulation, on applique la règle : c'est l'habitude qui compte.
 *
 * Fichier de fonctions uniquement : il n'affiche rien.
 */

require_once __DIR__ . '/securite.php';   // luhnValide()

/**
 * Cartes de test. Ce sont les VRAIS numéros de test publiés par Stripe
 * (https://docs.stripe.com/testing) : un développeur les reconnaît, et le
 * jour où l'on branche le vrai Stripe en mode test, ils donnent le même
 * résultat. Clé : numéro sans espaces ; valeur : [code, explication].
 * Sert aussi à l'encadré d'aide de payement.php.
 */
const CARTES_TEST = [
    '4242424242424242' => ['approved',           'Paiement accepté'],
    '4000000000000002' => ['card_declined',      'Carte refusée par la banque'],
    '4000000000009995' => ['insufficient_funds', 'Fonds insuffisants'],
    '4000000000000069' => ['expired_card',       'Carte expirée'],
    '4000000000000119' => ['processing_error',   'Erreur technique de la banque'],
];

/** Comptes PayPal de test : e-mail → [code, explication]. */
const PAYPAL_TEST = [
    'acheteur@paypal-test.fr' => ['approved',           'Paiement accepté'],
    'refuse@paypal-test.fr'   => ['paypal_declined',    'Paiement refusé par PayPal'],
    'solde@paypal-test.fr'    => ['insufficient_funds', 'Solde PayPal insuffisant'],
    'erreur@paypal-test.fr'   => ['processing_error',   'Erreur technique PayPal'],
];

/** Messages renvoyés par la passerelle, comme le ferait un vrai prestataire. */
const MESSAGES_PASSERELLE = [
    'approved'           => 'Paiement accepté',
    'card_declined'      => 'Votre carte a été refusée',
    'insufficient_funds' => 'Fonds insuffisants',
    'expired_card'       => 'Votre carte est expirée',
    'processing_error'   => 'Erreur technique lors du traitement du paiement',
    'incorrect_number'   => 'Le numéro de carte est incorrect',
    'incorrect_cvc'      => 'Le cryptogramme est incorrect',
    'invalid_amount'     => 'Montant invalide',
    'paypal_declined'    => 'Le paiement a été refusé par PayPal',
    'invalid_email'      => 'Adresse PayPal invalide',
];

/**
 * Réseau de la carte d'après ses premiers chiffres (le « BIN ») :
 *   Visa : commence par 4 ; American Express : 34 ou 37 ;
 *   Mastercard : 51 à 55, ou 2221 à 2720 (nouvelle plage depuis 2017).
 */
function detecterReseau(string $numero): string
{
    $numero = preg_replace('/\D/', '', $numero) ?? '';

    if (preg_match('/^4/', $numero)) {
        return 'Visa';
    }
    if (preg_match('/^3[47]/', $numero)) {
        return 'American Express';
    }
    if (preg_match('/^5[1-5]/', $numero)) {
        return 'Mastercard';
    }
    $debut = (int) substr($numero, 0, 4);
    if (strlen($numero) >= 4 && $debut >= 2221 && $debut <= 2720) {
        return 'Mastercard';
    }
    return 'Inconnu';
}

/**
 * Chaîne aléatoire tirée de random_bytes() (générateur cryptographique).
 * Les octets trop grands sont rejetés pour que chaque caractère ait
 * exactement la même probabilité (sinon l'opération modulo favoriserait
 * les premiers caractères de l'alphabet).
 */
function chaineAleatoire(string $alphabet, int $longueur): string
{
    $taille = strlen($alphabet);
    $limite = 256 - (256 % $taille);   // plus grand multiple de $taille ≤ 256
    $resultat = '';
    while (strlen($resultat) < $longueur) {
        foreach (str_split(random_bytes($longueur)) as $octet) {
            $valeur = ord($octet);
            if ($valeur < $limite && strlen($resultat) < $longueur) {
                $resultat .= $alphabet[$valeur % $taille];
            }
        }
    }
    return $resultat;
}

/** Construit le verdict, toujours sous la même forme. */
function verdictPasserelle(string $code, ?string $reference = null,
                           ?string $quatreDerniers = null, ?string $reseau = null): array
{
    return [
        'succes'          => $code === 'approved',
        'code'            => $code,
        'reference'       => $code === 'approved' ? $reference : null,
        'message'         => MESSAGES_PASSERELLE[$code] ?? 'Paiement refusé',
        'quatre_derniers' => $quatreDerniers,
        'reseau'          => $reseau,
    ];
}

/**
 * Paiement par carte (rôle de Stripe).
 *
 * @param string $numero     numéro saisi (les espaces sont acceptés)
 * @param string $expiration "MM/AA"
 * @param string $cvv        3 chiffres
 * @param float  $montant    montant TTC en euros, calculé par NOTRE serveur
 */
function passerellePayerCarte(string $numero, string $expiration, string $cvv, float $montant): array
{
    // Délai artificiel : imite l'aller-retour réseau vers le prestataire
    // (un vrai paiement prend une à deux secondes).
    usleep(random_int(800000, 2000000));

    $numero = preg_replace('/\s+/', '', $numero) ?? '';
    // Les 4 derniers chiffres sont la SEULE partie du numéro qui sortira d'ici
    $quatreDerniers = preg_match('/^[0-9]{4,}$/', $numero) ? substr($numero, -4) : null;
    $reseau         = detecterReseau($numero);

    if ($montant <= 0) {
        return verdictPasserelle('invalid_amount', null, $quatreDerniers, $reseau);
    }
    // Un vrai prestataire revérifie toujours le numéro, quoi qu'ait fait le site
    if (!preg_match('/^[0-9]{13,19}$/', $numero) || !luhnValide($numero)) {
        return verdictPasserelle('incorrect_number', null, $quatreDerniers, $reseau);
    }
    if (!preg_match('/^[0-9]{3}$/', $cvv)) {
        return verdictPasserelle('incorrect_cvc', null, $quatreDerniers, $reseau);
    }

    // Carte de test connue → résultat prévu ; tout autre numéro valide → accepté
    $code = CARTES_TEST[$numero][0] ?? 'approved';

    // Date d'expiration dépassée → refus, même pour une carte « acceptée »
    if ($code === 'approved' && preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', $expiration, $m)) {
        $finDeValidite = (int) ('20' . $m[2]) * 100 + (int) $m[1];   // ex. 202812
        if ($finDeValidite < (int) date('Ym')) {
            $code = 'expired_card';
        }
    }

    // Référence au format Stripe : « pi_ » (payment intent) + 24 caractères
    $reference = 'pi_' . chaineAleatoire('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', 24);

    return verdictPasserelle($code, $reference, $quatreDerniers, $reseau);
}

/**
 * Paiement PayPal (rôle de PayPal).
 *
 * Seul l'e-mail du compte est transmis : AUCUN mot de passe. Dans une vraie
 * intégration, le client est redirigé vers le site de PayPal, s'y connecte
 * lui-même, puis revient sur notre site avec une autorisation. Un site
 * marchand ne doit JAMAIS recueillir le mot de passe PayPal de ses clients.
 */
function passerellePayerPaypal(string $email, float $montant): array
{
    // Délai artificiel : imite l'aller-retour réseau vers PayPal
    usleep(random_int(800000, 2000000));

    $email = mb_strtolower(trim($email));

    if ($montant <= 0) {
        return verdictPasserelle('invalid_amount');
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return verdictPasserelle('invalid_email');
    }

    // Compte de test connu → résultat prévu ; toute autre adresse valide → accepté
    $code = PAYPAL_TEST[$email][0] ?? 'approved';

    // Référence au format PayPal : « PAYID- » + 24 caractères majuscules
    $reference = 'PAYID-' . chaineAleatoire('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 24);

    return verdictPasserelle($code, $reference);
}
