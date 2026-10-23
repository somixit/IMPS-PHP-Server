<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
if (current_user()) {
    header('Location: talk.php');
} else {
    header('Location: login.php');
}
exit;
