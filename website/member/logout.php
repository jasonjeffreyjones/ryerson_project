<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/community_lib.php';

ryerson_community_start_member_session();

ryerson_community_clear_member_session();
unset($_SESSION['ryerson_orcid_oauth_state']);
unset($_SESSION['ryerson_orcid_oauth_mode']);
unset($_SESSION['ryerson_pending_invitation_id']);
unset($_SESSION['ryerson_pending_invitation_orcid_id']);

session_regenerate_id(true);

header('Location: index.php');
exit;
