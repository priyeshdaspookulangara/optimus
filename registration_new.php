<?php
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$redirectUrl = 'register.php';
if (!empty($queryString)) {
    $redirectUrl .= '?' . $queryString;
}
header("Location: " . $redirectUrl);
exit();
