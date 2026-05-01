<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../survey/survey_lib.php';

$mysqli = ryerson_load_database_or_exit();

try {
	if (isset($_SESSION['selected_demo_survey_items']) && is_array($_SESSION['selected_demo_survey_items']) && count($_SESSION['selected_demo_survey_items']) === RYERSON_ITEMS_TO_PRESENT) {
		$items = $_SESSION['selected_demo_survey_items'];
	} else {
		$items = ryerson_fetch_survey_items($mysqli);
		$_SESSION['selected_demo_survey_items'] = $items;
	}
	$mysqli->close();
} catch (RuntimeException $exception) {
	$mysqli->close();
	error_log('Ryerson demo survey item error: ' . $exception->getMessage());
	ryerson_exit_with_message(500, 'Demo Survey Error', 'The demo survey items could not be loaded.');
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ryerson Demo Survey Items</title>
    <link rel="icon" type="image/png" href="../images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
    <style>
      .survey-wrap {
        max-width: 960px;
      }

      .statement-card {
        border: 1px solid #dee2e6;
        border-radius: 1rem;
        padding: 1.5rem;
        margin-bottom: 1.25rem;
        background: #fff;
      }

      .statement-text {
        font-size: clamp(1.25rem, 2vw, 1.75rem);
        line-height: 1.35;
        font-weight: 500;
        margin-bottom: 1rem;
      }

      .scale-row {
        display: grid;
        grid-template-columns: repeat(11, minmax(0, 1fr));
        gap: 0.35rem;
      }

      .scale-labels {
        display: flex;
        justify-content: space-between;
        margin-top: 0.5rem;
        color: #6c757d;
        font-size: 0.95rem;
        font-weight: 500;
      }

      .btn-check:checked + .scale-btn {
        color: #fff;
        background-color: #0d6efd;
        border-color: #0d6efd;
      }

      .scale-btn {
        min-height: 2.75rem;
        font-weight: 600;
      }
    </style>
  </head>
  <body>
    <main class="container py-4 survey-wrap">
      <div class="alert alert-info" role="alert">
        This is a public demonstration. Responses selected here are not saved.
      </div>

      <div class="mb-4">
        <h1 class="mb-2">Ryerson Demo Survey</h1>
        <p class="lead mb-1">Select one number from 0 to 10 for each statement.</p>
        <p class="text-muted mb-0">0 means maximum disagreement. 10 means maximum agreement. All items are required.</p>
      </div>

      <form action="submit-response.php" method="post">
        <?php foreach ($items as $index => $item): ?>
        <?php
          $surveyItemId = (int) $item['survey_item_id'];
          $inputName = 'responses[' . $surveyItemId . ']';
        ?>
        <section class="statement-card" aria-labelledby="item-<?php echo $surveyItemId; ?>-statement">
          <div class="d-flex justify-content-between gap-3 mb-2">
            <div class="text-muted fw-semibold">Item <?php echo (int) $index + 1; ?> of <?php echo RYERSON_ITEMS_TO_PRESENT; ?></div>
          </div>
          <p class="statement-text" id="item-<?php echo $surveyItemId; ?>-statement"><?php echo ryerson_html((string) $item['statement_text']); ?></p>
          <div class="scale-row" role="radiogroup" aria-labelledby="item-<?php echo $surveyItemId; ?>-statement">
            <?php for ($value = 0; $value <= 10; $value++): ?>
            <?php $inputId = 'item-' . $surveyItemId . '-response-' . $value; ?>
            <input class="btn-check" type="radio" name="<?php echo ryerson_html($inputName); ?>" id="<?php echo ryerson_html($inputId); ?>" value="<?php echo $value; ?>" required>
            <label class="btn btn-outline-primary scale-btn" for="<?php echo ryerson_html($inputId); ?>"><?php echo $value; ?></label>
            <?php endfor; ?>
          </div>
          <div class="scale-labels">
            <span>Disagree</span>
            <span>Agree</span>
          </div>
        </section>
        <?php endforeach; ?>

        <div class="d-grid d-sm-flex justify-content-sm-end my-4">
          <button type="submit" class="btn btn-primary btn-lg">Submit Demo Responses</button>
        </div>
      </form>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
  </body>
</html>
