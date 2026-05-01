<?php

declare(strict_types=1);

session_start();

$hasSurveyContext = isset($_SESSION['prolific_pid'], $_SESSION['session_id'], $_SESSION['study_id']) &&
	trim((string) $_SESSION['prolific_pid']) !== '' &&
	trim((string) $_SESSION['session_id']) !== '' &&
	trim((string) $_SESSION['study_id']) !== '';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Return the Study</title>
	<link rel="icon" type="image/png" href="../images/favicon.png">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
  </head>
  <body>
	<div class="container py-4">
		<h1 class="mb-3">Return the Study</h1>
		<p class="lead">Please return the study from the Prolific page where you opened it.</p>
		<p>If you do not want to take part, or if you have already submitted responses today, do not click a completion link on Prolific. Instead, close this survey tab or return to the Prolific study page and use Prolific's return-study option.</p>
		<?php if ($hasSurveyContext): ?>
		<p>If you change your mind, you can go back to the survey start page and continue.</p>
		<a href="10-begin.php" class="btn btn-primary">Back to Survey Start</a>
		<?php else: ?>
		<p class="text-muted">No active survey session was found. Reopen the study from Prolific if you want to start again.</p>
		<?php endif; ?>
	</div>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
  </body>
</html>
