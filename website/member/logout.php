<?php

declare(strict_types=1);

session_start();

unset($_SESSION['ryerson_member_id']);
unset($_SESSION['ryerson_member_orcid_id']);
unset($_SESSION['ryerson_member_display_name']);
unset($_SESSION['ryerson_orcid_oauth_state']);
unset($_SESSION['ryerson_orcid_oauth_mode']);
unset($_SESSION['ryerson_pending_invitation_id']);
unset($_SESSION['ryerson_pending_invitation_orcid_id']);

session_regenerate_id(true);

header('Location: index.php');
exit;
