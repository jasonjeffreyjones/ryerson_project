<?php

declare(strict_types=1);

require_once __DIR__ . '/survey_lib.php';

try {
	load_env_file();
	$completionCode = get_required_env_value('RYERSON_PROLIFIC_COMPLETION_CODE');
	$completionUrl = 'https://app.prolific.com/submissions/complete?cc=' . rawurlencode($completionCode);
} catch (RuntimeException $exception) {
	error_log('Ryerson survey completion error: ' . $exception->getMessage());
	ryerson_exit_with_message(500, 'Survey Completion Error', 'The Prolific completion code is not configured.');
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Survey Completion</title>
    <link rel="icon" type="image/png" href="../images/favicon.png">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  </head>
  <body>
	<div class="container py-4">
	
	<p class="lead">Thank you for taking part in this study. Please click the button below to be redirected back to Prolific.</p>

	<a href="<?php echo ryerson_html($completionUrl); ?>" class="btn btn-primary">Complete Survey</a>

    </div>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
  </body>
</html>
