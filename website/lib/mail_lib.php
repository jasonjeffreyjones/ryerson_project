<?php

declare(strict_types=1);

require_once __DIR__ . '/ryerson_bootstrap.php';

const RYERSON_SMTP_READ_BYTES = 8192;

function ryerson_mail_send_text(string $toAddress, string $subject, string $body): bool
{
	try {
		$fromHeader = get_required_env_value('RYERSON_MAIL_FROM');
		$replyToHeader = get_optional_env_value('RYERSON_MAIL_REPLY_TO', $fromHeader);
		$returnPathHeader = get_optional_env_value('RYERSON_MAIL_RETURN_PATH', ryerson_mail_address_from_header($fromHeader));

		$from = ryerson_mail_parse_address($fromHeader, 'RYERSON_MAIL_FROM');
		$replyTo = ryerson_mail_parse_address($replyToHeader, 'RYERSON_MAIL_REPLY_TO');
		$returnPath = ryerson_mail_parse_address($returnPathHeader, 'RYERSON_MAIL_RETURN_PATH');
		$to = ryerson_mail_parse_address($toAddress, 'recipient email address');

		$message = ryerson_mail_build_message($from, $replyTo, $to, $subject, $body);
		ryerson_mail_send_smtp((string) $returnPath['address'], (string) $to['address'], $message);
		return true;
	} catch (RuntimeException $exception) {
		error_log('Ryerson SMTP mail error: ' . $exception->getMessage());
		return false;
	}
}

function ryerson_mail_parse_address(string $headerAddress, string $label): array
{
	$headerAddress = trim($headerAddress);
	$name = '';
	$address = $headerAddress;

	if (preg_match('/^(.+)<([^<>]+)>$/', $headerAddress, $matches) === 1) {
		$name = trim((string) $matches[1], " \t\n\r\0\x0B\"'");
		$address = trim((string) $matches[2]);
	}

	if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
		throw new RuntimeException($label . ' must contain a valid email address.');
	}

	return [
		'name' => $name,
		'address' => $address,
	];
}

function ryerson_mail_address_from_header(string $headerAddress): string
{
	$parsed = ryerson_mail_parse_address($headerAddress, 'RYERSON_MAIL_FROM');
	return (string) $parsed['address'];
}

