<?php

declare(strict_types=1);

use GermanPath\Auth\AuthException;
use GermanPath\Commerce\PaymentException;
use GermanPath\Http\Request;
use GermanPath\Http\Response;
use GermanPath\Http\Router;
use GermanPath\Support\Session;
use function GermanPath\Support\renderLayout;
use function GermanPath\Support\e;

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$config = $app['config'];
$auth = $app['auth'];
$payments = $app['payments'];

$router = new Router();
$csrfField = static fn (): string => '<input type="hidden" name="_csrf" value="' . e(Session::csrfToken()) . '">';
$authPage = static function (string $title, string $body) use ($config): Response {
    return Response::html(renderLayout($config, $title, '<section class="auth-shell"><div class="card auth-card">' . $body . '</div></section>'));
};
$router->get('/', static function () use ($config): Response {
    $content = <<<HTML
<section class="hero">
    <p class="eyebrow">Phase 1 · Foundation</p>
    <h1>Deutsch lernen, das zu deinem Weg passt.</h1>
    <p>GermanPath ist vorbereitet für Kurse, Lehrer, kostenlose Inhalte und geschützte Videolektionen.</p>
</section>
<section class="grid" aria-label="Plattform-Status">
    <article class="card"><h2>Saubere Basis</h2><p>PHP, SQLite und eine zentrale Routing-/Fehlerbehandlungsschicht.</p></article>
    <article class="card"><h2>Portabel</h2><p>Ohne Node-Server, Docker oder VPS — bereit für gewöhnliches PHP-Hosting.</p></article>
    <article class="card"><h2><span class="status">System bereit</span></h2><p>Die nächste Ausbaustufe ist die JSON-Content-Engine für Kurse und Medien.</p></article>
</section>
HTML;

    return Response::html(renderLayout($config, 'Startseite', $content));
});

$router->get('/health', static function () use ($app): Response {
    return Response::json([
        'status' => 'ok',
        'app' => 'GermanPath',
        'environment' => $app['config']->environment(),
        'migrations_applied_this_request' => $app['applied_migrations'],
    ]);
});

