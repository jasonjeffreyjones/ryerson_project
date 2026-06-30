<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_lib.php';
require_once dirname(__DIR__) . '/lib/mail_lib.php';

$sent = false;
$errorMessage = '';
$toAddress = '';

try {
	ryerson_admin_bootstrap();
} catch (RuntimeException $exception) {
	error_log('Ryerson SMTP test admin bootstrap error: ' . $exception->getMessage());
	ryerson_admin_exit_with_error(500, 'SMTP Test Error', 'The SMTP test page could not load.');
}

$toAddress = get_optional_env_value('RYERSON_SMTP_TEST_TO', get_optional_env_value('RYERSON_DAILY_EMAIL_TO', ''));

try {
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		ryerson_admin_verify_csrf_token();
		$toAddress = trim((string) ($_POST['to_address'] ?? ''));
		if ($toAddress === '') {
			throw new RuntimeException('A test recipient email address is required.');
		}

		$subject = 'Ryerson SMTP test';
		$message = "This is a Ryerson Project SMTP test email.\n\n";
		$message .= "If this message arrives, inspect the original message headers and confirm SPF, DKIM, and DMARC pass.\n\n";
		$message .= 'Sent at UTC: ' . gmdate('Y-m-d H:i:s') . "\n";
		$sent = ryerson_mail_send_text($toAddress, $subject, $message);
		if (!$sent) {
			$errorMessage = 'SMTP send failed. Check the PHP error log for the SMTP response.';
		}
	}
} catch (RuntimeException $exception) {
	error_log('Ryerson SMTP test admin error: ' . $exception->getMessage());
	$errorMessage = $exception->getMessage();
}

$csrfToken = ryerson_admin_get_csrf_token();

ryerson_admin_render_header('SMTP Test');
?>
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="mb-1">SMTP Test</h1>
          <p class="text-muted mb-0">Send one authenticated SMTP test email through the Ryerson mail sender.</p>
        </div>
      </div>

      <?php if ($sent): ?>
      <div class="alert alert-success" role="alert">
        Test email accepted by SMTP for <?php echo ryerson_admin_html($toAddress); ?>.
      </div>
      <?php endif; ?>

      <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert">
        <?php echo ryerson_admin_html($errorMessage); ?>
      </div>
      <?php endif; ?>

      <form method="post" action="smtp_test.php" class="border rounded p-3">
        <input type="hidden" name="csrf_token" value="<?php echo ryerson_admin_html($csrfToken); ?>">
        <div class="mb-3">
          <label for="toAddress" class="form-label">Recipient email address</label>
          <input
            type="email"
            class="form-control"
            id="toAddress"
            name="to_address"
            value="<?php echo ryerson_admin_html($toAddress); ?>"
            required
          >
        </div>
        <button type="submit" class="btn btn-primary">Send Test Email</button>
      </form>

      <section class="mt-4">
        <h2 class="h5">Acceptance Check</h2>
        <p class="mb-0">
          In Gmail, open the received message, choose Show original, and confirm SPF, DKIM, and DMARC pass.
        </p>
      </section>
<?php
ryerson_admin_render_footer();