function ryerson_mail_format_address(array $mailbox): string
{
	$address = (string) $mailbox['address'];
	$name = trim((string) $mailbox['name']);
	if ($name === '') {
		return $address;
	}

	$name = str_replace(["\\", '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $name);
	return '"' . $name . '" <' . $address . '>';
}

function ryerson_mail_sanitize_header_value(string $value, string $label): string
{
	if (preg_match('/[\r\n]/', $value) === 1) {
		throw new RuntimeException($label . ' cannot contain line breaks.');
	}

	return trim($value);
}

function ryerson_mail_build_message(array $from, array $replyTo, array $to, string $subject, string $body): string
{
	$subject = ryerson_mail_sanitize_header_value($subject, 'Email subject');
	if ($subject === '') {
		throw new RuntimeException('Email subject cannot be empty.');
	}

	$host = get_optional_env_value('RYERSON_MAIL_MESSAGE_ID_DOMAIN', '');
	if ($host === '') {
		$domainStart = strrchr((string) $from['address'], '@');
		$host = $domainStart === false ? '' : substr($domainStart, 1);
	}
	if ($host === false || $host === '') {
		$host = 'jasonjones.ninja';
	}

	$messageId = bin2hex(random_bytes(16)) . '@' . $host;
	$headers = [
		'Date: ' . date('r'),
		'From: ' . ryerson_mail_format_address($from),
		'Reply-To: ' . ryerson_mail_format_address($replyTo),
		'To: ' . ryerson_mail_format_address($to),
		'Subject: ' . $subject,
		'Message-ID: <' . $messageId . '>',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'Content-Transfer-Encoding: quoted-printable',
		'X-Mailer: Ryerson Project SMTP',
	];

	return implode("\r\n", $headers) . "\r\n\r\n" . ryerson_mail_prepare_body($body) . "\r\n";
}

function ryerson_mail_prepare_body(string $body): string
{
	$body = str_replace(["\r\n", "\r"], "\n", $body);
	$body = quoted_printable_encode($body);
	$body = str_replace(["\r\n", "\r"], "\n", $body);
	$lines = explode("\n", $body);
	foreach ($lines as $index => $line) {
		if (substr($line, 0, 1) === '.') {
			$lines[$index] = '.' . $line;
		}
	}

	return implode("\r\n", $lines);
}

function ryerson_mail_send_smtp(string $envelopeFrom, string $recipient, string $message): void
{
	$host = get_required_env_value('RYERSON_SMTP_HOST');
	$port = (int) get_optional_env_value('RYERSON_SMTP_PORT', '465');
	$encryption = strtolower(get_optional_env_value('RYERSON_SMTP_ENCRYPTION', 'ssl'));
	$username = get_required_env_value('RYERSON_SMTP_USERNAME');
	$password = get_required_env_value('RYERSON_SMTP_PASSWORD');
	$timeout = (int) get_optional_env_value('RYERSON_SMTP_TIMEOUT_SECONDS', '20');
	if ($timeout < 1) {
		$timeout = 20;
	}

	$remote = $host . ':' . (string) $port;
	if ($encryption === 'ssl') {
		$remote = 'ssl://' . $remote;
	}

	$errno = 0;
	$errstr = '';
	$socket = stream_socket_client($remote, $errno, $errstr, $timeout);
	if ($socket === false) {
		throw new RuntimeException('Could not connect to SMTP server: ' . $errstr);
	}

	stream_set_timeout($socket, $timeout);
	try {
		ryerson_mail_expect($socket, [220]);
		$ehloHost = get_optional_env_value('RYERSON_SMTP_EHLO_HOST', 'jasonjones.ninja');
		ryerson_mail_command($socket, 'EHLO ' . $ehloHost, [250]);

		if ($encryption === 'tls' || $encryption === 'starttls') {
			ryerson_mail_command($socket, 'STARTTLS', [220]);
			$cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			if ($cryptoEnabled !== true) {
				throw new RuntimeException('Could not enable SMTP STARTTLS encryption.');
			}
			ryerson_mail_command($socket, 'EHLO ' . $ehloHost, [250]);
		} elseif ($encryption !== 'ssl' && $encryption !== 'none') {
			throw new RuntimeException('RYERSON_SMTP_ENCRYPTION must be ssl, tls, starttls, or none.');
		}

		ryerson_mail_command($socket, 'AUTH LOGIN', [334]);
		ryerson_mail_command($socket, base64_encode($username), [334]);
		ryerson_mail_command($socket, base64_encode($password), [235]);
		ryerson_mail_command($socket, 'MAIL FROM:<' . $envelopeFrom . '>', [250]);
		ryerson_mail_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
		ryerson_mail_command($socket, 'DATA', [354]);
		ryerson_mail_command($socket, $message . "\r\n.", [250]);
		ryerson_mail_command($socket, 'QUIT', [221]);
	} finally {
		fclose($socket);
	}
}

function ryerson_mail_command($socket, string $command, array $expectedCodes): string
{
	ryerson_mail_write($socket, $command . "\r\n");
	return ryerson_mail_expect($socket, $expectedCodes);
}

function ryerson_mail_write($socket, string $data): void
{
	$bytesWritten = 0;
	$totalBytes = strlen($data);
	while ($bytesWritten < $totalBytes) {
		$result = fwrite($socket, substr($data, $bytesWritten));
		if ($result === false || $result === 0) {
			throw new RuntimeException('Could not write to SMTP server.');
		}
		$bytesWritten += $result;
	}
}

function ryerson_mail_expect($socket, array $expectedCodes): string
{
	$response = '';
	$code = 0;

	while (($line = fgets($socket, RYERSON_SMTP_READ_BYTES)) !== false) {
		$response .= $line;
		if (strlen($line) >= 3 && ctype_digit(substr($line, 0, 3))) {
			$code = (int) substr($line, 0, 3);
		}
		if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
			break;
		}
	}

	if ($response === '') {
		throw new RuntimeException('SMTP server returned an empty response.');
	}

	if (!in_array($code, $expectedCodes, true)) {
		$safeResponse = trim(preg_replace('/\s+/', ' ', $response));
		throw new RuntimeException('SMTP server returned unexpected response: ' . $safeResponse);
	}

	return $response;
}
