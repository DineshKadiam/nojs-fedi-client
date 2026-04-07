<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_instance = $_POST['instance'] ?? '';
    $instance = normalize_instance($raw_instance);

    if (!$instance) {
        $error = 'Invalid instance URL.';
    } else {
        $app = get_or_register_app($instance);

        if (isset($app['error'])) {
            $error = 'Could not register app: ' . $app['error'];
        } else {
            $state = bin2hex(random_bytes(16));
            $_SESSION['oauth_state']    = $state;
            $_SESSION['oauth_instance'] = $instance;

            $authorize_url = $instance . '/oauth/authorize?' . http_build_query([
                'client_id'     => $app['client_id'],
                'redirect_uri'  => OAUTH_REDIRECT_URI,
                'response_type' => 'code',
                'scope'         => 'read write follow',
                'state'         => $state,
            ]);

            header('Location: ' . $authorize_url);
            exit;
        }
    }
}

html_head('Log in');
?>
<section>
  <h1>Log in to <?php echo h(APP_NAME); ?></h1>
  <?php if ($error): ?>
    <p><strong>Error:</strong> <?php echo h($error); ?></p>
  <?php endif; ?>
  <form method="post" action="login.php">
    <fieldset>
      <legend>Your Fediverse instance</legend>
      <label for="instance">Instance URL</label>
      <br>
      <input type="text" id="instance" name="instance" placeholder="<?php echo h(parse_url(DEFAULT_INSTANCE, PHP_URL_HOST) ?? DEFAULT_INSTANCE); ?>" required
             value="<?php echo h($_POST['instance'] ?? ''); ?>">
      <br>
      <button type="submit">Log in</button>
    </fieldset>
  </form>
</section>
<?php
html_foot();
