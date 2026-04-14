<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

$error     = '';
$oob_step2 = false;
$oob_auth_url = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- OOB step 2: user pastes the authorization code ---
    if (isset($_POST['oob_code'])) {
        // Instance is carried in a hidden field so this works even without cookies/session
        $instance = normalize_instance($_POST['oob_instance'] ?? '');
        $code     = trim($_POST['oob_code'] ?? '');
        $oob_auth_url = $_SESSION['oauth_oob_url'] ?? '';

        if (!$instance) {
            $error = 'Invalid instance. Please start the login process again.';
        } elseif ($code === '') {
            $error     = 'Please enter the authorization code.';
            $oob_step2 = true;
        } else {
            $db_key = $instance . '|oob';
            $stmt   = db()->prepare('SELECT client_id, client_secret FROM oauth_apps WHERE instance = ?');
            $stmt->execute([$db_key]);
            $app = $stmt->fetch();

            if (!$app) {
                $error = 'App registration not found. Please start the login process again.';
            } else {
                $token_data = api_token_exchange(
                    $instance,
                    $app['client_id'],
                    $app['client_secret'],
                    $code,
                    'urn:ietf:wg:oauth:2.0:oob'
                );

                if (isset($token_data['error'])) {
                    $error     = 'Token exchange failed: ' . $token_data['error'];
                    $oob_step2 = true;
                } else {
                    $access_token = $token_data['access_token'] ?? '';
                    if (!$access_token) {
                        $error     = 'No access token received.';
                        $oob_step2 = true;
                    } else {
                        $account = api_get($instance, $access_token, '/api/v1/accounts/verify_credentials');
                        if (isset($account['error'])) {
                            $error     = 'Could not fetch account info: ' . $account['error'];
                            $oob_step2 = true;
                        } else {
                            $accounts = $_SESSION['accounts'] ?? [];
                            $new_a    = empty($accounts) ? 0 : (max(array_keys($accounts)) + 1);
                            unset($_SESSION['oauth_state'], $_SESSION['oauth_instance'],
                                  $_SESSION['oauth_oob'], $_SESSION['oauth_oob_url']);
                            $_SESSION['accounts'][$new_a] = [
                                'instance'         => $instance,
                                'enc_access_token' => encrypt_token($access_token),
                                'account'          => [
                                    'id'           => $account['id'] ?? '',
                                    'username'     => $account['username'] ?? '',
                                    'display_name' => $account['display_name'] ?? '',
                                    'avatar'       => $account['avatar'] ?? '',
                                    'locked'       => !empty($account['locked']),
                                ],
                                'instance_config' => null,
                            ];
                            redirect('index.php?a=' . $new_a);
                        }
                    }
                }
            }
        }
    }

    // --- OOB step 1: register OOB app and display the authorization URL ---
    elseif (!empty($_POST['oob'])) {
        $raw_instance = $_POST['instance'] ?? '';
        $instance     = normalize_instance($raw_instance);

        if (!$instance) {
            $error = 'Invalid instance URL.';
        } else {
            $app = get_or_register_oob_app($instance);
            if (isset($app['error'])) {
                $error = 'Could not register app: ' . $app['error'];
            } else {
                $state = bin2hex(random_bytes(16));
                $_SESSION['oauth_state']    = $state;
                $_SESSION['oauth_instance'] = $instance;
                $_SESSION['oauth_oob']      = true;

                $oob_auth_url = rtrim($instance, '/') . '/oauth/authorize?' . http_build_query([
                    'client_id'     => $app['client_id'],
                    'redirect_uri'  => 'urn:ietf:wg:oauth:2.0:oob',
                    'response_type' => 'code',
                    'scope'         => 'read write follow',
                    'state'         => $state,
                ]);
                $_SESSION['oauth_oob_url'] = $oob_auth_url;
                $oob_instance = $instance;
                $oob_step2    = true;
            }
        }
    }

    // --- Normal login: redirect browser to instance ---
    else {
        $raw_instance = $_POST['instance'] ?? '';
        $instance     = normalize_instance($raw_instance);

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

                $authorize_url = rtrim($instance, '/') . '/oauth/authorize?' . http_build_query([
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
}

html_head('Log in');
?>
<section>
  <h1>Log in to <?php echo h(APP_NAME); ?></h1>
  <?php if ($error): ?>
    <p><strong>Error:</strong> <?php echo h($error); ?></p>
  <?php endif; ?>

  <?php if ($oob_step2): ?>
    <h2>Step 2: authorize and paste code</h2>
    <p>Open the URL below on a device that can reach your instance. Log in, authorize the app, then copy the code shown and paste it here.</p>
    <p><input type="text" value="<?php echo h($oob_auth_url); ?>" size="60" readonly></p>
    <form method="post" action="login.php">
      <input type="hidden" name="oob_instance" value="<?php echo h($_POST['oob_instance'] ?? $oob_instance ?? ''); ?>">
      <fieldset>
        <legend>Enter the authorization code</legend>
        <label for="oob_code">Code</label>
        <br>
        <input type="text" id="oob_code" name="oob_code" required autocomplete="off" size="40">
        <br>
        <button type="submit">Complete login</button>
      </fieldset>
    </form>
    <p><a href="login.php">Start over</a></p>

  <?php else: ?>
    <form method="post" action="login.php">
      <fieldset>
        <legend>Your Fediverse instance</legend>
        <label for="instance">Instance URL</label>
        <br>
        <input type="text" id="instance" name="instance" placeholder="<?php echo h(parse_url(DEFAULT_INSTANCE, PHP_URL_HOST) ?? DEFAULT_INSTANCE); ?>" required
               value="<?php echo h($_POST['instance'] ?? ''); ?>">
        <br>
        <label><input type="checkbox" name="oob" value="1"> Copy/paste mode (for old browsers)</label>
        <br>
        <button type="submit">Log in</button>
      </fieldset>
    </form>
  <?php endif; ?>
</section>
<?php
html_foot();