$router->get('/register', static function () use ($authPage, $csrfField): Response {
    return $authPage('Register', '<p class="eyebrow">GermanPath-Konto</p><h1>Konto erstellen</h1>
        <p>Speichere deinen Lernfortschritt und behalte deine Kurse an einem Ort.</p>
        <form method="post" action="/register">
            ' . $csrfField() . '
            <label for="display_name">Name</label><input id="display_name" name="display_name" required maxlength="80">
            <label for="email">E-Mail</label><input id="email" name="email" type="email" required autocomplete="email">
            <label for="password">Passwort</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
            <button type="submit">Konto erstellen</button>
        </form><p>Schon registriert? <a href="/login">Einloggen</a></p>');
});

$router->post('/register', static function (Request $request) use ($auth, $authPage, $csrfField): Response {
    if (!Session::verifyCsrf($request->input('_csrf'))) {
        return $authPage('Registration error', '<h1>Formular abgelaufen</h1><p>Bitte lade die Seite neu und versuche es erneut.</p>');
    }
    try {
        $auth->register(
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
            (string) $request->input('display_name', '')
        );
        return Response::redirect('/login?registered=1');
    } catch (AuthException $exception) {
        return $authPage('Register', '<p class="notice error">' . e($exception->getMessage()) . '</p>
            <p class="eyebrow">GermanPath-Konto</p><h1>Konto erstellen</h1>
            <form method="post" action="/register">' . $csrfField() . '
                <label for="display_name">Name</label><input id="display_name" name="display_name" required maxlength="80">
                <label for="email">E-Mail</label><input id="email" name="email" type="email" required autocomplete="email">
                <label for="password">Passwort</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
                <button type="submit">Konto erstellen</button>
            </form>');
    }
});

$router->get('/login', static function (Request $request) use ($authPage, $csrfField): Response {
    $notice = $request->input('registered') === '1'
        ? '<p class="notice success">Konto erstellt. Prüfe deine E-Mail und logge dich danach ein.</p>'
        : '';
    return $authPage('Login', $notice . '<p class="eyebrow">Willkommen zurück</p><h1>Einloggen</h1>
        <form method="post" action="/login">' . $csrfField() . '
            <label for="email">E-Mail</label><input id="email" name="email" type="email" required autocomplete="email">
            <label for="password">Passwort</label><input id="password" name="password" type="password" required autocomplete="current-password">
            <button type="submit">Einloggen</button>
        </form><p><a href="/forgot-password">Passwort vergessen?</a> · <a href="/register">Konto erstellen</a></p>');
});

$router->post('/login', static function (Request $request) use ($auth, $authPage, $csrfField): Response {
    if (!Session::verifyCsrf($request->input('_csrf'))) {
        return $authPage('Login error', '<h1>Formular abgelaufen</h1><p>Bitte lade die Seite neu und versuche es erneut.</p>');
    }
    try {
        $auth->login((string) $request->input('email', ''), (string) $request->input('password', ''), $request->clientIp());
        return Response::redirect('/account');
    } catch (AuthException $exception) {
        return $authPage('Login', '<p class="notice error">' . e($exception->getMessage()) . '</p>
            <p class="eyebrow">Willkommen zurück</p><h1>Einloggen</h1>
            <form method="post" action="/login">' . $csrfField() . '
                <label for="email">E-Mail</label><input id="email" name="email" type="email" required>
                <label for="password">Passwort</label><input id="password" name="password" type="password" required>
                <button type="submit">Einloggen</button>
            </form>');
    }
});

$router->post('/logout', static function (Request $request) use ($auth): Response {
    if (!Session::verifyCsrf($request->input('_csrf'))) {
        return Response::text('Invalid CSRF token', 419);
    }
    $auth->logout();
    return Response::redirect('/login?logged_out=1');
});

$router->get('/verify-email', static function (Request $request) use ($authPage, $auth): Response {
    try {
        $message = $auth->verifyToken((string) $request->input('token', ''));
        return $authPage('Email verified', '<p class="notice success">' . e($message) . '</p><h1>Alles bereit</h1><p><a href="/login">Zum Login</a></p>');
    } catch (AuthException $exception) {
        return $authPage('Verification error', '<p class="notice error">' . e($exception->getMessage()) . '</p><p><a href="/register">Neues Konto erstellen</a></p>');
    }
});

$router->get('/forgot-password', static function () use ($authPage, $csrfField): Response {
    return $authPage('Password reset', '<p class="eyebrow">Zugang wiederherstellen</p><h1>Passwort zurücksetzen</h1>
        <form method="post" action="/forgot-password">' . $csrfField() . '
            <label for="email">E-Mail</label><input id="email" name="email" type="email" required autocomplete="email">
            <button type="submit">Reset-Link senden</button>
        </form>');
});

$router->post('/forgot-password', static function (Request $request) use ($authPage, $auth, $csrfField): Response {
    if (!Session::verifyCsrf($request->input('_csrf'))) {
        return $authPage('Password reset', '<p class="notice error">Formular abgelaufen. Bitte versuche es erneut.</p>');
    }
    $auth->requestPasswordReset((string) $request->input('email', ''));
    return $authPage('Password reset', '<p class="notice success">Wenn ein Konto mit dieser E-Mail existiert, wurde ein Reset-Link versendet.</p><p>Prüfe dein Postfach und folge dem Link.</p>');
});

$router->get('/reset-password', static function (Request $request) use ($authPage, $csrfField): Response {
    $token = (string) $request->input('token', '');
    if ($token === '') {
        return $authPage('Password reset', '<p class="notice error">Dieser Reset-Link ist ungültig.</p>');
    }
    return $authPage('Password reset', '<p class="eyebrow">Neues Passwort</p><h1>Passwort festlegen</h1>
        <form method="post" action="/reset-password">' . $csrfField() . '
            <input type="hidden" name="token" value="' . e($token) . '">
            <label for="password">Neues Passwort</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
            <button type="submit">Passwort speichern</button>
        </form>');
});

$router->post('/reset-password', static function (Request $request) use ($auth, $authPage): Response {
    if (!Session::verifyCsrf($request->input('_csrf'))) {
        return $authPage('Password reset', '<p class="notice error">Formular abgelaufen. Bitte versuche es erneut.</p>');
    }
    try {
        $auth->resetPassword((string) $request->input('token', ''), (string) $request->input('password', ''));
        return $authPage('Password reset', '<p class="notice success">Passwort gespeichert.</p><p><a href="/login">Zum Login</a></p>');
    } catch (AuthException $exception) {
        return $authPage('Password reset', '<p class="notice error">' . e($exception->getMessage()) . '</p>');
    }
});

$router->get('/account', static function () use ($auth, $authPage, $csrfField): Response {
    $user = $auth->currentUser();
    if ($user === null) {
        return Response::redirect('/login');
    }
    $verified = $user['email_verified_at'] !== null ? 'verifiziert' : 'nicht verifiziert';
    $notice = isset($_GET['payment']) && $_GET['payment'] === 'submitted'
        ? '<p class="notice success">Zahlung eingereicht. Dein Kurszugang wird nach Prüfung freigeschaltet.</p>'
        : '';
    $body = $notice . '<p class="eyebrow">Dein Konto</p><h1>Hallo, ' . e($user['display_name']) . '</h1>
        <p>E-Mail: ' . e($user['email']) . ' · ' . e($verified) . '</p>
        <form method="post" action="/account/profile"><h2>Profil</h2>' . $csrfField() . '
            <label for="display_name">Anzeigename</label><input id="display_name" name="display_name" value="' . e($user['display_name']) . '" required maxlength="80">
            <button type="submit">Profil speichern</button></form>
        <form method="post" action="/account/password"><h2>Passwort ändern</h2>' . $csrfField() . '
            <label for="current_password">Aktuelles Passwort</label><input id="current_password" name="current_password" type="password" required>
            <label for="new_password">Neues Passwort</label><input id="new_password" name="new_password" type="password" required minlength="10">
            <button type="submit">Passwort ändern</button></form>
        <form method="post" action="/account/email"><h2>E-Mail ändern</h2>' . $csrfField() . '
            <label for="new_email">Neue E-Mail</label><input id="new_email" name="new_email" type="email" required>
            <label for="email_password">Aktuelles Passwort</label><input id="email_password" name="current_password" type="password" required>
            <button type="submit">Bestätigungslink senden</button></form>
        <form method="post" action="/logout">' . $csrfField() . '<button class="button-secondary" type="submit">Ausloggen</button></form>';
    return $authPage('Account', $body);
});

$router->get('/purchase/{slug}', static function (Request $request, array $params) use ($auth, $payments, $app, $authPage, $csrfField): Response {
    if ($auth->currentUser() === null) {
        return Response::redirect('/login');
    }
    $course = $app['content']->findBySlug('courses', (string) $params['slug']);
    if ($course === null || $course['is_free'] === true) {
        return Response::text('Not Found', 404);
    }

    $offerOptions = '';
    foreach ($course['offers'] as $offer) {
        foreach ($offer['access_options'] as $option) {
            $label = $offer['title'] . ' · ' . $option['duration'] . ' · '
                . number_format(((int) $option['amount_cents']) / 100, 2) . ' ' . ($option['currency'] ?? 'EUR');
            $offerOptions .= '<option value="' . e($offer['id'] . '|' . $option['duration']) . '">' . e($label) . '</option>';
        }
    }
    $methodOptions = '';
    foreach ($payments->paymentMethods() as $method) {
        $methodOptions .= '<option value="' . e($method['slug']) . '">' . e($method['name']) . '</option>';
    }
    $body = '<p class="eyebrow">Kurszugang</p><h1>' . e($course['title']) . '</h1>
        <p>Wähle eine Lehrkraft, Laufzeit und Zahlungsmethode. Die Freischaltung erfolgt nach manueller Prüfung.</p>
        <form method="post" action="/purchase/' . e($course['slug']) . '">' . $csrfField() . '
            <label for="offer_duration">Angebot und Laufzeit</label>
            <select id="offer_duration" name="offer_duration" required>' . $offerOptions . '</select>
            <label for="payment_method">Zahlungsmethode</label>
            <select id="payment_method" name="payment_method" required>' . $methodOptions . '</select>
            <label for="payer_name">Name des Zahlenden</label><input id="payer_name" name="payer_name" required maxlength="200">
            <label for="payer_account">Zahlungskonto / Nummer</label><input id="payer_account" name="payer_account" required maxlength="200">
            <label for="transaction_reference">Transaktionsnummer</label><input id="transaction_reference" name="transaction_reference" required maxlength="200">
            <label for="paid_at">Zeitpunkt der Zahlung</label><input id="paid_at" name="paid_at" type="datetime-local" required>
            <label for="note">Notiz (optional)</label><textarea id="note" name="note" rows="4" maxlength="1000"></textarea>
            <p class="notice">Der Upload des Zahlungsbelegs wird im nächsten Upload-Sicherheitsmodul ergänzt.</p>
            <button type="submit">Zahlung zur Prüfung einreichen</button>
        </form>';
    return $authPage('Kurszugang', $body);
});

$router->post('/purchase/{slug}', static function (Request $request, array $params) use ($auth, $payments, $app, $authPage): Response {
    $user = $auth->currentUser();
    if ($user === null) {
        return Response::redirect('/login');
    }
    if (!Session::verifyCsrf($request->input('_csrf'))) {
        return Response::text('Invalid CSRF token', 419);
    }
    $course = $app['content']->findBySlug('courses', (string) $params['slug']);
    if ($course === null) {
        return Response::text('Not Found', 404);
    }
    [$offerId, $duration] = array_pad(explode('|', (string) $request->input('offer_duration', ''), 2), 2, '');
    try {
        $payments->submit(
            (int) $user['id'],
            (string) $course['id'],
            $offerId,
            $duration,
            (string) $request->input('payment_method', ''),
            (string) $request->input('payer_name', ''),
            (string) $request->input('payer_account', ''),
            (string) $request->input('transaction_reference', ''),
            (string) $request->input('paid_at', ''),
            (string) $request->input('note', '')
        );
        return Response::redirect('/account?payment=submitted');
    } catch (PaymentException $exception) {
        return $authPage('Kurszugang', '<p class="notice error">' . e($exception->getMessage()) . '</p><p><a href="/purchase/' . e($course['slug']) . '">Zurück zur Zahlung</a></p>');
    }
});

$router->get('/admin', static function () use ($auth): Response {
    return $auth->currentUser() !== null ? Response::redirect('/admin/payments') : Response::redirect('/login');
});

$router->get('/admin/payments', static function () use ($auth, $payments, $authPage, $csrfField): Response {
    $user = $auth->currentUser();
    if ($user === null) {
        return Response::redirect('/login');
    }
    try {
        $submissions = $payments->pendingSubmissions((int) $user['id']);
    } catch (PaymentException $exception) {
        return Response::text($exception->getMessage(), 403);
    }
    $items = '';
    foreach ($submissions as $submission) {
        $items .= '<article class="card"><h2>#' . e((string) $submission['id']) . ' · ' . e($submission['course_id']) . '</h2>
            <p>' . e($submission['display_name']) . ' (' . e($submission['email']) . ') · '
            . e($submission['duration']) . ' · ' . e($submission['amount_cents'] . ' ' . $submission['currency']) . '</p>
            <p>Transaktion: ' . e($submission['transaction_reference']) . '</p>
            <form method="post" action="/admin/payments/' . e((string) $submission['id']) . '/approve">' . $csrfField() . '
                <input name="admin_note" placeholder="Interne Notiz"><button type="submit">Genehmigen</button></form>
            <form method="post" action="/admin/payments/' . e((string) $submission['id']) . '/reject">' . $csrfField() . '
                <input name="admin_note" placeholder="Ablehnungsgrund" required><button class="button-secondary" type="submit">Ablehnen</button></form>
        </article>';
    }
    return $authPage('Payment review', '<p class="eyebrow">Administration</p><h1>Zahlungen prüfen</h1>'
        . ($items === '' ? '<p>Keine offenen Zahlungen.</p>' : '<div class="grid">' . $items . '</div>'));
});

$router->post('/admin/payments/{id}/approve', static function (Request $request, array $params) use ($auth, $payments): Response {
    $user = $auth->currentUser();
    if ($user === null) {
        return Response::redirect('/login');
    }
    if (!Session::verifyCsrf($request->input('_csrf'))) {
        return Response::text('Invalid CSRF token', 419);
    }
    try {
        $payments->approve((int) $user['id'], (int) $params['id'], (string) $request->input('admin_note', ''));
        return Response::redirect('/admin/payments');
    } catch (PaymentException $exception) {
        return Response::text($exception->getMessage(), 403);
    }
});

$router->post('/admin/payments/{id}/reject', static function (Request $request, array $params) use ($auth, $payments): Response {
    $user = $auth->currentUser();
    if ($user === null) {
        return Response::redirect('/login');
    }
    if (!Session::verifyCsrf($request->input('_csrf'))) {
        return Response::text('Invalid CSRF token', 419);
    }
    try {
        $payments->reject((int) $user['id'], (int) $params['id'], (string) $request->input('admin_note', ''));
        return Response::redirect('/admin/payments');
    } catch (PaymentException $exception) {
        return Response::text($exception->getMessage(), 403);
    }
});

$router->post('/account/profile', static function (Request $request) use ($auth, $authPage): Response {
    $user = $auth->currentUser();
    if ($user === null) {
        return Response::redirect('/login');
    }
    if (!Session::verifyCsrf($request->input('_csrf'))) {
        return Response::text('Invalid CSRF token', 419);
    }
    try {
        $auth->updateProfile((int) $user['id'], (string) $request->input('display_name', ''));
        return Response::redirect('/account');
    } catch (AuthException $exception) {
        return $authPage('Account', '<p class="notice error">' . e($exception->getMessage()) . '</p><p><a href="/account">Zurück zum Konto</a></p>');
    }
});

$router->post('/account/password', static function (Request $request) use ($auth): Response {
    $user = $auth->currentUser();
    if ($user === null) {
        return Response::redirect('/login');
    }
    if (!Session::verifyCsrf($request->input('_csrf'))) {
        return Response::text('Invalid CSRF token', 419);
    }
    try {
        $auth->changePassword((int) $user['id'], (string) $request->input('current_password', ''), (string) $request->input('new_password', ''));
        return Response::redirect('/account');
    } catch (AuthException $exception) {
        return Response::text($exception->getMessage(), 422);
    }
});

$router->post('/account/email', static function (Request $request) use ($auth): Response {
    $user = $auth->currentUser();
    if ($user === null) {
        return Response::redirect('/login');
    }
    if (!Session::verifyCsrf($request->input('_csrf'))) {
        return Response::text('Invalid CSRF token', 419);
    }
    try {
        $auth->requestEmailChange((int) $user['id'], (string) $request->input('new_email', ''), (string) $request->input('current_password', ''));
        return Response::redirect('/account');
    } catch (AuthException $exception) {
        return Response::text($exception->getMessage(), 422);
    }
});

$router->get('/courses', static function () use ($app, $config): Response {
    $courses = $app['content']->loadCollection('courses');
    $cards = '';
    foreach ($courses as $course) {
        $cards .= '<article class="card"><p class="eyebrow">' . e($course['level']) . '</p>'
            . '<h2><a href="/course/' . e($course['slug']) . '">' . e($course['title']) . '</a></h2>'
            . '<p>' . e($course['short_description']) . '</p>'
            . '<p><strong>' . e((string) $course['total_videos']) . '</strong> Lektionen · '
            . ($course['is_free'] ? 'Kostenlos' : 'Premium') . '</p></article>';
    }
    $content = '<section><p class="eyebrow">Content Engine · Phase 2</p><h1>Kurse</h1>'
        . '<p>Strukturierte Deutschkurse mit transparenten Lernzielen und mehreren Lehrkräften.</p>'
        . '<div class="grid">' . $cards . '</div></section>';
    return Response::html(renderLayout($config, 'Kurse', $content));
});

$router->get('/course/{slug}', static function (Request $request, array $params) use ($app, $config): Response {
    $course = $app['content']->findBySlug('courses', (string) $params['slug']);
    if ($course === null) {
        return Response::text('Not Found', 404);
    }

    $content = '<section class="hero"><p class="eyebrow">' . e($course['level']) . ' · '
        . e($course['category']) . '</p><h1>' . e($course['title']) . '</h1><p>'
        . e($course['description']) . '</p></section>'
        . '<section class="grid"><article class="card"><h2>Lernziele</h2><ul>';
    foreach ($course['learning_outcomes'] as $outcome) {
        $content .= '<li>' . e($outcome) . '</li>';
    }
    $content .= '</ul></article><article class="card"><h2>Format</h2><p>'
        . e((string) $course['total_videos']) . ' Videos · ' . e($course['duration'])
        . '</p><p>Lehrkräfte: ' . e(implode(', ', $course['teacher_ids'])) . '</p>'
        . ($course['is_free'] ? '' : '<p><a class="button-link" href="/purchase/' . e($course['slug']) . '">Kurszugang auswählen</a></p>')
        . '</article></section>';
    return Response::html(renderLayout($config, $course['title'], $content));
});

$router->get('/free', static function () use ($app, $config): Response {
    $videos = array_values(array_filter(
        $app['content']->loadCollection('videos'),
        static fn (array $video): bool => $video['is_free'] === true
    ));
    $items = '';
    foreach ($videos as $video) {
        $items .= '<article class="card"><p class="eyebrow">Kostenlose Lektion</p><h2>'
            . e($video['title']) . '</h2><p>' . e($video['description']) . '</p></article>';
    }
    $content = '<section><p class="eyebrow">Freie Inhalte</p><h1>Kostenlos lernen</h1>'
        . '<p>Starte mit kurzen, zugänglichen Lektionen.</p><div class="grid">' . $items . '</div></section>';
    return Response::html(renderLayout($config, 'Kostenlos lernen', $content));
});

$router->dispatch(Request::fromGlobals())->send();
