<?php
if (session_status() === PHP_SESSION_NONE) session_start();
unset($_SESSION['store_customer_id']);
header('Location: index.php');
exit;
