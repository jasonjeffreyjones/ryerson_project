<?php

declare(strict_types=1);

session_start();
unset($_SESSION['selected_demo_survey_items']);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Return the Demo Survey</title>
	<link rel="icon" type="image/png" href="../images/favicon.png">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
  </head>
  <body>
	<div class="container py-4">
		<h1 class="mb-3">Return the Demo Survey</h1>
		<p class="lead">This is only a demonstration. No responses were saved.</p>
		<p>If you change your mind, you can go back to the demo survey start page.</p>
		<a href="10-begin.php" class="btn btn-primary">Back to Demo Survey Start</a>
	</div>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
  </body>
</html>
