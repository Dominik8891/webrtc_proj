<?php

namespace App\Model;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class Email
{
    public static function sendMail($in_email, $in_msg, $in_subject)
    {
        $mail = new PHPMailer(true);

        $mail->CharSet = 'UTF-8';        // <--- Encoding!
        $mail->Encoding = 'base64';      // <--- Encoding!

        // $mail->SMTPDebug = SMTP::DEBUG_SERVER;

        $mail->isSMTP();
        $mail->SMTPAuth = true;

        $mail->Host = $_ENV['SMTP_SERVER'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $_ENV['SMTP_PORT'];

        $mail->Username = $_ENV['SMTP_USERNAME'];
        $mail->Password = $_ENV['SMTP_PASSWORD'];

        $mail->setFrom($_ENV['SMTP_USERNAME'], 'Test');
        $mail->addAddress($in_email, $in_email);

        $mail->Subject = $in_subject;
        $mail->Body = $in_msg;

        $mail->send();
    }
}