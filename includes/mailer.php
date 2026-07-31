<?php
// SMTP Mailer configuration and helper

function sendEmail($to, $subject, $body, $fromName = "Optimus Infinity") {
    $config = require __DIR__ . '/config.php';
    $smtp = $config['smtp'] ?? [
        'host' => 'ssl://mail.optimusinfinity.com',
        'port' => 465,
        'username' => 'info@optimusinfinity.com',
        'password' => 'pearlsPearls2#',
    ];

    $host = $smtp['host'];
    $port = $smtp['port'];
    $username = $smtp['username'];
    $password = $smtp['password'];

    $socket = @fsockopen($host, $port, $errno, $errstr, 10);
    if ($socket) {
        $getResponse = function($socket) {
            $response = "";
            while (($line = fgets($socket, 512)) !== false) {
                $response .= $line;
                if (substr($line, 3, 1) === ' ') {
                    break;
                }
            }
            return $response;
        };

        $getResponse($socket); // Read greeting

        // EHLO
        fwrite($socket, "EHLO localhost\r\n");
        $getResponse($socket);

        // AUTH LOGIN
        fwrite($socket, "AUTH LOGIN\r\n");
        $getResponse($socket);

        // Send Username
        fwrite($socket, base64_encode($username) . "\r\n");
        $getResponse($socket);

        // Send Password
        fwrite($socket, base64_encode($password) . "\r\n");
        $getResponse($socket);

        // MAIL FROM
        fwrite($socket, "MAIL FROM: <$username>\r\n");
        $getResponse($socket);

        // RCPT TO
        fwrite($socket, "RCPT TO: <$to>\r\n");
        $getResponse($socket);

        // DATA
        fwrite($socket, "DATA\r\n");
        $getResponse($socket);

        // Headers
        $headers = "From: \"$fromName\" <$username>\r\n" .
                   "To: <$to>\r\n" .
                   "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
                   "MIME-Version: 1.0\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n" .
                   "Date: " . date('r') . "\r\n\r\n";

        fwrite($socket, $headers . $body . "\r\n.\r\n");
        $getResponse($socket);

        // QUIT
        fwrite($socket, "QUIT\r\n");
        $getResponse($socket);

        fclose($socket);
        return true;
    } else {
        // Fallback to standard PHP mail if direct SMTP connection fails (e.g. no outbound SMTP access in sandbox)
        error_log("Direct SMTP failed: $errno - $errstr. Falling back to standard PHP mail().");
        $headers = "From: \"$fromName\" <$username>\r\n" .
                   "Reply-To: $username\r\n" .
                   "MIME-Version: 1.0\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n" .
                   "X-Mailer: PHP/" . phpversion();
        return @mail($to, $subject, $body, $headers);
    }
}
