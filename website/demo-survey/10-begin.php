<?php

declare(strict_types=1);

session_start();
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo Survey</title>
	<link rel="icon" type="image/png" href="../images/favicon.png">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  </head>
  <body>
	<div class="container py-4">

	<div class="alert alert-info" role="alert">
		This is a public demonstration of the Ryerson survey flow. It does not collect or save responses.
	</div>
	
	<p class="lead">In this survey, you will say how much you agree or disagree with statements.</p>
	<ul>
		<li>The survey aims to estimate beliefs, attitudes and opinions of American adults.</li>
		<li>It will be delivered to randomly selected American adults.</li>
		<li>No identifying information will be requested or stored.</li>
		<li>Your anonymous responses will be aggregated with others to estimate the distribution of responses among American adults.</li>
		<li>The data will be stored permanently and shared publicly.</li>
		<li>Please answer truthfully and thoughtfully.</li>
		<li>You can withdraw your consent at any time by closing the survey.</li>
	</ul>
	
	<div class="d-flex gap-2 mt-4">
		<form action="20-items.php" method="post" class="m-0">
			<button type="submit" class="btn btn-primary">Agree and Continue</button>
		</form>
		<a href="15-return.php" class="btn btn-outline-secondary">Return</a>
	</div>

    </div>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
  </body>
</html>
