<?php
// SMTP Mailer configuration and helper

function sendEmail($to, $subject, $body, $fromName = "Optimus Infinity") {
    $config = require __DIR__ . '/config.php';
    $smtp = $config['smtp'] ?? null;

    if (!$smtp) {
        error_log("SMTP configuration is missing from config.php. Falling back to standard PHP mail().");
        $headers = "From: \"$fromName\" <info@optimusinfinity.com>\r\n" .
                   "Reply-To: info@optimusinfinity.com\r\n" .
                   "MIME-Version: 1.0\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n" .
                   "X-Mailer: PHP/" . phpversion();
        return @mail($to, $subject, $body, $headers);
    }

    $host = $smtp['host'];
    $port = $smtp['port'];
    $username = $smtp['username'];
    $password = $smtp['password'];

    $success = false;

    try {
        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($socket) {
            @stream_set_timeout($socket, 5);

            $getResponse = function($socket) {
                $response = "";
                while (($line = @fgets($socket, 512)) !== false) {
                    $response .= $line;
                    if (substr($line, 3, 1) === ' ') {
                        break;
                    }
                }
                return $response;
            };

            $checkResponse = function($socket, $expectedCode) use ($getResponse) {
                $res = $getResponse($socket);
                return (intval($res) === $expectedCode);
            };

            if ($checkResponse($socket, 220)) { // Greeting
                @fwrite($socket, "EHLO localhost\r\n");
                if ($checkResponse($socket, 250)) {
                    @fwrite($socket, "AUTH LOGIN\r\n");
                    if ($checkResponse($socket, 334)) {
                        @fwrite($socket, base64_encode($username) . "\r\n");
                        if ($checkResponse($socket, 334)) {
                            @fwrite($socket, base64_encode($password) . "\r\n");
                            if ($checkResponse($socket, 235)) { // Auth Success
                                @fwrite($socket, "MAIL FROM: <$username>\r\n");
                                if ($checkResponse($socket, 250)) {
                                    @fwrite($socket, "RCPT TO: <$to>\r\n");
                                    if ($checkResponse($socket, 250)) {
                                        @fwrite($socket, "DATA\r\n");
                                        if ($checkResponse($socket, 354)) {
                                            $headers = "From: \"$fromName\" <$username>\r\n" .
                                                       "To: <$to>\r\n" .
                                                       "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
                                                       "MIME-Version: 1.0\r\n" .
                                                       "Content-Type: text/plain; charset=UTF-8\r\n" .
                                                       "Content-Transfer-Encoding: 8bit\r\n" .
                                                       "Date: " . date('r') . "\r\n\r\n";

                                            @fwrite($socket, $headers . $body . "\r\n.\r\n");
                                            if ($checkResponse($socket, 250)) {
                                                $success = true;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            @fwrite($socket, "QUIT\r\n");
            $getResponse($socket);
            @fclose($socket);
        }
    } catch (\Throwable $e) {
        error_log("SMTP Error: " . $e->getMessage());
    }

    if ($success) {
        return true;
    }

    // Fallback to standard PHP mail if direct SMTP fails (e.g. firewalled outbound ports or bad auth)
    error_log("SMTP failed or threw exception. Falling back to standard PHP mail().");
    $headers = "From: \"$fromName\" <$username>\r\n" .
               "Reply-To: $username\r\n" .
               "MIME-Version: 1.0\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n" .
               "X-Mailer: PHP/" . phpversion();
    return @mail($to, $subject, $body, $headers);
}
