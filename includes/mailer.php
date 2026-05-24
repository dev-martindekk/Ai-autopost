<?php
/**
 * Simple SMTP Mailer — no external library required
 * Config via system_settings: smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from, smtp_from_name, smtp_secure
 */

function sendEmail(string $to, string $toName, string $subject, string $htmlBody): bool {
    $host     = getSetting('smtp_host', '');
    $port     = (int)getSetting('smtp_port', 587);
    $user     = getSetting('smtp_user', '');
    $pass     = getSetting('smtp_pass', '');
    $from     = getSetting('smtp_from', $user);
    $fromName = getSetting('smtp_from_name', 'AI AutoPost SEO');
    $secure   = getSetting('smtp_secure', 'tls'); // 'tls'=STARTTLS, 'ssl'=direct SSL, 'none'

    if (empty($host) || empty($user) || empty($pass)) {
        error_log("[Mailer] SMTP not configured — host=$host user=$user");
        return false;
    }

    try {
        // Connect
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
        ]]);

        if ($secure === 'ssl') {
            $socket = stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
        } else {
            $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 30);
        }

        if (!$socket) {
            error_log("[Mailer] Connect failed: $errstr ($errno)");
            return false;
        }

        stream_set_timeout($socket, 30);

        $recv = function() use ($socket): string {
            $buf = '';
            while ($line = fgets($socket, 512)) {
                $buf .= $line;
                if ($line[3] === ' ') break; // end of multi-line response
            }
            return $buf;
        };

        $send = function(string $cmd) use ($socket): void {
            fwrite($socket, $cmd . "\r\n");
        };

        // Greeting
        $recv();
        $send("EHLO " . ($host ?: 'localhost'));
        $ehlo = $recv();

        // STARTTLS
        if ($secure === 'tls') {
            $send("STARTTLS");
            $recv();
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $send("EHLO " . ($host ?: 'localhost'));
            $recv();
        }

        // Auth LOGIN
        $send("AUTH LOGIN");
        $recv();
        $send(base64_encode($user));
        $recv();
        $send(base64_encode($pass));
        $resp = $recv();
        if (strpos($resp, '235') === false) {
            error_log("[Mailer] Auth failed: $resp");
            fclose($socket);
            return false;
        }

        // Envelope
        $send("MAIL FROM:<{$from}>");
        $recv();
        $send("RCPT TO:<{$to}>");
        $recv();
        $send("DATA");
        $recv();

        // Headers + body
        $boundary = md5(uniqid());
        $headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n"
                  . "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$to}>\r\n"
                  . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
                  . "MIME-Version: 1.0\r\n"
                  . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
                  . "Date: " . date('r') . "\r\n"
                  . "X-Mailer: AI-AutoPost-SEO\r\n";

        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody));

        $body = "--{$boundary}\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($plainText)) . "\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($htmlBody)) . "\r\n"
              . "--{$boundary}--\r\n";

        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $resp = $recv();

        $send("QUIT");
        fclose($socket);

        if (strpos($resp, '250') === false) {
            error_log("[Mailer] Send failed: $resp");
            return false;
        }

        error_log("[Mailer] Sent to $to: $subject");
        return true;

    } catch (Throwable $e) {
        error_log("[Mailer] Exception: " . $e->getMessage());
        return false;
    }
}

function sendPasswordResetEmail(string $to, string $name, string $resetLink): bool {
    $appName = 'AI AutoPost SEO';
    $subject = "[{$appName}] ลิงก์รีเซ็ตรหัสผ่านของคุณ";
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;background:#f8fafc;padding:40px 0;">
<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
  <div style="background:linear-gradient(135deg,#6366F1,#8B5CF6);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;">🔐 รีเซ็ตรหัสผ่าน</h1>
    <p style="color:rgba(255,255,255,.8);margin:8px 0 0;font-size:14px;">' . htmlspecialchars($appName) . '</p>
  </div>
  <div style="padding:32px;">
    <p style="color:#374151;font-size:15px;">สวัสดี <strong>' . htmlspecialchars($name ?: $to) . '</strong>,</p>
    <p style="color:#6B7280;font-size:14px;line-height:1.7;">เราได้รับคำขอรีเซ็ตรหัสผ่านสำหรับบัญชีของคุณ กดปุ่มด้านล่างเพื่อตั้งรหัสผ่านใหม่</p>
    <div style="text-align:center;margin:28px 0;">
      <a href="' . htmlspecialchars($resetLink) . '" style="display:inline-block;background:linear-gradient(135deg,#6366F1,#8B5CF6);color:#fff;padding:14px 32px;border-radius:10px;font-size:15px;font-weight:700;text-decoration:none;">ตั้งรหัสผ่านใหม่</a>
    </div>
    <p style="color:#9CA3AF;font-size:12px;line-height:1.6;">ลิงก์นี้จะหมดอายุใน <strong>1 ชั่วโมง</strong><br>ถ้าคุณไม่ได้ขอรีเซ็ต กรุณาเพิกเฉยต่ออีเมลนี้</p>
    <hr style="border:none;border-top:1px solid #F1F5F9;margin:20px 0;">
    <p style="color:#9CA3AF;font-size:11px;word-break:break-all;">หรือเปิดลิงก์นี้ในเบราว์เซอร์:<br>' . htmlspecialchars($resetLink) . '</p>
  </div>
</div>
</body></html>';

    return sendEmail($to, $name ?: $to, $subject, $html);
}
